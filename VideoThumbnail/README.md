# Video Thumbnail

Video Thumbnail is an Omeka S module that allows automatic thumbnail generation for video files with frame selection capabilities.

## Features

- Automatically generates thumbnails for video files (MP4, MOV, WebM, etc.) during upload
- Provides a user interface to select specific frames from videos to use as thumbnails
- Configure which video formats are supported via the admin interface
- Specify the default frame position for thumbnail generation 
- Includes a batch processing job to regenerate thumbnails for all videos based on the selected frame position
- Integrates with Omeka S's existing media management system

## Requirements

- Omeka S (tested with versions 3.x and 4.x)
- PHP 7.4 or higher
- FFmpeg (must be installed on the server)

## Installation

1. Download the latest release
2. Unzip the module into the `modules` directory
3. Rename the unzipped directory to `VideoThumbnail`
4. Install the module from the admin panel

## Configuration

After installation, navigate to the module's configuration page:

1. Set the path to the FFmpeg executable (e.g., `/usr/bin/ffmpeg`)
2. Configure the number of frames to extract for selection (default: 5)
3. Set the default frame position as a percentage of video duration (default: 10%)
4. Select which video formats will be supported (MP4, MOV, WebM, OGG, AVI)

## Usage

### Uploading Videos

When you upload a video file (MP4 or MOV), the module automatically generates a thumbnail using the default frame position.

### Selecting a Different Frame

To select a different frame as the thumbnail:

1. Navigate to the media edit page
2. Scroll down to the "Video Thumbnail" section
3. Click "Select Frame"
4. Choose from the available frames
5. Click "Select" under your preferred frame

### Batch Processing

To regenerate thumbnails for all videos:

#### Option 1: From the Video Thumbnail configuration page

1. Navigate to the Video Thumbnail configuration page (Modules > Video Thumbnail)
2. Set your desired default frame position
3. Select the supported formats 
4. Check the "Regenerate All Video Thumbnails" option
5. Click "Save settings"

#### Option 2: From the job manager

1. Navigate to the admin dashboard
2. Click on the "Add a new job" link in the notification
3. Select "VideoThumbnail\Job\ExtractFrames" from the job type dropdown
4. Click "Submit"

Either method will run a background job to update all video thumbnails using the configured settings.

## Troubleshooting

- **FFmpeg not found**: Ensure FFmpeg is properly installed and the path is correctly set in the configuration.
- **No frames appear**: Check if the video file is readable by the server and is in a supported format.
- **Job processing errors**: Check the job logs for detailed error messages.
- **"Call to undefined method getArgs()" error**: This is fixed in the latest version. The module now handles different Omeka S job API versions.

## License

This module is licensed under the MIT License.

## Support

For issues and feature requests, please use the [module's GitHub issue tracker](https://github.com/omeka-s-modules/VideoThumbnail/issues).
