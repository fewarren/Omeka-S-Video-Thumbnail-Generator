<?php
namespace VideoThumbnail\Service\ControllerPlugin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Controller\Plugin\ExtractVideoFrames;

class ExtractVideoFramesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Attempt to retrieve VideoFrameExtractor from the service container
        if ($services->has('VideoThumbnail\Stdlib\VideoFrameExtractor')) {
            $videoFrameExtractor = $services->get('VideoThumbnail\Stdlib\VideoFrameExtractor');
        } else {
            // Fallback to direct instantiation if not registered
            $settings = $services->get('Omeka\Settings');
            $ffmpegPath = $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg');
            $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
        }
        
        return new ExtractVideoFrames($videoFrameExtractor);
    }
}
