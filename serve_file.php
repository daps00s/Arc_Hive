<?php
session_start();
require 'db_connection.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT) ?: 0;
$fileId = filter_var($_GET['file_id'] ?? 0, FILTER_VALIDATE_INT);

if (!$fileId) {
    http_response_code(400);
    exit('Invalid file ID');
}

try {
    // Verify file access
    $stmt = $pdo->prepare("
        SELECT file_path, file_type
        FROM files
        WHERE file_id = ? AND file_status != 'deleted'
        AND (
            user_id = ? 
            OR department_id IN (
                SELECT department_id FROM user_department_assignments WHERE user_id = ?
            )
            OR EXISTS (
                SELECT 1 FROM transactions t
                WHERE t.file_id = ? AND t.user_id = ?
                AND t.transaction_type IN ('file_sent', 'notification')
                AND t.transaction_status IN ('pending', 'accepted')
            )
        )
    ");
    $stmt->execute([$fileId, $userId, $userId, $fileId, $userId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file || !file_exists($file['file_path'])) {
        error_log("File not found or inaccessible: file_id=$fileId, user_id=$userId, path=" . ($file['file_path'] ?? 'null'));
        http_response_code(404);
        exit('File not found');
    }

    // Set appropriate headers
    header('Content-Type: ' . $file['file_type']);
    header('Content-Disposition: inline; filename="' . basename($file['file_path']) . '"');
    readfile($file['file_path']);
    exit;
} catch (Exception $e) {
    error_log("Error serving file: file_id=$fileId, user_id=$userId, error=" . $e->getMessage());
    http_response_code(500);
    exit('Server error');
}
?>