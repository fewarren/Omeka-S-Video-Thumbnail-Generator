<?php
namespace VideoThumbnail\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;
use Omeka\Stdlib\Message;
use VideoThumbnail\Form\ConfigBatchForm;

class VideoThumbnailController extends AbstractActionController
{
    protected $entityManager;
    protected $fileManager;
    protected $settings;

    public function __construct($entityManager, $fileManager)
    {
        $this->entityManager = $entityManager;
        $this->fileManager = $fileManager;
    }

    public function setSettings($settings)
    {
        $this->settings = $settings;
        return $this;
    }

    public function indexAction()
    {
        $form = new ConfigBatchForm();
        $form->init();

        $supportedFormats = $this->settings->get('videothumbnail_supported_formats', ['video/mp4', 'video/quicktime']);
        if (!is_array($supportedFormats)) {
            $supportedFormats = ['video/mp4', 'video/quicktime'];
        }

        $form->setData([
            'default_frame_position' => $this->settings->get('videothumbnail_default_frame', 10),
            'supported_formats' => $supportedFormats,
        ]);

        $request = $this->getRequest();
        if ($request->isPost()) {
            $form->setData($request->getPost());
            if ($form->isValid()) {
                $formData = $form->getData();
                $this->settings->set('videothumbnail_default_frame', $formData['default_frame_position']);
                $this->settings->set('videothumbnail_supported_formats', $formData['supported_formats']);
                $this->messenger()->addSuccess('Video thumbnail settings updated.');

                if (!empty($formData['regenerate_thumbnails'])) {
                    $dispatcher = $this->jobDispatcher();
                    $job = $dispatcher->dispatch('VideoThumbnail\Job\ExtractFrames', [
                        'frame_position' => $formData['default_frame_position'],
                    ]);

                    $message = new Message(
                        'Regenerating video thumbnails in the background (job %s). This may take a while.',
                        $job->getId()
                    );
                    $this->messenger()->addSuccess($message);
                }

                return $this->redirect()->toRoute('admin/video-thumbnail');
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel();
        $view->setVariable('form', $form);
        $view->setVariable('totalVideos', $this->getTotalVideos());
        $view->setVariable('supportedFormats', implode(', ', $supportedFormats));
        return $view;
    }

    public function saveFrameAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin');
        }

        $mediaId = $this->params()->fromPost('media_id');
        $position = $this->params()->fromPost('position');
        if (!$mediaId || !isset($position)) {
            return new JsonModel([
                'success' => false,
                'message' => 'Missing required parameters',
            ]);
        }

        if (!is_numeric($mediaId) || !is_numeric($position)) {
            return new JsonModel([
                'success' => false,
                'message' => 'Invalid parameters',
            ]);
        }

        $mediaId = (int)$mediaId;
        $position = (float)$position;

        $supportedFormats = $this->settings->get('videothumbnail_supported_formats', ['video/mp4', 'video/quicktime']);
        try {
            $response = $this->api()->read('media', $mediaId);
            if (!$response) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Media not found',
                ]);
            }

            $media = $response->getContent();
            $mediaType = $media->mediaType();
            if (!in_array($mediaType, $supportedFormats)) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'This media is not a supported video format.',
                ]);
            }

            $videoPath = $media->originalFilePath();
            if (!file_exists($videoPath) || !is_readable($videoPath)) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Video file is not accessible',
                ]);
            }

            $ffmpegPath = $this->getValidFFmpegPath();
            if (!$ffmpegPath) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'FFmpeg not found or not executable. Please configure a valid path.',
                ]);
            }

            $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
            $duration = $videoFrameExtractor->getVideoDuration($videoPath);
            if ($duration <= 0) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Could not determine video duration',
                ]);
            }

            $percentage = max(0, min(100, $position));
            $timePosition = ($percentage / 100) * $duration;
            $framePath = $videoFrameExtractor->extractFrame($videoPath, $timePosition);
            if (!$framePath) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Failed to extract frame',
                ]);
            }

            $tempFileFactory = $this->tempFileFactory();
            $tempFile = $tempFileFactory->build();
            $tempFile->setSourceName('thumbnail.jpg');
            $tempFile->setTempPath($framePath);

            $fileManager = $this->fileManager;
            $mediaEntity = $this->entityManager->find('Omeka\Entity\Media', $mediaId);
            $fileManager->storeThumbnails($tempFile, $mediaEntity);

            $data = $mediaEntity->getData() ?: [];
            $data['video_duration'] = $duration;
            $data['thumbnail_frame_time'] = $timePosition;
            $data['thumbnail_frame_percentage'] = $percentage;
            $mediaEntity->setData($data);

            $this->entityManager->flush();

            if (file_exists($framePath)) {
                unlink($framePath);
            }

            return new JsonModel([
                'success' => true,
                'message' => 'Thumbnail updated successfully',
                'thumbnailUrl' => $media->thumbnailUrl('medium'),
            ]);
        } catch (\Exception $e) {
            return new JsonModel([
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage(),
            ]);
        }
    }

    protected function getValidFFmpegPath()
    {
        $ffmpegPath = $this->settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
        if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
            $possiblePaths = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'];
            foreach ($possiblePaths as $path) {
                if (file_exists($path) && is_executable($path)) {
                    $this->settings->set('videothumbnail_ffmpeg_path', $path);
                    return $path;
                }
            }
            return false;
        }
        return $ffmpegPath;
    }
}
