<?php
namespace VideoThumbnail\Media\Ingester;

use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;
use Omeka\Media\Ingester\MutableIngesterInterface;
use Omeka\Stdlib\ErrorStore;
use Laminas\Form\Element\File;
use Laminas\Http\PhpEnvironment\UploadProgressData;
use Laminas\View\Renderer\PhpRenderer;
use VideoThumbnail\Stdlib\VideoFrameExtractor;

class VideoThumbnail implements MutableIngesterInterface
{
    /**
     * @var TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var array
     */
    protected $settings;

    /**
     * @var VideoFrameExtractor
     */
    protected $videoFrameExtractor;

    /**
     * @param TempFileFactory $tempFileFactory
     * @param $settings
     * @param VideoFrameExtractor $videoFrameExtractor
     */
    public function __construct(TempFileFactory $tempFileFactory, $settings, VideoFrameExtractor $videoFrameExtractor)
    {
        $this->tempFileFactory = $tempFileFactory;
        $this->settings = $settings;
        $this->videoFrameExtractor = $videoFrameExtractor;
    }

    /**
     * Get the label for the ingester.
     *
     * @return string
     */
    public function getLabel()
    {
        return 'Video Thumbnail'; // @translate
    }

    /**
     * Create a new media entity from an uploaded file.
     *
     * @param Request $request
     * @param array $fileData
     * @param Media $media
     * @param ErrorStore $errorStore
     * @return bool
     */
    public function ingest(Request $request, array $fileData, Media $media, ErrorStore $errorStore)
    {
        // This ingester leverages the standard file ingester
        $fileIngester = new \Omeka\Media\Ingester\Upload($this->tempFileFactory);
        if (!$fileIngester->ingest($request, $fileData, $media, $errorStore)) {
            return false;
        }

        // Now extract frame for thumbnail if it's a video
        $mediaType = $media->getMediaType();
        if ($this->isVideoMedia($mediaType)) {
            $filePath = $media->getFilePath();
            $this->extractAndSetDefaultThumbnail($filePath, $media);
        }

        return true;
    }

    /**
     * Check if media type is a supported video format
     *
     * @param string $mediaType
     * @return bool
     */
    protected function isVideoMedia($mediaType)
    {
        return in_array($mediaType, ['video/mp4', 'video/quicktime']);
    }

    /**
     * Extract a frame and set it as the thumbnail
     *
     * @param string $filePath
     * @param Media $media
     */
    protected function extractAndSetDefaultThumbnail($filePath, Media $media)
    {
        // Get video duration
        $duration = $this->videoFrameExtractor->getVideoDuration($filePath);
        
        // Use default frame setting (percentage of the video)
        $defaultFrame = $this->settings->get('videothumbnail_default_frame', 10);
        $frameTime = ($duration * $defaultFrame) / 100;
        
        // Extract the frame
        $tempFile = $this->videoFrameExtractor->extractFrame($filePath, $frameTime);
        
        if ($tempFile) {
            // Generate thumbnails for the media
            $tempFileObj = $this->tempFileFactory->build();
            $tempFileObj->setSourceName('thumbnail.jpg');
            $tempFileObj->setTempPath($tempFile);
            
            $media->setHasThumbnails(true);
            
            // Store frame data for later use
            $media->setData([
                'video_duration' => $duration,
                'thumbnail_frame_time' => $frameTime,
                'thumbnail_frame_percentage' => $defaultFrame,
            ]);
            
            // Clean up
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Get the form elements required to configure ingest of a file.
     *
     * @param PhpRenderer $view
     * @param array $options
     * @return string
     */
    public function form(PhpRenderer $view, array $options = [])
    {
        // For ingestion, use the standard file upload form
        return $view->formFile('file[file]', [
            'class' => 'videothumbnail-file-input',
            'id' => 'videothumbnail-file-input',
        ]);
    }

    /**
     * Get the form elements used to edit a media after ingest.
     *
     * @param PhpRenderer $view
     * @param array $options
     * @return string
     */
    public function updateForm(PhpRenderer $view, array $options = [])
    {
        // For updates, use the standard file upload form
        return $view->formFile('file[file]', [
            'class' => 'videothumbnail-file-input',
            'id' => 'videothumbnail-file-input',
        ]);
    }

    /**
     * @param Media $media
     * @param Request $request
     * @param ErrorStore $errorStore
     * @return bool
     */
    public function update(Media $media, Request $request, ErrorStore $errorStore)
    {
        $fileData = $request->getValue('file');
        if (!isset($fileData['file']) || empty($fileData['file']['name'])) {
            return true;
        }

        // Update leverages the standard file ingester
        $fileIngester = new \Omeka\Media\Ingester\Upload($this->tempFileFactory);
        if (!$fileIngester->update($media, $request, $errorStore)) {
            return false;
        }

        // Now extract frame for thumbnail if it's a video
        $mediaType = $media->getMediaType();
        if ($this->isVideoMedia($mediaType)) {
            $filePath = $media->getFilePath();
            $this->extractAndSetDefaultThumbnail($filePath, $media);
        }

        return true;
    }
}
