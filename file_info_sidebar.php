<aside id="fileInfoSidebar" class="file-info-sidebar hidden" aria-hidden="true">
    <header class="sidebar-header">
        <h2 id="fileInfoTitle"></h2>
        <div class="sidebar-notification" aria-live="polite"></div>
        <button id="closeFileInfo" class="close-sidebar" aria-label="Close file info sidebar">
            <i class="fas fa-times"></i>
        </button>
    </header>
    <section id="filePreview" class="file-info-section file-preview" aria-label="File preview and QR code">
        <div class="preview-container">
            <button class="preview-nav prev" aria-label="Previous view (File Preview or QR Code)" disabled>
                <i class="fas fa-chevron-left"></i>
            </button>
            <div class="preview-content active" data-view="file" aria-hidden="false"></div>
            <div class="preview-content" data-view="qr" aria-hidden="true"></div>
            <button class="preview-nav next" aria-label="Next view (File Preview or QR Code)">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </section>
    <nav class="info-tabs" role="tablist">
        <button class="info-tab-button active" data-tab="details" role="tab" aria-selected="true" aria-controls="detailsTab">Details</button>
        <button class="info-tab-button" data-tab="activity" role="tab" aria-selected="false" aria-controls="activityTab">Activity</button>
    </nav>
    <section id="detailsTab" class="info-tab-content active" role="tabpanel" aria-labelledby="details-tab"></section>
    <section id="activityTab" class="info-tab-content" role="tabpanel" aria-labelledby="activity-tab">
        <div class="file-info-section">
            <strong>Sent To:</strong> <span id="fileSentTo"></span>
        </div>
        <div class="file-info-section">
            <strong>Received By:</strong> <span id="fileReceivedBy"></span>
        </div>
        <div class="file-info-section">
            <strong>Copied By:</strong> <span id="fileCopiedBy"></span>
        </div>
        <div class="file-info-section">
            <strong>Renamed To:</strong> <span id="fileRenamedTo"></span>
        </div>
        <div class="file-info-section">
            <strong>Requested By:</strong> <span id="fileRequestedBy"></span>
        </div>
    </section>
</aside>

<style>
    /* style/file_info_sidebar.css */
    :root {
        --primary-color: #50c878;
        --primary-hover: #40a867;
        --background-color: #f7fafc;
        --card-background: #ffffff;
        --text-color: #2d3748;
        --text-secondary: #718096;
        --border-color: #e2e8f0;
        --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        --danger-color: #e53e3e;
        --border-radius: 8px;
        --transition: all 0.3s ease;
        --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        --spacing-unit: 8px;
    }

    .file-info-sidebar {
        position: fixed;
        top: 0;
        right: 0;
        width: 400px;
        height: 100vh;
        background: var(--card-background);
        box-shadow: -4px 0 12px rgba(0, 0, 0, 0.15);
        z-index: 1200;
        padding: calc(var(--spacing-unit) * 3);
        overflow-y: auto;
        transition: transform var(--transition);
        transform: translateX(100%);
        display: flex;
        scrollbar-width: thin;
        scrollbar-gutter: stable both-edges;
        flex-direction: column;
        gap: calc(var(--spacing-unit) * 3);
    }

    .file-info-sidebar:not(.hidden) {
        transform: translateX(0);
    }

    .file-info-sidebar.hidden {
        display: none;
    }

    .sidebar-header {
        position: relative;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-bottom: calc(var(--spacing-unit) * 2);
        border-bottom: 1px solid var(--border-color);
    }

    .file-info-sidebar h2 {
        font-size: 1.25rem;
        font-weight: 600;
        margin: 0;
        line-height: 1.2;
        color: var(--text-color);
        max-width: 250px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .close-sidebar {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 1.25rem;
        color: var(--text-secondary);
        padding: calc(var(--spacing-unit));
        border-radius: var(--border-radius);
        transition: var(--transition);
        z-index: 2;
    }

    .close-sidebar:hover,
    .close-sidebar:focus {
        background: var(--danger-color);
        color: white;
        outline: none;
    }

    .sidebar-notification {
        position: absolute;
        top: 50%;
        right: calc(var(--spacing-unit) * 3);
        transform: translate(100%, -50%);
        padding: calc(var(--spacing-unit)) calc(var(--spacing-unit) * 1.5);
        font-size: 0.75rem;
        font-weight: 500;
        color: white;
        border-radius: var(--border-radius);
        opacity: 0;
        max-width: 200px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        transition: transform 0.3s ease, opacity 0.3s ease;
        z-index: 1;
    }

    .sidebar-notification.success {
        background: var(--primary-color);
    }

    .sidebar-notification.error {
        background: var(--danger-color);
    }

    .sidebar-notification.visible {
        transform: translate(0, -50%);
        opacity: 1;
    }

    .file-preview {
        text-align: center;
        padding: calc(var(--spacing-unit) * 2);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius);
        background: var(--background-color);
        position: relative;
    }

    .preview-container {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .preview-content {
        display: none;
        max-width: 100%;
        max-height: 200px;
    }

    .preview-content.active {
        display: block;
    }

    .preview-content[data-view="file"] img {
        max-width: 100%;
        max-height: 200px;
        border-radius: 6px;
        object-fit: contain;
    }

    .preview-content[data-view="file"] i {
        font-size: 2.5rem;
        color: var(--text-secondary);
    }

    .preview-content[data-view="file"] p {
        font-size: 0.875rem;
        color: var(--text-secondary);
        margin: calc(var(--spacing-unit)) 0 0;
    }

    .preview-content[data-view="qr"] img {
        max-width: 100%;
        max-height: 200px;
        border-radius: 6px;
        object-fit: contain;
    }

    .preview-nav {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        background: var(--card-background);
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius);
        padding: calc(var(--spacing-unit));
        cursor: pointer;
        font-size: 1rem;
        color: var(--text-color);
        transition: var(--transition);
    }

    .preview-nav.prev {
        left: calc(var(--spacing-unit));
    }

    .preview-nav.next {
        right: calc(var(--spacing-unit));
    }

    .preview-nav:hover,
    .preview-nav:focus {
        background: var(--primary-color);
        color: white;
        outline: none;
    }

    .preview-nav:disabled {
        background: var(--border-color);
        cursor: not-allowed;
        opacity: 0.6;
    }

    .info-tabs {
        display: flex;
        gap: calc(var(--spacing-unit));
        border-bottom: 1px solid var(--border-color);
        padding-bottom: calc(var(--spacing-unit));
    }

    .info-tab-button {
        flex: 1;
        padding: calc(var(--spacing-unit) * 1.5);
        background: var(--background-color);
        border: none;
        border-radius: var(--border-radius);
        cursor: pointer;
        font-size: 0.875rem;
        font-weight: 500;
        color: var(--text-color);
        text-align: center;
        transition: var(--transition);
    }

    .info-tab-button[aria-selected="true"] {
        background: var(--primary-color);
        color: white;
    }

    .info-tab-button:hover,
    .info-tab-button:focus {
        background: var(--primary-hover);
        color: white;
        outline: none;
    }

    .info-tab-content {
        display: none;
        padding: calc(var(--spacing-unit) * 2);
        border-radius: var(--border-radius);
        background: var(--background-color);
    }

    .info-tab-content.active {
        display: block;
    }

    .file-info-section {
        padding: calc(var(--spacing-unit) * 1.5) 0;
        font-size: 0.875rem;
        border-bottom: 1px solid var(--border-color);
    }

    .file-info-section:last-child {
        border-bottom: none;
    }

    .file-info-section strong {
        display: block;
        font-weight: 600;
        color: var(--text-color);
        margin-bottom: calc(var(--spacing-unit) * 0.5);
    }

    .info-fields-list {
        list-style: disc;
        padding-left: 20px;
        margin: calc(var(--spacing-unit) * 1.5) 0;
    }

    .info-fields-list li {
        font-size: 0.875rem;
        color: var(--text-color);
        margin-bottom: calc(var(--spacing-unit));
    }

    @media (max-width: 1024px) {
        .file-info-sidebar {
            width: 100%;
            max-width: 400px;
        }
    }

    @media (max-width: 768px) {
        .file-info-sidebar {
            width: 100%;
        }
    }
</style>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    // templates/file_info_sidebar.js
    $(document).ready(function() {
        // Ensure sidebar is hidden on page load
        $('#fileInfoSidebar').addClass('hidden').attr('aria-hidden', 'true');

        // Close file info sidebar
        $('#closeFileInfo').on('click', function() {
            $('#fileInfoSidebar').addClass('hidden').attr('aria-hidden', 'true');
            $('.sidebar-notification').removeClass('visible').empty();
        });

        // Tab switching for file info sidebar
        $('#fileInfoSidebar').on('click', '.info-tab-button', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $('#fileInfoSidebar .info-tab-button').removeClass('active').attr('aria-selected', 'false');
            $(this).addClass('active').attr('aria-selected', 'true');
            $('#fileInfoSidebar .info-tab-content').removeClass('active').attr('aria-hidden', 'true');
            const tabId = $(this).data('tab');
            $(`#${tabId}Tab`).addClass('active').attr('aria-hidden', 'false');
        });

        // Preview navigation
        $('#fileInfoSidebar').on('click', '.preview-nav', function() {
            const $previewContainer = $(this).closest('.preview-container');
            const $contents = $previewContainer.find('.preview-content');
            const $current = $contents.filter('.active');
            const currentIndex = $contents.index($current);
            const isNext = $(this).hasClass('next');
            const newIndex = isNext ? currentIndex + 1 : currentIndex - 1;

            if (newIndex >= 0 && newIndex < $contents.length) {
                $current.removeClass('active').attr('aria-hidden', 'true');
                $contents.eq(newIndex).addClass('active').attr('aria-hidden', 'false');
                $previewContainer.find('.prev').prop('disabled', newIndex === 0);
                $previewContainer.find('.next').prop('disabled', newIndex === $contents.length - 1);
            }
        });

        // Function to show sidebar notification
        function showSidebarNotification(message, type = 'success') {
            const $notification = $('.sidebar-notification');
            $notification
                .text(message)
                .removeClass('success error')
                .addClass(type)
                .addClass('visible');
            setTimeout(() => {
                $notification.removeClass('visible');
                setTimeout(() => $notification.empty(), 300); // Clear after slide-out
            }, 3000);
        }

        // Function to populate file info sidebar
        window.populateFileInfoSidebar = function(fileId, csrfToken) {
            $.ajax({
                url: 'api/file_operations.php',
                method: 'POST',
                data: {
                    action: 'fetch_file_info',
                    file_id: fileId,
                    csrf_token: csrfToken
                },
                success: function(data) {
                    console.log('File info response:', data);
                    if (data.success) {
                        // Populate header with file name
                        $('#fileInfoTitle').text(data.file.file_name || 'File Information');

                        // Populate Details tab
                        const $detailsTab = $('#detailsTab').empty();
                        $detailsTab.append(`<div class="file-info-section"><strong>Access:</strong> ${data.file.access_level || 'Unknown'}</div>`);
                        $detailsTab.append(`<div class="file-info-section"><strong>File Type:</strong> ${data.file.file_type || data.file.document_type || 'Unknown'}</div>`);
                        $detailsTab.append(`<div class="file-info-section"><strong>File Size:</strong> ${(data.file.file_size ? (data.file.file_size / 1024).toFixed(2) + ' KB' : 'N/A')}</div>`);
                        $detailsTab.append(`<div class="file-info-section"><strong>Category:</strong> ${data.file.document_type || 'Unknown'}</div>`);
                        $detailsTab.append(`<div class="file-info-section"><strong>Uploaded By:</strong> ${data.file.uploader_name || 'Unknown'}</div>`);
                        $detailsTab.append(`<div class="file-info-section"><strong>Uploaded On:</strong> ${data.file.upload_date ? new Date(data.file.upload_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A'}</div>`);
                        $detailsTab.append(`<div class="file-info-section"><strong>Physical Location:</strong> ${data.file.physical_location || 'N/A'}</div>`);
                        $detailsTab.append(`<div class="file-info-section"><strong>Document Type:</strong> ${data.file.document_type || 'Unknown'}</div>`);

                        if (data.formatted_fields && data.formatted_fields.length > 0) {
                            $detailsTab.append('<div class="file-info-section"><strong>Document Fields:</strong><ul class="info-fields-list"></ul></div>');
                            const $fieldsList = $detailsTab.find('.info-fields-list');
                            data.formatted_fields.forEach(field => {
                                $fieldsList.append(`<li>${field.key}: ${field.value}</li>`);
                            });
                        } else {
                            $detailsTab.append('<div class="file-info-section"><strong>Document Fields:</strong> None</div>');
                        }

                        // Populate Activity tab
                        $('#fileSentTo').text(data.activity.sent_to?.join(', ') || 'None');
                        $('#fileReceivedBy').text(data.activity.received_by?.join(', ') || 'None');
                        $('#fileCopiedBy').text(data.activity.copied_by?.join(', ') || 'None');
                        $('#fileRenamedTo').text(data.activity.renamed_to || 'N/A');
                        $('#fileRequestedBy').text(data.activity.requested_by?.join(', ') || 'None');

                        // Populate file preview
                        const $fileContent = $('#filePreview .preview-content[data-view="file"]').empty();
                        if (data.file.file_type && data.file.file_type.startsWith('image/') && data.file.file_path) {
                            const $img = $(`<img src="${data.file.file_path}" alt="File preview" style="max-width: 100%; max-height: 200px;">`);
                            $img.on('error', function() {
                                $fileContent.empty().append('<i class="fas fa-file fa-3x"></i><p>Preview Not Available</p>');
                            });
                            $fileContent.append($img);
                        } else if (data.file.file_type === 'application/pdf') {
                            $fileContent.append('<i class="fas fa-file-pdf fa-3x"></i><p>PDF Preview Not Available</p>');
                        } else {
                            $fileContent.append('<i class="fas fa-file fa-3x"></i><p>Preview Not Available</p>');
                        }

                        // Populate QR code
                        const $qrContent = $('#filePreview .preview-content[data-view="qr"]').empty();
                        if (data.file.qr_path) {
                            const $qrImg = $(`<img src="${data.file.qr_path}" alt="QR code for file" style="max-width: 100%; max-height: 200px;">`);
                            $qrImg.on('error', function() {
                                $qrContent.empty().append('<p>QR code not available</p>');
                            });
                            $qrContent.append($qrImg);
                        } else {
                            $qrContent.append('<p>No QR code available</p>');
                        }

                        // Initialize preview navigation
                        $('#filePreview .preview-nav.prev').prop('disabled', true);
                        $('#filePreview .preview-nav.next').prop('disabled', !data.file.qr_path);
                        $('#filePreview .preview-content').removeClass('active').attr('aria-hidden', 'true');
                        $('#filePreview .preview-content[data-view="file"]').addClass('active').attr('aria-hidden', 'false');

                        // Show sidebar with animation
                        $('#fileInfoSidebar')
                            .removeClass('hidden')
                            .css({
                                right: '-400px'
                            })
                            .animate({
                                right: '0'
                            }, 300)
                            .attr('aria-hidden', 'false');

                        // Show success notification
                        showSidebarNotification('File info loaded', 'success');
                    } else {
                        $('#fileInfoTitle').text('File Information');
                        showSidebarNotification(data.message || 'Failed to load file information', 'error');
                    }
                },
                error: function(jqXHR) {
                    console.error('File info fetch error:', jqXHR.status, jqXHR.statusText, jqXHR.responseText);
                    $('#fileInfoTitle').text('File Information');
                    showSidebarNotification(jqXHR.responseJSON?.message || 'Failed to load file information due to a server error', 'error');
                }
            });
        };
    });
</script>