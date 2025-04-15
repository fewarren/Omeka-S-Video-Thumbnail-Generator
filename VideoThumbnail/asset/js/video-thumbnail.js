const VideoThumbnail = {
    /**
     * Initialize the frame selector
     * 
     * @param {string} saveUrl The URL to save the selected frame
     * @param {string} extractUrl The URL to extract frames asynchronously
     * @param {number} mediaId The media ID
     * @param {number} framesCount Number of frames to extract
     * @param {number} duration Video duration in seconds
     */
    initFrameSelector: function(saveUrl, extractUrl, mediaId, framesCount, duration, currentFramePercent) {
        const framesContainer = document.getElementById('frames-container');
        const loadingOverlay = document.getElementById('video-thumbnail-loading');
        
        if (loadingOverlay) {
            loadingOverlay.style.display = 'flex';
        }
        
        // Load frames asynchronously
        this.loadFrames(extractUrl, mediaId, framesCount, duration)
            .then(frames => {
                if (loadingOverlay) {
                    loadingOverlay.style.display = 'none';
                }
                
                // Render frames
                this.renderFrames(frames, framesContainer, mediaId, currentFramePercent);
                
                // Initialize select buttons
                this.initSelectButtons(saveUrl);
            })
            .catch(error => {
                if (loadingOverlay) {
                    loadingOverlay.style.display = 'none';
                }
                
                console.error('Error loading frames:', error);
                if (framesContainer) {
                    framesContainer.innerHTML = '<div class="error">' + 
                        Omeka.jsTranslate('Error loading video frames') + '</div>';
                }
            });
    },
    
    /**
     * Load frames asynchronously
     * 
     * @param {string} extractUrl The URL to extract frames
     * @param {number} mediaId The media ID
     * @param {number} framesCount Number of frames to extract
     * @param {number} duration Video duration in seconds
     * @return {Promise} Promise that resolves with frame data
     */
    loadFrames: function(extractUrl, mediaId, framesCount, duration) {
        const frames = [];
        const promises = [];
        
        // Calculate frame positions evenly distributed throughout the video
        for (let i = 0; i < framesCount; i++) {
            const percent = framesCount > 1 ? (i / (framesCount - 1)) * 100 : 10;
            
            // Create form data for this frame
            const formData = new FormData();
            formData.append('media_id', mediaId);
            formData.append('position', percent);
            
            // Make AJAX request to extract the frame
            const promise = fetch(extractUrl, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    frames.push(data);
                }
                return data;
            });
            
            promises.push(promise);
        }
        
        // Wait for all frame extractions to complete
        return Promise.all(promises).then(() => {
            // Sort frames by time position
            return frames.sort((a, b) => a.time - b.time);
        });
    },
    
    /**
     * Render frames in the container
     * 
     * @param {Array} frames Array of frame data
     * @param {Element} container Container element
     * @param {number} mediaId The media ID
     * @param {number|null} currentFramePercent The current frame percentage (if any)
     */
    renderFrames: function(frames, container, mediaId, currentFramePercent) {
        if (!container) return;
        
        container.innerHTML = '';
        
        frames.forEach(frame => {
            const frameElement = document.createElement('div');
            frameElement.className = 'frame';
            
            // If this is the current frame, highlight it
            if (currentFramePercent !== null && Math.abs(frame.percent - currentFramePercent) < 1) {
                frameElement.classList.add('current-frame');
            }
            
            const img = document.createElement('img');
            img.src = frame.image;
            img.alt = 'Frame at ' + this.formatTime(frame.time);
            
            const info = document.createElement('div');
            info.className = 'frame-info';
            info.textContent = this.formatTime(frame.time) + ' (' + Math.round(frame.percent) + '%)';
            
            const selectButton = document.createElement('button');
            selectButton.className = 'select-frame o-icon-edit';
            selectButton.setAttribute('data-media-id', mediaId);
            selectButton.setAttribute('data-position', frame.percent);
            selectButton.title = Omeka.jsTranslate('Select this frame as thumbnail');
            
            frameElement.appendChild(img);
            frameElement.appendChild(info);
            frameElement.appendChild(selectButton);
            
            container.appendChild(frameElement);
        });
    },
    
    /**
     * Initialize select buttons
     * 
     * @param {string} saveUrl The URL to save the selected frame
     */
    initSelectButtons: function(saveUrl) {
        // Get all select frame buttons
        const selectButtons = document.querySelectorAll('.select-frame');
        const loadingOverlay = document.getElementById('video-thumbnail-loading');
        
        // Add event listener to each button
        selectButtons.forEach(button => {
            button.addEventListener('click', function(event) {
                event.preventDefault();
                
                // Show loading overlay
                if (loadingOverlay) {
                    loadingOverlay.style.display = 'flex';
                }
                
                // Get data attributes
                const mediaId = this.getAttribute('data-media-id');
                const position = this.getAttribute('data-position');
                
                // Create form data
                const formData = new FormData();
                formData.append('media_id', mediaId);
                formData.append('position', position);
                
                // Make AJAX request to save the selected frame
                fetch(saveUrl, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    // Hide loading overlay
                    if (loadingOverlay) {
                        loadingOverlay.style.display = 'none';
                    }
                    
                    if (data.success) {
                        // Success - update current thumbnail and show success message
                        const currentThumbnail = document.querySelector('.current-thumbnail img');
                        if (currentThumbnail && data.thumbnailUrl) {
                            // Add timestamp to force cache refresh
                            currentThumbnail.src = data.thumbnailUrl + '?t=' + Date.now();
                        }
                        
                        alert(data.message || 'Thumbnail updated successfully');
                    } else {
                        // Error
                        alert(data.message || 'An error occurred while updating the thumbnail');
                    }
                })
                .catch(error => {
                    // Hide loading overlay
                    if (loadingOverlay) {
                        loadingOverlay.style.display = 'none';
                    }
                    
                    console.error('Error:', error);
                    alert('An error occurred while updating the thumbnail');
                });
            });
        });
    },
    
    /**
     * Format seconds as HH:MM:SS
     * 
     * @param {number} seconds
     * @return {string}
     */
    formatTime: function(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.floor(seconds % 60);
        
        return (h > 0 ? h + ':' : '') + 
               (m < 10 ? '0' + m : m) + ':' + 
               (s < 10 ? '0' + s : s);
    }
};
