<?php
ob_start(); // Start output buffering to capture any unexpected output
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
        if ($recipient['type'] === 'department') {
            // Fetch users for the parent department and all its sub-departments
            try {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT uda.user_id
                    FROM user_department_assignments uda
                    LEFT JOIN departments d ON uda.department_id = d.department_id
                    WHERE uda.department_id = ? 
                       OR d.parent_department_id = ?
                ");
                $stmt->execute([$recipient['id'], $recipient['id']]);
                $deptUsersCache[$recipient['id']] = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (empty($deptUsersCache[$recipient['id']])) {
                    error_log("No users found for department ID: {$recipient['id']} or its sub-departments");
                }
            } catch (Exception $e) {
                error_log("Error fetching users for department ID {$recipient['id']}: " . $e->getMessage());
                $deptUsersCache[$recipient['id']] = [];
            }
        } elseif ($recipient['type'] === 'sub_department') {
            // Fetch users only for the specific sub-department
            try {
                $stmt = $pdo->prepare("SELECT user_id FROM user_department_assignments WHERE department_id = ?");
                $stmt->execute([$recipient['id']]);
                $deptUsersCache[$recipient['id']] = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (empty($deptUsersCache[$recipient['id']])) {
                    error_log("No users found for sub-department ID: {$recipient['id']}");
                }
            } catch (Exception $e) {
                error_log("Error fetching users for sub-department ID {$recipient['id']}: " . $e->getMessage());
                $deptUsersCache[$recipient['id']] = [];
            }
        }
    }

    $senderUsername = $_SESSION['username'] ?? 'Unknown User';
    $insertStmt = $pdo->prepare("
        INSERT INTO transactions (file_id, user_id, users_department_id, transaction_type, transaction_status, transaction_time, description)
        VALUES (?, ?, ?, 'file_sent', 'pending', NOW(), ?)
    ");

    $notificationStmt = $pdo->prepare("
        INSERT INTO transactions (file_id, user_id, transaction_type, transaction_status, transaction_time, description)
        VALUES (?, ?, ?, ?, NOW(), ?)
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
                    error_log("Invalid user ID: $userId for file: $fileName");
                    continue; // Skip invalid user
                }
                if (in_array("user:$userId", $sentRecipients)) {
                    continue; // Skip duplicate user
                }
                $sentRecipients[] = "user:$userId";
                $transactionData[] = [
                    $fileId,
                    $userId,
                    null,
                    "File '$fileName' sent for review by $senderUsername" . ($message ? " with message: $message" : "")
                ];
                // Notification for receipt (no actions)
                $notificationStmt->execute([
                    $fileId,
                    $userId,
                    'notification',
                    'received',
                    "You have received a file '$fileName' from $senderUsername." . ($message ? " Message: $message" : "")
                ]);
                $recipientCount++;
            } elseif ($deptId) {
                // Validate department exists
                $stmt = $pdo->prepare("SELECT 1 FROM departments WHERE department_id = ?");
                $stmt->execute([$deptId]);
                if (!$stmt->fetchColumn()) {
                    error_log("Invalid department ID: $deptId for file: $fileName");
                    continue; // Skip invalid department
                }
                if (empty($deptUsersCache[$deptId])) {
                    error_log("Skipping department ID $deptId for file $fileName: No users assigned");
                    continue; // Skip department with no users
                }
                // Send to all users in department with correct users_department_id
                foreach ($deptUsersCache[$deptId] as $deptUserId) {
                    if (in_array("user:$deptUserId", $sentRecipients)) {
                        continue; // Skip duplicate user
                    }
                    // Fetch users_department_id for this user and department
                    $stmt = $pdo->prepare("
                        SELECT users_department_id 
                        FROM user_department_assignments 
                        WHERE user_id = ? AND department_id = ?
                    ");
                    $stmt->execute([$deptUserId, $deptId]);
                    $usersDepartmentId = $stmt->fetchColumn();
                    if (!$usersDepartmentId) {
                        error_log("No user_department_id found for user ID: $deptUserId in department ID: $deptId for file: $fileName");
                        continue; // Skip if no valid assignment
                    }
                    // Validate department user exists
                    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE user_id = ?");
                    $stmt->execute([$deptUserId]);
                    if (!$stmt->fetchColumn()) {
                        error_log("Invalid department user ID: $deptUserId for file: $fileName");
                        continue; // Skip invalid user
                    }
                    $sentRecipients[] = "user:$deptUserId";
                    $transactionData[] = [
                        $fileId,
                        $deptUserId,
                        $usersDepartmentId,
                        "File '$fileName' sent for review by $senderUsername" . ($message ? " with message: $message" : "")
                    ];
                    // Notification for receipt (no actions)
                    $notificationStmt->execute([
                        $fileId,
                        $deptUserId,
                        'notification',
                        'received',
                        "You have received a file '$fileName' from $senderUsername." . ($message ? " Message: $message" : "")
                    ]);
                    $recipientCount++;
                }
            }
        }
    }

    // Log activity after processing all files and recipients
    logActivity(
        $_SESSION['user_id'],
        "Sent " . count($files) . " file(s) to $recipientCount recipients",
        null, // No single file_id since multiple files may be sent
        null,
        null,
        'file_send'
    );

    // Batch insert transactions
    foreach ($transactionData as $data) {
        // $data = [$fileId, $userId, $deptId, $description]
        $description = $data[3];
        if ($data[2]) { // If deptId is set
            $stmt = $pdo->prepare("SELECT department_name, parent_department_id FROM departments WHERE department_id = ?");
            $stmt->execute([$data[2]]);
            $dept = $stmt->fetch(PDO::FETCH_ASSOC);
            $recipientType = $dept['parent_department_id'] ? 'sub-department' : 'department';
            $description = str_replace("sent for review", "sent to $recipientType '{$dept['department_name']}' for review", $description);
        }
        $insertStmt->execute([$data[0], $data[1], $data[2], $description]);
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
?>