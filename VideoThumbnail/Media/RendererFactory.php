<?php
namespace VideoThumbnail\Service\Media;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Media\Renderer\VideoThumbnail;

class RendererFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new VideoThumbnail();
    }
}
