<?php
namespace VideoThumbnail\Stdlib;

class VideoFrameExtractor
{
    /**
     * @var string
     */
    protected $ffmpegPath;

    /**
     * @param string $ffmpegPath
     */
    public function __construct($ffmpegPath)
    {
        $this->ffmpegPath = $ffmpegPath;
    }

    /**
     * Get video duration in seconds
     *
     * @param string $videoPath
     * @return float
     */
    public function getVideoDuration($videoPath)
    {
        $command = sprintf(
            '%s -i %s 2>&1 | grep "Duration" | cut -d \' \' -f 4 | sed s/,//',
            escapeshellcmd($this->ffmpegPath),
            escapeshellarg($videoPath)
        );
        
        $duration = shell_exec($command);
        
        if (empty($duration)) {
            return 0;
        }
        
        // Convert HH:MM:SS.ms to seconds
        $durationParts = explode(':', trim($duration));
        if (count($durationParts) === 3) {
            $hours = (float)$durationParts[0];
            $minutes = (float)$durationParts[1];
            $seconds = (float)$durationParts[2];
            
            return $hours * 3600 + $minutes * 60 + $seconds;
        }
        
        return 0;
    }

    /**
     * Extract a frame at specified position (in seconds)
     *
     * @param string $videoPath
     * @param float $position
     * @return string|null Path to extracted frame or null on failure
     */
    public function extractFrame($videoPath, $position)
    {
        if (!file_exists($videoPath)) {
            return null;
        }
        
        // Create temporary file for the frame
        $tempFile = tempnam(sys_get_temp_dir(), 'omeka_vt_');
        
        // Add jpg extension
        $frameFile = $tempFile . '.jpg';
        rename($tempFile, $frameFile);
        
        // Format position with proper time format (HH:MM:SS.xxx)
        $hours = floor($position / 3600);
        $minutes = floor(($position % 3600) / 60);
        $seconds = $position % 60;
        $formattedTime = sprintf('%02d:%02d:%06.3f', $hours, $minutes, $seconds);
        
        // Build command to extract frame
        $command = sprintf(
            '%s -ss %s -i %s -vframes 1 -q:v 2 -y %s 2>/dev/null',
            escapeshellcmd($this->ffmpegPath),
            escapeshellarg($formattedTime),
            escapeshellarg($videoPath),
            escapeshellarg($frameFile)
        );
        
        // Execute command
        $returnVar = null;
        exec($command, $output, $returnVar);
        
        // Check if frame was extracted successfully
        if ($returnVar !== 0 || !file_exists($frameFile)) {
            // Try alternative method with seconds instead of formatted time
            $command = sprintf(
                '%s -ss %f -i %s -vframes 1 -q:v 2 -y %s 2>/dev/null',
                escapeshellcmd($this->ffmpegPath),
                $position,
                escapeshellarg($videoPath),
                escapeshellarg($frameFile)
            );
            
            exec($command, $output, $returnVar);
            
            if ($returnVar !== 0 || !file_exists($frameFile)) {
                return null;
            }
        }
        
        return $frameFile;
    }

    /**
     * Extract multiple frames across the video
     *
     * @param string $videoPath
     * @param int $count Number of frames to extract
     * @return array Array of frame file paths
     */
    public function extractFrames($videoPath, $count = 5)
    {
        $duration = $this->getVideoDuration($videoPath);
        $frames = [];
        
        if ($duration <= 0 || $count <= 0) {
            return $frames;
        }
        
        // Extract frames at evenly distributed positions
        for ($i = 0; $i < $count; $i++) {
            $position = ($i / max(1, $count - 1)) * $duration;
            $framePath = $this->extractFrame($videoPath, $position);
            
            if ($framePath) {
                $frames[] = [
                    'path' => $framePath,
                    'time' => $position,
                    'percent' => ($position / $duration) * 100,
                ];
            }
        }
        
        return $frames;
    }
}
