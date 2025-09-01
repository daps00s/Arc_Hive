/* script/search.js */
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

    const $searchForm = $('#searchForm');
    const $searchInput = $('#searchInput');
    const $searchButton = $('.search-button');
    const $loadingSpinner = $('.loading-spinner');
    const searchQuery = $searchInput.val();

    // Initialize sidebar and modal as hidden
    $('#fileInfoSidebar').addClass('hidden').attr('aria-hidden', 'true');
    $('#fullPageModal').removeClass('active').attr('aria-hidden', 'true');

    // Throttle search input
    let searchTimeout;
    $searchInput.on('input', function() {
        clearTimeout(searchTimeout);
        const query = $(this).val().trim();
        $searchButton.prop('disabled', query.length < 2);
        if (query.length >= 2) {
            searchTimeout = setTimeout(() => {
                $searchForm.submit();
            }, 500);
        }
    });

    // Search form submission
    $searchForm.on('submit', function(e) {
        e.preventDefault();
        const query = $searchInput.val().trim();
        if (query.length >= 2) {
            $searchButton.addClass('hidden');
            $loadingSpinner.removeClass('hidden');
            window.location.href = `?query=${encodeURIComponent(query)}&csrf_token=${encodeURIComponent(csrfToken)}`;
        } else {
            notyf.error('Search query must be at least 2 characters long');
        }
    });

    // Retry search
    $('#retrySearch').on('click', function(e) {
        e.preventDefault();
        $searchForm.submit();
    });

    // Sidebar toggle
    $('.sidebar .toggle-btn').on('click', function() {
        $('.sidebar').toggleClass('minimized');
        $('.main-container, .top-nav').toggleClass('resized');
    });

    // Kebab menu toggle
    $(document).on('click', '.kebab-menu', function(e) {
        e.stopPropagation();
        const $fileItem = $(this).closest('.result-item');
        const $fileMenu = $fileItem.find('.file-menu');
        const isExpanded = $fileMenu.hasClass('hidden');
        $('.file-menu').addClass('hidden').attr('aria-expanded', 'false');
        $fileMenu.toggleClass('hidden').attr('aria-expanded', isExpanded);
        $(this).attr('aria-expanded', isExpanded);
    });

    // Close file menu on outside click
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.kebab-menu, .file-menu').length) {
            $('.file-menu').addClass('hidden').attr('aria-expanded', 'false');
            $('.kebab-menu').attr('aria-expanded', 'false');
        }
    });

    // Match pagination
    $(document).on('click', '.next-match', function() {
        const $contentMatches = $(this).closest('.content-matches');
        const $matches = $contentMatches.find('.match-item');
        const $current = $contentMatches.find('.match-item.active');
        const currentIndex = parseInt($current.data('index'));
        const nextIndex = currentIndex + 1;
        if (nextIndex < $matches.length) {
            $current.removeClass('active').attr('aria-hidden', 'true');
            $matches.eq(nextIndex).addClass('active').attr('aria-hidden', 'false');
            $contentMatches.find('.match-counter').text(`${nextIndex + 1} of ${$matches.length}`);
            $contentMatches.find('.prev-match').prop('disabled', false);
            if (nextIndex === $matches.length - 1) {
                $(this).prop('disabled', true);
            }
        }
    });

    $(document).on('click', '.prev-match', function() {
        const $contentMatches = $(this).closest('.content-matches');
        const $matches = $contentMatches.find('.match-item');
        const $current = $contentMatches.find('.match-item.active');
        const currentIndex = parseInt($current.data('index'));
        const prevIndex = currentIndex - 1;
        if (prevIndex >= 0) {
            $current.removeClass('active').attr('aria-hidden', 'true');
            $matches.eq(prevIndex).addClass('active').attr('aria-hidden', 'false');
            $contentMatches.find('.match-counter').text(`${prevIndex + 1} of ${$matches.length}`);
            $contentMatches.find('.next-match').prop('disabled', false);
            if (prevIndex === 0) {
                $(this).prop('disabled', true);
            }
        }
    });

    // View full page in modal
    $(document).on('click', '.view-full-page', function() {
        const $matchItem = $(this).closest('.match-item');
        const fileId = $matchItem.data('file-id');
        const pageNumber = parseInt($matchItem.data('page-number'));
        $loadingSpinner.removeClass('hidden');
        $('#fullPageModal').addClass('active').attr('aria-hidden', 'false');
        $('#modalContent').html('<p>Loading...</p>');

        // Fetch page text and matched pages
        $.ajax({
            url: 'api/fetch_page_text.php',
            method: 'POST',
            data: {
                file_id: fileId,
                page_number: pageNumber,
                search_query: searchQuery,
                csrf_token: csrfToken
            },
            success: function(data) {
                $loadingSpinner.addClass('hidden');
                if (data.success) {
                    const highlightedText = highlightSearchTerm(data.text, searchQuery);
                    $('#modalContent').html(highlightedText);
                    $('#modalPageNumber').text(pageNumber);
                    $('#modalTotalPages').text(data.total_pages);
                    $('#fullPageModal').data('file-id', fileId);
                    $('#fullPageModal').data('current-page', pageNumber);
                    $('#fullPageModal').data('total-pages', data.total_pages);
                    $('#fullPageModal').data('matched-pages', data.matched_pages);
                    const matchedPages = data.matched_pages || [];
                    const currentIndex = matchedPages.indexOf(pageNumber);
                    $('#fullPageModal .modal-nav.prev').prop('disabled', currentIndex <= 0);
                    $('#fullPageModal .modal-nav.next').prop('disabled', currentIndex >= matchedPages.length - 1);
                    notyf.success('Page loaded successfully');
                } else {
                    $('#modalContent').html(`<p class="error-message">${data.message}</p>`);
                    $('#modalPageNumber').text(pageNumber);
                    $('#modalTotalPages').text(data.total_pages || 1);
                    $('#fullPageModal .modal-nav.prev').prop('disabled', true);
                    $('#fullPageModal .modal-nav.next').prop('disabled', true);
                    notyf.error(data.message || 'Failed to load page content');
                }
            },
            error: function(jqXHR) {
                $loadingSpinner.addClass('hidden');
                $('#modalContent').html('<p class="error-message">Failed to load page content due to a server error</p>');
                $('#modalPageNumber').text(pageNumber);
                $('#modalTotalPages').text(1);
                $('#fullPageModal .modal-nav.prev').prop('disabled', true);
                $('#fullPageModal .modal-nav.next').prop('disabled', true);
                notyf.error(jqXHR.responseJSON?.message || 'Failed to load page content due to a server error');
            }
        });
    });

    // Modal navigation
    $(document).on('click', '.modal-nav', function() {
        const fileId = $('#fullPageModal').data('file-id');
        const currentPage = parseInt($('#fullPageModal').data('current-page'));
        const matchedPages = $('#fullPageModal').data('matched-pages') || [];
        const totalPages = parseInt($('#fullPageModal').data('total-pages'));
        const currentIndex = matchedPages.indexOf(currentPage);
        const isNext = $(this).hasClass('next');
        const nextIndex = isNext ? currentIndex + 1 : currentIndex - 1;

        if (nextIndex >= 0 && nextIndex < matchedPages.length) {
            const newPage = matchedPages[nextIndex];
            $loadingSpinner.removeClass('hidden');
            $.ajax({
                url: 'api/fetch_page_text.php',
                method: 'POST',
                data: {
                    file_id: fileId,
                    page_number: newPage,
                    search_query: searchQuery,
                    csrf_token: csrfToken
                },
                success: function(data) {
                    $loadingSpinner.addClass('hidden');
                    if (data.success) {
                        const highlightedText = highlightSearchTerm(data.text, searchQuery);
                        $('#modalContent').html(highlightedText);
                        $('#modalPageNumber').text(newPage);
                        $('#modalTotalPages').text(data.total_pages);
                        $('#fullPageModal').data('current-page', newPage);
                        $('#fullPageModal').data('matched-pages', data.matched_pages);
                        $('#fullPageModal .modal-nav.prev').prop('disabled', nextIndex <= 0);
                        $('#fullPageModal .modal-nav.next').prop('disabled', nextIndex >= matchedPages.length - 1);
                    } else {
                        $('#modalContent').html(`<p class="error-message">${data.message}</p>`);
                        notyf.error(data.message || 'Failed to load page content');
                    }
                },
                error: function(jqXHR) {
                    $loadingSpinner.addClass('hidden');
                    $('#modalContent').html('<p class="error-message">Failed to load page content due to a server error</p>');
                    notyf.error(jqXHR.responseJSON?.message || 'Failed to load page content due to a server error');
                }
            });
        }
    });

    // Close modal
    $(document).on('click', '.modal-close', function() {
        $('#fullPageModal').removeClass('active').attr('aria-hidden', 'true');
        $('#modalContent').empty();
        $('#fullPageModal').removeData('file-id').removeData('current-page').removeData('total-pages').removeData('matched-pages');
    });

    // File menu actions
    $(document).on('click', '.file-menu button', function() {
        const $fileItem = $(this).closest('.result-item');
        const fileId = $fileItem.data('file-id');
        const action = $(this).attr('class').split(' ')[0];
        $loadingSpinner.removeClass('hidden');
        $('.file-menu').addClass('hidden').attr('aria-expanded', 'false');
        $('.kebab-menu').attr('aria-expanded', 'false');

        if (action === 'file-info') {
            window.populateFileInfoSidebar(fileId, csrfToken);
            $loadingSpinner.addClass('hidden');
        } else if (action === 'request-file') {
            $.ajax({
                url: 'api/file_operations.php',
                method: 'POST',
                data: {
                    action: 'request_file_access',
                    file_id: fileId,
                    csrf_token: csrfToken
                },
                success: function(data) {
                    $loadingSpinner.addClass('hidden');
                    if (data.success) {
                        notyf.success('Access request sent successfully');
                    } else {
                        notyf.error(data.message || 'Failed to send access request');
                    }
                },
                error: function(jqXHR) {
                    $loadingSpinner.addClass('hidden');
                    notyf.error(jqXHR.responseJSON?.message || 'Failed to send access request due to a server error');
                }
            });
        } else {
            $loadingSpinner.addClass('hidden');
            notyf.error('Action not implemented yet');
        }
    });
});