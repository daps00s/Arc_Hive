/* global notyf */
const notyf = new Notyf({
    duration: 4000,
    position: { x: 'right', y: 'top' },
    ripple: true
});

// Utility function for debouncing
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Highlight search term in text
function highlightSearchTerm(text, term) {
    if (!term || !text) return text;
    const regex = new RegExp(`(${term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
    return text.replace(regex, '<span class="highlight">$1</span>');
}

$(document).ready(function() {
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    if (!csrfToken) {
        console.error('CSRF token is missing');
        notyf.error('Session error: Please refresh the page');
        return;
    }

    const fileId = $('.file-viewer').find('.file-name-title').data('file-id') || new URLSearchParams(window.location.search).get('file_id');
    const initialSearchTerm = $('#searchInput').val();
    let currentPage = 1;
    const totalPages = parseInt($('#totalPages').text(), 10) || 1;

    // Highlight initial search term
    if (initialSearchTerm) {
        $('.page-content').each(function() {
            const $page = $(this);
            const text = $page.find('p').text();
            const highlighted = highlightSearchTerm(text, initialSearchTerm);
            $page.find('p').html(highlighted);
        });
    }

    // Sidebar toggle
    $('.sidebar .toggle-btn').on('click', function() {
        $('.sidebar').toggleClass('minimized');
        $('.main-container, .top-nav').toggleClass('resized');
    });

    // Pagination controls
    $('#prevPage').on('click', function() {
        if (currentPage > 1) {
            currentPage--;
            updatePageDisplay();
        }
    });

    $('#nextPage').on('click', function() {
        if (currentPage < totalPages) {
            currentPage++;
            updatePageDisplay();
        }
    });

    function updatePageDisplay() {
        $('.page-content').hide();
        $(`.page-content[data-page="${currentPage}"]`).show();
        $('#currentPage').text(currentPage);
        $('#prevPage').prop('disabled', currentPage === 1);
        $('#nextPage').prop('disabled', currentPage === totalPages);
        // Reapply highlighting for the current search term
        const searchTerm = $('#searchInput').val();
        if (searchTerm) {
            const $currentPage = $(`.page-content[data-page="${currentPage}"]`);
            const text = $currentPage.find('p').text();
            const highlighted = highlightSearchTerm(text, searchTerm);
            $currentPage.find('p').html(highlighted);
        }
    }

    // File info sidebar toggle
    $('.file-name-title').on('click', function() {
        if (!$('#fileInfoSidebar').hasClass('active')) {
            fetchFileInfo(fileId);
        }
    });

    $('#closeFileInfo').on('click', function() {
        $('#fileInfoSidebar').animate({ right: '-400px' }, 300, function() {
            $(this).removeClass('active').addClass('hidden');
        });
    });

    // Tab switching for file info sidebar
    $('.info-tab').on('click', function(e) {
        e.preventDefault();
        $('.info-tab').removeClass('active');
        $(this).addClass('active');
        $('.info-section').removeClass('active');
        const tabId = $(this).data('tab');
        $(`#${tabId}Tab`).addClass('active');
    });

    // Back to dashboard
    $('#backToDashboard').on('click', function() {
        window.location.href = 'dashboard.php';
    });

    // Search input handler
    const handleSearch = debounce(function() {
        const searchTerm = $('#searchInput').val().trim();
        if (searchTerm) {
            $('.page-content').each(function() {
                const $page = $(this);
                const text = $page.text();
                const highlighted = highlightSearchTerm(text, searchTerm);
                $page.find('p').html(highlighted);
            });
        } else {
            $('.page-content').each(function() {
                const $page = $(this);
                $page.find('p').html($page.find('p').text());
            });
        }
    }, 300);

    $('#searchInput').on('input', handleSearch);

    function fetchFileInfo(fileId) {
        $('#loadingSpinner').show();
        $.ajax({
            url: 'api/file_operations.php',
            method: 'POST',
            data: {
                action: 'fetch_file_info',
                file_id: fileId,
                csrf_token: csrfToken
            },
            success: function(data) {
                $('#loadingSpinner').hide();
                if (data.success) {
                    $('#fileSentTo').text(data.activity.sent_to ? data.activity.sent_to.join(', ') : 'None');
                    $('#fileReceivedBy').text(data.activity.received_by ? data.activity.received_by.join(', ') : 'None');
                    $('#fileCopiedBy').text(data.activity.copied_by ? data.activity.copied_by.join(', ') : 'None');
                    $('#fileRenamedTo').text(data.activity.renamed_to || 'None');
                    $('#fileInfoSidebar')
                        .removeClass('hidden')
                        .addClass('active')
                        .css({ right: '-400px' })
                        .animate({ right: '0' }, 300);
                } else {
                    notyf.error(data.message || 'Failed to load file information');
                }
            },
            error: function(xhr, status, error) {
                $('#loadingSpinner').hide();
                console.error('File info fetch error:', status, error, xhr.responseText);
                notyf.error('Failed to load file information');
            }
        });
    }

    // Keyboard navigation for pagination
    $(document).on('keydown', function(e) {
        if (e.key === 'ArrowLeft' && currentPage > 1) {
            currentPage--;
            updatePageDisplay();
        } else if (e.key === 'ArrowRight' && currentPage < totalPages) {
            currentPage++;
            updatePageDisplay();
        }
    });

    // Ensure accessibility
    $('#searchInput').attr('aria-label', 'Search within file content');
    $('.pagination-btn').attr('tabindex', '0');
});