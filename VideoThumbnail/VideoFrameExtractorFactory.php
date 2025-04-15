<?php
namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Stdlib\VideoFrameExtractor;
use VideoThumbnail\Stdlib\Debug;

class VideoFrameExtractorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Initialize debug logging
        $settings = $services->get('Omeka\Settings');
        Debug::init($settings);
        Debug::logEntry(__METHOD__);
        
        // Get configured path
        $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
        Debug::log('Configured FFmpeg path: ' . $ffmpegPath, __METHOD__);
        
        // Look for specific patterns that indicate stale paths (like Nix store paths)
        if (!empty($ffmpegPath) && (strpos($ffmpegPath, '/nix/store') !== false)) {
            Debug::log('Detected potential stale Nix store path, clearing and forcing auto-detection', __METHOD__);
            $settings->set('videothumbnail_ffmpeg_path', '');
            $ffmpegPath = '';
        }
        // Check if the configured path is valid
        else if (!empty($ffmpegPath) && (!file_exists($ffmpegPath) || !is_executable($ffmpegPath))) {
            Debug::log('Invalid FFmpeg path, will auto-detect new path', __METHOD__);
            // Clear the invalid setting and force auto-detection
            $settings->set('videothumbnail_ffmpeg_path', '');
            $ffmpegPath = '';
        } else {
            // First, check if the configured path works
            if (file_exists($ffmpegPath) && is_executable($ffmpegPath)) {
                Debug::log('FFmpeg exists and is executable at configured path', __METHOD__);
                // Do a final validation to ensure it actually works
                $output = [];
                $returnVar = null;
                exec(escapeshellcmd($ffmpegPath) . ' -version 2>/dev/null', $output, $returnVar);
                
                if ($returnVar === 0 && !empty($output)) {
                    Debug::log('FFmpeg validation successful: ' . $output[0], __METHOD__);
                    return $this->validateFfmpegAndCreate($ffmpegPath);
                } else {
                    Debug::log('FFmpeg path exists but command failed, will auto-detect', __METHOD__);
                    $settings->set('videothumbnail_ffmpeg_path', '');
                    $ffmpegPath = '';
                }
            }
        }
        
        Debug::log('Configured FFmpeg path is invalid, trying to auto-detect...', __METHOD__);
        
        // Try to find FFmpeg using various detection methods
        $detectionMethods = [
            [$this, 'detectUsingWhich'],
            [$this, 'detectUsingType'],
            [$this, 'detectUsingCommandExists'], 
            [$this, 'detectUsingEnvPath'],
            [$this, 'detectUsingCommonPaths'],
        ];
        
        foreach ($detectionMethods as $method) {
            $result = $method($settings);
            if ($result) {
                return $result;
            }
        }
        
        // Last resort: use default path and hope it works
        Debug::logError('FFmpeg not found using any detection method, using default path', __METHOD__);
        Debug::logExit(__METHOD__);
        return new VideoFrameExtractor('/usr/bin/ffmpeg');
    }
    
    /**
     * Detect FFmpeg using 'which' command
     *
     * @param \Laminas\ServiceManager\ServiceLocatorInterface $settings
     * @return \VideoThumbnail\Stdlib\VideoFrameExtractor|null
     */
    protected function detectUsingWhich($settings)
    {
        Debug::log('Trying to find FFmpeg using "which" command', __METHOD__);
        $output = [];
        $returnVar = null;
        exec('which ffmpeg 2>/dev/null', $output, $returnVar);
        
        if ($returnVar === 0 && !empty($output[0]) && file_exists($output[0]) && is_executable($output[0])) {
            $ffmpegPath = $output[0];
            Debug::log('Found FFmpeg using which command: ' . $ffmpegPath, __METHOD__);
            // Update the setting for future use
            $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
            return $this->validateFfmpegAndCreate($ffmpegPath);
        }
        
        return null;
    }
    
    /**
     * Detect FFmpeg in common installation paths
     *
     * @param \Laminas\ServiceManager\ServiceLocatorInterface $settings
     * @return \VideoThumbnail\Stdlib\VideoFrameExtractor|null
     */
    protected function detectUsingCommonPaths($settings)
    {
        Debug::log('Trying to find FFmpeg in common paths', __METHOD__);
        $commonPaths = [
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/opt/local/bin/ffmpeg',
            '/opt/bin/ffmpeg',
            '/usr/share/ffmpeg/bin/ffmpeg',
            '/snap/bin/ffmpeg',
            '/var/lib/flatpak/exports/bin/ffmpeg',
            '/usr/lib/ffmpeg/bin/ffmpeg',
            '/opt/ffmpeg/bin/ffmpeg',
        ];
        
        foreach ($commonPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                $ffmpegPath = $path;
                Debug::log('Found FFmpeg at common path: ' . $ffmpegPath, __METHOD__);
                // Update the setting for future use
                $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
                return $this->validateFfmpegAndCreate($ffmpegPath);
            }
        }
        
        return null;
    }
    
    /**
     * Detect FFmpeg using 'type' command (more portable than 'which')
     *
     * @param \Laminas\ServiceManager\ServiceLocatorInterface $settings
     * @return \VideoThumbnail\Stdlib\VideoFrameExtractor|null
     */
    protected function detectUsingType($settings)
    {
        Debug::log('Trying to find FFmpeg using "type" command', __METHOD__);
        $output = [];
        $returnVar = null;
        
        // 'type' is a shell builtin that works in most Linux/Unix environments
        exec('type -p ffmpeg 2>/dev/null', $output, $returnVar);
        
        if ($returnVar === 0 && !empty($output[0]) && file_exists($output[0]) && is_executable($output[0])) {
            $ffmpegPath = $output[0];
            Debug::log('Found FFmpeg using type command: ' . $ffmpegPath, __METHOD__);
            // Update the setting for future use
            $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
            return $this->validateFfmpegAndCreate($ffmpegPath);
        }
        
        return null;
    }
    
    /**
     * Detect FFmpeg using command_exists shell function
     *
     * @param \Laminas\ServiceManager\ServiceLocatorInterface $settings
     * @return \VideoThumbnail\Stdlib\VideoFrameExtractor|null
     */
    protected function detectUsingCommandExists($settings)
    {
        Debug::log('Trying to find FFmpeg using command_exists shell function', __METHOD__);
        $output = [];
        $returnVar = null;
        
        // Use command_exists if available, or fallback to type
        exec('(command -v command_exists >/dev/null 2>&1 && command_exists ffmpeg) || type ffmpeg >/dev/null 2>&1', $output, $returnVar);
        
        if ($returnVar === 0) {
            // If command exists, try to get its full path
            exec('command -v ffmpeg 2>/dev/null', $output, $returnVar);
            
            if ($returnVar === 0 && !empty($output[0]) && file_exists($output[0]) && is_executable($output[0])) {
                $ffmpegPath = $output[0];
                Debug::log('Found FFmpeg using command_exists: ' . $ffmpegPath, __METHOD__);
                // Update the setting for future use
                $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
                return $this->validateFfmpegAndCreate($ffmpegPath);
            }
        }
        
        return null;
    }
    
    /**
     * Detect FFmpeg by searching in PATH environment variable
     *
     * @param \Laminas\ServiceManager\ServiceLocatorInterface $settings
     * @return \VideoThumbnail\Stdlib\VideoFrameExtractor|null
     */
    protected function detectUsingEnvPath($settings)
    {
        Debug::log('Trying to find FFmpeg in PATH environment variable', __METHOD__);
        
        // Get PATH environment variable
        $pathEnv = getenv('PATH');
        if (!$pathEnv) {
            return null;
        }
        
        // Split PATH into directories
        $pathDirs = explode(PATH_SEPARATOR, $pathEnv);
        Debug::log('Searching ' . count($pathDirs) . ' directories in PATH', __METHOD__);
        
        foreach ($pathDirs as $dir) {
            $ffmpegPath = $dir . DIRECTORY_SEPARATOR . 'ffmpeg';
            if (file_exists($ffmpegPath) && is_executable($ffmpegPath)) {
                Debug::log('Found FFmpeg in PATH: ' . $ffmpegPath, __METHOD__);
                // Update the setting for future use
                $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
                return $this->validateFfmpegAndCreate($ffmpegPath);
            }
            
            // Check for .exe extension on Windows
            $ffmpegPathExe = $ffmpegPath . '.exe';
            if (file_exists($ffmpegPathExe) && is_executable($ffmpegPathExe)) {
                Debug::log('Found FFmpeg.exe in PATH: ' . $ffmpegPathExe, __METHOD__);
                // Update the setting for future use
                $settings->set('videothumbnail_ffmpeg_path', $ffmpegPathExe);
                return $this->validateFfmpegAndCreate($ffmpegPathExe);
            }
        }
        
        return null;
    }
    
    /**
     * Validate FFmpeg executable works and create extractor
     *
     * @param string $ffmpegPath
     * @return VideoFrameExtractor
     */
    protected function validateFfmpegAndCreate($ffmpegPath)
    {
        Debug::log('Validating FFmpeg at: ' . $ffmpegPath, __METHOD__);
        
        try {
            // Try to execute ffmpeg -version to verify it works (with extended debugging)
            $output = [];
            $returnVar = null;
            $command = escapeshellcmd($ffmpegPath) . ' -version 2>/dev/null';
            Debug::log('Executing validation command: ' . $command, __METHOD__);
            
            exec($command, $output, $returnVar);
            Debug::log('Validation command returned code: ' . $returnVar . ' with ' . count($output) . ' lines of output', __METHOD__);
            
            if ($returnVar === 0 && !empty($output)) {
                Debug::log('FFmpeg validation successful: ' . $output[0], __METHOD__);
                Debug::logExit(__METHOD__);
                return new VideoFrameExtractor($ffmpegPath);
            }
            
            // Additional information if the command failed
            $errorInfo = 'Return code: ' . $returnVar;
            if (!empty($output)) {
                $errorInfo .= ', Output: ' . implode("\n", array_slice($output, 0, 3));
            } else {
                $errorInfo .= ', No output received';
            }
            Debug::logError('FFmpeg validation failed: ' . $errorInfo, __METHOD__);
            
            // Try an alternative validation approach by getting ffmpeg help
            Debug::log('Trying alternative validation method with -h', __METHOD__);
            $altCommand = escapeshellcmd($ffmpegPath) . ' -h 2>/dev/null';
            exec($altCommand, $altOutput, $altReturnVar);
            
            if ($altReturnVar === 0 && !empty($altOutput)) {
                Debug::log('Alternative validation succeeded', __METHOD__);
                Debug::logExit(__METHOD__);
                return new VideoFrameExtractor($ffmpegPath);
            }
            
            Debug::logError('All validation attempts failed for: ' . $ffmpegPath, __METHOD__);
            
            // Try to get information about the file
            if (file_exists($ffmpegPath)) {
                $fileInfo = 'File exists, ';
                $fileInfo .= 'Size: ' . filesize($ffmpegPath) . ' bytes, ';
                $fileInfo .= 'Permissions: ' . substr(sprintf('%o', fileperms($ffmpegPath)), -4) . ', ';
                $fileInfo .= 'Executable: ' . (is_executable($ffmpegPath) ? 'Yes' : 'No');
                Debug::log('File information: ' . $fileInfo, __METHOD__);
            } else {
                Debug::logError('File does not exist: ' . $ffmpegPath, __METHOD__);
            }
        } catch (\Exception $e) {
            Debug::logError('Exception during validation: ' . $e->getMessage(), __METHOD__);
        }
        
        Debug::logExit(__METHOD__);
        return new VideoFrameExtractor($ffmpegPath);
    }
}