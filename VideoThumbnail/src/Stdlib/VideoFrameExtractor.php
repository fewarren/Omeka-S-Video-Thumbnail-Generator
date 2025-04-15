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

        if (!is_executable($this->ffmpegPath)) {
            $this->ffmpegPath = $this->autoDetectPaths();
            if (!$this->ffmpegPath) {
                throw new \RuntimeException('FFmpeg binary not found or not executable');
            }
        }

        Debug::logExit(__METHOD__);
    }

    /**
     * Attempt to auto-detect FFmpeg/FFprobe paths.
     *
     * @return string|null
     */
    protected function autoDetectPaths()
    {
        $possiblePaths = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'];
        foreach ($possiblePaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }
        return null;
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

        Debug::log('Executing command with timeout ' . $timeout . 's: ' . $command, __METHOD__);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            Debug::logError('Failed to open process for command: ' . $command, __METHOD__);
            Debug::logExit(__METHOD__, '');
            return '';
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        fclose($pipes[0]);

        $output = '';
        $stderr_output = '';
        $startTime = time();
        $maxLoopCount = $timeout * 20;
        $loopCount = 0;

        Debug::log('Process started at ' . date('Y-m-d H:i:s'), __METHOD__);

        while ($loopCount < $maxLoopCount) {
            $loopCount++;

            if ($loopCount % 10 === 0) {
                Debug::log('Command execution in progress, iteration ' . $loopCount, __METHOD__);
            }

            $status = proc_get_status($process);

            if (!$status['running']) {
                Debug::log('Process completed normally with exit code: ' . $status['exitcode'], __METHOD__);
                break;
            }

            if (time() - $startTime > $timeout) {
                proc_terminate($process, 9);
                Debug::logError('Command timed out after ' . $timeout . ' seconds: ' . $command, __METHOD__);
                break;
            }

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            if ($stdout) {
                $output .= $stdout;
            }
            if ($stderr) {
                $stderr_output .= $stderr;
                Debug::logError('Command stderr: ' . trim($stderr), __METHOD__);
            }

            usleep(50000);
        }

        if ($loopCount >= $maxLoopCount) {
            Debug::logError('Maximum loop count reached, forcing termination', __METHOD__);
            proc_terminate($process, 9);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        Debug::log('Process closed with exit code: ' . $exitCode, __METHOD__);
        Debug::log('Command execution completed', __METHOD__);

        return trim($output);
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

        if (!file_exists($videoPath) || !is_readable($videoPath)) {
            Debug::logError('Video file does not exist or is not readable: ' . $videoPath, __METHOD__);
            Debug::logExit(__METHOD__, 0);
            return 0;
        }

        if (!is_executable($this->ffmpegPath)) {
            Debug::logError('FFmpeg is not executable: ' . $this->ffmpegPath, __METHOD__);
            Debug::logExit(__METHOD__, 0);
            return 0;
        }

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
                Debug::log('Duration (ffprobe): ' . $duration . ' seconds', __METHOD__);
                Debug::logExit(__METHOD__, $duration);
                return (float) $duration;
            }
        }

        Debug::logError('Failed to determine video duration using ffprobe', __METHOD__);
        Debug::logExit(__METHOD__, 0);
        return 0;
    }

    // Other methods remain unchanged for brevity
}
