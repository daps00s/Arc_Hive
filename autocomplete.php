<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json');

// CSRF Validation
if (empty($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$term = filter_var($_GET['term'], FILTER_SANITIZE_STRING);

try {
    $stmt = $pdo->prepare("
        SELECT f.file_name AS value, dt.type_name AS document_type, d.department_id
        FROM files f
        LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
        LEFT JOIN users_department ud ON f.user_id = ud.user_id
        LEFT JOIN departments d ON ud.department_id = d.department_id
        WHERE f.file_name LIKE ? AND f.file_status != 'deleted'
        LIMIT 10
    ");
    $stmt->execute(['%' . $term . '%']);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'results' => $results]);
} catch (Exception $e) {
    error_log("Autocomplete error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error fetching autocomplete suggestions.']);
}
