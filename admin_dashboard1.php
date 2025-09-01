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

// Validate session and authentication
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

// Database connection
require 'db_connection.php';

// CSRF protection functions
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

// Database query execution with error handling
function executeQuery(PDO $pdo, string $query, array $params = []): PDOStatement
{
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Database error in executeQuery: " . $e->getMessage());
        throw new RuntimeException("Database operation failed", 0, $e);
    }
}

// Sanitize output
function sanitizeOutput(?string $data): string
{
    return htmlspecialchars($data ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Migration: Add transaction_type column if not exists
try {
    executeQuery($pdo, "
        ALTER TABLE transactions
        ADD COLUMN IF NOT EXISTS transaction_type VARCHAR(50) DEFAULT NULL
    ");
} catch (Exception $e) {
    error_log("Migration failed: " . $e->getMessage());
}

// Fetch admin details
try {
    $adminStmt = executeQuery(
        $pdo,
        "SELECT user_id, username, role FROM users WHERE user_id = ?",
        [$_SESSION['user_id']]
    );
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
    'incomingFiles' => "
        SELECT COUNT(*) AS incoming_count 
        FROM transactions t
        JOIN files f ON t.file_id = f.file_id
        WHERE t.users_department_id IN (SELECT users_department_id FROM user_department_assignments WHERE user_id = ?) 
        AND t.transaction_status = 'pending' 
        AND t.transaction_type = 'send'",
    'outgoingFiles' => "
        SELECT COUNT(*) AS outgoing_count 
        FROM transactions t
        JOIN files f ON t.file_id = f.file_id
        WHERE t.user_id = ? 
        AND t.transaction_status = 'pending' 
        AND t.transaction_type = 'send'"
];

$stats = [];
foreach ($statisticsQueries as $key => $query) {
    try {
        $params = [];
        if (in_array($key, ['incomingFiles', 'outgoingFiles'])) {
            $params = [$_SESSION['user_id']];
        }

        $stmt = executeQuery($pdo, $query, $params);
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
        $stmt = executeQuery($pdo, $query, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log("Error fetching data: " . $e->getMessage());
        return [];
    }
}

// Fetch all dashboard data with improved queries
$dashboardData = [
    'pendingRequestsDetails' => fetchData($pdo, "
        SELECT t.transaction_id, f.file_name, u.username AS requester_name, 
               COALESCE(d2.department_name, d.department_name) AS requester_department,
               CASE WHEN d2.department_id IS NOT NULL THEN d.department_name ELSE NULL END AS requester_subdepartment,
               COALESCE(sl.full_path, f.file_path) AS storage_location
        FROM transactions t
        JOIN files f ON t.file_id = f.file_id
        JOIN users u ON t.user_id = u.user_id
        JOIN user_department_assignments ud ON u.user_id = ud.user_id
        JOIN departments d ON ud.department_id = d.department_id
        LEFT JOIN departments d2 ON d.parent_department_id = d2.department_id
        LEFT JOIN storage_locations sl ON f.storage_location_id = sl.storage_location_id
        WHERE t.transaction_status = 'pending' AND t.transaction_type = 'request'
        GROUP BY t.transaction_id
        ORDER BY t.transaction_time DESC
    "),

    'fileUploadTrends' => fetchData($pdo, "
        SELECT 
            f.file_name AS document_name,
            dt.type_name AS document_type,
            f.upload_date AS upload_date,
            u.username AS uploader_name,
            COALESCE(d2.department_name, d.department_name) AS uploader_department,
            CASE WHEN d2.department_id IS NOT NULL THEN d.department_name ELSE NULL END AS uploader_subdepartment,
            td.department_name AS target_department_name
        FROM files f
        LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
        LEFT JOIN users u ON f.user_id = u.user_id
        LEFT JOIN user_department_assignments uda ON u.user_id = uda.user_id
        LEFT JOIN departments d ON uda.department_id = d.department_id
        LEFT JOIN departments d2 ON d.parent_department_id = d2.department_id
        LEFT JOIN transactions t ON f.file_id = t.file_id AND t.transaction_type = 'send'
        LEFT JOIN user_department_assignments tud ON t.users_department_id = tud.users_department_id
        LEFT JOIN departments td ON tud.department_id = td.department_id
        WHERE f.upload_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ORDER BY f.upload_date ASC
    "),

    'fileDistribution' => fetchData($pdo, "
        SELECT 
            f.file_name AS document_name,
            dt.type_name AS document_type,
            us.username AS sender_name,
            ur.username AS receiver_name,
            t.transaction_time AS time_sent,
            t2.transaction_time AS time_received,
            COALESCE(d2.department_name, d.department_name) AS department_name,
            CASE WHEN d2.department_id IS NOT NULL THEN d.department_name ELSE NULL END AS sub_department_name
        FROM files f
        LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
        LEFT JOIN transactions t ON f.file_id = t.file_id AND t.transaction_type = 'send'
        LEFT JOIN users us ON t.user_id = us.user_id
        LEFT JOIN transactions t2 ON f.file_id = t2.file_id AND t2.transaction_type = 'accept'
        LEFT JOIN users ur ON t2.user_id = ur.user_id
        LEFT JOIN user_department_assignments ud ON f.user_id = ud.user_id
        LEFT JOIN departments d ON ud.department_id = d.department_id
        LEFT JOIN departments d2 ON d.parent_department_id = d2.department_id
        WHERE us.username IS NOT NULL OR ur.username IS NOT NULL
        GROUP BY f.file_id
        ORDER BY f.upload_date DESC
    "),

    'usersPerDepartment' => fetchData($pdo, "
        SELECT 
            COALESCE(d2.department_name, d.department_name) AS department_name,
            COUNT(DISTINCT ud.user_id) AS user_count
        FROM departments d
        LEFT JOIN departments d2 ON d.parent_department_id = d2.department_id
        LEFT JOIN user_department_assignments ud ON d.department_id = ud.department_id
        WHERE d.department_type IN ('college', 'office')
        GROUP BY d.department_id
        ORDER BY department_name
    "),

    'documentCopies' => fetchData($pdo, "
        SELECT 
            f.file_name,
            COUNT(DISTINCT c.file_id) AS copy_count,
            GROUP_CONCAT(DISTINCT COALESCE(d2.department_name, d.department_name) SEPARATOR ', ') AS offices_with_copy,
            GROUP_CONCAT(DISTINCT COALESCE(sl.full_path, c.file_path) SEPARATOR ' | ') AS physical_duplicates
        FROM files f
        LEFT JOIN files c ON f.file_id = c.parent_file_id
        LEFT JOIN transactions t ON c.file_id = t.file_id AND t.transaction_type IN ('send', 'accept')
        LEFT JOIN user_department_assignments ud ON t.users_department_id = ud.users_department_id
        LEFT JOIN departments d ON ud.department_id = d.department_id
        LEFT JOIN departments d2 ON d.parent_department_id = d2.department_id
        LEFT JOIN storage_locations sl ON c.storage_location_id = sl.storage_location_id
        GROUP BY f.file_id
        ORDER BY f.file_name
    "),

    'retrievalHistory' => fetchData($pdo, "
        SELECT 
            t.transaction_id,
            t.transaction_type AS type,
            t.transaction_status AS status,
            t.transaction_time AS time,
            u.username AS user_name,
            f.file_name,
            COALESCE(d2.department_name, d.department_name) AS department_name,
            COALESCE(sl.full_path, f.file_path) AS storage_location
        FROM transactions t
        JOIN files f ON t.file_id = f.file_id
        JOIN users u ON t.user_id = u.user_id
        JOIN user_department_assignments ud ON t.users_department_id = ud.users_department_id
        JOIN departments d ON ud.department_id = d.department_id
        LEFT JOIN departments d2 ON d.parent_department_id = d2.department_id
        LEFT JOIN storage_locations sl ON f.storage_location_id = sl.storage_location_id
        WHERE t.transaction_type IN ('request', 'send', 'accept')
        ORDER BY t.transaction_time DESC
        LIMIT 100
    "),

    'accessHistory' => fetchData($pdo, "
        SELECT 
            t.transaction_id,
            t.transaction_time AS time,
            u.username AS user_name,
            f.file_name,
            t.transaction_type AS type,
            COALESCE(d2.department_name, d.department_name) AS department_name
        FROM transactions t
        JOIN files f ON t.file_id = f.file_id
        JOIN users u ON t.user_id = u.user_id
        JOIN user_department_assignments ud ON t.users_department_id = ud.users_department_id
        JOIN departments d ON ud.department_id = d.department_id
        LEFT JOIN departments d2 ON d.parent_department_id = d2.department_id
        WHERE t.transaction_type = 'accept'
        ORDER BY t.transaction_time DESC
        LIMIT 100
    ")
];

// Extract variables for easier access in the view
[
    'pendingRequestsDetails' => $pendingRequestsDetails,
    'fileUploadTrends' => $fileUploadTrends,
    'fileDistribution' => $fileDistribution,
    'usersPerDepartment' => $usersPerDepartment,
    'documentCopies' => $documentCopies,
    'retrievalHistory' => $retrievalHistory,
    'accessHistory' => $accessHistory
] = $dashboardData;

// Generate CSRF token for forms
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Admin Dashboard - ArcHive</title>
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="script/admin_dashboard.js"></script>
</head>

<body class="admin-dashboard">
    <?php include 'admin_menu.php'; ?>

    <div class="top-nav">
        <h2>Welcome, <?= sanitizeOutput($admin['username']) ?>!</h2>
        <button class="toggle-btn" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="main-content">
        <?php if (isset($errorMessage)): ?>
            <div class="error-message"><i class="fas fa-exclamation-circle"></i> <?= sanitizeOutput($errorMessage) ?></div>
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
                <i class="fas fa-arrow-down"></i>
                <h3>Incoming Files</h3>
                <p><?= $stats['incomingFiles'] ?></p>
            </div>
            <div class="stat-card">
                <i class="fas fa-arrow-up"></i>
                <h3>Outgoing Files</h3>
                <p><?= $stats['outgoingFiles'] ?></p>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="chart-grid">
            <!-- File Upload Trends -->
            <div class="chart-container" data-chart-type="FileUploadTrends">
                <h3>File Upload Trends (Last 7 Days)</h3>
                <canvas id="fileUploadTrendsChart"></canvas>
                <div class="chart-actions">
                    <button onclick="generateReport('FileUploadTrends')"><i class="fas fa-print"></i> Print Report</button>
                    <button onclick="openDownloadModal('FileUploadTrends')"><i class="fas fa-download"></i> Download Report</button>
                </div>
            </div>

            <!-- File Distribution -->
            <div class="chart-container" data-chart-type="FileDistribution">
                <h3>File Distribution by Document Type</h3>
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
                <h3>Document Copies Details</h3>
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
                <button class="modal-close" onclick="closeDownloadModal()" aria-label="Close Download Modal"><i class="fas fa-times"></i></button>
                <h3 id="downloadModalTitle">Select Download Format</h3>
                <div class="download-options">
                    <button onclick="downloadReport(currentChartType, 'csv')"><i class="fas fa-file-csv"></i> Download as CSV</button>
                    <button onclick="downloadReport(currentChartType, 'pdf')"><i class="fas fa-file-pdf"></i> Download as PDF</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Pass PHP data to JavaScript -->
    <script>
        const dashboardData = {
            fileUploadTrends: <?= json_encode($fileUploadTrends, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            fileDistribution: <?= json_encode($fileDistribution, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            usersPerDepartment: <?= json_encode($usersPerDepartment, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            documentCopies: <?= json_encode($documentCopies, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            pendingRequestsDetails: <?= json_encode($pendingRequestsDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            retrievalHistory: <?= json_encode($retrievalHistory, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            accessHistory: <?= json_encode($accessHistory, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
        };
    </script>
</body>

</html>