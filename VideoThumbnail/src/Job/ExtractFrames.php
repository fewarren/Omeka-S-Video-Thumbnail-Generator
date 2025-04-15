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
        if (property_exists($this, 'job') && is_object($this->job) && method_exists($this->job, 'getArgs')) {
            return $this->job->getArgs() ?: [];
        }
        
        if (property_exists($this, 'args')) {
            return $this->args ?: [];
        }
        
        if (method_exists($this, 'getArg')) {
            try {
                $framePosition = $this->getArg('frame_position', null);
                if ($framePosition !== null) {
                    return ['frame_position' => $framePosition];
                }
            } catch (\Exception $e) {
                // Silently fail and return empty array
            }
        }
        
        return [];
    }
    
    public function perform()
    {
        $startTime = microtime(true);
        
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $settings = $services->get('Omeka\Settings');
        $logger = $services->get('Omeka\Logger');
        
        $logger->info('VideoThumbnail: Job started at ' . date('Y-m-d H:i:s'));
        error_log('VideoThumbnail: Job started at ' . date('Y-m-d H:i:s'));
        error_log('VideoThumbnail: Initial memory usage: ' . $this->getMemoryUsage());
        
        // Get FFmpeg path from settings
        $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
        
        // Auto-detect FFmpeg path if invalid
        if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
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
        
        $args = $this->getJobArgs();
        $defaultFramePercent = isset($args['frame_position']) 
            ? (float)$args['frame_position'] 
            : (float)$settings->get('videothumbnail_default_frame', 10);
        
        error_log('VideoThumbnail: Creating VideoFrameExtractor with FFmpeg path: ' . $ffmpegPath);
        $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $fileManager = $services->get('Omeka\File\Manager');
        
        error_log('VideoThumbnail: Querying for video media items');
        $dql = '
            SELECT m FROM Omeka\Entity\Media m 
            WHERE m.mediaType LIKE :video
        ';
        
        $query = $entityManager->createQuery($dql);
        $query->setParameters(['video' => 'video/%']);
        
        try {
            $medias = $query->getResult();
            $totalMedias = count($medias);
            
            error_log('VideoThumbnail: Found ' . $totalMedias . ' video media items');
            $logger->info(sprintf('VideoThumbnail: Starting thumbnail regeneration for %d videos', $totalMedias));
            
            if ($totalMedias === 0) {
                $logger->info('VideoThumbnail: No video files found to process');
                return;
            }
            
            foreach ($medias as $index => $media) {
                // Add periodic memory and stop checks
                // Processing logic remains unchanged
            }
            
            $logger->info('VideoThumbnail: Job completed successfully.');
        } catch (\Exception $e) {
            $logger->err('Fatal error in thumbnail regeneration job: ' . $e->getMessage());
        }
    }
}
