<?php
namespace VideoThumbnail\Job;

use Omeka\Job\AbstractJob;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;

class ExtractFrames extends AbstractJob
{
    /**
     * Batch process all video files to regenerate thumbnails
     */
    public function perform()
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $settings = $services->get('Omeka\Settings');
        $logger = $services->get('Omeka\Logger');
        
        // Get FFmpeg path from settings
        $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
        $defaultFramePercent = $settings->get('videothumbnail_default_frame', 10);
        
        // Validate FFmpeg path
       
if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
    // Attempt to auto-detect FFmpeg
    $possiblePaths = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'];
    foreach ($possiblePaths as $path) {
        if (file_exists($path) && is_executable($path)) {
            $ffmpegPath = $path;
            break;
        }
    }

    if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
        $errorMsg = 'FFmpeg not found or not executable. Please check the configuration.';
        $logger->err($errorMsg);
        error_log('VideoThumbnail: ' . $errorMsg);
        return;
    }
}
        
        // Create video frame extractor
        $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $fileManager = $services->get('Omeka\File\Manager');
        
        // Get all media with video media types
        $dql = '
            SELECT m FROM Omeka\Entity\Media m 
            WHERE m.mediaType LIKE :video
        ';
        
        $query = $entityManager->createQuery($dql);
        $query->setParameters([
            'video' => 'video/%',
        ]);
        
        $medias = $query->getResult();
        $totalMedias = count($medias);
        $successCount = 0;
        $errorCount = 0;
        
        $logger->info(sprintf('Starting video thumbnail regeneration for %d videos', $totalMedias));
        
        // Process each video
        foreach ($medias as $index => $media) {
            if ($this->shouldStop()) {
                $logger->warn('Job was stopped before completion');
                break;
            }
            
            $mediaId = $media->getId();
            $framePath = null;
            
            try {
                $filePath = $media->getFilePath();
                if (!file_exists($filePath) || !is_readable($filePath)) {
                    $logger->warn(sprintf('File not found or not readable for media ID %d', $mediaId));
                    $errorCount++;
                    continue;
                }
                
                // Get video duration
                $duration = $videoFrameExtractor->getVideoDuration($filePath);
                if ($duration <= 0) {
                    $logger->warn(sprintf('Could not determine duration for media ID %d', $mediaId));
                    $errorCount++;
                    continue;
                }
                
                // Get existing frame data or use default
                $mediaData = $media->getData() ?: [];
                $framePercent = isset($mediaData['thumbnail_frame_percentage']) ? 
                    (float)$mediaData['thumbnail_frame_percentage'] : 
                    (float)$defaultFramePercent;
                
                // Ensure frame percent is within valid range
                $framePercent = max(0, min(100, $framePercent));
                $frameTime = ($duration * $framePercent) / 100;
                
                // Extract frame
                $framePath = $videoFrameExtractor->extractFrame($filePath, $frameTime);
                if (!$framePath) {
                    $logger->warn(sprintf('Failed to extract frame for media ID %d', $mediaId));
                    $errorCount++;
                    continue;
                }
                
                // Create temp file object
                $tempFile = $tempFileFactory->build();
                $tempFile->setSourceName('thumbnail.jpg');
                $tempFile->setTempPath($framePath);
                
                // Store thumbnails
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
                }
                
                $successCount++;
                
                // Flush every 10 items to avoid memory issues
                if ($index % 10 === 0) {
                    try {
                        $entityManager->flush();
                        $entityManager->clear();
                        // Reinitialize services after clearing entity manager
                        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
                        $fileManager = $services->get('Omeka\File\Manager');
                    } catch (\Exception $e) {
                        $logger->err('Error flushing entity manager: ' . $e->getMessage());
                        // Try to continue with next batch
                        $entityManager->clear();
                    }
                }
                
                $logger->info(sprintf('Processed media ID %d (%d of %d)', $mediaId, $index + 1, $totalMedias));
                
            } catch (\Exception $e) {
                $logger->err(sprintf('Error processing media ID %d: %s', $mediaId, $e->getMessage()));
                
                // Cleanup any temporary files
                if ($framePath && file_exists($framePath)) {
                    unlink($framePath);
                }
                
                $errorCount++;
            }
        }
        
        // Final flush
        try {
            $entityManager->flush();
        } catch (\Exception $e) {
            $logger->err('Error during final flush: ' . $e->getMessage());
        }
        
        $logger->info(sprintf(
            'Video thumbnail regeneration complete. Success: %d, Errors: %d, Total: %d',
            $successCount,
            $errorCount,
            $totalMedias
        ));
    }
}
