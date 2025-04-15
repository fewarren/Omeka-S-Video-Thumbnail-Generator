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
     * @param VideoFrameExtractor $videoFrameExtractor
     */
    public function __construct(VideoFrameExtractor $videoFrameExtractor)
    {
        $this->videoFrameExtractor = $videoFrameExtractor;
    }
    
    /**
     * Extract a single frame from a video
     *
     * @param string $videoPath
     * @param float $position
     * @return string|null
     */
    public function extractFrame($videoPath, $position)
    {
        return $this->videoFrameExtractor->extractFrame($videoPath, $position);
    }
    
    /**
     * Extract multiple frames from a video
     *
     * @param string $videoPath
     * @param int $count
     * @return array
     */
    public function extractFrames($videoPath, $count = 5)
    {
        return $this->videoFrameExtractor->extractFrames($videoPath, $count);
    }
    
    /**
     * Get video duration
     *
     * @param string $videoPath
     * @return float
     */
    public function getVideoDuration($videoPath)
    {
        return $this->videoFrameExtractor->getVideoDuration($videoPath);
    }
}