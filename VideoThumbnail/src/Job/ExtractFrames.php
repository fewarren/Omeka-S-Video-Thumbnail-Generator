<?php
namespace VideoThumbnail\Job;

use Omeka\Job\AbstractJob;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;

class ExtractFrames extends AbstractJob
{
    /**
     * Get memory usage in a human-readable format
     * 
     * @param int $bytes
     * @return string
     */
    protected function getMemoryUsage($bytes = null)
    {
        if ($bytes === null) {
            $bytes = memory_get_usage(true);
        }
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Check if memory usage is approaching the limit
     * 
     * @param float $threshold Percentage threshold (0-1)
     * @return bool True if memory usage is above threshold
     */
    protected function isMemoryLimitApproaching($threshold = 0.8)
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            // No memory limit
            return false;
        }
        
        // Convert memory limit to bytes
        $memoryLimit = $this->convertToBytes($memoryLimit);
        $currentUsage = memory_get_usage(true);
        
        return ($currentUsage / $memoryLimit) > $threshold;
    }
    
    /**
     * Convert PHP memory value to bytes
     * 
     * @param string $memoryValue
     * @return int
     */
    protected function convertToBytes($memoryValue)
    {
        $memoryValue = trim($memoryValue);
        $last = strtolower($memoryValue[strlen($memoryValue) - 1]);
        $value = (int)$memoryValue;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
    
    /**
     * Get job arguments or an empty array if not available
     *
     * Compatibility method for Omeka S versions that may have different AbstractJob implementations
     * 
     * @return array
     */
    protected function getJobArgs(): array
    {
        // First try to access job args property if it exists in the parent class
        if (property_exists($this, 'job') && is_object($this->job) && method_exists($this->job, 'getArgs')) {
            return $this->job->getArgs() ?: [];
        }
        
        // Try direct args property if available
        if (property_exists($this, 'args')) {
            return $this->args ?: [];
        }
        
        // Fallback for other Omeka S versions where args might be accessible differently
        if (method_exists($this, 'getArg')) {
            // Try to access using per-argument getter that some Omeka versions provide
            try {
                $framePosition = $this->getArg('frame_position', null);
                if ($framePosition !== null) {
                    return ['frame_position' => $framePosition];
                }
            } catch (\Exception $e) {
                // Silently fail and return empty array
            }
        }
        
        // Last resort fallback
        return [];
    }
    
    public function perform()
    {
        $startTime = microtime(true);
        
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $settings = $services->get('Omeka\Settings');
        $logger = $services->get('Omeka\Logger');
        
        // Debug: Log job start
        $logger->info('VideoThumbnail: Job started at ' . date('Y-m-d H:i:s'));
        error_log('VideoThumbnail: Job started at ' . date('Y-m-d H:i:s'));
        error_log('VideoThumbnail: Initial memory usage: ' . $this->getMemoryUsage());
        
        // Get FFmpeg path from settings
        $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
        
        // Get job parameters or use settings as fallback
        $args = $this->getJobArgs();
        $defaultFramePercent = isset($args['frame_position']) 
            ? (float)$args['frame_position'] 
            : (float)$settings->get('videothumbnail_default_frame', 10);
        
        // Validate FFmpeg path
        if (!file_exists($ffmpegPath)) {
            $errorMsg = 'FFmpeg not found at path: ' . $ffmpegPath;
            $logger->err($errorMsg);
            error_log('VideoThumbnail: ' . $errorMsg);
            return;
        }
        
        if (!is_executable($ffmpegPath)) {
            $errorMsg = 'FFmpeg is not executable at path: ' . $ffmpegPath;
            $logger->err($errorMsg);
            error_log('VideoThumbnail: ' . $errorMsg);
            return;
        }
        
        // Create video frame extractor
        error_log('VideoThumbnail: Creating VideoFrameExtractor with FFmpeg path: ' . $ffmpegPath);
        $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $fileManager = $services->get('Omeka\File\Manager');
        
        // Get all media with video media types
        error_log('VideoThumbnail: Querying for video media items');
        $dql = '
            SELECT m FROM Omeka\Entity\Media m 
            WHERE m.mediaType LIKE :video
        ';
        
        $query = $entityManager->createQuery($dql);
        $query->setParameters([
            'video' => 'video/%',
        ]);
        
        try {
            $medias = $query->getResult();
            $totalMedias = count($medias);
            
            error_log('VideoThumbnail: Found ' . $totalMedias . ' video media items');
            $logger->info(sprintf('VideoThumbnail: Starting thumbnail regeneration for %d videos', $totalMedias));
            
            // If no videos, exit early
            if ($totalMedias === 0) {
                $logger->info('VideoThumbnail: No video files found to process');
                error_log('VideoThumbnail: No video files found to process');
                return;
            }
            
            $successCount = 0;
            $errorCount = 0;
            $lastMemoryCheck = microtime(true);
            $memoryCheckInterval = 30; // seconds
            
            // Process each video
            foreach ($medias as $index => $media) {
                // Check if job should stop
                if ($this->shouldStop()) {
                    $logger->warn('VideoThumbnail: Job was stopped before completion');
                    error_log('VideoThumbnail: Job was stopped before completion');
                    break;
                }
                
                // Periodic memory check
                $now = microtime(true);
                if ($now - $lastMemoryCheck > $memoryCheckInterval) {
                    $lastMemoryCheck = $now;
                    $memoryUsage = $this->getMemoryUsage();
                    error_log('VideoThumbnail: Memory usage at item ' . ($index + 1) . ': ' . $memoryUsage);
                    
                    // If memory usage is too high, force garbage collection and flush
                    if ($this->isMemoryLimitApproaching(0.75)) {
                        error_log('VideoThumbnail: Memory usage approaching limit. Forcing garbage collection.');
                        if (function_exists('gc_collect_cycles')) {
                            gc_collect_cycles();
                        }
                        
                        try {
                            $entityManager->flush();
                            $entityManager->clear();
                            // Reinitialize services
                            $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
                            $fileManager = $services->get('Omeka\File\Manager');
                            error_log('VideoThumbnail: Entity manager flushed and cleared due to memory pressure');
                        } catch (\Exception $e) {
                            $errorMsg = 'Error during emergency flush: ' . $e->getMessage();
                            $logger->err($errorMsg);
                            error_log('VideoThumbnail: ' . $errorMsg);
                            // Try to recover
                            $entityManager->clear();
                        }
                    }
                }
                
                $mediaId = $media->getId();
                $framePath = null;
                
                error_log('VideoThumbnail: Processing media ID ' . $mediaId . ' (' . ($index + 1) . ' of ' . $totalMedias . ')');
                
                try {
                    // Get file path
                    $filePath = $media->getFilePath();
                    if (!file_exists($filePath)) {
                        $errorMsg = sprintf('File not found for media ID %d at path %s', $mediaId, $filePath);
                        $logger->warn($errorMsg);
                        error_log('VideoThumbnail: ' . $errorMsg);
                        $errorCount++;
                        continue;
                    }
                    
                    if (!is_readable($filePath)) {
                        $errorMsg = sprintf('File not readable for media ID %d at path %s', $mediaId, $filePath);
                        $logger->warn($errorMsg);
                        error_log('VideoThumbnail: ' . $errorMsg);
                        $errorCount++;
                        continue;
                    }
                    
                    // Get video duration
                    error_log('VideoThumbnail: Getting duration for media ID ' . $mediaId);
                    $duration = $videoFrameExtractor->getVideoDuration($filePath);
                    if ($duration <= 0) {
                        $errorMsg = sprintf('Could not determine duration for media ID %d', $mediaId);
                        $logger->warn($errorMsg);
                        error_log('VideoThumbnail: ' . $errorMsg);
                        $errorCount++;
                        continue;
                    }
                    
                    error_log('VideoThumbnail: Media ID ' . $mediaId . ' has duration of ' . $duration . ' seconds');
                    
                    // Get existing frame data or use default
                    $mediaData = $media->getData() ?: [];
                    $framePercent = isset($mediaData['thumbnail_frame_percentage']) ? 
                        (float)$mediaData['thumbnail_frame_percentage'] : 
                        (float)$defaultFramePercent;
                    
                    // Ensure frame percent is within valid range
                    $framePercent = max(0, min(100, $framePercent));
                    $frameTime = ($duration * $framePercent) / 100;
                    
                    error_log('VideoThumbnail: Extracting frame at ' . $frameTime . 's (' . $framePercent . '%) for media ID ' . $mediaId);
                    
                    // Extract frame
                    $framePath = $videoFrameExtractor->extractFrame($filePath, $frameTime);
                    if (!$framePath) {
                        $errorMsg = sprintf('Failed to extract frame for media ID %d', $mediaId);
                        $logger->warn($errorMsg);
                        error_log('VideoThumbnail: ' . $errorMsg);
                        $errorCount++;
                        continue;
                    }
                    
                    error_log('VideoThumbnail: Frame extracted successfully to ' . $framePath);
                    
                    // Create temp file object
                    $tempFile = $tempFileFactory->build();
                    $tempFile->setSourceName('thumbnail.jpg');
                    $tempFile->setTempPath($framePath);
                    
                    // Store thumbnails
                    error_log('VideoThumbnail: Storing thumbnails for media ID ' . $mediaId);
                    $fileManager->storeThumbnails($tempFile, $media);
                    
                    // Update media data
                    $mediaData['video_duration'] = $duration;
                    $mediaData['thumbnail_frame_time'] = $frameTime;
                    $mediaData['thumbnail_frame_percentage'] = $framePercent;
                    $media->setData($mediaData);
                    
                    // Save
                    $entityManager->persist($media);
                    
                    // Clean up
                    if (file_exists($framePath)) {
                        unlink($framePath);
                        $framePath = null;
                        error_log('VideoThumbnail: Cleaned up temporary frame file');
                    }
                    
                    $successCount++;
                    
                    // Flush every 5 items to avoid memory issues (reduced from 10)
                    if ($index % 5 === 0 && $index > 0) {
                        try {
                            error_log('VideoThumbnail: Performing scheduled flush at item ' . ($index + 1));
                            $entityManager->flush();
                            $entityManager->clear();
                            // Reinitialize services after clearing entity manager
                            $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
                            $fileManager = $services->get('Omeka\File\Manager');
                            
                            // Force garbage collection
                            if (function_exists('gc_collect_cycles')) {
                                $collected = gc_collect_cycles();
                                error_log('VideoThumbnail: Garbage collection freed ' . $collected . ' cycles');
                            }
                            
                            error_log('VideoThumbnail: Memory usage after flush: ' . $this->getMemoryUsage());
                        } catch (\Exception $e) {
                            $errorMsg = 'Error flushing entity manager: ' . $e->getMessage();
                            $logger->err($errorMsg);
                            error_log('VideoThumbnail: ' . $errorMsg);
                            // Try to continue with next batch
                            $entityManager->clear();
                        }
                    }
                    
                    $logger->info(sprintf('Processed media ID %d (%d of %d)', $mediaId, $index + 1, $totalMedias));
                    
                } catch (\Exception $e) {
                    $errorMsg = sprintf('Error processing media ID %d: %s', $mediaId, $e->getMessage());
                    $logger->err($errorMsg);
                    error_log('VideoThumbnail: ' . $errorMsg);
                    error_log('VideoThumbnail: ' . $e->getTraceAsString());
                    
                    // Cleanup any temporary files
                    if ($framePath && file_exists($framePath)) {
                        unlink($framePath);
                    }
                    
                    $errorCount++;
                }
            }
            
            // Final flush
            error_log('VideoThumbnail: Performing final flush');
            try {
                $entityManager->flush();
            } catch (\Exception $e) {
                $errorMsg = 'Error during final flush: ' . $e->getMessage();
                $logger->err($errorMsg);
                error_log('VideoThumbnail: ' . $errorMsg);
            }
            
            $endTime = microtime(true);
            $executionTime = round($endTime - $startTime, 2);
            
            $summaryMsg = sprintf(
                'Video thumbnail regeneration complete. Success: %d, Errors: %d, Total: %d. Time: %s seconds',
                $successCount,
                $errorCount,
                $totalMedias,
                $executionTime
            );
            
            $logger->info('VideoThumbnail: ' . $summaryMsg);
            error_log('VideoThumbnail: ' . $summaryMsg);
            error_log('VideoThumbnail: Final memory usage: ' . $this->getMemoryUsage());
            
        } catch (\Exception $e) {
            $errorMsg = 'Fatal error in thumbnail regeneration job: ' . $e->getMessage();
            $logger->err($errorMsg);
            error_log('VideoThumbnail: ' . $errorMsg);
            error_log('VideoThumbnail: ' . $e->getTraceAsString());
        }
    }
}
