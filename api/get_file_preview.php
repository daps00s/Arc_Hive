<?php
// api/get_file_preview.php
session_start();
require '../db_connection.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$fileId = filter_var($_GET['file_id'] ?? null, FILTER_VALIDATE_INT);

if (!$fileId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid file ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT f.file_id, f.file_name, f.file_type, f.file_path, f.upload_date, f.file_size,
               f.access_level, f.copy_type, f.file_status, f.qr_path,
               dt.type_name AS document_type,
               u.username AS uploader_name,
               d.department_name,
               sd.department_name AS sub_department_name,
               sl.full_path AS physical_location
        FROM files f
        LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
        LEFT JOIN users u ON f.user_id = u.user_id
        LEFT JOIN departments d ON f.department_id = d.department_id
        LEFT JOIN departments sd ON f.sub_department_id = sd.department_id
        LEFT JOIN storage_locations sl ON f.storage_location_id = sl.storage_location_id
        WHERE f.file_id = ? AND f.file_status != 'deleted'
    ");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'File not found']);
        exit;
    }

    // Check access permissions
    $accessGranted = false;
    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['role'] ?? 'client';

    if ($file['access_level'] === 'personal' && $file['user_id'] == $userId) {
        $accessGranted = true;
    } elseif ($file['access_level'] === 'sub_department') {
        $stmt = $pdo->prepare("
            SELECT 1 FROM user_department_assignments uda
            JOIN departments d ON uda.department_id = d.department_id
            WHERE uda.user_id = ? AND (uda.department_id = ? OR d.parent_department_id = ?)
        ");
        $stmt->execute([$userId, $file['sub_department_id'], $file['sub_department_id']]);
        $accessGranted = (bool)$stmt->fetchColumn();
    } elseif ($file['access_level'] === 'college') {
        $stmt = $pdo->prepare("
            SELECT 1 FROM user_department_assignments
            WHERE user_id = ? AND department_id = ?
        ");
        $stmt->execute([$userId, $file['department_id']]);
        $accessGranted = (bool)$stmt->fetchColumn();
    } elseif ($userRole === 'admin') {
        $accessGranted = true;
    }

    if (!$accessGranted) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    // Get file metadata
    $stmt = $pdo->prepare("
        SELECT field_name, field_value 
        FROM file_metadata 
        WHERE file_id = ?
    ");
    $stmt->execute([$fileId]);
    $metadata = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $file['metadata'] = array_column($metadata, 'field_value', 'field_name');

    // Get OCR text if available
    $stmt = $pdo->prepare("
        SELECT extracted_text, ocr_status
        FROM text_repository
        WHERE file_id = ?
    ");
    $stmt->execute([$fileId]);
    $ocrData = $stmt->fetch(PDO::FETCH_ASSOC);

    $file['ocr_text'] = $ocrData['extracted_text'] ?? null;
    $file['ocr_status'] = $ocrData['ocr_status'] ?? 'not_processed';

    // Generate preview URL
    $filePath = __DIR__ . '/../' . $file['file_path'];
    if (file_exists($filePath)) {
        $file['preview_url'] = $file['file_path'];
        $file['download_url'] = $file['file_path'];
    } else {
        $file['preview_url'] = null;
        $file['download_url'] = null;
    }
    require_once '../vendor/autoload.php';


    try {
        $qrDir = __DIR__ . '/../Uploads/QR_Codes/';
        if (!file_exists($qrDir)) {
            mkdir($qrDir, 0755, true);
        }

        $qrFilename = 'qr_' . $fileId . '.png';
        $qrPath = $qrDir . $qrFilename;

        // Build QR code with PNG writer
        $result = Builder::create()
            ->writer(new PngWriter())
            ->data(json_encode([
                'file_id' => $fileId,
                'physical_location' => $file['physical_location'],
                'department' => $file['department_name']
            ]))
            ->size(300)
            ->margin(10)
            ->build();

        // Save file
        $result->saveToFile($qrPath);

        // Update database
        $relativeQrPath = 'Uploads/QR_Codes/' . $qrFilename;
        $updateStmt = $pdo->prepare("UPDATE files SET qr_path = ? WHERE file_id = ?");
        $updateStmt->execute([$relativeQrPath, $fileId]);

        $file['qr_path'] = $relativeQrPath;
    } catch (Exception $e) {
        error_log("QR code generation failed for file $fileId: " . $e->getMessage());
    }

    echo json_encode(['success' => true, 'file' => $file]);
} catch (PDOException $e) {
    error_log("Database error in get_file_preview: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
} catch (Exception $e) {
    error_log("Error in get_file_preview: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
