<?php
namespace VideoThumbnail\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use VideoThumbnail\Controller\Admin\VideoThumbnailController;

class VideoThumbnailControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $entityManager = $services->get('Omeka\EntityManager');
        $config = $services->get('Config');
        $settings = $services->get('Omeka\Settings');
        
        // Try to get file manager using a more robust approach
        $fileManager = null;
        $fileManagerServices = [
            'Omeka\File\Manager',
            'Omeka\File\Store\Manager',
            'Omeka\File\TempFileFactory',
        ];
        
        foreach ($fileManagerServices as $service) {
            if ($services->has($service)) {
                $fileManager = $services->get($service);
                break;
            }
        }
        
        if (!$fileManager) {
            throw new \Exception('Could not locate file manager service');
        }
        
        $controller = new VideoThumbnailController(
            $entityManager,
            $fileManager
        );
        $controller->setSettings($settings);
        
        return $controller;
    }
}