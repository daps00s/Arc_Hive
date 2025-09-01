<?php
session_start();
require 'db_connection.php';

/**
 * Transaction type constants.
 */
const TRANSACTION_TYPES = [
    'notification' => 'notification'
];

/**
 * Fetches user notifications with pagination and optional read status update.
 *
 * @param int $userId The ID of the user.
 * @param int $limit Number of notifications to fetch.
 * @param int $offset Pagination offset.
 * @param bool $markAsRead Whether to mark fetched notifications as read.
 * @return array Fetched notifications.
 * @throws PDOException If query fails.
 */
function fetchUserNotifications(int $userId, int $limit = 5, int $offset = 0, bool $markAsRead = false): array
{
    global $pdo;

    try {
        // Fetch notifications using optimized index
        $stmt = $pdo->prepare("
            SELECT t.transaction_id AS id, t.file_id, t.transaction_status AS status, t.transaction_time AS timestamp,
                   t.description AS message, t.transaction_type AS type, COALESCE(f.file_name, 'Unknown File') AS file_name
            FROM transactions t
            LEFT JOIN files f ON t.file_id = f.file_id
            WHERE t.user_id = :user_id 
            AND t.transaction_type = :notification
            AND (f.file_status != 'deleted' OR f.file_id IS NULL)
            ORDER BY t.transaction_time DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':notification' => TRANSACTION_TYPES['notification'],
            ':limit' => $limit,
            ':offset' => $offset
        ]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($markAsRead && !empty($notifications)) {
            $notificationIds = array_column($notifications, 'id');
            $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
            $stmt = $pdo->prepare("
                UPDATE transactions 
                SET transaction_status = :completed
                WHERE transaction_id IN ($placeholders) 
                AND transaction_type = :notification
                AND transaction_status = :pending
            ");
            $params = array_merge($notificationIds, [
                ':notification' => TRANSACTION_TYPES['notification'],
                ':completed' => 'completed',
                ':pending' => 'pending'
            ]);
            $stmt->execute($params);
        }

        return $notifications;
    } catch (PDOException $e) {
        error_log("Fetch notifications error for user $userId: " . $e->getMessage());
        throw $e;
    }
}

try {
    if (!isset($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'User not authenticated']);
        exit;
    }

    $userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
    $limit = filter_var($_GET['limit'] ?? 5, FILTER_VALIDATE_INT) ?: 5;
    $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
    $markAsRead = filter_var($_GET['mark_as_read'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $notifications = fetchUserNotifications($userId, $limit, $offset, $markAsRead);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'notifications' => $notifications]);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch notifications']);
}
