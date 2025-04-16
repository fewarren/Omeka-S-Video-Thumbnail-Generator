<?php
namespace VideoThumbnail\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use VideoThumbnail\Stdlib\VideoFrameExtractor;

class ExtractVideoFrames extends AbstractPlugin
{
    /**
     * @var VideoFrameExtractor
     */
    protected $videoFrameExtractor;
    
    /**
     * Constructor
     *
     * @param VideoFrameExtractor $videoFrameExtractor The video frame extractor instance used for frame operations.
     */
    public function __construct(VideoFrameExtractor $videoFrameExtractor)
    {
        $this->videoFrameExtractor = $videoFrameExtractor;
    }
    
    /**
     * Extract a single frame from a video.
     *
     * @param string $videoPath The path to the video file.
     * @param float $position The position in the video to extract the frame, measured in seconds.
     * @return string|null The path to the extracted frame image, or null if extraction fails.
     */
    public function extractFrame($videoPath, $position)
    {
        return $this->videoFrameExtractor->extractFrame($videoPath, $position);
    }
    
    /**
     * Extract multiple frames from a video.
     *
     * @param string $videoPath The path to the video file.
     * @param int $count The number of frames to extract, distributed evenly across the video's duration.
     * @return array An array of paths to the extracted frame images.
     */
    public function extractFrames($videoPath, $count = 5)
    {
        return $this->videoFrameExtractor->extractFrames($videoPath, $count);
    }
    
    /**
     * Get the duration of a video.
     *
     * @param string $videoPath The path to the video file.
     * @return float The duration of the video in seconds.
     */
    public function getVideoDuration($videoPath)
    {
        return $this->videoFrameExtractor->getVideoDuration($videoPath);
    }
}
