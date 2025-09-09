<aside id="recipientFileInfoSidebar" class="file-info-sidebar hidden" aria-hidden="true">
    <header class="sidebar-header">
        <h2>File Preview</h2>
        <button id="closeRecipientFileInfo" class="close-sidebar" aria-label="Close file preview sidebar">
            <i class="fas fa-times"></i>
        </button>
    </header>
    <section id="recipientFilePreview" class="file-info-section file-preview" aria-label="File preview"></section>
    <section id="actionButtons" class="action-buttons"></section>
</aside>

<style>
    .file-info-sidebar {
        position: fixed;
        top: 0;
        right: -400px;
        width: 400px;
        height: 100vh;
        background: var(--card-background, #fff);
        box-shadow: -4px 0 12px rgba(0, 0, 0, 0.15);
        z-index: 1200;
        padding: 24px;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: var(--text-secondary, #6c757d) var(--background-secondary, #f8f9fa);
        display: flex;
        flex-direction: column;
        gap: 24px;
        box-sizing: border-box;
    }

    .file-info-sidebar:not(.hidden) {
        right: 0;
    }

    .file-info-sidebar::-webkit-scrollbar {
        width: 8px;
    }

    .file-info-sidebar::-webkit-scrollbar-track {
        background: var(--background-secondary, #f8f9fa);
    }

    .file-info-sidebar::-webkit-scrollbar-thumb {
        background: var(--text-secondary, #6c757d);
        border-radius: 4px;
    }

    .sidebar-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-bottom: 16px;
        border-bottom: 1px solid var(--border-color, #dee2e6);
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
        color: var(--text-secondary, #6c757d);
        padding: 8px;
        border-radius: 4px;
        transition: all 0.2s ease;
    }

    .close-sidebar:hover,
    .close-sidebar:focus {
        background: var(--danger-hover, #f8d7da);
        color: var(--danger-color, #dc3545);
        outline: none;
    }

    .file-preview {
        text-align: center;
        padding: 16px;
        border: 1px solid var(--border-color, #dee2e6);
        border-radius: 8px;
        background: var(--background-secondary, #f8f9fa);
        min-height: 200px;
        max-height: 500px;
        overflow-y: auto;
        box-sizing: border-box;
    }

    .file-preview img {
        max-width: 100%;
        max-height: 100%;
        border-radius: 6px;
        object-fit: contain;
    }

    .file-preview pre {
        text-align: left;
        background: #f8f8f8;
        padding: 10px;
        border-radius: 4px;
        max-height: 100%;
        overflow-y: auto;
        font-size: 0.875rem;
        color: var(--text-color, #212529);
        margin: 0;
    }

    .file-preview embed {
        width: 100%;
        height: 100%;
        max-height: 500px;
        border: none;
    }

    .file-preview p.error {
        color: var(--danger-color, #dc3545);
        font-size: 0.875rem;
        margin: 8px 0 0;
    }

    .file-preview i {
        font-size: 2.5rem;
        color: var(--text-secondary, #6c757d);
    }

    .file-preview p {
        font-size: 0.875rem;
        color: var(--text-secondary, #6c757d);
        margin: 8px 0 0;
    }

    .action-buttons {
        margin-top: 16px;
        text-align: center;
        display: flex;
        justify-content: center;
        gap: 10px;
    }

    .action-buttons .btn {
        padding: 8px 16px;
        font-size: 0.875rem;
    }

    @media (max-width: 1024px) {
        .file-info-sidebar {
            width: 100%;
            max-width: 600px;
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

        .file-preview {
            max-height: 300px;
        }
    }
</style>

<script>
    // Close recipient file info sidebar
    $('#closeRecipientFileInfo').on('click', function() {
        $('#recipientFileInfoSidebar').animate({
            right: '-400px'
        }, 300, function() {
            $(this).addClass('hidden').attr('aria-hidden', 'true');
        });
    });
</script>