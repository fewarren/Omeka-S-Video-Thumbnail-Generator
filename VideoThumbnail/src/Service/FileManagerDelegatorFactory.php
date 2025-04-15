<?php
namespace VideoThumbnail\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;

/**
 * Delegator factory to ensure backward compatibility with different versions of Omeka S
 * by making the File\Store\Manager service compatible with the File\Manager interface
 */
class FileManagerDelegatorFactory implements DelegatorFactoryInterface
{
    /**
     * Creates a delegator for the FileManager service
     */
    public function __invoke(ContainerInterface $container, $name, callable $callback, array $options = null)
    {
        $fileManager = $callback();
        
        // Register the file manager as the Omeka\File\Manager service if it doesn't exist
        if (!$container->has('Omeka\File\Manager')) {
            // Register this service in the service manager
            $services = $container->get('ServiceManager');
            if (method_exists($services, 'setService')) {
                $services->setService('Omeka\File\Manager', $fileManager);
            } else {
                // For older Laminas versions
                $services->setService('Omeka\File\Manager', $fileManager);
            }
        }
        
        return $fileManager;
    }
}