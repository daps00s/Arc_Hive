<?php
session_start();
require '../db_connection.php';

// Security headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit();
}

// Validate user
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
if ($userId === false) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit();
}

// Fetch logs
try {
    $stmt = $pdo->prepare("
        SELECT t.transaction_id, t.action, t.file_id, t.transaction_status, t.transaction_time, t.description, u.username
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.user_id
        ORDER BY t.transaction_time DESC
    ");
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'logs' => $logs]);
} catch (PDOException $e) {
    error_log("Failed to fetch logs for user_id: $userId at " . date('Y-m-d H:i:s') . ": " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to fetch logs due to a database error']);
}
exit();
