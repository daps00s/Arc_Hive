<?php

declare(strict_types=1);
session_start();
require 'db_connection.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CSRF token generation and validation
function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Redirect to login if not authenticated or not an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
if ($userId === false) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Function to execute prepared queries safely
function executeQuery(PDO $pdo, string $query, array $params = []): PDOStatement|false
{
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage() . " in query: $query");
        return false;
    }
}

// Function to log transactions
function logTransaction(PDO $pdo, int $userId, string $status, int $type, string $message): bool
{
    $stmt = executeQuery(
        $pdo,
        "INSERT INTO transactions (user_id, transaction_status, transaction_type, transaction_time, description) VALUES (?, ?, ?, NOW(), ?)",
        [$userId, $status, $type, $message]
    );
    return $stmt !== false;
}

// Validate field name (alphanumeric, underscores, max 50 chars, no reserved words)
function validateFieldName(string $fieldName): bool
{
    $reservedWords = ['date_released', 'date_received'];
    return preg_match('/^[a-zA-Z0-9_]{1,50}$/', $fieldName) && !in_array($fieldName, $reservedWords);
}

// Check if field is used in files or text_repository.metadata
function isFieldUsed(PDO $pdo, int $documentTypeId, string $fieldName): bool
{
    $stmt = executeQuery(
        $pdo,
        "SELECT COUNT(*) as count FROM text_repository WHERE JSON_CONTAINS_PATH(metadata, 'one', '$.$fieldName') AND file_id IN (SELECT file_id FROM files WHERE document_type_id = ?)",
        [$documentTypeId]
    );
    return $stmt && $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
}

// Handle AJAX requests
$error = '';
$success = '';
$response = ['success' => false, 'message' => ''];
$fieldsCache = []; // Cache for fields JSON

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $pdo->beginTransaction();

    try {
        if ($action === 'add_document_type' || $action === 'edit_document_type') {
            $id = isset($_POST['id']) ? filter_var($_POST['id'], FILTER_VALIDATE_INT) : null;
            $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

            if (empty($name) || strlen($name) > 50) {
                throw new Exception('Document type name is required and must be 50 characters or less.', 16);
            }

            $checkStmt = executeQuery(
                $pdo,
                "SELECT document_type_id FROM document_types WHERE type_name = ? AND document_type_id != ?",
                [$name, $id ?? 0]
            );
            if ($checkStmt && $checkStmt->rowCount() > 0) {
                throw new Exception('Document type name already exists.', $action === 'add_document_type' ? 16 : 17);
            }

            if ($action === 'add_document_type') {
                $fields = json_encode([
                    ['name' => 'date_released', 'label' => 'Date Released', 'type' => 'date', 'required' => true],
                    ['name' => 'date_received', 'label' => 'Date Received', 'type' => 'date', 'required' => true]
                ]);
                $stmt = executeQuery(
                    $pdo,
                    "INSERT INTO document_types (type_name, fields, is_active) VALUES (?, ?, 1)",
                    [$name, $fields]
                );
                $message = "Added document type: $name";
                $transType = 16;
            } elseif ($action === 'edit_document_type' && $id) {
                $stmt = executeQuery(
                    $pdo,
                    "UPDATE document_types SET type_name = ? WHERE document_type_id = ?",
                    [$name, $id]
                );
                $message = "Updated document type: $name";
                $transType = 17;
            }

            if (!$stmt) {
                throw new Exception('Failed to ' . ($action === 'add_document_type' ? 'add' : 'update') . ' document type.', $transType);
            }

            $pdo->commit();
            $success = $message;
            logTransaction($pdo, $userId, 'completed', $transType, $message);
            $response = ['success' => true, 'message' => $success];
        } elseif ($action === 'add_field') {
            $document_type_id = filter_var($_POST['document_type_id'], FILTER_VALIDATE_INT);
            $field_name = trim(filter_input(INPUT_POST, 'field_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
            $field_label = trim(filter_input(INPUT_POST, 'field_label', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
            $field_type = filter_input(INPUT_POST, 'field_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            // FIX: Handle is_required properly when checkbox is not checked
            $is_required = isset($_POST['is_required']) ? filter_var($_POST['is_required'], FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0, 'max_range' => 1]]) : 0;

            if (!$document_type_id || empty($field_name) || empty($field_label) || !in_array($field_type, ['text', 'number', 'date', 'file'])) {
                throw new Exception('All field inputs must be valid.', 18);
            }
            if (!validateFieldName($field_name)) {
                throw new Exception('Field name must be alphanumeric with underscores, 1-50 characters, and not a reserved word.', 18);
            }

            $docTypeStmt = executeQuery($pdo, "SELECT fields FROM document_types WHERE document_type_id = ?", [$document_type_id]);
            if (!$docTypeStmt || $docTypeStmt->rowCount() === 0) {
                throw new Exception('Invalid document type selected.', 18);
            }

            $fields = $docTypeStmt->fetch(PDO::FETCH_ASSOC)['fields'];
            $fieldsArray = json_decode($fields, true);
            if (array_reduce($fieldsArray, fn($carry, $field) => $carry || $field['name'] === $field_name, false)) {
                throw new Exception('Field name already exists for this document type.', 18);
            }

            $stmt = executeQuery(
                $pdo,
                "UPDATE document_types SET fields = JSON_ARRAY_APPEND(fields, '$', JSON_OBJECT('name', ?, 'label', ?, 'type', ?, 'required', ?)) WHERE document_type_id = ?",
                [$field_name, $field_label, $field_type, (bool)$is_required, $document_type_id]
            );
            if (!$stmt) {
                throw new Exception('Failed to add field.', 18);
            }

            $pdo->commit();
            $message = "Added field: $field_label to document type ID: $document_type_id";
            logTransaction($pdo, $userId, 'completed', 18, $message);
            $response = ['success' => true, 'message' => $message];
            unset($fieldsCache[$document_type_id]);
        } elseif ($action === 'edit_field') {
            $document_type_id = filter_var($_POST['document_type_id'], FILTER_VALIDATE_INT);
            $field_index = filter_var($_POST['field_index'], FILTER_VALIDATE_INT);
            $field_name = trim(filter_input(INPUT_POST, 'field_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
            $field_label = trim(filter_input(INPUT_POST, 'field_label', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
            $field_type = filter_input(INPUT_POST, 'field_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            // FIX: Handle is_required properly when checkbox is not checked
            $is_required = isset($_POST['is_required']) ? filter_var($_POST['is_required'], FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 0, 'max_range' => 1]]) : 0;

            if (!$document_type_id || !isset($field_index) || empty($field_name) || empty($field_label) || !in_array($field_type, ['text', 'number', 'date', 'file'])) {
                throw new Exception('All field inputs must be valid.', 19);
            }
            if (!validateFieldName($field_name)) {
                throw new Exception('Field name must be alphanumeric with underscores, 1-50 characters, and not a reserved word.', 19);
            }

            $docTypeStmt = executeQuery($pdo, "SELECT fields FROM document_types WHERE document_type_id = ?", [$document_type_id]);
            if (!$docTypeStmt || $docTypeStmt->rowCount() === 0) {
                throw new Exception('Invalid document type selected.', 19);
            }

            $fields = json_decode($docTypeStmt->fetch(PDO::FETCH_ASSOC)['fields'], true);
            if (!isset($fields[$field_index])) {
                throw new Exception('Invalid field index.', 19);
            }
            if (in_array($fields[$field_index]['name'], ['date_released', 'date_received'])) {
                throw new Exception('Cannot edit mandatory fields (Date Released or Date Received).', 19);
            }

            $otherFields = array_filter($fields, fn($f, $i) => $i !== $field_index && $f['name'] === $field_name, ARRAY_FILTER_USE_BOTH);
            if (!empty($otherFields) && $fields[$field_index]['name'] !== $field_name) {
                throw new Exception('Field name already exists for this document type.', 19);
            }

            $stmt = executeQuery(
                $pdo,
                "UPDATE document_types SET fields = JSON_SET(fields, '$[$field_index]', JSON_OBJECT('name', ?, 'label', ?, 'type', ?, 'required', ?)) WHERE document_type_id = ?",
                [$field_name, $field_label, $field_type, (bool)$is_required, $document_type_id]
            );
            if (!$stmt) {
                throw new Exception('Failed to update field.', 19);
            }

            $pdo->commit();
            $message = "Updated field: $field_label for document type ID: $document_type_id";
            logTransaction($pdo, $userId, 'completed', 19, $message);
            $response = ['success' => true, 'message' => $message];
            unset($fieldsCache[$document_type_id]);
        } elseif ($action === 'delete_field') {
            $document_type_id = filter_var($_POST['document_type_id'], FILTER_VALIDATE_INT);
            $field_index = filter_var($_POST['field_index'], FILTER_VALIDATE_INT);

            if (!$document_type_id || !isset($field_index)) {
                throw new Exception('Invalid field or document type ID.', 20);
            }

            $docTypeStmt = executeQuery($pdo, "SELECT fields FROM document_types WHERE document_type_id = ?", [$document_type_id]);
            if (!$docTypeStmt || $docTypeStmt->rowCount() === 0) {
                throw new Exception('Invalid document type selected.', 20);
            }

            $fields = json_decode($docTypeStmt->fetch(PDO::FETCH_ASSOC)['fields'], true);
            if (!isset($fields[$field_index])) {
                throw new Exception('Invalid field index.', 20);
            }
            if (in_array($fields[$field_index]['name'], ['date_released', 'date_received'])) {
                throw new Exception('Cannot delete mandatory fields (Date Released or Date Received).', 20);
            }
            if (isFieldUsed($pdo, $document_type_id, $fields[$field_index]['name'])) {
                throw new Exception('Cannot delete field used in existing documents.', 20);
            }

            $stmt = executeQuery(
                $pdo,
                "UPDATE document_types SET fields = JSON_REMOVE(fields, '$[$field_index]') WHERE document_type_id = ?",
                [$document_type_id]
            );
            if (!$stmt) {
                throw new Exception('Failed to delete field.', 20);
            }

            $pdo->commit();
            $message = "Deleted field: {$fields[$field_index]['label']} from document type ID: $document_type_id";
            logTransaction($pdo, $userId, 'completed', 20, $message);
            $response = ['success' => true, 'message' => $message];
            unset($fieldsCache[$document_type_id]);
        } elseif ($action === 'delete_fields') {
            $document_type_id = filter_var($_POST['document_type_id'], FILTER_VALIDATE_INT);
            $field_indices = isset($_POST['field_indices']) ? json_decode($_POST['field_indices'], true) : [];
            $field_indices = array_filter($field_indices, fn($index) => filter_var($index, FILTER_VALIDATE_INT) !== false);

            if (!$document_type_id || empty($field_indices)) {
                throw new Exception('Invalid document type or field indices.', 23);
            }

            $docTypeStmt = executeQuery($pdo, "SELECT fields FROM document_types WHERE document_type_id = ?", [$document_type_id]);
            if (!$docTypeStmt || $docTypeStmt->rowCount() === 0) {
                throw new Exception('Invalid document type selected.', 23);
            }

            $fields = json_decode($docTypeStmt->fetch(PDO::FETCH_ASSOC)['fields'], true);
            $deletedLabels = [];
            foreach ($field_indices as $index) {
                if (!isset($fields[$index])) {
                    throw new Exception('Invalid field index: ' . $index, 23);
                }
                if (in_array($fields[$index]['name'], ['date_released', 'date_received'])) {
                    throw new Exception('Cannot delete mandatory fields (Date Released or Date Received).', 23);
                }
                if (isFieldUsed($pdo, $document_type_id, $fields[$index]['name'])) {
                    throw new Exception("Cannot delete field '{$fields[$index]['label']}' used in existing documents.", 23);
                }
                $deletedLabels[] = $fields[$index]['label'];
            }

            $query = "UPDATE document_types SET fields = JSON_REMOVE(fields" . str_repeat(", '$[?]'", count($field_indices)) . ") WHERE document_type_id = ?";
            $params = array_merge($field_indices, [$document_type_id]);
            $stmt = executeQuery($pdo, $query, $params);
            if (!$stmt) {
                throw new Exception('Failed to delete fields.', 23);
            }

            $pdo->commit();
            $message = "Deleted fields: " . implode(', ', $deletedLabels) . " from document type ID: $document_type_id";
            logTransaction($pdo, $userId, 'completed', 23, $message);
            $response = ['success' => true, 'message' => $message];
            unset($fieldsCache[$document_type_id]);
        } elseif ($action === 'delete_document_types') {
            $ids = isset($_POST['ids']) ? json_decode($_POST['ids'], true) : [];
            $ids = array_filter($ids, fn($id) => filter_var($id, FILTER_VALIDATE_INT) !== false);

            if (empty($ids)) {
                throw new Exception('No valid document type IDs provided.', 21);
            }

            $checkStmt = executeQuery($pdo, "SELECT document_type_id FROM files WHERE document_type_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", $ids);
            if ($checkStmt && $checkStmt->rowCount() > 0) {
                throw new Exception('Cannot delete document types with associated files.', 21);
            }

            $stmt = executeQuery($pdo, "UPDATE document_types SET is_active = 0 WHERE document_type_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", $ids);
            if (!$stmt) {
                throw new Exception('Failed to delete document types.', 21);
            }

            $pdo->commit();
            $message = "Deleted " . count($ids) . " document type(s)";
            logTransaction($pdo, $userId, 'completed', 21, $message);
            $response = ['success' => true, 'message' => $message];
        } elseif ($action === 'reorder_fields') {
            $document_type_id = filter_var($_POST['document_type_id'], FILTER_VALIDATE_INT);
            $field_order = isset($_POST['field_order']) ? json_decode($_POST['field_order'], true) : [];

            if (!$document_type_id || empty($field_order)) {
                throw new Exception('Invalid document type or field order.', 22);
            }

            $docTypeStmt = executeQuery($pdo, "SELECT fields FROM document_types WHERE document_type_id = ?", [$document_type_id]);
            if (!$docTypeStmt || $docTypeStmt->rowCount() === 0) {
                throw new Exception('Invalid document type selected.', 22);
            }

            $fields = json_decode($docTypeStmt->fetch(PDO::FETCH_ASSOC)['fields'], true);
            if (count($field_order) !== count($fields)) {
                throw new Exception('Field order does not match existing fields.', 22);
            }

            // Ensure mandatory fields (date_released, date_received) remain first
            $mandatoryFields = array_filter($fields, fn($f) => in_array($f['name'], ['date_released', 'date_received']));
            $nonMandatoryFields = array_filter($fields, fn($f) => !in_array($f['name'], ['date_released', 'date_received']));
            $newOrder = [];
            foreach ($field_order as $index) {
                if (!isset($fields[$index])) {
                    throw new Exception('Invalid field index in order.', 22);
                }
                $newOrder[] = $fields[$index];
            }
            $mandatoryIndices = array_keys($mandatoryFields);
            foreach ($mandatoryIndices as $i => $index) {
                if ($field_order[$i] != $index) {
                    throw new Exception('Mandatory fields (Date Released, Date Received) must remain first.', 22);
                }
            }

            $stmt = executeQuery(
                $pdo,
                "UPDATE document_types SET fields = ? WHERE document_type_id = ?",
                [json_encode($newOrder), $document_type_id]
            );
            if (!$stmt) {
                throw new Exception('Failed to reorder fields.', 22);
            }

            $pdo->commit();
            $message = "Reordered fields for document type ID: $document_type_id";
            logTransaction($pdo, $userId, 'completed', 22, $message);
            $response = ['success' => true, 'message' => $message];
            unset($fieldsCache[$document_type_id]);
        }
    } catch (Exception $e) {
        // Check if a transaction is active before attempting rollback
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Map actions to transaction types
        $actionToTransType = [
            'add_document_type' => 16,
            'edit_document_type' => 17,
            'add_field' => 18,
            'edit_field' => 19,
            'delete_field' => 20,
            'delete_document_types' => 21,
            'reorder_fields' => 22,
            'delete_fields' => 23,
        ];

        // Determine transaction type, default to 0 for unknown actions
        $transType = $e->getCode() ?: ($actionToTransType[$action] ?? 0);

        // Sanitize error message to avoid exposing sensitive information
        $errorMessage = $e instanceof PDOException
            ? 'A database error occurred. Please try again later.'
            : $e->getMessage();

        // Log the transaction, with fallback if logging fails
        $logSuccess = logTransaction($pdo, $userId, 'failed', $transType, $errorMessage);
        if (!$logSuccess) {
            error_log("Failed to log transaction: Action=$action, Error=" . $e->getMessage());
        }

        // Prepare response with sanitized message
        $response = [
            'success' => false,
            'message' => $errorMessage,
        ];
    }

    // FIX: Ensure proper JSON response even on errors
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Handle GET requests (no CSRF validation)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    $action = filter_input(INPUT_GET, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $response = ['success' => false, 'message' => ''];

    if ($action === 'get_document_type') {
        $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
        if ($id) {
            $stmt = executeQuery($pdo, "SELECT document_type_id as id, type_name as name FROM document_types WHERE document_type_id = ? AND is_active = 1", [$id]);
            if ($stmt && $data = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $response = ['success' => true, 'document_type' => $data];
            } else {
                $response['message'] = 'Document type not found.';
            }
        } else {
            $response['message'] = 'Invalid document type ID.';
        }
    } elseif ($action === 'get_field') {
        $document_type_id = filter_var($_GET['document_type_id'], FILTER_VALIDATE_INT);
        $field_index = filter_var($_GET['field_index'], FILTER_VALIDATE_INT);
        if ($document_type_id && isset($field_index)) {
            $fields = $fieldsCache[$document_type_id] ?? null;
            if (!$fields) {
                $stmt = executeQuery($pdo, "SELECT fields FROM document_types WHERE document_type_id = ? AND is_active = 1", [$document_type_id]);
                if ($stmt && $data = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $fields = json_decode($data['fields'], true);
                    $fieldsCache[$document_type_id] = $fields;
                } else {
                    $response['message'] = 'Document type not found.';
                    header('Content-Type: application/json');
                    echo json_encode($response);
                    exit;
                }
            }
            if (isset($fields[$field_index])) {
                $response = ['success' => true, 'field' => $fields[$field_index]];
            } else {
                $response['message'] = 'Field not found.';
            }
        } else {
            $response['message'] = 'Invalid document type ID or field index.';
        }
    } elseif ($action === 'get_fields') {
        $document_type_id = filter_var($_GET['document_type_id'], FILTER_VALIDATE_INT);
        if ($document_type_id) {
            $fields = $fieldsCache[$document_type_id] ?? null;
            if (!$fields) {
                $stmt = executeQuery($pdo, "SELECT fields FROM document_types WHERE document_type_id = ? AND is_active = 1", [$document_type_id]);
                if ($stmt && $data = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $fields = json_decode($data['fields'], true);
                    $fieldsCache[$document_type_id] = $fields;
                    $response = ['success' => true, 'fields' => $fields];
                } else {
                    $response['message'] = 'Failed to load fields.';
                }
            } else {
                $response = ['success' => true, 'fields' => $fields];
            }
        } else {
            $response['message'] = 'Invalid document type ID.';
        }
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Fetch document types with pagination
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?? 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;
$sort = filter_input(INPUT_GET, 'sort', FILTER_SANITIZE_FULL_SPECIAL_CHARS, ['options' => ['default' => 'type_name ASC']]) ?? 'type_name ASC';

$documentTypesStmt = executeQuery(
    $pdo,
    "SELECT document_type_id as id, type_name, fields FROM document_types WHERE is_active = 1 ORDER BY $sort LIMIT ? OFFSET ?",
    [$perPage, $offset]
);
$documentTypes = $documentTypesStmt ? $documentTypesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$totalStmt = executeQuery($pdo, "SELECT COUNT(*) as total FROM document_types WHERE is_active = 1");
$total = $totalStmt ? $totalStmt->fetch(PDO::FETCH_ASSOC)['total'] : 0;
$totalPages = ceil($total / $perPage);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">

    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css">
    <link rel="stylesheet" href="style/doc_type_management.css">
    <link rel="stylesheet" href="style/admin-sidebar.css">


    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>

</head>

<body class="document-type-management">
    <?php include 'admin_menu.php'; ?>
    <div class="main-content document-type-management">
        <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        <?php if (!empty($error)) { ?>
            <div class="error-message"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php } ?>
        <?php if (!empty($success)) { ?>
            <div class="success-message"><?php echo htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php } ?>
        <h2>Document Type Management</h2>
        <div class="controls">
            <div>
                <button class="open-modal-btn" onclick="openModal('add')" aria-label="Add Document Type"><i class="fas fa-plus"></i> Add Document Type</button>
                <button class="delete-btn" onclick="deleteSelected()" disabled id="bulkDeleteBtn" aria-label="Delete Selected Document Types"><i class="fas fa-trash"></i> Delete Selected</button>
            </div>
            <div class="pagination-controls">
                <label for="sortSelect">Sort by:</label>
                <select id="sortSelect" onchange="updateSort()" aria-label="Sort Document Types">
                    <option value="type_name ASC" <?php echo $sort === 'type_name ASC' ? 'selected' : ''; ?>>Name (A-Z)</option>
                    <option value 'type_name DESC' <?php echo $sort === 'type_name DESC' ? 'selected' : ''; ?>>Name (Z-A)</option>
                </select>
                <div class="pagination">
                    <button onclick="changePage(<?php echo $page - 1; ?>)" <?php echo $page <= 1 ? 'disabled' : ''; ?> aria-label="Previous Page"><i class="fas fa-chevron-left"></i></button>
                    <span id="pageInfo">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                    <button onclick="changePage(<?php echo $page + 1; ?>)" <?php echo $page >= $totalPages ? 'disabled' : ''; ?> aria-label="Next Page"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
        </div>
        <div class="document-type-section">
            <div class="document-grid">
                <?php foreach ($documentTypes as $type) { ?>
                    <div class="document-card" data-id="<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        <h3 onclick="toggleFields(<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>)">
                            <input type="checkbox" class="select-type" data-id="<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" onchange="toggleBulkDelete()" aria-label="Select Document Type">
                            <i class="fas fa-chevron-down"></i>
                            <?php echo htmlspecialchars($type['type_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            <div class="action-buttons" style="margin-left: auto;">
                                <button class="edit-btn" onclick="openModal('edit', <?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>, event)" aria-label="Edit Document Type"><i class="fas fa-edit"></i></button>
                                <button class="delete-btn" onclick="deleteDocumentType(<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>, event)" aria-label="Delete Document Type"><i class="fas fa-trash"></i></button>
                            </div>
                        </h3>
                        <div class="fields-container" id="fields-container-<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <div class="loading" id="loading-<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" style="display: none;">
                                <span class="download-loading"></span> Loading...
                            </div>
                            <table class="fields-table" id="fields-<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="select-all-<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" onchange="toggleFieldSelection(<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>)"></th>
                                        <th>Field Name</th>
                                        <th>Label</th>
                                        <th>Type</th>
                                        <th>Required</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                            <div class="action-buttons">
                                <button class="edit-btn" onclick="addFieldModal(<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>, event)" aria-label="Add Field"><i class="fas fa-plus"></i> Add Field</button>
                                <button class="reorder-btn" onclick="reorderFields(<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>, event)" aria-label="Reorder Fields"><i class="fas fa-sort"></i> Reorder Fields</button>
                                <button class="delete-btn" onclick="deleteSelectedFields(<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>)" disabled id="bulkFieldDelete-<?php echo htmlspecialchars((string)$type['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" aria-label="Delete Selected Fields"><i class="fas fa-trash"></i> Delete Selected</button>
                            </div>
                        </div>
                    </div>
                <?php } ?>
                <?php if (empty($documentTypes)) { ?>
                    <p class="no-data">No document types found.</p>
                <?php } ?>
            </div>
        </div>
    </div>
    <div class="modal" id="documentTypeModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('documentTypeModal')">&times;</span>
            <h3 id="modalTitle">Add Document Type</h3>
            <form id="documentTypeForm">
                <input type="hidden" id="documentTypeId" name="id">
                <div class="form-group">
                    <label for="typeName">Document Type Name:</label>
                    <input type="text" id="typeName" name="name" required maxlength="50" aria-required="true">
                </div>
                <div class="form-actions">
                    <button type="button" onclick="closeModal('documentTypeModal')">Cancel</button>
                    <button type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>
    <div class="modal" id="fieldModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('fieldModal')">&times;</span>
            <h3 id="fieldModalTitle">Add Field</h3>
            <form id="fieldForm">
                <input type="hidden" id="fieldDocumentTypeId" name="document_type_id">
                <input type="hidden" id="fieldIndex" name="field_index">
                <div class="form-group">
                    <label for="fieldName">Field Name:</label>
                    <input type="text" id="fieldName" name="field_name" required maxlength="50" pattern="[a-zA-Z0-9_]+" title="Alphanumeric characters and underscores only" aria-required="true">
                </div>
                <div class="form-group">
                    <label for="fieldLabel">Field Label:</label>
                    <input type="text" id="fieldLabel" name="field_label" required maxlength="50" aria-required="true">
                </div>
                <div class="form-group">
                    <label for="fieldType">Field Type:</label>
                    <select id="fieldType" name="field_type" required aria-required="true">
                        <option value="text">Text</option>
                        <option value="number">Number</option>
                        <option value="date">Date</option>
                        <option value="file">File</option>
                    </select>
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="isRequired" name="is_required" value="1">
                    <label for="isRequired">Required Field</label>
                </div>
                <div class="form-actions">
                    <button type="button" onclick="closeModal('fieldModal')">Cancel</button>
                    <button type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>
    <div class="modal" id="reorderModal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('reorderModal')">&times;</span>
            <h3>Reorder Fields</h3>
            <p>Drag and drop to reorder fields. Mandatory fields (Date Released, Date Received) must remain first.</p>
            <ul id="sortableFields"></ul>
            <div class="form-actions">
                <button type="button" onclick="closeModal('reorderModal')">Cancel</button>
                <button type="button" onclick="saveFieldOrder()">Save Order</button>
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
    <script>
        // CSRF token for AJAX requests
        const csrfToken = document.getElementById('csrf_token').value;

        // Notyf for notifications
        const notyf = new Notyf({
            duration: 5000,
            position: {
                x: 'right',
                y: 'top',
            },
            types: [{
                type: 'success',
                background: '#4caf50',
                dismissible: true
            }, {
                type: 'error',
                background: '#f44336',
                dismissible: true
            }]
        });

        // Function to show error message
        function showError(message) {
            notyf.error(message);
        }

        // Function to show success message
        function showSuccess(message) {
            notyf.success(message);
        }

        // Open modal for adding/editing document type
        function openModal(action, id = null) {
            const modal = document.getElementById('documentTypeModal');
            const title = document.getElementById('modalTitle');
            const form = document.getElementById('documentTypeForm');
            const idInput = document.getElementById('documentTypeId');
            const nameInput = document.getElementById('typeName');

            if (action === 'add') {
                title.textContent = 'Add Document Type';
                form.reset();
                idInput.value = '';
            } else if (action === 'edit' && id) {
                title.textContent = 'Edit Document Type';
                fetchDocumentType(id).then(type => {
                    idInput.value = type.id;
                    nameInput.value = type.name;
                }).catch(error => {
                    showError('Failed to load document type: ' + error);
                    closeModal('documentTypeModal');
                });
            }

            modal.style.display = 'block';
        }

        // Close modal
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Fetch document type details
        async function fetchDocumentType(id) {
            const response = await fetch(`?action=get_document_type&id=${id}`);
            const data = await response.json();
            if (data.success) {
                return data.document_type;
            } else {
                throw new Error(data.message);
            }
        }

        // Toggle fields visibility
        function toggleFields(documentTypeId) {
            const container = document.getElementById(`fields-container-${documentTypeId}`);
            const chevron = container.previousElementSibling.querySelector('.fa-chevron-down');
            const tableBody = document.getElementById(`fields-${documentTypeId}`).querySelector('tbody');

            if (container.style.display === 'none' || container.style.display === '') {
                // Show fields
                document.getElementById(`loading-${documentTypeId}`).style.display = 'block';
                fetchFields(documentTypeId).then(fields => {
                    displayFields(documentTypeId, fields);
                    container.style.display = 'block';
                    chevron.classList.add('fa-chevron-up');
                    chevron.classList.remove('fa-chevron-down');
                    document.getElementById(`loading-${documentTypeId}`).style.display = 'none';
                }).catch(error => {
                    showError('Failed to load fields: ' + error);
                    document.getElementById(`loading-${documentTypeId}`).style.display = 'none';
                });
            } else {
                // Hide fields
                container.style.display = 'none';
                chevron.classList.remove('fa-chevron-up');
                chevron.classList.add('fa-chevron-down');
            }
        }

        // Fetch fields for a document type
        async function fetchFields(documentTypeId) {
            const response = await fetch(`?action=get_fields&document_type_id=${documentTypeId}`);
            const data = await response.json();
            if (data.success) {
                return data.fields;
            } else {
                throw new Error(data.message);
            }
        }

        // Display fields in table
        function displayFields(documentTypeId, fields) {
            const tableBody = document.getElementById(`fields-${documentTypeId}`).querySelector('tbody');
            tableBody.innerHTML = '';

            fields.forEach((field, index) => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td><input type="checkbox" class="field-checkbox" data-index="${index}" onchange="toggleFieldBulkDelete(${documentTypeId})"></td>
                    <td>${escapeHtml(field.name)}</td>
                    <td>${escapeHtml(field.label)}</td>
                    <td>${escapeHtml(field.type)}</td>
                    <td>${field.required ? 'Yes' : 'No'}</td>
                    <td>
                        <button class="edit-btn" onclick="editFieldModal(${documentTypeId}, ${index}, event)" aria-label="Edit Field"><i class="fas fa-edit"></i></button>
                        <button class="delete-btn" onclick="deleteField(${documentTypeId}, ${index}, event)" aria-label="Delete Field"><i class="fas fa-trash"></i></button>
                    </td>
                `;
                tableBody.appendChild(row);
            });
        }

        // Open modal for adding/editing field
        function addFieldModal(documentTypeId, event) {
            if (event) event.stopPropagation();
            const modal = document.getElementById('fieldModal');
            const title = document.getElementById('fieldModalTitle');
            const form = document.getElementById('fieldForm');
            const docTypeIdInput = document.getElementById('fieldDocumentTypeId');
            const fieldIndexInput = document.getElementById('fieldIndex');

            title.textContent = 'Add Field';
            form.reset();
            docTypeIdInput.value = documentTypeId;
            fieldIndexInput.value = '';
            document.getElementById('isRequired').checked = false;

            modal.style.display = 'block';
        }

        function editFieldModal(documentTypeId, fieldIndex, event) {
            if (event) event.stopPropagation();
            const modal = document.getElementById('fieldModal');
            const title = document.getElementById('fieldModalTitle');
            const form = document.getElementById('fieldForm');
            const docTypeIdInput = document.getElementById('fieldDocumentTypeId');
            const fieldIndexInput = document.getElementById('fieldIndex');

            title.textContent = 'Edit Field';
            form.reset();
            docTypeIdInput.value = documentTypeId;
            fieldIndexInput.value = fieldIndex;

            fetchField(documentTypeId, fieldIndex).then(field => {
                document.getElementById('fieldName').value = field.name;
                document.getElementById('fieldLabel').value = field.label;
                document.getElementById('fieldType').value = field.type;
                document.getElementById('isRequired').checked = field.required;
            }).catch(error => {
                showError('Failed to load field: ' + error);
                closeModal('fieldModal');
            });

            modal.style.display = 'block';
        }

        // Fetch field details
        async function fetchField(documentTypeId, fieldIndex) {
            const response = await fetch(`?action=get_field&document_type_id=${documentTypeId}&field_index=${fieldIndex}`);
            const data = await response.json();
            if (data.success) {
                return data.field;
            } else {
                throw new Error(data.message);
            }
        }

        // Delete document type
        function deleteDocumentType(id, event) {
            if (event) event.stopPropagation();
            if (confirm('Are you sure you want to delete this document type?')) {
                const formData = new FormData();
                formData.append('action', 'delete_document_types');
                formData.append('ids', JSON.stringify([id]));
                formData.append('csrf_token', csrfToken);

                fetch('', {
                        method: 'POST',
                        body: formData
                    }).then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess(data.message);
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            showError(data.message);
                        }
                    }).catch(error => {
                        showError('Failed to delete document type: ' + error);
                    });
            }
        }

        // Delete field
        function deleteField(documentTypeId, fieldIndex, event) {
            if (event) event.stopPropagation();
            if (confirm('Are you sure you want to delete this field?')) {
                const formData = new FormData();
                formData.append('action', 'delete_field');
                formData.append('document_type_id', documentTypeId);
                formData.append('field_index', fieldIndex);
                formData.append('csrf_token', csrfToken);

                fetch('', {
                        method: 'POST',
                        body: formData
                    }).then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess(data.message);
                            // Refresh the fields display
                            fetchFields(documentTypeId).then(fields => {
                                displayFields(documentTypeId, fields);
                                toggleFieldBulkDelete(documentTypeId);
                            });
                        } else {
                            showError(data.message);
                        }
                    }).catch(error => {
                        showError('Failed to delete field: ' + error);
                    });
            }
        }

        // Toggle bulk delete button for document types
        function toggleBulkDelete() {
            const checkboxes = document.querySelectorAll('.select-type:checked');
            const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
            bulkDeleteBtn.disabled = checkboxes.length === 0;
        }

        // Toggle bulk delete button for fields
        function toggleFieldBulkDelete(documentTypeId) {
            const checkboxes = document.querySelectorAll(`#fields-${documentTypeId} .field-checkbox:checked`);
            const bulkDeleteBtn = document.getElementById(`bulkFieldDelete-${documentTypeId}`);
            bulkDeleteBtn.disabled = checkboxes.length === 0;
        }

        // Toggle all field selection for a document type
        function toggleFieldSelection(documentTypeId) {
            const selectAll = document.getElementById(`select-all-${documentTypeId}`);
            const checkboxes = document.querySelectorAll(`#fields-${documentTypeId} .field-checkbox`);
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            toggleFieldBulkDelete(documentTypeId);
        }

        // Delete selected document types
        function deleteSelected() {
            const selectedIds = Array.from(document.querySelectorAll('.select-type:checked')).map(cb => parseInt(cb.dataset.id));
            if (selectedIds.length === 0) return;

            if (confirm(`Are you sure you want to delete ${selectedIds.length} document type(s)?`)) {
                const formData = new FormData();
                formData.append('action', 'delete_document_types');
                formData.append('ids', JSON.stringify(selectedIds));
                formData.append('csrf_token', csrfToken);

                fetch('', {
                        method: 'POST',
                        body: formData
                    }).then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess(data.message);
                            setTimeout(() => location.reload(), 1000);
                        } else {
                            showError(data.message);
                        }
                    }).catch(error => {
                        showError('Failed to delete document types: ' + error);
                    });
            }
        }

        // Delete selected fields
        function deleteSelectedFields(documentTypeId) {
            const selectedIndices = Array.from(document.querySelectorAll(`#fields-${documentTypeId} .field-checkbox:checked`)).map(cb => parseInt(cb.dataset.index));
            if (selectedIndices.length === 0) return;

            if (confirm(`Are you sure you want to delete ${selectedIndices.length} field(s)?`)) {
                const formData = new FormData();
                formData.append('action', 'delete_fields');
                formData.append('document_type_id', documentTypeId);
                formData.append('field_indices', JSON.stringify(selectedIndices));
                formData.append('csrf_token', csrfToken);

                fetch('', {
                        method: 'POST',
                        body: formData
                    }).then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess(data.message);
                            // Refresh the fields display
                            fetchFields(documentTypeId).then(fields => {
                                displayFields(documentTypeId, fields);
                                toggleFieldBulkDelete(documentTypeId);
                                document.getElementById(`select-all-${documentTypeId}`).checked = false;
                            });
                        } else {
                            showError(data.message);
                        }
                    }).catch(error => {
                        showError('Failed to delete fields: ' + error);
                    });
            }
        }

        // Reorder fields modal
        function reorderFields(documentTypeId, event) {
            if (event) event.stopPropagation();
            const modal = document.getElementById('reorderModal');
            const list = document.getElementById('sortableFields');
            list.innerHTML = '';

            fetchFields(documentTypeId).then(fields => {
                fields.forEach((field, index) => {
                    const li = document.createElement('li');
                    li.className = 'ui-state-default';
                    li.dataset.index = index;
                    li.innerHTML = `
                        <span class="ui-icon ui-icon-arrowthick-2-n-s"></span>
                        ${escapeHtml(field.label)} (${escapeHtml(field.name)})
                    `;
                    list.appendChild(li);
                });

                // Make the list sortable
                $(list).sortable({
                    placeholder: "ui-state-highlight",
                    update: function(event, ui) {
                        // Validate that mandatory fields remain first
                        const mandatoryFields = fields.filter(f => ['date_released', 'date_received'].includes(f.name));
                        const mandatoryIndices = mandatoryFields.map(f => fields.indexOf(f));

                        for (let i = 0; i < mandatoryIndices.length; i++) {
                            const currentIndex = Array.from(list.children).findIndex(li => parseInt(li.dataset.index) === mandatoryIndices[i]);
                            if (currentIndex !== i) {
                                showError('Mandatory fields (Date Released, Date Received) must remain first.');
                                $(list).sortable('cancel');
                                return;
                            }
                        }
                    }
                }).disableSelection();

                modal.dataset.documentTypeId = documentTypeId;
                modal.style.display = 'block';
            }).catch(error => {
                showError('Failed to load fields for reordering: ' + error);
            });
        }

        // Save field order
        function saveFieldOrder() {
            const modal = document.getElementById('reorderModal');
            const documentTypeId = modal.dataset.documentTypeId;
            const list = document.getElementById('sortableFields');
            const fieldOrder = Array.from(list.children).map(li => parseInt(li.dataset.index));

            const formData = new FormData();
            formData.append('action', 'reorder_fields');
            formData.append('document_type_id', documentTypeId);
            formData.append('field_order', JSON.stringify(fieldOrder));
            formData.append('csrf_token', csrfToken);

            fetch('', {
                    method: 'POST',
                    body: formData
                }).then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccess(data.message);
                        closeModal('reorderModal');
                        // Refresh the fields display if it's open
                        const container = document.getElementById(`fields-container-${documentTypeId}`);
                        if (container.style.display === 'block') {
                            fetchFields(documentTypeId).then(fields => {
                                displayFields(documentTypeId, fields);
                            });
                        }
                    } else {
                        showError(data.message);
                    }
                }).catch(error => {
                    showError('Failed to save field order: ' + error);
                });
        }

        // Change page
        function changePage(page) {
            const url = new URL(window.location);
            url.searchParams.set('page', page);
            window.location = url.toString();
        }

        // Update sort
        function updateSort() {
            const sortSelect = document.getElementById('sortSelect');
            const url = new URL(window.location);
            url.searchParams.set('sort', sortSelect.value);
            url.searchParams.set('page', '1'); // Reset to first page when changing sort
            window.location = url.toString();
        }

        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Form submission handlers
        document.getElementById('documentTypeForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', this.querySelector('#documentTypeId').value ? 'edit_document_type' : 'add_document_type');
            formData.append('csrf_token', csrfToken);

            fetch('', {
                    method: 'POST',
                    body: formData
                }).then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccess(data.message);
                        closeModal('documentTypeModal');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showError(data.message);
                    }
                }).catch(error => {
                    showError('Failed to save document type: ' + error);
                });
        });

        document.getElementById('fieldForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', this.querySelector('#fieldIndex').value ? 'edit_field' : 'add_field');
            formData.append('csrf_token', csrfToken);

            fetch('', {
                    method: 'POST',
                    body: formData
                }).then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccess(data.message);
                        closeModal('fieldModal');
                        // Refresh the fields display if it's open
                        const documentTypeId = formData.get('document_type_id');
                        const container = document.getElementById(`fields-container-${documentTypeId}`);
                        if (container.style.display === 'block') {
                            fetchFields(documentTypeId).then(fields => {
                                displayFields(documentTypeId, fields);
                            });
                        }
                    } else {
                        showError(data.message);
                    }
                }).catch(error => {
                    showError('Failed to save field: ' + error);
                });
        });

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let i = 0; i < modals.length; i++) {
                if (event.target === modals[i]) {
                    modals[i].style.display = 'none';
                }
            }
        };
    </script>
</body>

</html>