<?php
namespace VideoThumbnail\Service\Media;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Media\Ingester\VideoThumbnail;

class IngesterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $settings = $services->get('Omeka\Settings');
        $videoFrameExtractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor(
            $settings->get('videothumbnail_ffmpeg_path', '/usr/bin/ffmpeg')
        );
        
        return new VideoThumbnail(
            $tempFileFactory,
            $settings,
            $videoFrameExtractor
        );
    }
}
