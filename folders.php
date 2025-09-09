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

function sendJsonResponse(bool $success, string $message, array $data, int $statusCode): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    echo json_encode(['success' => $success, 'message' => $message] + $data);
    exit;
}

function cacheStore(string $key, $value, int $ttl): bool {
    global $cacheDir;
    $filename = "$cacheDir/" . md5($key) . '.cache';
    $data = serialize(['data' => $value, 'expires' => time() + $ttl]);
    return file_put_contents($filename, $data, LOCK_EX) !== false;
}

function cacheFetch(string $key) {
    global $cacheDir;
    $filename = "$cacheDir/" . md5($key) . '.cache';
    if (file_exists($filename)) {
        $content = unserialize(file_get_contents($filename));
        if ($content['expires'] > time()) return $content['data'];
        unlink($filename);
    }
    return false;
}

function cacheExists(string $key): bool {
    global $cacheDir;
    $filename = "$cacheDir/" . md5($key) . '.cache';
    if (file_exists($filename)) {
        $content = unserialize(file_get_contents($filename));
        if ($content['expires'] > time()) return true;
        unlink($filename);
    }
    return false;
}

function fetchUserDepartmentsWithSub(PDO $pdo, int $userId): array {
    $cacheKey = "departments_user_$userId";
    if (cacheExists($cacheKey)) return cacheFetch($cacheKey);
    try {
        $stmt = $pdo->prepare("
            WITH RECURSIVE dept_hierarchy AS (
                SELECT d.department_id, d.department_name, d.parent_department_id, ud.users_department_id
                FROM departments d JOIN user_department_assignments ud ON d.department_id = ud.department_id
                WHERE ud.user_id = ?
                UNION ALL
                SELECT d.department_id, d.department_name, d.parent_department_id, ud.users_department_id
                FROM departments d JOIN dept_hierarchy dh ON d.parent_department_id = dh.department_id
                JOIN user_department_assignments ud ON d.department_id = ud.department_id
            )
            SELECT DISTINCT department_id AS id, department_name AS name, parent_department_id AS parent_id
            FROM dept_hierarchy ORDER BY department_name
        ");
        $stmt->execute([$userId]);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        cacheStore($cacheKey, $departments, $GLOBALS['cacheTTL']);
        return $departments;
    } catch (PDOException $e) {
        error_log("Error fetching departments for user {$userId}: " . $e->getMessage());
        sendJsonResponse(false, 'Failed to fetch departments.', [], 500);
        return [];
    }
}

function fetchUserFiles(PDO $pdo, int $userId, ?int $parentFileId): array {
    $cacheKey = "user_files_{$userId}_" . ($parentFileId ?: 'null');
    if (cacheExists($cacheKey)) return cacheFetch($cacheKey);
    try {
        $query = "
            SELECT f.file_id, f.file_name, f.upload_date, f.copy_type, COALESCE(dt.type_name, 'Unknown Type') AS document_type,
                   f.file_path AS physical_storage
            FROM files f LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            WHERE f.user_id = ? " . ($parentFileId ? "AND f.parent_file_id = ?" : "AND f.parent_file_id IS NULL") . "
            ORDER BY f.upload_date DESC LIMIT 100
        ";
        $stmt = $pdo->prepare($query);
        $params = [$userId];
        if ($parentFileId) $params[] = $parentFileId;
        $stmt->execute($params);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        cacheStore($cacheKey, $files, $GLOBALS['cacheTTL']);
        return $files;
    } catch (PDOException $e) {
        error_log("Error fetching user files for user {$userId}: " . $e->getMessage());
        return [];
    }
}

function fetchDepartmentFiles(PDO $pdo, int $userId, int $departmentId, ?int $parentFileId, int $page = 1): array {
    $limit = 100;
    $offset = ($page - 1) * $limit;
    $cacheKey = "dept_files_{$userId}_{$departmentId}_" . ($parentFileId ?: 'null') . "_page_{$page}";
    if (cacheExists($cacheKey)) return cacheFetch($cacheKey);
    try {
        $query = "
            WITH RECURSIVE dept_hierarchy AS (
                SELECT department_id
                FROM departments
                WHERE department_id = ?
                UNION ALL
                SELECT d.department_id
                FROM departments d
                JOIN dept_hierarchy dh ON d.parent_department_id = dh.department_id
            )
            SELECT DISTINCT f.file_id, f.file_name, f.upload_date, f.copy_type, f.file_path AS physical_storage,
                   COALESCE(dt.type_name, 'Unknown Type') AS document_type
            FROM files f
            JOIN transactions t ON f.file_id = t.file_id
            JOIN user_department_assignments ud ON t.users_department_id = ud.users_department_id
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            WHERE ud.department_id IN (SELECT department_id FROM dept_hierarchy)
            AND ud.user_id = ?
            AND t.transaction_status = 'completed'
            AND f.file_status != 'deleted'
            " . ($parentFileId ? "AND f.parent_file_id = ?" : "AND f.parent_file_id IS NULL") . "
            ORDER BY f.upload_date DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $pdo->prepare($query);
        $params = [$departmentId, $userId, $limit, $offset];
        if ($parentFileId) array_splice($params, 2, 0, $parentFileId);
        $stmt->execute($params);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        cacheStore($cacheKey, $files, $GLOBALS['cacheTTL']);
        return $files;
    } catch (PDOException $e) {
        error_log("Error fetching department files for user {$userId}, dept {$departmentId}: " . $e->getMessage());
        return [];
    }
}

function fetchNotifications(PDO $pdo, int $userId): array {
    try {
        $stmt = $pdo->prepare("
            SELECT t.transaction_id AS id, t.file_id, t.transaction_status AS status, t.transaction_time AS timestamp,
                   t.description AS message, COALESCE(f.file_name, 'Unknown File') AS file_name, f.file_path, f.copy_type
            FROM transactions t LEFT JOIN files f ON t.file_id = f.file_id
            WHERE t.user_id = ? AND t.transaction_status = 'pending'
            ORDER BY t.transaction_time DESC LIMIT 10
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching notifications for user {$userId}: " . $e->getMessage());
        return [];
    }
}

function getFileIcon(string $fileName): string {
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $iconMap = [
        'pdf' => 'fas fa-file-pdf', 'doc' => 'fas fa-file-word', 'docx' => 'fas fa-file-word',
        'xls' => 'fas fa-file-excel', 'xlsx' => 'fas fa-file-excel', 'ppt' => 'fas fa-file-powerpoint',
        'pptx' => 'fas fa-file-powerpoint', 'jpg' => 'fas fa-file-image', 'png' => 'fas fa-file-image',
        'jpeg' => 'fas fa-file-image', 'gif' => 'fas fa-file-image', 'txt' => 'fas fa-file-alt',
        'zip' => 'fas fa-file-archive', 'rar' => 'fas fa-file-archive', 'csv' => 'fas fa-file-csv'
    ];
    return $iconMap[$extension] ?? 'fas fa-file';
}

try {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
        header('Location: logout.php');
        exit;
    }
    $userId = (int)$_SESSION['user_id'];
    $username = htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8');
    $userRole = htmlspecialchars($_SESSION['role'], ENT_QUOTES, 'UTF-8');
    session_regenerate_id(true);

    $csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    global $pdo;
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.role, u.email AS profile_pic, d.department_id, d.department_name
        FROM users u LEFT JOIN user_department_assignments ud ON u.user_id = ud.user_id
        LEFT JOIN departments d ON ud.department_id = d.department_id
        WHERE u.user_id = ? LIMIT 1
    ");
    $stmt->execute([$userId]);
    $userDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$userDetails) {
        error_log("User not found for ID: $userId");
        header('Location: logout.php');
        exit;
    }

    $stmt = $pdo->prepare("SELECT document_type_id, type_name AS name FROM document_types ORDER BY type_name ASC");
    $stmt->execute();
    $docTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $departments = fetchUserDepartmentsWithSub($pdo, $userId);
    // Batch fetch data for personal files (Uploaded, Received, Shared)
$personalQueries = [
    // Uploaded files
    [
        'sql' => "
            SELECT f.file_id, f.file_name, f.upload_date, f.copy_type, dt.type_name AS document_type, f.file_type, f.file_size, d.department_name
            FROM files f
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            LEFT JOIN departments d ON f.department_id = d.department_id
            WHERE f.user_id = ? AND f.file_status != 'deleted'
            ORDER BY f.upload_date DESC
            LIMIT 100",
        'params' => [$userId]
    ],
    // Files received
    [
        'sql' => "
            SELECT DISTINCT f.file_id, f.file_name, f.upload_date, f.copy_type, dt.type_name AS document_type, f.file_type, f.file_size,
                           u.username AS sender_username
            FROM files f
            JOIN transactions t ON f.file_id = t.file_id
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            LEFT JOIN users u ON f.user_id = u.user_id
            WHERE t.user_id = ? AND t.transaction_type = 'file_sent' AND t.transaction_status IN ('pending', 'accepted')
            ORDER BY f.upload_date DESC
            LIMIT 100",
        'params' => [$userId]
    ],
    // Files shared
    [
        'sql' => "
            SELECT DISTINCT f.file_id, f.file_name, f.upload_date, f.copy_type, dt.type_name AS document_type, f.file_type, f.file_size,
                           GROUP_CONCAT(DISTINCT u2.username) AS shared_with
            FROM files f
            JOIN transactions t ON f.file_id = t.file_id
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            LEFT JOIN users u2 ON t.user_id = u2.user_id
            WHERE f.user_id = ? AND t.transaction_type = 'file_sent'
            GROUP BY f.file_id
            ORDER BY f.upload_date DESC
            LIMIT 100",
        'params' => [$userId]
    ]
];

$personalResults = [];
foreach ($personalQueries as $index => $query) {
    try {
        $stmt = $pdo->prepare($query['sql']);
        $stmt->execute($query['params']);
        $personalResults[$index] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Personal query $index failed: " . $e->getMessage());
        $personalResults[$index] = [];
    }
}

$uploadedFiles = $personalResults[0] ?? [];
$receivedFiles = $personalResults[1] ?? [];
$sharedFiles = $personalResults[2] ?? [];
    $selectedDeptId = isset($_GET['dept']) ? (int)$_GET['dept'] : ($departments ? $departments[0]['id'] : null);
    $departmentFiles = $selectedDeptId ? [$selectedDeptId => fetchDepartmentFiles($pdo, $userId, $selectedDeptId, null)] : [];
    $notifications = fetchNotifications($pdo, $userId);
} catch (Exception $e) {
    error_log("Error in folders.php: " . $e->getMessage());
    sendJsonResponse(false, 'Server error occurred.', [], 500);
}

ob_end_clean();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Folders - File Management</title>
    <link rel="stylesheet" href="style/dashboard.css">
    <link rel="stylesheet" href="style/client-sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/noty/3.1.4/noty.min.css">
    <link rel="stylesheet" href="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/noty/3.1.4/noty.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        .main-content { margin-left: 350px; transition: all 0.3s; }
        .main-content.resized { margin-left: 260px; }
        .top-nav { background: linear-gradient(135deg, #38a169, #2d3748); padding: 15px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); position: fixed; top: 0; left: 250px; width: calc(100% - 250px); transition: left 0.3s, width 0.3s; }
        .top-nav.resized { left: 60px; width: calc(100% - 60px); }
        .top-nav h2 { margin: 0; font-size: 1.5rem; color: white; }
        .content-wrapper { padding: 80px 20px 20px; }
        .view-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; }
        .view-tab { padding: 10px 20px; border: none; background: none; cursor: pointer; font-weight: 600; color: #4a5568; transition: all 0.3s; }
        .view-tab.active, .view-tab:hover { background: #38a169; color: white; border-radius: 4px 4px 0 0; }
        .department-tabs { display: flex; gap: 10px; margin-bottom: 20px; overflow-x: auto; }
        .dept-tab { padding: 10px 20px; border: none; background: none; cursor: pointer; font-weight: 600; color: #4a5568; transition: all 0.3s; white-space: nowrap; }
        .dept-tab.active, .dept-tab:hover { background: #38a169; color: white; border-radius: 4px; }
        .sorting-buttons { display: flex; gap: 10px; margin-bottom: 20px; }
        .sort-btn { padding: 10px 20px; border: none; background: #edf2f7; cursor: pointer; border-radius: 4px; font-weight: 600; color: #4a5568; transition: all 0.3s; }
        .sort-btn.active, .sort-btn:hover { background: #38a169; color: white; }
        .no-results { text-align: center; color: #4a5568; font-size: 0.95rem; padding: 20px; }
        .view-more { text-align: center; margin-top: 20px; }
        .view-more button { background: #38a169; color: white; border: none; padding: 12px 20px; border-radius: 4px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .view-more button:hover { background: #2f855a; transform: translateY(-2px); }
        .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; }
        .modal.open { display: flex; }
        .modal-content { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); max-width: 500px; width: 90%; max-height: 80vh; overflow-y: auto; }
        .close-btn { position: absolute; top: 10px; right: 10px; background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #4a5568; }
        .close-btn:hover { color: #2d3748; }
        .modal-content h2 { margin: 0 0 15px; font-size: 1.5rem; }
        .modal-content label { display: block; margin-bottom: 8px; font-weight: 600; }
        .modal-content input[type="text"], .modal-content select, .modal-content input[type="file"] { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 0.95rem; }
        .modal-content input[type="text"]:focus, .modal-content select:focus { outline: none; border-color: #38a169; }
        .modal-content button[type="submit"] { background: #38a169; color: white; border: none; padding: 10px 20px; border-radius: 4px; font-weight: 600; cursor: pointer; }
        .modal-content button[type="submit"]:hover { background: #2f855a; }
        .confirm-buttons { display: flex; gap: 10px; justify-content: flex-end; }
        .confirm-buttons button { padding: 10px 20px; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; }
        .confirm-buttons button:first-child { background: #e53e3e; color: white; }
        .confirm-buttons button:last-child { background: #4a5568; color: white; }
        .file-info-sidebar { position: fixed; right: 0; top: 0; width: 350px; height: 100vh; background: white; box-shadow: -2px 0 8px rgba(0,0,0,0.1); transform: translateX(100%); transition: transform 0.3s ease-in-out; z-index: 900; }
        .file-info-sidebar.active { transform: translateX(0); }
        .file-info-sidebar .modal-content { padding: 20px; max-width: none; height: 100%; border-radius: 0; box-shadow: none; }
        .file-info-sidebar h3 { margin-top: 0; }
        .file-info-sidebar .close-btn { position: static; float: right; }
        .file-preview { margin-bottom: 15px; }
        .file-info-header { display: flex; gap: 10px; margin-bottom: 15px; border-bottom: 1px solid #e2e8f0; }
        .info-tab { padding: 10px; cursor: pointer; font-weight: 600; color: #4a5568; border-bottom: 2px solid transparent; }
        .info-tab.active, .info-tab:hover { border-bottom: 2px solid #38a169; color: #2d3748; }
        .info-section { display: none; }
        .info-section.active { display: block; }
        .info-item { margin-bottom: 10px; }
        .info-label { font-weight: 600; display: inline-block; width: 100px; }
        .info-value { color: #4a5568; }
        .full-preview-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .full-preview-modal.open { display: flex; }
        .full-preview-content { max-width: 90%; max-height: 90%; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); position: relative; }
        .close-full-preview { position: absolute; top: 10px; right: 10px; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #4a5568; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-250px); }
            .sidebar.active { transform: translateX(0); }
            .sidebar .toggle-btn { display: block; }
            .main-content { margin-left: 0; width: 100%; }
            .main-content.resized { margin-left: 250px; width: calc(100% - 250px); }
            .top-nav { left: 0; width: 100%; }
            .top-nav.resized { left: 250px; width: calc(100% - 250px); }
            .file-info-sidebar { width: 100%; }
        }
        .progress-bar { display: flex; gap: 10px; margin-bottom: 20px; }
        .progress-step { flex: 1; text-align: center; padding: 10px; background: #e2e8f0; border-radius: 4px; font-weight: 600; }
        .progress-step.active { background: #38a169; color: white; }
        .modal-step.hidden { display: none; }
        .drag-drop-area { border: 2px dashed #e2e8f0; padding: 20px; text-align: center; border-radius: 4px; margin-bottom: 20px; }
        .drag-drop-area.drag-over { border-color: #38a169; background: rgba(56,161,105,0.1); }
        .result, .error { margin: 10px 0; font-size: 0.95rem; }
        .result { color: #38a169; }
        .error { color: #e53e3e; }
        #reader { width: 100%; max-width: 400px; margin: 10px 0; }
        .file-menu { position: absolute; background: white; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-radius: 4px; z-index: 100; }
        .file-menu button { display: block; width: 100%; padding: 10px; border: none; background: none; text-align: left; cursor: pointer; color: #4a5568; }
        .file-menu button:hover { background: #f7fafc; }
        .tab-container {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}
.tab-button {
    padding: 10px 20px;
    border: none;
    background: none;
    cursor: pointer;
    font-weight: 600;
    color: #4a5568;
    transition: all 0.3s;
}
.tab-button.active, .tab-button:hover {
    background: #38a169;
    color: white;
    border-radius: 4px;
}
.tab-content {
    display: none;
}
.tab-content:not(.hidden) {
    display: block;
}
.files-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.files-controls select {
    padding: 8px;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 0.95rem;
}
.view-buttons {
    display: flex;
    gap: 5px;
}
.view-button {
    padding: 8px;
    border: none;
    background: #edf2f7;
    cursor: pointer;
    border-radius: 4px;
    font-size: 0.95rem;
    color: #4a5568;
    transition: all 0.3s;
}
.view-button.active, .view-button:hover {
    background: #38a169;
    color: white;
}
.files-grid.grid-view .file-item {
    width: calc(33.33% - 20px);
    display: inline-block;
    vertical-align: top;
    margin: 10px;
    padding: 15px;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    background: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}
.files-grid.list-view .file-item {
    width: 100%;
    margin: 5px 0;
    padding: 10px;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    background: white;
}
.no-files {
    text-align: center;
    color: #4a5568;
    font-size: 0.95rem;
    padding: 20px;
}
    </style>
</head>
<body>
    <aside class="sidebar">
        <button class="toggle-btn"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Document Archival</h2>
        <?php if ($userRole === 'admin'): ?>
            <a href="admin_dashboard.php" class="admin-dashboard-btn" data-tooltip="Admin Dashboard" aria-label="Admin Dashboard">
                <i class="fas fa-user-shield"></i><span class="link-text">Admin Dashboard</span>
            </a>
        <?php endif; ?>
        <a href="dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>"><i class="fas fa-home"></i><span class="link-text">Dashboard</span></a>
        <a href="my-report.php"><i class="fas fa-chart-bar"></i><span class="link-text">My Report</span></a>
        <a href="folders.php" class="<?= basename($_SERVER['PHP_SELF']) === 'folders.php' ? 'active' : '' ?>"><i class="fas fa-folder"></i><span class="link-text">My Folder</span></a>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </aside>

    <div class="main-content <?php echo $userRole === 'admin' ? '' : 'resized'; ?>">
        <div class="top-nav <?php echo $userRole === 'admin' ? '' : 'resized'; ?>">
            <button class="toggle-btn"><i class="fas fa-bars"></i></button>
            <h2>My Folders</h2>
            <div class="search-container">
                <input type="text" class="search-bar" id="searchInput" placeholder="Search files...">
                <button class="search-button"><i class="fas fa-search"></i></button>
            </div>
            <button class="action-button" onclick="openModal('upload')"><i class="fas fa-upload"></i> Upload</button>
            <button class="action-button" onclick="openModal('scanQR')"><i class="fas fa-qrcode"></i> Scan QR</button>
        </div>

        <div class="content-wrapper">
            <div class="view-tabs">
                <div class="view-tab <?php echo !isset($_GET['dept']) ? 'active' : ''; ?>" data-view="personal">Personal Files</div>
                <div class="view-tab <?php echo isset($_GET['dept']) ? 'active' : ''; ?>" data-view="department">Department Files</div>
            </div>

            <?php if (empty($departments)): ?>
                <div class="no-results">No department assigned. Contact admin.</div>
            <?php else: ?>
                <div class="department-tabs" style="display: <?php echo isset($_GET['dept']) ? 'flex' : 'none'; ?>;">
                    <?php foreach ($departments as $dept): ?>
                        <div class="dept-tab <?php echo $selectedDeptId == $dept['id'] ? 'active' : ''; ?>" data-dept-id="<?php echo $dept['id']; ?>">
                            <?php echo htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="sorting-buttons">
                <button class="sort-btn" data-criteria="name">Name</button>
                <button class="sort-btn" data-criteria="type">Type</button>
                <button class="sort-btn" data-criteria="date">Date</button>
            </div>

            <div class="files-grid">
                <div class="masonry-section" id="personalFilesSection" style="display: <?php echo !isset($_GET['dept']) ? 'block' : 'none'; ?>;">
    <h3>Personal Files</h3>
    <div class="tab-container">
        <button class="tab-button active" data-tab="uploaded">Uploaded</button>
        <button class="tab-button" data-tab="received">Received</button>
        <button class="tab-button" data-tab="shared">Shared</button>
    </div>
    <div class="files-controls">
        <select id="fileSort">
            <option value="date-desc">Newest First</option>
            <option value="date-asc">Oldest First</option>
            <option value="department">By Department</option>
            <option value="sub-department">By Sub-Department</option>
            <option value="personal">Personal</option>
        </select>
        <div class="view-buttons">
            <button class="view-button active" data-view="grid" aria-label="Grid View"><i class="fas fa-th"></i></button>
            <button class="view-button" data-view="list" aria-label="List View"><i class="fas fa-list"></i></button>
        </div>
    </div>
    <div id="uploadedTab" class="tab-content files-grid grid-view">
        <?php if (empty($uploadedFiles)): ?>
            <p class="no-files">No files uploaded.</p>
        <?php else: ?>
            <?php foreach ($uploadedFiles as $file): ?>
                <div class="file-item" data-file-id="<?php echo htmlspecialchars($file['file_id']); ?>" data-file-name="<?php echo htmlspecialchars($file['file_name']); ?>" data-document-type="<?php echo htmlspecialchars($file['document_type'] ?? 'Unknown'); ?>" data-upload-date="<?php echo $file['upload_date']; ?>">
                    <p class="file-name"><?php echo htmlspecialchars($file['file_name']); ?></p>
                    <p class="file-meta">Type: <?php echo htmlspecialchars($file['document_type'] ?? 'Unknown'); ?> | Uploaded: <?php echo date('M d, Y', strtotime($file['upload_date'])); ?> | Dept: <?php echo htmlspecialchars($file['department_name'] ?? 'None'); ?></p>
                    <button class="kebab-menu"><i class="fas fa-ellipsis-v"></i></button>
                    <div class="file-menu hidden">
                        <button class="download-file" onclick="downloadFile(<?php echo $file['file_id']; ?>)">Download</button>
                        <button class="rename-file" onclick="openModal('rename', <?php echo $file['file_id']; ?>, '<?php echo htmlspecialchars($file['file_name']); ?>')">Rename</button>
                        <button class="delete-file" onclick="openModal('confirm', <?php echo $file['file_id']; ?>)">Delete</button>
                        <button class="share-file" onclick="openModal('sendFile', <?php echo $file['file_id']; ?>)">Share</button>
                        <button class="file-info" onclick="showFileInfo(<?php echo $file['file_id']; ?>)">File Info</button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (count($uploadedFiles) >= 100): ?>
            <div class="view-more"><button onclick="loadMoreFiles('personal', 'uploaded')">View More</button></div>
        <?php endif; ?>
    </div>
    <div id="receivedTab" class="tab-content files-grid grid-view hidden">
        <?php if (empty($receivedFiles)): ?>
            <p class="no-files">No files received.</p>
        <?php else: ?>
            <?php foreach ($receivedFiles as $file): ?>
                <div class="file-item" data-file-id="<?php echo htmlspecialchars($file['file_id']); ?>" data-file-name="<?php echo htmlspecialchars($file['file_name']); ?>" data-document-type="<?php echo htmlspecialchars($file['document_type'] ?? 'Unknown'); ?>" data-upload-date="<?php echo $file['upload_date']; ?>">
                    <p class="file-name"><?php echo htmlspecialchars($file['file_name']); ?></p>
                    <p class="file-meta">Type: <?php echo htmlspecialchars($file['document_type'] ?? 'Unknown'); ?> | Uploaded: <?php echo date('M d, Y', strtotime($file['upload_date'])); ?> | Sender: <?php echo htmlspecialchars($file['sender_username'] ?? 'Unknown'); ?></p>
                    <button class="kebab-menu"><i class="fas fa-ellipsis-v"></i></button>
                    <div class="file-menu hidden">
                        <button class="download-file" onclick="downloadFile(<?php echo $file['file_id']; ?>)">Download</button>
                        <button class="file-info" onclick="showFileInfo(<?php echo $file['file_id']; ?>)">File Info</button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (count($receivedFiles) >= 100): ?>
            <div class="view-more"><button onclick="loadMoreFiles('personal', 'received')">View More</button></div>
        <?php endif; ?>
    </div>
    <div id="sharedTab" class="tab-content files-grid grid-view hidden">
        <?php if (empty($sharedFiles)): ?>
            <p class="no-files">No files shared.</p>
        <?php else: ?>
            <?php foreach ($sharedFiles as $file): ?>
                <div class="file-item" data-file-id="<?php echo htmlspecialchars($file['file_id']); ?>" data-file-name="<?php echo htmlspecialchars($file['file_name']); ?>" data-document-type="<?php echo htmlspecialchars($file['document_type'] ?? 'Unknown'); ?>" data-upload-date="<?php echo $file['upload_date']; ?>">
                    <p class="file-name"><?php echo htmlspecialchars($file['file_name']); ?></p>
                    <p class="file-meta">Type: <?php echo htmlspecialchars($file['document_type'] ?? 'Unknown'); ?> | Uploaded: <?php echo date('M d, Y', strtotime($file['upload_date'])); ?> | Shared With: <?php echo htmlspecialchars($file['shared_with'] ?? 'None'); ?></p>
                    <button class="kebab-menu"><i class="fas fa-ellipsis-v"></i></button>
                    <div class="file-menu hidden">
                        <button class="download-file" onclick="downloadFile(<?php echo $file['file_id']; ?>)">Download</button>
                        <button class="rename-file" onclick="openModal('rename', <?php echo $file['file_id']; ?>, '<?php echo htmlspecialchars($file['file_name']); ?>')">Rename</button>
                        <button class="delete-file" onclick="openModal('confirm', <?php echo $file['file_id']; ?>)">Delete</button>
                        <button class="share-file" onclick="openModal('sendFile', <?php echo $file['file_id']; ?>)">Share</button>
                        <button class="file-info" onclick="showFileInfo(<?php echo $file['file_id']; ?>)">File Info</button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (count($sharedFiles) >= 100): ?>
            <div class="view-more"><button onclick="loadMoreFiles('personal', 'shared')">View More</button></div>
        <?php endif; ?>
    </div>
</div>

                <div class="masonry-section" id="departmentFilesSection" style="display: <?php echo isset($_GET['dept']) ? 'block' : 'none'; ?>;">
                    <h3>Department Files</h3>
                    <div class="file-card-container" id="departmentFileGrid">
                        <?php if (isset($_GET['dept']) && !empty($departmentFiles[$selectedDeptId])): ?>
                            <?php foreach ($departmentFiles[$selectedDeptId] as $file): ?>
                                <div class="file-item" data-file-id="<?php echo $file['file_id']; ?>" data-file-name="<?php echo htmlspecialchars($file['file_name']); ?>" data-document-type="<?php echo htmlspecialchars($file['document_type']); ?>" data-upload-date="<?php echo $file['upload_date']; ?>">
                                    <p class="file-name"><?php echo htmlspecialchars($file['file_name']); ?></p>
                                    <p class="file-meta">Type: <?php echo htmlspecialchars($file['document_type']); ?> | Uploaded: <?php echo date('m/d/Y', strtotime($file['upload_date'])); ?></p>
                                    <button class="kebab-menu"><i class="fas fa-ellipsis-v"></i></button>
                                    <div class="file-menu hidden">
                                        <button class="download-file">Download</button>
                                        <button class="rename-file" onclick="openModal('rename', <?php echo $file['file_id']; ?>, '<?php echo htmlspecialchars($file['file_name']); ?>')">Rename</button>
                                        <button class="delete-file" onclick="openModal('confirm', <?php echo $file['file_id']; ?>)">Delete</button>
                                        <button class="share-file">Share</button>
                                        <button class="file-info" onclick="showFileInfo(<?php echo $file['file_id']; ?>)">File Info</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($departmentFiles[$selectedDeptId]) >= 100): ?>
                                <div class="view-more"><button onclick="loadMoreFiles('department')">View More</button></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="no-results">No files found for this department</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal" id="uploadModal">
            <div class="modal-content">
                <button class="close-btn" onclick="closeModal('upload')"><i class="fas fa-times"></i></button>
                <h2>Upload File</h2>
                <div class="progress-bar">
                    <div class="progress-step active" data-step="1">1. Select File</div>
                    <div class="progress-step" data-step="2">2. Details</div>
                </div>
                <form id="uploadForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                    <div class="modal-step" data-step="1">
                        <div class="drag-drop-area">
                            <p>Drag & Drop files or</p>
                            <button type="button" class="choose-file-button">Choose File</button>
                            <input type="file" id="fileInput" name="files[]" multiple hidden>
                        </div>
                        <div id="filePreviewArea"></div>
                        <button type="button" class="next-step submit-button">Next</button>
                    </div>
                    <div class="modal-step hidden" data-step="2">
                        <label>Access Level</label>
                        <select name="access_level" id="accessLevel">
                            <option value="personal">Personal</option>
                            <option value="department">Department</option>
                            <option value="sub_department">Sub-Department</option>
                        </select>
                        <div id="departmentContainer" class="hidden">
                            <label>Department</label>
                            <select id="departmentSelect" name="department_id">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= htmlspecialchars($dept['id']) ?>"><?= htmlspecialchars($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label>Sub-Department</label>
                            <select id="subDepartmentSelect" name="sub_department_id">
                                <option value="">No Sub-Department</option>
                            </select>
                        </div>
                        <label>Document Type</label>
                        <select name="document_type_id" id="documentType">
                            <option value="">Select Document Type</option>
                            <?php foreach ($docTypes as $doc): ?>
                                <option value="<?= htmlspecialchars($doc['document_type_id']) ?>"><?= htmlspecialchars($doc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label><input type="checkbox" id="hardcopyCheckbox" name="is_hardcopy"> Hardcopy</label>
                        <div id="hardcopyOptions" class="hidden">
                            <label><input type="radio" name="hardcopyOption" value="new" checked> New Hardcopy</label>
                            <label><input type="radio" name="hardcopyOption" value="existing"> Existing Hardcopy</label>
                            <label for="hardcopyFileName">File Name</label>
                            <input type="text" id="hardcopyFileName" name="hardcopy_file_name" placeholder="Enter file name">
                            <div id="hardcopySearchContainer" class="hidden">
                                <label for="physicalStorage">Physical Storage</label>
                                <input type="text" id="physicalStorage" name="physical_storage" placeholder="Search storage">
                            </div>
                        </div>
                        <button type="button" class="prev-step">Previous</button>
                        <button type="submit" class="submit-button">Upload</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal" id="renameModal">
            <div class="modal-content">
                <button class="close-btn" onclick="closeModal('rename')"><i class="fas fa-times"></i></button>
                <h2>Rename File</h2>
                <form id="renameForm">
                    <input type="hidden" name="file_id" id="renameFileId">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                    <label for="newFileName">New File Name</label>
                    <input type="text" id="newFileName" name="new_file_name" required>
                    <button type="submit">Rename</button>
                </form>
            </div>
        </div>

        <div class="modal" id="confirmModal">
            <div class="modal-content">
                <button class="close-btn" onclick="closeModal('confirm')"><i class="fas fa-times"></i></button>
                <h2>Confirm Deletion</h2>
                <p>Are you sure you want to delete this file?</p>
                <div class="confirm-buttons">
                    <button onclick="deleteFile($('#confirmModal').data('file-id'))">Yes</button>
                    <button onclick="closeModal('confirm')">Cancel</button>
                </div>
            </div>
        </div>

        <div class="modal" id="scanQRModal">
            <div class="modal-content">
                <button class="close-btn" onclick="closeModal('scanQR')"><i class="fas fa-times"></i></button>
                <h2>Scan QR Code</h2>
                <div id="reader"></div>
                <input type="file" id="qr-input-file" accept="image/*">
                <button class="action-button" onclick="startScanner()">Start Scan</button>
                <button class="action-button" onclick="stopScanner()">Stop Scan</button>
                <div id="result" class="result"></div>
                <div id="error" class="error"></div>
            </div>
        </div>

        <div class="modal" id="sendFileModal">
            <div class="modal-content">
                <h3>Send File</h3>
                <button class="close-btn" onclick="closeModal('sendFile')"><i class="fas fa-times"></i></button>
                <form id="sendFileForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                    <div class="modal-section">
                        <label>Select Files</label>
                        <div class="files-grid scrollable" id="fileSelectionGrid">
                            <?php if (empty($personalFiles)): ?>
                                <p class="no-files">No files available to send.</p>
                            <?php else: ?>
                                <?php foreach ($personalFiles as $file): ?>
                                    <div class="file-item selectable" data-file-id="<?php echo $file['file_id']; ?>">
                                        <p class="file-name"><?php echo htmlspecialchars($file['file_name']); ?></p>
                                        <p class="file-meta">Type: <?php echo htmlspecialchars($file['document_type']); ?> | Uploaded: <?php echo date('m/d/Y', strtotime($file['upload_date'])); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="modal-section">
                        <label>Select Recipients</label>
                        <input type="text" id="recipientSearch" placeholder="Search users or departments...">
                        <div id="recipientList" class="recipient-list"></div>
                    </div>
                    <div class="modal-section">
                        <label>Message (Optional)</label>
                        <textarea name="message" placeholder="Add a message..." rows="4"></textarea>
                    </div>
                    <div class="modal-actions">
                        <button type="submit" class="submit-button">Send</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="file-info-sidebar" id="fileInfoSidebar">
            <div class="modal-content">
                <h3>File Information</h3>
                <button class="close-btn" onclick="closeFileInfo()"><i class="fas fa-times"></i></button>
                <div class="file-preview" id="filePreview"></div>
                <div class="file-info-header">
                    <div class="info-tab active" data-tab="details">Details</div>
                    <div class="info-tab" data-tab="activity">Activity</div>
                </div>
                <div class="info-section active" id="detailsSection">
                    <div class="file-details">
                        <h4>File Details</h4>
                        <div class="info-item"><span class="info-label">Name</span><span class="info-value" id="infoFileName"></span></div>
                        <div class="info-item"><span class="info-label">Type</span><span class="info-value" id="infoFileType"></span></div>
                        <div class="info-item"><span class="info-label">Size</span><span class="info-value" id="infoFileSize"></span></div>
                        <div class="info-item"><span class="info-label">Category</span><span class="info-value" id="infoFileCategory"></span></div>
                        <div class="info-item"><span class="info-label">Uploader</span><span class="info-value" id="infoUploader"></span></div>
                        <div class="info-item"><span class="info-label">Upload Date</span><span class="info-value" id="infoUploadDate"></span></div>
                        <div class="info-item"><span class="info-label">Storage</span><span class="info-value" id="infoPhysicalStorage"></span></div>
                        <div class="info-item"><span class="info-label">Access</span><span class="info-value" id="infoAccess"></span></div>
                        <div id="infoQRCode"></div>
                    </div>
                </div>
                <div class="info-section" id="activitySection">
                    <h4>Activity Log</h4>
                    <div id="fileHistory"></div>
                </div>
            </div>
        </div>

        <div class="full-preview-modal" id="fullPreviewModal">
            <div class="full-preview-content">
                <button class="close-full-preview" onclick="closeFullPreview()"><i class="fas fa-times"></i></button>
                <div id="fullFilePreview"></div>
            </div>
        </div>

<?php include 'templates/file_info_sidebar.php'; ?>
    </div>

    <script>
        const notyf = new Noty({ theme: 'metroui', timeout: 3000, progressBar: true });
        const state = {
            activeView: '<?php echo isset($_GET['dept']) ? 'department' : 'personal'; ?>',
            activeDeptId: '<?php echo $selectedDeptId ?? ''; ?>',
            activeModal: null,
            activeTab: 'details',
            currentPage: 1,
            isLoading: false,
            loadedFileIds: new Set()
        };
        let html5QrcodeScanner = null;

        $(document).ready(function() {
            $('.toggle-btn').click(function() {
                $('.sidebar').toggleClass('minimized');
                $('.main-content, .top-nav').toggleClass('resized');
            });

            $('.view-tab').click(function() {
                $('.view-tab').removeClass('active');
                $(this).addClass('active');
                state.activeView = $(this).data('view');
                state.currentPage = 1;
                state.loadedFileIds.clear();
                $('#personalFilesSection').toggle(state.activeView === 'personal');
                $('#departmentFilesSection').toggle(state.activeView === 'department');
                $('.department-tabs').toggle(state.activeView === 'department');
                if (state.activeView === 'department' && state.activeDeptId) loadDepartmentFiles(state.activeDeptId);
                else if (state.activeView === 'department') {
                    $('.dept-tab').first().addClass('active');
                    state.activeDeptId = $('.dept-tab').first().data('dept-id') || '';
                    loadDepartmentFiles(state.activeDeptId);
                } else $('#fileGrid').find('.file-item').show();
            });

            $('.dept-tab').click(function() {
                $('.dept-tab').removeClass('active');
                $(this).addClass('active');
                state.activeDeptId = $(this).data('dept-id');
                state.currentPage = 1;
                state.loadedFileIds.clear();
                loadDepartmentFiles(state.activeDeptId);
            });

            $('.info-tab').click(function() {
                $('.info-tab').removeClass('active');
                $('.info-section').removeClass('active');
                $(this).addClass('active');
                state.activeTab = $(this).data('tab');
                $(`#${state.activeTab}Section`).addClass('active');
            });

            $('#searchInput').on('input', function() {
                const query = $(this).val().toLowerCase().trim();
                const grid = state.activeView === 'personal' ? '#fileGrid' : '#departmentFileGrid';
                $(grid).find('.file-item').each(function() {
                    $(this).toggle($(this).data('file-name').toLowerCase().includes(query));
                });
                $(grid).find('.no-results').toggle($(grid).find('.file-item:visible').length === 0);
            });

            $('.sort-btn').click(function() {
                $('.sort-btn').removeClass('active');
                $(this).addClass('active');
                sortFiles($(this).data('criteria'));
            });

            $('#uploadForm').submit(function(e) {
                e.preventDefault();
                if (state.isLoading) return;
                setLoadingState(true);
                const formData = new FormData(this);
                if ($('#hardcopyCheckbox').is(':checked') && $('input[name="hardcopyOption"]:checked').val() === 'new' && !$('#hardcopyFileName').val()) {
                    notyf.error('Hardcopy file name required.');
                    setLoadingState(false);
                    return;
                }
                $.ajax({
                    url: 'upload.php', method: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            notyf.success(response.message);
                            if (response.qr_path) $('#filePreviewArea').html(`<p>QR Code:</p><img src="${response.qr_path}" style="max-width: 200px;">`);
                            closeModal('upload');
                            setTimeout(() => location.reload(), 2000);
                        } else notyf.error(response.message || 'Upload failed.');
                    },
                    error: () => notyf.error('Upload error.'),
                    complete: () => setLoadingState(false)
                });
            });

            $('#renameForm').submit(function(e) {
                e.preventDefault();
                if (state.isLoading) return;
                setLoadingState(true);
                $.ajax({
                    url: 'rename_file.php', method: 'POST', data: $(this).serialize(), dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            notyf.success(response.message);
                            closeModal('rename');
                            location.reload();
                        } else notyf.error(response.message || 'Rename failed.');
                    },
                    error: () => notyf.error('Rename error.'),
                    complete: () => setLoadingState(false)
                });
            });

            $('#sendFileForm').submit(function(e) {
                e.preventDefault();
                if (state.isLoading) return;
                setLoadingState(true);
                const selectedFiles = $('#fileSelectionGrid .file-item.selected').map(function() {
                    return $(this).data('file-id');
                }).get();
                const formData = new FormData(this);
                formData.append('file_ids', JSON.stringify(selectedFiles));
                $.ajax({
                    url: 'send_file.php', method: 'POST', data: formData, processData: false, contentType: false, dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            notyf.success(response.message);
                            closeModal('sendFile');
                            location.reload();
                        } else notyf.error(response.message || 'Send failed.');
                    },
                    error: () => notyf.error('Send error.'),
                    complete: () => setLoadingState(false)
                });
            });

            $('.next-step').click(() => {
                $('.modal-step[data-step="1"]').addClass('hidden');
                $('.modal-step[data-step="2"]').removeClass('hidden');
                $('.progress-step[data-step="1"]').removeClass('active');
                $('.progress-step[data-step="2"]').addClass('active');
            });

            $('.prev-step').click(() => {
                $('.modal-step[data-step="2"]').addClass('hidden');
                $('.modal-step[data-step="1"]').removeClass('hidden');
                $('.progress-step[data-step="2"]').removeClass('active');
                $('.progress-step[data-step="1"]').addClass('active');
            });

            $('#hardcopyCheckbox').change(function() {
                $('#hardcopyOptions').toggleClass('hidden', !this.checked);
                $('#fileInput').prop('disabled', this.checked);
            });

            $('input[name="hardcopyOption"]').change(function() {
                const isExisting = $(this).val() === 'existing';
                $('#hardcopySearchContainer').toggleClass('hidden', !isExisting);
                $('#hardcopyFileName').toggleClass('hidden', isExisting);
            });

            $('#accessLevel').change(function() {
                $('#departmentContainer').toggleClass('hidden', this.value === 'personal');
            });

            $('#departmentSelect').change(function() {
                const deptId = $(this).val();
                if (deptId) {
                    $.ajax({
                        url: 'get_sub_departments.php', type: 'GET', data: { department_id: deptId }, dataType: 'json',
                        success: function(data) {
                            $('#subDepartmentSelect').html('<option value="">No Sub-Department</option>' + data.map(subDept => `<option value="${subDept.department_id}">${subDept.department_name}</option>`).join(''));
                        },
                        error: () => notyf.error('Error fetching sub-departments.')
                    });
                }
            });

            $(document).on('click', '.kebab-menu', function(e) {
                e.stopPropagation();
                const $menu = $(this).siblings('.file-menu');
                $('.file-menu').not($menu).addClass('hidden');
                $menu.toggleClass('hidden');
            });

            $(document).on('click', function(e) {
                if (!$(e.target).closest('.kebab-menu, .file-menu').length) {
                    $('.file-menu').addClass('hidden');
                }
            });

            $('#fileSelectionGrid .file-item').click(function() {
                $(this).toggleClass('selected');
            });

            $('#recipientSearch').on('input', function() {
                const query = $(this).val().toLowerCase();
                $.ajax({
                    url: 'search_recipients.php', method: 'GET', data: { query: query }, dataType: 'json',
                    success: function(data) {
                        $('#recipientList').html(data.map(item => `
                            <div class="recipient-item" data-type="${item.type}" data-id="${item.id}">
                                <input type="checkbox" name="recipients[]" value="${item.type}:${item.id}">
                                ${item.name} (${item.type})
                            </div>
                        `).join(''));
                    },
                    error: () => notyf.error('Error searching recipients.')
                });
            });
        });



        function openModal(modalId, fileId = null, fileName = '') {
            state.activeModal = modalId;
            $(`#${modalId}Modal`).addClass('open');
            if (modalId === 'rename' && fileId) {
                $('#renameFileId').val(fileId);
                $('#newFileName').val(fileName);
            } else if (modalId === 'confirm' && fileId) {
                $('#confirmModal').data('file-id', fileId);
            } else if (modalId === 'sendFile' && fileId) {
                $('#fileSelectionGrid .file-item').removeClass('selected');
                $(`#fileSelectionGrid .file-item[data-file-id="${fileId}"]`).addClass('selected');
            }
        }

        function closeModal(modalId) {
            $(`#${modalId}Modal`).removeClass('open');
            state.activeModal = null;
            if (modalId === 'scanQR') stopScanner();
        }

        function startScanner() {
            $('#result, #error').text('');
            html5QrcodeScanner = new Html5Qrcode("reader");
            html5QrcodeScanner.start({ facingMode: "environment" }, { fps: 10, qrbox: { width: 250, height: 250 } }, onScanSuccess, onScanFailure)
                .catch(err => $('#error').text(`Error starting scanner: ${err}`));
        }

        function stopScanner() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.stop().then(() => html5QrcodeScanner.clear()).catch(err => $('#error').text(`Error stopping scanner: ${err}`));
            }
        }

        function onScanSuccess(decodedText) {
            $('#result').text(`Scanned: ${decodedText}`);
            if (decodedText.startsWith('file_id:')) fetchFileInfo(decodedText.replace('file_id:', '').trim());
            stopScanner();
        }

        function onScanFailure(error) {
            console.warn(`Scan error: ${error}`);
        }

        function fetchFileInfo(fileId) {
            if (state.isLoading) return;
            setLoadingState(true);
            $.ajax({
                url: 'get_file_info.php', method: 'GET', data: { id: fileId }, dataType: 'json',
                success: function(response) {
                    if (response.error) $('#error').text(response.error);
                    else {
                        $('#fileInfoContent').html(`
                            <p><strong>Name:</strong> ${response.file_name}</p>
                            <p><strong>Date:</strong> ${response.upload_date}</p>
                            <p><strong>Type:</strong> ${response.copy_type}</p>
                            <p><strong>Category:</strong> ${response.document_type || 'N/A'}</p>
                            <p><strong>Department:</strong> ${response.department_name || 'N/A'}</p>
                            ${response.qr_path ? `<p><strong>QR:</strong><br><img src="${response.qr_path}" style="max-width: 200px;"></p>` : ''}
                            ${response.file_path ? `<p><strong>Path:</strong> ${response.file_path}</p>` : ''}
                        `);
                        $('#fileInfoModal').addClass('open');
                    }
                },
                error: () => $('#error').text('Error fetching file info.'),
                complete: () => setLoadingState(false)
            });
        }

        function showFileInfo(fileId) {
            if (state.isLoading) return;
            setLoadingState(true);
            $.ajax({
                url: 'get_file_details.php', method: 'POST', data: { file_id: fileId, csrf_token: $('meta[name="csrf-token"]').attr('content') }, dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        $('#infoFileName').text(data.file_name || 'N/A');
                        $('#infoFileType, #infoFileCategory').text(data.document_type || 'N/A');
                        $('#infoFileSize').text(data.file_size || 'N/A');
                        $('#infoUploader').text(data.uploader || 'N/A');
                        $('#infoUploadDate').text(data.upload_date ? new Date(data.upload_date).toLocaleDateString('en-US') : 'N/A');
                        $('#infoPhysicalStorage').text(data.physical_storage || 'None');
                        $('#infoAccess').text(data.access_info || 'N/A');
                        $('#infoQRCode').empty();
                        if (data.physical_storage && data.physical_storage !== 'None') new QRCode(document.getElementById('infoQRCode'), { text: `file_id:${fileId}`, width: 100, height: 100 });
                        $('#fileHistory').html(data.history ? data.history.map(h => `<p>${h.action} on ${new Date(h.timestamp).toLocaleString('en-US')}</p>`).join('') : '<p>No history</p>');
                        $('#filePreview').empty();
                        if (data.copy_type === 'soft_copy' && data.file_path && data.file_path !== 'None') {
                            const ext = data.file_path.split('.').pop().toLowerCase();
                            $('#filePreview').html(['jpg', 'png', 'jpeg', 'gif'].includes(ext) ? `<img src="${data.file_path}" style="max-width: 100%; max-height: 200px;">` :
                                ext === 'pdf' ? `<iframe src="${data.file_path}" style="width: 100%; height: 200px;"></iframe>` : '<p>Preview not available</p>');
                        } else $('#filePreview').html('<p>No preview available</p>');
                        $('#fileInfoSidebar').addClass('active');
                        $('.info-tab[data-tab="details"]').addClass('active');
                        $('.info-tab[data-tab="activity"]').removeClass('active');
                        $('#detailsSection').addClass('active');
                        $('#activitySection').removeClass('active');
                    } else notyf.error(response.message || 'Error fetching file info.');
                },
                error: () => notyf.error('Failed to load file info.'),
                complete: () => setLoadingState(false)
            });
        }

        function closeFileInfo() {
            $('#fileInfoSidebar').removeClass('active');
            $('#filePreview, #infoQRCode').empty();
        }

        function openFullPreview(fileId) {
            $('#fullPreviewModal').data('file-id', fileId).addClass('open');
            $.ajax({
                url: 'get_file_details.php', method: 'POST', data: { file_id: fileId, csrf_token: $('meta[name="csrf-token"]').attr('content') }, dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        const preview = $('#fullFilePreview');
                        if (data.copy_type === 'soft_copy' && data.file_path && data.file_path !== 'None') {
                            const ext = data.file_path.split('.').pop().toLowerCase();
                            preview.html(['jpg', 'png', 'jpeg', 'gif'].includes(ext) ? `<img src="${data.file_path}" style="max-width: 100%; max-height: 80vh;">` :
                                ext === 'pdf' ? `<iframe src="${data.file_path}" style="width: 100%; height: 80vh;"></iframe>` : '<p>Preview not available</p>');
                        } else preview.html('<p>No preview available</p>');
                    } else notyf.error(response.message || 'Error fetching preview.');
                },
                error: () => notyf.error('Error fetching preview.')
            });
        }

        function closeFullPreview() {
            $('#fullPreviewModal').removeClass('open');
            $('#fullFilePreview').empty();
        }

        function downloadFile(fileId) {
            if (state.isLoading) return;
            setLoadingState(true);
            $.ajax({
                url: 'download_file.php', method: 'POST', data: { file_id: fileId, csrf_token: $('meta[name="csrf-token"]').attr('content') }, dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        window.location.href = response.download_url;
                        notyf.success('Download started.');
                    } else {
                        notyf.error(response.message || 'Download failed.');
                    }
                },
                error: () => notyf.error('Download error.'),
                complete: () => setLoadingState(false)
            });
        }

        function deleteFile(fileId) {
            setLoadingState(true);
            $.ajax({
                url: 'delete_file.php', method: 'POST', headers: { 'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content') }, data: JSON.stringify({ file_id: fileId }), contentType: 'application/json', dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        notyf.success(response.message);
                        location.reload();
                    } else notyf.error(response.message || 'Delete failed.');
                },
                error: () => notyf.error('Delete error.'),
                complete: () => setLoadingState(false)
            });
        }

        function loadDepartmentFiles(deptId) {
            if (!deptId) {
                $('#departmentFileGrid').html('<p class="no-results">Select a department</p>');
                return;
            }
            setLoadingState(true);
            $.ajax({
                url: 'get_department_files.php', method: 'POST', data: { department_id: deptId, page: state.currentPage, csrf_token: $('meta[name="csrf-token"]').attr('content') }, dataType: 'json',
                success: function(response) {
                    const grid = $('#departmentFileGrid').empty();
                    state.loadedFileIds.clear();
                    if (!response.success || response.data.length === 0) {
                        grid.append('<p class="no-results">No files found</p>');
                    } else {
                        response.data.forEach(file => {
                            if (!state.loadedFileIds.has(file.file_id)) {
                                state.loadedFileIds.add(file.file_id);
                                grid.append(`
                                    <div class="file-item" data-file-id="${file.file_id}" data-file-name="${file.file_name}" data-document-type="${file.document_type}" data-upload-date="${file.upload_date}">
                                        <p class="file-name">${file.file_name}</p>
                                        <p class="file-meta">Type: ${file.document_type || 'Unknown'} | Uploaded: ${new Date(file.upload_date).toLocaleDateString('en-US')}</p>
                                        <button class="kebab-menu"><i class="fas fa-ellipsis-v"></i></button>
                                        <div class="file-menu hidden">
                                            <button class="download-file" onclick="downloadFile(${file.file_id})">Download</button>
                                            <button class="rename-file" onclick="openModal('rename', ${file.file_id}, '${file.file_name}')">Rename</button>
                                            <button class="delete-file" onclick="openModal('confirm', ${file.file_id})">Delete</button>
                                            <button class="share-file" onclick="openModal('sendFile', ${file.file_id})">Share</button>
                                            <button class="file-info" onclick="showFileInfo(${file.file_id})">File Info</button>
                                        </div>
                                    </div>
                                `);
                            }
                        });
                        if (response.data.length >= 100) grid.append('<div class="view-more"><button onclick="loadMoreFiles(\'department\')">View More</button></div>');
                    }
                },
                error: () => {
                    $('#departmentFileGrid').html('<p class="no-results">Error loading files.</p>');
                    notyf.error('Failed to load files.');
                },
                complete: () => setLoadingState(false)
            });
        }

        function loadMoreFiles(view) {
            state.currentPage++;
            if (view === 'personal') loadPersonalFiles();
            else if (view === 'department') loadDepartmentFiles(state.activeDeptId);
        }

        // Initialize state for personal files tabs
state.personalTab = 'uploaded'; // Default tab
state.uploadedPage = 1;
state.receivedPage = 1;
state.sharedPage = 1;
state.uploadedFileIds = new Set();
state.receivedFileIds = new Set();
state.sharedFileIds = new Set();

$(document).ready(function() {
    // Tab switching for personal files
    $('#personalFilesSection .tab-button').click(function() {
        $('#personalFilesSection .tab-button').removeClass('active');
        $(this).addClass('active');
        $('#personalFilesSection .tab-content').addClass('hidden');
        state.personalTab = $(this).data('tab');
        $(`#${state.personalTab}Tab`).removeClass('hidden');
        state[state.personalTab + 'Page'] = 1;
        state[state.personalTab + 'FileIds'].clear();
        loadPersonalFiles(state.personalTab);
    });

    // File view toggle (grid/list)
    $('#personalFilesSection .view-button').click(function() {
        const view = $(this).data('view');
        $('#personalFilesSection .view-button').removeClass('active');
        $(this).addClass('active');
        $('#personalFilesSection .files-grid').removeClass('grid-view list-view').addClass(`${view}-view`);
    });

    // File sorting
    $('#personalFilesSection #fileSort').change(function() {
        const sort = $(this).val();
        const grid = $(`#${state.personalTab}Tab`);
        const cards = grid.find('.file-item').get();
        cards.sort((a, b) => {
            if (sort === 'date-desc') {
                return new Date($(b).data('upload-date')) - new Date($(a).data('upload-date'));
            } else if (sort === 'date-asc') {
                return new Date($(a).data('upload-date')) - new Date($(b).data('upload-date'));
            } else if (sort === 'department') {
                const aDept = $(a).find('.file-meta').text().includes('Dept: None') ? '' : $(a).find('.file-meta').text().split('Dept: ')[1];
                const bDept = $(b).find('.file-meta').text().includes('Dept: None') ? '' : $(b).find('.file-meta').text().split('Dept: ')[1];
                return aDept.localeCompare(bDept);
            } else if (sort === 'sub-department') {
                // Assume sub-department files have a department_name and parent_department_id
                const aSub = $(a).data('document-type').includes('Sub-Department') ? $(a).data('file-name') : '';
                const bSub = $(b).data('document-type').includes('Sub-Department') ? $(b).data('file-name') : '';
                return aSub.localeCompare(bSub);
            } else if (sort === 'personal') {
                const aPersonal = $(a).find('.file-meta').text().includes('Dept: None') ? 0 : 1;
                const bPersonal = $(b).find('.file-meta').text().includes('Dept: None') ? 0 : 1;
                return aPersonal - bPersonal;
            }
        });
        grid.empty().append(cards);
        if (!cards.length) grid.append('<p class="no-files">No files found</p>');
    });
});

function loadPersonalFiles(tab) {
    if (state.isLoading) return;
    setLoadingState(true);
    const page = state[tab + 'Page'];
    const fileIds = state[tab + 'FileIds'];
    $.ajax({
        url: 'api/file_operations.php',
        method: 'POST',
        data: {
            action: tab === 'uploaded' ? 'fetch_uploaded_files' : tab === 'received' ? 'fetch_received_files' : 'fetch_shared_files',
            page: page,
            csrf_token: $('meta[name="csrf-token"]').attr('content')
        },
        dataType: 'json',
        success: function(response) {
            const grid = $(`#${tab}Tab`).empty();
            fileIds.clear();
            if (!response.success || response.files.length === 0) {
                grid.append(`<p class="no-files">No ${tab} files</p>`);
            } else {
                response.files.forEach(file => {
                    if (!fileIds.has(file.file_id)) {
                        fileIds.add(file.file_id);
                        const meta = tab === 'received' ?
                            `Type: ${file.document_type || 'Unknown'} | Uploaded: ${new Date(file.upload_date).toLocaleDateString('en-US')} | Sender: ${file.sender_username || 'Unknown'}` :
                            tab === 'shared' ?
                            `Type: ${file.document_type || 'Unknown'} | Uploaded: ${new Date(file.upload_date).toLocaleDateString('en-US')} | Shared With: ${file.shared_with || 'None'}` :
                            `Type: ${file.document_type || 'Unknown'} | Uploaded: ${new Date(file.upload_date).toLocaleDateString('en-US')} | Dept: ${file.department_name || 'None'}`;
                        const menu = tab === 'received' ?
                            `<div class="file-menu hidden">
                                <button class="download-file" onclick="downloadFile(${file.file_id})">Download</button>
                                <button class="file-info" onclick="showFileInfo(${file.file_id})">File Info</button>
                            </div>` :
                            `<div class="file-menu hidden">
                                <button class="download-file" onclick="downloadFile(${file.file_id})">Download</button>
                                <button class="rename-file" onclick="openModal('rename', ${file.file_id}, '${file.file_name}')">Rename</button>
                                <button class="delete-file" onclick="openModal('confirm', ${file.file_id})">Delete</button>
                                <button class="share-file" onclick="openModal('sendFile', ${file.file_id})">Share</button>
                                <button class="file-info" onclick="showFileInfo(${file.file_id})">File Info</button>
                            </div>`;
                        grid.append(`
                            <div class="file-item" data-file-id="${file.file_id}" data-file-name="${file.file_name}" data-document-type="${file.document_type || 'Unknown'}" data-upload-date="${file.upload_date}">
                                <p class="file-name">${file.file_name}</p>
                                <p class="file-meta">${meta}</p>
                                <button class="kebab-menu"><i class="fas fa-ellipsis-v"></i></button>
                                ${menu}
                            </div>
                        `);
                    }
                });
                if (response.files.length >= 100) {
                    grid.append(`<div class="view-more"><button onclick="loadMoreFiles('personal', '${tab}')">View More</button></div>`);
                }
            }
        },
        error: () => {
            $(`#${tab}Tab`).html(`<p class="no-files">Error loading ${tab} files.</p>`);
            notyf.error(`Error loading ${tab} files.`);
        },
        complete: () => setLoadingState(false)
    });
}

function loadMoreFiles(view, tab) {
    if (view === 'personal') {
        state[tab + 'Page']++;
        loadPersonalFiles(tab);
    } else if (view === 'department') {
        state.currentPage++;
        loadDepartmentFiles(state.activeDeptId);
    }
}

        function sortFiles(criteria) {
            const grid = state.activeView === 'personal' ? '#fileGrid' : '#departmentFileGrid';
            const cards = $(grid).find('.file-item').get();
            cards.sort((a, b) => {
                const aValue = $(a).data(criteria === 'date' ? 'upload-date' : criteria);
                const bValue = $(b).data(criteria === 'date' ? 'upload-date' : criteria);
                return criteria === 'date' ? new Date(bValue) - new Date(aValue) : aValue.localeCompare(bValue);
            });
            $(grid).empty().append(cards);
            if (!cards.length) $(grid).append('<p class="no-results">No files found</p>');
        }

        $('.drag-drop-area').on('dragover', function(e) {
            e.preventDefault();
            $(this).addClass('drag-over');
        }).on('dragleave', function() {
            $(this).removeClass('drag-over');
        }).on('drop', function(e) {
            e.preventDefault();
            $(this).removeClass('drag-over');
            $('#fileInput').prop('files', e.originalEvent.dataTransfer.files);
            updateFilePreview(e.originalEvent.dataTransfer.files);
        });

        $('.choose-file-button').click(() => $('#fileInput').click());
        $('#fileInput').change(function() { updateFilePreview(this.files); });

        function updateFilePreview(files) {
            $('#filePreviewArea').empty().append(Array.from(files).map(file => `<p>${file.name}</p>`).join(''));
        }

        if (state.activeView === 'department' && state.activeDeptId) loadDepartmentFiles(state.activeDeptId);
    </script>
</body>
</html>