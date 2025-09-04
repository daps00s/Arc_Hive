<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); 
ini_set('log_errors', '1');

session_start();

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

function validate_session(): void {
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

// Database connection
$host = '127.0.0.1';
$dbname = 'arc-hive-mainDB';
$username = 'root';
$password = '';
$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$pdo = new PDO($dsn, $username, $password, $options);

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

// System statistics queries
$statisticsQueries = [
    'totalUsers' => "SELECT COUNT(*) FROM users",
    'totalFiles' => "SELECT COUNT(*) FROM files",
    'pendingRequests' => "SELECT COUNT(*) FROM transactions WHERE transaction_status = 'pending' AND transaction_type = 'request'",
    'fileUploads' => "SELECT COUNT(*) FROM v_file_uploads WHERE upload_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
    'fileActivity' => "SELECT COUNT(*) FROM v_file_activity WHERE transaction_type IN ('access', 'scan') AND transaction_time >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
];

$stats = [];
foreach ($statisticsQueries as $key => $query) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $stats[$key] = $stmt->fetchColumn() ?: 0;
    } catch (Exception $e) {
        error_log("Error fetching statistic {$key}: " . $e->getMessage());
        $stats[$key] = 0;
        $errorMessage = "Failed to load statistics. Some data may be unavailable.";
    }
}

// Data fetching functions
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

// Fetch report data
$reportData = [
    'fileUploadTrends' => fetchData($pdo, "
        SELECT DATE(upload_date) AS date, COUNT(*) AS count
        FROM v_file_uploads
        GROUP BY DATE(upload_date)
        ORDER BY date ASC
    "),
    'fileUploadTrendsTable' => fetchData($pdo, "
        SELECT file_id, file_name, upload_date, uploader_name, department_name, document_type
        FROM v_file_uploads
        ORDER BY upload_date DESC
        LIMIT 20
    "),
    'fileDistribution' => fetchData($pdo, "
        SELECT department_name, COUNT(*) AS count
        FROM v_file_distribution
        GROUP BY department_name
    "),
    'usersPerDepartment' => fetchData($pdo, "
        SELECT d.department_name, COUNT(uda.user_id) AS user_count
        FROM departments d
        LEFT JOIN user_department_assignments uda ON uda.department_id = d.department_id
        GROUP BY d.department_id
        ORDER BY department_name
    "),
    'documentCopies' => fetchData($pdo, "
        SELECT original_file_name AS file_name, copy_count, offices_with_copy
        FROM v_document_copies
        WHERE copy_count > 0
        ORDER BY file_name
    "),
    'pendingRequests' => fetchData($pdo, "
        SELECT t.transaction_id, t.transaction_time, u.username, f.file_name, d.department_name
        FROM transactions t
        LEFT JOIN users u ON u.user_id = t.user_id
        LEFT JOIN files f ON f.file_id = t.file_id
        LEFT JOIN user_department_assignments ud ON ud.users_department_id = t.users_department_id
        LEFT JOIN departments d ON d.department_id = ud.department_id
        WHERE t.transaction_type = 'request' AND t.transaction_status = 'pending'
        ORDER BY t.transaction_time DESC
    "),
    'retrievalHistory' => fetchData($pdo, "
        SELECT transaction_id, transaction_time AS time, username, file_name, department_name
        FROM v_file_activity
        WHERE transaction_type IN ('access', 'scan')
        ORDER BY transaction_time DESC
        LIMIT 100
    "),
    'accessHistory' => fetchData($pdo, "
        SELECT transaction_id, transaction_time AS time, username, file_name, department_name
        FROM v_file_activity
        WHERE transaction_type = 'access'
        ORDER BY transaction_time DESC
        LIMIT 100
    ")
];

// Extract variables for easier access
[
    'fileUploadTrends' => $fileUploadTrends,
    'fileUploadTrendsTable' => $fileUploadTrendsTable,
    'fileDistribution' => $fileDistribution,
    'usersPerDepartment' => $usersPerDepartment,
    'documentCopies' => $documentCopies,
    'pendingRequests' => $pendingRequests,
    'retrievalHistory' => $retrievalHistory,
    'accessHistory' => $accessHistory
] = $reportData;

// Generate CSRF token
$csrfToken = bin2hex(random_bytes(16));

// Generate CSV
function generateCSV($data, $report) {
    $filename = $report . '_' . date('YmdHis') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    if (!empty($data)) {
        fputcsv($output, array_map(function($key) { return ucwords(str_replace('_', ' ', $key)); }, array_keys($data[0])));
        foreach ($data as $row) {
            fputcsv($output, array_map(function($value) { return $value ?? 'N/A'; }, $row));
        }
    }
    fclose($output);
    exit;
}

// Generate PDF using TCPDF
function generatePDF($chartType, $data, $title) {
    try {
        if (!file_exists('vendor/tecnickcom/tcpdf/tcpdf.php')) {
            error_log("TCPDF library not found at vendor/tecnickcom/tcpdf/tcpdf.php");
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
        $pdf->SetSubject('Report generated from ArcHive Dashboard');
        
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(TRUE, 15);
        
        $pdf->SetFont('helvetica', '', 10);
        
        $pdf->AddPage();
        
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, $title, 0, 1, 'C');
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 10, 'Generated on: ' . date('F j, Y, g:i A'), 0, 1, 'C');
        
        error_log("PDF data for $title: " . print_r($data, true));

        if (empty($data)) {
            $pdf->Write(0, 'No data available.', '', 0, 'C');
        } else {
            $header = array_keys($data[0]);

            $pdf->SetFillColor(80, 200, 120);
            $pdf->SetTextColor(255, 255, 255);
            $cellWidth = 180 / count($header);

            foreach ($header as $col) {
                $label = ucwords(str_replace('_', ' ', $col));
                $pdf->MultiCell($cellWidth, 7, $label, 1, 'C', 1, 0, '', '', true, 0, false, true, 7, 'M');
            }
            $pdf->Ln();

            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetTextColor(0, 0, 0);
            $fill = 0;
            foreach ($data as $row) {
                foreach ($header as $col) {
                    $value = $row[$col] ?? 'N/A';
                    if (in_array($col, ['upload_date', 'transaction_time', 'time']) && !empty($value)) {
                        $value = date('Y-m-d H:i:s', strtotime($value));
                    }
                    $pdf->MultiCell($cellWidth, 6, $value, 1, 'L', $fill, 0, '', '', true, 0, false, true, 6, 'M');
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
function generateWord($data, $report) {
    try {
        if (!file_exists('vendor/autoload.php')) {
            error_log("PHPWord autoload not found at vendor/autoload.php");
            header('HTTP/1.1 500 Internal Server Error');
            echo "Error: PHPWord library not found.";
            exit;
        }
        require_once 'vendor/autoload.php';

        while (ob_get_level()) ob_end_clean();

        $phpWord = new \PhpOffice\PhpWord\PhpWord();
        $section = $phpWord->addSection();
        $table = $section->addTable();

        $header = array_keys($data[0]);

        $table->addRow();
        foreach ($header as $col) {
            $label = ucwords(str_replace('_', ' ', $col));
            $table->addCell(2000)->addText($label, ['bold' => true], ['alignment' => 'center']);
        }

        error_log("Word data for $report: " . print_r($data, true));

        if (empty($data)) {
            $section->addText('No data available.');
        } else {
            foreach ($data as $row) {
                $table->addRow();
                foreach ($header as $col) {
                    $value = $row[$col] ?? 'N/A';
                    if (in_array($col, ['upload_date', 'transaction_time', 'time']) && !empty($value)) {
                        $value = date('Y-m-d H:i:s', strtotime($value));
                    }
                    $table->addCell(2000)->addText($value);
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
        echo "Error generating Word: " . htmlspecialchars($e->getMessage());
        exit;
    }
}

// Handle CSV, PDF, and Word downloads
if (isset($_GET['download']) && isset($_GET['report'])) {
    $report = $_GET['report'];
    $data = [];

    // Select which dataset to export
    switch ($report) {
        case 'FileUploadTrends':
            $data = $fileUploadTrendsTable;
            break;
        case 'FileDistribution':
            $data = $fileDistribution;
            break;
        case 'UsersPerDepartment':
            $data = $usersPerDepartment;
            break;
        case 'DocumentCopies':
            $data = $documentCopies;
            break;
        case 'PendingRequests':
            $data = $pendingRequests;
            break;
        case 'RetrievalHistory':
            $data = $retrievalHistory;
            break;
        case 'AccessHistory':
            $data = $accessHistory;
            break;
        default:
            error_log("Invalid report type: $report");
            die("Invalid report type.");
    }

    // CSV
    if ($_GET['download'] === 'csv') {
        if (!empty($data)) {
            generateCSV($data, $report);
        } else {
            error_log("No data available for CSV download: $report");
            die("No data available for download.");
        }

    // PDF
    } elseif ($_GET['download'] === 'pdf') {
        error_log("Attempting PDF for $report with data count: " . count($data));
        if (!empty($data)) {
            generatePDF($report, $data, $report);
        } else {
            error_log("No data available for PDF download: $report");
            die("No data available for download.");
        }

    // Word
    } elseif ($_GET['download'] === 'word') {
        require_once __DIR__ . '/vendor/autoload.php';

        if (empty($data)) {
            error_log("No data available for Word download: $report");
            die("No data available for download.");
        }

        $phpWord = new \PhpOffice\PhpWord\PhpWord();
        $section = $phpWord->addSection();

        // Title
        $section->addText("Report: " . htmlspecialchars($report), ['bold' => true, 'size' => 16]);

        // Table
        $table = $section->addTable();

        // Header row
        $headers = array_keys($data[0]);
        $table->addRow();
        foreach ($headers as $header) {
            $table->addCell(3000)->addText($header, ['bold' => true]);
        }

        // Data rows
        foreach ($data as $row) {
            $table->addRow();
            foreach ($row as $cell) {
                $table->addCell(3000)->addText((string)$cell);
            }
        }

        // Clean output buffer before sending file
        if (ob_get_length()) {
            ob_end_clean();
        }

        // Output Word file
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment;filename="' . $report . '.docx"');
        header('Cache-Control: max-age=0');

        $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save('php://output');
        exit();

    // Invalid format
    } else {
        error_log("Invalid download format for report: $report");
        die("Invalid download format.");
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Reports Dashboard - ArcHive</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta name="robots" content="noindex, nofollow">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <link rel="stylesheet" href="style/admin-interface.css">
    <link rel="stylesheet" href="style/admin-sidebar.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <script src="lib/chart.js"></script>
    <script src="lib/jspdf.umd.min.js"></script>
</head>
<body class="admin-dashboard">
    <?php include 'admin_menu.php'; ?>

    <div class="top-nav">
        <h2>Welcome, <?= htmlspecialchars($admin['username']) ?>!</h2>
        <button class="toggle-btn" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="main-content">
        <?php if (isset($errorMessage)): ?>
            <div class="error-message"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="admin-stats">
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <h3>Total Users</h3>
                <p><?= $stats['totalUsers'] ?></p>
            </div>
            <div class="stat-card">
                <i class="fas fa-file-alt"></i>
                <h3>Total Files</h3>
                <p><?= $stats['totalFiles'] ?></p>
            </div>
            <div class="stat-card">
                <i class="fas fa-hourglass-half"></i>
                <h3>Pending Requests</h3>
                <p><?= $stats['pendingRequests'] ?></p>
            </div>
            <div class="stat-card">
                <i class="fas fa-upload"></i>
                <h3>File Uploads (7 Days)</h3>
                <p><?= $stats['fileUploads'] ?></p>
            </div>
            <div class="stat-card">
                <i class="fas fa-history"></i>
                <h3>File Activity (7 Days)</h3>
                <p><?= $stats['fileActivity'] ?></p>
            </div>
            <div class="stat-card">
                <i class="fas fa-user"></i>
                <h3>Activity logs </h3>
                <p><?= $stats['Activity_logs'] ?></p>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="chart-grid">
            <!-- File Upload Trends -->
            <div class="chart-container" data-chart-type="FileUploadTrends">
                <h3>File Upload Trends</h3>
                <canvas id="fileUploadTrendsChart"></canvas>
                <div class="chart-actions">
                    <button onclick="generateReport('FileUploadTrends')"><i class="fas fa-print"></i> Print Report</button>
                    <button onclick="openDownloadModal('FileUploadTrends')"><i class="fas fa-download"></i> Download Report</button>
                </div>
            </div>

            <!-- File Distribution -->
            <div class="chart-container" data-chart-type="FileDistribution">
                <h3>File Distribution</h3>
                <canvas id="fileDistributionChart"></canvas>
                <div class="chart-actions">
                    <button onclick="generateReport('FileDistribution')"><i class="fas fa-print"></i> Print Report</button>
                    <button onclick="openDownloadModal('FileDistribution')"><i class="fas fa-download"></i> Download Report</button>
                </div>
            </div>

            <!-- Users Per Department -->
            <div class="chart-container" data-chart-type="UsersPerDepartment">
                <h3>Users Per Department</h3>
                <canvas id="usersPerDepartmentChart"></canvas>
                <div class="chart-actions">
                    <button onclick="generateReport('UsersPerDepartment')"><i class="fas fa-print"></i> Print Report</button>
                    <button onclick="openDownloadModal('UsersPerDepartment')"><i class="fas fa-download"></i> Download Report</button>
                </div>
            </div>

            <!-- Document Copies -->
            <div class="chart-container" data-chart-type="DocumentCopies">
                <h3>Document Copies</h3>
                <canvas id="documentCopiesChart"></canvas>
                <div class="chart-actions">
                    <button onclick="generateReport('DocumentCopies')"><i class="fas fa-print"></i> Print Report</button>
                    <button onclick="openDownloadModal('DocumentCopies')"><i class="fas fa-download"></i> Download Report</button>
                </div>
            </div>

            <!-- Pending Requests -->
            <div class="chart-container" data-chart-type="PendingRequests">
                <h3>Pending Requests</h3>
                <p class="no-data">Click to view details in table</p>
                <div class="chart-actions">
                    <button onclick="generateReport('PendingRequests')"><i class="fas fa-print"></i> Print Report</button>
                    <button onclick="openDownloadModal('PendingRequests')"><i class="fas fa-download"></i> Download Report</button>
                </div>
            </div>

            <!-- Retrieval History -->
            <div class="chart-container" data-chart-type="RetrievalHistory">
                <h3>Retrieval History</h3>
                <p class="no-data">Click to view details in table</p>
                <div class="chart-actions">
                    <button onclick="generateReport('RetrievalHistory')"><i class="fas fa-print"></i> Print Report</button>
                    <button onclick="openDownloadModal('RetrievalHistory')"><i class="fas fa-download"></i> Download Report</button>
                </div>
            </div>

            <!-- Access History -->
            <div class="chart-container" data-chart-type="AccessHistory">
                <h3>Access History</h3>
                <p class="no-data">Click to view details in table</p>
                <div class="chart-actions">
                    <button onclick="generateReport('AccessHistory')"><i class="fas fa-print"></i> Print Report</button>
                    <button onclick="openDownloadModal('AccessHistory')"><i class="fas fa-download"></i> Download Report</button>
                </div>
            </div>
        </div>

        <!-- Modal for Data Tables -->
        <div class="modal-overlay" id="dataTableModal" role="dialog" aria-labelledby="modalTitle" style="display: none;">
            <div class="modal-content">
                <button class="modal-close" onclick="closeModal()" aria-label="Close Modal"><i class="fas fa-times"></i></button>
                <h3 id="modalTitle"></h3>
                <div class="pagination-controls">
                    <label for="itemsPerPage">Items per page:</label>
                    <select id="itemsPerPage" onchange="updatePagination()">
                        <option value="5">5</option>
                        <option value="10">10</option>
                        <option value="20">20</option>
                        <option value="all">All</option>
                    </select>
                    <button onclick="previousPage()" id="prevPage" disabled><i class="fas fa-chevron-left"></i> Previous</button>
                    <span id="pageInfo"></span>
                    <button onclick="nextPage()" id="nextPage"><i class="fas fa-chevron-right"></i> Next</button>
                </div>
                <div id="modalTable" class="data-table"></div>
            </div>
        </div>

        <!-- Modal for Download Format -->
        <div class="modal-overlay" id="downloadFormatModal" role="dialog" aria-labelledby="downloadModalTitle" style="display: none;">
            <div class="modal-content">
                <button class="modal-close" onclick="closeDownloadModal()" aria-label="Close Download Modal">
                    <i class="fas fa-times"></i>
                </button>
                <h3 id="downloadModalTitle">Select Download Format</h3>
                <div class="download-options">
                    <button onclick="downloadReport(currentChartType, 'csv')">
                        <i class="fas fa-file-csv"></i> Download as CSV
                    </button>
                    <button onclick="downloadReport(currentChartType, 'pdf')">
                        <i class="fas fa-file-pdf"></i> Download as PDF
                    </button>
                    <button onclick="downloadReport(currentChartType, 'word')">
                        <i class="fas fa-file-word"></i> Download as Word
                    </button>
                </div>
            </div>
        </div>

    <!-- Pass PHP data to JavaScript -->
    <script>
        const dashboardData = {
            FileUploadTrends: <?= json_encode($fileUploadTrends, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            FileUploadTrendsTable: <?= json_encode($fileUploadTrendsTable, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            FileDistribution: <?= json_encode($fileDistribution, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            UsersPerDepartment: <?= json_encode($usersPerDepartment, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            DocumentCopies: <?= json_encode($documentCopies, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            PendingRequests: <?= json_encode($pendingRequests, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            RetrievalHistory: <?= json_encode($retrievalHistory, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            AccessHistory: <?= json_encode($accessHistory, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
        };
    </script>
    <script>
        // Constants for configuration
        const ITEMS_PER_PAGE_OPTIONS = [5, 10, 20, 'all'];
        const CHART_CONFIG = {
            FileUploadTrends: {
                type: 'line',
                label: 'File Uploads',
                title: 'File Upload Trends',
                xAxis: 'Date',
                yAxis: 'Number of Uploads',
                borderColor: '#3498db',
                backgroundColor: 'rgba(52, 152, 219, 0.2)',
                processData: (data) => {
                    const uploadDates = [...new Set(data.map(item => item.date))];
                    return {
                        labels: uploadDates,
                        data: uploadDates.map(date =>
                            data.filter(item => item.date === date).reduce((sum, item) => sum + parseInt(item.count), 0)
                        ),
                    };
                },
                dataKey: 'FileUploadTrends'
            },
            FileDistribution: {
                type: 'pie',
                label: 'Files by Department',
                title: 'File Distribution by Department',
                xAxis: 'Department',
                yAxis: 'Number of Files',
                borderColor: '#27ae60',
                backgroundColor: '#2ecc71',
                processData: (data) => ({
                    labels: data.map(item => item.department_name),
                    data: data.map(item => item.count),
                }),
                dataKey: 'FileDistribution'
            },
            UsersPerDepartment: {
                type: 'bar',
                label: 'Users per Department',
                title: 'Users Per Department',
                xAxis: 'Department',
                yAxis: 'Number of Users',
                borderColor: '#c0392b',
                backgroundColor: '#e74c3c',
                processData: (data) => ({
                    labels: data.map(item => item.department_name),
                    data: data.map(item => item.user_count),
                }),
                dataKey: 'UsersPerDepartment'
            },
            DocumentCopies: {
                type: 'bar',
                label: 'Copy Count per File',
                title: 'Document Copies Details',
                xAxis: 'File Name',
                yAxis: 'Number of Copies',
                borderColor: '#f39c12',
                backgroundColor: '#f1c40f',
                processData: (data) => ({
                    labels: data.map(item => item.file_name),
                    data: data.map(item => item.copy_count),
                }),
                dataKey: 'DocumentCopies'
            },
            PendingRequests: {
                title: 'Pending Requests',
                processData: () => ({ labels: [], data: [] }),
                dataKey: 'PendingRequests'
            },
            RetrievalHistory: {
                title: 'Retrieval History',
                processData: () => ({ labels: [], data: [] }),
                dataKey: 'RetrievalHistory'
            },
            AccessHistory: {
                title: 'Access History',
                processData: () => ({ labels: [], data: [] }),
                dataKey: 'AccessHistory'
            }
        };

        // Utility Functions
        const sanitizeHTML = (str) => {
            const div = document.createElement('div');
            div.textContent = str ?? '';
            return div.innerHTML;
        };

        const escapeCsvField = (str) => {
            if (str == null) return '""';
            const stringified = String(str);
            if (stringified.includes('"') || stringified.includes(',') || stringified.includes('\n')) {
                return `"${stringified.replace(/"/g, '""')}"`;
            }
            return `"${stringified}"`;
        };

        // Chart Initialization
        const initializeChart = (canvasId, config, data) => {
            const canvas = document.getElementById(canvasId);
            if (!canvas) {
                console.warn(`Canvas element with ID ${canvasId} not found`);
                return null;
            }

            if (!data || data.length === 0) {
                canvas.parentElement.insertAdjacentHTML(
                    'beforeend',
                    '<p class="no-data">No data available for this chart.</p>'
                );
                return null;
            }

            const { labels, data: chartData } = config.processData(data);
            const chart = new Chart(canvas, {
                type: config.type,
                data: {
                    labels,
                    datasets: [{
                        label: config.label,
                        data: chartData,
                        borderColor: config.borderColor,
                        backgroundColor: config.backgroundColor,
                        borderWidth: 1,
                        fill: config.type === 'line',
                        tension: config.type === 'line' ? 0.4 : undefined,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    aspectRatio: 1.5,
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 20 } },
                        title: { display: true, text: config.title, font: { size: 16 } }
                    },
                    scales: {
                        x: {
                            title: { display: true, text: config.xAxis, font: { size: 14 } },
                            ticks: { autoSkip: true, maxTicksLimit: 10 }
                        },
                        y: {
                            title: { display: true, text: config.yAxis, font: { size: 14 } },
                            beginAtZero: true
                        }
                    }
                }
            });
            return chart;
        };

        // Table Generation for Modal and Print
        const generateTableContent = (chartType, page = 1, itemsPerPage = 5, forPrint = false) => {
            const dataKey = chartType === 'FileUploadTrends' ? 'FileUploadTrendsTable' : chartType;
            const data = dashboardData[dataKey] || [];
            if (!data.length) return '<p class="no-data">No data available.</p>';

            const start = forPrint ? 0 : (page - 1) * itemsPerPage;
            const end = forPrint ? data.length : (itemsPerPage === 'all' ? data.length : start + itemsPerPage);
            const slicedData = data.slice(start, end);

            const tableHeaders = {
                FileUploadTrends: ['File ID', 'File Name', 'Upload Date', 'Uploader', 'Department Name', 'Document Type'],
                FileDistribution: ['Department Name', 'File Count'],
                UsersPerDepartment: ['Department Name', 'User Count'],
                DocumentCopies: ['File Name', 'Copy Count', 'Offices with Copy'],
                PendingRequests: ['Transaction ID', 'Time', 'Username', 'File Name', 'Department Name'],
                RetrievalHistory: ['Transaction ID', 'Time', 'Username', 'File Name', 'Department Name'],
                AccessHistory: ['Transaction ID', 'Time', 'Username', 'File Name', 'Department Name']
            };

            const headers = tableHeaders[chartType] || [];
            const tableRows = slicedData.map(entry => {
                switch (chartType) {
                    case 'FileUploadTrends':
                        return `
                            <tr>
                                <td>${sanitizeHTML(entry.file_id)}</td>
                                <td>${sanitizeHTML(entry.file_name)}</td>
                                <td>${new Date(entry.upload_date).toLocaleString()}</td>
                                <td>${sanitizeHTML(entry.uploader_name)}</td>
                                <td>${sanitizeHTML(entry.department_name || 'None')}</td>
                                <td>${sanitizeHTML(entry.document_type || 'None')}</td>
                            </tr>`;
                    case 'FileDistribution':
                        return `
                            <tr>
                                <td>${sanitizeHTML(entry.department_name)}</td>
                                <td>${sanitizeHTML(entry.count)}</td>
                            </tr>`;
                    case 'UsersPerDepartment':
                        return `
                            <tr>
                                <td>${sanitizeHTML(entry.department_name)}</td>
                                <td>${sanitizeHTML(entry.user_count)}</td>
                            </tr>`;
                    case 'DocumentCopies':
                        return `
                            <tr>
                                <td>${sanitizeHTML(entry.file_name)}</td>
                                <td>${sanitizeHTML(entry.copy_count)}</td>
                                <td>${sanitizeHTML(entry.offices_with_copy || 'None')}</td>
                            </tr>`;
                    case 'PendingRequests':
                    case 'RetrievalHistory':
                    case 'AccessHistory':
                        return `
                            <tr>
                                <td>${sanitizeHTML(entry.transaction_id)}</td>
                                <td>${new Date(entry.time).toLocaleString()}</td>
                                <td>${sanitizeHTML(entry.username)}</td>
                                <td>${sanitizeHTML(entry.file_name)}</td>
                                <td>${sanitizeHTML(entry.department_name || 'None')}</td>
                            </tr>`;
                    default:
                        return '';
                }
            }).join('');

            return `
                <table class="data-table">
                    <thead>
                        <tr>
                            ${headers.map(header => `<th>${sanitizeHTML(header)}</th>`).join('')}
                        </tr>
                    </thead>
                    <tbody>
                        ${tableRows}
                    </tbody>
                </table>
            `;
        };

        // Generate Report Content for Print
        const generateReportContent = (chartType) => {
            const reportContent = generateTableContent(chartType, 1, 'all', true);
            const canvas = document.getElementById(`${chartType}Chart`);
            let chartImage = '';
            if (canvas && CHART_CONFIG[chartType].type) {
                chartImage = canvas.toDataURL('image/png', 1.0);
            }
            const currentDate = new Date().toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });

            return `
                <!DOCTYPE html>
                <html lang="en">
                    <head>
                        <meta charset="UTF-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                        <title>${chartType} Report - ArcHive</title>
                        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
                        <style>
                            body { 
                                font-family: 'Inter', Arial, sans-serif; 
                                margin: 0.3in auto;
                                padding: 0 0.5in;
                                color: #2d3748; 
                                line-height: 1.4;
                                background-color: #ffffff;
                                width: 8.5in;
                                box-sizing: border-box;
                                display: flex;
                                flex-direction: column;
                                align-items: center;
                            }
                            h1 { 
                                font-size: 18px; 
                                text-align: center; 
                                margin: 0.2in 0 10px 0;
                                color: #2c3e50; 
                                font-weight: 600;
                                width: 100%;
                            }
                            h2 { 
                                font-size: 12px; 
                                text-align: center; 
                                margin: 0 0 15px 0; 
                                color: #4a5568; 
                                font-weight: 400;
                                width: 100%;
                            }
                            h3 {
                                font-size: 14px;
                                margin: 15px 0 10px 0;
                                color: #2c3e50;
                                font-weight: 500;
                                text-align: center;
                                width: 100%;
                            }
                            img { 
                                max-width: 100%; 
                                width: 450px; 
                                height: auto;
                                display: block; 
                                margin: 0 auto 15px auto;
                                border: 1px solid #e2e8f0;
                                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                            }
                            table { 
                                width: 100%; 
                                max-width: 750px; 
                                border-collapse: collapse; 
                                margin: 10px auto;
                                font-size: 8pt; 
                                background-color: #ffffff; 
                                box-shadow: 0 2px 6px rgba(0,0,0,0.05); 
                            }
                            th, td { 
                                border: 1px solid #e2e8f0; 
                                padding: 6px 8px;
                                text-align: left; 
                                word-wrap: break-word; 
                            }
                            th { 
                                background-color: #50c878; 
                                color: #ffffff; 
                                font-weight: 500; 
                                text-transform: uppercase; 
                                font-size: 7pt;
                                text-align: center;
                            }
                            td { 
                                color: #2d3748; 
                                font-size: 7pt;
                            }
                            tr:nth-child(even) { 
                                background-color: #f9fafb; 
                            }
                            tr:hover { 
                                background-color: #f1f5f9; 
                            }
                            .no-data {
                                text-align: center;
                                font-size: 8pt;
                                color: #4a5568;
                                margin: 10px 0;
                                width: 100%;
                            }
                            @media print {
                                body { 
                                    margin: 0.3in auto;
                                    padding: 0 0.5in;
                                    -webkit-print-color-adjust: exact; 
                                    print-color-adjust: exact;
                                    width: 8.5in;
                                    box-sizing: border-box;
                                    display: flex;
                                    flex-direction: column;
                                    align-items: center;
                                }
                                table { 
                                    font-size: 7pt; 
                                    margin: 10px auto;
                                }
                                th { 
                                    background-color: #50c878 !important; 
                                    color: #ffffff !important;
                                    text-align: center !important;
                                }
                                td {
                                    text-align: left;
                                }
                                tr:nth-child(even) { 
                                    background-color: #f9fafb !important; 
                                }
                                img {
                                    max-width: 100% !important;
                                    width: 450px !important;
                                    height: auto !important;
                                    margin: 0 auto 15px auto !important;
                                }
                            }
                        </style>
                    </head>
                    <body>
                        <h1>${sanitizeHTML(CHART_CONFIG[chartType].title)}</h1>
                        <h2>Generated on: ${currentDate}</h2>
                        ${chartImage ? `<img src="${chartImage}" alt="${sanitizeHTML(chartType)} Chart">` : '<p class="no-data">No chart available for this report.</p>'}
                        <h3>Data Table</h3>
                        ${reportContent}
                    </body>
                </html>
            `;
        };

        // Generate Printable Report
        const generateReport = (chartType) => {
            const reportContent = generateReportContent(chartType);
            const printWindow = window.open('', '_blank');
            printWindow.document.write(reportContent);
            printWindow.document.close();
            printWindow.onload = () => {
                printWindow.focus();
                setTimeout(() => {
                    printWindow.print();
                }, 500);
            };
        };

        // Download Report as CSV, PDF, or Word
        const downloadReport = (chartType, format) => {
            const dataKey = chartType === 'FileUploadTrends' ? 'FileUploadTrendsTable' : chartType;
            const data = dashboardData[dataKey] || [];
            if (!data.length) {
                alert('No data available for download.');
                closeDownloadModal();
                return;
            }

            if (format === 'csv') {
                const headers = {
                    FileUploadTrends: ['File ID', 'File Name', 'Upload Date', 'Uploader', 'Department Name', 'Document Type'],
                    FileDistribution: ['Department Name', 'File Count'],
                    UsersPerDepartment: ['Department Name', 'User Count'],
                    DocumentCopies: ['File Name', 'Copy Count', 'Offices with Copy'],
                    PendingRequests: ['Transaction ID', 'Time', 'Username', 'File Name', 'Department Name'],
                    RetrievalHistory: ['Transaction ID', 'Time', 'Username', 'File Name', 'Department Name'],
                    AccessHistory: ['Transaction ID', 'Time', 'Username', 'File Name', 'Department Name']
                };

                let csvContent = headers[chartType].map(escapeCsvField).join(',') + '\n';
                data.forEach(entry => {
                    let row;
                    switch (chartType) {
                        case 'FileUploadTrends':
                            row = [
                                entry.file_id || '',
                                entry.file_name || '',
                                entry.upload_date ? new Date(entry.upload_date).toLocaleString() : 'N/A',
                                entry.uploader_name || '',
                                entry.department_name || 'None',
                                entry.document_type || 'None'
                            ];
                            break;
                        case 'FileDistribution':
                            row = [
                                entry.department_name || '',
                                entry.count || 0
                            ];
                            break;
                        case 'UsersPerDepartment':
                            row = [
                                entry.department_name || '',
                                entry.user_count || 0
                            ];
                            break;
                        case 'DocumentCopies':
                            row = [
                                entry.file_name || '',
                                entry.copy_count || 0,
                                entry.offices_with_copy || 'None'
                            ];
                            break;
                        case 'PendingRequests':
                        case 'RetrievalHistory':
                        case 'AccessHistory':
                            row = [
                                entry.transaction_id || '',
                                entry.time ? new Date(entry.time).toLocaleString() : 'N/A',
                                entry.username || '',
                                entry.file_name || '',
                                entry.department_name || 'None'
                            ];
                            break;
                        default:
                            alert('Download not implemented for this report type.');
                            closeDownloadModal();
                            return;
                    }
                    csvContent += row.map(escapeCsvField).join(',') + '\n';
                });

                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = `${chartType}_Report.csv`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
            } else if (format === 'pdf') {
                window.location.href = `?download=pdf&report=${chartType}`;
            } else if (format === 'word') {
                window.location.href = `?download=word&report=${chartType}`;
            } else {
                alert('Invalid format selected.');
            }
            closeDownloadModal();

            
        };

        // Sidebar Toggle
        const toggleSidebar = () => {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const topNav = document.querySelector('.top-nav');
            sidebar.classList.toggle('minimized');
            mainContent.classList.toggle('resized');
            topNav.classList.toggle('resized');
        };

        // Modal Functions
        let currentChartType = '';
        let currentPage = 1;
        let itemsPerPage = ITEMS_PER_PAGE_OPTIONS[0];

        const openModal = (chartType) => {
            currentChartType = chartType;
            currentPage = 1;
            const modal = document.getElementById('dataTableModal');
            const modalTitle = document.getElementById('modalTitle');
            modalTitle.textContent = CHART_CONFIG[chartType].title || chartType;
            renderTable();
            modal.style.display = 'flex';
        };

        const closeModal = () => {
            const modal = document.getElementById('dataTableModal');
            modal.style.display = 'none';
            document.getElementById('modalTable').innerHTML = '';
        };

        const openDownloadModal = (chartType) => {
            currentChartType = chartType;
            const modal = document.getElementById('downloadFormatModal');
            const modalTitle = document.getElementById('downloadModalTitle');
            modalTitle.textContent = `Select Download Format for ${CHART_CONFIG[chartType].title || chartType} Report`;
            modal.style.display = 'flex';
        };

        const closeDownloadModal = () => {
            const modal = document.getElementById('downloadFormatModal');
            modal.style.display = 'none';
        };

        const updatePagination = () => {
            const itemsPerPageSelect = document.getElementById('itemsPerPage');
            itemsPerPage = itemsPerPageSelect.value === 'all' ? dashboardData[CHART_CONFIG[currentChartType].dataKey]?.length || 1 : parseInt(itemsPerPageSelect.value);
            currentPage = 1;
            renderTable();
        };

        const previousPage = () => {
            if (currentPage > 1) {
                currentPage--;
                renderTable();
            }
        };

        const nextPage = () => {
            const maxPage = Math.ceil(dashboardData[CHART_CONFIG[currentChartType].dataKey]?.length / itemsPerPage) || 1;
            if (currentPage < maxPage) {
                currentPage++;
                renderTable();
            }
        };

        const renderTable = () => {
            const modalTable = document.getElementById('modalTable');
            modalTable.innerHTML = generateTableContent(currentChartType, currentPage, itemsPerPage);

            const prevButton = document.getElementById('prevPage');
            const nextButton = document.getElementById('nextPage');
            const pageInfo = document.getElementById('pageInfo');

            const maxPage = Math.ceil(dashboardData[CHART_CONFIG[currentChartType].dataKey]?.length / itemsPerPage) || 1;
            prevButton.disabled = currentPage === 1;
            nextButton.disabled = currentPage === maxPage || !dashboardData[CHART_CONFIG[currentChartType].dataKey]?.length;
            pageInfo.textContent = `Page ${currentPage} of ${maxPage}`;
        };

        // Event Listeners
        document.addEventListener('DOMContentLoaded', () => {
            const charts = {
                FileUploadTrends: initializeChart('fileUploadTrendsChart', CHART_CONFIG.FileUploadTrends, dashboardData.FileUploadTrends),
                FileDistribution: initializeChart('fileDistributionChart', CHART_CONFIG.FileDistribution, dashboardData.FileDistribution),
                UsersPerDepartment: initializeChart('usersPerDepartmentChart', CHART_CONFIG.UsersPerDepartment, dashboardData.UsersPerDepartment),
                DocumentCopies: initializeChart('documentCopiesChart', CHART_CONFIG.DocumentCopies, dashboardData.DocumentCopies)
            };

            document.querySelectorAll('.chart-container').forEach(container => {
                container.addEventListener('click', (e) => {
                    if (e.target.closest('.chart-actions')) return;
                    const chartType = container.dataset.chartType;
                    if (chartType) openModal(chartType);
                });
            });

            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const topNav = document.querySelector('.top-nav');
            if (sidebar && sidebar.classList.contains('minimized')) {
                mainContent.classList.add('resized');
                topNav.classList.add('resized');
            }

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    closeModal();
                    closeDownloadModal();
                }
            });
        });
    </script>
</body>
</html>