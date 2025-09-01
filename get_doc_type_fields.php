<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$documentTypeName = filter_var($_POST['document_type_name'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);

if (!$documentTypeName) {
    echo json_encode(['success' => false, 'message' => 'Document type is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT field_name, field_label, field_type
        FROM document_types
        WHERE type_name = ?
    ");
    $stmt->execute([$documentTypeName]);
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($fields)) {
        // Fallback fields
        $fallbackFields = [
            [
                'field_name' => strtolower($documentTypeName) . '_id',
                'field_label' => ucfirst($documentTypeName) . ' ID',
                'field_type' => 'text'
            ]
        ];
        echo json_encode(['success' => true, 'data' => ['fields' => $fallbackFields]]);
    } else {
        echo json_encode(['success' => true, 'data' => ['fields' => $fields]]);
    }
} catch (Exception $e) {
    error_log("Error fetching document type fields: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error fetching metadata fields']);
}