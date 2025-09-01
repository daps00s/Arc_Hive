<?php
session_start();
require 'db_connection.php';
require 'TransactionLogger.php';
require 'LocationService.php';

function generateResponse(bool $success, string $message = '', array $data = [], int $statusCode = 200): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Invalid request method.', 405);
    }
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated.', 401);
    }
    $fileId = filter_var($_GET['file_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$fileId) {
        throw new Exception('Invalid file ID.', 400);
    }

    // Log the QR scan
    $logger = new TransactionLogger($pdo);
    $logger->logTransaction(
        $_SESSION['user_id'],
        $fileId,
        $_SESSION['department_id'] ?? null,
        'scan',
        'completed',
        'QR code scanned for file ID ' . $fileId
    );

    // Fetch file details
    $stmt = $this->pdo->prepare("
        SELECT f.*, sl.room, sl.cabinet, sl.layer, sl.box, sl.folder
        FROM files f
        LEFT JOIN storage_locations sl ON f.location_id = sl.location_id
        WHERE f.file_id = ?
    ");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        throw new Exception('File not found.', 404);
    }

    // Check access level
    if ($file['access_level'] === 'personal' && $file['user_id'] !== $_SESSION['user_id']) {
        throw new Exception('Access denied.', 403);
    }

    // Get location path
    $locationService = new LocationService($pdo);
    $location = $locationService->getFullLocationPath($fileId);

    // Fetch transaction history
    $stmt = $pdo->prepare("
        SELECT * FROM transactions
        WHERE file_id = ?
        ORDER BY transaction_time DESC
        LIMIT 50
    ");
    $stmt->execute([$fileId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    generateResponse(true, 'File details retrieved.', [
        'file' => $file,
        'location' => $location,
        'history' => $history
    ]);
} catch (Exception $e) {
    generateResponse(false, 'Error: ' . $e->getMessage(), [], $e->getCode() ?: 500);
}
