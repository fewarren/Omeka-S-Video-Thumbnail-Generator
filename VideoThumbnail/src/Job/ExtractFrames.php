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
        
        // Create video frame extractor
        $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $fileManager = $services->get('Omeka\File\Manager');
        
        // Get all media with video media types
        $dql = '
            SELECT m FROM Omeka\Entity\Media m 
            WHERE m.mediaType = :mp4 OR m.mediaType = :mov
        ';
        
        $query = $entityManager->createQuery($dql);
        $query->setParameters([
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
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
            
            try {
                $filePath = $media->getFilePath();
                if (!file_exists($filePath)) {
                    $logger->warn(sprintf('File not found for media ID %d', $mediaId));
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
                $framePercent = $mediaData['thumbnail_frame_percentage'] ?? $defaultFramePercent;
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
                @unlink($framePath);
                
                $successCount++;
                
                // Flush every 20 items to avoid memory issues
                if ($index % 20 === 0) {
                    $entityManager->flush();
                    $entityManager->clear();
                }
                
                $logger->info(sprintf('Processed media ID %d (%d of %d)', $mediaId, $index + 1, $totalMedias));
                
            } catch (\Exception $e) {
                $logger->err(sprintf('Error processing media ID %d: %s', $mediaId, $e->getMessage()));
                $errorCount++;
            }
        }
        
        // Final flush
        $entityManager->flush();
        
        $logger->info(sprintf(
            'Video thumbnail regeneration complete. Success: %d, Errors: %d, Total: %d',
            $successCount,
            $errorCount,
            $totalMedias
        ));
    }
}
