<?php
namespace VideoThumbnail\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\MediaRepresentation;

class VideoThumbnailSelector extends AbstractHelper
{
    /**
     * Render the video thumbnail selector
     *
     * @param MediaRepresentation $media
     * @return string
     */
    public function __invoke(MediaRepresentation $media)
    {
        $view = $this->getView();
        $assetUrl = $view->plugin('assetUrl');
        
        // Include assets
        $view->headLink()->appendStylesheet($assetUrl('css/video-thumbnail.css', 'VideoThumbnail'));
        $view->headScript()->appendFile($assetUrl('js/video-thumbnail.js', 'VideoThumbnail'));
        
        $mediaType = $media->mediaType();
        
        // Only render for supported video types
        if (!in_array($mediaType, ['video/mp4', 'video/quicktime'])) {
            return '';
        }
        
        // Get data
        $mediaData = $media->data();
        $currentFrame = isset($mediaData['thumbnail_frame_percentage']) 
            ? $mediaData['thumbnail_frame_percentage'] 
            : null;
        
        return $view->partial(
            'common/video-thumbnail-selector',
            [
                'media' => $media,
                'currentFrame' => $currentFrame,
            ]
        );
    }
}
