<?php
/**
 * VideoThumbnail Plugin Demo Script
 * 
 * This script demonstrates the core functionality of the VideoThumbnail plugin
 * without requiring a full Omeka S installation.
 */

// Include the VideoFrameExtractor class
require_once __DIR__ . '/VideoThumbnail/src/Stdlib/VideoFrameExtractor.php';

// Set up basic HTML
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VideoThumbnail Demo</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .container {
            background: #f5f5f5;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        h1, h2 {
            color: #333;
        }
        pre {
            background: #eee;
            padding: 10px;
            border-radius: 3px;
            overflow: auto;
        }
        .frames-container {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }
        .frame {
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 4px;
            background: white;
            width: calc(25% - 15px);
            min-width: 200px;
        }
        .frame img {
            width: 100%;
            height: auto;
        }
        .frame-info {
            margin-top: 10px;
            font-size: 0.9em;
        }
        .error {
            color: red;
            font-weight: bold;
        }
        .success {
            color: green;
            font-weight: bold;
        }
        .upload-form {
            margin: 20px 0;
            padding: 20px;
            background: #e9f7ff;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <h1>VideoThumbnail Plugin Demo</h1>
    
    <div class="container">
        <h2>Environment Information</h2>
        <p>This page demonstrates the core video thumbnail extraction functionality of the VideoThumbnail Omeka S plugin.</p>
        
        <h3>PHP Version</h3>
        <pre><?= phpversion() ?></pre>
        
        <h3>FFmpeg Version</h3>
        <pre><?php
        $ffmpegPath = '/nix/store/3zc5jbvqzrn8zmva4fx5p0nh4yy03wk4-ffmpeg-6.1.1-bin/bin/ffmpeg';
        $output = [];
        exec(escapeshellcmd($ffmpegPath) . ' -version', $output);
        echo htmlspecialchars(implode("\n", array_slice($output, 0, 3)));
        ?></pre>
    </div>
    
    <div class="container">
        <h2>Example Frame Extraction</h2>
        <?php
        // Set PHP memory limit higher
        ini_set('memory_limit', '256M');
        
        // Create sample video URL - we'll use a smaller test video
        $sampleVideoUrl = "https://filesamples.com/samples/video/mp4/sample_640x360.mp4";
        $localVideoPath = __DIR__ . '/sample.mp4';
        
        // Check if we already have the sample video
        if (!file_exists($localVideoPath)) {
            echo "<p>Downloading sample video (this may take a moment)...</p>";
            
            // Download with curl instead of file_get_contents to handle larger files
            $ch = curl_init($sampleVideoUrl);
            $fp = fopen($localVideoPath, 'wb');
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $success = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            
            if (!$success) {
                echo "<p class='error'>Failed to download sample video: $error</p>";
                // Try a much smaller built-in test video instead
                echo "<p>Generating a simple test video file with FFmpeg...</p>";
                // Generate a 5-second test video with FFmpeg
                $testCmd = sprintf(
                    '%s -f lavfi -i testsrc=duration=5:size=640x360:rate=30 -c:v libx264 %s',
                    escapeshellcmd($ffmpegPath),
                    escapeshellarg($localVideoPath)
                );
                exec($testCmd, $output, $returnVal);
                
                if ($returnVal !== 0 || !file_exists($localVideoPath)) {
                    echo "<p class='error'>Failed to create test video.</p>";
                } else {
                    echo "<p class='success'>Generated test video successfully.</p>";
                }
            } else {
                echo "<p class='success'>Sample video downloaded successfully.</p>";
            }
        }
        
        // Proceed if we have the video file
        if (file_exists($localVideoPath)) {
            try {
                // Create frame extractor
                $extractor = new \VideoThumbnail\Stdlib\VideoFrameExtractor($ffmpegPath);
                
                // Get video duration
                $duration = $extractor->getVideoDuration($localVideoPath);
                echo "<p>Video duration: " . gmdate("H:i:s", $duration) . "</p>";
                
                // Extract frames at various positions
                $frameCount = 4; // Number of frames to extract
                $frames = [];
                
                echo "<h3>Extracted Frames</h3>";
                echo "<div class='frames-container'>";
                
                for ($i = 0; $i < $frameCount; $i++) {
                    $position = ($i / ($frameCount - 1)) * $duration;
                    $percent = ($position / $duration) * 100;
                    
                    echo "<div class='frame'>";
                    $framePath = $extractor->extractFrame($localVideoPath, $position);
                    
                    if ($framePath) {
                        // Convert to base64 for display
                        $imageData = base64_encode(file_get_contents($framePath));
                        echo "<img src='data:image/jpeg;base64," . $imageData . "' alt='Frame at " . gmdate("H:i:s", $position) . "'>";
                        echo "<div class='frame-info'>";
                        echo "Time: " . gmdate("H:i:s", $position) . " (" . round($percent) . "%)";
                        echo "</div>";
                        
                        // Clean up the temporary file
                        @unlink($framePath);
                    } else {
                        echo "<p class='error'>Failed to extract frame at position " . gmdate("H:i:s", $position) . "</p>";
                    }
                    
                    echo "</div>";
                }
                
                echo "</div>";
                
            } catch (Exception $e) {
                echo "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        } else {
            echo "<p class='error'>Sample video file not available.</p>";
        }
        ?>
    </div>
    
    <div class="container">
        <h2>VideoThumbnail Plugin Documentation</h2>
        
        <h3>Features</h3>
        <ul>
            <li>Automatically generates thumbnails for video files (MP4, MOV) during upload</li>
            <li>Provides a user interface to select specific frames from videos to use as thumbnails</li>
            <li>Includes a batch processing job to regenerate thumbnails for all videos</li>
            <li>Integrates with Omeka S's existing media management system</li>
        </ul>
        
        <h3>Requirements</h3>
        <ul>
            <li>Omeka S (tested with versions 3.x and 4.x)</li>
            <li>PHP 7.4 or higher</li>
            <li>FFmpeg (must be installed on the server)</li>
        </ul>
        
        <h3>Installation in Omeka S</h3>
        <ol>
            <li>Download the latest release of the VideoThumbnail plugin</li>
            <li>Unzip the module into the Omeka S <code>modules</code> directory</li>
            <li>Rename the unzipped directory to <code>VideoThumbnail</code></li>
            <li>Install the module from the admin panel</li>
            <li>Configure the FFmpeg path in the module settings</li>
        </ol>
    </div>
</body>
</html><?php
// End of file
?>