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
use Omeka\Entity\Job;
use Omeka\Api\Representation\MediaRepresentation;

class Module extends AbstractModule
{
    /** Module body */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Get this module's configuration form.
     *
     * @param PhpRenderer $renderer
     * @return string
     */
    public function getConfigForm(PhpRenderer $renderer)
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

    /**
     * Handle this module's configuration form.
     *
     * @param AbstractController $controller
     * @return bool False if there was an error during handling
     */
    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(ConfigForm::class);

        $form->init();
        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }

        $formData = $form->getData();
        $settings->set('videothumbnail_ffmpeg_path', $formData['videothumbnail_ffmpeg_path']);
        $settings->set('videothumbnail_frames_count', $formData['videothumbnail_frames_count']);
        $settings->set('videothumbnail_default_frame', $formData['videothumbnail_default_frame']);

        // Test FFmpeg path
        $ffmpegPath = $formData['videothumbnail_ffmpeg_path'];
        $output = [];
        $returnVar = 0;
        exec(escapeshellcmd($ffmpegPath) . ' -version', $output, $returnVar);
        if ($returnVar !== 0) {
            $controller->messenger()->addError('FFmpeg could not be found at the specified path. Please check the path and ensure FFmpeg is installed.');
            return false;
        }

        return true;
    }
 
    public function getAutoloaderConfig()
    {
        return [
            'Laminas\Loader\StandardAutoloader' => [
                'namespaces' => [
                    __NAMESPACE__ => __DIR__ . '/src',
                ],
            ],
        ];
    }
    
    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);

        $application = $event->getApplication();
        $serviceManager = $application->getServiceManager();
        $viewHelperManager = $serviceManager->get('ViewHelperManager');
        $viewHelperManager->setAlias('videoThumbnailSelector', 'VideoThumbnail\View\Helper\VideoThumbnailSelector');
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        $settings->set('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
        $settings->set('videothumbnail_frames_count', 5);
        $settings->set('videothumbnail_default_frame', 10);
        
        // Create temporary directory for frame extraction
        $tempDir = OMEKA_PATH . '/files/video-thumbnails';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
    }
     
    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        $settings->delete('videothumbnail_ffmpeg_path');
        $settings->delete('videothumbnail_frames_count');
        $settings->delete('videothumbnail_default_frame');
        
        // Remove temporary directory
        $tempDir = OMEKA_PATH . '/files/video-thumbnails';
        if (is_dir($tempDir)) {
            $this->recursiveRemoveDirectory($tempDir);
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Add frame selection tab to media edit form
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.edit.form.after',
            [$this, 'handleViewEditFormAfter']
        );

        // Handle thumbnail selection when media is saved
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.update.post',
            [$this, 'handleMediaUpdatePost']
        );
        
        // Add job to admin dashboard
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Index',
            'view.layout',
            [$this, 'addAdminWarning']
        );
    }

    public function handleViewEditFormAfter($event)
    {
        $view = $event->getTarget();
        $media = $view->media;
        
        // Only show for video media
        if (!$media || !$this->isVideoMedia($media)) {
            return;
        }
        
        echo $view->videoThumbnailSelector($media);
    }

    public function handleMediaUpdatePost($event)
    {
        $request = $event->getParam('request');
        $media = $event->getParam('response')->getContent();
        
        if (!$this->isVideoMedia($media)) {
            return;
        }
        
        $data = $request->getContent();
        if (isset($data['videothumbnail_frame'])) {
            $selectedFrame = $data['videothumbnail_frame'];
            $this->updateVideoThumbnail($media, $selectedFrame);
        }
    }

    public function addAdminWarning($event)
    {
        $view = $event->getTarget();
        $serviceLocator = $this->getServiceLocator();
        $viewHelpers = $serviceLocator->get('ViewHelperManager');
        $url = $viewHelpers->get('url');
        
        $jobUrl = $url('admin/job/browse');
        $message = sprintf(
            'You can %s to regenerate all video thumbnails.',
            sprintf(
                '<a href="%s">add a new job</a>',
                $url('admin/id', ['controller' => 'job', 'action' => 'add', 'id' => 'VideoThumbnail\Job\ExtractFrames'])
            )
        );
        
        $messenger = $viewHelpers->get('messenger');
        $messenger->addSuccess($message);
    }

    /**
     * Check if a media is a supported video
     */
    protected function isVideoMedia($media)
    {
        if ($media instanceof Media) {
            $mediaType = $media->getMediaType();
        } elseif ($media instanceof MediaRepresentation) {
            $mediaType = $media->mediaType();
        } else {
            return false;
        }
        
        return in_array($mediaType, ['video/mp4', 'video/quicktime']);
    }

    /**
     * Update video thumbnail with selected frame
     */
    protected function updateVideoThumbnail($media, $selectedFrame)
    {
        $serviceLocator = $this->getServiceLocator();
        $settings = $serviceLocator->get('Omeka\Settings');
        $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path');
        
        $extractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
        
        if ($media instanceof MediaRepresentation) {
            $filePath = $media->originalFilePath();
            $mediaId = $media->id();
        } else {
            $filePath = $media->getFilePath();
            $mediaId = $media->getId();
        }
        
        // Get video duration
        $duration = $extractor->getVideoDuration($filePath);
        $frameTime = ($duration * $selectedFrame) / 100;
        
        // Extract frame and set as thumbnail
        $extractedFrame = $extractor->extractFrame($filePath, $frameTime);
        if ($extractedFrame) {
            // Save thumbnail
            $tempManager = $serviceLocator->get('Omeka\File\TempFileFactory');
            $tempFile = $tempManager->build();
            $tempFile->setSourceName('thumbnail.jpg');
            $tempFile->setTempPath($extractedFrame);
            
            $entityManager = $serviceLocator->get('Omeka\EntityManager');
            $mediaEntity = $entityManager->find('Omeka\Entity\Media', $mediaId);
            
            $fileManager = $serviceLocator->get('Omeka\File\Manager');
            $fileManager->storeThumbnails($tempFile, $mediaEntity);
            
            $entityManager->flush();
            
            // Clean up
            unlink($tempFile);
        }
    }

    /**
     * Recursively remove a directory
     */
    protected function recursiveRemoveDirectory($directory)
    {
        if (is_dir($directory)) {
            $objects = scandir($directory);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($directory . DIRECTORY_SEPARATOR . $object)) {
                        $this->recursiveRemoveDirectory($directory . DIRECTORY_SEPARATOR . $object);
                    } else {
                        unlink($directory . DIRECTORY_SEPARATOR . $object);
                    }
                }
            }
            rmdir($directory);
        }
    }
}
