<?php
namespace VideoThumbnail;

use Omeka\Module\AbstractModule;
use VideoThumbnail\Form\ConfigForm;
use VideoThumbnail\Media\Ingester\VideoThumbnail as VideoThumbnailIngester;
use VideoThumbnail\Media\Renderer\VideoThumbnail as VideoThumbnailRenderer;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Entity\Media;
use Omeka\Api\Representation\MediaRepresentation;

class Module extends AbstractModule
{
    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getConfigForm(PhpRenderer $renderer): string
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $form = $services->get('FormElementManager')->get(ConfigForm::class);

        $settings = $services->get('Omeka\Settings');
        $form->init();
        $form->setData([
            'videothumbnail_ffmpeg_path' => $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg'),
            'videothumbnail_frames_count' => $settings->get('videothumbnail_frames_count', 5),
            'videothumbnail_default_frame' => $settings->get('videothumbnail_default_frame', 2),
        ]);

        return $renderer->render('video-thumbnail/admin/config-form', [
            'form' => $form,
        ]);
    }

    public function handleConfigForm(AbstractController $controller): bool
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(ConfigForm::class);

        $form->init();
        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->flashMessenger()->addErrors($form->getMessages());
            return false;
        }

        $formData = $form->getData();

        if ((int)$formData['videothumbnail_frames_count'] <= 0 || (int)$formData['videothumbnail_default_frame'] < 0) {
            $controller->flashMessenger()->addError('Frame count and default frame must be non-negative integers.');
            return false;
        }

        $settings->set('videothumbnail_ffmpeg_path', $formData['videothumbnail_ffmpeg_path']);
        $settings->set('videothumbnail_frames_count', $formData['videothumbnail_frames_count']);
        $settings->set('videothumbnail_default_frame', $formData['videothumbnail_default_frame']);

        $ffmpegPath = $formData['videothumbnail_ffmpeg_path'];
        if (!is_executable($ffmpegPath)) {
            $controller->flashMessenger()->addError('FFmpeg path is not executable or not found.');
            return false;
        }

        $output = [];
        $returnVar = 0;
        exec(escapeshellcmd($ffmpegPath) . ' -version', $output, $returnVar);
        if ($returnVar !== 0) {
            $controller->flashMessenger()->addError('FFmpeg could not be found at the specified path.');
            return false;
        }

        return true;
    }

    public function getAutoloaderConfig(): array
    {
        return [
            'Laminas\Loader\StandardAutoloader' => [
                'namespaces' => [
                    __NAMESPACE__ => __DIR__ . '/src',
                ],
            ],
        ];
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);
        $application = $event->getApplication();
        $serviceManager = $application->getServiceManager();
        $viewHelperManager = $serviceManager->get('ViewHelperManager');
        $viewHelperManager->setAlias('videoThumbnailSelector', 'VideoThumbnail\View\Helper\VideoThumbnailSelector');
    }

    public function install(ServiceLocatorInterface $serviceLocator): void
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        $settings->set('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
        $settings->set('videothumbnail_frames_count', 5);
        $settings->set('videothumbnail_default_frame', 10);

        $tempDir = OMEKA_PATH . '/files/video-thumbnails';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator): void
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        $settings->delete('videothumbnail_ffmpeg_path');
        $settings->delete('videothumbnail_frames_count');
        $settings->delete('videothumbnail_default_frame');

        $tempDir = OMEKA_PATH . '/files/video-thumbnails';
        if (is_dir($tempDir)) {
            $this->recursiveRemoveDirectory($tempDir);
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach('Omeka\Controller\Admin\Media', 'view.edit.form.after', [$this, 'handleViewEditFormAfter']);
        $sharedEventManager->attach('Omeka\Api\Adapter\MediaAdapter', 'api.update.post', [$this, 'handleMediaUpdatePost']);
        $sharedEventManager->attach('Omeka\Controller\Admin\Index', 'view.layout', [$this, 'addAdminWarning']);
    }

    public function handleViewEditFormAfter($event): void
    {
        $view = $event->getTarget();
        $media = $view->media;

        if (!$media || !$this->isVideoMedia($media)) {
            return;
        }

        echo $view->videoThumbnailSelector($media);
    }

    public function handleMediaUpdatePost($event): void
    {
        $request = $event->getParam('request');
        $media = $event->getParam('response')->getContent();

        if (!$this->isVideoMedia($media)) {
            return;
        }

        $data = $request->getContent();
        if (isset($data['videothumbnail_frame'])) {
            $this->updateVideoThumbnail($media, $data['videothumbnail_frame']);
        }
    }

    public function addAdminWarning($event): void
    {
        $view = $event->getTarget();
        $serviceLocator = $this->getServiceLocator();
        $viewHelpers = $serviceLocator->get('ViewHelperManager');
        $url = $viewHelpers->get('url');

        $message = sprintf(
            'You can %s to regenerate all video thumbnails.',
            sprintf(
                '<a href="%s">add a new job</a>',
                $url('admin/id', ['controller' => 'job', 'action' => 'add', 'id' => 'VideoThumbnail\\Job\\ExtractFrames'])
            )
        );

        $flashMessenger = $viewHelpers->get('flashMessenger');
        $flashMessenger->addSuccess($message);
    }

    protected function isVideoMedia($media): bool
    {
        $mediaType = $media instanceof Media ? $media->getMediaType() : ($media instanceof MediaRepresentation ? $media->mediaType() : null);
        return $mediaType && strpos($mediaType, 'video/') === 0;
    }

    protected function updateVideoThumbnail($media, $selectedFrame): void
    {
        try {
            $serviceLocator = $this->getServiceLocator();
            $settings = $serviceLocator->get('Omeka\Settings');
            $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path');

            $extractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
            $filePath = $media instanceof MediaRepresentation ? $media->originalFilePath() : $media->getFilePath();
            $mediaId = $media instanceof MediaRepresentation ? $media->id() : $media->getId();

            $duration = $extractor->getVideoDuration($filePath);
            $frameTime = ($duration * $selectedFrame) / 100;
            $extractedFrame = $extractor->extractFrame($filePath, $frameTime);

            if ($extractedFrame) {
                $tempManager = $serviceLocator->get('Omeka\File\TempFileFactory');
                $tempFile = $tempManager->build();
                $tempFile->setSourceName('thumbnail.jpg');
                $tempFile->setTempPath($extractedFrame);

                $entityManager = $serviceLocator->get('Omeka\EntityManager');
                $mediaEntity = $entityManager->find('Omeka\Entity\Media', $mediaId);

                $fileManager = $serviceLocator->get('Omeka\File\Manager');
                $fileManager->storeThumbnails($tempFile, $mediaEntity);
                $entityManager->flush();

                unlink($tempFile->getTempPath());
            }
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }
    }

    protected function recursiveRemoveDirectory($directory): void
    {
        if (is_dir($directory)) {
            $objects = scandir($directory);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    $path = $directory . DIRECTORY_SEPARATOR . $object;
                    is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
                }
            }
            rmdir($directory);
        }
    }
}

