<?php
namespace VideoThumbnail\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\View\Model\JsonModel;
use Omeka\Stdlib\Message;
use VideoThumbnail\Form\ConfigBatchForm;

class VideoThumbnailController extends AbstractActionController
{
    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;
    
    /**
     * @var \Omeka\File\Manager
     */
    protected $fileManager;
    
    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;
    
    /**
     * @param \Doctrine\ORM\EntityManager $entityManager
     * @param \Omeka\File\Manager $fileManager
     */
    public function __construct($entityManager, $fileManager)
    {
        $this->entityManager = $entityManager;
        $this->fileManager = $fileManager;
    }
    
    /**
     * Set settings service
     *
     * @param \Omeka\Settings\Settings $settings
     * @return self
     */
    public function setSettings($settings)
    {
        $this->settings = $settings;
        return $this;
    }
    
    /**
     * Index action - shows general information and configuration options
     */
    public function indexAction()
    {
        $form = new ConfigBatchForm();
        $form->init();
        
        // Get current settings
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
                
                // Save settings
                $this->settings->set('videothumbnail_default_frame', $formData['default_frame_position']);
                $this->settings->set('videothumbnail_supported_formats', $formData['supported_formats']);
                
                $this->messenger()->addSuccess('Video thumbnail settings updated.'); // @translate
                
                // If regenerate option is selected, dispatch the job
                if (!empty($formData['regenerate_thumbnails'])) {
                    $dispatcher = $this->jobDispatcher();
                    $job = $dispatcher->dispatch('VideoThumbnail\Job\ExtractFrames', [
                        'frame_position' => $formData['default_frame_position'],
                    ]);
                    
                    $message = new Message(
                        'Regenerating video thumbnails in the background (job %s). This may take a while.', // @translate
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
        
        // Get supported formats from settings
        $supportedFormats = $this->settings->get('videothumbnail_supported_formats', ['video/mp4', 'video/quicktime']);
        
        // Verify this is a supported video media
        if (!in_array($mediaType, $supportedFormats)) {
            $this->messenger()->addError('This media is not a supported video format. Supported formats: ' . implode(', ', $supportedFormats)); // @translate
            return $this->redirect()->toRoute('admin/media', ['id' => $mediaId]);
        }
        
        $view = new ViewModel();
        $view->setVariable('media', $media);
        
        // Get settings
        $ffmpegPath = $this->settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
        $framesCount = $this->settings->get('videothumbnail_frames_count', 5);
        
        // Check if the configured path is valid
        if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
            // Try using the default path instead
            if (file_exists('/usr/bin/ffmpeg') && is_executable('/usr/bin/ffmpeg')) {
                $ffmpegPath = '/usr/bin/ffmpeg';
                // Update the setting for future use
                $this->settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
            }
        }
        
        // Set up the view with minimal information
        $view->setVariable('mediaId', $mediaId);
        $view->setVariable('framesCount', $framesCount);
        
        // Only get duration - this is a quick operation
        $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
        $videoPath = $media->originalFilePath();
        $duration = $videoFrameExtractor->getVideoDuration($videoPath);
        
        $view->setVariable('duration', $duration);
        
        // Get current frame position if it exists
        $mediaData = $media->data();
        $currentFramePercent = $mediaData['thumbnail_frame_percentage'] ?? null;
        $view->setVariable('currentFramePercent', $currentFramePercent);
        
        // Instead of extracting frames synchronously, we'll use JavaScript to load them asynchronously
        // The view will render without frames initially, then JavaScript will fetch them via AJAX
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
        
        // Get supported formats from settings
        $supportedFormats = $this->settings->get('videothumbnail_supported_formats', ['video/mp4', 'video/quicktime']);
        
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
            
            // Verify this is a supported video media
            if (!in_array($mediaType, $supportedFormats)) {
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
            $ffmpegPath = $this->settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
            
            // Check if the configured path is valid
            if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
                // Try using the default path instead
                if (file_exists('/usr/bin/ffmpeg') && is_executable('/usr/bin/ffmpeg')) {
                    $ffmpegPath = '/usr/bin/ffmpeg';
                    // Update the setting for future use
                    $this->settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
                }
            }
            
            if (!file_exists($ffmpegPath)) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'FFmpeg not found at path: ' . $ffmpegPath, // @translate
                ]);
            }
            
            if (!is_executable($ffmpegPath)) {
                return new JsonModel([
                    'success' => false,
                    'message' => 'FFmpeg is not executable at path: ' . $ffmpegPath, // @translate
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
        
        $framePosition = $this->params()->fromPost('frame_position', $this->settings->get('videothumbnail_default_frame', 10));
        
        $dispatcher = $this->jobDispatcher();
        $job = $dispatcher->dispatch('VideoThumbnail\Job\ExtractFrames', [
            'frame_position' => $framePosition,
        ]);
        
        $message = new Message(
            'Regenerating video thumbnails in the background (job %s). This may take a while.', // @translate
            $job->getId()
        );
        $this->messenger()->addSuccess($message);
        
        return $this->redirect()->toRoute('admin/video-thumbnail');
    }
    
    /**
     * API endpoint to extract a single frame for preview with improved error handling and debug logging
     */
    public function extractFrameAction()
    {
        if (!$this->getRequest()->isPost()) {
            return new JsonModel([
                'success' => false,
                'message' => 'Method not allowed', // @translate
            ]);
        }
        
        $mediaId = $this->params()->fromPost('media_id');
        $position = $this->params()->fromPost('position');
        
        error_log('VideoThumbnail: extractFrameAction called for media ID ' . $mediaId . ' at position ' . $position);
        
        if (!$mediaId || !isset($position)) {
            error_log('VideoThumbnail: Missing required parameters');
            return new JsonModel([
                'success' => false,
                'message' => 'Missing required parameters', // @translate
            ]);
        }
        
        // Validate media ID and position
        if (!is_numeric($mediaId) || !is_numeric($position)) {
            error_log('VideoThumbnail: Invalid parameters - mediaId: ' . $mediaId . ', position: ' . $position);
            return new JsonModel([
                'success' => false,
                'message' => 'Invalid parameters', // @translate
            ]);
        }
        
        // Convert to proper types
        $mediaId = (int)$mediaId;
        $position = (float)$position;
        $framePath = null;
        
        // Get supported formats from settings
        $supportedFormats = $this->settings->get('videothumbnail_supported_formats', ['video/mp4', 'video/quicktime']);
        
        // Get media
        try {
            error_log('VideoThumbnail: Reading media ' . $mediaId);
            $response = $this->api()->read('media', $mediaId);
            if (!$response) {
                error_log('VideoThumbnail: Media not found: ' . $mediaId);
                return new JsonModel([
                    'success' => false,
                    'message' => 'Media not found', // @translate
                ]);
            }
            
            $media = $response->getContent();
            $mediaType = $media->mediaType();
            
            error_log('VideoThumbnail: Media type: ' . $mediaType);
            
            // Verify this is a supported video media
            if (!in_array($mediaType, $supportedFormats)) {
                error_log('VideoThumbnail: Unsupported media type: ' . $mediaType);
                return new JsonModel([
                    'success' => false,
                    'message' => 'This media is not a supported video format.', // @translate
                ]);
            }
            
            $videoPath = $media->originalFilePath();
            error_log('VideoThumbnail: Video path: ' . $videoPath);
            
            if (!file_exists($videoPath)) {
                error_log('VideoThumbnail: Video file does not exist: ' . $videoPath);
                return new JsonModel([
                    'success' => false,
                    'message' => 'Video file does not exist', // @translate
                ]);
            }
            
            if (!is_readable($videoPath)) {
                error_log('VideoThumbnail: Video file is not readable: ' . $videoPath);
                return new JsonModel([
                    'success' => false,
                    'message' => 'Video file is not readable', // @translate
                ]);
            }
            
            // Get FFmpeg path from settings
            $ffmpegPath = $this->settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
            error_log('VideoThumbnail: FFmpeg path: ' . $ffmpegPath);
            $duration = null;
            
            // Check if the configured path is valid
            if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
                error_log('VideoThumbnail: Invalid FFmpeg path, will use default path');
                // Try using the default path instead
                if (file_exists('/usr/bin/ffmpeg') && is_executable('/usr/bin/ffmpeg')) {
                    $ffmpegPath = '/usr/bin/ffmpeg';
                    // Update the setting for future use
                    $this->settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
                    error_log('VideoThumbnail: Updated FFmpeg path to: ' . $ffmpegPath);
                }
            }
            
            if (!file_exists($ffmpegPath)) {
                error_log('VideoThumbnail: FFmpeg not found: ' . $ffmpegPath);
                return new JsonModel([
                    'success' => false,
                    'message' => 'FFmpeg not found at path: ' . $ffmpegPath . '. Please configure a valid path in the module settings.', // @translate
                ]);
            }
            
            if (!is_executable($ffmpegPath)) {
                error_log('VideoThumbnail: FFmpeg is not executable: ' . $ffmpegPath);
                return new JsonModel([
                    'success' => false,
                    'message' => 'FFmpeg is not executable at path: ' . $ffmpegPath, // @translate
                ]);
            }
            
            // Extract the frame
            error_log('VideoThumbnail: Creating VideoFrameExtractor');
            $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
            
            // Get duration if we received a percentage
            if ($position <= 100) {
                error_log('VideoThumbnail: Getting video duration');
                $duration = $videoFrameExtractor->getVideoDuration($videoPath);
                
                if ($duration <= 0) {
                    error_log('VideoThumbnail: Could not determine video duration');
                    return new JsonModel([
                        'success' => false,
                        'message' => 'Could not determine video duration', // @translate
                    ]);
                }
                
                $timePosition = ($position / 100) * $duration;
                error_log('VideoThumbnail: Position ' . $position . '% = ' . $timePosition . ' seconds of ' . $duration . ' seconds');
            } else {
                // Assume position is already in seconds
                $timePosition = $position;
                error_log('VideoThumbnail: Using time position directly: ' . $timePosition . ' seconds');
            }
            
            error_log('VideoThumbnail: Extracting frame at ' . $timePosition . ' seconds');
            $framePath = $videoFrameExtractor->extractFrame($videoPath, $timePosition);
            
            if (!$framePath) {
                error_log('VideoThumbnail: Failed to extract frame');
                return new JsonModel([
                    'success' => false,
                    'message' => 'Failed to extract frame', // @translate
                ]);
            }
            
            error_log('VideoThumbnail: Frame extracted to ' . $framePath);
            
            // Check filesize to avoid memory issues with large frames
            $filesize = filesize($framePath);
            error_log('VideoThumbnail: Frame file size: ' . $filesize . ' bytes');
            
            if ($filesize > 10000000) { // 10MB limit
                error_log('VideoThumbnail: Frame file size too large: ' . $filesize . ' bytes');
                unlink($framePath);
                return new JsonModel([
                    'success' => false,
                    'message' => 'Extracted frame is too large to process', // @translate
                ]);
            }
            
            // For safer memory handling, read the file in chunks if it's large
            error_log('VideoThumbnail: Converting frame to base64');
            if ($filesize > 1000000) { // 1MB threshold
                // Read in chunks to avoid memory issues
                error_log('VideoThumbnail: Using chunked reading for large frame file');
                $imageData = '';
                $handle = fopen($framePath, 'rb');
                while (!feof($handle)) {
                    $imageData .= base64_encode(fread($handle, 512000)); // 500KB chunks
                }
                fclose($handle);
            } else {
                // Standard method for smaller files
                $imageData = base64_encode(file_get_contents($framePath));
            }
            
            // Clean up temporary file
            error_log('VideoThumbnail: Cleaning up temporary file');
            if (file_exists($framePath)) {
                unlink($framePath);
            }
            
            error_log('VideoThumbnail: Frame extraction complete, returning JSON response');
            return new JsonModel([
                'success' => true,
                'image' => 'data:image/jpeg;base64,' . $imageData,
                'time' => $timePosition,
                'percent' => $duration ? ($timePosition / $duration) * 100 : $position,
            ]);
            
        } catch (\Exception $e) {
            error_log('VideoThumbnail: Error extracting video frame: ' . $e->getMessage());
            error_log('VideoThumbnail: ' . $e->getTraceAsString());
            
            // Clean up any temporary files
            if ($framePath && file_exists($framePath)) {
                unlink($framePath);
            }
            
            return new JsonModel([
                'success' => false,
                'message' => 'An error occurred while extracting the frame: ' . $e->getMessage(), // @translate
            ]);
        }
    }
    
    /**
     * Get total number of video files in the system based on supported formats
     */
    protected function getTotalVideos()
    {
        $supportedFormats = $this->settings->get('videothumbnail_supported_formats', ['video/mp4', 'video/quicktime']);
        if (empty($supportedFormats)) {
            return 0;
        }
        
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('COUNT(m.id)')
           ->from('Omeka\Entity\Media', 'm')
           ->where($qb->expr()->in('m.mediaType', ':formats'))
           ->setParameter('formats', $supportedFormats);
        
        return $qb->getQuery()->getSingleScalarResult();
    }
    
    /**
     * Get the file manager service
     */
    protected function getFileManager()
    {
        return $this->fileManager;
    }
    
    /**
     * Get the entity manager
     */
    protected function getEntityManager()
    {
        return $this->entityManager;
    }
}
