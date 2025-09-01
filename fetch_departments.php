<?php
session_start();
require 'db_connection.php';

try {
    $stmt = $pdo->prepare("SELECT department_id, department_name FROM departments ORDER BY department_name ASC");
    $stmt->execute();
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'departments' => $departments]);
} catch (Exception $e) {
    error_log('Fetch departments error: ' . $e->getMessage());
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch departments']);
}