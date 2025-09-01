<?php
session_start();

// Include required files
$requiredFiles = ['../db_connection.php', '../log_activity.php'];
foreach ($requiredFiles as $file) {
    if (!file_exists($file)) {
        die(json_encode(['success' => false, 'message' => "Required file $file not found", 'statusCode' => 500]));
    }
    require_once $file;
}

header('Content-Type: application/json');

// Define constants
define('UPLOAD_DIR', normalizePath(getenv('UPLOAD_DIR') ?: realpath(__DIR__ . '/../Uploads') . DIRECTORY_SEPARATOR));
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('SUPPORTED_FILE_TYPES', [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'txt' => 'text/plain',
    'csv' => 'text/csv',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
]);
define('LOG_DIR', normalizePath(__DIR__ . '/logs/'));
define('UPLOAD_LOG_FILE', LOG_DIR . 'upload_error.log');

/**
 * Normalizes file paths for cross-platform compatibility.
 *
 * @param string $path The input path.
 * @return string The normalized path.
 */
function normalizePath(string $path): string
{
    return str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
}

// Ensure log directory exists
if (!file_exists(LOG_DIR) && !mkdir(LOG_DIR, 0755, true)) {
    die(json_encode(['success' => false, 'message' => 'Failed to create log directory', 'statusCode' => 500]));
}

function send_response($success, $message = '', $data = [], $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

function validate_csrf_token($token)
{
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        error_log("CSRF validation failed for user_id: " . ($_SESSION['user_id'] ?? 'unknown'), 3, UPLOAD_LOG_FILE);
        send_response(false, 'Invalid CSRF token. Please refresh the page.', [], 403);
    }
}

function get_storage_location_id($pdo, $subDepartmentId, $departmentId, $userId, $accessLevel)
{
    try {
        // Validate user permission to create storage for the department
        if ($accessLevel !== 'personal') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM user_department_assignments 
                WHERE user_id = ? AND (department_id = ? OR department_id = ?)
            ");
            $stmt->execute([$userId, $subDepartmentId ?: $departmentId, $departmentId]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception("User lacks permission to create storage for this department");
            }
        }

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
        if ($subDepartmentId && $accessLevel === 'sub_department') {
            $query .= " AND sub_department_id = ? AND department_id = ?";
            $params[] = $subDepartmentId;
            $params[] = $departmentId;
        } elseif ($departmentId && $accessLevel === 'department') {
            $query .= " AND department_id = ? AND sub_department_id IS NULL";
            $params[] = $departmentId;
        } else {
            $query .= " AND sub_department_id IS NULL AND department_id IS NULL";
        }

        $query .= " ORDER BY sl.storage_location_id ASC LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $location = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($location) {
            return $location;
        }

        // Create new storage hierarchy
        $pdo->beginTransaction();
        try {
            $deptName = '';
            $subDeptName = '';
            if ($subDepartmentId && $accessLevel === 'sub_department') {
                $stmt = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = ? AND department_type = 'sub_department'");
                $stmt->execute([$subDepartmentId]);
                $subDeptName = $stmt->fetchColumn();
                if (!$subDeptName) {
                    throw new Exception("Sub-department not found for ID: $subDepartmentId");
                }
                $stmt = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = ?");
                $stmt->execute([$departmentId]);
                $deptName = $stmt->fetchColumn();
                if (!$deptName) {
                    throw new Exception("Department not found for ID: $departmentId");
                }
            } elseif ($departmentId && $accessLevel === 'department') {
                $stmt = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = ? AND department_type = 'department'");
                $stmt->execute([$departmentId]);
                $deptName = $stmt->fetchColumn();
                if (!$deptName) {
                    throw new Exception("Department not found for ID: $departmentId");
                }
            } else {
                $deptName = 'Personal';
            }

            $stmt = $pdo->prepare("
                SELECT MAX(CAST(SUBSTRING(unit_name, 2) AS UNSIGNED)) as max_num
                FROM storage_locations
                WHERE unit_type = ? 
                AND (sub_department_id = ? OR department_id = ? OR (sub_department_id IS NULL AND department_id IS NULL))
            ");

            $unitTypes = ['room', 'cabinet', 'layer', 'box', 'folder'];
            $unitNames = [];
            foreach ($unitTypes as $type) {
                $stmt->execute([$type, $subDepartmentId ?: null, $departmentId ?: null]);
                $maxNum = $stmt->fetchColumn() ?: 0;
                $unitNames[$type] = $type[0] . ($maxNum + 1);
            }

            $fullPath = implode(' > ', [
                $deptName,
                $subDeptName,
                $unitNames['room'],
                $unitNames['cabinet'],
                $unitNames['layer'],
                $unitNames['box'],
                $unitNames['folder']
            ]);
            $fullPath = normalizePath(trim(str_replace('  >', '', $fullPath), ' >'));

            // Dynamic folder capacity based on department size
            $folderCapacity = 100; // Default capacity
            if ($accessLevel === 'department' || $accessLevel === 'sub_department') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_department_assignments WHERE department_id = ?");
                $stmt->execute([$subDepartmentId ?: $departmentId]);
                $userCount = $stmt->fetchColumn();
                $folderCapacity = max(100, $userCount * 10); // Scale capacity with users
            }

            $stmt = $pdo->prepare("
                INSERT INTO storage_locations (
                    unit_name, unit_type, full_path, folder_capacity,
                    department_id, sub_department_id
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $unitNames['folder'],
                'folder',
                $fullPath,
                $folderCapacity,
                $accessLevel !== 'personal' ? $departmentId : null,
                $accessLevel === 'sub_department' ? $subDepartmentId : null
            ]);

            $storageLocationId = $pdo->lastInsertId();
            $pdo->commit();
            return [
                'storage_location_id' => $storageLocationId,
                'full_path' => $fullPath,
                'current_files' => 0,
                'folder_capacity' => $folderCapacity
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Storage creation error: " . $e->getMessage(), 3, UPLOAD_LOG_FILE);
            throw $e;
        }
    } catch (Exception $e) {
        error_log("Storage location error: " . $e->getMessage(), 3, UPLOAD_LOG_FILE);
        send_response(false, "Failed to get storage location: " . $e->getMessage(), [], 500);
    }
}

try {
    // Log incoming data for debugging
    error_log("POST: " . json_encode($_POST) . ", FILES: " . json_encode($_FILES), 3, UPLOAD_LOG_FILE);

    // Validate input
    if (!isset($_SESSION['user_id'])) {
        send_response(false, 'User not authenticated', [], 401);
    }
    $userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
    if (!$userId) {
        send_response(false, 'Invalid user ID', [], 400);
    }

    validate_csrf_token($_POST['csrf_token'] ?? '');

    $isHardcopy = isset($_POST['is_hardcopy']) && $_POST['is_hardcopy'] === '1';
    $documentTypeId = filter_var($_POST['document_type_id'] ?? 0, FILTER_VALIDATE_INT) ?: null;
    $accessLevel = $_POST['access_level'] ?? 'personal';
    $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $subDepartmentId = filter_var($_POST['sub_department_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $hardcopyOption = $_POST['hardcopyOption'] ?? '';
    $physicalStorage = $_POST['physical_storage'] ?? '';
    $hardcopyFileName = $_POST['hardcopy_file_name'] ?? '';

    if (!in_array($accessLevel, ['personal', 'sub_department', 'department'])) {
        send_response(false, 'Invalid access level', [], 400);
    }
    if (!$documentTypeId) {
        send_response(false, 'Document type is required', [], 400);
    }
    if ($accessLevel === 'department' && !$departmentId) {
        send_response(false, 'Department ID is required for department access level', [], 400);
    }
    if ($accessLevel === 'sub_department' && (!$departmentId || !$subDepartmentId)) {
        send_response(false, 'Both department and sub-department IDs are required for sub-department access level', [], 400);
    }
    if ($isHardcopy && $hardcopyOption === 'existing' && empty($physicalStorage)) {
        send_response(false, 'Physical storage location is required for existing hardcopy', [], 400);
    }
    if ($isHardcopy && $hardcopyOption === 'new' && empty($hardcopyFileName)) {
        send_response(false, 'Hardcopy file name is required for new hardcopy', [], 400);
    }

    // Validate document type exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM document_types WHERE document_type_id = ?");
    $stmt->execute([$documentTypeId]);
    if ($stmt->fetchColumn() == 0) {
        send_response(false, 'Invalid document type', [], 400);
    }

    // Get user's personal folder
    $stmt = $pdo->prepare("SELECT personal_folder FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $personalFolder = $stmt->fetchColumn() ?: "user_$userId";

    // Collect dynamic doc_type_fields from POST
    $docTypeFields = [];
    $knownKeys = ['csrf_token', 'access_level', 'department_id', 'sub_department_id', 'document_type_id', 'is_hardcopy', 'hardcopyOption', 'hardcopy_file_name', 'physical_storage'];
    foreach ($_POST as $key => $value) {
        if (!in_array($key, $knownKeys)) {
            $docTypeFields[$key] = trim($value);
        }
    }

    // Log collected fields for debugging
    error_log("Collected doc_type_fields: " . json_encode($docTypeFields), 3, UPLOAD_LOG_FILE);

    // Validate required fields based on document type
    $stmt = $pdo->prepare("SELECT fields FROM document_types WHERE document_type_id = ?");
    $stmt->execute([$documentTypeId]);
    $docFieldsJson = $stmt->fetchColumn();
    $docFields = json_decode($docFieldsJson, true) ?? [];
    foreach ($docFields as $field) {
        if (isset($field['required']) && $field['required'] && (!isset($docTypeFields[$field['name']]) || trim($docTypeFields[$field['name']]) === '')) {
            send_response(false, "Required field '{$field['label']}' is missing", [], 400);
        }
    }

    // Prepare JSON for doc_type_fields
    $docTypeFieldsJson = json_encode(['dynamic_fields' => $docTypeFields], JSON_UNESCAPED_SLASHES);
    error_log("Stored doc_type_fields JSON: " . $docTypeFieldsJson, 3, UPLOAD_LOG_FILE);

    // Begin transaction
    $pdo->beginTransaction();
    $uploadedFiles = [];

    if ($isHardcopy) {
        // Handle hardcopy logic
        $storageLocation = get_storage_location_id($pdo, $subDepartmentId, $departmentId, $userId, $accessLevel);
        $storageLocationId = $storageLocation['storage_location_id'];
        $fileName = $hardcopyOption === 'new' ? $hardcopyFileName : basename($physicalStorage);
        $filePath = $hardcopyOption === 'new' ? normalizePath(UPLOAD_DIR . str_replace(' > ', DIRECTORY_SEPARATOR, $storageLocation['full_path']) . DIRECTORY_SEPARATOR . $fileName) : normalizePath($physicalStorage);

        // Check for existing hardcopy file
        $stmt = $pdo->prepare("SELECT file_id FROM files WHERE file_name = ? AND user_id = ? AND copy_type = 'hard_copy'");
        $stmt->execute([$fileName, $userId]);
        if ($stmt->fetchColumn()) {
            $pdo->rollBack();
            error_log("Duplicate hardcopy detected: $fileName for user $userId", 3, UPLOAD_LOG_FILE);
            send_response(false, "Hardcopy file '$fileName' already exists", [], 400);
        }

        $query = "
            INSERT INTO files (
                user_id, department_id, sub_department_id, document_type_id, doc_type_fields,
                file_name, file_path, copy_type, upload_date, date_updated,
                storage_location_id, access_level, file_type
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, 'hardcopy')
        ";
        $params = [
            $userId,
            $accessLevel !== 'personal' ? $departmentId : null,
            $accessLevel === 'sub_department' ? $subDepartmentId : null,
            $documentTypeId,
            $docTypeFieldsJson,
            $fileName,
            $filePath,
            'hard_copy',
            $storageLocationId,
            $accessLevel
        ];

        error_log("Hardcopy INSERT query: $query, Params: " . json_encode($params), 3, UPLOAD_LOG_FILE);

        $stmt = $pdo->prepare($query);
        try {
            $stmt->execute($params);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Database insert error for hardcopy $fileName: " . $e->getMessage(), 3, UPLOAD_LOG_FILE);
            send_response(false, "Failed to insert hardcopy $fileName: " . $e->getMessage(), [], 500);
        }

        $fileId = $pdo->lastInsertId();

        // Insert into text_repository
        $stmt = $pdo->prepare("
            INSERT INTO text_repository (file_id, extracted_text, ocr_attempts)
            VALUES (?, '', 0)
        ");
        try {
            $stmt->execute([$fileId]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Text repository insert error for file ID $fileId: " . $e->getMessage(), 3, UPLOAD_LOG_FILE);
            send_response(false, "Failed to insert text repository for hardcopy $fileName: " . $e->getMessage(), [], 500);
        }

        // Log file upload
        if (function_exists('logActivity')) {
            logActivity($userId, "Uploaded hardcopy: $fileName", $fileId, $subDepartmentId ?: $departmentId, null, 'file_upload');
            // Log notification for successful upload
            logActivity($userId, "Upload file successful:Hardcopy $fileName", $fileId, $subDepartmentId ?: $departmentId, null, 'notification');
        }

        $uploadedFiles[] = [
            'file_id' => $fileId,
            'file_name' => $fileName,
            'file_type' => 'hardcopy'
        ];
    } else {
        // Validate softcopy files
        if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
            $pdo->rollBack();
            send_response(false, 'Please select a file for soft copy upload', [], 400);
        }

        // Normalize and deduplicate files array
        $files = $_FILES['files'];
        $uniqueFiles = [];
        $seenFiles = [];
        for ($i = 0; $i < count($files['name']); $i++) {
            $fileKey = md5($files['name'][$i] . $files['size'][$i] . $files['type'][$i]);
            if (!isset($seenFiles[$fileKey])) {
                $seenFiles[$fileKey] = true;
                $uniqueFiles[] = [
                    'name' => $files['name'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'type' => $files['type'][$i],
                    'size' => $files['size'][$i]
                ];
            } else {
                error_log("Duplicate file detected in upload: {$files['name'][$i]}", 3, UPLOAD_LOG_FILE);
            }
        }

        foreach ($uniqueFiles as $fileData) {
            if ($fileData['error'] !== UPLOAD_ERR_OK) {
                $pdo->rollBack();
                $errorCode = $fileData['error'] ?? 'Unknown';
                error_log("Upload error for file {$fileData['name']}: Error code $errorCode", 3, UPLOAD_LOG_FILE);
                send_response(false, "Upload error for {$fileData['name']}: Error code $errorCode", [], 400);
            }

            $fileName = basename($fileData['name']);
            $tmpPath = $fileData['tmp_name'];
            if (!is_uploaded_file($tmpPath)) {
                $pdo->rollBack();
                error_log("Invalid temporary file: $tmpPath for {$fileName}", 3, UPLOAD_LOG_FILE);
                send_response(false, "Invalid temporary file for $fileName", [], 400);
            }

            $fileType = mime_content_type($tmpPath) ?: $fileData['type'];
            $fileSize = $fileData['size'] !== '' ? $fileData['size'] : null;

            // Validate file type
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (!isset(SUPPORTED_FILE_TYPES[$extension]) || $fileType !== SUPPORTED_FILE_TYPES[$extension]) {
                $pdo->rollBack();
                error_log("Unsupported file type for {$fileName}: $fileType", 3, UPLOAD_LOG_FILE);
                send_response(false, "Unsupported file type for $fileName", [], 400);
            }

            // Validate file size
            if ($fileSize !== null && $fileSize > MAX_FILE_SIZE) {
                $pdo->rollBack();
                error_log("File size exceeds limit for {$fileName}: $fileSize bytes", 3, UPLOAD_LOG_FILE);
                send_response(false, "File $fileName exceeds size limit of 10MB", [], 400);
            }

            // Check for existing file in database based on original file name
            $stmt = $pdo->prepare("
                SELECT file_id FROM files 
                WHERE file_name = ? AND user_id = ? AND copy_type = 'soft_copy'
            ");
            $stmt->execute([$fileName, $userId]);
            if ($stmt->fetchColumn()) {
                $pdo->rollBack();
                error_log("Duplicate softcopy detected: $fileName for user $userId", 3, UPLOAD_LOG_FILE);
                send_response(false, "File '$fileName' already exists", [], 400);
            }

            // Get storage location
            $storageLocation = get_storage_location_id($pdo, $subDepartmentId, $departmentId, $userId, $accessLevel);
            $storageLocationId = $storageLocation['storage_location_id'];
            $filePathPrefix = normalizePath(UPLOAD_DIR);

            if ($accessLevel === 'personal') {
                $filePathPrefix .= normalizePath($personalFolder . DIRECTORY_SEPARATOR);
            } elseif ($accessLevel === 'department') {
                $stmt = $pdo->prepare("SELECT folder_path FROM departments WHERE department_id = ?");
                $stmt->execute([$departmentId]);
                $folderPath = $stmt->fetchColumn();
                if (!$folderPath) {
                    $pdo->rollBack();
                    send_response(false, "Invalid department folder path for ID $departmentId", [], 400);
                }
                $filePathPrefix .= normalizePath($folderPath . DIRECTORY_SEPARATOR);
            } elseif ($accessLevel === 'sub_department') {
                $stmt = $pdo->prepare("SELECT folder_path FROM departments WHERE department_id = ?");
                $stmt->execute([$subDepartmentId]);
                $folderPath = $stmt->fetchColumn();
                if (!$folderPath) {
                    $pdo->rollBack();
                    send_response(false, "Invalid sub-department folder path for ID $subDepartmentId", [], 400);
                }
                $filePathPrefix .= normalizePath($folderPath . DIRECTORY_SEPARATOR);
            }

            if ($storageLocationId) {
                $stmt = $pdo->prepare("SELECT full_path FROM storage_locations WHERE storage_location_id = ?");
                $stmt->execute([$storageLocationId]);
                $fullPath = $stmt->fetchColumn();
                if (!$fullPath) {
                    $pdo->rollBack();
                    send_response(false, "Storage location not found for ID $storageLocationId", [], 500);
                }
                $filePathPrefix .= normalizePath(str_replace(' > ', DIRECTORY_SEPARATOR, $fullPath) . DIRECTORY_SEPARATOR);
            }

            // Ensure unique file name on filesystem
            $baseFileName = pathinfo($fileName, PATHINFO_FILENAME);
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            $counter = 1;
            $uniqueFileName = $fileName;
            $filePath = normalizePath($filePathPrefix . $uniqueFileName);
            while (file_exists($filePath) || file_exists(strtolower($filePath))) {
                $uniqueFileName = $baseFileName . '_' . $counter . '.' . $extension;
                $filePath = normalizePath($filePathPrefix . $uniqueFileName);
                $counter++;
            }

            // Create and validate directory
            $uploadDir = normalizePath(dirname($filePath));
            if (!file_exists($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true) || !is_dir($uploadDir)) {
                    $pdo->rollBack();
                    error_log("Failed to create directory $uploadDir", 3, UPLOAD_LOG_FILE);
                    send_response(false, "Failed to create directory for $uniqueFileName", [], 500);
                }
            }
            if (!is_writable($uploadDir)) {
                $pdo->rollBack();
                error_log("Directory not writable: $uploadDir", 3, UPLOAD_LOG_FILE);
                send_response(false, "Directory not writable for $uniqueFileName", [], 500);
            }

            // Move uploaded file
            error_log("Attempting to move file: tmp=$tmpPath, target=$filePath", 3, UPLOAD_LOG_FILE);
            if (!move_uploaded_file($tmpPath, $filePath)) {
                $pdo->rollBack();
                error_log("Failed to move file: tmp=$tmpPath, target=$filePath", 3, UPLOAD_LOG_FILE);
                send_response(false, "Failed to move file $uniqueFileName", [], 500);
            }

            $fileStatus = in_array($fileType, ['pdf', 'jpg', 'jpeg', 'png']) ? 'pending_ocr' : 'completed';
            $fileName = $uniqueFileName;

            // Prepare query for softcopy
            $query = "
                INSERT INTO files (
                    user_id, department_id, sub_department_id, document_type_id, doc_type_fields,
                    file_name, file_path, copy_type, folder_capacity, upload_date, date_updated,
                    parent_file_id, storage_location_id, access_level, qr_path, file_status,
                    file_type, file_size
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?, ?, ?, ?)
            ";
            $params = [
                $userId,
                $accessLevel !== 'personal' ? $departmentId : null,
                $accessLevel === 'sub_department' ? $subDepartmentId : null,
                $documentTypeId,
                $docTypeFieldsJson,
                $fileName,
                $filePath,
                'soft_copy',
                null,
                null,
                $storageLocationId,
                $accessLevel,
                null,
                $fileStatus,
                $fileType,
                $fileSize
            ];

            error_log("Softcopy INSERT query: $query, Params: " . json_encode($params), 3, UPLOAD_LOG_FILE);

            $stmt = $pdo->prepare($query);
            try {
                $stmt->execute($params);
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Database insert error for file $fileName: " . $e->getMessage(), 3, UPLOAD_LOG_FILE);
                send_response(false, "Failed to insert file $fileName: " . $e->getMessage(), [], 500);
            }

            $fileId = $pdo->lastInsertId();

            // Insert into text_repository
            $stmt = $pdo->prepare("
                INSERT INTO text_repository (file_id, extracted_text, ocr_attempts)
                VALUES (?, '', 0)
            ");
            try {
                $stmt->execute([$fileId]);
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Text repository insert error for file ID $fileId: " . $e->getMessage(), 3, UPLOAD_LOG_FILE);
                send_response(false, "Failed to insert text repository for file $fileName: " . $e->getMessage(), [], 500);
            }

            // Log file upload
            if (function_exists('logActivity')) {
                logActivity($userId, "Uploaded file: $fileName", $fileId, $subDepartmentId ?: $departmentId, null, 'file_upload');
                // Log notification for successful upload
                logActivity($userId, "Upload file successful:Softcopy $fileName", $fileId, $subDepartmentId ?: $departmentId, null, 'notification');
            } else {
                error_log("logActivity function not defined", 3, UPLOAD_LOG_FILE);
            }

            $uploadedFiles[] = [
                'file_id' => $fileId,
                'file_name' => $fileName,
                'file_path' => $filePath,
                'file_type' => $fileType
            ];
        }
    }

    // Commit transaction
    try {
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Transaction commit error: " . $e->getMessage(), 3, UPLOAD_LOG_FILE);
        send_response(false, "Failed to commit transaction: " . $e->getMessage(), [], 500);
    }

    // Trigger OCR processing for softcopy files asynchronously
    if (!$isHardcopy && !empty($uploadedFiles)) {
        $ocrScript = realpath(__DIR__ . '/ocr_processor.php');
        $logFile = __DIR__ . '/logs/ocr_processor.log';

        // Ensure log directory exists
        if (!file_exists(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }

        error_log("OCR script path: $ocrScript", 3, UPLOAD_LOG_FILE);

        if (!file_exists($ocrScript) || !is_readable($ocrScript)) {
            error_log("OCR processor script not found or not readable: $ocrScript", 3, UPLOAD_LOG_FILE);
            send_response(true, 'Files uploaded successfully, but OCR processing could not be scheduled.', ['files' => $uploadedFiles], 200);
        } else {
            foreach ($uploadedFiles as $file) {
                // Check by file extension instead of MIME type
                $extension = strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION));
                $ocrExtensions = ['pdf', 'jpg', 'jpeg', 'png'];

                if (in_array($extension, $ocrExtensions)) {
                    $command = escapeshellcmd("php $ocrScript $fileId >> $logFile 2>&1");
                    $output = [];
                    $returnCode = 0;

                    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                        // Use START /B for reliable async execution on Windows
                        exec("start /B $command 2>&1", $output, $returnCode);
                    } else {
                        // Use & for background execution on Unix/Linux
                        exec("$command &", $output, $returnCode);
                    }

                    if ($returnCode !== 0) {
                        error_log("Failed to start OCR processing for file ID $fileId: " . implode("\n", $output), 3, UPLOAD_LOG_FILE);
                        send_response(true, 'Files uploaded successfully, but OCR processing could not be scheduled.', ['files' => $uploadedFiles], 200);
                    } else {
                        error_log("OCR triggered successfully for file ID {$file['file_id']} (Extension: $extension)", 3, UPLOAD_LOG_FILE);
                        if (function_exists('logActivity')) {
                            logActivity($userId, "Triggered OCR for file: {$file['file_name']}", $file['file_id'], null, null, 'ocr_trigger');
                        }
                    }
                } else {
                    error_log("Skipping OCR for file ID {$file['file_id']} - not an OCR-able type (Extension: $extension)", 3, UPLOAD_LOG_FILE);
                }
            }
        }
    }

    send_response(true, 'Files uploaded successfully', ['files' => $uploadedFiles]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Upload error: " . $e->getMessage() . " | Line: " . $e->getLine() . " | File: " . $e->getFile(), 3, UPLOAD_LOG_FILE);
    send_response(false, "Server error: " . $e->getMessage(), [], 500);
}