<?php
session_start();

// Required dependencies with validation
$requiredFiles = ['db_connection.php'];
foreach ($requiredFiles as $file) {
    if (!file_exists($file)) {
        error_log("Missing required file: $file");
        http_response_code(500);
        exit(json_encode([
            'success' => false,
            'message' => 'Server error: Missing critical dependency.'
        ]));
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
 * Fetches file details including access information and activity history.
 *
 * @param PDO $pdo
 * @param int $fileId
 * @param int $userId
 * @return array
 */
function fetchFileDetails(PDO $pdo, int $fileId, int $userId): array
{
    $cacheKey = "file_details_{$fileId}_{$userId}";
    if (cacheExists($cacheKey)) {
        return cacheFetch($cacheKey);
    }

    try {
        // Fetch file details
        $stmt = $pdo->prepare("
            SELECT f.file_id, f.file_name, f.file_path, f.copy_type, f.upload_date, 
                   COALESCE(dt.type_name, 'Unknown Type') AS document_type,
                   u.username AS uploader,
                   f.access_level
            FROM files f
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            LEFT JOIN users u ON f.user_id = u.user_id
            WHERE f.file_id = ? AND f.user_id = ?
        ");
        $stmt->execute([$fileId, $userId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            sendJsonResponse(false, 'File not found or access denied.', [], 403);
        }

        // Fetch access information (users and departments)
        $accessStmt = $pdo->prepare("
            SELECT DISTINCT COALESCE(u.username, d.department_name) AS access_entity
            FROM files f
            LEFT JOIN users u ON f.user_id = u.user_id
            LEFT JOIN users_department ud ON f.department_id = ud.department_id OR f.sub_department_id = ud.department_id
            LEFT JOIN departments d ON ud.department_id = d.department_id
            WHERE f.file_id = ? AND f.access_level IN ('sub_department', 'college')
        ");
        $accessStmt->execute([$fileId]);
        $accessList = $accessStmt->fetchAll(PDO::FETCH_COLUMN);
        $accessInfo = $accessList ? implode(', ', $accessList) : ($file['access_level'] === 'personal' ? 'Personal' : 'None');

        // Fetch file activity history
        $historyStmt = $pdo->prepare("
            SELECT t.transaction_id, t.transaction_status, t.transaction_time, t.description,
                   COALESCE(u.username, 'System') AS actor,
                   d.department_name AS target_department
            FROM transactions t
            LEFT JOIN users u ON t.user_id = u.user_id
            LEFT JOIN departments d ON t.users_department_id = d.department_id
            WHERE t.file_id = ?
            ORDER BY t.transaction_time DESC
            LIMIT 50
        ");
        $historyStmt->execute([$fileId]);
        $historyRaw = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        // Process activity history
        $history = array_map(function ($entry) {
            $action = '';
            switch ($entry['description']) {
                case strpos($entry['description'], 'sent') !== false:
                    $action = "Sent to " . ($entry['target_department'] ?? $entry['actor']);
                    break;
                case strpos($entry['description'], 'received') !== false:
                    $action = "Received by " . ($entry['target_department'] ?? $entry['actor']);
                    break;
                case strpos($entry['description'], 'copied') !== false:
                    $action = "Copied by " . ($entry['target_department'] ?? $entry['actor']);
                    break;
                case strpos($entry['description'], 'renamed') !== false:
                    $action = "Renamed to '" . ($entry['description'] ? substr($entry['description'], strpos($entry['description'], ':') + 2) : 'Unknown') . "'";
                    break;
                default:
                    $action = $entry['description'] ?: 'Unknown action';
            }
            return [
                'action' => $action,
                'timestamp' => $entry['transaction_time']
            ];
        }, $historyRaw);

        // Calculate file size (if soft copy and file exists)
        $fileSize = 'N/A';
        if ($file['copy_type'] === 'soft_copy' && $file['file_path'] && file_exists($file['file_path'])) {
            $bytes = filesize($file['file_path']);
            $fileSize = formatFileSize($bytes);
        }

        $details = [
            'file_id' => $file['file_id'],
            'file_name' => $file['file_name'],
            'file_path' => $file['file_path'],
            'copy_type' => $file['copy_type'],
            'upload_date' => $file['upload_date'],
            'document_type' => $file['document_type'],
            'uploader' => $file['uploader'],
            'access_info' => $accessInfo,
            'file_size' => $fileSize,
            'physical_storage' => $file['file_path'] ?: 'None',
            'history' => $history
        ];

        cacheStore($cacheKey, $details, $GLOBALS['cacheTTL']);
        return $details;
    } catch (PDOException $e) {
        error_log("Error fetching file details for file {$fileId}: " . $e->getMessage());
        sendJsonResponse(false, 'Failed to fetch file details.', [], 500);
        return [];
    }
}

/**
 * Formats file size in bytes to a human-readable format.
 *
 * @param int $bytes
 * @return string
 */
function formatFileSize(int $bytes): string
{
    if ($bytes === 0) {
        return '0 Bytes';
    }
    $units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $power = floor(log($bytes, 1024));
    $size = round($bytes / pow(1024, $power), 2);
    return $size . ' ' . $units[$power];
}

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, 'Invalid request method.', [], 405);
    }

    // Validate CSRF token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        sendJsonResponse(false, 'Invalid CSRF token.', [], 403);
    }

    // Validate user session
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
        sendJsonResponse(false, 'Unauthorized access.', [], 401);
    }

    // Validate input
    $fileId = filter_input(INPUT_POST, 'file_id', FILTER_VALIDATE_INT);
    if ($fileId === false || $fileId <= 0) {
        sendJsonResponse(false, 'Invalid file ID.', [], 400);
    }

    $userId = (int)$_SESSION['user_id'];

    global $pdo;

    // Fetch file details
    $fileDetails = fetchFileDetails($pdo, $fileId, $userId);

    // Send response
    sendJsonResponse(true, 'File details retrieved successfully.', ['data' => $fileDetails], 200);
} catch (Exception $e) {
    error_log("Error in get_file_details.php: " . $e->getMessage());
    sendJsonResponse(false, 'Server error occurred.', [], 500);
}
