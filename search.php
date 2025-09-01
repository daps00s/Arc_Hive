<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
require 'notification.php';

// Security: Session & CSRF
if (empty($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    error_log("Unauthorized access attempt at " . date('Y-m-d H:i:s'));
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'] ?? '')) {
    error_log("CSRF validation failed for user_id: " . ($_SESSION['user_id'] ?? 'unknown') . " at " . date('Y-m-d H:i:s'));
    header('Location: dashboard.php');
    exit;
}

// Validate user input
$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT) ?: 0;
$userRole = trim($_SESSION['role'] ?? 'client');
$query = trim($_GET['query'] ?? '');
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?: 1;
$resultsPerPage = 10;
$offset = ($page - 1) * $resultsPerPage;

// Validate search query
if (empty($query) || strlen($query) < 2) {
    error_log("Invalid or empty query attempt by user_id: $userId at " . date('Y-m-d H:i:s'));
    header('Location: dashboard.php');
    exit;
}

// Increase GROUP_CONCAT max length
try {
    $pdo->exec("SET SESSION group_concat_max_len = 10000");
} catch (PDOException $e) {
    error_log("Failed to set group_concat_max_len for user_id: $userId at " . date('Y-m-d H:i:s') . ": " . $e->getMessage());
}

// Search files and content (only pages with matched text, centered on search term)
$searchTerm = '%' . $query . '%';
$results = [];
$totalResults = 0;
$totalPages = 0;

try {
    // Count total results for pagination
    $countStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT f.file_id) AS total
        FROM files f
        LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
        LEFT JOIN departments d ON f.department_id = d.department_id
        LEFT JOIN file_pages fp ON f.file_id = fp.file_id
        WHERE (f.user_id = :userId1 OR f.department_id IN (
            SELECT department_id FROM user_department_assignments WHERE user_id = :userId2
        ))
        AND f.file_status = 'completed'
        AND (f.file_name LIKE :searchTerm1 
             OR dt.type_name LIKE :searchTerm2 
             OR fp.extracted_text LIKE :searchTerm3)
    ");
    $countStmt->bindValue('userId1', $userId, PDO::PARAM_INT);
    $countStmt->bindValue('userId2', $userId, PDO::PARAM_INT);
    $countStmt->bindValue('searchTerm1', $searchTerm, PDO::PARAM_STR);
    $countStmt->bindValue('searchTerm2', $searchTerm, PDO::PARAM_STR);
    $countStmt->bindValue('searchTerm3', $searchTerm, PDO::PARAM_STR);
    $countStmt->execute();
    $totalResults = $countStmt->fetchColumn();
    $totalPages = ceil($totalResults / $resultsPerPage);

    // Cap page number
    if ($page > $totalPages && $totalPages > 0) {
        $page = $totalPages;
        $offset = ($page - 1) * $resultsPerPage;
    }

    error_log("Search query '$query' returned $totalResults results for user_id: $userId at " . date('Y-m-d H:i:s'));

    if ($totalResults > 0) {
        // Fetch search results with context around the search term
        $stmt = $pdo->prepare("
            SELECT 
                f.file_id,
                f.file_name,
                f.upload_date,
                f.copy_type,
                f.file_type,
                dt.type_name AS document_type,
                d.department_name,
                (SELECT GROUP_CONCAT(
                    CONCAT(
                        SUBSTRING(
                            fp2.extracted_text,
                            GREATEST(1, LOCATE(:searchTerm4, fp2.extracted_text) - 100),
                            200
                        ),
                        '|||',
                        fp2.page_number
                    )
                    ORDER BY fp2.page_number SEPARATOR '|||'
                )
                FROM file_pages fp2
                WHERE fp2.file_id = f.file_id
                AND fp2.extracted_text LIKE :searchTerm5
                GROUP BY fp2.file_id) AS matched_text
            FROM files f
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            LEFT JOIN departments d ON f.department_id = d.department_id
            WHERE (f.user_id = :userId1 OR f.department_id IN (
                SELECT department_id FROM user_department_assignments WHERE user_id = :userId2
            ))
            AND f.file_status = 'completed'
            AND (f.file_name LIKE :searchTerm1 
                 OR dt.type_name LIKE :searchTerm2 
                 OR EXISTS (
                     SELECT 1 FROM file_pages fp
                     WHERE fp.file_id = f.file_id
                     AND fp.extracted_text LIKE :searchTerm3
                 ))
            GROUP BY f.file_id, f.file_name, f.upload_date, f.copy_type, f.file_type, dt.type_name, d.department_name
            ORDER BY f.upload_date DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue('userId1', $userId, PDO::PARAM_INT);
        $stmt->bindValue('userId2', $userId, PDO::PARAM_INT);
        $stmt->bindValue('searchTerm1', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue('searchTerm2', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue('searchTerm3', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue('searchTerm4', $query, PDO::PARAM_STR); // Exact match for LOCATE
        $stmt->bindValue('searchTerm5', $searchTerm, PDO::PARAM_STR); // Wildcard for LIKE
        $stmt->bindValue('limit', $resultsPerPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Fetched " . count($results) . " results for query '$query' for user_id: $userId at " . date('Y-m-d H:i:s'));
    }
} catch (PDOException $e) {
    error_log("Search query '$query' failed for user_id: $userId at " . date('Y-m-d H:i:s') . ": " . $e->getMessage() . " (SQLSTATE: " . $e->getCode() . ")");
    $errorMessage = "Failed to process search query due to a database error (Error Code: " . $e->getCode() . "). Please check your query or try again later.";
}

// Escape query for safe display
$escapedQuery = htmlspecialchars($query, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <title>Search Results - Arc-Hive</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <link rel="stylesheet" href="style/client-sidebar.css">
    <link rel="stylesheet" href="style/search.css">
</head>

<body>
    <aside class="sidebar" role="navigation" aria-label="Main Navigation">
        <button class="toggle-btn" title="Toggle Sidebar" aria-label="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Document Archival</h2>
        <?php if ($userRole === 'admin'): ?>
            <a href="admin_dashboard.php" class="admin-dashboard-btn" data-tooltip="Admin Dashboard" aria-label="Admin Dashboard">
                <i class="fas fa-user-shield"></i><span class="link-text">Admin Dashboard</span>
            </a>
        <?php endif; ?>
        <a href="dashboard.php" class="active" data-tooltip="Dashboard" aria-label="Dashboard">
            <i class="fas fa-home"></i><span class="link-text">Dashboard</span>
        </a>
        <a href="my-report.php" data-tooltip="My Report" aria-label="My Report">
            <i class="fas fa-chart-bar"></i><span class="link-text">My Report</span>
        </a>
        <a href="folders.php" data-tooltip="My Folder" aria-label="My Folder">
            <i class="fas fa-folder"></i><span class="link-text">My Folder</span>
        </a>
        <a href="logout.php" class="logout-btn" data-tooltip="Logout" aria-label="Logout">
            <i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span>
        </a>
    </aside>
    <div class="main-container">
        <nav class="top-nav">
            <h1>Search Results for "<span class="query-highlight"><?php echo $escapedQuery; ?></span>"</h1>
            <div class="search-container">
                <form id="searchForm" action="search.php" method="GET" aria-label="Search form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="text" id="searchInput" name="query" placeholder="Search files and content..." value="<?php echo $escapedQuery; ?>" aria-label="Search files and content" minlength="2" required autofocus>
                    <button type="submit" class="search-button" aria-label="Search" disabled><i class="fas fa-search"></i></button>
                    <span class="loading-spinner hidden"><i class="fas fa-spinner fa-spin"></i></span>
                </form>
            </div>
        </nav>
        <main class="main-content">
            <section class="search-results" aria-live="polite">
                <?php if (isset($errorMessage)): ?>
                    <div class="error-message" role="alert">
                        <p><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="error-tip">
                            <a href="dashboard.php" aria-label="Return to Dashboard">Return to Dashboard</a> or
                            <a href="#" id="retrySearch" aria-label="Retry search">try again</a>.
                        </p>
                    </div>
                <?php elseif (empty($results)): ?>
                    <div class="no-results" role="alert">
                        <p>No results found for "<span class="query-highlight"><?php echo $escapedQuery; ?></span>"</p>
                        <p class="no-results-tip">Try checking your spelling, using synonyms, or broader terms. Only files with completed OCR processing are searchable.</p>
                    </div>
                <?php else: ?>
                    <div class="results-grid">
                        <?php foreach ($results as $result): ?>
                            <article class="result-item" data-file-id="<?php echo htmlspecialchars($result['file_id'], ENT_QUOTES, 'UTF-8'); ?>" role="region" aria-label="Search result for <?php echo htmlspecialchars($result['file_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="result-header">
                                    <h3><?php echo htmlspecialchars($result['file_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <button class="kebab-menu" aria-label="File options" aria-expanded="false"><i class="fas fa-ellipsis-v"></i></button>
                                    <div class="file-menu hidden" role="menu">
                                        <button class="download-file" role="menuitem">Download</button>
                                        <button class="rename-file" role="menuitem">Rename</button>
                                        <button class="delete-file" role="menuitem">Delete</button>
                                        <button class="share-file" role="menuitem">Share</button>
                                        <button class="file-info" role="menuitem">File Info</button>
                                    </div>
                                </div>
                                <p class="result-meta">
                                    Type: <?php echo htmlspecialchars($result['document_type'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?> |
                                    Uploaded: <?php echo date('M d, Y', strtotime($result['upload_date'])); ?> |
                                    Dept: <?php echo htmlspecialchars($result['department_name'] ?? 'None', ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <?php if (!empty($result['matched_text'])): ?>
                                    <div class="content-matches">
                                        <h4>Content Matches:</h4>
                                        <?php
                                        $matches = array_filter(explode('|||', $result['matched_text'] ?? ''), 'trim');
                                        $matchCount = count($matches) / 2;
                                        for ($i = 0; $i < $matchCount; $i++):
                                            $text = $matches[$i * 2];
                                            $page = $matches[$i * 2 + 1];
                                            $text = mb_strimwidth($text, 0, 200, '...');
                                            $highlightedText = preg_replace(
                                                "/(" . preg_quote($query, '/') . ")/i",
                                                '<span class="highlight">$1</span>',
                                                htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
                                            );
                                        ?>
                                            <div class="match-item <?php echo $i === 0 ? 'active' : ''; ?>" data-index="<?php echo $i; ?>" data-page-number="<?php echo htmlspecialchars($page, ENT_QUOTES, 'UTF-8'); ?>" data-file-id="<?php echo htmlspecialchars($result['file_id'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="<?php echo $i === 0 ? 'false' : 'true'; ?>">
                                                <p><strong><?php echo htmlspecialchars(is_numeric($page) ? "Page $page" : $page, ENT_QUOTES, 'UTF-8'); ?>:</strong> <span class="match-text"><?php echo $highlightedText; ?></span></p>
                                                <button class="view-full-page" aria-label="View full text for page <?php echo htmlspecialchars($page, ENT_QUOTES, 'UTF-8'); ?>">View Full Page</button>
                                            </div>
                                        <?php endfor; ?>
                                        <?php if ($matchCount > 1): ?>
                                            <div class="pagination-controls" role="navigation" aria-label="Match navigation">
                                                <button class="prev-match" <?php echo $matchCount <= 1 ? 'disabled' : ''; ?> aria-label="Previous match">Previous</button>
                                                <span class="match-counter" aria-live="polite">1 of <?php echo $matchCount; ?></span>
                                                <button class="next-match" <?php echo $matchCount <= 1 ? 'disabled' : ''; ?> aria-label="Next match">Next</button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <nav class="pagination" role="navigation" aria-label="Pagination">
                            <?php if ($page > 1): ?>
                                <a href="search.php?query=<?php echo urlencode($query); ?>&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>&page=<?php echo $page - 1; ?>" class="page-link" aria-label="Previous page">Previous</a>
                            <?php endif; ?>
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <a href="search.php?query=<?php echo urlencode($query); ?>&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>&page=<?php echo $i; ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>" aria-current="<?php echo $i == $page ? 'page' : 'false'; ?>" aria-label="Page <?php echo $i; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="search.php?query=<?php echo urlencode($query); ?>&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>&page=<?php echo $page + 1; ?>" class="page-link" aria-label="Next page">Next</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </main>
        <?php include 'templates/file_info_sidebar.php'; ?>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <script>
        const notyf = new Notyf({
            duration: 4000,
            position: {
                x: 'right',
                y: 'top'
            },
            ripple: true
        });

        $(document).ready(function() {
            const csrfToken = $('meta[name="csrf-token"]').attr('content');
            const $searchForm = $('#searchForm');
            const $searchInput = $('#searchInput');
            const $searchButton = $('.search-button');
            const $loadingSpinner = $('.loading-spinner');
            const searchQuery = "<?php echo addslashes($query); ?>";

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
                    window.location.href = `search.php?query=${encodeURIComponent(query)}&csrf_token=${encodeURIComponent(csrfToken)}`;
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

            // View full page text
            $(document).on('click', '.view-full-page', function() {
                const $matchItem = $(this).closest('.match-item');
                const fileId = $matchItem.data('file-id');
                const pageNumber = $matchItem.data('page-number');
                $loadingSpinner.removeClass('hidden');
                $.ajax({
                    url: 'api/file_operations.php',
                    method: 'POST',
                    data: {
                        action: 'fetch_page_text',
                        file_id: fileId,
                        page_number: pageNumber,
                        csrf_token: csrfToken
                    },
                    success: function(data) {
                        $loadingSpinner.addClass('hidden');
                        if (data.success) {
                            const $matchText = $matchItem.find('.match-text');
                            const highlightedText = data.text.replace(
                                new RegExp(`(${searchQuery})`, 'gi'),
                                '<span class="highlight">$1</span>'
                            );
                            $matchText.html(highlightedText);
                            $matchItem.find('.view-full-page').remove();
                            $matchItem.addClass('expanded');
                            notyf.success('Full page text loaded');
                        } else {
                            notyf.error(data.message || 'Failed to load full page text');
                        }
                    },
                    error: function() {
                        $loadingSpinner.addClass('hidden');
                        notyf.error('Failed to load full page text due to a server error');
                    }
                });
            });

            // File menu actions
            $(document).on('click', '.file-menu button', function(e) {
                e.stopPropagation();
                const $fileItem = $(this).closest('.result-item');
                const fileId = $fileItem.data('file-id');
                const action = $(this).attr('class');
                $loadingSpinner.removeClass('hidden');

                if (action === 'file-info') {
                    $.ajax({
                        url: 'api/file_operations.php',
                        method: 'POST',
                        data: {
                            action: 'fetch_file_info',
                            file_id: fileId,
                            csrf_token: csrfToken
                        },
                        success: function(data) {
                            $loadingSpinner.addClass('hidden');
                            if (data.success) {
                                const $detailsTab = $('#detailsTab').empty();
                                $detailsTab.append(`<div class="file-info-section"><strong>Access:</strong> ${data.file.access_level || 'Unknown'}</div>`);
                                $detailsTab.append(`<div class="file-info-section"><strong>QR Code:</strong> ${data.file.qr_path ? 'Available' : 'Not Available'}</div>`);
                                $detailsTab.append(`<div class="file-info-section"><strong>File Type:</strong> ${data.file.file_type || data.file.document_type || 'Unknown'}</div>`);
                                $detailsTab.append(`<div class="file-info-section"><strong>File Size:</strong> ${(data.file.file_size ? (data.file.file_size / 1024).toFixed(2) + ' KB' : 'N/A')}</div>`);
                                $detailsTab.append(`<div class="file-info-section"><strong>Category:</strong> ${data.file.document_type || 'Unknown'}</div>`);
                                $detailsTab.append(`<div class="file-info-section"><strong>Uploader:</strong> ${data.file.uploader_name || 'Unknown'}</div>`);
                                $detailsTab.append(`<div class="file-info-section"><strong>Upload Date:</strong> ${data.file.upload_date ? new Date(data.file.upload_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Unknown'}</div>`);
                                $detailsTab.append(`<div class="file-info-section"><strong>Physical Location:</strong> ${data.file.physical_location || 'None'}</div>`);
                                $detailsTab.append(`<div class="file-info-section"><strong>Document Type:</strong> ${data.file.document_type || 'Unknown'}</div>`);
                                if (data.formatted_fields && data.formatted_fields.length > 0) {
                                    let fieldsHtml = '<div class="file-info-section"><strong>Fields:</strong><ul class="fields-list">';
                                    data.formatted_fields.forEach(field => {
                                        fieldsHtml += `<li>${field.key}: ${field.value}</li>`;
                                    });
                                    fieldsHtml += '</ul></div>';
                                    $detailsTab.append(fieldsHtml);
                                } else {
                                    $detailsTab.append('<div class="file-info-section"><strong>Fields:</strong> None</div>');
                                }
                                $('#fileSentTo').text(data.activity.sent_to ? data.activity.sent_to.join(', ') : 'None');
                                $('#fileReceivedBy').text(data.activity.received_by ? data.activity.received_by.join(', ') : 'None');
                                $('#fileCopiedBy').text(data.activity.copied_by ? data.activity.copied_by.join(', ') : 'None');
                                $('#fileRenamedTo').text(data.activity.renamed_to || 'None');
                                const $preview = $('#filePreview').empty();
                                if (data.file.file_type && data.file.file_type.startsWith('image/')) {
                                    $preview.append(`<img src="${data.file.file_path}" alt="Preview of ${data.file.file_name}" style="max-width: 100%; max-height: 200px;">`);
                                } else if (data.file.file_type === 'application/pdf') {
                                    $preview.append('<i class="fas fa-file-pdf fa-3x"></i><p>PDF Preview Not Available</p>');
                                } else {
                                    $preview.append('<i class="fas fa-file fa-3x"></i><p>No Preview Available</p>');
                                }
                                $('#fileInfoSidebar')
                                    .removeClass('hidden')
                                    .attr('aria-hidden', 'false')
                                    .css({
                                        right: '-400px'
                                    })
                                    .animate({
                                        right: '0'
                                    }, 300);
                            } else {
                                notyf.error(data.message || 'Failed to load file information');
                            }
                        },
                        error: function(xhr, status, error) {
                            $loadingSpinner.addClass('hidden');
                            notyf.error('Failed to load file information due to a server error');
                        }
                    });
                }
                $('.file-menu').addClass('hidden').attr('aria-expanded', 'false');
                $('.kebab-menu').attr('aria-expanded', 'false');
            });



            // Keyboard navigation
            $(document).on('keydown', '.kebab-menu', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $(this).trigger('click');
                }
            });

            $(document).on('keydown', '.file-menu button, .view-full-page', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $(this).trigger('click');
                }
            });
        });
    </script>
</body>

</html>