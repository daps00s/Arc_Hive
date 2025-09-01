<?php
// api/file_operations.php
session_start();
require '../db_connection.php';
require '../log_activity.php';
require '../notification.php';

header('Content-Type: application/json');

function validate_csrf_token($token)
{
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        error_log("CSRF validation failed for user_id: " . ($_SESSION['user_id'] ?? 'unknown'));
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
}

function send_response($success, $message = '', $data = [], $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

if (empty($_SESSION['user_id'])) {
    error_log("Unauthorized access: user_id not set in session");
    send_response(false, 'Unauthorized access', [], 401);
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT) ?: 0;
$userRole = trim($_SESSION['role'] ?? 'client');

try {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'fetch_document_types':
            validate_csrf_token($_POST['csrf_token']);
            $stmt = $pdo->prepare("
                SELECT document_type_id, type_name AS name
                FROM document_types
                WHERE is_active = 1
                ORDER BY type_name ASC
            ");
            $stmt->execute();
            $documentTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            send_response(true, 'Document types fetched successfully', ['document_types' => $documentTypes]);
            break;

        case 'fetch_sub_departments':
            validate_csrf_token($_POST['csrf_token']);
            $deptId = isset($_POST['department_id']) ? filter_var($_POST['department_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : 0;
            if ($deptId === false) {
                send_response(false, 'Invalid department ID', [], 400);
            }
            error_log("Fetching sub-departments for department_id: $deptId, user_id: $userId");
            $subDepartments = [];
            if ($deptId === 0) {
                // Fetch top-level departments where user is assigned
                $stmt = $pdo->prepare("
                    SELECT DISTINCT d.department_id, d.department_name
                    FROM departments d
                    WHERE d.parent_department_id IS NULL
                    AND EXISTS (
                        SELECT 1 FROM user_department_assignments uda
                        LEFT JOIN departments sd ON uda.department_id = sd.department_id
                        WHERE uda.user_id = ?
                        AND (sd.parent_department_id = d.department_id OR uda.department_id = d.department_id)
                    )
                    ORDER BY d.department_name ASC
                ");
                $stmt->execute([$userId]);
                $subDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("Top-level departments fetched: " . json_encode($subDepartments));
            } else {
                // Fetch all sub-departments under the selected department
                $stmt = $pdo->prepare("
                    SELECT d.department_id, d.department_name
                    FROM departments d
                    WHERE d.parent_department_id = ?
                    ORDER BY d.department_name ASC
                ");
                $stmt->execute([$deptId]);
                $subDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("Sub-departments fetched for deptId $deptId: " . json_encode($subDepartments));
            }
            if (empty($subDepartments)) {
                error_log("No sub-departments found for department_id: $deptId");
            }
            send_response(true, 'Departments fetched successfully', ['sub_departments' => $subDepartments]);
            break;

        case 'fetch_doc_fields':
            validate_csrf_token($_POST['csrf_token']);
            $docTypeId = filter_var($_POST['document_type_id'], FILTER_VALIDATE_INT);
            if (!$docTypeId) {
                send_response(false, 'Invalid document type ID', [], 400);
            }
            $stmt = $pdo->prepare("
                SELECT fields 
                FROM document_types 
                WHERE document_type_id = ? AND is_active = 1
            ");
            $stmt->execute([$docTypeId]);
            $documentType = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$documentType || empty($documentType['fields'])) {
                send_response(false, 'No fields found for this document type', [], 404);
            }
            $fieldsData = json_decode($documentType['fields'], true);
            $formattedFields = [];
            foreach ($fieldsData as $field) {
                $formattedFields[] = [
                    'id' => $field['id'] ?? uniqid('field_'),
                    'name' => $field['name'] ?? 'Unknown',
                    'type' => $field['type'] ?? 'text',
                    'required' => $field['required'] ?? false
                ];
            }
            send_response(true, 'Document fields fetched successfully', ['fields' => $formattedFields]);
            break;

        case 'fetch_storage_locations':
            validate_csrf_token($_POST['csrf_token']);
            $deptId = filter_var($_POST['department_id'], FILTER_VALIDATE_INT) ?: null;
            $subDeptId = filter_var($_POST['sub_department_id'], FILTER_VALIDATE_INT) ?: null;

            if (!$deptId && !$subDeptId) {
                send_response(false, 'Department or sub-department ID required', [], 400);
            }

            // Check for existing folder with capacity
            $query = "
                SELECT sl.storage_location_id, sl.full_path,
                       (SELECT COUNT(*) FROM files f WHERE f.storage_location_id = sl.storage_location_id) AS current_files,
                       sl.folder_capacity
                FROM storage_locations sl
                WHERE sl.unit_type = 'folder'
                AND sl.folder_capacity > (
                    SELECT COUNT(*) 
                    FROM files f 
                    WHERE f.storage_location_id = sl.storage_location_id
                )
            ";

            $params = [];
            if ($subDeptId) {
                $query .= " AND sl.sub_department_id = ?";
                $params[] = $subDeptId;
            } elseif ($deptId) {
                $query .= " AND sl.department_id = ? AND sl.sub_department_id IS NULL";
                $params[] = $deptId;
            }

            $query .= " ORDER BY sl.storage_location_id ASC LIMIT 1";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $location = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($location) {
                send_response(true, 'Storage location fetched successfully', [
                    'locations' => [
                        [
                            'storage_location_id' => $location['storage_location_id'],
                            'full_path' => $location['full_path']
                        ]
                    ]
                ]);
            }

            // Check if any storage hierarchy exists
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM storage_locations 
                WHERE unit_type = 'cabinet' 
                AND (department_id = ? OR sub_department_id = ?)
            ");
            $checkStmt->execute([$deptId ?: null, $subDeptId ?: null]);
            $cabinetCount = $checkStmt->fetchColumn();

            if ($cabinetCount == 0) {
                send_response(false, 'No storage cabinets exist for this department/sub-department. Please contact an administrator to set up storage.', [], 404);
            }

            // Check if folders exist but are full
            $folderCheckStmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM storage_locations 
                WHERE unit_type = 'folder' 
                AND (department_id = ? OR sub_department_id = ?)
            ");
            $folderCheckStmt->execute([$deptId ?: null, $subDeptId ?: null]);
            $folderCount = $folderCheckStmt->fetchColumn();

            if ($folderCount > 0) {
                send_response(false, 'No available space in this location.', [], 400);
            }

            // Create new storage hierarchy
            $pdo->beginTransaction();
            try {
                $deptName = '';
                if ($deptId) {
                    $stmt = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = ?");
                    $stmt->execute([$deptId]);
                    $deptName = $stmt->fetchColumn();
                }
                $subDeptName = '';
                if ($subDeptId) {
                    $stmt = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = ?");
                    $stmt->execute([$subDeptId]);
                    $subDeptName = $stmt->fetchColumn();
                }

                // Find next available unit numbers
                $stmt = $pdo->prepare("
                    SELECT MAX(CAST(SUBSTRING(unit_name, 2) AS UNSIGNED)) as max_num
                    FROM storage_locations
                    WHERE unit_type = ? 
                    AND (department_id = ? OR sub_department_id = ?)
                ");

                $unitTypes = ['room', 'cabinet', 'layer', 'box', 'folder'];
                $unitNames = [];
                foreach ($unitTypes as $type) {
                    $stmt->execute([$type, $deptId ?: null, $subDeptId ?: null]);
                    $maxNum = $stmt->fetchColumn() ?: 0;
                    $unitNames[$type] = sprintf('%s%03d', strtoupper(substr($type, 0, 1)), $maxNum + 1);
                }

                // Build storage hierarchy backwards
                $parentId = null;
                $newLocationId = null;
                $fullPathParts = [];

                // Get base path
                $basePath = ($deptName ? $deptName : '') . ($subDeptName ? ($deptName ? ' > ' : '') . $subDeptName : '');

                foreach (array_reverse($unitTypes) as $type) {
                    $unitPath = $basePath;
                    if (!empty($fullPathParts)) {
                        $unitPath .= ' > ' . implode(' > ', array_reverse($fullPathParts));
                    }
                    $unitPath .= ' > ' . $unitNames[$type];

                    $stmt = $pdo->prepare("
                        INSERT INTO storage_locations (
                            department_id, sub_department_id, unit_type, unit_name,
                            parent_storage_location_id, full_path, folder_capacity
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $deptId,
                        $subDeptId,
                        $type,
                        $unitNames[$type],
                        $parentId,
                        $unitPath,
                        $type === 'folder' ? 100 : null
                    ]);

                    $parentId = $pdo->lastInsertId();
                    if ($type === 'folder') {
                        $newLocationId = $parentId;
                    }
                    $fullPathParts[] = $unitNames[$type];
                }

                $pdo->commit();
                send_response(true, 'Storage location created and fetched successfully', [
                    'locations' => [
                        [
                            'storage_location_id' => $newLocationId,
                            'full_path' => $unitPath
                        ]
                    ]
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'upload_file':
            validate_csrf_token($_POST['csrf_token']);
            // Handle upload logic (assuming it's here or in another file, but since not provided, skip)
            break;

        case 'load_files_for_sending':
            validate_csrf_token($_POST['csrf_token']);
            $stmt = $pdo->prepare("
                SELECT f.file_id, f.file_name, f.upload_date, f.copy_type, dt.type_name AS document_type, d.department_name
                FROM files f
                LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
                LEFT JOIN departments d ON f.department_id = d.department_id
                WHERE f.user_id = ? OR f.department_id IN (
                    SELECT department_id 
                    FROM user_department_assignments 
                    WHERE user_id = ?
                )
                ORDER BY f.upload_date DESC
                LIMIT 50
            ");
            $stmt->execute([$userId, $userId]);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
            send_response(true, 'Files for sending fetched successfully', ['files' => $files]);
            break;

        case 'load_recipients':
            validate_csrf_token($_POST['csrf_token']);
            $stmt = $pdo->prepare("
                SELECT user_id, username
                FROM users
                WHERE user_id != ? AND role != 'admin'
                ORDER BY username ASC
            ");
            $stmt->execute([$userId]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT d.department_id, d.department_name
                FROM departments d
                INNER JOIN user_department_assignments uda ON d.department_id = uda.department_id
                WHERE uda.user_id = ? 
                ORDER BY d.department_name ASC
            ");
            $stmt->execute([$userId]);
            $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            send_response(true, 'Recipients fetched successfully', [
                'users' => $users,
                'departments' => $departments
            ]);
            break;

        case 'fetch_uploaded_files':
            validate_csrf_token($_POST['csrf_token']);
            $sort = in_array($_POST['sort'] ?? 'date-desc', ['date-asc', 'date-desc', 'name-asc', 'name-desc']) ? $_POST['sort'] : 'date-desc';
            $orderBy = [
                'date-asc' => 'f.upload_date ASC',
                'date-desc' => 'f.upload_date DESC',
                'name-asc' => 'f.file_name ASC',
                'name-desc' => 'f.file_name DESC'
            ][$sort];

            $stmt = $pdo->prepare("
                SELECT f.file_id, f.file_name, f.upload_date, f.copy_type, dt.type_name AS document_type, d.department_name
                FROM files f
                LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
                LEFT JOIN departments d ON f.department_id = d.department_id
                WHERE f.user_id = ? OR f.department_id IN (
                    SELECT department_id 
                    FROM user_department_assignments 
                    WHERE user_id = ?
                )
                ORDER BY $orderBy
                LIMIT 50
            ");
            $stmt->execute([$userId, $userId]);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
            send_response(true, 'Files fetched successfully', ['files' => $files]);
            break;

        case 'search_files':
            validate_csrf_token($_POST['csrf_token']);
            $query = trim($_POST['query'] ?? '');
            if (empty($query)) {
                send_response(false, 'Search query is empty', [], 400);
            }
            $searchTerm = '%' . $query . '%';
            $stmt = $pdo->prepare("
                SELECT f.file_id, f.file_name, f.upload_date, f.copy_type, dt.type_name AS document_type, d.department_name
                FROM files f
                LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
                LEFT JOIN departments d ON f.department_id = d.department_id
                WHERE (f.user_id = ? OR f.department_id IN (
                    SELECT department_id 
                    FROM user_department_assignments 
                    WHERE user_id = ?
                ))
                AND (f.file_name LIKE ? OR dt.type_name LIKE ?)
                ORDER BY f.upload_date DESC
                LIMIT 50
            ");
            $stmt->execute([$userId, $userId, $searchTerm, $searchTerm]);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
            send_response(true, 'Search results fetched successfully', ['files' => $files]);
            break;

        case 'accept_file':
            validate_csrf_token($_POST['csrf_token']);
            $notificationId = filter_var($_POST['notification_id'], FILTER_VALIDATE_INT);
            $fileId = filter_var($_POST['file_id'], FILTER_VALIDATE_INT);
            if (!$notificationId || !$fileId) {
                send_response(false, 'Invalid notification or file ID', [], 400);
            }
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    UPDATE transactions 
                    SET transaction_status = 'accepted'
                    WHERE transaction_id = ? AND user_id = ?
                ");
                $stmt->execute([$notificationId, $userId]);
                $stmt = $pdo->prepare("
                    SELECT file_name FROM files WHERE file_id = ?
                ");
                $stmt->execute([$fileId]);
                $fileName = $stmt->fetchColumn();
                logActivity($userId, "Accepted file: $fileName", $fileId, null, null, 'accept');
                $pdo->commit();
                send_response(true, 'File accepted successfully');
            } catch (Exception $e) {
                $pdo->rollBack();
                send_response(false, 'Failed to accept file: ' . $e->getMessage(), [], 500);
            }
            break;

        case 'reject_file':
            validate_csrf_token($_POST['csrf_token']);
            $notificationId = filter_var($_POST['notification_id'], FILTER_VALIDATE_INT);
            $fileId = filter_var($_POST['file_id'], FILTER_VALIDATE_INT);
            if (!$notificationId || !$fileId) {
                send_response(false, 'Invalid notification or file ID', [], 400);
            }
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    UPDATE transactions 
                    SET transaction_status = 'rejected'
                    WHERE transaction_id = ? AND user_id = ?
                ");
                $stmt->execute([$notificationId, $userId]);
                $stmt = $pdo->prepare("
                    SELECT file_name FROM files WHERE file_id = ?
                ");
                $stmt->execute([$fileId]);
                $fileName = $stmt->fetchColumn();
                logActivity($userId, "Rejected file: $fileName", $fileId, null, null, 'reject');
                $pdo->commit();
                send_response(true, 'File rejected successfully');
            } catch (Exception $e) {
                $pdo->rollBack();
                send_response(false, 'Failed to reject file: ' . $e->getMessage(), [], 500);
            }
            break;

        case 'fetch_notifications':
            validate_csrf_token($_POST['csrf_token']);
            $stmt = $pdo->prepare("
                SELECT t.transaction_id AS id, t.file_id, t.transaction_type, t.transaction_status, 
                       t.description AS message, t.transaction_time AS timestamp
                FROM transactions t
                WHERE t.user_id = ? AND t.transaction_type IN ('notification', 'file_sent')
                ORDER BY t.transaction_time DESC
                LIMIT 50
            ");
            $stmt->execute([$userId]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            send_response(true, 'Notifications fetched successfully', ['notifications' => $notifications]);
            break;

        case 'fetch_file_info':
            validate_csrf_token($_POST['csrf_token']);
            $fileId = filter_var($_POST['file_id'], FILTER_VALIDATE_INT);
            if (!$fileId) {
                send_response(false, 'Invalid file ID', [], 400);
            }

            // Fetch file details
            $stmt = $pdo->prepare("
                SELECT f.file_id, f.file_name, f.file_path, f.file_type, f.file_size, f.access_level, f.qr_path, 
                       f.upload_date, f.doc_type_fields, dt.type_name AS document_type, u.username AS uploader_name,
                       COALESCE(sl.full_path, f.file_path) AS physical_location
                FROM files f
                LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
                LEFT JOIN users u ON f.user_id = u.user_id
                LEFT JOIN storage_locations sl ON f.storage_location_id = sl.storage_location_id
                WHERE f.file_id = ? AND (f.user_id = ? OR f.department_id IN (
                    SELECT department_id FROM user_department_assignments WHERE user_id = ?
                ))
            ");
            $stmt->execute([$fileId, $userId, $userId]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$file) {
                send_response(false, 'File not found or access denied', [], 404);
            }

            // Parse doc_type_fields and handle potential nested structure
            $docTypeFieldsJson = $file['doc_type_fields'];
            $docTypeFields = json_decode($docTypeFieldsJson, true) ?? [];
            $dynamicFields = [];

            if (isset($docTypeFields['dynamic_fields'])) {
                $dynamic = $docTypeFields['dynamic_fields'];
                if (is_array($dynamic)) {
                    $dynamicFields = $dynamic;
                } elseif (is_string($dynamic)) {
                    // If it's a string, try to decode it as JSON
                    $decodedDynamic = json_decode($dynamic, true);
                    if (is_array($decodedDynamic)) {
                        $dynamicFields = $decodedDynamic;
                    }
                }
                // Check for further nesting under 'doc_type_fields'
                if (isset($dynamicFields['doc_type_fields'])) {
                    $inner = $dynamicFields['doc_type_fields'];
                    if (is_string($inner)) {
                        $decodedInner = json_decode($inner, true);
                        if (is_array($decodedInner)) {
                            $dynamicFields = $decodedInner;
                        }
                    } elseif (is_array($inner)) {
                        $dynamicFields = $inner;
                    }
                }
            } elseif (is_array($docTypeFields)) {
                // If no 'dynamic_fields', assume flat structure
                $dynamicFields = $docTypeFields;
            }

            // Format fields for response (key-value pairs, excluding empty values)
            $formattedFields = [];
            foreach ($dynamicFields as $key => $value) {
                if ($value !== '') {
                    $formattedFields[] = ['key' => $key, 'value' => $value];
                }
            }

            // Fetch activity logs
            $stmt = $pdo->prepare("
                SELECT t.transaction_type, t.description, u.username, d.department_name
                FROM transactions t
                LEFT JOIN users u ON t.user_id = u.user_id
                LEFT JOIN user_department_assignments uda ON t.users_department_id = uda.users_department_id
                LEFT JOIN departments d ON uda.department_id = d.department_id
                WHERE t.file_id = ? AND t.transaction_type IN ('file_sent', 'accept', 'file_copy', 'file_rename')
                ORDER BY t.transaction_time DESC
            ");
            $stmt->execute([$fileId]);
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $activityData = [
                'sent_to' => [],
                'received_by' => [],
                'copied_by' => [],
                'renamed_to' => null
            ];

            foreach ($activities as $activity) {
                if ($activity['transaction_type'] === 'file_sent') {
                    $recipient = $activity['username'] ?: $activity['department_name'];
                    if ($recipient) {
                        $activityData['sent_to'][] = $recipient;
                    }
                } elseif ($activity['transaction_type'] === 'accept') {
                    if ($activity['username']) {
                        $activityData['received_by'][] = $activity['username'];
                    }
                } elseif ($activity['transaction_type'] === 'file_copy') {
                    if ($activity['username']) {
                        $activityData['copied_by'][] = $activity['username'];
                    }
                } elseif ($activity['transaction_type'] === 'file_rename') {
                    $activityData['renamed_to'] = $activity['description'];
                }
            }

            send_response(true, 'File information fetched successfully', [
                'file' => $file,
                'formatted_fields' => $formattedFields,
                'activity' => $activityData
            ]);
            break;

        default:
            send_response(false, 'Invalid action', [], 400);
    }
} catch (Exception $e) {
    error_log("Error in file_operations.php: " . $e->getMessage());
    send_response(false, $e->getMessage(), [], 500);
}
