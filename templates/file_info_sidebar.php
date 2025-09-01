<aside id="fileInfoSidebar" class="file-info-sidebar hidden" aria-hidden="true">
    <header class="sidebar-header">
        <h2>File Information</h2>
        <button id="closeFileInfo" class="close-sidebar" aria-label="Close file info sidebar">
            <i class="fas fa-times"></i>
        </button>
    </header>
    <section id="filePreview" class="file-info-section file-preview" aria-label="File preview"></section>
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
    </section>
</aside>

<style>
    .file-info-sidebar {
        position: fixed;
        top: 0;
        right: -400px;
        width: 400px;
        height: 100vh;
        background: var(--card-background);
        box-shadow: -4px 0 12px rgba(0, 0, 0, 0.15);
        z-index: 1200;
        padding: 24px;
        overflow-y: auto;
        transition: right 0.3s ease-in-out;
        scrollbar-width: thin;
        display: flex;
        flex-direction: column;
        gap: 24px;
    }

    .file-info-sidebar:not(.hidden) {
        right: 0;
    }

    .sidebar-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-bottom: 16px;
        border-bottom: 1px solid var(--border-color);
    }

    .file-info-sidebar h2 {
        font-size: 1.5rem;
        font-weight: 600;
        margin: 0;
        line-height: 1.2;
    }

    .close-sidebar {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 1.25rem;
        color: var(--text-secondary);
        padding: 8px;
        border-radius: 4px;
        transition: all 0.2s ease;
    }

    .close-sidebar:hover,
    .close-sidebar:focus {
        background: var(--danger-hover);
        color: var(--danger-color);
        outline: none;
    }

    .file-preview {
        text-align: center;
        padding: 16px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--background-secondary);
    }

    #filePreview img {
        max-width: 100%;
        max-height: 200px;
        border-radius: 6px;
        object-fit: contain;
    }

    #filePreview i {
        font-size: 2.5rem;
        color: var(--text-secondary);
    }

    #filePreview p {
        font-size: 0.875rem;
        color: var(--text-secondary);
        margin: 8px 0 0;
    }

    .info-tabs {
        display: flex;
        gap: 8px;
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 8px;
    }

    .info-tab-button {
        flex: 1;
        padding: 12px;
        background: var(--background-secondary);
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.875rem;
        font-weight: 500;
        color: var(--text-color);
        text-align: center;
        transition: all 0.2s ease;
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
        padding: 16px;
        border-radius: 6px;
        background: var(--background-secondary);
    }

    .info-tab-content.active {
        display: block;
    }

    .file-info-section {
        padding: 12px 0;
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
        margin-bottom: 4px;
    }

    .info-fields-list {
        list-style: disc;
        padding-left: 20px;
        margin: 12px 0;
    }

    .info-fields-list li {
        font-size: 0.875rem;
        color: var(--text-color);
        margin-bottom: 8px;
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
            right: -100%;
        }

        .file-info-sidebar:not(.hidden) {
            right: 0;
        }
    }
</style>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    // Close file info sidebar
    $('#closeFileInfo').on('click', function() {
        $('#fileInfoSidebar').animate({
            right: '-400px'
        }, 300, function() {
            $(this).addClass('hidden').attr('aria-hidden', 'true');
        });
    });

    // Tab switching for file info sidebar
    $('#fileInfoSidebar').on('click', '.info-tab-button', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $('#fileInfoSidebar .info-tab-button').removeClass('active').attr('aria-selected', 'false');
        $(this).addClass('active').attr('aria-selected', 'true');
        $('#fileInfoSidebar .info-tab-content').removeClass('active');
        const tabId = $(this).data('tab');
        $(`#${tabId}Tab`).addClass('active');
    });
</script>