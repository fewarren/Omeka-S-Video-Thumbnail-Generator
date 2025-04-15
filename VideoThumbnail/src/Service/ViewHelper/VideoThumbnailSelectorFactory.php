<?php
namespace VideoThumbnail\Service\ViewHelper;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\View\Helper\VideoThumbnailSelector;

class VideoThumbnailSelectorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new VideoThumbnailSelector();
    }
}