<?php
namespace VideoThumbnail\Media\Renderer;

use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Media\Renderer\RendererInterface;
use Laminas\View\Renderer\PhpRenderer;

class VideoThumbnail implements RendererInterface
{
    /**
     * Render a video with thumbnail support
     *
     * @param PhpRenderer $view
     * @param MediaRepresentation $media
     * @param array $options
     * @return string
     */
    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = [])
    {
        $escapeHtml = $view->plugin('escapeHtml');
        $escapeHtmlAttr = $view->plugin('escapeHtmlAttr');
        $assetUrl = $view->plugin('assetUrl');
        
        // Include our CSS/JS
        $view->headLink()->appendStylesheet($assetUrl('css/video-thumbnail.css', 'VideoThumbnail'));
        $view->headScript()->appendFile($assetUrl('js/video-thumbnail.js', 'VideoThumbnail'));
        
        $mediaType = $media->mediaType();
        $url = $escapeHtmlAttr($media->originalUrl());
        $title = $media->displayTitle();
        $title = $escapeHtmlAttr($title ? $title : $media->source());

        // Get thumbnail URL if available
        $thumbnailUrl = $media->thumbnailUrl('medium');
        
        // Common attributes for all video elements
        $attributes = [
            'class' => 'video-js vjs-fluid',
            'controls' => 'controls',
            'preload' => 'metadata',
            'width' => '100%',
            'height' => 'auto',
        ];
        
        // Add poster (thumbnail) if available
        if ($thumbnailUrl) {
            $attributes['poster'] = $thumbnailUrl;
        }
        
        // Convert attributes to string
        $attributesStr = '';
        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }
            if ($value === '') {
                $attributesStr .= " $key";
                continue;
            }
            $attributesStr .= " $key=\"" . $escapeHtmlAttr($value) . '"';
        }
        
        return sprintf(
            '<video%s>
                <source src="%s" type="%s">
                %s
            </video>',
            $attributesStr,
            $url,
            $escapeHtml($mediaType),
            $view->translate('Your browser does not support the video tag.')
        );
    }
}
