<?php
namespace VideoThumbnail;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'VideoThumbnail\View\Helper\VideoThumbnailSelector' => Service\ViewHelper\VideoThumbnailSelectorFactory::class,
        ],
    ],
    'form_elements' => [
        'factories' => [
            Form\ConfigForm::class => Service\Form\ConfigFormFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'VideoThumbnail\Controller\Admin\VideoThumbnail' => Service\Controller\VideoThumbnailControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'extractVideoFrames' => Service\ControllerPlugin\ExtractVideoFramesFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'video-thumbnail' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/video-thumbnail[/:action]',
                            'defaults' => [
                                '__NAMESPACE__' => 'VideoThumbnail\Controller\Admin',
                                'controller' => 'VideoThumbnail',
                                'action' => 'index',
                            ],
                        ],
                    ],
                    'video-thumbnail-select-frame' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/video-thumbnail/select-frame/:id',
                            'defaults' => [
                                '__NAMESPACE__' => 'VideoThumbnail\Controller\Admin',
                                'controller' => 'VideoThumbnail',
                                'action' => 'select-frame',
                            ],
                            'constraints' => [
                                'id' => '\d+',
                            ],
                        ],
                    ],
                    'video-thumbnail-extract-frame' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/video-thumbnail/extract-frame',
                            'defaults' => [
                                '__NAMESPACE__' => 'VideoThumbnail\Controller\Admin',
                                'controller' => 'VideoThumbnail',
                                'action' => 'extract-frame',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Video Thumbnail', 
                'route' => 'admin/video-thumbnail',
                'resource' => 'VideoThumbnail\Controller\Admin\VideoThumbnail',
            ],
        ],
    ],
    'media_ingesters' => [
        'factories' => [
            'videothumbnail' => Service\Media\IngesterFactory::class,
        ],
    ],
    'media_renderers' => [
        'factories' => [
            'videothumbnail' => Service\Media\RendererFactory::class,
        ],
        // Aliases are now registered dynamically based on the configured formats
    ],
    'js_translate_strings' => [
        'Select Frame', 
        'Generating thumbnails...', 
        'Error loading video frames', 
        'Select this frame as thumbnail',
    ],
    'assets' => [
        'module_paths' => [
            'VideoThumbnail' => 'VideoThumbnail/asset',
        ],
    ],
    'job' => [
        'dispatcher_strategies' => [
            'factories' => [
                Job\DispatchStrategy\VideoThumbnailStrategy::class => Service\Job\DispatchStrategy\VideoThumbnailStrategyFactory::class,
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
            'VideoThumbnail\VideoFrameExtractor' => Service\VideoFrameExtractorFactory::class,
        ],
        'delegators' => [
            'Omeka\File\Store\Manager' => [
                Service\FileManagerDelegatorFactory::class,
            ],
        ],
    ],
];
