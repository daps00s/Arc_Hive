<?php
// search.php
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

// Increase GROUP_CONCAT max length for content matches
try {
    $pdo->exec("SET SESSION group_concat_max_len = 10000");
} catch (PDOException $e) {
    error_log("Failed to set group_concat_max_len for user_id: $userId at " . date('Y-m-d H:i:s') . ": " . $e->getMessage());
}

// Search files and content
$searchTerm = '%' . $query . '%';
$results = [];
$totalResults = 0;
$totalPages = 0;

try {
    // Fetch user's departments for ownership check
    $deptStmt = $pdo->prepare("SELECT department_id FROM user_department_assignments WHERE user_id = ?");
    $deptStmt->execute([$userId]);
    $userDepts = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

    // Count total results for pagination
    $countStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT f.file_id) AS total
        FROM files f
        LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
        LEFT JOIN departments d ON f.department_id = d.department_id
        LEFT JOIN file_pages fp ON f.file_id = fp.file_id
        WHERE f.file_status = 'completed'
        AND (
            f.file_name LIKE :searchTerm1 
            OR dt.type_name LIKE :searchTerm2 
            OR (
                (f.user_id = :userId1 OR f.department_id IN (
                    SELECT department_id FROM user_department_assignments WHERE user_id = :userId2
                ) OR f.access_level = 'public')
                AND EXISTS (
                    SELECT 1 FROM file_pages fp
                    WHERE fp.file_id = f.file_id
                    AND fp.extracted_text LIKE :searchTerm3
                )
            )
        )
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
        // Fetch search results with context
        $stmt = $pdo->prepare("
            SELECT 
                f.file_id,
                f.file_name,
                f.upload_date,
                f.copy_type,
                f.file_type,
                dt.type_name AS document_type,
                d.department_name,
                f.user_id AS owner_id,
                f.access_level,
                f.department_id,
                (SELECT CASE WHEN (f.user_id = :userId3 OR f.department_id IN (
                    SELECT department_id FROM user_department_assignments WHERE user_id = :userId4
                ) OR f.access_level = 'public') THEN GROUP_CONCAT(
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
                ) ELSE NULL END
                FROM file_pages fp2
                WHERE fp2.file_id = f.file_id
                AND fp2.extracted_text LIKE :searchTerm5
                GROUP BY fp2.file_id) AS matched_text
            FROM files f
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            LEFT JOIN departments d ON f.department_id = d.department_id
            WHERE f.file_status = 'completed'
            AND (
                f.file_name LIKE :searchTerm1 
                OR dt.type_name LIKE :searchTerm2 
                OR (
                    (f.user_id = :userId1 OR f.department_id IN (
                        SELECT department_id FROM user_department_assignments WHERE user_id = :userId2
                    ) OR f.access_level = 'public')
                    AND EXISTS (
                        SELECT 1 FROM file_pages fp
                        WHERE fp.file_id = f.file_id
                        AND fp.extracted_text LIKE :searchTerm3
                    )
                )
            )
            ORDER BY 
                CASE 
                    WHEN f.file_name LIKE :searchTerm6 THEN 1 
                    WHEN dt.type_name LIKE :searchTerm7 THEN 2 
                    ELSE 3 
                END ASC,
                f.upload_date DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue('userId1', $userId, PDO::PARAM_INT);
        $stmt->bindValue('userId2', $userId, PDO::PARAM_INT);
        $stmt->bindValue('userId3', $userId, PDO::PARAM_INT);
        $stmt->bindValue('userId4', $userId, PDO::PARAM_INT);
        $stmt->bindValue('searchTerm1', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue('searchTerm2', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue('searchTerm3', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue('searchTerm4', $query, PDO::PARAM_STR);
        $stmt->bindValue('searchTerm5', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue('searchTerm6', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue('searchTerm7', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue('limit', $resultsPerPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Fetched " . count($results) . " results for query '$query' for user_id: $userId at " . date('Y-m-d H:i:s'));

        $content_matches = [];
        $name_matches = [];
        $non_owned_matches = [];

        foreach ($results as $result) {
            $isOwned = ($result['owner_id'] == $userId || $result['access_level'] == 'public' || in_array($result['department_id'], $userDepts));
            if (!$isOwned) {
                $non_owned_matches[] = $result;
            } elseif (!empty($result['matched_text'])) {
                $content_matches[] = $result;
            } else {
                $name_matches[] = $result;
            }
        }
    }
} catch (PDOException $e) {
    error_log("Search query '$query' failed for user_id: $userId at " . date('Y-m-d H:i:s') . ": " . $e->getMessage() . " (SQLSTATE: " . $e->getCode() . ")");
    $errorMessage = "Failed to process search query due to a database error. Please try again later.";
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
    <link rel="stylesheet" href="style/file_info_sidebar.css">
    <link rel="stylesheet" href="style/search.css">
    <style>
        .non-owned-files-section {
            border: 2px solid #ff9800;
            background-color: #fff8e1;
            padding: 15px;
            margin-top: 20px;
            border-radius: 8px;
        }

        .non-owned-files-section h2 {
            color: #ff9800;
            font-size: 1.5em;
            margin-bottom: 15px;
        }

        .non-owned-files-section .result-item {
            background-color: #fff3cd;
            border-left: 4px solid #ff9800;
        }
    </style>
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
        <a href="dashboard.php" class="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '') ?>" data-tooltip="Dashboard" aria-label="Dashboard">
            <i class="fas fa-home"></i><span class="link-text">Dashboard</span>
        </a>
        <a href="my-report.php" data-tooltip="My Report" aria-label="My Report">
            <i class="fas fa-chart-bar"></i><span class="link-text">My Report</span>
        </a>
        <a href="folders.php" class="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']) === 'my-folder.php' ? 'active' : '') ?>" data-tooltip="My Folder" aria-label="My Folder">
            <i class="fas fa-folder"></i><span class="link-text">My Folder</span>
        </a>
        <a href="logout.php" class="logout-btn" data-tooltip="Logout" aria-label="Logout">
            <i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span>
        </a>
    </aside>
    <div class="main-container">
        <nav class="top-nav" role="navigation" aria-label="Top Navigation">
            <h1>Search Results for <span class="query-highlight"><?= $escapedQuery ?></span></h1>
            <div class="search-container">
                <form id="searchForm" action="search.php" method="GET">
                    <input type="text" id="searchInput" name="query" value="<?= $escapedQuery ?>" placeholder="Search documents..." aria-label="Search documents">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="search-button" aria-label="Search"><i class="fas fa-search"></i></button>
                </form>
                <i class="fas fa-spinner fa-spin loading-spinner hidden" aria-hidden="true"></i>
            </div>
        </nav>
        <main class="search-results" role="main">
            <section aria-live="polite">
                <?php if (isset($errorMessage)): ?>
                    <div class="error-message" role="alert">
                        <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                        <p class="error-tip">Try <a href="#" id="retrySearch">searching again</a> or contact support.</p>
                    </div>
                <?php elseif (empty($results)): ?>
                    <div class="no-results">
                        No results found for "<?= $escapedQuery ?>"
                        <p class="no-results-tip">Try a different query or <a href="dashboard.php">return to dashboard</a>.</p>
                    </div>
                <?php else: ?>
                    <div class="results-container">
                        <?php if (!empty($content_matches)): ?>
                            <section class="content-matches-section">
                                <h2>Files with Matching Content (Your Files/Department)</h2>
                                <div class="results-grid">
                                    <?php foreach ($content_matches as $result): ?>
                                        <article class="result-item"
                                            data-file-id="<?= htmlspecialchars($result['file_id'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-owner-id="<?= htmlspecialchars($result['owner_id'], ENT_QUOTES, 'UTF-8') ?>"
                                            role="region"
                                            aria-label="Search result for <?= htmlspecialchars($result['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="result-header">
                                                <h3><?= htmlspecialchars($result['file_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                                <button class="kebab-menu" aria-label="File options" aria-expanded="false">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <div class="file-menu hidden" role="menu">
                                                    <?php if ($result['owner_id'] == $userId || $result['access_level'] == 'public' || in_array($result['department_id'], $userDepts)): ?>
                                                        <button class="download-file" role="menuitem">Download</button>
                                                        <button class="rename-file" role="menuitem">Rename</button>
                                                        <button class="delete-file" role="menuitem">Delete</button>
                                                        <button class="share-file" role="menuitem">Share</button>
                                                        <button class="file-info" role="menuitem">File Info</button>
                                                    <?php else: ?>
                                                        <button class="request-file" role="menuitem">Request Access</button>
                                                        <button class="file-info" role="menuitem">File Info</button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <p class="result-meta">
                                                Type: <?= htmlspecialchars($result['document_type'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?> |
                                                Uploaded: <?= date('M d, Y', strtotime($result['upload_date'])) ?> |
                                                Dept: <?= htmlspecialchars($result['department_name'] ?? 'None', ENT_QUOTES, 'UTF-8') ?>
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
                                                        <div class="match-item <?= $i === 0 ? 'active' : '' ?>"
                                                            data-index="<?= $i ?>"
                                                            data-page-number="<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>"
                                                            data-file-id="<?= htmlspecialchars($result['file_id'], ENT_QUOTES, 'UTF-8') ?>"
                                                            aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>">
                                                            <p><strong><?= htmlspecialchars(is_numeric($page) ? "Page $page" : $page, ENT_QUOTES, 'UTF-8') ?>:</strong>
                                                                <span class="match-text"><?= $highlightedText ?></span>
                                                            </p>
                                                            <button class="view-full-page" aria-label="View full content for page <?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>">View Full Page</button>
                                                        </div>
                                                    <?php endfor; ?>
                                                    <?php if ($matchCount > 1): ?>
                                                        <div class="pagination-controls" role="navigation" aria-label="Match navigation">
                                                            <button class="prev-match" <?= $matchCount <= 1 ? 'disabled' : '' ?> aria-label="Previous match">Previous</button>
                                                            <span class="match-counter" aria-live="polite">1 of <?= $matchCount ?></span>
                                                            <button class="next-match" <?= $matchCount <= 1 ? 'disabled' : '' ?> aria-label="Next match">Next</button>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endif; ?>
                        <?php if (!empty($name_matches)): ?>
                            <section class="name-matches-section">
                                <h2>Files Matching by Name or Type (Your Files/Department)</h2>
                                <div class="results-grid">
                                    <?php foreach ($name_matches as $result): ?>
                                        <article class="result-item"
                                            data-file-id="<?= htmlspecialchars($result['file_id'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-owner-id="<?= htmlspecialchars($result['owner_id'], ENT_QUOTES, 'UTF-8') ?>"
                                            role="region"
                                            aria-label="Search result for <?= htmlspecialchars($result['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="result-header">
                                                <h3><?= htmlspecialchars($result['file_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                                <button class="kebab-menu" aria-label="File options" aria-expanded="false">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <div class="file-menu hidden" role="menu">
                                                    <?php if ($result['owner_id'] == $userId || $result['access_level'] == 'public' || in_array($result['department_id'], $userDepts)): ?>
                                                        <button class="download-file" role="menuitem">Download</button>
                                                        <button class="rename-file" role="menuitem">Rename</button>
                                                        <button class="delete-file" role="menuitem">Delete</button>
                                                        <button class="share-file" role="menuitem">Share</button>
                                                        <button class="file-info" role="menuitem">File Info</button>
                                                    <?php else: ?>
                                                        <button class="request-file" role="menuitem">Request Access</button>
                                                        <button class="file-info" role="menuitem">File Info</button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <p class="result-meta">
                                                Type: <?= htmlspecialchars($result['document_type'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?> |
                                                Uploaded: <?= date('M d, Y', strtotime($result['upload_date'])) ?> |
                                                Dept: <?= htmlspecialchars($result['department_name'] ?? 'None', ENT_QUOTES, 'UTF-8') ?>
                                            </p>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endif; ?>
                        <?php if (!empty($non_owned_matches)): ?>
                            <section class="non-owned-files-section">
                                <h2>Other Department Files (Name/Type Matches Only)</h2>
                                <div class="results-grid">
                                    <?php foreach ($non_owned_matches as $result): ?>
                                        <article class="result-item non-owned-file"
                                            data-file-id="<?= htmlspecialchars($result['file_id'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-owner-id="<?= htmlspecialchars($result['owner_id'], ENT_QUOTES, 'UTF-8') ?>"
                                            role="region"
                                            aria-label="Search result for restricted file <?= htmlspecialchars($result['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="result-header">
                                                <h3><?= htmlspecialchars($result['file_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                                <button class="kebab-menu" aria-label="File options" aria-expanded="false">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <div class="file-menu hidden" role="menu">
                                                    <button class="request-file" role="menuitem">Request Access</button>
                                                    <button class="file-info" role="menuitem">File Info</button>
                                                </div>
                                            </div>
                                            <p class="result-meta">
                                                Type: <?= htmlspecialchars($result['document_type'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?> |
                                                Uploaded: <?= date('M d, Y', strtotime($result['upload_date'])) ?> |
                                                Dept: <?= htmlspecialchars($result['department_name'] ?? 'None', ENT_QUOTES, 'UTF-8') ?>
                                            </p>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endif; ?>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <nav class="pagination" role="navigation" aria-label="Pagination">
                            <?php if ($page > 1): ?>
                                <a href="?query=<?= urlencode($query) ?>&csrf_token=<?= urlencode($_SESSION['csrf_token']) ?>&page=<?= $page - 1 ?>" class="page-link" aria-label="Previous page">Previous</a>
                            <?php endif; ?>
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            if ($endPage - $startPage < 4) {
                                $startPage = max(1, $endPage - 4);
                            }
                            for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <a href="?query=<?= urlencode($query) ?>&csrf_token=<?= urlencode($_SESSION['csrf_token']) ?>&page=<?= $i ?>"
                                    class="page-link <?= $i == $page ? 'active' : '' ?>"
                                    aria-current="<?= $i == $page ? 'page' : 'false' ?>"
                                    aria-label="Page <?= $i ?>"><?= $i ?></a>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="?query=<?= urlencode($query) ?>&csrf_token=<?= urlencode($_SESSION['csrf_token']) ?>&page=<?= $page + 1 ?>" class="page-link" aria-label="Next page">Next</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </main>
        <?php include 'templates/file_info_sidebar.php'; ?>
        <!-- Text Modal -->
        <div id="fullPageModal" class="modal text-modal" aria-hidden="true">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Page Content</h2>
                    <button class="modal-close" aria-label="Close modal"><i class="fas fa-times"></i></button>
                </div>
                <div class="modal-body">
                    <div class="page-container">
                        <div id="modalContent" class="page-content"></div>
                    </div>
                    <div class="modal-footer">
                        <button class="modal-nav prev" aria-label="Previous matched page" disabled><i class="fas fa-chevron-left"></i> Previous</button>
                        <span class="page-counter" aria-live="polite">
                            Page <span id="modalPageNumber">1</span> of <span id="modalTotalPages">1</span>
                        </span>
                        <button class="modal-nav next" aria-label="Next matched page"><i class="fas fa-chevron-right"></i> Next</button>
                    </div>
                </div>
            </div>
        </div>
        <!-- Image Modal -->
        <div id="imageModal" class="modal image-modal" aria-hidden="true">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Image Viewer</h2>
                    <div class="image-controls">
                        <button class="image-control zoom-in" aria-label="Zoom in"><i class="fas fa-search-plus"></i></button>
                        <button class="image-control zoom-out" aria-label="Zoom out"><i class="fas fa-search-minus"></i></button>
                        <button class="image-control rotate-left" aria-label="Rotate left"><i class="fas fa-undo"></i></button>
                        <button class="image-control rotate-right" aria-label="Rotate right"><i class="fas fa-redo"></i></button>
                        <button class="image-control reset" aria-label="Reset view"><i class="fas fa-sync-alt"></i></button>
                    </div>
                    <button class="modal-close" aria-label="Close image modal"><i class="fas fa-times"></i></button>
                </div>
                <div class="modal-body">
                    <div class="image-container">
                        <img id="modalImage" src="" alt="Document image" class="image-content" aria-describedby="imageCaption">
                        <div id="imageCaption" class="image-caption" aria-live="polite"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="modal-nav prev" aria-label="Previous image" disabled><i class="fas fa-chevron-left"></i> Previous</button>
                    <span class="page-counter" aria-live="polite">
                        Image <span id="imagePageNumber">1</span> of <span id="imageTotalPages">1</span>
                    </span>
                    <button class="modal-nav next" aria-label="Next image"><i class="fas fa-chevron-right"></i> Next</button>
                </div>
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <script src="script/search.js"></script>
</body>

</html>