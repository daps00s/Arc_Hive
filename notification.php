<?php
require 'db_connection.php';

/**
 * Sends a notification to a user by logging it in the transactions table.
 *
 * @param int $userId The ID of the user to notify.
 * @param string $message The notification message.
 * @param int|null $fileId The ID of the file (if applicable).
 * @param string $type The type of notification (e.g., 'received', 'uploaded').
 * @throws PDOException If notification logging fails.
 */
function sendNotification(int $userId, string $message, ?int $fileId = null, string $type = 'uploaded'): void
{
    global $pdo;

    $status = in_array($type, ['access_request', 'access_result']) ? ($type === 'access_request' ? 'pending' : 'completed') : 'completed';
    $description = in_array($type, ['access_request', 'access_result']) ? $status : $message;

    $stmt = $pdo->prepare("
        INSERT INTO transactions (user_id, file_id, transaction_type, transaction_status, transaction_time, description)
        VALUES (:user_id, :file_id, 'notification', :status, NOW(), :description)
    ");
    $params = [
        ':user_id' => $userId,
        ':file_id' => $fileId,
        ':status' => $status,
        ':description' => $description
    ];

    try {
        $stmt->execute($params);
    } catch (PDOException $e) {
        error_log("Failed to send notification to user $userId for file $fileId: " . $e->getMessage());
        throw new PDOException("Failed to send notification", 0, $e);
    }
}

/**
 * Sends a notification to all users in a department.
 *
 * @param int $departmentId The ID of the department.
 * @param string $message The notification message.
 * @param int|null $fileId The ID of the file (if applicable).
 * @param string $type The type of notification (e.g., 'received', 'uploaded').
 * @throws PDOException If notification logging fails.
 */
function sendNotificationToDepartment(int $departmentId, string $message, ?int $fileId = null, string $type = 'uploaded'): void
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT user_id FROM user_department_assignments WHERE department_id = :department_id");
    $stmt->execute([':department_id' => $departmentId]);
    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($userIds)) {
        return;
    }

    $status = in_array($type, ['access_request', 'access_result']) ? ($type === 'access_request' ? 'pending' : 'completed') : 'completed';
    $description = in_array($type, ['access_request', 'access_result']) ? $status : $message;

    $stmt = $pdo->prepare("
        INSERT INTO transactions (user_id, file_id, transaction_type, transaction_status, transaction_time, description)
        VALUES (?, ?, 'notification', ?, NOW(), ?)
    ");

    try {
        $pdo->beginTransaction();
        foreach ($userIds as $userId) {
            $stmt->execute([$userId, $fileId, $status, $description]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Failed to send department notification for department $departmentId: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Marks a notification as read.
 *
 * @param int $notificationId The ID of the notification.
 * @param int $userId The ID of the user.
 * @return bool Success status.
 */
function markNotificationAsRead(int $notificationId, int $userId): bool
{
    global $pdo;

    $stmt = $pdo->prepare("
        UPDATE transactions 
        SET transaction_status = 'read' 
        WHERE transaction_id = :notification_id AND user_id = :user_id AND transaction_type = 'notification'
    ");
    try {
        $stmt->execute([':notification_id' => $notificationId, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Failed to mark notification $notificationId as read for user $userId: " . $e->getMessage());
        return false;
    }
}
