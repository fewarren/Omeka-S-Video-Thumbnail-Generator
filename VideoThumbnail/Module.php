<?php
namespace VideoThumbnail;

use Omeka\Module\AbstractModule;
use VideoThumbnail\Form\ConfigForm;
use VideoThumbnail\Media\Ingester\VideoThumbnail as VideoThumbnailIngester;
use VideoThumbnail\Media\Renderer\VideoThumbnail as VideoThumbnailRenderer;
use VideoThumbnail\Stdlib\Debug;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Entity\Media;
use Omeka\Api\Representation\MediaRepresentation;

class Module extends AbstractModule
{
    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getConfigForm(PhpRenderer $renderer): string
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $form = $services->get('FormElementManager')->get(ConfigForm::class);

        $settings = $services->get('Omeka\Settings');
        $form->init();
        $form->setData([
            'videothumbnail_ffmpeg_path' => $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg'),
            'videothumbnail_frames_count' => $settings->get('videothumbnail_frames_count', 5),
            'videothumbnail_default_frame' => $settings->get('videothumbnail_default_frame', 2),
            'videothumbnail_debug_mode' => $settings->get('videothumbnail_debug_mode', false),
        ]);

        return $renderer->render('video-thumbnail/admin/config-form', [
            'form' => $form,
        ]);
    }

    public function handleConfigForm(AbstractController $controller): bool
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(ConfigForm::class);

        $form->init();
        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->flashMessenger()->addErrors($form->getMessages());
            return false;
        }

        $formData = $form->getData();

        if ((int)$formData['videothumbnail_frames_count'] <= 0 || (int)$formData['videothumbnail_default_frame'] < 0) {
            $controller->flashMessenger()->addError('Frame count and default frame must be non-negative integers.');
            return false;
        }

        $settings->set('videothumbnail_frames_count', $formData['videothumbnail_frames_count']);
        $settings->set('videothumbnail_default_frame', $formData['videothumbnail_default_frame']);
        $settings->set('videothumbnail_debug_mode', !empty($formData['videothumbnail_debug_mode']));

        // Get the user-provided FFmpeg path
        $ffmpegPath = $formData['videothumbnail_ffmpeg_path'];
        
        // Look for specific patterns that indicate stale paths (like Nix store paths)
        if (!empty($ffmpegPath) && (strpos($ffmpegPath, '/nix/store') !== false)) {
            $controller->flashMessenger()->addWarning('A Nix store path was detected in the FFmpeg configuration. This is often a stale path. Attempting to auto-detect FFmpeg...');
            // Force auto-detection
            $ffmpegPath = '';
        }
        // Check if the configured path is valid
        else if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
            $controller->flashMessenger()->addWarning('The configured FFmpeg path is invalid. Attempting to auto-detect FFmpeg...');
            // Force auto-detection
            $ffmpegPath = '';
        } else if (file_exists($ffmpegPath) && is_executable($ffmpegPath)) {
            // Path exists and is executable, try to verify it works
            $output = [];
            $returnVar = 0;
            exec(escapeshellcmd($ffmpegPath) . ' -version 2>/dev/null', $output, $returnVar);
            if ($returnVar === 0) {
                // FFmpeg found and works, save it
                $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
                return true;
            } else {
                $controller->flashMessenger()->addWarning('The FFmpeg path was found but failed to execute. Attempting to auto-detect FFmpeg...');
                $ffmpegPath = '';
            }
        }
        
        // Path doesn't work, try auto-detection
        $controller->flashMessenger()->addWarning('The specified FFmpeg path was not valid. Attempting to auto-detect FFmpeg...');
        
        // Create a factory to use its detection methods
        $factory = new \VideoThumbnail\Service\VideoFrameExtractorFactory();
        
        // Try the factory's detection methods to find FFmpeg
        $detectionMethods = [
            [$factory, 'detectUsingWhich'],
            [$factory, 'detectUsingType'],
            [$factory, 'detectUsingCommandExists'],
            [$factory, 'detectUsingEnvPath'],
            [$factory, 'detectUsingCommonPaths'],
        ];
        
        $found = false;
        foreach ($detectionMethods as $method) {
            if (is_callable($method)) {
                $result = $method($settings);
                if ($result) {
                    $found = true;
                    // Get the path back from settings after detection
                    $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path');
                    $controller->flashMessenger()->addSuccess('FFmpeg auto-detected at: ' . $ffmpegPath);
                    return true;
                }
            }
        }
        
        // Try a final method - check for ffmpeg in common system paths
        $systemPaths = [
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/opt/local/bin/ffmpeg',
            '/opt/bin/ffmpeg',
            '/snap/bin/ffmpeg',
            '/var/lib/flatpak/exports/bin/ffmpeg'
        ];
        
        foreach ($systemPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                $output = [];
                $returnVar = 0;
                exec(escapeshellcmd($path) . ' -version 2>/dev/null', $output, $returnVar);
                if ($returnVar === 0) {
                    $settings->set('videothumbnail_ffmpeg_path', $path);
                    $controller->flashMessenger()->addSuccess('FFmpeg found at system path: ' . $path);
                    return true;
                }
            }
        }
        
        // Still no FFmpeg, give up
        $controller->flashMessenger()->addError('FFmpeg could not be found on this system. Please install FFmpeg or provide a valid path.');
        return false;
    }

    public function getAutoloaderConfig(): array
    {
        return [
            'Laminas\Loader\StandardAutoloader' => [
                'namespaces' => [
                    __NAMESPACE__ => __DIR__ . '/src',
                ],
            ],
        ];
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);
        $application = $event->getApplication();
        $serviceManager = $application->getServiceManager();
        
        // Initialize debug system
        $settings = $serviceManager->get('Omeka\Settings');
        Debug::init($settings);
        Debug::logEntry(__METHOD__);
        
        $viewHelperManager = $serviceManager->get('ViewHelperManager');
        $viewHelperManager->setAlias('videoThumbnailSelector', 'VideoThumbnail\View\Helper\VideoThumbnailSelector');
        
        // Register renderer aliases for configured formats
        $supportedFormats = $settings->get('videothumbnail_supported_formats', ['video/mp4', 'video/quicktime']);
        $this->setupMediaRendererAliases($serviceManager, $supportedFormats);
        
        Debug::logExit(__METHOD__);
    }
    
    /**
     * Setup media renderer aliases for the supported formats
     *
     * @param \Laminas\ServiceManager\ServiceLocatorInterface $serviceManager
     * @param array $supportedFormats
     */
    protected function setupMediaRendererAliases($serviceManager, array $supportedFormats): void
    {
        if (empty($supportedFormats)) {
            return;
        }
        
        if (!$serviceManager->has('Omeka\Media\Renderer\Manager')) {
            return;
        }
        
        $rendererManager = $serviceManager->get('Omeka\Media\Renderer\Manager');
        
        foreach ($supportedFormats as $format) {
            if (!empty($format) && is_string($format)) {
                if (method_exists($rendererManager, 'addAlias')) {
                    $rendererManager->addAlias($format, 'videothumbnail');
                }
            }
        }
    }

    public function install(ServiceLocatorInterface $serviceLocator): void
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        
        // Check if there's already a setting and if it's a stale Nix store path
        $existingPath = $settings->get('videothumbnail_ffmpeg_path', '');
        if (!empty($existingPath) && strpos($existingPath, '/nix/store') !== false) {
            // Clear the stale Nix store path
            $settings->set('videothumbnail_ffmpeg_path', '');
        }
        
        // Create an instance of the VideoFrameExtractorFactory to use its detection methods
        $factory = new \VideoThumbnail\Service\VideoFrameExtractorFactory();
        
        // Initialize debug logging for the factory
        if (class_exists('\VideoThumbnail\Stdlib\Debug')) {
            \VideoThumbnail\Stdlib\Debug::init($settings);
            \VideoThumbnail\Stdlib\Debug::log('Installing VideoThumbnail module, detecting FFmpeg', __METHOD__);
        }
        
        // First, try the factory's detection methods to find FFmpeg
        $detectionMethods = [
            [$factory, 'detectUsingWhich'],
            [$factory, 'detectUsingType'],
            [$factory, 'detectUsingCommonPaths'],
            [$factory, 'detectUsingCommandExists'],
            [$factory, 'detectUsingEnvPath'],
        ];
        
        $ffmpegPath = '/usr/bin/ffmpeg'; // Default fallback
        $found = false;
        
        foreach ($detectionMethods as $method) {
            // Only try methods that exist
            if (is_callable($method)) {
                $result = $method($settings);
                if ($result) {
                    $found = true;
                    if (class_exists('\VideoThumbnail\Stdlib\Debug')) {
                        \VideoThumbnail\Stdlib\Debug::log('FFmpeg found using detection method: ' . $method[1], __METHOD__);
                    }
                    break;
                }
            }
        }
        
        // If none of the detection methods worked, try one more approach
        if (!$found) {
            if (class_exists('\VideoThumbnail\Stdlib\Debug')) {
                \VideoThumbnail\Stdlib\Debug::log('Detection methods failed, trying manual detection', __METHOD__);
            }
            
            // Try checking if FFmpeg exists in PATH by running a command
            $output = [];
            $returnVar = null;
            exec('command -v ffmpeg 2>/dev/null', $output, $returnVar);
            
            if ($returnVar === 0 && !empty($output[0])) {
                $ffmpegPath = $output[0];
                if (class_exists('\VideoThumbnail\Stdlib\Debug')) {
                    \VideoThumbnail\Stdlib\Debug::log('FFmpeg found using command -v: ' . $ffmpegPath, __METHOD__);
                }
            } else {
                // Try common alternative paths as a final fallback
                $alternativePaths = [
                    '/usr/bin/ffmpeg',
                    '/usr/local/bin/ffmpeg',
                    '/opt/local/bin/ffmpeg',
                    '/opt/bin/ffmpeg',
                    '/snap/bin/ffmpeg',
                    '/var/lib/flatpak/exports/bin/ffmpeg'
                ];
                
                foreach ($alternativePaths as $path) {
                    if (file_exists($path) && is_executable($path)) {
                        $ffmpegPath = $path;
                        if (class_exists('\VideoThumbnail\Stdlib\Debug')) {
                            \VideoThumbnail\Stdlib\Debug::log('FFmpeg found at common path: ' . $ffmpegPath, __METHOD__);
                        }
                        break;
                    }
                }
            }
        }
        
        // Validate that the path actually works before setting it
        if (file_exists($ffmpegPath) && is_executable($ffmpegPath)) {
            $output = [];
            $returnVar = null;
            exec(escapeshellcmd($ffmpegPath) . ' -version 2>/dev/null', $output, $returnVar);
            if ($returnVar !== 0 || empty($output)) {
                // Fallback to a standard path if validation fails
                $ffmpegPath = '/usr/bin/ffmpeg';
                if (class_exists('\VideoThumbnail\Stdlib\Debug')) {
                    \VideoThumbnail\Stdlib\Debug::log('Validation failed, using default path: ' . $ffmpegPath, __METHOD__);
                }
            }
        }
        
        $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
        $settings->set('videothumbnail_frames_count', 5);
        $settings->set('videothumbnail_default_frame', 10);
        $settings->set('videothumbnail_debug_mode', false);
        $settings->set('videothumbnail_supported_formats', [
            'video/mp4',
            'video/quicktime',
            'video/webm',
        ]);

        $tempDir = OMEKA_PATH . '/files/video-thumbnails';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator): void
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        $settings->delete('videothumbnail_ffmpeg_path');
        $settings->delete('videothumbnail_frames_count');
        $settings->delete('videothumbnail_default_frame');
        $settings->delete('videothumbnail_debug_mode');
        $settings->delete('videothumbnail_supported_formats');

        $tempDir = OMEKA_PATH . '/files/video-thumbnails';
        if (is_dir($tempDir)) {
            $this->recursiveRemoveDirectory($tempDir);
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach('Omeka\Controller\Admin\Media', 'view.edit.form.after', [$this, 'handleViewEditFormAfter']);
        $sharedEventManager->attach('Omeka\Api\Adapter\MediaAdapter', 'api.update.post', [$this, 'handleMediaUpdatePost']);
        // Only attach warning to the admin dashboard, not to every admin page
        $sharedEventManager->attach('Omeka\Controller\Admin\Index', 'view.browse.after', [$this, 'addAdminWarning']);
    }

    public function handleViewEditFormAfter($event): void
    {
        Debug::logEntry(__METHOD__);
        
        $view = $event->getTarget();
        $media = $view->media;
        Debug::log('Got media from view', __METHOD__);

        if (!$media || !$this->isVideoMedia($media)) {
            Debug::log('Not a video media, exiting', __METHOD__);
            Debug::logExit(__METHOD__);
            return;
        }

        Debug::log('Rendering video thumbnail selector', __METHOD__);
        echo $view->videoThumbnailSelector($media);
        
        Debug::logExit(__METHOD__);
    }

    public function handleMediaUpdatePost($event): void
    {
        Debug::logEntry(__METHOD__);
        
        $request = $event->getParam('request');
        $media = $event->getParam('response')->getContent();
        Debug::log('Got media from event: ' . (is_object($media) ? get_class($media) : 'not an object'), __METHOD__);

        if (!$this->isVideoMedia($media)) {
            Debug::log('Not a video media, exiting', __METHOD__);
            Debug::logExit(__METHOD__);
            return;
        }

        Debug::log('Processing video media update', __METHOD__);
        $data = $request->getContent();
        Debug::log('Request data: ' . json_encode(array_keys($data)), __METHOD__);
        
        if (isset($data['videothumbnail_frame'])) {
            Debug::log('Found videothumbnail_frame value: ' . $data['videothumbnail_frame'], __METHOD__);
            $this->updateVideoThumbnail($media, $data['videothumbnail_frame']);
        } else {
            Debug::log('No videothumbnail_frame in request data', __METHOD__);
        }
        
        Debug::logExit(__METHOD__);
    }

    public function addAdminWarning($event): void
    {
        Debug::logEntry(__METHOD__);
        
        $view = $event->getTarget();
        $serviceLocator = $this->getServiceLocator();
        
        // Get session container to track if we've shown the message
        $sessionContainer = new \Laminas\Session\Container('VideoThumbnail');
        
        // Only show the message once per session
        if (!empty($sessionContainer->warning_shown)) {
            Debug::log('Warning already shown in this session, skipping', __METHOD__);
            Debug::logExit(__METHOD__);
            return;
        }
        
        // Mark as shown
        $sessionContainer->warning_shown = true;
        
        $viewHelpers = $serviceLocator->get('ViewHelperManager');
        $url = $viewHelpers->get('url');
        Debug::log('Got view helpers and URL helper', __METHOD__);

        $jobUrl = $url('admin/id', ['controller' => 'job', 'action' => 'add', 'id' => 'VideoThumbnail\\Job\\ExtractFrames']);
        Debug::log('Generated job URL: ' . $jobUrl, __METHOD__);
        
        $message = sprintf(
            'You can %s to regenerate all video thumbnails.',
            sprintf(
                '<a href="%s">add a new job</a>',
                $jobUrl
            )
        );
        Debug::log('Created message with job link', __METHOD__);

        $flashMessenger = $viewHelpers->get('flashMessenger');
        $flashMessenger->addMessage($message, 'success');
        Debug::log('Added success message to flash messenger', __METHOD__);
        
        Debug::logExit(__METHOD__);
    }

    protected function isVideoMedia($media): bool
    {
        Debug::logEntry(__METHOD__, ['media' => $media !== null ? (get_class($media)) : 'null']);
        
        $mediaType = $media instanceof Media ? $media->getMediaType() : ($media instanceof MediaRepresentation ? $media->mediaType() : null);
        $isVideo = $mediaType && strpos($mediaType, 'video/') === 0;
        
        Debug::log('Media type: ' . ($mediaType ?? 'null') . ', Is video: ' . ($isVideo ? 'true' : 'false'), __METHOD__);
        Debug::logExit(__METHOD__, $isVideo);
        
        return $isVideo;
    }

    protected function updateVideoThumbnail($media, $selectedFrame): void
    {
        Debug::logEntry(__METHOD__, ['media' => $media instanceof Media ? 'Media Entity' : 'Media Representation', 'selectedFrame' => $selectedFrame]);
        
        try {
            Debug::log('Getting service locator and settings', __METHOD__);
            $serviceLocator = $this->getServiceLocator();
            $settings = $serviceLocator->get('Omeka\Settings');
            $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
            
            // Look for specific patterns that indicate stale paths (like Nix store paths)
            if (!empty($ffmpegPath) && (strpos($ffmpegPath, '/nix/store') !== false)) {
                Debug::log('Detected potential stale Nix store path, clearing and forcing auto-detection', __METHOD__);
                $settings->set('videothumbnail_ffmpeg_path', '');
                $ffmpegPath = '';
            }
            
            // Check if the configured path is valid
            if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
                Debug::log('Invalid FFmpeg path: ' . $ffmpegPath, __METHOD__);
                // Try default path as replacement
                if (file_exists('/usr/bin/ffmpeg') && is_executable('/usr/bin/ffmpeg')) {
                    $ffmpegPath = '/usr/bin/ffmpeg';
                    // Update settings for future use
                    $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
                    Debug::log('Using standard FFmpeg path: ' . $ffmpegPath, __METHOD__);
                } else {
                    // Try to auto-detect ffmpeg using common methods
                    $output = [];
                    $returnVar = null;
                    exec('which ffmpeg 2>/dev/null', $output, $returnVar);
                    if ($returnVar === 0 && !empty($output[0]) && file_exists($output[0]) && is_executable($output[0])) {
                        $ffmpegPath = $output[0];
                        $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
                        Debug::log('Found FFmpeg using which command: ' . $ffmpegPath, __METHOD__);
                    }
                }
            }
            
            // Final verification that FFmpeg path exists and is executable
            if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
                // Try some common paths as a final attempt
                $commonPaths = [
                    '/usr/bin/ffmpeg',
                    '/usr/local/bin/ffmpeg',
                    '/opt/local/bin/ffmpeg',
                    '/opt/bin/ffmpeg',
                    '/snap/bin/ffmpeg',
                    '/var/lib/flatpak/exports/bin/ffmpeg'
                ];
                
                foreach ($commonPaths as $path) {
                    if (file_exists($path) && is_executable($path)) {
                        $ffmpegPath = $path;
                        $settings->set('videothumbnail_ffmpeg_path', $ffmpegPath);
                        Debug::log('Found FFmpeg at common path: ' . $ffmpegPath, __METHOD__);
                        break;
                    }
                }
                
                // If still no valid path, we have to give up
                if (!file_exists($ffmpegPath) || !is_executable($ffmpegPath)) {
                    Debug::logError('FFmpeg not found at any known path', __METHOD__);
                    Debug::logExit(__METHOD__);
                    return;
                }
            }
            
            Debug::log('FFmpeg path: ' . $ffmpegPath, __METHOD__);

            Debug::log('Creating VideoFrameExtractor', __METHOD__);
            $extractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
            
            $filePath = $media instanceof MediaRepresentation ? $media->originalFilePath() : $media->getFilePath();
            $mediaId = $media instanceof MediaRepresentation ? $media->id() : $media->getId();
            Debug::log('Media file path: ' . $filePath, __METHOD__);
            Debug::log('Media ID: ' . $mediaId, __METHOD__);

            Debug::log('Getting video duration', __METHOD__);
            $duration = $extractor->getVideoDuration($filePath);
            Debug::log('Video duration: ' . $duration . ' seconds', __METHOD__);
            
            $frameTime = ($duration * $selectedFrame) / 100;
            Debug::log('Calculated frame time: ' . $frameTime . ' seconds (at ' . $selectedFrame . '% of ' . $duration . ' seconds)', __METHOD__);
            
            Debug::log('Extracting frame', __METHOD__);
            $extractedFrame = $extractor->extractFrame($filePath, $frameTime);

            if ($extractedFrame) {
                Debug::log('Frame extracted successfully: ' . $extractedFrame, __METHOD__);
                Debug::log('Creating temp file', __METHOD__);
                $tempManager = $serviceLocator->get('Omeka\File\TempFileFactory');
                $tempFile = $tempManager->build();
                $tempFile->setSourceName('thumbnail.jpg');
                $tempFile->setTempPath($extractedFrame);

                Debug::log('Getting entity manager', __METHOD__);
                $entityManager = $serviceLocator->get('Omeka\EntityManager');
                Debug::log('Finding media entity with ID: ' . $mediaId, __METHOD__);
                $mediaEntity = $entityManager->find('Omeka\Entity\Media', $mediaId);
                
                if (!$mediaEntity) {
                    Debug::logError('Media entity not found for ID: ' . $mediaId, __METHOD__);
                    Debug::logExit(__METHOD__);
                    return;
                }

                Debug::log('Getting file manager', __METHOD__);
                // Try to get file manager with fallback to ensure compatibility
                if ($serviceLocator->has('Omeka\File\Manager')) {
                    $fileManager = $serviceLocator->get('Omeka\File\Manager');
                } else {
                    $fileManager = $serviceLocator->get('Omeka\File\Store\Manager');
                }
                Debug::log('Storing thumbnails', __METHOD__);
                $fileManager->storeThumbnails($tempFile, $mediaEntity);
                Debug::log('Flushing entity manager', __METHOD__);
                $entityManager->flush();

                Debug::log('Removing temporary file', __METHOD__);
                unlink($tempFile->getTempPath());
                Debug::log('Thumbnail update completed successfully', __METHOD__);
            } else {
                Debug::logError('Failed to extract frame from video', __METHOD__);
            }
        } catch (\Exception $e) {
            Debug::logError('Exception updating video thumbnail: ' . $e->getMessage(), __METHOD__, $e);
        }
        
        Debug::logExit(__METHOD__);
    }

    protected function recursiveRemoveDirectory($directory): void
    {
        if (is_dir($directory)) {
            $objects = scandir($directory);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    $path = $directory . DIRECTORY_SEPARATOR . $object;
                    is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
                }
            }
            rmdir($directory);
        }
    }
}

