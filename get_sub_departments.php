<?php

declare(strict_types=1);
session_start();
require 'db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$deptId = filter_input(INPUT_GET, 'dept_id', FILTER_VALIDATE_INT);
if (!$deptId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid department ID']);
    exit;
}

try {
    $pdo = $GLOBALS['pdo'];
    $stmt = $pdo->prepare("SELECT department_id, department_name FROM departments WHERE parent_department_id = ?");
    $stmt->execute([$deptId]);
    $subDepts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($subDepts);
} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error']);
}
