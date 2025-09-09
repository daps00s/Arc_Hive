<?php
session_start();

// ✅ Always send JSON
header('Content-Type: application/json; charset=utf-8');
ob_clean();

// ---- Required files check ----
$requiredFiles = ['db_connection.php'];
foreach ($requiredFiles as $file) {
    if (!file_exists($file)) {
        error_log("Missing required file: $file");
        sendJsonResponse(false, 'Server error: Missing critical dependency.', [], 500);
    }
    require_once $file;
}

use Dotenv\Dotenv;

// ---- Dotenv Safe Load ----
try {
    $dotenv = Dotenv::createImmutable(__DIR__, ['.env']);
    $dotenv->safeLoad();
} catch (Throwable $e) {
    error_log("Dotenv error: " . $e->getMessage());
    sendJsonResponse(false, 'Configuration error.', [], 500);
}

// ---- Error Handling ----
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('error_log', __DIR__ . '/logs/error_log.log');
error_reporting(E_ALL);

// ---- Cache Setup ----
$cacheDir = __DIR__ . '/cache';
if (!file_exists($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}
$cacheTTL = (int)($_ENV['CACHE_TTL'] ?? 300);

// ---- JSON Response Helper ----
function sendJsonResponse(bool $success, string $message, array $data, int $statusCode): void
{
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

// ---- File Size Formatter ----
function formatFileSize(int $bytes): string
{
    if ($bytes === 0) return '0 Bytes';
    $units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $power = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
}

// ---- Cache Functions ----
function cacheStore(string $key, $value, int $ttl): bool
{
    global $cacheDir;
    $filename = $cacheDir . '/' . md5($key) . '.cache';
    $data = serialize(['data' => $value, 'expires' => time() + $ttl]);
    return file_put_contents($filename, $data, LOCK_EX) !== false;
}

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

function cacheExists(string $key): bool
{
    global $cacheDir;
    $filename = $cacheDir . '/' . md5($key) . '.cache';
    if (file_exists($filename)) {
        $content = unserialize(file_get_contents($filename));
        if ($content['expires'] > time()) return true;
        unlink($filename);
    }
    return false;
}

// ---- Fetch File Details ----
function fetchFileDetails(PDO $pdo, int $fileId, int $userId): array
{
    $cacheKey = "file_details_{$fileId}_{$userId}";
    if (cacheExists($cacheKey)) return cacheFetch($cacheKey);

    try {
        // File details
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

        // History (last 50)
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

        $history = array_map(function ($entry) {
            return [
                'action' => $entry['description'] ?: 'Unknown action',
                'timestamp' => $entry['transaction_time']
            ];
        }, $historyRaw);

        // File size (soft copies only)
        $fileSize = 'N/A';
        if ($file['copy_type'] === 'soft_copy' && $file['file_path'] && file_exists($file['file_path'])) {
            $fileSize = formatFileSize(filesize($file['file_path']));
        }

        $details = [
            'file_id' => $file['file_id'],
            'file_name' => $file['file_name'],
            'file_path' => $file['file_path'],
            'copy_type' => $file['copy_type'],
            'upload_date' => $file['upload_date'],
            'document_type' => $file['document_type'],
            'uploader' => $file['uploader'],
            'file_size' => $fileSize,
            'history' => $history
        ];

        cacheStore($cacheKey, $details, $GLOBALS['cacheTTL']);
        return $details;
    } catch (PDOException $e) {
        error_log("Error fetching file details for file {$fileId}: " . $e->getMessage());
        sendJsonResponse(false, 'Failed to fetch file details.', [], 500);
    }
}

// ---- Main Execution ----
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, 'Invalid request method.', [], 405);
    }

    if (!isset($_SESSION['user_id'])) {
        sendJsonResponse(false, 'Unauthorized access.', [], 401);
    }

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        sendJsonResponse(false, 'Invalid CSRF token.', [], 403);
    }

    $fileId = filter_input(INPUT_POST, 'file_id', FILTER_VALIDATE_INT);
    if ($fileId === false || $fileId <= 0) {
        sendJsonResponse(false, 'Invalid file ID.', [], 400);
    }

    $userId = (int)$_SESSION['user_id'];
    global $pdo;

    $fileDetails = fetchFileDetails($pdo, $fileId, $userId);

    sendJsonResponse(true, 'File details retrieved successfully.', ['data' => $fileDetails], 200);
} catch (Exception $e) {
    error_log("Error in get_file_details.php: " . $e->getMessage());
    sendJsonResponse(false, 'Server error occurred.', [], 500);
}
