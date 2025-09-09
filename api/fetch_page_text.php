<?php
// api/fetch_page_text.php
session_start();
require '../db_connection.php';

header('Content-Type: application/json');

function validate_csrf_token($token)
{
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
}

function send_response($success, $message = '', $data = [], $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

if (empty($_SESSION['user_id'])) {
    send_response(false, 'Unauthorized', [], 401);
}

validate_csrf_token($_POST['csrf_token'] ?? '');

$fileId = filter_var($_POST['file_id'] ?? 0, FILTER_VALIDATE_INT);
$pageNumber = filter_var($_POST['page_number'] ?? 0, FILTER_VALIDATE_INT);
$searchQuery = trim($_POST['search_query'] ?? '');

if (!$fileId || !$pageNumber) {
    send_response(false, 'Invalid file or page', [], 400);
}

// Check access
$stmt = $pdo->prepare("SELECT user_id, department_id, access_level, file_type, file_path FROM files WHERE file_id = ?");
$stmt->execute([$fileId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    send_response(false, 'File not found', [], 404);
}

$hasAccess = ($file['user_id'] == $_SESSION['user_id']);
if (!$hasAccess && $file['department_id']) {
    $stmt = $pdo->prepare("SELECT 1 FROM user_department_assignments WHERE user_id = ? AND department_id = ?");
    $stmt->execute([$_SESSION['user_id'], $file['department_id']]);
    $hasAccess = $stmt->fetch() !== false;
}

if (!$hasAccess) {
    send_response(false, 'No access to this file', [], 403);
}

// Get total pages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM file_pages WHERE file_id = ?");
$stmt->execute([$fileId]);
$totalPages = $stmt->fetchColumn();

// Get matched pages if search
$matchedPages = [];
if ($searchQuery) {
    $searchTerm = '%' . $searchQuery . '%';
    $stmt = $pdo->prepare("SELECT page_number FROM file_pages WHERE file_id = ? AND extracted_text LIKE ? ORDER BY page_number");
    $stmt->execute([$fileId, $searchTerm]);
    $matchedPages = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Check if file is an image
$isImage = in_array(strtolower($file['file_type']), ['png', 'jpeg', 'jpg', 'gif', 'tiff']);
if ($isImage) {
    // For images, return the file path or URL
    $imagePath = $file['file_path'];
    if ($totalPages > 1) {
        // Handle multi-page images (e.g., TIFF or PDF rendered as images)
        $stmt = $pdo->prepare("SELECT file_path FROM file_pages WHERE file_id = ? AND page_number = ?");
        $stmt->execute([$fileId, $pageNumber]);
        $pageData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pageData && !empty($pageData['file_path'])) {
            $imagePath = $pageData['file_path'];
        }
    }
    if (!$imagePath || !file_exists($imagePath)) {
        send_response(false, 'Image not found', [], 404);
    }
    // Convert file path to URL (adjust base URL as needed)
    $baseUrl = 'http://localhost/arc-hive/'; // Replace with your base URL
    $imageUrl = str_replace($_SERVER['DOCUMENT_ROOT'], $baseUrl, $imagePath);
    send_response(true, 'Image fetched', [
        'image_url' => $imageUrl,
        'total_pages' => $totalPages,
        'matched_pages' => $matchedPages,
        'is_image' => true
    ]);
} else {
    // Get page text for non-image files
    $stmt = $pdo->prepare("SELECT extracted_text FROM file_pages WHERE file_id = ? AND page_number = ?");
    $stmt->execute([$fileId, $pageNumber]);
    $text = $stmt->fetchColumn();
    if ($text === false) {
        send_response(false, 'Page not found', [], 404);
    }
    send_response(true, 'Page fetched', [
        'text' => $text,
        'total_pages' => $totalPages,
        'matched_pages' => $matchedPages,
        'is_image' => false
    ]);
}
