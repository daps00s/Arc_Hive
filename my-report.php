<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
require 'vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://code.jquery.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src https://fonts.gstatic.com;");

// Disable error display
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('error_log', __DIR__ . '/logs/error_log.log');
error_reporting(E_ALL);

/**
 * Validate session and return user info
 * @return array ['user_id'=>int, 'role'=>string] or null
 */
function validateSession(): ?array
{
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        error_log("Unauthorized access attempt in my-report.php: Session invalid.");
        return null;
    }
    session_regenerate_id(true);
    return ['user_id' => (int)$_SESSION['user_id'], 'role' => (string)$_SESSION['role']];
}

/**
 * Fetch user details and departments
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function fetchUserDetailsAndDepartments(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.username AS full_name, u.role,
                   GROUP_CONCAT(DISTINCT d.department_name ORDER BY d.department_name SEPARATOR ', ') AS department_names,
                   GROUP_CONCAT(DISTINCT d.department_id ORDER BY d.department_name) AS department_ids
            FROM users u
            LEFT JOIN user_departments ud ON u.user_id = ud.user_id
            LEFT JOIN departments d ON ud.department_id = d.department_id
            WHERE u.user_id = ?
            GROUP BY u.user_id
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: ['user_id' => $userId, 'full_name' => 'Unknown', 'role' => 'user', 'department_names' => 'None', 'department_ids' => ''];
    } catch (Exception $e) {
        error_log("Error fetching user details in my-report.php: " . $e->getMessage());
        return ['user_id' => $userId, 'full_name' => 'Unknown', 'role' => 'user', 'department_names' => 'None', 'department_ids' => ''];
    }
}

/**
 * Fetch document copy details
 * @param PDO $pdo
 * @param int $userId
 * @return array
 */
function fetchDocumentCopyDetails(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT f.file_id, f.file_name, f.copy_type, f.file_path AS physical_storage,
                   GROUP_CONCAT(DISTINCT d.department_name ORDER BY d.department_name SEPARATOR ', ') AS departments_with_copy
            FROM files f
            LEFT JOIN transactions t ON f.file_id = t.file_id
            LEFT JOIN user_departments ud ON t.user_department_id = ud.user_department_id
            LEFT JOIN departments d ON ud.department_id = d.department_id
            WHERE t.user_id = ? AND t.transaction_type = 'upload'
            GROUP BY f.file_id, f.file_name, f.copy_type, f.file_path
            ORDER BY f.file_name
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching document copies in my-report.php: " . $e->getMessage());
        return [];
    }
}

// Initialize variables
$errorMessage = '';
$userId = null;
$userRole = 'user';
$user = ['full_name' => 'Unknown', 'department_names' => 'None', 'department_ids' => ''];
$documentCopies = [];
$csrfToken = bin2hex(random_bytes(32));

// Check database connection
if (!isset($pdo) || !$pdo instanceof PDO) {
    $errorMessage = 'Database connection not available. Please try again later.';
    error_log("Database connection not available in my-report.php.");
} else {
    // Validate session
    $session = validateSession();
    if ($session === null) {
        header('Location: login.php');
        exit;
    }
    $userId = $session['user_id'];
    $userRole = $session['role'];

    // Generate CSRF token
    $_SESSION['csrf_token'] = $csrfToken;

    // Fetch user details and departments
    $user = fetchUserDetailsAndDepartments($pdo, $userId);
    $user['department_ids'] = explode(',', $user['department_ids'] ?? '');

    // Fetch document copies
    $documentCopies = fetchDocumentCopyDetails($pdo, $userId);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <title>File Activity Report</title>
    <link rel="stylesheet" href="style/client-sidebar.css">
    <link rel="stylesheet" href="style/my-report.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/noty/3.1.4/noty.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/noty/3.1.4/noty.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
</head>

<body>
    <aside class="sidebar" role="navigation" aria-label="Main Navigation">
        <button class="toggle-btn" title="Toggle Sidebar" aria-label="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Document Archival</h2>
        <?php if ($userRole === 'admin'): ?>
            <a href="admin_dashboard.php" class="admin-dashboard-btn" data-tooltip="Admin Dashboard" aria-label="Admin Dashboard">
                <i class="fas fa-user-shield"></i><span class="link-text">Admin Dashboard</span>
            </a>
        <?php endif; ?>
        <a href="dashboard.php" class="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '') ?>" data-tooltip="Dashboard" aria-label="Dashboard">
            <i class="fas fa-home"></i><span class="link-text">Dashboard</span>
        </a>
        <a href="my-report.php" data-tooltip="My Report" aria-label="My Report">
            <i class="fas fa-chart-bar"></i><span class="link-text">My Report</span>
        </a>
        <a href="my-folder.php" class="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']) === 'my-folder.php' ? 'active' : '') ?>" data-tooltip="My Folder" aria-label="My Folder">
            <i class="fas fa-folder"></i><span class="link-text">My Folder</span>
        </a>
        <?php foreach ($userDepartments as $dept): ?>
            <a href="department_folder.php?department_id=<?= htmlspecialchars(filter_var($dept['department_id'], FILTER_SANITIZE_NUMBER_INT)) ?>"
                class="<?= isset($_GET['department_id']) && (int)$_GET['department_id'] === $dept['department_id'] ? 'active' : '' ?>"
                data-tooltip="<?= htmlspecialchars($dept['department_name'] ?? 'Unnamed Department') ?>"
                aria-label="<?= htmlspecialchars($dept['department_name'] ?? 'Unnamed Department') ?> Folder">
                <i class="fas fa-folder"></i>
                <span class="link-text"><?= htmlspecialchars($dept['department_name'] ?? 'Unnamed Department') ?></span>
            </a>
        <?php endforeach; ?>
        <a href="logout.php" class="logout-btn" data-tooltip="Logout" aria-label="Logout">
            <i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span>
        </a>
    </aside>

    <div class="main-content <?php echo $userRole === 'admin' ? '' : 'resized'; ?>">
        <div class="top-nav <?php echo $userRole === 'admin' ? '' : 'resized'; ?>" id="topNav">
            <button class="toggle-btn" id="toggleNavSidebar"><i class="fas fa-bars"></i></button>
            <h2>File Activity Report</h2>
            <div class="filter-container">
                <div class="interval-buttons">
                    <button class="interval-btn active" data-interval="day">Day</button>
                    <button class="interval-btn" data-interval="week">Week</button>
                    <button class="interval-btn" data-interval="month">Month</button>
                    <button class="interval-btn" data-interval="range">Custom Range</button>
                </div>
                <div class="date-range" id="dateRange">
                    <label><i class="fas fa-calendar-alt"></i> Start: <input type="date" id="startDate"></label>
                    <label><i class="fas fa-calendar-alt"></i> End: <input type="date" id="endDate"></label>
                </div>
                <?php if (!empty($user['department_ids'])): ?>
                    <select id="departmentFilter">
                        <option value="">All Departments</option>
                        <?php foreach (array_combine($user['department_ids'], explode(', ', $user['department_names'])) as $id => $name): ?>
                            <option value="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <button id="applyFilter"><i class="fas fa-filter"></i> Apply</button>
                <button id="clearFilter"><i class="fas fa-times"></i> Clear</button>
            </div>
        </div>

        <div id="print-header">
            <h1>File Activity Report</h1>
            <p>Generated by: <?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>, Departments: <?php echo htmlspecialchars($user['department_names'], ENT_QUOTES, 'UTF-8'); ?></p>
            <p>Generated on: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>

        <div class="main-content">
            <?php if ($errorMessage): ?>
                <div id="customAlert" class="alert error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <div class="chart-container">
                <canvas id="myChart"></canvas>
                <div class="chart-actions">
                    <button class="download-btn" onclick="downloadChart()"><i class="fas fa-download"></i> Download PDF</button>
                    <button class="print-btn" onclick="printChart()"><i class="fas fa-print"></i> Print</button>
                </div>
            </div>
            <div class="files-table">
                <h3>File Activity</h3>
                <div class="table-controls">
                    <select id="sortBy">
                        <option value="event_date">Sort by Date</option>
                        <option value="file_name">Sort by File Name</option>
                        <option value="document_type">Sort by Type</option>
                        <option value="department_name">Sort by Department</option>
                    </select>
                    <select id="filterDirection">
                        <option value="">All Directions</option>
                        <option value="Sent">Sent</option>
                        <option value="Received">Received</option>
                    </select>
                </div>
                <table id="filesTable">
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Department</th>
                            <th>Uploader</th>
                            <th>Direction</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="files-table">
                <h3>Document Copies</h3>
                <table id="copiesTable">
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Copy Type</th>
                            <th>Physical Storage</th>
                            <th>Departments with Copy</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documentCopies as $copy): ?>
                            <tr>
                                <td title="<?php echo htmlspecialchars($copy['file_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($copy['file_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td><?php echo htmlspecialchars($copy['copy_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($copy['physical_storage'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($copy['departments_with_copy'] ?? 'None', ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="loading-spinner"></div>
        </div>
    </div>

    <script>
        const elements = {
            chartCanvas: document.getElementById('myChart'),
            filesTable: document.getElementById('filesTable'),
            copiesTable: document.getElementById('copiesTable'),
            printHeader: document.getElementById('print-header'),
            intervalButtons: document.querySelectorAll('.interval-btn'),
            startDate: document.getElementById('startDate'),
            endDate: document.getElementById('endDate'),
            departmentFilter: document.getElementById('departmentFilter'),
            sortBy: document.getElementById('sortBy'),
            filterDirection: document.getElementById('filterDirection'),
            applyFilter: document.getElementById('applyFilter'),
            clearFilter: document.getElementById('clearFilter'),
            dateRange: document.getElementById('dateRange')
        };

        const state = {
            isLoading: false,
            chartInstance: null,
            tableData: [],
            interval: 'day',
            startDate: '',
            endDate: '',
            departmentId: ''
        };

        const notyf = new Noty({
            theme: 'metroui',
            timeout: 3000,
            progressBar: true
        });

        const setLoadingState = (isLoading) => {
            state.isLoading = isLoading;
            document.querySelector('.loading-spinner').style.display = isLoading ? 'block' : 'none';
            elements.applyFilter.disabled = isLoading;
        };

        const showAlert = (message, type) => notyf.open({
            type,
            message
        });

        const updateTable = () => {
            const tbody = elements.filesTable.querySelector('tbody');
            tbody.innerHTML = '';
            let filteredData = [...state.tableData];

            const direction = elements.filterDirection.value;
            if (direction) {
                filteredData = filteredData.filter(row => row.direction === direction);
            }

            const departmentId = elements.departmentFilter ? elements.departmentFilter.value : '';
            if (departmentId) {
                filteredData = filteredData.filter(row => row.department_id === departmentId);
            }

            const sortBy = elements.sortBy.value;
            filteredData.sort((a, b) => {
                if (sortBy === 'event_date') {
                    return new Date(b[sortBy]) - new Date(a[sortBy]);
                }
                return a[sortBy].localeCompare(b[sortBy]);
            });

            if (filteredData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="no-data">No files found for this period.</td></tr>';
                return;
            }

            filteredData.forEach(row => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td title="${row.file_name}">${row.file_name}</td>
                    <td>${row.document_type}</td>
                    <td>${row.upload_date ? new Date(row.upload_date).toLocaleDateString('en-US') : 'N/A'}</td>
                    <td>${row.department_name}</td>
                    <td>${row.uploader}</td>
                    <td>${row.direction}</td>
                `;
                tbody.appendChild(tr);
            });
        };

        const updateChart = () => {
            if (state.isLoading) return;
            setLoadingState(true);

            const data = {
                interval: state.interval,
                startDate: elements.startDate.value,
                endDate: elements.endDate.value,
                departmentId: elements.departmentFilter ? elements.departmentFilter.value : '',
                csrf_token: document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            };

            fetch('fetch_incoming_outgoing.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': data.csrf_token
                    },
                    body: JSON.stringify(data)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        showAlert(data.error, 'error');
                        return;
                    }

                    if (state.chartInstance) {
                        state.chartInstance.destroy();
                    }

                    state.chartInstance = new Chart(elements.chartCanvas, {
                        type: 'line',
                        data: {
                            labels: data.labels || ['No Data'],
                            datasets: [{
                                    label: 'Files Sent',
                                    data: data.datasets.files_sent || [0],
                                    borderColor: '#34d058',
                                    backgroundColor: 'rgba(52, 208, 88, 0.2)',
                                    fill: true
                                },
                                {
                                    label: 'Files Received',
                                    data: data.datasets.files_received || [0],
                                    borderColor: '#2c3e50',
                                    backgroundColor: 'rgba(44, 62, 80, 0.2)',
                                    fill: true
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    position: 'top'
                                },
                                title: {
                                    display: true,
                                    text: 'File Activity Report'
                                }
                            },
                            animation: {
                                duration: 1000,
                                easing: 'easeOutQuart'
                            }
                        }
                    });

                    state.tableData = data.tableData || [];
                    updateTable();
                })
                .catch(err => {
                    console.error('Fetch error:', err);
                    showAlert('Failed to load report data.', 'error');
                })
                .finally(() => setLoadingState(false));
        };

        const downloadChart = async () => {
            if (state.isLoading) return;
            setLoadingState(true);
            try {
                const {
                    jsPDF
                } = window.jspdf;
                const headerImg = await html2canvas(elements.printHeader, {
                    scale: 2
                });
                const chartImg = await html2canvas(elements.chartCanvas, {
                    scale: 2
                });
                const fileTableImg = await html2canvas(elements.filesTable, {
                    scale: 2
                });
                const copiesTableImg = await html2canvas(elements.copiesTable, {
                    scale: 2
                });

                const pdf = new jsPDF('p', 'pt', 'a4');
                pdf.addImage(headerImg, 'PNG', 20, 20, 555, 555 * headerImg.height / headerImg.width);
                pdf.addPage();
                pdf.addImage(chartImg, 'PNG', 20, 20, 555, 555 * chartImg.height / chartImg.width);
                pdf.addPage();
                pdf.addImage(fileTableImg, 'PNG', 20, 20, 555, 555 * fileTableImg.height / fileTableImg.width);
                pdf.addPage();
                pdf.addImage(copiesTableImg, 'PNG', 20, 20, 555, 555 * copiesTableImg.height / copiesTableImg.width);
                pdf.save('file-activity-report.pdf');
            } catch (err) {
                console.error('Download error:', err);
                showAlert('Failed to generate PDF.', 'error');
            } finally {
                setLoadingState(false);
            }
        };

        const printChart = () => {
            elements.printHeader.style.display = 'block';
            window.print();
            elements.printHeader.style.display = 'none';
        };

        document.addEventListener('DOMContentLoaded', () => {
            elements.intervalButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    elements.intervalButtons.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    state.interval = btn.dataset.interval;
                    elements.dateRange.style.display = state.interval === 'range' ? 'flex' : 'none';
                });
            });

            elements.applyFilter.addEventListener('click', updateChart);
            elements.clearFilter.addEventListener('click', () => {
                elements.intervalButtons.forEach(b => b.classList.remove('active'));
                elements.intervalButtons[0].classList.add('active');
                state.interval = 'day';
                elements.startDate.value = '';
                elements.endDate.value = '';
                if (elements.departmentFilter) elements.departmentFilter.value = '';
                elements.sortBy.value = 'event_date';
                elements.filterDirection.value = '';
                elements.dateRange.style.display = 'none';
                updateChart();
            });

            elements.sortBy.addEventListener('change', updateTable);
            elements.filterDirection.addEventListener('change', updateTable);
            if (elements.departmentFilter) elements.departmentFilter.addEventListener('change', updateChart);

            document.getElementById('toggleSidebar').addEventListener('click', () => {
                document.getElementById('sidebar').classList.toggle('minimized');
                document.getElementById('topNav').classList.toggle('resized');
                document.querySelector('.main-content').classList.toggle('resized');
            });

            document.getElementById('toggleNavSidebar').addEventListener('click', () => {
                document.getElementById('sidebar').classList.toggle('minimized');
                document.getElementById('topNav').classList.toggle('resized');
                document.querySelector('.main-content').classList.toggle('resized');
            });

            updateChart();
        });
    </script>
</body>

</html>