<?php
session_start();
ob_start();

// Required dependencies
$requiredFiles = ['db_connection.php', 'log_activity.php', 'notification.php', 'vendor/autoload.php', 'phpqrcode/qrlib.php'];
foreach ($requiredFiles as $file) {
    if (!file_exists($file)) {
        error_log("Missing required file: $file");
        http_response_code(500);
        ob_end_clean();
        exit("<html><body><h1>Server Error</h1><p>Missing critical dependency.</p></body></html>");
    }
    require_once $file;
}

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__, ['.env']);
$dotenv->safeLoad();

// Configure error handling
ini_set('display_errors', 0);
ini_set('error_log', __DIR__ . '/logs/error_log.log');
error_reporting(E_ALL);

// Cache configuration
$cacheDir = __DIR__ . '/cache';
if (!file_exists($cacheDir)) mkdir($cacheDir, 0777, true);
$cacheTTL = (int)($_ENV['CACHE_TTL'] ?? 300);

function sendJsonResponse(bool $success, string $message, array $data, int $statusCode): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    echo json_encode(['success' => $success, 'message' => $message] + $data);
    exit;
}

function cacheStore(string $key, $value, int $ttl): bool
{
    global $cacheDir;
    $filename = "$cacheDir/" . md5($key) . '.cache';
    $data = serialize(['data' => $value, 'expires' => time() + $ttl]);
    return file_put_contents($filename, $data, LOCK_EX) !== false;
}

function cacheFetch(string $key)
{
    global $cacheDir;
    $filename = "$cacheDir/" . md5($key) . '.cache';
    if (file_exists($filename)) {
        $content = unserialize(file_get_contents($filename));
        if ($content['expires'] > time()) return $content['data'];
        unlink($filename);
    }
    return false;
}

function cacheExists(string $key): bool
{
    global $cacheDir;
    $filename = "$cacheDir/" . md5($key) . '.cache';
    if (file_exists($filename)) {
        $content = unserialize(file_get_contents($filename));
        if ($content['expires'] > time()) return true;
        unlink($filename);
    }
    return false;
}

function fetchUserDepartmentsWithSub(PDO $pdo, int $userId): array
{
    $cacheKey = "departments_user_$userId";
    if (cacheExists($cacheKey)) return cacheFetch($cacheKey);
    try {
        $stmt = $pdo->prepare("
            WITH RECURSIVE dept_hierarchy AS (
                SELECT d.department_id, d.department_name, d.parent_department_id, ud.users_department_id
                FROM departments d 
                JOIN user_department_assignments ud ON d.department_id = ud.department_id
                WHERE ud.user_id = :user_id
                UNION ALL
                SELECT d.department_id, d.department_name, d.parent_department_id, ud.users_department_id
                FROM departments d 
                JOIN dept_hierarchy dh ON d.parent_department_id = dh.department_id
                JOIN user_department_assignments ud ON d.department_id = ud.department_id
            )
            SELECT DISTINCT department_id AS id, department_name AS name, parent_department_id AS parent_id
            FROM dept_hierarchy 
            ORDER BY department_name
        ");
        $stmt->execute(['user_id' => $userId]);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        cacheStore($cacheKey, $departments, 300);
        return $departments;
    } catch (Exception $e) {
        error_log("Error fetching departments for user $userId: " . $e->getMessage());
        return [];
    }
}

// Validate session
if (empty($_SESSION['user_id'])) {
    error_log("Unauthorized access: user_id not set in session");
    http_response_code(401);
    ob_end_clean();
    exit("<html><body><h1>Unauthorized</h1><p>Please log in to access this page.</p></body></html>");
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
$userRole = $_SESSION['role'] ?? 'user'; // Default to 'user' if role is not set
$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;

// Fetch document types
try {
    $stmt = $pdo->prepare("SELECT document_type_id, type_name AS name FROM document_types WHERE is_active = 1 ORDER BY type_name");
    $stmt->execute();
    $docTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching document types: " . $e->getMessage());
    $docTypes = [];
}

// Fetch user departments
$departments = fetchUserDepartmentsWithSub($pdo, $userId);

// Fetch user's uploaded files for personal section
try {
    $stmt = $pdo->prepare("
        SELECT f.file_id, f.file_name, f.upload_date, f.copy_type, dt.type_name AS document_type, f.file_type, f.file_size, d.department_name
        FROM files f
        LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
        LEFT JOIN departments d ON f.department_id = d.department_id
        WHERE f.user_id = ? AND f.file_status != 'deleted'
        ORDER BY f.upload_date DESC
        LIMIT 20
    ");
    $stmt->execute([$userId]);
    $uploadedFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching uploaded files: " . $e->getMessage());
    $uploadedFiles = [];
}

// Fetch received files (files sent to user)
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.file_id, f.file_name, f.upload_date, f.copy_type, dt.type_name AS document_type, f.file_type, f.file_size,
                       u.username AS sender_username, d.department_name
        FROM files f
        JOIN transactions t ON f.file_id = t.file_id
        LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
        LEFT JOIN users u ON f.user_id = u.user_id
        LEFT JOIN departments d ON f.department_id = d.department_id
        WHERE t.user_id = ? AND t.transaction_type = 'file_sent' AND t.transaction_status IN ('pending', 'accepted')
        ORDER BY f.upload_date DESC
        LIMIT 20
    ");
    $stmt->execute([$userId]);
    $receivedFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching received files: " . $e->getMessage());
    $receivedFiles = [];
}

// Fetch shared files (files shared by user)
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.file_id, f.file_name, f.upload_date, f.copy_type, dt.type_name AS document_type, f.file_type, f.file_size,
                       u.username AS recipient_username, d.department_name
        FROM files f
        JOIN transactions t ON f.file_id = t.file_id
        LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
        LEFT JOIN users u ON t.user_id = u.user_id
        LEFT JOIN departments d ON f.department_id = d.department_id
        WHERE f.user_id = ? AND t.transaction_type = 'file_sent' AND t.transaction_status = 'accepted'
        ORDER BY f.upload_date DESC
        LIMIT 20
    ");
    $stmt->execute([$userId]);
    $sharedFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching shared files: " . $e->getMessage());
    $sharedFiles = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>File Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="style/client-sidebar.css">
    <link rel="stylesheet" href="style/folder-page.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
        <a href="folders.php" class="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']) === 'folders.php' ? 'active' : '') ?>" data-tooltip="My Folder" aria-label="My Folder">
            <i class="fas fa-folder"></i><span class="link-text">My Folder</span>
        </a>
        <a href="logout.php" class="logout-btn" data-tooltip="Logout" aria-label="Logout">
            <i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span>
        </a>
    </aside>
    <div class="top-nav">
        <h2>File Management</h2>
        <div class="user-info">
            <img src="path/to/avatar.jpg" alt="User avatar">
            <span><?= htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>
    <div class="main-content">
        <div class="action-buttons">
            <button id="uploadButton" onclick="openModal('uploadFileModal')" aria-label="Upload a new file">Upload</button>
        </div>
        <div class="view-tabs" role="tablist">
            <button class="view-tab active" data-view="personal" role="tab" aria-selected="true" aria-controls="personalFilesSection">Personal</button>
            <button class="view-tab" data-view="department" role="tab" aria-selected="false" aria-controls="departmentFilesSection">Department</button>
        </div>
        <div id="personalFilesSection" class="files-section active" aria-hidden="false">
            <div class="tab-buttons" role="tablist">
                <button class="tab-button active" data-tab="uploaded" role="tab" aria-selected="true" aria-controls="uploadedTab">Uploaded</button>
                <button class="tab-button" data-tab="received" role="tab" aria-selected="false" aria-controls="receivedTab">Received</button>
                <button class="tab-button" data-tab="shared" role="tab" aria-selected="false" aria-controls="sharedTab">Shared</button>
            </div>
            <div class="filters" data-section="personal">
                <input type="text" id="personalSearchBar" placeholder="Search personal files..." aria-label="Search personal files">
                <select id="personalFileSort" aria-label="Sort personal files">
                    <option value="date-desc">Date (Newest)</option>
                    <option value="date-asc">Date (Oldest)</option>
                    <option value="name-asc">Name (A-Z)</option>
                    <option value="name-desc">Name (Z-A)</option>
                    <option value="size-asc">Size (Smallest)</option>
                    <option value="size-desc">Size (Largest)</option>
                </select>
                <select id="personalFilterType" aria-label="Filter personal files by type">
                    <option value="">All Types</option>
                    <?php foreach ($docTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type['document_type_id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" id="personalFilterDateFrom" aria-label="Filter personal files by date from">
                <input type="date" id="personalFilterDateTo" aria-label="Filter personal files by date to">
                <select id="personalFilterAccess" aria-label="Filter personal files by access">
                    <option value="">All Access</option>
                    <option value="owner">Owner</option>
                    <option value="shared">Shared</option>
                </select>
                <button id="personalApplyFilters" aria-label="Apply personal filters">Apply Filters</button>
                <button id="personalResetFilters" aria-label="Reset personal filters">Reset</button>
            </div>
            <div class="view-toggle">
                <button class="toggle-button active" data-view="grid" aria-label="Grid view"><i class="fas fa-th"></i></button>
                <button class="toggle-button" data-view="list" aria-label="List view"><i class="fas fa-list"></i></button>
            </div>
            <div id="uploadedTab" class="tab-content active files-grid grid-view" role="tabpanel" aria-labelledby="uploaded-tab">
                <?php if (empty($uploadedFiles)): ?>
                    <div class="no-files-message">
                        <i class="fas fa-folder-open"></i>
                        <p>No uploaded files found. <a href="#" onclick="openModal('uploadFileModal')">Upload your first file</a>.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($uploadedFiles as $file): ?>
                        <div class="file-item" data-file-id="<?= htmlspecialchars($file['file_id'], ENT_QUOTES, 'UTF-8') ?>" tabindex="0" role="button" aria-label="File: <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="file-icon">
                                <i class="fas fa-file-<?= strtolower(str_replace(['pdf', 'doc', 'xls'], ['pdf', 'word', 'chart-bar'], $file['file_type'] ?? 'alt')) ?>" aria-hidden="true"></i>
                            </div>
                            <div class="file-name"><?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="file-meta">
                                <span><?= htmlspecialchars($file['document_type'] ?? 'Unknown Type', ENT_QUOTES, 'UTF-8') ?></span>
                                <span><?= date('M d, Y', strtotime($file['upload_date'])) ?></span>
                                <span><?= number_format($file['file_size'] ?? 0 / 1024, 1) ?> KB</span>
                            </div>
                            <button class="kebab-menu" aria-label="More options for <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>" aria-expanded="false">
                                <i class="fas fa-ellipsis-v" aria-hidden="true"></i>
                            </button>
                            <div class="file-menu hidden" role="menu">
                                <button class="menu-item download-file" role="menuitem" tabindex="-1">Download</button>
                                <button class="menu-item rename-file" role="menuitem" tabindex="-1">Rename</button>
                                <button class="menu-item share-file" role="menuitem" tabindex="-1">Share</button>
                                <button class="menu-item delete-file" role="menuitem" tabindex="-1">Delete</button>
                                <button class="menu-item file-info" role="menuitem" tabindex="-1">Info</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div id="receivedTab" class="tab-content files-grid grid-view" role="tabpanel" aria-labelledby="received-tab">
                <?php if (empty($receivedFiles)): ?>
                    <div class="no-files-message">
                        <i class="fas fa-inbox"></i>
                        <p>No received files found.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($receivedFiles as $file): ?>
                        <div class="file-item" data-file-id="<?= htmlspecialchars($file['file_id'], ENT_QUOTES, 'UTF-8') ?>" tabindex="0" role="button" aria-label="Received file: <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="file-icon">
                                <i class="fas fa-file-<?= strtolower(str_replace(['pdf', 'doc', 'xls'], ['pdf', 'word', 'chart-bar'], $file['file_type'] ?? 'alt')) ?>" aria-hidden="true"></i>
                            </div>
                            <div class="file-name"><?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="file-meta">
                                <span>From: <?= htmlspecialchars($file['sender_username'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></span>
                                <span><?= date('M d, Y', strtotime($file['upload_date'])) ?></span>
                                <span><?= number_format($file['file_size'] ?? 0 / 1024, 1) ?> KB</span>
                            </div>
                            <button class="kebab-menu" aria-label="More options for <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>" aria-expanded="false">
                                <i class="fas fa-ellipsis-v" aria-hidden="true"></i>
                            </button>
                            <div class="file-menu hidden" role="menu">
                                <button class="menu-item download-file" role="menuitem" tabindex="-1">Download</button>
                                <button class="menu-item accept-file" role="menuitem" tabindex="-1">Accept</button>
                                <button class="menu-item deny-file" role="menuitem" tabindex="-1">Deny</button>
                                <button class="menu-item file-info" role="menuitem" tabindex="-1">Info</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div id="sharedTab" class="tab-content files-grid grid-view" role="tabpanel" aria-labelledby="shared-tab">
                <?php if (empty($sharedFiles)): ?>
                    <div class="no-files-message">
                        <i class="fas fa-share"></i>
                        <p>No shared files found.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($sharedFiles as $file): ?>
                        <div class="file-item" data-file-id="<?= htmlspecialchars($file['file_id'], ENT_QUOTES, 'UTF-8') ?>" tabindex="0" role="button" aria-label="Shared file: <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="file-icon">
                                <i class="fas fa-file-<?= strtolower(str_replace(['pdf', 'doc', 'xls'], ['pdf', 'word', 'chart-bar'], $file['file_type'] ?? 'alt')) ?>" aria-hidden="true"></i>
                            </div>
                            <div class="file-name"><?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="file-meta">
                                <span>To: <?= htmlspecialchars($file['recipient_username'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></span>
                                <span><?= date('M d, Y', strtotime($file['upload_date'])) ?></span>
                                <span><?= number_format($file['file_size'] ?? 0 / 1024, 1) ?> KB</span>
                            </div>
                            <button class="kebab-menu" aria-label="More options for <?= htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8') ?>" aria-expanded="false">
                                <i class="fas fa-ellipsis-v" aria-hidden="true"></i>
                            </button>
                            <div class="file-menu hidden" role="menu">
                                <button class="menu-item download-file" role="menuitem" tabindex="-1">Download</button>
                                <button class="menu-item file-info" role="menuitem" tabindex="-1">Info</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="pagination">
                <button id="personalPrevPage" aria-label="Previous page"><i class="fas fa-chevron-left"></i></button>
                <span id="personalPageInfo"></span>
                <button id="personalNextPage" aria-label="Next page"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
        <div id="departmentFilesSection" class="files-section" aria-hidden="true">
            <select id="departmentSelect" aria-label="Select department">
                <option value="">Select Department</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= htmlspecialchars($dept['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <div id="fileHierarchy" aria-label="Department hierarchy">
                <ul id="departmentTree"></ul>
                <ul id="subDepartmentTree"></ul>
                <ul id="directoryTree"></ul>
            </div>
            <div id="breadcrumbPath" aria-label="Current department path"></div>
            <div class="filters" data-section="department">
                <input type="text" id="departmentSearchBar" placeholder="Search department files..." aria-label="Search department files">
                <select id="departmentFileSort" aria-label="Sort department files">
                    <option value="date-desc">Date (Newest)</option>
                    <option value="date-asc">Date (Oldest)</option>
                    <option value="name-asc">Name (A-Z)</option>
                    <option value="name-desc">Name (Z-A)</option>
                    <option value="size-asc">Size (Smallest)</option>
                    <option value="size-desc">Size (Largest)</option>
                </select>
                <select id="departmentFilterType" aria-label="Filter department files by type">
                    <option value="">All Types</option>
                    <?php foreach ($docTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type['document_type_id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" id="departmentFilterDateFrom" aria-label="Filter department files by date from">
                <input type="date" id="departmentFilterDateTo" aria-label="Filter department files by date to">
                <select id="departmentFilterAccess" aria-label="Filter department files by access">
                    <option value="">All Access</option>
                    <option value="department">Department</option>
                    <option value="sub-department">Sub-Department</option>
                </select>
                <button id="departmentApplyFilters" aria-label="Apply department filters">Apply Filters</button>
                <button id="departmentResetFilters" aria-label="Reset department filters">Reset</button>
            </div>
            <div class="view-toggle">
                <button class="toggle-button active" data-view="grid" aria-label="Grid view"><i class="fas fa-th"></i></button>
                <button class="toggle-button" data-view="list" aria-label="List view"><i class="fas fa-list"></i></button>
            </div>
            <div id="departmentTab" class="tab-content files-grid grid-view" role="tabpanel" aria-labelledby="department-tab">
                <div class="no-files-message">
                    <i class="fas fa-building"></i>
                    <p>Select a department to view files.</p>
                </div>
            </div>
            <div class="pagination">
                <button id="departmentPrevPage" aria-label="Previous page"><i class="fas fa-chevron-left"></i></button>
                <span id="departmentPageInfo"></span>
                <button id="departmentNextPage" aria-label="Next page"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
        <div id="uploadFileModal" class="modal" aria-hidden="true">
            <div class="modal-content">
                <button class="close-btn" onclick="closeModal('uploadFileModal')" aria-label="Close upload modal">&times;</button>
                <h2>Upload File</h2>
                <form id="uploadFileForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <label for="fileInput">Select File</label>
                    <input type="file" id="fileInput" name="file" required>
                    <label for="documentType">Document Type</label>
                    <select id="documentType" name="document_type_id" required>
                        <option value="">Select Document Type</option>
                        <?php foreach ($docTypes as $type): ?>
                            <option value="<?= htmlspecialchars($type['document_type_id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="departmentAssign">Assign to Department (Optional)</label>
                    <select id="departmentAssign" name="department_id">
                        <option value="">None</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= htmlspecialchars($dept['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" aria-label="Upload file">Upload</button>
                </form>
            </div>
        </div>
        <div id="renameModal" class="modal" aria-hidden="true">
            <div class="modal-content">
                <button class="close-btn" onclick="closeModal('renameModal')" aria-label="Close rename modal">&times;</button>
                <h2>Rename File</h2>
                <form id="renameForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="file_id">
                    <label for="newFileName">New File Name</label>
                    <input type="text" id="newFileName" name="new_file_name" required>
                    <button type="submit" aria-label="Rename file">Rename</button>
                </form>
            </div>
        </div>
        <div id="sendFileModal" class="modal" aria-hidden="true">
            <div class="modal-content">
                <button class="close-btn" onclick="closeModal('sendFileModal')" aria-label="Close share file modal">&times;</button>
                <h2>Share File</h2>
                <form id="sendFileForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="file_id">
                    <label for="recipientSearch">Search Recipients</label>
                    <input type="text" id="recipientSearch" placeholder="Search users or departments..." aria-label="Search recipients">
                    <div id="recipientList"></div>
                    <button type="submit" aria-label="Share file">Share</button>
                </form>
            </div>
        </div>
        <div id="confirmModal" class="modal" aria-hidden="true">
            <div class="modal-content">
                <button class="close-btn" onclick="closeModal('confirmModal')" aria-label="Close confirm modal">&times;</button>
                <h2>Confirm Delete</h2>
                <p>Are you sure you want to delete this file?</p>
                <div class="confirm-buttons">
                    <button id="confirmDelete" onclick="deleteFile($('#confirmModal').data('file-id'))" aria-label="Confirm delete">Delete</button>
                    <button onclick="closeModal('confirmModal')" aria-label="Cancel delete">Cancel</button>
                </div>
            </div>
        </div>
        <div id="notifications" aria-live="polite"></div>
        <?php include 'file_info_sidebar.php'; ?>
    </div>
    <script src="script/folder-page.js"></script>
</body>

</html>