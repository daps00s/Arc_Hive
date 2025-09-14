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

    // Initialize modals as hidden
    $('#fileInfoSidebar').addClass('hidden').attr('aria-hidden', 'true');
    $('#fullPageModal').removeClass('active').attr('aria-hidden', 'true');
    $('#imageModal').removeClass('active').attr('aria-hidden', 'true');

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

    // Image viewer state
    let zoomLevel = 1;
    let panX = 0;
    let panY = 0;
    let rotation = 0;
    let isPanning = false;
    let startX, startY;

    // View full page or image
    $(document).on('click', '.view-full-page', function() {
        const $matchItem = $(this).closest('.match-item');
        const fileId = $matchItem.data('file-id');
        const pageNumber = parseInt($matchItem.data('page-number'));
        $loadingSpinner.removeClass('hidden');

        // Fetch page content
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
                    if (data.is_image) {
                        // Show image modal
                        $('#imageModal').addClass('active').attr('aria-hidden', 'false');
                        $('#fullPageModal').removeClass('active').attr('aria-hidden', 'true');
                        $('#modalImage').attr('src', data.image_url);
                        $('#imageCaption').text(`Image ${pageNumber} of file ID ${fileId}`);
                        $('#imagePageNumber').text(pageNumber);
                        $('#imageTotalPages').text(data.total_pages);
                        $('#imageModal').data('file-id', fileId);
                        $('#imageModal').data('current-page', pageNumber);
                        $('#imageModal').data('total-pages', data.total_pages);
                        $('#imageModal').data('matched-pages', data.matched_pages);
                        const matchedPages = data.matched_pages || [];
                        const currentIndex = matchedPages.indexOf(pageNumber);
                        $('#imageModal .modal-nav.prev').prop('disabled', currentIndex <= 0);
                        $('#imageModal .modal-nav.next').prop('disabled', currentIndex >= matchedPages.length - 1);
                        // Reset image transformations
                        zoomLevel = 1;
                        panX = 0;
                        panY = 0;
                        rotation = 0;
                        updateImageTransform();
                        notyf.success('Image loaded successfully');
                    } else {
                        // Show text modal
                        $('#fullPageModal').addClass('active').attr('aria-hidden', 'false');
                        $('#imageModal').removeClass('active').attr('aria-hidden', 'true');
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
                    }
                } else {
                    if (data.is_image) {
                        $('#imageModal').addClass('active').attr('aria-hidden', 'false');
                        $('#fullPageModal').removeClass('active').attr('aria-hidden', 'true');
                        $('#modalImage').attr('src', '');
                        $('#imageCaption').text('Error: ' + data.message);
                        $('#imagePageNumber').text(pageNumber);
                        $('#imageTotalPages').text(data.total_pages || 1);
                        $('#imageModal .modal-nav.prev').prop('disabled', true);
                        $('#imageModal .modal-nav.next').prop('disabled', true);
                    } else {
                        $('#fullPageModal').addClass('active').attr('aria-hidden', 'false');
                        $('#imageModal').removeClass('active').attr('aria-hidden', 'true');
                        $('#modalContent').html(`<p class="error-message">${data.message}</p>`);
                        $('#modalPageNumber').text(pageNumber);
                        $('#modalTotalPages').text(data.total_pages || 1);
                        $('#fullPageModal .modal-nav.prev').prop('disabled', true);
                        $('#fullPageModal .modal-nav.next').prop('disabled', true);
                    }
                    notyf.error(data.message || 'Failed to load content');
                }
            },
            error: function(jqXHR) {
                $loadingSpinner.addClass('hidden');
                $('#fullPageModal').addClass('active').attr('aria-hidden', 'false');
                $('#imageModal').removeClass('active').attr('aria-hidden', 'true');
                $('#modalContent').html('<p class="error-message">Failed to load content due to a server error</p>');
                $('#modalPageNumber').text(pageNumber);
                $('#modalTotalPages').text(1);
                $('#fullPageModal .modal-nav.prev').prop('disabled', true);
                $('#fullPageModal .modal-nav.next').prop('disabled', true);
                notyf.error(jqXHR.responseJSON?.message || 'Failed to load content due to a server error');
            }
        });
    });

    // Update image transform
    function updateImageTransform() {
        const $image = $('#modalImage');
        $image.css('transform', `scale(${zoomLevel}) translate(${panX}px, ${panY}px) rotate(${rotation}deg)`);
        $('#imageCaption').text(`Image ${$('#imagePageNumber').text()} of file ID ${$('#imageModal').data('file-id')} (Zoom: ${Math.round(zoomLevel * 100)}%)`);
    }

    // Image controls
    $(document).on('click', '.image-control.zoom-in', function() {
        zoomLevel = Math.min(zoomLevel + 0.2, 3); // Max zoom: 300%
        updateImageTransform();
        notyf.success(`Zoomed in to ${Math.round(zoomLevel * 100)}%`);
    });

    $(document).on('click', '.image-control.zoom-out', function() {
        zoomLevel = Math.max(zoomLevel - 0.2, 0.5); // Min zoom: 50%
        updateImageTransform();
        notyf.success(`Zoomed out to ${Math.round(zoomLevel * 100)}%`);
    });

    $(document).on('click', '.image-control.rotate-left', function() {
        rotation -= 90;
        updateImageTransform();
        notyf.success('Rotated left');
    });

    $(document).on('click', '.image-control.rotate-right', function() {
        rotation += 90;
        updateImageTransform();
        notyf.success('Rotated right');
    });

    $(document).on('click', '.image-control.reset', function() {
        zoomLevel = 1;
        panX = 0;
        panY = 0;
        rotation = 0;
        updateImageTransform();
        notyf.success('View reset');
    });

    // Image panning
    $('#modalImage').on('mousedown', function(e) {
        if (zoomLevel > 1) {
            isPanning = true;
            startX = e.clientX - panX;
            startY = e.clientY - panY;
            $(this).css('cursor', 'grabbing');
        }
    });

    $(document).on('mousemove', function(e) {
        if (isPanning) {
            panX = e.clientX - startX;
            panY = e.clientY - startY;
            updateImageTransform();
        }
    });

    $(document).on('mouseup', function() {
        isPanning = false;
        $('#modalImage').css('cursor', 'grab');
    });

    // Prevent panning outside modal
    $('#imageModal').on('mouseleave', function() {
        isPanning = false;
        $('#modalImage').css('cursor', 'grab');
    });

    // Keyboard navigation for image controls
    $(document).on('keydown', function(e) {
        if ($('#imageModal').hasClass('active')) {
            switch (e.key) {
                case '+':
                case '=':
                    $('.image-control.zoom-in').trigger('click');
                    e.preventDefault();
                    break;
                case '-':
                    $('.image-control.zoom-out').trigger('click');
                    e.preventDefault();
                    break;
                case 'ArrowLeft':
                    $('.image-control.rotate-left').trigger('click');
                    e.preventDefault();
                    break;
                case 'ArrowRight':
                    $('.image-control.rotate-right').trigger('click');
                    e.preventDefault();
                    break;
                case 'r':
                case 'R':
                    $('.image-control.reset').trigger('click');
                    e.preventDefault();
                    break;
                case 'Escape':
                    $('.modal-close').trigger('click');
                    e.preventDefault();
                    break;
            }
        }
    });

    // Modal navigation for both modals
    $(document).on('click', '.modal-nav', function() {
        const $modal = $(this).closest('.modal');
        const isImageModal = $modal.is('#imageModal');
        const modalId = isImageModal ? '#imageModal' : '#fullPageModal';
        const fileId = $(modalId).data('file-id');
        const currentPage = parseInt($(modalId).data('current-page'));
        const matchedPages = $(modalId).data('matched-pages') || [];
        const totalPages = parseInt($(modalId).data('total-pages'));
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
                        if (data.is_image) {
                            $('#imageModal').addClass('active').attr('aria-hidden', 'false');
                            $('#fullPageModal').removeClass('active').attr('aria-hidden', 'true');
                            $('#modalImage').attr('src', data.image_url);
                            $('#imageCaption').text(`Image ${newPage} of file ID ${fileId}`);
                            $('#imagePageNumber').text(newPage);
                            $('#imageTotalPages').text(data.total_pages);
                            $('#imageModal').data('current-page', newPage);
                            $('#imageModal').data('matched-pages', data.matched_pages);
                            $('#imageModal .modal-nav.prev').prop('disabled', nextIndex <= 0);
                            $('#imageModal .modal-nav.next').prop('disabled', nextIndex >= matchedPages.length - 1);
                            zoomLevel = 1;
                            panX = 0;
                            panY = 0;
                            rotation = 0;
                            updateImageTransform();
                        } else {
                            $('#fullPageModal').addClass('active').attr('aria-hidden', 'false');
                            $('#imageModal').removeClass('active').attr('aria-hidden', 'true');
                            const highlightedText = highlightSearchTerm(data.text, searchQuery);
                            $('#modalContent').html(highlightedText);
                            $('#modalPageNumber').text(newPage);
                            $('#modalTotalPages').text(data.total_pages);
                            $('#fullPageModal').data('current-page', newPage);
                            $('#fullPageModal').data('matched-pages', data.matched_pages);
                            $('#fullPageModal .modal-nav.prev').prop('disabled', nextIndex <= 0);
                            $('#fullPageModal .modal-nav.next').prop('disabled', nextIndex >= matchedPages.length - 1);
                        }
                    } else {
                        if (data.is_image) {
                            $('#imageModal').addClass('active').attr('aria-hidden', 'false');
                            $('#fullPageModal').removeClass('active').attr('aria-hidden', 'true');
                            $('#modalImage').attr('src', '');
                            $('#imageCaption').text('Error: ' + data.message);
                            $('#imagePageNumber').text(newPage);
                            $('#imageTotalPages').text(data.total_pages || 1);
                            $('#imageModal .modal-nav.prev').prop('disabled', true);
                            $('#imageModal .modal-nav.next').prop('disabled', true);
                        } else {
                            $('#fullPageModal').addClass('active').attr('aria-hidden', 'false');
                            $('#imageModal').removeClass('active').attr('aria-hidden', 'true');
                            $('#modalContent').html(`<p class="error-message">${data.message}</p>`);
                            $('#modalPageNumber').text(newPage);
                            $('#modalTotalPages').text(data.total_pages || 1);
                            $('#fullPageModal .modal-nav.prev').prop('disabled', true);
                            $('#fullPageModal .modal-nav.next').prop('disabled', true);
                        }
                        notyf.error(data.message || 'Failed to load content');
                    }
                },
                error: function(jqXHR) {
                    $loadingSpinner.addClass('hidden');
                    $('#fullPageModal').addClass('active').attr('aria-hidden', 'false');
                    $('#imageModal').removeClass('active').attr('aria-hidden', 'true');
                    $('#modalContent').html('<p class="error-message">Failed to load content due to a server error</p>');
                    $('#modalPageNumber').text(newPage);
                    $('#modalTotalPages').text(1);
                    $('#fullPageModal .modal-nav.prev').prop('disabled', true);
                    $('#fullPageModal .modal-nav.next').prop('disabled', true);
                    notyf.error(jqXHR.responseJSON?.message || 'Failed to load content due to a server error');
                }
            });
        }
    });

    // Close modals
    $(document).on('click', '.modal-close', function() {
        $('#fullPageModal').removeClass('active').attr('aria-hidden', 'true');
        $('#imageModal').removeClass('active').attr('aria-hidden', 'true');
        $('#modalContent').empty();
        $('#modalImage').attr('src', '');
        $('#fullPageModal').removeData('file-id').removeData('current-page').removeData('total-pages').removeData('matched-pages');
        $('#imageModal').removeData('file-id').removeData('current-page').removeData('total-pages').removeData('matched-pages');
        zoomLevel = 1;
        panX = 0;
        panY = 0;
        rotation = 0;
        updateImageTransform();
    });

// File menu actions - REVISED VERSION
$(document).on('click', '.file-menu button', function() {
    const $fileItem = $(this).closest('.result-item');
    const fileId = $fileItem.data('file-id');
    const action = $(this).attr('class').split(' ')[0];
    const $loadingSpinner = $('.loading-spinner');
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    
    console.log('Button clicked:', action, 'for file_id:', fileId, 'with csrf_token:', csrfToken);
    
    $loadingSpinner.removeClass('hidden');
    $('.file-menu').addClass('hidden').attr('aria-expanded', 'false');
    $('.kebab-menu').attr('aria-expanded', 'false');

    if (action === 'file-info') {
        window.populateFileInfoSidebar(fileId, csrfToken);
        $loadingSpinner.addClass('hidden');
} else if (action === 'request-file') {
    const $button = $(this);
    const fileId = $fileItem.data('file-id');
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    console.log('Request Access clicked: file_id=' + fileId + ', csrf_token=' + csrfToken);
    if (!fileId || !csrfToken) {
        console.error('Missing fileId or csrfToken:', { fileId, csrfToken });
        notyf.error('Cannot send request: Invalid file or session data');
        $loadingSpinner.addClass('hidden');
        return;
    }
    $.ajax({
        url: 'api/file_operations.php',
        method: 'POST',
        data: {
            action: 'request_access',
            file_id: fileId,
            csrf_token: csrfToken
        },
        beforeSend: function() {
            console.log('Sending AJAX request:', {
                action: 'request_access',
                file_id: fileId,
                csrf_token: csrfToken
            });
        },
        success: function(data) {
            $loadingSpinner.addClass('hidden');
            console.log('Request Access response:', data);
            if (data.success) {
                $button.text('Request Pending').prop('disabled', true).addClass('disabled');
                notyf.success(data.message || 'Access request sent successfully');
            } else {
                notyf.error(data.message || 'Failed to send access request');
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            $loadingSpinner.addClass('hidden');
            console.error('Request Access AJAX error:', {
                status: jqXHR.status,
                responseText: jqXHR.responseText,
                textStatus,
                errorThrown
            });
            notyf.error(jqXHR.responseJSON?.message || 'Failed to send access request');
        }
    });
    } else {
        $loadingSpinner.addClass('hidden');
        notyf.error('Action not implemented: ' + action);
    }
});
});