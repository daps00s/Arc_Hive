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
        SELECT meta_data
        FROM files
        WHERE department_id = ? AND document_type_id = (SELECT document_type_id FROM document_types WHERE type_name = ?)
        AND copy_type = 'hard' AND file_status != 'deleted'
        ORDER BY upload_date DESC LIMIT 1
    ");
    $stmt->execute([$departmentId, $documentType]);
    $lastMeta = $stmt->fetchColumn();

    $metadata = json_decode($lastMeta, true) ?: ['cabinet' => 'A', 'layer' => 1, 'box' => 1, 'folder' => 1];
    $metadata['folder'] = isset($metadata['folder']) ? $metadata['folder'] + 1 : 1;

    echo json_encode(['success' => true, 'metadata' => $metadata]);
} catch (Exception $e) {
    error_log("Storage suggestion error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to fetch storage suggestion.']);
}
