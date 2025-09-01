<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
require 'notification.php';
require 'vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Secure error handling
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('error_log', __DIR__ . '/logs/error_log.log');
error_reporting(E_ALL);

/**
 * Transaction and notification type constants.
 */
const TRANSACTION_TYPES = [
    'file_sent' => 'file_sent',
    'notification' => 'notification',
    'co-ownership' => 'co-ownership',
    'file_approve' => 'file_approve',
    'file_reject' => 'file_reject'
];
const NOTIFICATION_TYPES = [
    'file_result' => 'file_result'
];

/**
 * Generates a JSON response with appropriate HTTP status.
 *
 * @param bool $success Success status.
 * @param string $message Response message.
 * @param string|null $redirect Optional redirect URL.
 * @param bool $popup Whether to show a popup.
 * @param int $statusCode HTTP status code.
 * @return void
 */
function sendResponse(bool $success, string $message, ?string $redirect, bool $popup, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'redirect' => $redirect,
        'popup' => $popup
    ]);
    exit;
}

/**
 * Validates user session.
 *
 * @return int User ID.
 * @throws Exception If user is not authenticated.
 */
function validateUserSession(): int
{
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated');
    }
    return (int)$_SESSION['user_id'];
}

/**
 * Validates CSRF token and regenerates it.
 *
 * @param string $csrfToken Token to validate.
 * @return bool Whether the token is valid.
 */
function validateCsrfToken(string $csrfToken): bool
{
    $isValid = isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $csrfToken);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Regenerate for security
    return $isValid;
}

/**
 * Validates redirect URL against allowed domains.
 *
 * @param string $url The URL to validate.
 * @return bool Whether the URL is valid.
 */
function validateRedirectUrl(string $url): bool
{
    $allowedDomains = [parse_url($_SERVER['HTTP_HOST'], PHP_URL_HOST)];
    $parsedUrl = parse_url($url);
    return !empty($url) && isset($parsedUrl['host']) && in_array($parsedUrl['host'], $allowedDomains);
}

try {
    // Validate request method and CSRF token
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Invalid request method', null, true, 405);
    }
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        sendResponse(false, 'Invalid CSRF token', null, true, 403);
    }

    $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? null;
    $fileId = filter_var($_POST['file_id'] ?? null, FILTER_VALIDATE_INT);
    $redirect = filter_var($_POST['redirect'] ?? '', FILTER_SANITIZE_URL);

    if (!$notificationId || !$fileId || !$action || !in_array($action, ['accept', 'deny'])) {
        sendResponse(false, 'Missing or invalid parameters', null, true, 400);
    }
    if (!empty($redirect) && !validateRedirectUrl($redirect)) {
        sendResponse(false, 'Invalid redirect URL', null, true, 400);
    }

    $userId = validateUserSession();
    $username = htmlspecialchars($_SESSION['username'] ?? 'Unknown User');

    global $pdo;
    $pdo->beginTransaction();

    // Get file and sender details using optimized index
    $stmt = $pdo->prepare("
        SELECT f.file_name, f.document_type_id, dt.type_name AS document_type, t.user_id AS sender_id, u.username AS sender_username
        FROM transactions t
        JOIN files f ON t.file_id = f.file_id
        LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
        JOIN users u ON t.user_id = u.user_id
        WHERE t.transaction_id = :notification_id 
        AND t.transaction_type = :file_sent 
        AND t.transaction_status = :pending
    ");
    $stmt->execute([
        ':notification_id' => $notificationId,
        ':file_sent' => TRANSACTION_TYPES['file_sent'],
        ':pending' => 'pending'
    ]);
    $fileDetails = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fileDetails) {
        $pdo->rollBack();
        sendResponse(false, 'Notification or file not found', null, true, 404);
    }

    $fileName = htmlspecialchars($fileDetails['file_name']);
    $documentType = htmlspecialchars($fileDetails['document_type'] ?? 'File');
    $senderId = $fileDetails['sender_id'];
    $senderUsername = htmlspecialchars($fileDetails['sender_username']);

    // Cache user's department ID
    if (!isset($_SESSION['users_department_id'])) {
        $stmt = $pdo->prepare("SELECT users_department_id FROM users_department WHERE user_id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $_SESSION['users_department_id'] = $stmt->fetchColumn() ?: null;
    }
    $usersDepartmentId = $_SESSION['users_department_id'];

    if ($action === 'accept') {
        // Update transfer status
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET transaction_status = :accepted, transaction_time = NOW()
            WHERE file_id = :file_id 
            AND (user_id = :user_id OR users_department_id = :users_department_id)
            AND transaction_type = :file_sent 
            AND transaction_status = :pending
        ");
        $stmt->execute([
            ':file_id' => $fileId,
            ':user_id' => $userId,
            ':users_department_id' => $usersDepartmentId,
            ':file_sent' => TRANSACTION_TYPES['file_sent'],
            ':pending' => 'pending',
            ':accepted' => 'accepted'
        ]);

        // Update notification status
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET transaction_status = :accepted
            WHERE transaction_id = :notification_id 
            AND user_id = :user_id 
            AND transaction_type = :notification
        ");
        $stmt->execute([
            ':notification_id' => $notificationId,
            ':user_id' => $userId,
            ':notification' => TRANSACTION_TYPES['notification'],
            ':accepted' => 'accepted'
        ]);

        // Grant co-ownership
        $stmt = $pdo->prepare("
            INSERT INTO transactions (file_id, user_id, transaction_type, transaction_status, transaction_time, description)
            VALUES (:file_id, :user_id, :co_ownership, :completed, NOW(), :description)
        ");
        $stmt->execute([
            ':file_id' => $fileId,
            ':user_id' => $userId,
            ':co_ownership' => TRANSACTION_TYPES['co-ownership'],
            ':completed' => 'completed',
            ':description' => "Co-ownership granted to $username for file '$fileName'"
        ]);

        $logMessage = $usersDepartmentId
            ? "Accepted $documentType: $fileName for department"
            : "Accepted $documentType: $fileName";
        logActivity($userId, $logMessage, $fileId, null, $usersDepartmentId, TRANSACTION_TYPES['file_approve']);

        sendNotification(
            $senderId,
            "Your $documentType '$fileName' was accepted by $username",
            $fileId,
            NOTIFICATION_TYPES['file_result']
        );

        sendResponse(true, 'File accepted successfully', $redirect, true, 200);
    } elseif ($action === 'deny') {
        // Update transfer status
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET transaction_status = :denied, transaction_time = NOW()
            WHERE file_id = :file_id 
            AND (user_id = :user_id OR users_department_id = :users_department_id)
            AND transaction_type = :file_sent 
            AND transaction_status = :pending
        ");
        $stmt->execute([
            ':file_id' => $fileId,
            ':user_id' => $userId,
            ':users_department_id' => $usersDepartmentId,
            ':file_sent' => TRANSACTION_TYPES['file_sent'],
            ':pending' => 'pending',
            ':denied' => 'denied'
        ]);

        // Update notification status
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET transaction_status = :denied
            WHERE transaction_id = :notification_id 
            AND user_id = :user_id 
            AND transaction_type = :notification
        ");
        $stmt->execute([
            ':notification_id' => $notificationId,
            ':user_id' => $userId,
            ':notification' => TRANSACTION_TYPES['notification'],
            ':denied' => 'denied'
        ]);

        $logMessage = $usersDepartmentId
            ? "Denied $documentType: $fileName for department"
            : "Denied $documentType: $fileName";
        logActivity($userId, $logMessage, $fileId, null, $usersDepartmentId, TRANSACTION_TYPES['file_reject']);

        sendNotification(
            $senderId,
            "Your $documentType '$fileName' was denied by $username",
            $fileId,
            NOTIFICATION_TYPES['file_result']
        );

        sendResponse(true, 'File denied successfully', $redirect, true, 200);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Transaction error in handle_file_acceptance.php: " . $e->getMessage());
    sendResponse(false, 'An error occurred while processing the request', null, true, 500);
}
