<?php
session_start();

// Start output buffering to capture any stray output
ob_start();

// Required dependencies with validation
$requiredFiles = ['db_connection.php', 'log_activity.php', 'notification.php', 'vendor/autoload.php', 'phpqrcode/qrlib.php'];
foreach ($requiredFiles as $file) {
    if (!file_exists($file)) {
        error_log("Missing required file: $file");
        http_response_code(500);
        ob_end_clean();
        exit("<html><body><h1>Server Error</h1><p>Missing critical dependency. Please contact the administrator.</p></body></html>");
    }
    require_once $file;
}

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__, ['.env']);
$dotenv->safeLoad();

// Configure error handling
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('error_log', __DIR__ . '/logs/error_log.log');
error_reporting(E_ALL);

// File-based cache configuration
$cacheDir = __DIR__ . '/cache';
if (!file_exists($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}
$cacheTTL = (int)($_ENV['CACHE_TTL'] ?? 300);

/**
 * Sends a JSON response with appropriate HTTP status.
 *
 * @param bool $success
 * @param string $message
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function sendJsonResponse(bool $success, string $message, array $data, int $statusCode): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

/**
 * Stores data in cache.
 *
 * @param string $key
 * @param mixed $value
 * @param int $ttl
 * @return bool
 */
function cacheStore(string $key, $value, int $ttl): bool
{
    global $cacheDir;
    $filename = $cacheDir . '/' . md5($key) . '.cache';
    $data = serialize(['data' => $value, 'expires' => time() + $ttl]);
    return file_put_contents($filename, $data, LOCK_EX) !== false;
}

/**
 * Fetches data from cache.
 *
 * @param string $key
 * @return mixed
 */
function cacheFetch(string $key)
{
    global $cacheDir;
    $filename = $cacheDir . '/' . md5($key) . '.cache';
    if (file_exists($filename)) {
        $content = unserialize(file_get_contents($filename));
        if ($content['expires'] > time()) {
            return $content['data'];
        }
        unlink($filename);
    }
    return false;
}

/**
 * Checks if cache exists and is valid.
 *
 * @param string $key
 * @return bool
 */
function cacheExists(string $key): bool
{
    global $cacheDir;
    $filename = $cacheDir . '/' . md5($key) . '.cache';
    if (file_exists($filename)) {
        $content = unserialize(file_get_contents($filename));
        if ($content['expires'] > time()) {
            return true;
        }
        unlink($filename);
    }
    return false;
}

/**
 * Fetches departments and sub-departments for a user.
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function fetchUserDepartmentsWithSub(PDO $pdo, int $userId): array
{
    $cacheKey = "departments_user_$userId";
    if (cacheExists($cacheKey)) {
        return cacheFetch($cacheKey);
    }
    try {
        $stmt = $pdo->prepare("
            WITH RECURSIVE dept_hierarchy AS (
                SELECT d.department_id, d.department_name, d.parent_department_id, ud.users_department_id
                FROM departments d
                JOIN user_department_assignments ud ON d.department_id = ud.department_id
                WHERE ud.user_id = ?
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

/**
 * Fetches personal files for a user.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param ?int $parentFileId
 * @return array
 */
function fetchUserFiles(PDO $pdo, int $userId, ?int $parentFileId): array
{
    $cacheKey = "user_files_{$userId}_" . ($parentFileId ?: 'null');
    if (cacheExists($cacheKey)) {
        return cacheFetch($cacheKey);
    }
    try {
        $query = "
            SELECT f.file_id, f.file_name, f.upload_date, f.copy_type, 
                   COALESCE(dt.type_name, 'Unknown Type') AS document_type,
                   f.file_path AS physical_storage
            FROM files f
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            WHERE f.user_id = ? 
            " . ($parentFileId ? "AND f.parent_file_id = ?" : "AND f.parent_file_id IS NULL") . "
            ORDER BY f.upload_date DESC
            LIMIT 100
        ";
        $stmt = $pdo->prepare($query);
        $params = [$userId];
        if ($parentFileId) {
            $params[] = $parentFileId;
        }
        $stmt->execute($params);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        cacheStore($cacheKey, $files, $GLOBALS['cacheTTL']);
        return $files;
    } catch (PDOException $e) {
        error_log("Error fetching user files for user {$userId}: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches department files for a user.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param int $departmentId
 * @param ?int $parentFileId
 * @return array
 */
function fetchDepartmentFiles(PDO $pdo, int $userId, int $departmentId, ?int $parentFileId): array
{
    $cacheKey = "dept_files_{$userId}_{$departmentId}_" . ($parentFileId ?: 'null');
    if (cacheExists($cacheKey)) {
        return cacheFetch($cacheKey);
    }
    try {
        $query = "
            SELECT DISTINCT f.file_id, f.file_name, f.upload_date, 
                   f.copy_type, f.file_path AS physical_storage, 
                   COALESCE(dt.type_name, 'Unknown Type') AS document_type
            FROM files f
            JOIN transactions t ON f.file_id = t.file_id
            JOIN user_department_assignments ud ON t.users_department_id = ud.users_department_id
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            WHERE ud.department_id = ? AND ud.user_id = ? 
            AND t.transaction_status = 'completed'
            " . ($parentFileId ? "AND f.parent_file_id = ?" : "AND f.parent_file_id IS NULL") . "
            ORDER BY f.upload_date DESC
            LIMIT 100
        ";
        $stmt = $pdo->prepare($query);
        $params = [$departmentId, $userId];
        if ($parentFileId) {
            $params[] = $parentFileId;
        }
        $stmt->execute($params);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
        cacheStore($cacheKey, $files, $GLOBALS['cacheTTL']);
        return $files;
    } catch (PDOException $e) {
        error_log("Error fetching department files for user {$userId}, dept {$departmentId}: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches notifications for pending file approvals.
 *
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function fetchNotifications(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT t.transaction_id AS id, t.file_id, t.transaction_status AS status, 
                   t.transaction_time AS timestamp, t.description AS message,
                   COALESCE(f.file_name, 'Unknown File') AS file_name,
                   f.file_path, f.copy_type
            FROM transactions t
            LEFT JOIN files f ON t.file_id = f.file_id
            WHERE t.user_id = ? AND t.transaction_status = 'pending'
            ORDER BY t.transaction_time DESC
            LIMIT 10
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching notifications for user {$userId}: " . $e->getMessage());
        return [];
    }
}

/**
 * Returns Font Awesome icon class based on file extension.
 *
 * @param string $fileName
 * @return string
 */
function getFileIcon(string $fileName): string
{
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $iconMap = [
        'pdf' => 'fas fa-file-pdf',
        'doc' => 'fas fa-file-word',
        'docx' => 'fas fa-file-word',
        'xls' => 'fas fa-file-excel',
        'xlsx' => 'fas fa-file-excel',
        'ppt' => 'fas fa-file-powerpoint',
        'pptx' => 'fas fa-file-powerpoint',
        'jpg' => 'fas fa-file-image',
        'png' => 'fas fa-file-image',
        'jpeg' => 'fas fa-file-image',
        'gif' => 'fas fa-file-image',
        'txt' => 'fas fa-file-alt',
        'zip' => 'fas fa-file-archive',
        'rar' => 'fas fa-file-archive',
        'csv' => 'fas fa-file-csv'
    ];
    return $iconMap[$extension] ?? 'fas fa-file';
}

// Fetch document types
try {
    $stmt = $pdo->prepare("SELECT document_type_id, type_name AS name FROM document_types ORDER BY type_name ASC");
    $stmt->execute();
    $docTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching document types: " . $e->getMessage());
    $docTypes = [];
}

try {
    // Validate user session
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
        header('Location: logout.php');
        exit;
    }
    $userId = (int)$_SESSION['user_id'];
    $username = htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8');
    $userRole = htmlspecialchars($_SESSION['role'], ENT_QUOTES, 'UTF-8');
    session_regenerate_id(true);

    // Generate CSRF token
    $csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    global $pdo;

    // Fetch user details
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.role, u.email AS profile_pic, 
               d.department_id, d.department_name
        FROM users u
        LEFT JOIN user_department_assignments ud ON u.user_id = ud.user_id
        LEFT JOIN departments d ON ud.department_id = d.department_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $userDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$userDetails) {
        error_log("User not found for ID: $userId");
        header('Location: logout.php');
        exit;
    }

    // Fetch departments
    $departments = fetchUserDepartmentsWithSub($pdo, $userId);

    // Fetch personal files
    $personalFiles = fetchUserFiles($pdo, $userId, null);

    // Fetch department files for the selected department (if any)
    $selectedDeptId = isset($_GET['dept']) ? (int)$_GET['dept'] : ($departments ? $departments[0]['id'] : null);
    $departmentFiles = [];
    if ($selectedDeptId) {
        $departmentFiles[$selectedDeptId] = fetchDepartmentFiles($pdo, $userId, $selectedDeptId, null);
    }

    // Fetch notifications
    $notifications = fetchNotifications($pdo, $userId);
} catch (Exception $e) {
    error_log("Error in folders.php: " . $e->getMessage());
    sendJsonResponse(false, 'Server error occurred.', [], 500);
}

// Clear output buffer to prevent stray output
ob_end_clean();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Folders - File Management System</title>
    <link rel="stylesheet" href="style/client-sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/noty/3.1.4/noty.min.css">
    <link rel="stylesheet" href="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/noty/3.1.4/noty.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        /* Copied CSS from dashboard.css */
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            background-color: #f4f4f9;
            overflow-x: hidden;
        }

        .sidebar {
            width: 250px;
            background-color: #2c3e50;
            color: white;
            height: 100vh;
            position: fixed;
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .sidebar.hidden {
            transform: translateX(-250px);
        }

        .sidebar .toggle-btn {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            padding: 10px;
            cursor: pointer;
        }

        .sidebar .sidebar-title {
            padding: 20px;
            font-size: 1.5rem;
            text-align: center;
            border-bottom: 1px solid #34495e;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            color: white;
            text-decoration: none;
            transition: background 0.3s;
        }

        .sidebar a:hover {
            background-color: #34495e;
        }

        .sidebar a.active {
            background-color: #1abc9c;
        }

        .sidebar a i {
            margin-right: 10px;
        }

        .sidebar .link-text {
            flex-grow: 1;
        }

        .sidebar .logout-btn {
            position: absolute;
            bottom: 20px;
            width: calc(100% - 40px);
        }

        .main-content {
            margin-left: 250px;
            width: calc(100% - 250px);
            transition: all 0.3s ease;
        }

        .main-content.resized {
            margin-left: 0;
            width: 100%;
        }

        .top-nav {
            background-color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .top-nav.resized {
            margin-left: 0;
            width: 100%;
        }

        .top-nav h2 {
            margin: 0;
            font-size: 1.8rem;
        }

        .search-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .search-bar {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 200px;
        }

        .top-nav button {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .top-nav button:hover {
            background: #0056b3;
        }

        .content-wrapper {
            padding: 20px;
        }

        .view-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .view-tab {
            padding: 10px 20px;
            border: none;
            background: #f4f4f9;
            cursor: pointer;
            border-radius: 4px;
        }

        .view-tab.active {
            background: #007bff;
            color: white;
        }

        .department-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            overflow-x: auto;
        }

        .dept-tab {
            padding: 10px 20px;
            border: none;
            background: #f4f4f9;
            cursor: pointer;
            border-radius: 4px;
            white-space: nowrap;
        }

        .dept-tab.active {
            background: #007bff;
            color: white;
        }

        .sorting-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .sort-btn {
            padding: 8px 16px;
            border: none;
            background: #f4f4f9;
            cursor: pointer;
            border-radius: 4px;
        }

        .sort-btn.active {
            background: #007bff;
            color: white;
        }

        .masonry-grid {
            column-count: 3;
            column-gap: 20px;
        }

        .masonry-section {
            break-inside: avoid;
            margin-bottom: 20px;
        }

        .file-card-container {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }

        .file-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            width: 100%;
        }

        .file-icon-container {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .file-name {
            margin: 0;
            font-weight: bold;
        }

        .file-type-badge {
            background: #e9ecef;
            padding: 5px 10px;
            border-radius: 12px;
            display: inline-block;
            margin: 5px 0;
        }

        .file-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }

        .file-actions button {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
        }

        .no-results {
            text-align: center;
            color: #666;
        }

        .view-more {
            text-align: center;
            margin-top: 20px;
        }

        .view-more button {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }

        .modal.open {
            display: flex;
        }

        .modal-content {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            max-width: 500px;
            width: 100%;
            position: relative;
        }

        .close-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
        }

        .modal-content h2 {
            margin-top: 0;
        }

        .modal-content label {
            display: block;
            margin: 10px 0 5px;
        }

        .modal-content input[type="text"],
        .modal-content select,
        .modal-content input[type="file"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .modal-content button[type="submit"] {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
        }

        .confirm-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .confirm-buttons button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .confirm-buttons button:first-child {
            background: #dc3545;
            color: white;
        }

        .confirm-buttons button:last-child {
            background: #6c757d;
            color: white;
        }

        .file-info-sidebar {
            position: fixed;
            right: 0;
            top: 0;
            width: 300px;
            height: 100vh;
            background: white;
            box-shadow: -2px 0 4px rgba(0,0,0,0.1);
            padding: 20px;
            transform: translateX(300px);
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .file-info-sidebar.active {
            transform: translateX(0);
        }

        .file-name-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .file-name-title {
            margin: 0;
        }

        .close-sidebar-btn {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
        }

        .file-preview {
            margin-bottom: 15px;
        }

        .file-info-header {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .info-tab {
            padding: 10px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
        }

        .info-tab.active {
            border-bottom: 2px solid #007bff;
        }

        .info-section {
            display: none;
        }

        .info-section.active {
            display: block;
        }

        .file-details .info-item {
            margin-bottom: 10px;
        }

        .info-label {
            font-weight: bold;
            display: inline-block;
            width: 100px;
        }

        .info-value {
            display: inline-block;
        }

        .full-preview-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 3000;
        }

        .full-preview-modal.open {
            display: flex;
        }

        .full-preview-content {
            max-width: 90%;
            max-height: 90%;
            background: white;
            padding: 20px;
            border-radius: 8px;
            position: relative;
        }

        .close-full-preview {
            position: absolute;
            top: 10px;
            right: 10px;
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
        }

        .loading-spinner {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            border: 4px solid #f3f3f3;
            border-top: 4px solid #007bff;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }

        .loading-spinner.active {
            display: block;
        }

        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-250px);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .sidebar .toggle-btn {
                display: block;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .main-content.resized {
                margin-left: 250px;
                width: calc(100% - 250px);
            }

            .masonry-grid {
                column-count: 1;
            }
        }

        /* Additional styles for QR scanner */
        .progress-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .progress-step {
            flex: 1;
            text-align: center;
            padding: 10px;
            background: #f4f4f9;
            border-radius: 4px;
        }

        .progress-step.active {
            background: #007bff;
            color: white;
        }

        .modal-step {
            display: block;
        }

        .modal-step.hidden {
            display: none;
        }

        .drag-drop-area {
            border: 2px dashed #ddd;
            padding: 20px;
            text-align: center;
            margin-bottom: 15px;
        }

        .choose-file-button {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        #filePreviewArea {
            margin-bottom: 15px;
        }

        .modal-content input[type="checkbox"],
        .modal-content input[type="radio"] {
            margin-right: 5px;
        }

        .result {
            color: green;
            margin: 10px 0;
            word-break: break-all;
        }

        .error {
            color: red;
            margin: 10px 0;
        }

        #reader {
            width: 100%;
            max-width: 400px;
            margin: 10px 0;
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
        <a href="folders.php" class="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']) === 'folders.php' ? 'active' : '') ?>" data-tooltip="My Folder" aria-label="My Folder">
            <i class="fas fa-folder"></i><span class="link-text">My Folder</span>
        </a>
        <a href="logout.php" class="logout-btn" data-tooltip="Logout" aria-label="Logout">
            <i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span>
        </a>
    </aside>

    <div class="main-content <?php echo $userRole === 'admin' ? '' : 'resized'; ?>">
        <div class="top-nav <?php echo $userRole === 'admin' ? '' : 'resized'; ?>">
            <button class="toggle-btn"><i class="fas fa-bars"></i></button>
            <h2>My Folders</h2>
            <div class="search-container">
                <input type="text" class="search-bar" id="searchInput" placeholder="Search files...">
            </div>
            <button onclick="openModal('upload')"><i class="fas fa-upload"></i> Upload File</button>
            <button onclick="openModal('scanQR')"><i class="fas fa-qrcode"></i> Scan QR</button>
        </div>

        <div class="content-wrapper">
            <div class="view-tabs">
                <div class="view-tab <?php echo !isset($_GET['dept']) ? 'active' : ''; ?>" data-view="personal">Personal Files</div>
                <div class="view-tab <?php echo isset($_GET['dept']) ? 'active' : ''; ?>" data-view="department">Department Files</div>
            </div>

            <?php if (empty($departments)): ?>
                <div class="no-departments-message">
                    You are not assigned to any department. Contact the administrator.
                </div>
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
                <button class="sort-btn" data-criteria="name">Sort by Name</button>
                <button class="sort-btn" data-criteria="type">Sort by Type</button>
                <button class="sort-btn" data-criteria="date">Sort by Date</button>
            </div>

            <div class="masonry-grid">
                <div class="masonry-section" id="personalFilesSection" style="display: <?php echo !isset($_GET['dept']) ? 'block' : 'none'; ?>;">
                    <h3>Personal Files</h3>
                    <div class="file-card-container" id="fileGrid">
                        <?php if (empty($personalFiles)): ?>
                            <p class="no-results">No personal files found</p>
                        <?php else: ?>
                            <?php foreach ($personalFiles as $file): ?>
                                <div class="file-card" data-file-id="<?php echo $file['file_id']; ?>" data-file-name="<?php echo htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8'); ?>" data-document-type="<?php echo htmlspecialchars($file['document_type'], ENT_QUOTES, 'UTF-8'); ?>" data-upload-date="<?php echo $file['upload_date']; ?>">
                                    <div class="file-icon-container">
                                        <i class="<?php echo getFileIcon($file['file_name']); ?>"></i>
                                    </div>
                                    <p class="file-name"><?php echo htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <p class="file-type-badge"><?php echo htmlspecialchars($file['document_type'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <p><?php echo date('m/d/Y', strtotime($file['upload_date'])); ?></p>
                                    <div class="file-actions">
                                        <button class="info-btn" title="Info" onclick="showFileInfo(<?php echo $file['file_id']; ?>)"><i class="fas fa-info-circle"></i></button>
                                        <button class="rename-btn" title="Rename" onclick="openModal('rename', <?php echo $file['file_id']; ?>, '<?php echo htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8'); ?>')"><i class="fas fa-edit"></i></button>
                                        <button class="delete-btn" title="Delete" onclick="openModal('confirm', <?php echo $file['file_id']; ?>)"><i class="fas fa-trash"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (count($personalFiles) >= 100): ?>
                        <div class="view-more">
                            <button onclick="loadMoreFiles('personal')">View More</button>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="masonry-section" id="departmentFilesSection" style="display: <?php echo isset($_GET['dept']) ? 'block' : 'none'; ?>;">
                    <h3>Department Files</h3>
                    <div class="file-card-container" id="departmentFileGrid">
                        <?php if (isset($_GET['dept']) && !empty($departmentFiles[$selectedDeptId])): ?>
                            <?php foreach ($departmentFiles[$selectedDeptId] as $file): ?>
                                <div class="file-card" data-file-id="<?php echo $file['file_id']; ?>" data-file-name="<?php echo htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8'); ?>" data-document-type="<?php echo htmlspecialchars($file['document_type'], ENT_QUOTES, 'UTF-8'); ?>" data-upload-date="<?php echo $file['upload_date']; ?>">
                                    <div class="file-icon-container">
                                        <i class="<?php echo getFileIcon($file['file_name']); ?>"></i>
                                    </div>
                                    <p class="file-name"><?php echo htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <p class="file-type-badge"><?php echo htmlspecialchars($file['document_type'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <p><?php echo date('m/d/Y', strtotime($file['upload_date'])); ?></p>
                                    <div class="file-actions">
                                        <button class="info-btn" title="Info" onclick="showFileInfo(<?php echo $file['file_id']; ?>)"><i class="fas fa-info-circle"></i></button>
                                        <button class="rename-btn" title="Rename" onclick="openModal('rename', <?php echo $file['file_id']; ?>, '<?php echo htmlspecialchars($file['file_name'], ENT_QUOTES, 'UTF-8'); ?>')"><i class="fas fa-edit"></i></button>
                                        <button class="delete-btn" title="Delete" onclick="openModal('confirm', <?php echo $file['file_id']; ?>)"><i class="fas fa-trash"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($departmentFiles[$selectedDeptId]) >= 100): ?>
                                <div class="view-more">
                                    <button onclick="loadMoreFiles('department')">View More</button>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="no-results">No files found for this department</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modals -->
        <div class="modal" id="uploadModal">
            <div class="modal-content">
                <button class="close-btn" onclick="closeModal('upload')"><i class="fas fa-times"></i></button>
                <h2>Upload File</h2>
                <div class="progress-bar">
                    <div class="progress-step active" data-step="1">1. Select File</div>
                    <div class="progress-step" data-step="2">2. Details</div>
                </div>
                <form id="uploadForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-step" data-step="1">
                        <div class="drag-drop-area">
                            <p>Drag & Drop files here or</p>
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
                                    <option value="<?= htmlspecialchars($dept['id']) ?>">
                                        <?= htmlspecialchars($dept['name']) ?>
                                    </option>
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
                                <option value="<?= htmlspecialchars($doc['document_type_id']) ?>">
                                    <?= htmlspecialchars($doc['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="dynamicFields"></div>
                        <label><input type="checkbox" id="hardcopyCheckbox" name="is_hardcopy"> This is a hardcopy</label>
                        <div id="hardcopyOptions" class="hidden">
                            <label><input type="radio" name="hardcopyOption" id="hardcopyOptionNew" value="new" checked> New Hardcopy</label>
                            <label><input type="radio" name="hardcopyOption" value="existing"> Existing Hardcopy</label>
                            <label for="hardcopyFileName">Hardcopy File Name</label>
                            <input type="text" id="hardcopyFileName" name="hardcopy_file_name" placeholder="Enter file name">
                            <div id="storageSuggestion" class="hidden"></div>
                            <div id="hardcopySearchContainer" class="hidden">
                                <label for="physicalStorage">Physical Storage Location</label>
                                <input type="text" id="physicalStorage" name="physical_storage" placeholder="Search storage location">
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
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
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
                    <button onclick="deleteFile($('#confirmModal').data('file-id'))">Yes, Delete</button>
                    <button onclick="closeModal('confirm')">Cancel</button>
                </div>
            </div>
        </div>

        <div class="modal" id="scanQRModal">
            <div class="modal-content">
                <button class="close-btn" onclick="closeModal('scanQR')"><i class="fas fa-times"></i></button>
                <h2>Scan QR Code</h2>
                <div id="reader" style="width: 100%; max-width: 400px; margin: 10px 0;"></div>
                <input type="file" id="qr-input-file" accept="image/*">
                <button onclick="startScanner()">Start Webcam Scan</button>
                <button onclick="stopScanner()">Stop Webcam Scan</button>
                <div id="result" class="result"></div>
                <div id="error" class="error"></div>
            </div>
        </div>

        <div class="modal" id="fileInfoModal">
            <div class="modal-content">
                <button class="close-btn" onclick="closeModal('fileInfo')"><i class="fas fa-times"></i></button>
                <h2>File Information</h2>
                <div id="fileInfoContent"></div>
            </div>
        </div>

        <div class="file-info-sidebar" id="fileInfoSidebar">
            <div class="file-name-container">
                <h2 class="file-name-title" id="infoFileName"></h2>
                <button class="close-sidebar-btn" onclick="closeFileInfo()"><i class="fas fa-times"></i></button>
            </div>
            <div class="file-preview" id="filePreview"></div>
            <div class="file-info-header">
                <div class="info-tab active" data-tab="details">Details</div>
                <div class="info-tab" data-tab="activity">Activity</div>
            </div>
            <div class="info-section active" id="detailsSection">
                <div class="file-details">
                    <h3>File Details</h3>
                    <div class="info-item">
                        <span class="info-label">Type</span>
                        <span class="info-value" id="infoFileType"></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Size</span>
                        <span class="info-value" id="infoFileSize"></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Category</span>
                        <span class="info-value" id="infoFileCategory"></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Uploader</span>
                        <span class="info-value" id="infoUploader"></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Upload Date</span>
                        <span class="info-value" id="infoUploadDate"></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Physical Storage</span>
                        <span class="info-value" id="infoPhysicalStorage"></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Access</span>
                        <span class="info-value" id="infoAccess"></span>
                    </div>
                    <div id="infoQRCode"></div>
                </div>
            </div>
            <div class="info-section" id="activitySection">
                <div class="access-log">
                    <h3>Activity Log</h3>
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

        <div class="loading-spinner"></div>
    </div>

    <script>
        const notyf = new Noty({
            theme: 'metroui',
            timeout: 3000,
            progressBar: true
        });

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
                $('.main-content').toggleClass('resized');
                $('.top-nav').toggleClass('resized');
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
                if (state.activeView === 'department' && state.activeDeptId) {
                    loadDepartmentFiles(state.activeDeptId);
                } else if (state.activeView === 'department') {
                    $('.dept-tab').first().addClass('active');
                    state.activeDeptId = $('.dept-tab').first().data('dept-id') || '';
                    loadDepartmentFiles(state.activeDeptId);
                } else {
                    $('#fileGrid').find('.file-card').show();
                    $('#searchInput').val('');
                }
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
                $(grid).find('.file-card').each(function() {
                    const fileName = $(this).data('file-name').toLowerCase();
                    $(this).toggle(fileName.includes(query));
                });
                $(grid).find('.no-results').toggle($(grid).find('.file-card:visible').length === 0);
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
                const isHardcopy = $('#hardcopyCheckbox').is(':checked');
                const hardcopyOption = $('input[name="hardcopyOption"]:checked').val();

                if (isHardcopy && hardcopyOption === 'new' && !$('#hardcopyFileName').val()) {
                    notyf.error('Hardcopy file name is required.');
                    setLoadingState(false);
                    return;
                }

                $.ajax({
                    url: 'upload.php',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            notyf.success(response.message);
                            if (response.qr_path) {
                                const qrImage = `<p>Generated QR Code:</p><img src="${response.qr_path}" alt="QR Code" style="max-width: 200px;">`;
                                $('#filePreviewArea').html(qrImage);
                            }
                            closeModal('upload');
                            setTimeout(() => location.reload(), 2000);
                        } else {
                            notyf.error(response.message || 'Failed to upload file.');
                        }
                    },
                    error: function() {
                        notyf.error('Error uploading file.');
                    },
                    complete: function() {
                        setLoadingState(false);
                    }
                });
            });

            $('#renameForm').submit(function(e) {
                e.preventDefault();
                if (state.isLoading) return;
                setLoadingState(true);
                $.ajax({
                    url: 'rename_file.php',
                    method: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            notyf.success(response.message);
                            closeModal('rename');
                            location.reload();
                        } else {
                            notyf.error(response.message || 'Failed to rename file.');
                        }
                    },
                    error: function() {
                        notyf.error('Error renaming file.');
                    },
                    complete: function() {
                        setLoadingState(false);
                    }
                });
            });

            // Progress steps
            $('.next-step').on('click', function() {
                $('.modal-step[data-step="1"]').addClass('hidden');
                $('.modal-step[data-step="2"]').removeClass('hidden');
                $('.progress-step[data-step="1"]').removeClass('active');
                $('.progress-step[data-step="2"]').addClass('active');
            });

            $('.prev-step').on('click', function() {
                $('.modal-step[data-step="2"]').addClass('hidden');
                $('.modal-step[data-step="1"]').removeClass('hidden');
                $('.progress-step[data-step="2"]').removeClass('active');
                $('.progress-step[data-step="1"]').addClass('active');
            });

            // Hardcopy options
            $('#hardcopyCheckbox').on('change', function() {
                $('#hardcopyOptions').toggleClass('hidden', !this.checked);
                $('#fileInput').prop('disabled', this.checked);
                $('#hardcopyFileName').prop('disabled', !this.checked);
            });

            $('input[name="hardcopyOption"]').on('change', function() {
                const isExisting = $(this).val() === 'existing';
                $('#hardcopySearchContainer').toggleClass('hidden', !isExisting);
                $('#hardcopyFileName').toggleClass('hidden', isExisting);
                $('#storageSuggestion').toggleClass('hidden', isExisting);
            });

            // Department and sub-department handling
            $('#accessLevel').on('change', function() {
                $('#departmentContainer').toggleClass('hidden', this.value === 'personal');
            });

            $('#departmentSelect').on('change', function() {
                const deptId = $(this).val();
                if (deptId) {
                    $.ajax({
                        url: 'get_sub_departments.php',
                        type: 'GET',
                        data: { department_id: deptId },
                        dataType: 'json',
                        success: function(data) {
                            $('#subDepartmentSelect').html('<option value="">No Sub-Department</option>');
                            data.forEach(subDept => {
                                $('#subDepartmentSelect').append(
                                    `<option value="${subDept.department_id}">${subDept.department_name}</option>`
                                );
                            });
                        },
                        error: function() {
                            notyf.error('Error fetching sub-departments.');
                        }
                    });
                } else {
                    $('#subDepartmentSelect').html('<option value="">No Sub-Department</option>');
                }
            });
        });

        function setLoadingState(isLoading) {
            state.isLoading = isLoading;
            $('.loading-spinner').toggleClass('active', isLoading);
        }

        function showAlert(message, type) {
            notyf.open({
                type: type,
                message: message
            });
        }

        function openModal(modalId, fileId = null, fileName = '') {
            state.activeModal = modalId;
            $(`#${modalId}Modal`).addClass('open');
            if (modalId === 'rename' && fileId) {
                $('#renameFileId').val(fileId);
                $('#newFileName').val(fileName);
            } else if (modalId === 'confirm' && fileId) {
                $('#confirmModal').data('file-id', fileId);
            }
        }

        function closeModal(modalId) {
            $(`#${modalId}Modal`).removeClass('open');
            state.activeModal = null;
            if (modalId === 'scanQR') {
                stopScanner();
            }
        }

        function startScanner() {
            document.getElementById('result').innerText = '';
            document.getElementById('error').innerText = '';

            html5QrcodeScanner = new Html5Qrcode("reader");
            const config = { fps: 10, qrbox: { width: 250, height: 250 } };

            html5QrcodeScanner.start(
                { facingMode: "environment" },
                config,
                onScanSuccess,
                onScanFailure
            ).catch(err => {
                document.getElementById('error').innerText = `Error starting scanner: ${err}`;
            });
        }

        function stopScanner() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.stop().then(() => {
                    html5QrcodeScanner.clear();
                }).catch(err => {
                    document.getElementById('error').innerText = `Error stopping scanner: ${err}`;
                });
            }
        }

        function onScanSuccess(decodedText, decodedResult) {
            document.getElementById('result').innerText = `Scanned: ${decodedText}`;
            document.getElementById('error').innerText = '';

            if (decodedText.startsWith('file_id:')) {
                const fileId = decodedText.replace('file_id:', '').trim();
                fetchFileInfo(fileId);
            }

            stopScanner();
        }

        function onScanFailure(error) {
            console.warn(`Scan error: ${error}`);
        }

        function fetchFileInfo(fileId) {
            if (state.isLoading) return;
            setLoadingState(true);
            $.ajax({
                url: 'get_file_info.php',
                method: 'GET',
                data: { id: fileId },
                dataType: 'json',
                success: function(response) {
                    if (response.error) {
                        document.getElementById('error').innerText = response.error;
                    } else {
                        const content = `
                            <p><strong>File Name:</strong> ${response.file_name}</p>
                            <p><strong>Upload Date:</strong> ${response.upload_date}</p>
                            <p><strong>Copy Type:</strong> ${response.copy_type}</p>
                            <p><strong>Document Type:</strong> ${response.document_type || 'N/A'}</p>
                            <p><strong>Department:</strong> ${response.department_name || 'N/A'}</p>
                            <p><strong>File Type:</strong> ${response.file_type || 'N/A'}</p>
                            <p><strong>File Size:</strong> ${response.file_size ? (response.file_size / 1024).toFixed(2) + ' KB' : 'N/A'}</p>
                            ${response.qr_path ? `<p><strong>QR Code:</strong><br><img src="${response.qr_path}" alt="QR Code" style="max-width: 200px;"></p>` : ''}
                            ${response.file_path ? `<p><strong>File Path:</strong> ${response.file_path}</p>` : ''}
                        `;
                        document.getElementById('fileInfoContent').innerHTML = content;
                        document.getElementById('fileInfoModal').classList.add('open');
                    }
                },
                error: function(err) {
                    document.getElementById('error').innerText = `Error fetching file info: ${err.statusText}`;
                },
                complete: function() {
                    setLoadingState(false);
                }
            });
        }

        // QR input file handling
        document.getElementById('qr-input-file').addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                document.getElementById('result').innerText = '';
                document.getElementById('error').innerText = '';

                const html5Qrcode = new Html5Qrcode("reader");
                html5Qrcode.scanFile(file, true)
                    .then(decodedText => {
                        document.getElementById('result').innerText = `Scanned: ${decodedText}`;
                        if (decodedText.startsWith('file_id:')) {
                            const fileId = decodedText.replace('file_id:', '').trim();
                            fetchFileInfo(fileId);
                        }
                    })
                    .catch(err => {
                        document.getElementById('error').innerText = `Error scanning file: ${err}`;
                    });
            }
        });

        function showFileInfo(fileId) {
            if (state.isLoading) return;
            setLoadingState(true);
            $.ajax({
                url: 'get_file_details.php',
                method: 'POST',
                data: {
                    file_id: fileId,
                    csrf_token: $('meta[name="csrf-token"]').attr('content')
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        $('#infoFileName').text(data.file_name || 'N/A');
                        $('#infoFileType').text(data.document_type || 'N/A');
                        $('#infoFileSize').text(data.file_size || 'N/A');
                        $('#infoFileCategory').text(data.document_type || 'N/A');
                        $('#infoUploader').text(data.uploader || 'N/A');
                        $('#infoUploadDate').text(data.upload_date ? new Date(data.upload_date).toLocaleDateString('en-US') : 'N/A');
                        $('#infoPhysicalStorage').text(data.physical_storage || 'None');
                        $('#infoAccess').text(data.access_info || 'N/A');
                        $('#infoQRCode').empty();
                        if (data.physical_storage && data.physical_storage !== 'None') {
                            new QRCode(document.getElementById('infoQRCode'), {
                                text: `file_id:${fileId}`,
                                width: 100,
                                height: 100
                            });
                        }
                        $('#fileHistory').html(data.history ? data.history.map(h => `<p>${h.action} on ${new Date(h.timestamp).toLocaleString('en-US')}</p>`).join('') : '<p>No history available</p>');
                        $('#filePreview').empty();
                        if (data.copy_type === 'soft_copy' && data.file_path && data.file_path !== 'None') {
                            const ext = data.file_path.split('.').pop().toLowerCase();
                            if (ext === 'pdf') {
                                $('#filePreview').html(`<iframe src="${data.file_path}" title="File Preview" style="width: 100%; height: 200px;"></iframe>`);
                            } else if (['jpg', 'png', 'jpeg', 'gif'].includes(ext)) {
                                $('#filePreview').html(`<img src="${data.file_path}" alt="File Preview" style="max-width: 100%; max-height: 200px;">`);
                            } else {
                                $('#filePreview').html('<p>Preview not available for this file type</p>');
                            }
                        } else if (data.copy_type === 'hard_copy') {
                            $('#filePreview').html('<p>Hardcopy - No digital preview available</p>');
                        } else {
                            $('#filePreview').html('<p>No preview available</p>');
                        }
                        $('#fileInfoSidebar').addClass('active');
                        $('.info-tab[data-tab="details"]').addClass('active');
                        $('.info-tab[data-tab="activity"]').removeClass('active');
                        $('#detailsSection').addClass('active');
                        $('#activitySection').removeClass('active');
                    } else {
                        notyf.error(response.message || 'Error fetching file information.');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('File info AJAX error:', textStatus, errorThrown);
                    notyf.error('Failed to load file information. Please try again.');
                },
                complete: function() {
                    setLoadingState(false);
                }
            });
        }

        function closeFileInfo() {
            $('#fileInfoSidebar').removeClass('active');
            $('#filePreview').empty();
            $('#infoQRCode').empty();
        }

        function openFullPreview(fileId) {
            $('#fullPreviewModal').data('file-id', fileId).addClass('open');
            const preview = $('#fullFilePreview');
            $.ajax({
                url: 'get_file_details.php',
                method: 'POST',
                data: {
                    file_id: fileId,
                    csrf_token: $('meta[name="csrf-token"]').attr('content')
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        if (data.copy_type === 'soft_copy' && data.file_path && data.file_path !== 'None') {
                            const ext = data.file_path.split('.').pop().toLowerCase();
                            if (ext === 'pdf') {
                                preview.html(`<iframe src="${data.file_path}" title="File Preview" style="width: 100%; height: 80vh;"></iframe>`);
                            } else if (['jpg', 'png', 'jpeg', 'gif'].includes(ext)) {
                                preview.html(`<img src="${data.file_path}" alt="File Preview" style="max-width: 100%; max-height: 80vh;">`);
                            } else {
                                preview.html('<p>Preview not available for this file type</p>');
                            }
                        } else if (data.copy_type === 'hard_copy') {
                            preview.html('<p>Hardcopy - No digital preview available</p>');
                        } else {
                            preview.html('<p>No preview available</p>');
                        }
                    } else {
                        notyf.error(response.message || 'Error fetching file preview.');
                    }
                },
                error: function() {
                    notyf.error('Error fetching file preview.');
                }
            });
        }

        function closeFullPreview() {
            $('#fullPreviewModal').removeClass('open');
            $('#fullFilePreview').empty();
        }

        function handleAccessRequest(fileId, action) {
            setLoadingState(true);
            $.ajax({
                url: 'handle_access_request.php',
                method: 'POST',
                data: JSON.stringify({
                    file_id: fileId,
                    action: action,
                    csrf_token: $('meta[name="csrf-token"]').attr('content')
                }),
                contentType: 'application/json',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        notyf.success(response.message);
                        $('#notificationList').find(`[data-transaction-id="${response.transaction_id}"]`).remove();
                        location.reload();
                    } else {
                        notyf.error(response.message || 'Error handling access request.');
                    }
                },
                error: function() {
                    notyf.error('Error handling access request.');
                },
                complete: function() {
                    setLoadingState(false);
                }
            });
        }

        function deleteFile(fileId) {
            setLoadingState(true);
            $.ajax({
                url: 'delete_file.php',
                method: 'POST',
                headers: {
                    'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content')
                },
                data: JSON.stringify({
                    file_id: fileId
                }),
                contentType: 'application/json',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        notyf.success(response.message);
                        location.reload();
                    } else {
                        notyf.error(response.message || 'Failed to delete file.');
                    }
                },
                error: function() {
                    notyf.error('Error deleting file.');
                },
                complete: function() {
                    setLoadingState(false);
                }
            });
        }

        function loadDepartmentFiles(deptId) {
            if (!deptId) {
                $('#departmentFileGrid').html('<p class="no-results">Select a department to view files</p>');
                return;
            }
            setLoadingState(true);
            $.ajax({
                url: 'get_department_files.php',
                method: 'POST',
                data: {
                    department_id: deptId,
                    page: state.currentPage,
                    csrf_token: $('meta[name="csrf-token"]').attr('content')
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const files = response.data;
                        const grid = $('#departmentFileGrid').empty();
                        state.loadedFileIds.clear();
                        if (files.length === 0) {
                            grid.append('<p class="no-results">No files found for this department</p>');
                        } else {
                            files.forEach(file => {
                                if (!state.loadedFileIds.has(file.file_id)) {
                                    state.loadedFileIds.add(file.file_id);
                                    const card = $(`
                                        <div class="file-card" data-file-id="${file.file_id}" data-file-name="${file.file_name}" data-document-type="${file.document_type}" data-upload-date="${file.upload_date}">
                                            <div class="file-icon-container">
                                                <i class="${getFileIcon(file.file_name)}"></i>
                                            </div>
                                            <p class="file-name">${file.file_name}</p>
                                            <p class="file-type-badge">${file.document_type}</p>
                                            <p>${new Date(file.upload_date).toLocaleDateString('en-US')}</p>
                                            <div class="file-actions">
                                                <button class="info-btn" title="Info" onclick="showFileInfo(${file.file_id})"><i class="fas fa-info-circle"></i></button>
                                                <button class="rename-btn" title="Rename" onclick="openModal('rename', ${file.file_id}, '${file.file_name}')"><i class="fas fa-edit"></i></button>
                                                <button class="delete-btn" title="Delete" onclick="openModal('confirm', ${file.file_id})"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </div>
                                    `);
                                    grid.append(card);
                                }
                            });
                            if (files.length >= 100) {
                                grid.append('<div class="view-more"><button onclick="loadMoreFiles(\'department\')">View More</button></div>');
                            }
                        }
                    } else {
                        notyf.error(response.message || 'Error fetching department files.');
                    }
                },
                error: function() {
                    notyf.error('Error fetching department files.');
                },
                complete: function() {
                    setLoadingState(false);
                }
            });
        }

        function sortFiles(criteria) {
            const grid = state.activeView === 'personal' ? $('#fileGrid') : $('#departmentFileGrid');
            const cards = grid.find('.file-card').get();
            cards.sort((a, b) => {
                const aVal = $(a).data(criteria === 'name' ? 'file-name' : criteria === 'type' ? 'document-type' : 'upload-date');
                const bVal = $(b).data(criteria === 'name' ? 'file-name' : criteria === 'type' ? 'document-type' : 'upload-date');
                if (criteria === 'date') {
                    return new Date(bVal) - new Date(aVal);
                }
                return aVal.localeCompare(bVal);
            });
            grid.empty().append(cards);
            grid.find('.no-results').toggle(cards.length === 0);
        }

        function loadMoreFiles(view) {
            state.currentPage++;
            const url = view === 'personal' ? 'get_user_files.php' : 'get_department_files.php';
            const data = view === 'personal' ? {
                page: state.currentPage,
                csrf_token: $('meta[name="csrf-token"]').attr('content')
            } : {
                department_id: state.activeDeptId,
                page: state.currentPage,
                csrf_token: $('meta[name="csrf-token"]').attr('content')
            };
            const grid = view === 'personal' ? $('#fileGrid' : '#departmentFileGrid';
            $.ajax({
                url: url,
                method: 'POST',
                data: data,
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data.length > 0) {
                        response.data.forEach(file => {
                            if (!state.loadedFileIds.has(file.file_id)) {
                                state.loadedFileIds.add(file.file_id);
                                const card = $(`
                                    <div class="file-card" data-file-id="${file.file_id}" data-file-name="${file.file_name}" data-document-type="${file.document_type}" data-upload-date="${file.upload_date}">
                                        <div class="file-icon-container">
                                            <i class="${getFileIcon(file.file_name)}"></i>
                                        </div>
                                        <p class="file-name">${file.file_name}</p>
                                        <p class="file-type-badge">${file.document_type}</p>
                                        <p>${new Date(file.upload_date).toLocaleDateString('en-US')}</p>
                                        <div class="file-actions">
                                            <button class="info-btn" title="Info" onclick="showFileInfo(${file.file_id})"><i class="fas fa-info-circle"></i></button>
                                            <button class="rename-btn" title="Rename" onclick="openModal('rename', ${file.file_id}, '${file.file_name}')"><i class="fas fa-edit"></i></button>
                                            <button class="delete-btn" title="Delete" onclick="openModal('confirm', ${file.file_id})"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </div>
                                `);
                                grid.append(card);
                            }
                        });
                    } else {
                        $('.view-more').hide();
                        notyf.info('No more files to load.');
                    }
                },
                error: function() {
                    notyf.error('Error loading more files.');
                }
            });
        }

        function getFileIcon(fileName) {
            const extension = fileName.split('.').pop().toLowerCase();
            const iconMap = {
                'pdf': 'fas fa-file-pdf',
                'doc': 'fas fa-file-word',
                'docx': 'fas fa-file-word',
                'xls': 'fas fa-file-excel',
                'xlsx': 'fas fa-file-excel',
                'ppt': 'fas fa-file-powerpoint',
                'pptx': 'fas fa-file-powerpoint',
                'jpg': 'fas fa-file-image',
                'png': 'fas fa-file-image',
                'jpeg': 'fas fa-file-image',
                'gif': 'fas fa-file-image',
                'txt': 'fas fa-file-alt',
                'zip': 'fas fa-file-archive',
                'rar': 'fas fa-file-archive',
                'csv': 'fas fa-file-csv'
            };
            return iconMap[extension] || 'fas fa-file';
        }
    </script>
</body>

</html>