<?php
namespace VideoThumbnail\Form;

use Laminas\Form\Form;
use Laminas\Form\Element\Checkbox;
use Laminas\Form\Element\MultiCheckbox;
use Laminas\Form\Element\Number;

class ConfigBatchForm extends Form
{
    public function init()
    {
        // Add CSRF protection
        $this->add([
            'type' => 'csrf',
            'name' => 'csrf',
            'options' => [
                'csrf_options' => [
                    'timeout' => 600,
                ],
            ],
        ]);

        $this->add([
            'name' => 'default_frame_position',
            'type' => Number::class,
            'options' => [
                'label' => 'Default Frame Position (% of video duration)', // @translate
                'info' => 'Default position for thumbnail extraction as percentage of video duration (0-100). This applies to batch operations and newly uploaded videos.', // @translate
            ],
            'attributes' => [
                'required' => true,
                'min' => 0,
                'max' => 100,
                'step' => 1,
                'value' => 10,
                'id' => 'default_frame_position',
            ],
        ]);

        $this->add([
            'name' => 'supported_formats',
            'type' => MultiCheckbox::class,
            'options' => [
                'label' => 'Supported Video Formats', // @translate
                'info' => 'Select the video formats that should be processed by the video thumbnail generator', // @translate
                'value_options' => [
                    'video/mp4' => 'MP4 (video/mp4)',
                    'video/quicktime' => 'MOV/QuickTime (video/quicktime)',
                    'video/x-msvideo' => 'AVI (video/x-msvideo)',
                    'video/webm' => 'WebM (video/webm)',
                    'video/ogg' => 'OGG (video/ogg)',
                ],
            ],
            'attributes' => [
                'id' => 'supported_formats',
            ],
        ]);

        $this->add([
            'name' => 'regenerate_thumbnails',
            'type' => Checkbox::class,
            'options' => [
                'label' => 'Regenerate All Video Thumbnails', // @translate
                'info' => 'Check this box to regenerate thumbnails for all supported video files using the default frame position above. This will create a background job.', // @translate
            ],
            'attributes' => [
                'id' => 'regenerate_thumbnails',
            ],
        ]);
    }
}