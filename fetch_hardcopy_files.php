<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json');

// CSRF Validation
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$departmentId = filter_var($_POST['department_id'], FILTER_SANITIZE_NUMBER_INT);
$documentType = filter_var($_POST['document_type'], FILTER_SANITIZE_STRING);

try {
    $stmt = $pdo->prepare("
        SELECT f.file_id AS id, f.file_name AS file_name, f.meta_data
        FROM files f
        JOIN users_department ud ON f.user_id = ud.user_id
        WHERE ud.department_id = ? AND f.copy_type = 'hard' 
        AND f.document_type_id = (SELECT document_type_id FROM document_types WHERE type_name = ? LIMIT 1)
        AND f.file_status != 'deleted'
    ");
    $stmt->execute([$departmentId, $documentType]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'files' => $files]);
} catch (Exception $e) {
    error_log("Fetch hardcopy files error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to fetch hardcopy files.']);
}
