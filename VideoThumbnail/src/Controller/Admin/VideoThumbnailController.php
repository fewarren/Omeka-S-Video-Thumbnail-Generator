<?php
namespace VideoThumbnail\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;
use Omeka\Stdlib\Message;

class VideoThumbnailController extends AbstractActionController
{
    /**
     * Index action - shows general information
     */
    public function indexAction()
    {
        $view = new ViewModel();
        $view->setVariable('totalVideos', $this->getTotalVideos());
        return $view;
    }

    /**
     * Frame selection action for a specific media
     */
    public function selectFrameAction()
    {
        $mediaId = $this->params('id');
        $response = $this->api()->read('media', $mediaId);
        if (!$response) {
            $this->messenger()->addError('Media not found.'); // @translate
            return $this->redirect()->toRoute('admin/media');
        }
        
        $media = $response->getContent();
        $mediaType = $media->mediaType();
        
        // Verify this is a video media
        if (!in_array($mediaType, ['video/mp4', 'video/quicktime'])) {
            $this->messenger()->addError('This media is not a supported video format.'); // @translate
            return $this->redirect()->toRoute('admin/media', ['id' => $mediaId]);
        }
        
        $view = new ViewModel();
        $view->setVariable('media', $media);
        
        // Get FFmpeg path from settings
        $settings = $this->settings();
        $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
        $framesCount = $settings->get('videothumbnail_frames_count', 5);
        
        // Extract frames for selection
        $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
        $videoPath = $media->originalFilePath();
        
        $duration = $videoFrameExtractor->getVideoDuration($videoPath);
        $frames = [];
        
        // Extract frames at different positions
        for ($i = 0; $i < $framesCount; $i++) {
            $position = ($i / ($framesCount - 1)) * $duration;
            $framePath = $videoFrameExtractor->extractFrame($videoPath, $position);
            
            if ($framePath) {
                // Convert to base64 for display
                $imageData = base64_encode(file_get_contents($framePath));
                $frames[] = [
                    'time' => $position,
                    'percent' => ($position / $duration) * 100,
                    'image' => 'data:image/jpeg;base64,' . $imageData,
                ];
                
                // Clean up temporary file
                @unlink($framePath);
            }
        }
        
        $view->setVariable('frames', $frames);
        $view->setVariable('duration', $duration);
        
        return $view;
    }

    /**
     * API endpoint to extract and save a specific frame
     */
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
                'message' => 'Missing required parameters', // @translate
            ]);
        }
        
        // Get media
        $response = $this->api()->read('media', $mediaId);
        if (!$response) {
            return new JsonModel([
                'success' => false,
                'message' => 'Media not found', // @translate
            ]);
        }
        
        $media = $response->getContent();
        $videoPath = $media->originalFilePath();
        
        // Get FFmpeg path from settings
        $settings = $this->settings();
        $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
        
        // Extract the frame
        $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
        $framePath = $videoFrameExtractor->extractFrame($videoPath, $position);
        
        if (!$framePath) {
            return new JsonModel([
                'success' => false,
                'message' => 'Failed to extract frame', // @translate
            ]);
        }
        
        // Create a temp file object
        $tempFileFactory = $this->tempFileFactory();
        $tempFile = $tempFileFactory->build();
        $tempFile->setSourceName('thumbnail.jpg');
        $tempFile->setTempPath($framePath);
        
        // Update the media's thumbnails
        $fileManager = $this->getFileManager();
        $mediaEntity = $this->getEntityManager()->find('Omeka\Entity\Media', $mediaId);
        $fileManager->storeThumbnails($tempFile, $mediaEntity);
        
        // Store frame data
        $duration = $videoFrameExtractor->getVideoDuration($videoPath);
        $data = $mediaEntity->getData() ?: [];
        $data['video_duration'] = $duration;
        $data['thumbnail_frame_time'] = $position;
        $data['thumbnail_frame_percentage'] = ($position / $duration) * 100;
        $mediaEntity->setData($data);
        
        // Save changes
        $this->getEntityManager()->flush();
        
        // Clean up
        @unlink($framePath);
        
        $this->messenger()->addSuccess('Thumbnail updated successfully'); // @translate
        
        return new JsonModel([
            'success' => true,
            'message' => 'Thumbnail updated successfully', // @translate
            'thumbnailUrl' => $media->thumbnailUrl('medium'),
        ]);
    }

    /**
     * Run batch job to regenerate all video thumbnails
     */
    public function batchRegenerateAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin');
        }
        
        $dispatcher = $this->jobDispatcher();
        $job = $dispatcher->dispatch('VideoThumbnail\Job\ExtractFrames');
        
        $message = new Message(
            'Regenerating video thumbnails in the background (job %s). This may take a while.', // @translate
            $job->getId()
        );
        $this->messenger()->addSuccess($message);
        
        return $this->redirect()->toRoute('admin/video-thumbnail');
    }
    
    /**
     * Get total number of video files in the system
     */
    protected function getTotalVideos()
    {
        $query = $this->getEntityManager()->createQuery('
            SELECT COUNT(m.id) FROM Omeka\Entity\Media m 
            WHERE m.mediaType = :mp4 OR m.mediaType = :mov
        ');
        $query->setParameters([
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
        ]);
        
        return $query->getSingleScalarResult();
    }
    
    /**
     * Get the file manager service
     */
    protected function getFileManager()
    {
        return $this->getServiceLocator()->get('Omeka\File\Manager');
    }
    
    /**
     * Get the entity manager
     */
    protected function getEntityManager()
    {
        return $this->getServiceLocator()->get('Omeka\EntityManager');
    }
}
