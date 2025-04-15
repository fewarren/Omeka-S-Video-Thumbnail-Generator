<?php
namespace VideoThumbnail\Stdlib;

use VideoThumbnail\Stdlib\Debug;

class VideoFrameExtractor
{
    /**
     * @var string
     */
    protected $ffmpegPath;
    
    /**
     * @var int Default timeout in seconds
     */
    protected $defaultTimeout = 30;

    /**
     * @param string $ffmpegPath
     */
    public function __construct($ffmpegPath)
    {
        Debug::logEntry(__METHOD__, ['ffmpegPath' => $ffmpegPath]);
        $this->ffmpegPath = $ffmpegPath;
        Debug::logExit(__METHOD__);
    }
    
    /**
     * Execute a command with timeout and debug logging
     *
     * @param string $command Command to execute
     * @param int $timeout Timeout in seconds
     * @return string Command output
     */
    protected function executeCommandWithTimeout($command, $timeout = null)
    {
        Debug::logEntry(__METHOD__, ['command' => $command, 'timeout' => $timeout]);
        
        if ($timeout === null) {
            $timeout = $this->defaultTimeout;
        }
        
        // Debug: Log command execution attempt with timeout
        Debug::log('Executing command with timeout ' . $timeout . 's: ' . $command, __METHOD__);
        
        // Create descriptors for proc_open
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];
        
        // Start the process
        $process = proc_open($command, $descriptors, $pipes);
        
        if (!is_resource($process)) {
            Debug::logError('Failed to open process for command: ' . $command, __METHOD__);
            Debug::logExit(__METHOD__, '');
            return '';
        }
        
        // Set pipes to non-blocking mode
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        // Close stdin
        fclose($pipes[0]);
        
        $output = '';
        $stderr_output = '';
        $startTime = time();
        $maxLoopCount = $timeout * 20; // Max iterations (20 per second)
        $loopCount = 0;
        
        // Debug: Process started
        Debug::log('Process started at ' . date('Y-m-d H:i:s'), __METHOD__);
        
        // Read output with timeout and iteration limit
        while ($loopCount < $maxLoopCount) {
            $loopCount++;
            
            // Every 10 iterations, log progress for debugging
            if ($loopCount % 10 === 0) {
                Debug::log('Command execution in progress, iteration ' . $loopCount . ', running for ' . (time() - $startTime) . 's', __METHOD__);
            }
            
            // Check process status
            $status = proc_get_status($process);
            
            // Process completed
            if (!$status['running']) {
                Debug::log('Process completed normally with exit code: ' . $status['exitcode'], __METHOD__);
                break;
            }
            
            // Check for timeout
            if (time() - $startTime > $timeout) {
                // Kill the process if it's still running
                proc_terminate($process, 9); // SIGKILL
                Debug::logError('Command timed out after ' . $timeout . ' seconds: ' . $command, __METHOD__);
                break;
            }
            
            // Read from stdout and stderr
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            
            if ($stdout) {
                $output .= $stdout;
                // Detect FFmpeg progress lines for debugging
                if (strpos($stdout, 'time=') !== false) {
                    Debug::log('FFmpeg progress: ' . trim($stdout), __METHOD__);
                }
            }
            if ($stderr) {
                $stderr_output .= $stderr;
                // Log all stderr for debugging
                Debug::log('Command stderr: ' . trim($stderr), __METHOD__);
            }
            
            // Small sleep to prevent CPU hogging
            usleep(50000); // 50ms (reduced from 100ms)
        }
        
        // If we reached the max loop count, force terminate
        if ($loopCount >= $maxLoopCount) {
            Debug::logError('Maximum loop count reached (' . $maxLoopCount . '), forcing termination', __METHOD__);
            proc_terminate($process, 9); // SIGKILL
        }
        
        // Close pipes
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        // Close process
        $exitCode = proc_close($process);
        Debug::log('Process closed with exit code: ' . $exitCode, __METHOD__);
        
        // Debug: Command execution completed
        $runtime = time() - $startTime;
        Debug::log('Command execution completed after ' . $runtime . 's', __METHOD__);
        
        $result = trim($output);
        Debug::logExit(__METHOD__, $result);
        return $result;
    }

    /**
     * Get video duration in seconds with enhanced error handling and debug logging
     *
     * @param string $videoPath
     * @return float
     */
    public function getVideoDuration($videoPath)
    {
        Debug::logEntry(__METHOD__, ['videoPath' => $videoPath]);
        
        if (!file_exists($videoPath)) {
            Debug::logError('Video file does not exist: ' . $videoPath, __METHOD__);
            Debug::logExit(__METHOD__, 0);
            return 0;
        }
        
        if (!is_readable($videoPath)) {
            Debug::logError('Video file is not readable: ' . $videoPath, __METHOD__);
            Debug::logExit(__METHOD__, 0);
            return 0;
        }
        
        // Verify FFmpeg is executable before continuing
        if (!is_executable($this->ffmpegPath)) {
            Debug::logError('FFmpeg is not executable: ' . $this->ffmpegPath, __METHOD__);
            Debug::logExit(__METHOD__, 0);
            return 0;
        }
        
        try {
            // Method 1: Use ffprobe to get duration (faster and more reliable)
            $ffprobePath = dirname($this->ffmpegPath) . DIRECTORY_SEPARATOR . 'ffprobe';
            if (file_exists($ffprobePath) && is_executable($ffprobePath)) {
                Debug::log('Trying ffprobe method for duration', __METHOD__);
                $command = sprintf(
                    '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
                    escapeshellcmd($ffprobePath),
                    escapeshellarg($videoPath)
                );
                
                $duration = $this->executeCommandWithTimeout($command, 10);
                
                if (!empty($duration) && is_numeric($duration)) {
                    $duration = (float)$duration;
                    Debug::log('Duration (ffprobe): ' . $duration . ' seconds', __METHOD__);
                    Debug::logExit(__METHOD__, $duration);
                    return $duration;
                }
                
                Debug::log('ffprobe method failed, trying ffmpeg methods', __METHOD__);
            }
            
            // Method 2: Use ffmpeg with duration filter
            Debug::log('Trying ffmpeg method 1 for duration', __METHOD__);
            $command = sprintf(
                '%s -i %s -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 -v quiet',
                escapeshellcmd($this->ffmpegPath),
                escapeshellarg($videoPath)
            );
            
            $duration = $this->executeCommandWithTimeout($command, 10);
            
            // If the direct method fails, try the fallback method
            if (empty($duration) || !is_numeric($duration)) {
                // Method 3: Extract duration from ffmpeg output
                Debug::log('Trying ffmpeg method 2 for duration', __METHOD__);
                $command = sprintf(
                    '%s -i %s 2>&1',
                    escapeshellcmd($this->ffmpegPath),
                    escapeshellarg($videoPath)
                );
                
                $output = $this->executeCommandWithTimeout($command, 10);
                Debug::log('FFmpeg output for duration: ' . substr($output, 0, 500) . '...', __METHOD__);
                
                // Extract duration using regex
                if (preg_match('/Duration: ([0-9]{2}):([0-9]{2}):([0-9]{2}\.[0-9]+)/', $output, $matches)) {
                    $hours = (float)$matches[1];
                    $minutes = (float)$matches[2];
                    $seconds = (float)$matches[3];
                    
                    $duration = $hours * 3600 + $minutes * 60 + $seconds;
                    Debug::log('Duration (regex): ' . $duration . ' seconds', __METHOD__);
                    Debug::logExit(__METHOD__, $duration);
                    return $duration;
                }
                
                Debug::logError('Failed to extract duration from video', __METHOD__);
                Debug::logExit(__METHOD__, 0);
                return 0;
            }
            
            $duration = (float)$duration;
            Debug::log('Duration (direct): ' . $duration . ' seconds', __METHOD__);
            Debug::logExit(__METHOD__, $duration);
            return $duration;
            
        } catch (\Exception $e) {
            Debug::logError('Exception while getting video duration: ' . $e->getMessage(), __METHOD__, $e);
            Debug::logExit(__METHOD__, 0);
            return 0;
        }
    }

    /**
     * Extract a frame at specified position (in seconds) with enhanced logging
     *
     * @param string $videoPath
     * @param float $position
     * @return string|null Path to extracted frame or null on failure
     */
    public function extractFrame($videoPath, $position)
    {
        Debug::logEntry(__METHOD__, ['videoPath' => $videoPath, 'position' => $position]);
        
        $frameFile = null;
        $tempFile = null;
        
        if (!file_exists($videoPath)) {
            Debug::logError('Video file does not exist: ' . $videoPath, __METHOD__);
            Debug::logExit(__METHOD__, null);
            return null;
        }
        
        if (!is_readable($videoPath)) {
            Debug::logError('Video file is not readable: ' . $videoPath, __METHOD__);
            Debug::logExit(__METHOD__, null);
            return null;
        }
        
        if (!is_executable($this->ffmpegPath)) {
            Debug::logError('FFmpeg is not executable: ' . $this->ffmpegPath, __METHOD__);
            Debug::logExit(__METHOD__, null);
            return null;
        }
        
        try {
            // Create temporary file for the frame
            Debug::log('Creating temporary file', __METHOD__);
            $tempFile = tempnam(sys_get_temp_dir(), 'omeka_vt_');
            if (!$tempFile) {
                Debug::logError('Failed to create temporary file', __METHOD__);
                Debug::logExit(__METHOD__, null);
                return null;
            }
            
            // Add jpg extension
            $frameFile = $tempFile . '.jpg';
            if (!rename($tempFile, $frameFile)) {
                Debug::logError('Failed to rename temporary file from ' . $tempFile . ' to ' . $frameFile, __METHOD__);
                unlink($tempFile);
                Debug::logExit(__METHOD__, null);
                return null;
            }
            
            // Format position with proper time format (HH:MM:SS.xxx)
            $hours = floor($position / 3600);
            $minutes = floor(($position % 3600) / 60);
            $seconds = $position % 60;
            $formattedTime = sprintf('%02d:%02d:%06.3f', $hours, $minutes, $seconds);
            Debug::log('Formatted time position: ' . $formattedTime, __METHOD__);
            
            // Try method 1: Put -ss before input (more accurate seeking, faster)
            Debug::log('Trying extraction method 1 (fast seek)', __METHOD__);
            $command = sprintf(
                '%s -ss %s -i %s -vframes 1 -q:v 2 -y %s',
                escapeshellcmd($this->ffmpegPath),
                escapeshellarg($formattedTime),
                escapeshellarg($videoPath),
                escapeshellarg($frameFile)
            );
            
            // Execute command with timeout
            $output = $this->executeCommandWithTimeout($command, 30);
            
            // Check if frame was extracted successfully
            if (!file_exists($frameFile) || filesize($frameFile) === 0) {
                Debug::log('Method 1 failed, trying method 2 (seconds format)', __METHOD__);
                
                // Try method 2: Use seconds instead of formatted time
                $command = sprintf(
                    '%s -ss %f -i %s -vframes 1 -q:v 2 -y %s',
                    escapeshellcmd($this->ffmpegPath),
                    $position,
                    escapeshellarg($videoPath),
                    escapeshellarg($frameFile)
                );
                
                $output = $this->executeCommandWithTimeout($command, 30);
                
                if (!file_exists($frameFile) || filesize($frameFile) === 0) {
                    Debug::log('Method 2 failed, trying method 3 (accurate seek)', __METHOD__);
                    
                    // Try method 3: Input first, then seek (more accurate but slower)
                    $command = sprintf(
                        '%s -i %s -ss %s -vframes 1 -q:v 2 -y %s',
                        escapeshellcmd($this->ffmpegPath),
                        escapeshellarg($videoPath),
                        escapeshellarg($formattedTime),
                        escapeshellarg($frameFile)
                    );
                    
                    $output = $this->executeCommandWithTimeout($command, 30);
                    
                    if (!file_exists($frameFile) || filesize($frameFile) === 0) {
                        Debug::logError('All extraction methods failed', __METHOD__);
                        if (file_exists($frameFile)) {
                            unlink($frameFile);
                        }
                        Debug::logExit(__METHOD__, null);
                        return null;
                    }
                }
            }
            
            // Verify the frame was actually extracted
            $filesize = filesize($frameFile);
            Debug::log('Frame extracted successfully, size: ' . $filesize . ' bytes', __METHOD__);
            
            if ($filesize < 100) {  // If file is suspiciously small
                Debug::logError('Extracted frame is too small, likely invalid', __METHOD__);
                unlink($frameFile);
                Debug::logExit(__METHOD__, null);
                return null;
            }
            
            Debug::logExit(__METHOD__, $frameFile);
            return $frameFile;
            
        } catch (\Exception $e) {
            Debug::logError('Exception while extracting frame: ' . $e->getMessage(), __METHOD__, $e);
            
            // Clean up temp files
            if ($tempFile && file_exists($tempFile)) {
                unlink($tempFile);
            }
            if ($frameFile && file_exists($frameFile)) {
                unlink($frameFile);
            }
            
            Debug::logExit(__METHOD__, null);
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
        Debug::logEntry(__METHOD__, ['videoPath' => $videoPath, 'count' => $count]);
        
        if (!file_exists($videoPath) || !is_readable($videoPath)) {
            Debug::logError('Video file does not exist or is not readable: ' . $videoPath, __METHOD__);
            Debug::logExit(__METHOD__, []);
            return [];
        }

        // Validate count is reasonable
        $count = max(1, min(20, (int)$count));
        Debug::log('Validated frame count: ' . $count, __METHOD__);
        
        try {
            $duration = $this->getVideoDuration($videoPath);
            $frames = [];
            
            if ($duration <= 0) {
                Debug::logError('Could not determine video duration: ' . $videoPath, __METHOD__);
                Debug::logExit(__METHOD__, []);
                return $frames;
            }
            
            Debug::log('Video duration: ' . $duration . ' seconds', __METHOD__);
            
            // Extract frames at evenly distributed positions
            for ($i = 0; $i < $count; $i++) {
                // Avoid division by zero if count is 1
                $position = ($count > 1) ? ($i / ($count - 1)) * $duration : ($duration * 0.1);
                
                // Ensure position is within valid range
                $position = max(0.1, min($duration - 0.1, $position));
                
                Debug::log('Extracting frame ' . ($i + 1) . ' of ' . $count . ' at position ' . $position . ' seconds', __METHOD__);
                $framePath = $this->extractFrame($videoPath, $position);
                
                if ($framePath) {
                    $frames[] = [
                        'path' => $framePath,
                        'time' => $position,
                        'percent' => ($position / $duration) * 100,
                    ];
                    Debug::log('Frame ' . ($i + 1) . ' extracted successfully', __METHOD__);
                } else {
                    Debug::logError('Failed to extract frame ' . ($i + 1), __METHOD__);
                }
            }
            
            Debug::log('Extracted ' . count($frames) . ' frames successfully', __METHOD__);
            Debug::logExit(__METHOD__, $frames);
            return $frames;
        } catch (\Exception $e) {
            Debug::logError('Error extracting video frames: ' . $e->getMessage(), __METHOD__, $e);
            Debug::logExit(__METHOD__, []);
            return [];
        }
    }
}
