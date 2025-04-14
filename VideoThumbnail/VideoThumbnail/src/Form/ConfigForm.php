<?php
namespace VideoThumbnail\Form;

use Laminas\Form\Form;
use Laminas\Form\Element\Text;
use Laminas\Form\Element\Number;

class ConfigForm extends Form
{
    public function init()
    {
        $this->add([
            'name' => 'videothumbnail_ffmpeg_path',
            'type' => Text::class,
            'options' => [
                'label' => 'FFmpeg Path', // @translate
                'info' => 'Full path to FFmpeg executable (e.g., /usr/bin/ffmpeg)', // @translate
            ],
            'attributes' => [
                'required' => true,
                'id' => 'videothumbnail_ffmpeg_path',
            ],
        ]);

        $this->add([
            'name' => 'videothumbnail_frames_count',
            'type' => Number::class,
            'options' => [
                'label' => 'Number of Frames', // @translate
                'info' => 'Number of frames to extract for selection (higher values require more processing time)', // @translate
            ],
            'attributes' => [
                'required' => true,
                'min' => 3,
                'max' => 20,
                'step' => 1,
                'value' => 5,
                'id' => 'videothumbnail_frames_count',
            ],
        ]);

        $this->add([
            'name' => 'videothumbnail_default_frame',
            'type' => Number::class,
            'options' => [
                'label' => 'Default Frame Position (% of video duration)', // @translate
                'info' => 'Default position for thumbnail extraction as percentage of video duration (0-100)', // @translate
            ],
            'attributes' => [
                'required' => true,
                'min' => 0,
                'max' => 100,
                'step' => 1,
                'value' => 10,
                'id' => 'videothumbnail_default_frame',
            ],
        ]);
    }
}
