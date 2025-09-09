<?php
session_start();
require 'db_connection.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

function validate_session(): void
{
    if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
        header('Location: login.php');
        exit();
    }
    if ($_SESSION['role'] !== 'admin') {
        header('Location: unauthorized.php');
        exit();
    }
    $userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
    if ($userId === false) {
        session_destroy();
        header('Location: login.php');
        exit();
    }
}
validate_session();

// Fetch admin details
try {
    $adminStmt = $pdo->prepare("SELECT user_id, username, role FROM users WHERE user_id = ?");
    $adminStmt->execute([$_SESSION['user_id']]);
    $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        throw new RuntimeException("Admin user not found");
    }
} catch (Exception $e) {
    error_log("Error fetching admin details: " . $e->getMessage());
    $errorMessage = "Failed to load admin details. Please try again later.";
}

// Handle filters for file logs with default date range (last 30 days)
$fileFilters = [
    'user_id' => filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT) ?: null,
    'department_id' => filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT) ?: null,
    'recipient_id' => filter_input(INPUT_GET, 'recipient_id', FILTER_VALIDATE_INT) ?: null,
    'start_date' => filter_input(INPUT_GET, 'start_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: date('Y-m-d', strtotime('-30 days')),
    'end_date' => filter_input(INPUT_GET, 'end_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: null,
    'transaction_type' => filter_input(INPUT_GET, 'transaction_type', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY) ?: null,
    'search_query' => filter_input(INPUT_GET, 'search_query', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: null,
    'page' => filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?: 1,
];

// Handle filters for OCR logs
$ocrFilters = [
    'user_id' => filter_input(INPUT_GET, 'ocr_user_id', FILTER_VALIDATE_INT) ?: null,
    'start_date' => filter_input(INPUT_GET, 'ocr_start_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: date('Y-m-d', strtotime('-30 days')),
    'end_date' => filter_input(INPUT_GET, 'ocr_end_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: null,
    'search_query' => filter_input(INPUT_GET, 'ocr_search_query', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: null,
    'ocr_page' => filter_input(INPUT_GET, 'ocr_page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?: 1,
];

$itemsPerPage = 50;
$fileOffset = ($fileFilters['page'] - 1) * $itemsPerPage;
$ocrOffset = ($ocrFilters['ocr_page'] - 1) * $itemsPerPage;

// Define transaction types
$fileTransactionTypes = [
    'file_upload',
    'send',
    'request',
    'accept',
    'reject',
    'edit',
    'delete',
    'access',
    'scan',
    'relocation'
];
$ocrTransactionTypes = ['ocr_process', 'ocr_retry'];

// Build query for file-related transactions
$fileQuery = "SELECT 
    t.transaction_id, 
    t.transaction_time, 
    t.transaction_status, 
    t.transaction_type, 
    t.user_id, 
    u.username, 
    t.file_id, 
    f.file_name, 
    f.file_type, 
    dt.type_name AS document_type, 
    f.access_level, 
    d.department_name, 
    sl.full_path AS storage_location, 
    t.description,
    CASE 
        WHEN t.transaction_type = 'send' THEN 
            COALESCE(u2.username, d2.department_name, 'Unknown')
        ELSE NULL 
    END AS recipient,
    CASE 
        WHEN t.transaction_type = 'file_upload' THEN 
            JSON_ARRAY('file_id', 'file_name', 'file_type', 'document_type', 'access_level', 'department_name', 'storage_location', 'description')
        WHEN t.transaction_type = 'send' THEN 
            JSON_ARRAY('file_id', 'file_name', 'file_type', 'document_type', 'recipient', 'description')
        WHEN t.transaction_type = 'request' THEN 
            JSON_ARRAY('file_id', 'file_name', 'document_type', 'description')
        WHEN t.transaction_type IN ('accept', 'reject') THEN 
            JSON_ARRAY('file_id', 'file_name', 'description')
        WHEN t.transaction_type = 'access' THEN 
            JSON_ARRAY('file_id', 'file_name', 'document_type', 'description')
        WHEN t.transaction_type = 'scan' THEN 
            JSON_ARRAY('file_id', 'file_name', 'file_type', 'description')
        WHEN t.transaction_type = 'relocation' THEN 
            JSON_ARRAY('file_id', 'file_name', 'storage_location', 'description')
        WHEN t.transaction_type IN ('edit', 'delete') THEN 
            JSON_ARRAY('file_id', 'file_name', 'document_type', 'description')
    END AS relevant_fields
FROM transactions t
LEFT JOIN users u ON t.user_id = u.user_id
LEFT JOIN files f ON t.file_id = f.file_id
LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
LEFT JOIN departments d ON f.department_id = d.department_id
LEFT JOIN storage_locations sl ON f.storage_location_id = sl.storage_location_id
LEFT JOIN users u2 ON t.transaction_type = 'send' AND t.description LIKE CONCAT('%to user: ', u2.username)
LEFT JOIN departments d2 ON t.transaction_type = 'send' AND t.description LIKE CONCAT('%to department: ', d2.department_name)
WHERE t.transaction_type IN (" . implode(',', array_fill(0, count($fileTransactionTypes), '?')) . ")";
$fileParams = $fileTransactionTypes;

if ($fileFilters['user_id']) {
    $fileQuery .= " AND t.user_id = ?";
    $fileParams[] = $fileFilters['user_id'];
}
if ($fileFilters['department_id']) {
    $fileQuery .= " AND f.department_id = ?";
    $fileParams[] = $fileFilters['department_id'];
}
if ($fileFilters['recipient_id']) {
    $fileQuery .= " AND (u2.user_id = ? OR d2.department_id = ?)";
    $fileParams[] = $fileFilters['recipient_id'];
    $fileParams[] = $fileFilters['recipient_id'];
}
if ($fileFilters['start_date']) {
    $fileQuery .= " AND t.transaction_time >= ?";
    $fileParams[] = $fileFilters['start_date'] . ' 00:00:00';
}
if ($fileFilters['end_date']) {
    $fileQuery .= " AND t.transaction_time <= ?";
    $fileParams[] = $fileFilters['end_date'] . ' 23:59:59';
}
if ($fileFilters['transaction_type']) {
    $validTypes = array_intersect($fileFilters['transaction_type'], $fileTransactionTypes);
    if ($validTypes) {
        $placeholders = implode(',', array_fill(0, count($validTypes), '?'));
        $fileQuery .= " AND t.transaction_type IN ($placeholders)";
        $fileParams = array_merge($fileParams, $validTypes);
    }
}
if ($fileFilters['search_query']) {
    $fileQuery .= " AND (u.username LIKE ? OR f.file_name LIKE ? OR t.description LIKE ? OR u2.username LIKE ? OR d2.department_name LIKE ?)";
    $searchTerm = "%{$fileFilters['search_query']}%";
    $fileParams[] = $searchTerm;
    $fileParams[] = $searchTerm;
    $fileParams[] = $searchTerm;
    $fileParams[] = $searchTerm;
    $fileParams[] = $searchTerm;
}

// Count total file logs for pagination
$fileCountQuery = "SELECT COUNT(*) FROM transactions t
               LEFT JOIN users u ON t.user_id = u.user_id
               LEFT JOIN files f ON t.file_id = f.file_id
               LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
               LEFT JOIN departments d ON f.department_id = d.department_id
               LEFT JOIN storage_locations sl ON f.storage_location_id = sl.storage_location_id
               LEFT JOIN users u2 ON t.transaction_type = 'send' AND t.description LIKE CONCAT('%to user: ', u2.username)
               LEFT JOIN departments d2 ON t.transaction_type = 'send' AND t.description LIKE CONCAT('%to department: ', d2.department_name)
               WHERE t.transaction_type IN (" . implode(',', array_fill(0, count($fileTransactionTypes), '?')) . ")";
$fileCountParams = array_merge($fileTransactionTypes, array_slice($fileParams, count($fileTransactionTypes)));
try {
    $fileCountStmt = $pdo->prepare($fileCountQuery);
    $fileCountStmt->execute($fileCountParams);
    $totalFileLogs = $fileCountStmt->fetchColumn();
    $totalFilePages = ceil($totalFileLogs / $itemsPerPage);
} catch (Exception $e) {
    error_log("Error counting file logs: " . $e->getMessage());
    $totalFileLogs = 0;
    $totalFilePages = 1;
}

$fileQuery .= " ORDER BY t.transaction_time DESC LIMIT ? OFFSET ?";
$fileParams[] = $itemsPerPage;
$fileParams[] = $fileOffset;

// Fetch file logs
try {
    $fileStmt = $pdo->prepare($fileQuery);
    $fileStmt->execute($fileParams);
    $fileLogs = $fileStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    error_log("File logs fetched: " . count($fileLogs) . " records");
} catch (Exception $e) {
    error_log("Error fetching file logs: " . $e->getMessage() . " Query: $fileQuery");
    $fileLogs = [];
    $errorMessage = "Failed to load file access logs. Please check the database connection or try again later.";
}

// Build query for OCR-related transactions
$ocrQuery = "SELECT 
    t.transaction_id, 
    t.transaction_time, 
    t.transaction_status, 
    t.transaction_type, 
    t.file_id, 
    f.file_name, 
    f.file_type, 
    tr.ocr_status, 
    tr.ocr_attempts, 
    tr.ocr_engine, 
    tr.error_message, 
    tr.page_count, 
    tr.extraction_date,
    JSON_ARRAY('file_id', 'file_name', 'file_type', 'ocr_status', 'ocr_attempts', 'ocr_engine', 'error_message', 'page_count', 'extraction_date') AS relevant_fields
FROM transactions t
LEFT JOIN files f ON t.file_id = f.file_id
LEFT JOIN text_repository tr ON t.file_id = tr.file_id
WHERE t.transaction_type IN (" . implode(',', array_fill(0, count($ocrTransactionTypes), '?')) . ")";
$ocrParams = $ocrTransactionTypes;

if ($ocrFilters['user_id']) {
    $ocrQuery .= " AND t.user_id = ?";
    $ocrParams[] = $ocrFilters['user_id'];
}
if ($ocrFilters['start_date']) {
    $ocrQuery .= " AND t.transaction_time >= ?";
    $ocrParams[] = $ocrFilters['start_date'] . ' 00:00:00';
}
if ($ocrFilters['end_date']) {
    $ocrQuery .= " AND t.transaction_time <= ?";
    $ocrParams[] = $ocrFilters['end_date'] . ' 23:59:59';
}
if ($ocrFilters['search_query']) {
    $ocrQuery .= " AND (f.file_name LIKE ? OR tr.error_message LIKE ?)";
    $searchTerm = "%{$ocrFilters['search_query']}%";
    $ocrParams[] = $searchTerm;
    $ocrParams[] = $searchTerm;
}

// Count total OCR logs for pagination
$ocrCountQuery = "SELECT COUNT(*) FROM transactions t
               LEFT JOIN files f ON t.file_id = f.file_id
               LEFT JOIN text_repository tr ON t.file_id = tr.file_id
               WHERE t.transaction_type IN (" . implode(',', array_fill(0, count($ocrTransactionTypes), '?')) . ")";
$ocrCountParams = array_merge($ocrTransactionTypes, array_slice($ocrParams, count($ocrTransactionTypes)));
try {
    $ocrCountStmt = $pdo->prepare($ocrCountQuery);
    $ocrCountStmt->execute($ocrCountParams);
    $totalOcrLogs = $ocrCountStmt->fetchColumn();
    $totalOcrPages = ceil($totalOcrLogs / $itemsPerPage);
} catch (Exception $e) {
    error_log("Error counting OCR logs: " . $e->getMessage());
    $totalOcrLogs = 0;
    $totalOcrPages = 1;
}

$ocrQuery .= " ORDER BY t.transaction_time DESC LIMIT ? OFFSET ?";
$ocrParams[] = $itemsPerPage;
$ocrParams[] = $ocrOffset;

// Fetch OCR logs
try {
    $ocrStmt = $pdo->prepare($ocrQuery);
    $ocrStmt->execute($ocrParams);
    $ocrLogs = $ocrStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    error_log("OCR logs fetched: " . count($ocrLogs) . " records");
} catch (Exception $e) {
    error_log("Error fetching OCR logs: " . $e->getMessage() . " Query: $ocrQuery");
    $ocrLogs = [];
    if (!isset($errorMessage)) {
        $errorMessage = "Failed to load OCR logs. Please check the database connection or try again later.";
    }
}

// Fetch users, departments, and transaction types for filters
$users = fetchData($pdo, "SELECT user_id, username FROM users ORDER BY username");
$departments = fetchData($pdo, "SELECT department_id, department_name FROM departments ORDER BY department_name");
$recipients = array_merge(
    fetchData($pdo, "SELECT user_id AS id, username AS name FROM users ORDER BY username"),
    fetchData($pdo, "SELECT department_id AS id, department_name AS name FROM departments ORDER BY department_name")
);
$fileTransactionTypesList = fetchData($pdo, "SELECT DISTINCT transaction_type FROM transactions WHERE transaction_type IN (" . implode(',', array_fill(0, count($fileTransactionTypes), '?')) . ")", $fileTransactionTypes);
$ocrTransactionTypesList = fetchData($pdo, "SELECT DISTINCT transaction_type FROM transactions WHERE transaction_type IN (" . implode(',', array_fill(0, count($ocrTransactionTypes), '?')) . ")", $ocrTransactionTypes);

// Data fetching function
function fetchData(PDO $pdo, string $query, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log("Error fetching data: " . $e->getMessage());
        return [];
    }
}

// Generate CSV
function generateCSV($data, $report, $isOcr = false)
{
    $filename = $report . '_' . date('YmdHis') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    $output = fopen('php://output', 'w');

    $allHeaders = $isOcr ? [
        'Transaction ID',
        'File Name',
        'Action',
        'Date and Time',
        'File ID',
        'File Type',
        'OCR Status',
        'OCR Attempts',
        'OCR Engine',
        'Error Message',
        'Page Count',
        'Extraction Date'
    ] : [
        'Username',
        'File Name',
        'Action',
        'Date and Time',
        'Transaction ID',
        'File ID',
        'File Type',
        'Document Type',
        'Ownership',
        'Department',
        'Storage Location',
        'Recipient',
        'Description'
    ];
    fputcsv($output, $allHeaders);

    foreach ($data as $row) {
        $relevantFields = json_decode($row['relevant_fields'], true) ?: [];
        $rowData = $isOcr ? [
            $row['transaction_id'],
            $row['file_name'] ?? 'N/A',
            ucfirst(str_replace('_', ' ', $row['transaction_type'])),
            date('Y-m-d H:i:s', strtotime($row['transaction_time'])),
            $row['file_id'] ?? 'N/A',
            $row['file_type'] ?? 'N/A',
            $row['ocr_status'] ?? 'N/A',
            $row['ocr_attempts'] ?? 'N/A',
            $row['ocr_engine'] ?? 'N/A',
            $row['error_message'] ?? 'N/A',
            $row['page_count'] ?? 'N/A',
            $row['extraction_date'] ? date('Y-m-d H:i:s', strtotime($row['extraction_date'])) : 'N/A'
        ] : [
            $row['username'],
            $row['file_name'] ?? 'N/A',
            ucfirst(str_replace('_', ' ', $row['transaction_type'])),
            date('Y-m-d H:i:s', strtotime($row['transaction_time'])),
            $row['transaction_id'],
            $row['file_id'] ?? 'N/A',
            $row['file_type'] ?? 'N/A',
            $row['document_type'] ?? 'N/A',
            $row['access_level'] ?? 'N/A',
            $row['department_name'] ?? 'N/A',
            $row['storage_location'] ?? 'N/A',
            $row['recipient'] ?? 'N/A',
            $row['description'] ?? 'N/A'
        ];
        fputcsv($output, $rowData);
    }
    fclose($output);
    exit;
}

// Generate PDF using TCPDF
function generatePDF($data, $title, $isOcr = false)
{
    try {
        if (!file_exists('vendor/tecnickcom/tcpdf/tcpdf.php')) {
            error_log("TCPDF library not found");
            header('HTTP/1.1 500 Internal Server Error');
            echo "Error: TCPDF library not found.";
            exit;
        }
        require_once('vendor/tecnickcom/tcpdf/tcpdf.php');

        while (ob_get_level()) ob_end_clean();

        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('ArcHive');
        $pdf->SetTitle($title . ' Report');
        $pdf->SetSubject($isOcr ? 'OCR Logs Report' : 'File Access Logs Report');
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, $title, 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 10, 'Generated on: ' . date('F j, Y, g:i A'), 0, 1, 'C');

        if (empty($data)) {
            $pdf->Write(0, 'No data available.', '', 0, 'C');
        } else {
            $allHeaders = $isOcr ? [
                'Transaction ID',
                'File Name',
                'Action',
                'Date and Time',
                'File ID',
                'File Type',
                'OCR Status',
                'OCR Attempts',
                'OCR Engine',
                'Error Message',
                'Page Count',
                'Extraction Date'
            ] : [
                'Username',
                'File Name',
                'Action',
                'Date and Time',
                'Transaction ID',
                'File ID',
                'File Type',
                'Document Type',
                'Ownership',
                'Department',
                'Storage Location',
                'Recipient',
                'Description'
            ];
            $pdf->SetFillColor(80, 200, 120);
            $pdf->SetTextColor(255, 255, 255);
            $cellWidth = 180 / count($allHeaders);

            foreach ($allHeaders as $col) {
                $pdf->MultiCell($cellWidth, 7, $col, 1, 'C', 1, 0, '', '', true, 0, false, true, 7, 'M');
            }
            $pdf->Ln();

            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetTextColor(0, 0, 0);
            $fill = 0;
            foreach ($data as $row) {
                $relevantFields = json_decode($row['relevant_fields'], true) ?: [];
                if ($isOcr) {
                    $pdf->MultiCell($cellWidth, 6, $row['transaction_id'], 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    $pdf->MultiCell($cellWidth, 6, $row['file_name'] ?? 'N/A', 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    $pdf->MultiCell($cellWidth, 6, ucfirst(str_replace('_', ' ', $row['transaction_type'])), 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    $pdf->MultiCell($cellWidth, 6, date('Y-m-d H:i:s', strtotime($row['transaction_time'])), 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    $pdf->MultiCell($cellWidth, 6, $row['file_id'] ?? 'N/A', 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    $pdf->MultiCell($cellWidth, 6, $row['file_type'] ?? 'N/A', 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    $pdf->MultiCell($cellWidth, 6, $row['ocr_status'] ?? 'N/A', 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    $pdf->MultiCell($cellWidth, 6, $row['ocr_attempts'] ?? 'N/A', 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    $pdf->MultiCell($cellWidth, 6, $row['ocr_engine'] ?? 'N/A', 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    $pdf->MultiCell($cellWidth, 6, $row['error_message'] ?? 'N/A', 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    $pdf->MultiCell($cellWidth, 6, $row['page_count'] ?? 'N/A', 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    $pdf->MultiCell($cellWidth, 6, $row['extraction_date'] ? date('Y-m-d H:i:s', strtotime($row['extraction_date'])) : 'N/A', 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                } else {
                    $pdf->MultiCell($cellWidth, 6, $row['username'], 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    $pdf->MultiCell($cellWidth, 6, $row['file_name'] ?? 'N/A', 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    $pdf->MultiCell($cellWidth, 6, ucfirst(str_replace('_', ' ', $row['transaction_type'])), 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    $pdf->MultiCell($cellWidth, 6, date('Y-m-d H:i:s', strtotime($row['transaction_time'])), 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    $pdf->MultiCell($cellWidth, 6, $row['transaction_id'], 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    foreach (array_slice($allHeaders, 5) as $header) {
                        $field = strtolower(str_replace(' ', '_', $header));
                        if ($field === 'ownership') $field = 'access_level';
                        $value = in_array($field, $relevantFields) ? ($row[$field] ?? 'N/A') : '';
                        $pdf->MultiCell($cellWidth, 6, $value, 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
                    }
                }
                $pdf->Ln();
                $fill = !$fill;
            }
        }

        $pdf->Output($title . '_Report_' . date('YmdHis') . '.pdf', 'D');
    } catch (Exception $e) {
        error_log("PDF generation error: " . $e->getMessage());
        header('HTTP/1.1 500 Internal Server Error');
        echo "Error generating PDF: " . htmlspecialchars($e->getMessage());
        exit;
    }
}

// Generate Word using PHPWord
function generateWord($data, $report, $isOcr = false)
{
    try {
        if (!file_exists('vendor/autoload.php')) {
            error_log("PHPWord autoload not found");
            header('HTTP/1.1 500 Internal Server Error');
            echo "Error: PHPWord library not found.";
            exit;
        }
        require_once 'vendor/autoload.php';

        while (ob_get_level()) ob_end_clean();

        $phpWord = new \PhpOffice\PhpWord\PhpWord();
        $section = $phpWord->addSection();
        $section->addText($isOcr ? "OCR Logs Report" : "File Access Logs Report", ['bold' => true, 'size' => 16], ['alignment' => 'center']);
        $section->addText("Generated on: " . date('F j, Y, g:i A'), ['size' => 10], ['alignment' => 'center']);

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80]);
        $allHeaders = $isOcr ? [
            'Transaction ID',
            'File Name',
            'Action',
            'Date and Time',
            'File ID',
            'File Type',
            'OCR Status',
            'OCR Attempts',
            'OCR Engine',
            'Error Message',
            'Page Count',
            'Extraction Date'
        ] : [
            'Username',
            'File Name',
            'Action',
            'Date and Time',
            'Transaction ID',
            'File ID',
            'File Type',
            'Document Type',
            'Ownership',
            'Department',
            'Storage Location',
            'Recipient',
            'Description'
        ];
        $table->addRow();
        foreach ($allHeaders as $col) {
            $table->addCell(1200)->addText($col, ['bold' => true], ['alignment' => 'center']);
        }

        if (empty($data)) {
            $section->addText('No data available.', ['size' => 10]);
        } else {
            foreach ($data as $row) {
                $relevantFields = json_decode($row['relevant_fields'], true) ?: [];
                $table->addRow();
                if ($isOcr) {
                    $table->addCell(1200)->addText($row['transaction_id']);
                    $table->addCell(1200)->addText($row['file_name'] ?? 'N/A');
                    $table->addCell(1200)->addText(ucfirst(str_replace('_', ' ', $row['transaction_type'])));
                    $table->addCell(1200)->addText(date('Y-m-d H:i:s', strtotime($row['transaction_time'])));
                    $table->addCell(1200)->addText($row['file_id'] ?? 'N/A');
                    $table->addCell(1200)->addText($row['file_type'] ?? 'N/A');
                    $table->addCell(1200)->addText($row['ocr_status'] ?? 'N/A');
                    $table->addCell(1200)->addText($row['ocr_attempts'] ?? 'N/A');
                    $table->addCell(1200)->addText($row['ocr_engine'] ?? 'N/A');
                    $table->addCell(1200)->addText($row['error_message'] ?? 'N/A');
                    $table->addCell(1200)->addText($row['page_count'] ?? 'N/A');
                    $table->addCell(1200)->addText($row['extraction_date'] ? date('Y-m-d H:i:s', strtotime($row['extraction_date'])) : 'N/A');
                } else {
                    $table->addCell(1200)->addText($row['username']);
                    $table->addCell(1200)->addText($row['file_name'] ?? 'N/A');
                    $table->addCell(1200)->addText(ucfirst(str_replace('_', ' ', $row['transaction_type'])));
                    $table->addCell(1200)->addText(date('Y-m-d H:i:s', strtotime($row['transaction_time'])));
                    $table->addCell(1200)->addText($row['transaction_id']);
                    foreach (array_slice($allHeaders, 5) as $header) {
                        $field = strtolower(str_replace(' ', '_', $header));
                        if ($field === 'ownership') $field = 'access_level';
                        $value = in_array($field, $relevantFields) ? ($row[$field] ?? 'N/A') : '';
                        $table->addCell(1200)->addText($value);
                    }
                }
            }
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment;filename="' . $report . '_Report_' . date('YmdHis') . '.docx"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save('php://output');
        exit;
    } catch (Exception $e) {
        error_log("Word generation error: " . $e->getMessage());
        header('HTTP/1.1 500 Internal Server Error');
        echo "Error generating Word document: " . htmlspecialchars($e->getMessage());
        exit;
    }
}

// Handle download requests
if (isset($_GET['download']) && isset($_GET['report'])) {
    $format = $_GET['download'];
    $report = $_GET['report'];
    $isOcr = isset($_GET['is_ocr']) && $_GET['is_ocr'] === 'true';
    $data = $isOcr ? $ocrLogs : $fileLogs;
    if ($format === 'csv') {
        generateCSV($data, $report, $isOcr);
    } elseif ($format === 'pdf') {
        generatePDF($data, $report, $isOcr);
    } elseif ($format === 'word') {
        generateWord($data, $report, $isOcr);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Admin dashboard for viewing file access and OCR logs">
    <title>File Access and OCR Logs</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <link rel="stylesheet" href="style/admin-logs.css">
    <link rel="stylesheet" href="style/admin-sidebar.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
</head>

<body class="admin-dashboard">
    <?php include 'admin_menu.php'; ?>

    <div class="top-nav">
        <h2>File Access and OCR Logs</h2>
        <button class="toggle-btn" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="main-content">
        <?php if (isset($errorMessage)): ?>
            <div class="error-message"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <!-- File Access Logs Section -->
        <h3>File Access Logs</h3>
        <!-- Quick Filters for File Logs -->
        <div class="quick-filters">
            <button onclick="setDateFilter('today', 'file')" aria-label="Show file logs from today">Today</button>
            <button onclick="setDateFilter('week', 'file')" aria-label="Show file logs from last 7 days">Last 7 Days</button>
            <button onclick="setDateFilter('month', 'file')" aria-label="Show file logs from last 30 days">Last 30 Days</button>
        </div>

        <!-- Filters Form for File Logs -->
        <div class="filters">
            <form method="GET" action="" role="search">
                <input type="text" name="search_query" value="<?= htmlspecialchars($fileFilters['search_query'] ?? '') ?>" placeholder="Search by username, file name, description, or recipient..." aria-label="Search file access logs">
                <select name="user_id" aria-label="Filter by user">
                    <option value="">All Users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['user_id'] ?>" <?= $fileFilters['user_id'] == $user['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="department_id" aria-label="Filter by department">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['department_id'] ?>" <?= $fileFilters['department_id'] == $dept['department_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="recipient_id" aria-label="Filter by recipient">
                    <option value="">All Recipients</option>
                    <?php foreach ($recipients as $recipient): ?>
                        <option value="<?= $recipient['id'] ?>" <?= $fileFilters['recipient_id'] == $recipient['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($recipient['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="transaction_type[]" multiple aria-label="Filter by action">
                    <option value="">All Actions</option>
                    <?php foreach ($fileTransactionTypesList as $type): ?>
                        <option value="<?= htmlspecialchars($type['transaction_type']) ?>" <?= in_array($type['transaction_type'], (array)($fileFilters['transaction_type'] ?? [])) ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $type['transaction_type']))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="start_date" value="<?= htmlspecialchars($fileFilters['start_date']) ?>" aria-label="Start date">
                <input type="date" name="end_date" value="<?= htmlspecialchars($fileFilters['end_date'] ?? '') ?>" aria-label="End date">
                <button type="submit"><i class="fas fa-filter"></i> Apply Filters</button>
                <button type="button" onclick="window.location.href='admin_logs.php'" aria-label="Clear all filters"><i class="fas fa-undo"></i> Clear Filters</button>
            </form>
        </div>

        <!-- File Logs Table -->
        <div class="data-table-container">
            <table class="data-table" role="grid" aria-describedby="file-access-logs-desc">
                <caption id="file-access-logs-desc" class="sr-only">File access logs table showing usernames, file names, actions, dates, and transaction IDs</caption>
                <thead>
                    <tr>
                        <th data-sort="username" scope="col">Username</th>
                        <th data-sort="file_name" scope="col">File Name</th>
                        <th data-sort="transaction_type" scope="col">Action</th>
                        <th data-sort="transaction_time" scope="col">Date and Time</th>
                        <th data-sort="transaction_id" scope="col">Transaction ID</th>
                        <th scope="col">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($fileLogs)): ?>
                        <tr>
                            <td colspan="6" class="no-data">No file access logs found. Try adjusting filters or generating new file activities.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($fileLogs as $log): ?>
                            <?php
                            $relevantFields = json_decode($log['relevant_fields'], true) ?: [];
                            $details = [];
                            if (in_array('file_id', $relevantFields)) $details['File ID'] = htmlspecialchars($log['file_id'] ?? 'N/A');
                            if (in_array('file_name', $relevantFields)) $details['File Name'] = htmlspecialchars($log['file_name'] ?? 'N/A');
                            if (in_array('file_type', $relevantFields)) $details['File Type'] = htmlspecialchars($log['file_type'] ?? 'N/A');
                            if (in_array('document_type', $relevantFields)) $details['Document Type'] = htmlspecialchars($log['document_type'] ?? 'N/A');
                            if (in_array('access_level', $relevantFields)) $details['Ownership'] = htmlspecialchars($log['access_level'] ?? 'N/A');
                            if (in_array('department_name', $relevantFields)) $details['Department'] = htmlspecialchars($log['department_name'] ?? 'N/A');
                            if (in_array('storage_location', $relevantFields)) $details['Storage Location'] = htmlspecialchars($log['storage_location'] ?? 'N/A');
                            if (in_array('recipient', $relevantFields)) $details['Recipient'] = htmlspecialchars($log['recipient'] ?? 'N/A');
                            if (in_array('description', $relevantFields)) $details['Description'] = htmlspecialchars($log['description'] ?? 'N/A');
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($log['username']) ?></td>
                                <td><?= htmlspecialchars($log['file_name'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="transaction-type">
                                        <i class="fas <?= getTransactionIcon($log['transaction_type']) ?>"></i>
                                        <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $log['transaction_type']))) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($log['transaction_time']))) ?></td>
                                <td><?= htmlspecialchars($log['transaction_id']) ?></td>
                                <td>
                                    <button class="details-toggle" aria-expanded="false" aria-controls="file-details-<?= $log['transaction_id'] ?>">
                                        <i class="fas fa-chevron-down"></i> Details
                                    </button>
                                    <div id="file-details-<?= $log['transaction_id'] ?>" class="details-content" style="display: none;">
                                        <dl>
                                            <?php foreach ($details as $label => $value): ?>
                                                <div class="detail-item">
                                                    <dt><?= htmlspecialchars($label) ?>:</dt>
                                                    <dd><?= $value ?></dd>
                                                </div>
                                            <?php endforeach; ?>
                                        </dl>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- File Logs Pagination -->
        <div class="pagination">
            <?php if ($totalFilePages > 1): ?>
                <span>Page <?= $fileFilters['page'] ?> of <?= $totalFilePages ?></span>
                <?php if ($fileFilters['page'] > 1): ?>
                    <a href="?<?= http_build_query(array_merge($fileFilters, ['page' => $fileFilters['page'] - 1])) ?>" aria-label="Previous page">Previous</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $totalFilePages; $i++): ?>
                    <a href="?<?= http_build_query(array_merge($fileFilters, ['page' => $i])) ?>" class="<?= $fileFilters['page'] == $i ? 'active' : '' ?>" aria-label="Page <?= $i ?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php if ($fileFilters['page'] < $totalFilePages): ?>
                    <a href="?<?= http_build_query(array_merge($fileFilters, ['page' => $fileFilters['page'] + 1])) ?>" aria-label="Next page">Next</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- File Logs Export Buttons -->
        <div class="chart-actions">
            <button onclick="downloadWithLoading('csv', 'FileAccessLogs', false)" aria-label="Export file logs as CSV"><i class="fas fa-file-csv"></i> Export as CSV</button>
            <button onclick="downloadWithLoading('pdf', 'FileAccessLogs', false)" aria-label="Export file logs as PDF"><i class="fas fa-file-pdf"></i> Export as PDF</button>
            <button onclick="downloadWithLoading('word', 'FileAccessLogs', false)" aria-label="Export file logs as Word"><i class="fas fa-file-word"></i> Export as Word</button>
        </div>

        <!-- OCR Logs Section -->
        <h3>OCR Processing Logs</h3>
        <!-- Quick Filters for OCR Logs -->
        <div class="quick-filters">
            <button onclick="setDateFilter('today', 'ocr')" aria-label="Show OCR logs from today">Today</button>
            <button onclick="setDateFilter('week', 'ocr')" aria-label="Show OCR logs from last 7 days">Last 7 Days</button>
            <button onclick="setDateFilter('month', 'ocr')" aria-label="Show OCR logs from last 30 days">Last 30 Days</button>
        </div>

        <!-- Filters Form for OCR Logs -->
        <div class="filters">
            <form method="GET" action="" role="search">
                <input type="text" name="ocr_search_query" value="<?= htmlspecialchars($ocrFilters['search_query'] ?? '') ?>" placeholder="Search by file name or error message..." aria-label="Search OCR logs">
                <select name="ocr_user_id" aria-label="Filter by user">
                    <option value="">All Users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['user_id'] ?>" <?= $ocrFilters['user_id'] == $user['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="ocr_start_date" value="<?= htmlspecialchars($ocrFilters['start_date']) ?>" aria-label="Start date">
                <input type="date" name="ocr_end_date" value="<?= htmlspecialchars($ocrFilters['end_date'] ?? '') ?>" aria-label="End date">
                <button type="submit"><i class="fas fa-filter"></i> Apply Filters</button>
                <button type="button" onclick="window.location.href='admin_logs.php'" aria-label="Clear all filters"><i class="fas fa-undo"></i> Clear Filters</button>
            </form>
        </div>

        <!-- OCR Logs Table -->
        <div class="data-table-container">
            <table class="data-table" role="grid" aria-describedby="ocr-logs-desc">
                <caption id="ocr-logs-desc" class="sr-only">OCR logs table showing transaction IDs, file names, actions, dates, and details</caption>
                <thead>
                    <tr>
                        <th data-sort="transaction_id" scope="col">Transaction ID</th>
                        <th data-sort="file_name" scope="col">File Name</th>
                        <th data-sort="transaction_type" scope="col">Action</th>
                        <th data-sort="transaction_time" scope="col">Date and Time</th>
                        <th scope="col">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ocrLogs)): ?>
                        <tr>
                            <td colspan="5" class="no-data">No OCR logs found. Try adjusting filters or generating new OCR activities.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($ocrLogs as $log): ?>
                            <?php
                            $relevantFields = json_decode($log['relevant_fields'], true) ?: [];
                            $details = [];
                            if (in_array('file_id', $relevantFields)) $details['File ID'] = htmlspecialchars($log['file_id'] ?? 'N/A');
                            if (in_array('file_name', $relevantFields)) $details['File Name'] = htmlspecialchars($log['file_name'] ?? 'N/A');
                            if (in_array('file_type', $relevantFields)) $details['File Type'] = htmlspecialchars($log['file_type'] ?? 'N/A');
                            if (in_array('ocr_status', $relevantFields)) $details['OCR Status'] = htmlspecialchars($log['ocr_status'] ?? 'N/A');
                            if (in_array('ocr_attempts', $relevantFields)) $details['OCR Attempts'] = htmlspecialchars($log['ocr_attempts'] ?? 'N/A');
                            if (in_array('ocr_engine', $relevantFields)) $details['OCR Engine'] = htmlspecialchars($log['ocr_engine'] ?? 'N/A');
                            if (in_array('error_message', $relevantFields)) $details['Error Message'] = htmlspecialchars($log['error_message'] ?? 'N/A');
                            if (in_array('page_count', $relevantFields)) $details['Page Count'] = htmlspecialchars($log['page_count'] ?? 'N/A');
                            if (in_array('extraction_date', $relevantFields)) $details['Extraction Date'] = htmlspecialchars($log['extraction_date'] ? date('Y-m-d H:i:s', strtotime($log['extraction_date'])) : 'N/A');
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($log['transaction_id']) ?></td>
                                <td><?= htmlspecialchars($log['file_name'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="transaction-type">
                                        <i class="fas <?= getTransactionIcon($log['transaction_type']) ?>"></i>
                                        <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $log['transaction_type']))) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($log['transaction_time']))) ?></td>
                                <td>
                                    <button class="details-toggle" aria-expanded="false" aria-controls="ocr-details-<?= $log['transaction_id'] ?>">
                                        <i class="fas fa-chevron-down"></i> Details
                                    </button>
                                    <div id="ocr-details-<?= $log['transaction_id'] ?>" class="details-content" style="display: none;">
                                        <dl>
                                            <?php foreach ($details as $label => $value): ?>
                                                <div class="detail-item">
                                                    <dt><?= htmlspecialchars($label) ?>:</dt>
                                                    <dd><?= $value ?></dd>
                                                </div>
                                            <?php endforeach; ?>
                                        </dl>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- OCR Logs Pagination -->
        <div class="pagination">
            <?php if ($totalOcrPages > 1): ?>
                <span>Page <?= $ocrFilters['ocr_page'] ?> of <?= $totalOcrPages ?></span>
                <?php if ($ocrFilters['ocr_page'] > 1): ?>
                    <a href="?<?= http_build_query(array_merge($fileFilters, $ocrFilters, ['ocr_page' => $ocrFilters['ocr_page'] - 1])) ?>" aria-label="Previous page">Previous</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $totalOcrPages; $i++): ?>
                    <a href="?<?= http_build_query(array_merge($fileFilters, $ocrFilters, ['ocr_page' => $i])) ?>" class="<?= $ocrFilters['ocr_page'] == $i ? 'active' : '' ?>" aria-label="Page <?= $i ?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php if ($ocrFilters['ocr_page'] < $totalOcrPages): ?>
                    <a href="?<?= http_build_query(array_merge($fileFilters, $ocrFilters, ['ocr_page' => $ocrFilters['ocr_page'] + 1])) ?>" aria-label="Next page">Next</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- OCR Logs Export Buttons -->
        <div class="chart-actions">
            <button onclick="downloadWithLoading('csv', 'OCRLogs', true)" aria-label="Export OCR logs as CSV"><i class="fas fa-file-csv"></i> Export as CSV</button>
            <button onclick="downloadWithLoading('pdf', 'OCRLogs', true)" aria-label="Export OCR logs as PDF"><i class="fas fa-file-pdf"></i> Export as PDF</button>
            <button onclick="downloadWithLoading('word', 'OCRLogs', true)" aria-label="Export OCR logs as Word"><i class="fas fa-file-word"></i> Export as Word</button>
        </div>
    </div>

    <script>
        const notyf = new Notyf({
            duration: 4000,
            position: {
                x: 'right',
                y: 'top'
            },
            types: [{
                    type: 'error',
                    background: '#dc2626',
                    icon: '<i class="fas fa-exclamation-circle"></i>'
                },
                {
                    type: 'success',
                    background: '#50c878',
                    icon: '<i class="fas fa-check-circle"></i>'
                }
            ]
        });

        const toggleSidebar = () => {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const topNav = document.querySelector('.top-nav');
            sidebar.classList.toggle('minimized');
            mainContent.classList.toggle('resized');
            topNav.classList.toggle('resized');
        };

        // Client-side table sorting
        document.querySelectorAll('.data-table th[data-sort]').forEach(header => {
            header.addEventListener('click', () => {
                const table = header.closest('table');
                const tbody = table.querySelector('tbody');
                const index = Array.from(header.parentNode.children).indexOf(header);
                const sortKey = header.dataset.sort;
                const isAsc = header.classList.toggle('asc');

                const rows = Array.from(tbody.querySelectorAll('tr:not(.no-data)'));
                rows.sort((a, b) => {
                    const aText = a.children[index].textContent.trim();
                    const bText = b.children[index].textContent.trim();
                    if (sortKey === 'transaction_time') {
                        return isAsc ?
                            new Date(aText) - new Date(bText) :
                            new Date(bText) - new Date(aText);
                    } else if (sortKey === 'transaction_id') {
                        return isAsc ?
                            parseInt(aText) - parseInt(bText) :
                            parseInt(bText) - parseInt(aText);
                    } else {
                        return isAsc ?
                            aText.localeCompare(bText) :
                            bText.localeCompare(bText);
                    }
                });

                tbody.innerHTML = '';
                rows.forEach(row => tbody.appendChild(row));
                header.setAttribute('aria-sort', isAsc ? 'ascending' : 'descending');
            });
            header.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    header.click();
                }
            });
        });

        // Toggle details visibility
        document.querySelectorAll('.details-toggle').forEach(button => {
            button.addEventListener('click', () => {
                const details = button.nextElementSibling;
                const isExpanded = button.getAttribute('aria-expanded') === 'true';
                button.setAttribute('aria-expanded', !isExpanded);
                details.style.display = isExpanded ? 'none' : 'block';
                button.querySelector('i').classList.toggle('fa-chevron-down', isExpanded);
                button.querySelector('i').classList.toggle('fa-chevron-up', !isExpanded);
            });
        });

        // Debounced live search for file logs
        const fileSearchInput = document.querySelector('input[name="search_query"]');
        let fileSearchTimeout;
        fileSearchInput.addEventListener('input', () => {
            clearTimeout(fileSearchTimeout);
            fileSearchTimeout = setTimeout(() => {
                document.querySelector('form[action=""][role="search"]:first-of-type').submit();
            }, 300);
        });

        // Debounced live search for OCR logs
        const ocrSearchInput = document.querySelector('input[name="ocr_search_query"]');
        let ocrSearchTimeout;
        ocrSearchInput.addEventListener('input', () => {
            clearTimeout(ocrSearchTimeout);
            ocrSearchTimeout = setTimeout(() => {
                document.querySelector('form[action=""][role="search"]:last-of-type').submit();
            }, 300);
        });

        // Quick date filters
        function setDateFilter(period, type) {
            const startDate = document.querySelector(`input[name="${type === 'ocr' ? 'ocr_start_date' : 'start_date'}"]`);
            const endDate = document.querySelector(`input[name="${type === 'ocr' ? 'ocr_end_date' : 'end_date'}"]`);
            const today = new Date().toISOString().split('T')[0];
            if (period === 'today') {
                startDate.value = today;
                endDate.value = today;
            } else if (period === 'week') {
                startDate.value = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
                endDate.value = today;
            } else if (period === 'month') {
                startDate.value = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
                endDate.value = today;
            }
            document.querySelector(`form[action=""][role="search"]${type === 'ocr' ? ':last-of-type' : ':first-of-type'}`).submit();
        }

        // Download with loading indicator
        function downloadWithLoading(format, report, isOcr) {
            const button = document.querySelector(`button[onclick="downloadWithLoading('${format}', '${report}', ${isOcr})"]`);
            button.disabled = true;
            button.innerHTML = '<span class="download-loading"></span> Exporting...';
            setTimeout(() => {
                window.location.href = `?download=${format}&report=${report}&is_ocr=${isOcr}`;
                setTimeout(() => {
                    button.disabled = false;
                    button.innerHTML = `<i class="fas fa-file-${format}"></i> Export as ${format.toUpperCase()}`;
                    notyf.success(`Successfully exported as ${format.toUpperCase()}`);
                }, 1000);
            }, 500);
        }

        // Show error notification if present
        <?php if (isset($errorMessage)): ?>
            notyf.error('<?= htmlspecialchars($errorMessage) ?>');
        <?php endif; ?>
    </script>
</body>

</html>

<?php
// Helper function to get icons for transaction types
function getTransactionIcon($type)
{
    $icons = [
        'file_upload' => 'fa-upload',
        'send' => 'fa-envelope',
        'request' => 'fa-question-circle',
        'accept' => 'fa-check-circle',
        'reject' => 'fa-times-circle',
        'edit' => 'fa-edit',
        'delete' => 'fa-trash',
        'access' => 'fa-eye',
        'scan' => 'fa-camera',
        'relocation' => 'fa-map-marker-alt',
        'ocr_process' => 'fa-file-alt',
        'ocr_retry' => 'fa-redo'
    ];
    return $icons[$type] ?? 'fa-info-circle';
}
?>