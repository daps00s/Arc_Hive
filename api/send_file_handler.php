<?php
// api/send_file_handler.php
session_start();
require '../db_connection.php';
require '../log_activity.php';
require '../notification.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    error_log("CSRF or session validation failed: user_id=" . ($_SESSION['user_id'] ?? 'not_set'));
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token or session.']);
    exit;
}

$fileIds = json_decode($_POST['file_ids'] ?? '[]', true);
$recipientsRaw = json_decode($_POST['recipients'] ?? '[]', true); // Expect JSON array of type:id
$message = filter_var($_POST['message'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);

if (empty($fileIds)) {
    echo json_encode(['success' => false, 'message' => 'No files selected.']);
    exit;
}

if (empty($recipientsRaw)) {
    echo json_encode(['success' => false, 'message' => 'No recipients selected.']);
    exit;
}

// Validate and parse recipients
$recipients = [];
foreach ($recipientsRaw as $recipient) {
    if (!is_string($recipient) || strpos($recipient, ':') === false) {
        error_log("Skipping malformed recipient: " . json_encode($recipient));
        continue;
    }

    list($type, $id) = explode(':', $recipient, 2);
    if (!in_array($type, ['user', 'department', 'sub_department']) || !ctype_digit($id)) {
        error_log("Skipping invalid recipient type or id: $recipient");
        continue;
    }

    $recipients[] = ['type' => $type, 'id' => (int)$id];
}

if (empty($recipients)) {
    echo json_encode(['success' => false, 'message' => 'No valid recipients selected.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Validate files belong to user
    $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
    $stmt = $pdo->prepare("
        SELECT file_id, file_name, user_id 
        FROM files 
        WHERE file_id IN ($placeholders) AND user_id = ? AND file_status != 'deleted'
    ");
    $stmt->execute(array_merge($fileIds, [$_SESSION['user_id']]));
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($files) !== count($fileIds)) {
        throw new Exception('One or more files are invalid or access denied.');
    }

    // Pre-fetch department users
    $deptUsersCache = [];
    foreach ($recipients as $recipient) {
        if ($recipient['type'] === 'department' || $recipient['type'] === 'sub_department') {
            $stmt = $pdo->prepare("SELECT user_id FROM user_department_assignments WHERE department_id = ?");
            $stmt->execute([$recipient['id']]);
            $deptUsersCache[$recipient['id']] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    $senderUsername = $_SESSION['username'] ?? 'Unknown User';
    $insertStmt = $pdo->prepare("
        INSERT INTO transactions (file_id, user_id, users_department_id, transaction_type, transaction_status, transaction_time, description)
        VALUES (?, ?, ?, 'file_sent', 'pending', NOW(), ?)
    ");

    $notificationStmt = $pdo->prepare("
        INSERT INTO transactions (file_id, user_id, transaction_type, transaction_status, transaction_time, description)
        VALUES (?, ?, 'notification', 'pending', NOW(), ?)
    ");

    $sentRecipients = [];
    $recipientCount = 0;
    $transactionData = [];

    foreach ($files as $file) {
        $fileId = $file['file_id'];
        $fileName = $file['file_name'];

        foreach ($recipients as $recipient) {
            $userId = ($recipient['type'] === 'user') ? $recipient['id'] : null;
            $deptId = ($recipient['type'] === 'department' || $recipient['type'] === 'sub_department') ? $recipient['id'] : null;

            if ($userId) {
                // Validate user exists
                $stmt = $pdo->prepare("SELECT 1 FROM users WHERE user_id = ?");
                $stmt->execute([$userId]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception("Invalid user ID: $userId");
                }
            } elseif ($deptId) {
                // Validate department exists
                $stmt = $pdo->prepare("SELECT 1 FROM departments WHERE department_id = ?");
                $stmt->execute([$deptId]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception("Invalid department ID: $deptId");
                }
            }

            if ($deptId) {
                // Send to all users in department
                foreach ($deptUsersCache[$deptId] as $deptUserId) {
                    if (in_array("user:$deptUserId", $sentRecipients)) {
                        continue;
                    }

                    $sentRecipients[] = "user:$deptUserId";
                    $transactionData[] = [
                        $fileId,
                        $deptUserId,
                        $deptId,
                        "File '$fileName' sent for review by $senderUsername" . ($message ? " with message: $message" : "")
                    ];

                    $notificationStmt->execute([
                        $fileId,
                        $deptUserId,
                        "You have received a file '$fileName' from $senderUsername for review." . ($message ? " Message: $message" : "")
                    ]);

                    $recipientCount++;
                }
            } else {
                // Send to individual user
                if (in_array("user:$userId", $sentRecipients)) {
                    continue;
                }

                $sentRecipients[] = "user:$userId";
                $transactionData[] = [
                    $fileId,
                    $userId,
                    null,
                    "File '$fileName' sent for review by $senderUsername" . ($message ? " with message: $message" : "")
                ];

                $notificationStmt->execute([
                    $fileId,
                    $userId,
                    "You have received a file '$fileName' from $senderUsername for review." . ($message ? " Message: $message" : "")
                ]);

                $recipientCount++;
            }
        }

        logActivity(
            $_SESSION['user_id'],
            "Sent file: $fileName to $recipientCount recipients",
            $fileId,
            null,
            null,
            'file_send'
        );
    }

    // Batch insert transactions
    foreach ($transactionData as $data) {
        $insertStmt->execute($data);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Files sent successfully to $recipientCount recipients.",
        'recipient_count' => $recipientCount
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Send file error: " . $e->getMessage() . " | User ID: " . $_SESSION['user_id']);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to send files: ' . $e->getMessage()]);
}
