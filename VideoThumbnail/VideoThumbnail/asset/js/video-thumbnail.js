const VideoThumbnail = {
    /**
     * Initialize the frame selector
     * 
     * @param {string} saveUrl The URL to save the selected frame
     */
    initFrameSelector: function(saveUrl) {
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
    }
};
