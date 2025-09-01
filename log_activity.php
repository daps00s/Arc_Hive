
<?php
require 'db_connection.php';

/**
 * Transaction type constants for the transactions table.
 */
const TRANSACTION_TYPES = [
    'upload' => 'file_upload',
    'send' => 'file_sent',
    'notification' => 'notification',
    'request' => 'file_request',
    'approve' => 'file_approve',
    'reject' => 'file_reject',
    'edit' => 'file_edit',
    'delete' => 'file_delete',
    'add' => 'add',
    'other' => 'other',
    'fetch_status' => 'fetch_status',
    'co-ownership' => 'co-ownership'
];

/**
 * Maps action types to string values for transactions table.
 *
 * @param string $action The action to map (e.g., 'upload', 'send').
 * @return string The corresponding transaction type.
 */
function getTransactionType(string $action): string
{
    $action = strtolower($action);
    // Check if the action is already a valid transaction type value
    if (in_array($action, TRANSACTION_TYPES)) {
        return $action;
    }
    // Check if the action starts with "Upload file successful:"
    if (stripos($action, 'Upload file successful:') === 0) {
        return TRANSACTION_TYPES['notification'];
    }
    // Otherwise, map the action to a transaction type
    return TRANSACTION_TYPES[$action] ?? TRANSACTION_TYPES['other'];
}

/**
 * Logs an activity in the transactions table.
 *
 * @param int $userId The ID of the user performing the action.
 * @param string $description The action description.
 * @param int|null $fileId The ID of the file (if applicable).
 * @param int|null $departmentId The ID of the department or sub-department (if applicable).
 * @param int|null $usersDepartmentId The ID of the user's department affiliation (optional, overrides lookup).
 * @param string|null $transactionType Optional explicit transaction type.
 * @throws PDOException If logging fails.
 */
function logActivity(int $userId, string $description, ?int $fileId = null, ?int $departmentId = null, ?int $usersDepartmentId = null, ?string $transactionType = null): void
{
    global $pdo;

    // If usersDepartmentId is not provided, look it up based on departmentId
    if ($usersDepartmentId === null && $departmentId !== null) {
        $stmt = $pdo->prepare("
            SELECT users_department_id 
            FROM user_department_assignments 
            WHERE user_id = ? AND department_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $departmentId]);
        $usersDepartmentId = $stmt->fetchColumn() ?: null;
    }

    $type = $transactionType ? getTransactionType($transactionType) : getTransactionType($description);
    $stmt = $pdo->prepare("
        INSERT INTO transactions (user_id, users_department_id, file_id, transaction_type, transaction_status, transaction_time, description)
        VALUES (:user_id, :users_department_id, :file_id, :type, 'completed', NOW(), :description)
    ");
    $params = [
        ':user_id' => $userId,
        ':users_department_id' => $usersDepartmentId,
        ':file_id' => $fileId,
        ':type' => $type,
        ':description' => $description
    ];

    try {
        $stmt->execute($params);
    } catch (PDOException $e) {
        error_log("Failed to log activity for user $userId: $description - " . $e->getMessage(), 3, LOG_DIR . 'upload_error.log');
        throw new PDOException("Failed to log activity", 0, $e);
    }
}

/**
 * Logs file-related activities, including sending files to departments or users.
 *
 * @param int $userId The ID of the user performing the action.
 * @param string $fileName The name of the file.
 * @param int $fileId The ID of the file.
 * @param array $recipientIds Array of recipient IDs (users or departments).
 * @param string $recipientType 'user' or 'department'.
 * @throws PDOException If logging fails.
 */
function logFileActivity(int $userId, string $fileName, int $fileId, array $recipientIds = [], string $recipientType = 'user'): void
{
    global $pdo;

    // Get user's department assignment, if any
    if (!isset($_SESSION['users_department_id'])) {
        $stmt = $pdo->prepare("
            SELECT users_department_id 
            FROM user_department_assignments 
            WHERE user_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $_SESSION['users_department_id'] = $stmt->fetchColumn() ?: null;
    }
    $usersDepartmentId = $_SESSION['users_department_id'];

    if (empty($recipientIds)) {
        logActivity($userId, "Uploaded file: $fileName", $fileId, null, $usersDepartmentId, 'file_upload');
        return;
    }

    $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
    $table = $recipientType === 'department' ? 'departments' : 'users';
    $idColumn = $recipientType === 'department' ? 'department_id' : 'user_id';
    $nameColumn = $recipientType === 'department' ? 'department_name' : 'username';
    $stmt = $pdo->prepare("SELECT $idColumn, $nameColumn FROM $table WHERE $idColumn IN ($placeholders)");
    $stmt->execute($recipientIds);
    $recipients = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    try {
        $pdo->beginTransaction();
        foreach ($recipientIds as $recipientId) {
            $recipientName = $recipients[$recipientId] ?? 'Unknown';
            $description = "Sent file: $fileName to $recipientType: $recipientName";
            $usersDepartmentIdForRecipient = null;
            if ($recipientType === 'department') {
                $stmt = $pdo->prepare("
                    SELECT users_department_id 
                    FROM user_department_assignments 
                    WHERE user_id = ? AND department_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$userId, $recipientId]);
                $usersDepartmentIdForRecipient = $stmt->fetchColumn() ?: null;
            }
            logActivity($userId, $description, $fileId, $recipientType === 'department' ? $recipientId : null, $usersDepartmentIdForRecipient, 'file_sent');
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Failed to log file activity for file $fileId: " . $e->getMessage(), 3, LOG_DIR . 'upload_error.log');
        throw $e;
    }
}
