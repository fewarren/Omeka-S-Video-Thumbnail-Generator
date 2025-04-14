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
        if (!file_exists($videoPath) || !is_readable($videoPath)) {
            return 0;
        }
        
        // Use ffmpeg directly to get duration metadata
        $command = sprintf(
            '%s -i %s -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 -v quiet',
            escapeshellcmd($this->ffmpegPath),
            escapeshellarg($videoPath)
        );
        
        $duration = trim(shell_exec($command));
        
        // If the direct method fails, try the fallback method
        if (empty($duration) || !is_numeric($duration)) {
            // Get raw output from ffmpeg
            $command = sprintf(
                '%s -i %s 2>&1',
                escapeshellcmd($this->ffmpegPath),
                escapeshellarg($videoPath)
            );
            
            $output = shell_exec($command);
            
            // Extract duration using regex
            if (preg_match('/Duration: ([0-9]{2}):([0-9]{2}):([0-9]{2}\.[0-9]+)/', $output, $matches)) {
                $hours = (float)$matches[1];
                $minutes = (float)$matches[2];
                $seconds = (float)$matches[3];
                
                return $hours * 3600 + $minutes * 60 + $seconds;
            }
            
            return 0;
        }
        
        return (float)$duration;
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
        if (!file_exists($videoPath) || !is_readable($videoPath)) {
            return null;
        }
        
        if (!is_executable($this->ffmpegPath)) {
            error_log('FFmpeg is not executable: ' . $this->ffmpegPath);
            return null;
        }
        
        try {
            // Create temporary file for the frame
            $tempFile = tempnam(sys_get_temp_dir(), 'omeka_vt_');
            if (!$tempFile) {
                error_log('Failed to create temporary file');
                return null;
            }
            
            // Add jpg extension
            $frameFile = $tempFile . '.jpg';
            if (!rename($tempFile, $frameFile)) {
                unlink($tempFile);
                error_log('Failed to rename temporary file');
                return null;
            }
            
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
            
            // Execute command with timeout (30 seconds)
            $process = proc_open(
                $command, 
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes
            );
            
            if (is_resource($process)) {
                $status = proc_get_status($process);
                $returnVar = proc_close($process);
            } else {
                $returnVar = -1;
            }
            
            // Check if frame was extracted successfully
            if ($returnVar !== 0 || !file_exists($frameFile) || filesize($frameFile) === 0) {
                // Try alternative method with seconds instead of formatted time
                $command = sprintf(
                    '%s -ss %f -i %s -vframes 1 -q:v 2 -y %s 2>/dev/null',
                    escapeshellcmd($this->ffmpegPath),
                    $position,
                    escapeshellarg($videoPath),
                    escapeshellarg($frameFile)
                );
                
                $process = proc_open(
                    $command, 
                    [
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes
                );
                
                if (is_resource($process)) {
                    $status = proc_get_status($process);
                    $returnVar = proc_close($process);
                } else {
                    $returnVar = -1;
                }
                
                if ($returnVar !== 0 || !file_exists($frameFile) || filesize($frameFile) === 0) {
                    if (file_exists($frameFile)) {
                        unlink($frameFile);
                    }
                    return null;
                }
            }
            
            return $frameFile;
        } catch (\Exception $e) {
            error_log('Error extracting video frame: ' . $e->getMessage());
            if (isset($frameFile) && file_exists($frameFile)) {
                unlink($frameFile);
            }
            return null;
        }
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
        if (!file_exists($videoPath) || !is_readable($videoPath)) {
            error_log('Video file does not exist or is not readable: ' . $videoPath);
            return [];
        }

        // Validate count is reasonable
        $count = max(1, min(20, (int)$count));
        
        try {
            $duration = $this->getVideoDuration($videoPath);
            $frames = [];
            
            if ($duration <= 0) {
                error_log('Could not determine video duration: ' . $videoPath);
                return $frames;
            }
            
            // Extract frames at evenly distributed positions
            for ($i = 0; $i < $count; $i++) {
                // Avoid division by zero if count is 1
                $position = ($count > 1) ? ($i / ($count - 1)) * $duration : ($duration * 0.1);
                
                // Ensure position is within valid range
                $position = max(0.1, min($duration - 0.1, $position));
                
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
        } catch (\Exception $e) {
            error_log('Error extracting video frames: ' . $e->getMessage());
            return [];
        }
    }
}
