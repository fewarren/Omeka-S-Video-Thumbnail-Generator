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
        
        // Validate media ID and position
        if (!is_numeric($mediaId) || !is_numeric($position)) {
            return new JsonModel([
                'success' => false,
                'message' => 'Invalid parameters', // @translate
            ]);
        }
        
        // Convert to proper types
        $mediaId = (int)$mediaId;
        $position = (float)$position;
        
        // Get media
        try {
            $response = $this->api()->read('media', $mediaId);
            if (!$response) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Media not found', // @translate
                ]);
            }
            
            $media = $response->getContent();
            $mediaType = $media->mediaType();
            
            // Verify this is a video media
            if (strpos($mediaType, 'video/') !== 0) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'This media is not a supported video format.', // @translate
                ]);
            }
            
            $videoPath = $media->originalFilePath();
            if (!file_exists($videoPath) || !is_readable($videoPath)) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Video file is not accessible', // @translate
                ]);
            }
            
            // Get FFmpeg path from settings
            $settings = $this->settings();
            $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
            
            if (!is_executable($ffmpegPath)) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'FFmpeg is not executable', // @translate
                ]);
            }
            
            // Extract the frame
            $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
            
            // Get duration to validate position
            $duration = $videoFrameExtractor->getVideoDuration($videoPath);
            if ($duration <= 0) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'Could not determine video duration', // @translate
                ]);
            }
            
            // Ensure position is within valid range (0-100%)
            $percentage = max(0, min(100, $position));
            $timePosition = ($percentage / 100) * $duration;
            
            $framePath = $videoFrameExtractor->extractFrame($videoPath, $timePosition);
            
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
            $data = $mediaEntity->getData() ?: [];
            $data['video_duration'] = $duration;
            $data['thumbnail_frame_time'] = $timePosition;
            $data['thumbnail_frame_percentage'] = $percentage;
            $mediaEntity->setData($data);
            
            // Save changes
            $this->getEntityManager()->flush();
            
            // Clean up
            if (file_exists($framePath)) {
                unlink($framePath);
            }
            
            $this->messenger()->addSuccess('Thumbnail updated successfully'); // @translate
            
            return new JsonModel([
                'success' => true,
                'message' => 'Thumbnail updated successfully', // @translate
                'thumbnailUrl' => $media->thumbnailUrl('medium'),
            ]);
            
        } catch (\Exception $e) {
            // Clean up any temporary files
            if (isset($framePath) && file_exists($framePath)) {
                unlink($framePath);
            }
            
            error_log('Error saving video frame: ' . $e->getMessage());
            
            return new JsonModel([
                'success' => false,
                'message' => 'An error occurred while saving the thumbnail', // @translate
            ]);
        }
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
