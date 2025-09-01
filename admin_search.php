<?php
session_start();
require 'db_connection.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CSRF token generation and validation
function generateCsrfToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Redirect if not authenticated or not admin
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

// Execute prepared queries safely
function executeQuery($pdo, $query, $params = [])
{
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Database error in query: $query - " . $e->getMessage());
        return false;
    }
}

// Log admin actions
function logAdminAction($pdo, $userId, $fileId, $action, $status = 'completed', $description = null)
{
    $query = "INSERT INTO transactions (user_id, file_id, transaction_status, transaction_time, description, transaction_type) 
              VALUES (?, ?, ?, NOW(), ?, ?)";
    executeQuery($pdo, $query, [$userId, $fileId, $status, $description, $action]);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit();
    }
    $action = $_POST['action'] ?? '';
    $fileId = filter_var($_POST['file_id'] ?? null, FILTER_VALIDATE_INT);
    $fileIds = isset($_POST['file_ids']) ? json_decode($_POST['file_ids'], true) : [];

    if ($action === 'delete' && $fileId) {
        $deleteStmt = executeQuery($pdo, "UPDATE files SET file_status = 'deleted' WHERE file_id = ?", [$fileId]);
        if ($deleteStmt) {
            logAdminAction($pdo, $userId, $fileId, 'delete', 'completed', "Deleted file ID: $fileId");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete file']);
        }
    } elseif ($action === 'bulk_operation' && !empty($fileIds)) {
        $operation = $_POST['bulk_operation'] ?? '';
        $success = true;
        $message = '';
        foreach ($fileIds as $id) {
            if ($operation === 'delete') {
                $stmt = executeQuery($pdo, "UPDATE files SET file_status = 'deleted' WHERE file_id = ?", [$id]);
                if ($stmt) {
                    logAdminAction($pdo, $userId, $id, 'delete', 'completed', "Bulk deleted file ID: $id");
                } else {
                    $success = false;
                    $message = 'Failed to delete some files';
                }
            }
        }
        echo json_encode(['success' => $success, 'message' => $message ?: "Bulk $operation completed"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action or parameters']);
    }
    exit();
}

// Fetch admin details
$adminStmt = executeQuery($pdo, "SELECT user_id, username, role FROM users WHERE user_id = ?", [$userId]);
$admin = $adminStmt ? $adminStmt->fetch(PDO::FETCH_ASSOC) : null;

if (!$admin) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Fetch all files
$filesStmt = executeQuery($pdo, "
    SELECT 
        f.file_id, 
        f.file_name, 
        f.upload_date, 
        f.copy_type,
        f.file_path,
        f.file_type,
        f.file_status,
        dt.type_name AS document_type, 
        u.username AS uploaded_by,
        COALESCE(d.department_name, sd.department_name, 'N/A') AS department_name,
        COALESCE(sd.department_name, 'N/A') AS sub_department_name,
        tr.extracted_text AS file_content
    FROM files f
    LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
    LEFT JOIN users u ON f.user_id = u.user_id
    LEFT JOIN departments d ON f.department_id = d.department_id
    LEFT JOIN departments sd ON f.sub_department_id = sd.department_id
    LEFT JOIN text_repository tr ON f.file_id = tr.file_id
    WHERE f.file_status IN ('completed', 'pending_ocr', 'ocr_failed')
    ORDER BY f.upload_date DESC");
$allFiles = $filesStmt ? $filesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch filter data
$docTypesStmt = executeQuery($pdo, "SELECT type_name FROM document_types");
$documentTypes = $docTypesStmt ? $docTypesStmt->fetchAll(PDO::FETCH_COLUMN) : [];

$departmentsStmt = executeQuery($pdo, "SELECT department_name FROM departments WHERE department_type IN ('college', 'office', 'sub_department')");
$departments = $departmentsStmt ? $departmentsStmt->fetchAll(PDO::FETCH_COLUMN) : [];

$uploadersStmt = executeQuery($pdo, "SELECT DISTINCT username FROM users");
$uploaders = $uploadersStmt ? $uploadersStmt->fetchAll(PDO::FETCH_COLUMN) : [];

// File icon function
function getFileIcon($fileName, $fileType)
{
    $extension = $fileType ?: strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return match ($extension) {
        'pdf' => 'fas fa-file-pdf',
        'doc', 'docx' => 'fas fa-file-word',
        'xls', 'xlsx' => 'fas fa-file-excel',
        'jpg', 'png', 'jpeg', 'gif' => 'fas fa-file-image',
        default => 'fas fa-file'
    };
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Admin - File Management - Arc-Hive</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <link rel="stylesheet" href="style/admin-sidebar.css">
    <link rel="stylesheet" href="style/admin-search.css">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
</head>

<body class="admin-search">
    <!-- Top Navigation -->
    <div class="top-nav">
        <h2>File Management</h2>
        <input type="text" id="filterSearch" placeholder="Search by file name or content..." autocomplete="off" aria-label="Search files">
    </div>

    <!-- Sidebar -->
    <?php include 'admin_menu.php'; ?>

    <!-- Main Content -->
    <div class="main-content sidebar-expanded">
        <input type="hidden" id="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-button active" data-tab="filtered">Filtered Search</button>
            <button class="tab-button" data-tab="all">All Files</button>
        </div>

        <!-- Filtered Search Tab -->
        <div id="filtered-tab" class="tab-content active">
            <!-- Filter Section -->
            <div class="filter-section accordion">
                <button class="accordion-button">Filters <i class="fas fa-chevron-down"></i></button>
                <div class="accordion-panel">
                    <div class="search-container">
                        <div class="filter-group">
                            <label for="department">Department</label>
                            <input type="text" id="department" placeholder="Select department..." autocomplete="off" aria-label="Filter by department">
                        </div>
                        <div class="filter-group">
                            <label for="uploader">Uploader</label>
                            <input type="text" id="uploader" placeholder="Select uploader..." autocomplete="off" aria-label="Filter by uploader">
                        </div>
                    </div>
                    <div class="filter-buttons">
                        <button class="filter-button active" data-hardcopy="all">All</button>
                        <button class="filter-button" data-hardcopy="hard_copy">Hardcopy</button>
                        <button class="filter-button" data-hardcopy="soft_copy">Softcopy</button>
                        <button class="filter-button" id="resetFilters">Reset Filters</button>
                    </div>
                    <div class="sort-section">
                        <label for="sortField">Sort By:</label>
                        <select id="sortField">
                            <option value="file_name">File Name</option>
                            <option value="upload_date" selected>Upload Date</option>
                        </select>
                        <button class="filter-button" id="sortDirection" data-direction="desc">Descending</button>
                    </div>
                </div>
            </div>

            <!-- Bulk Actions -->
            <div class="filter-buttons">
                <button class="filter-button bulk-action" data-action="bulk_delete" title="Delete selected files (max 50)">Bulk Delete</button>
            </div>

            <!-- File Table -->
            <div class="table-container">
                <table class="file-table" id="fileTable">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAll"></th>
                            <th><span class="sort-link" data-sort="file_name">File Name <i class="fas fa-sort"></i></span></th>
                            <th>ID</th>
                            <th>Upload Date</th>
                            <th>Uploaded By</th>
                            <th>Department</th>
                            <th>Sub Department</th>
                            <th>Copy Type</th>
                            <th>File Status</th>
                            <th>File Path</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="fileTableBody"></tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="pagination" id="pagination"></div>
        </div>

        <!-- All Files Tab -->
        <div id="all-tab" class="tab-content">
            <h3>All Files</h3>
            <div class="table-container">
                <table class="file-table" id="allFilesTable">
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>ID</th>
                            <th>Upload Date</th>
                            <th>Uploaded By</th>
                            <th>Department</th>
                            <th>Sub Department</th>
                            <th>Copy Type</th>
                            <th>File Status</th>
                            <th>File Path</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="allFilesTableBody"></tbody>
                </table>
            </div>
        </div>

        <!-- Error Popup -->
        <div class="popup-error" id="errorPopup">
            <h3>Error</h3>
            <p id="errorMessage"></p>
            <button class="popup-button" id="closePopup">Close</button>
        </div>

        <!-- Delete Confirmation Modal -->
        <div class="modal" id="deleteConfirmModal">
            <div class="modal-content">
                <h3>Confirm Deletion</h3>
                <p>Are you sure you want to delete "<span id="deleteFileName"></span>"? This action cannot be undone.</p>
                <div class="modal-actions">
                    <button class="modal-button secondary" onclick="$('#deleteConfirmModal').hide()">Cancel</button>
                    <button class="modal-button" id="confirmDelete">Confirm Delete</button>
                </div>
            </div>
        </div>

        <script>
            // CSRF Token
            const csrfToken = document.getElementById('csrf_token').value;

            // Initialize Notyf
            const notyf = new Notyf({
                duration: 5000,
                position: {
                    x: 'right',
                    y: 'top'
                },
                ripple: true
            });

            // File data
            const allFiles = <?= json_encode($allFiles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const departments = <?= json_encode($departments, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const uploaders = <?= json_encode($uploaders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            // Filter and sort state
            let currentSort = {
                field: 'upload_date',
                direction: 'desc'
            };
            let currentPage = 1;
            const limit = 10;

            // Initialize
            $(document).ready(function() {
                // Autocomplete
                $('#department').autocomplete({
                    source: departments,
                    minLength: 0,
                    select: function(event, ui) {
                        $(this).val(ui.item.value);
                        filterAndRender();
                    }
                }).on('click', function() {
                    $(this).autocomplete('search', '');
                });

                $('#uploader').autocomplete({
                    source: uploaders,
                    minLength: 0,
                    select: function(event, ui) {
                        $(this).val(ui.item.value);
                        filterAndRender();
                    }
                }).on('click', function() {
                    $(this).autocomplete('search', '');
                });

                // Filter search input
                $('#filterSearch').on('input', debounce(filterAndRender, 300));

                // Filter buttons
                $('.filter-button[data-hardcopy]').on('click', function() {
                    $('.filter-button[data-hardcopy]').removeClass('active');
                    $(this).addClass('active');
                    filterAndRender();
                });

                // Reset filters
                $('#resetFilters').on('click', function() {
                    $('#department, #uploader, #filterSearch').val('');
                    $('.filter-button[data-hardcopy]').removeClass('active');
                    $('.filter-button[data-hardcopy="all"]').addClass('active');
                    $('#sortField').val('upload_date');
                    $('#sortDirection').data('direction', 'desc').text('Descending');
                    currentSort = {
                        field: 'upload_date',
                        direction: 'desc'
                    };
                    filterAndRender();
                });

                // Sort controls
                $('#sortField').on('change', function() {
                    currentSort.field = $(this).val();
                    filterAndRender();
                });

                $('#sortDirection').on('click', function() {
                    const direction = $(this).data('direction') === 'asc' ? 'desc' : 'asc';
                    $(this).data('direction', direction).text(direction === 'asc' ? 'Ascending' : 'Descending');
                    currentSort.direction = direction;
                    filterAndRender();
                });

                // Pagination
                $('#pagination').on('click', 'a', function(e) {
                    e.preventDefault();
                    currentPage = parseInt($(this).data('page'));
                    filterAndRender();
                });

                // Select all checkbox
                $('#selectAll').on('change', function() {
                    $('.file-checkbox').prop('checked', this.checked);
                });

                // Bulk actions
                $('.bulk-action').on('click', function() {
                    const selectedFiles = $('.file-checkbox:checked').map(function() {
                        return $(this).data('file-id');
                    }).get();
                    if (selectedFiles.length === 0) {
                        showErrorPopup('No files selected for bulk action');
                        return;
                    }
                    if (selectedFiles.length > 50) {
                        showErrorPopup('Cannot process more than 50 files at once');
                        return;
                    }
                    const action = $(this).data('action').replace('bulk_', '');
                    if (confirm(`Are you sure you want to ${action} ${selectedFiles.length} file(s)? This action cannot be undone.`)) {
                        handleBulkAction($(this).data('action'), selectedFiles);
                    }
                });

                // Tab switching
                $('.tab-button').on('click', function() {
                    $('.tab-button').removeClass('active');
                    $(this).addClass('active');
                    $('.tab-content').removeClass('active');
                    $(`#${$(this).data('tab')}-tab`).addClass('active');
                    if ($(this).data('tab') === 'all') {
                        renderAllFilesTable();
                    } else {
                        filterAndRender();
                    }
                });

                // Accordion
                $('.accordion-button').on('click', function() {
                    $(this).next('.accordion-panel').slideToggle();
                    $(this).find('i').toggleClass('fa-chevron-down fa-chevron-up');
                });

                // Action buttons
                $('#fileTableBody').on('click', '.action-preview', function(e) {
                    if (confirm('Are you sure you want to preview this file?')) {
                        handlePreview(e);
                    }
                });
                $('#fileTableBody, #allFilesTableBody').on('click', '.action-download', function(e) {
                    if (confirm('Are you sure you want to download this file?')) {
                        handleDownload(e);
                    }
                });
                $('#fileTableBody, #allFilesTableBody').on('click', '.action-delete', function() {
                    const fileId = $(this).data('file-id');
                    const fileName = allFiles.find(f => f.file_id === fileId).file_name;
                    showDeleteConfirm(fileId, fileName);
                });

                // Close error popup
                $('#closePopup').on('click', function() {
                    $('#errorPopup').hide();
                });

                // Initial render
                filterAndRender();
                renderAllFilesTable();
            });

            // Client-side file icon
            function getFileIcon(fileName, fileType) {
                const extension = fileType || fileName.split('.').pop().toLowerCase();
                switch (extension) {
                    case 'pdf':
                        return 'fas fa-file-pdf';
                    case 'doc':
                    case 'docx':
                        return 'fas fa-file-word';
                    case 'xls':
                    case 'xlsx':
                        return 'fas fa-file-excel';
                    case 'jpg':
                    case 'png':
                    case 'jpeg':
                    case 'gif':
                        return 'fas fa-file-image';
                    default:
                        return 'fas fa-file';
                }
            }

            // Filter and render
            function filterAndRender() {
                const search = $('#filterSearch').val().toLowerCase();
                const department = $('#department').val().toLowerCase();
                const uploader = $('#uploader').val().toLowerCase();
                const hardcopy = $('.filter-button.active[data-hardcopy]').data('hardcopy') || 'all';

                let filteredFiles = allFiles.filter(file => {
                    const matchesSearch = !search ||
                        (file.file_name && file.file_name.toLowerCase().includes(search)) ||
                        (file.file_content && file.file_content.toLowerCase().includes(search));
                    const matchesDept = !department ||
                        (file.department_name && file.department_name.toLowerCase().includes(department)) ||
                        (file.sub_department_name && file.sub_department_name.toLowerCase().includes(department));
                    const matchesUploader = !uploader || (file.uploaded_by && file.uploaded_by.toLowerCase() === uploader);
                    const matchesHardcopy = hardcopy === 'all' ||
                        (hardcopy === 'hard_copy' && file.copy_type === 'hard_copy') ||
                        (hardcopy === 'soft_copy' && file.copy_type === 'soft_copy');
                    return matchesSearch && matchesDept && matchesUploader && matchesHardcopy;
                });

                filteredFiles.sort((a, b) => {
                    const aValue = a[currentSort.field] || '';
                    const bValue = b[currentSort.field] || '';
                    if (currentSort.field === 'upload_date') {
                        return currentSort.direction === 'asc' ?
                            new Date(aValue) - new Date(bValue) :
                            new Date(bValue) - new Date(aValue);
                    }
                    const comparison = aValue.localeCompare(bValue, undefined, {
                        numeric: true
                    });
                    return currentSort.direction === 'asc' ? comparison : -comparison;
                });

                updateStats(filteredFiles);
                renderTable(filteredFiles);
            }

            // Render all files table
            function renderAllFilesTable() {
                const tbody = $('#allFilesTableBody');
                tbody.empty();
                if (allFiles.length === 0) {
                    tbody.append('<tr><td colspan="10" style="text-align: center; padding: 20px;">No files found.</td></tr>');
                    return;
                }
                allFiles.forEach(file => {
                    tbody.append(`
                        <tr>
                            <td><i class="${getFileIcon(file.file_name, file.file_type)} file-icon"></i>${sanitizeHTML(file.file_name)}</td>
                            <td>${sanitizeHTML(file.file_id)}</td>
                            <td>${sanitizeHTML(new Date(file.upload_date).toLocaleString())}</td>
                            <td>${sanitizeHTML(file.uploaded_by || 'N/A')}</td>
                            <td>${sanitizeHTML(file.department_name)}</td>
                            <td>${sanitizeHTML(file.sub_department_name)}</td>
                            <td>${sanitizeHTML(file.copy_type === 'hard_copy' ? 'Hard Copy' : 'Soft Copy')}</td>
                            <td>${sanitizeHTML(file.file_status || 'N/A')}</td>
                            <td>${sanitizeHTML(file.file_path)}</td>
                            <td>
                                <button class="action-button action-preview" data-file-id="${file.file_id}" data-file-path="${sanitizeHTML(file.file_path)}" title="Preview"><i class="fas fa-eye"></i></button>
                                <button class="action-button action-download" data-file-id="${file.file_id}" data-file-path="${sanitizeHTML(file.file_path)}" title="Download"><i class="fas fa-download"></i></button>
                                <button class="action-button action-delete" data-file-id="${file.file_id}" title="Delete"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    `);
                });
            }

            // Update statistics
            function updateStats(files) {
                const totalFiles = files.length;
                const hardCopyFiles = files.filter(file => file.copy_type === 'hard_copy').length;
                const softCopyFiles = files.filter(file => file.copy_type === 'soft_copy').length;
                $('#statCards').html(`
                    <div class="stat-card">
                        <h3>Total Files</h3>
                        <p>${sanitizeHTML(String(totalFiles))}</p>
                    </div>
                    <div class="stat-card">
                        <h3>Hard Copy Files</h3>
                        <p>${sanitizeHTML(String(hardCopyFiles))}</p>
                    </div>
                    <div class="stat-card">
                        <h3>Soft Copy Files</h3>
                        <p>${sanitizeHTML(String(softCopyFiles))}</p>
                    </div>
                `);
            }

            // Render table with pagination
            function renderTable(files) {
                const tbody = $('#fileTableBody');
                tbody.empty();
                const totalRecords = files.length;
                const totalPages = Math.ceil(totalRecords / limit);
                currentPage = Math.min(currentPage, totalPages) || 1;
                const start = (currentPage - 1) * limit;
                const paginatedFiles = files.slice(start, start + limit);

                if (paginatedFiles.length === 0) {
                    tbody.append('<tr><td colspan="10" style="text-align: center; padding: 20px;">No files found.</td></tr>');
                } else {
                    paginatedFiles.forEach(file => {
                        tbody.append(`
                            <tr>
                                <td><input type="checkbox" class="file-checkbox" data-file-id="${file.file_id}"></td>
                                <td><i class="${getFileIcon(file.file_name, file.file_type)} file-icon"></i>${sanitizeHTML(file.file_name)}</td>
                                <td>${sanitizeHTML(file.file_id)}</td>
                                <td>${sanitizeHTML(new Date(file.upload_date).toLocaleString())}</td>
                                <td>${sanitizeHTML(file.uploaded_by || 'N/A')}</td>
                                <td>${sanitizeHTML(file.department_name)}</td>
                                <td>${sanitizeHTML(file.sub_department_name)}</td>
                                <td>${sanitizeHTML(file.copy_type === 'hard_copy' ? 'Hard Copy' : 'Soft Copy')}</td>
                                <td>${sanitizeHTML(file.file_status || 'N/A')}</td>
                                <td>${sanitizeHTML(file.file_path)}</td>
                                <td>
                                    <button class="action-button action-preview" data-file-id="${file.file_id}" data-file-path="${sanitizeHTML(file.file_path)}" title="Preview"><i class="fas fa-eye"></i></button>
                                    <button class="action-button action-download" data-file-id="${file.file_id}" data-file-path="${sanitizeHTML(file.file_path)}" title="Download"><i class="fas fa-download"></i></button>
                                    <button class="action-button action-delete" data-file-id="${file.file_id}" title="Delete"><i class="fas fa-trash"></i></button>
                                </td>
                            </tr>
                        `);
                    });
                }

                const pagination = $('#pagination');
                pagination.empty();
                if (totalPages > 1) {
                    for (let i = 1; i <= totalPages; i++) {
                        pagination.append(`<a href="#" class="${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</a>`);
                    }
                }
            }

            // Update sort icons
            function updateSortIcons() {
                $('.sort-link').each(function() {
                    const field = $(this).data('sort');
                    $(this).find('i').removeClass('fa-sort-up fa-sort-down').addClass('fa-sort');
                    if (field === currentSort.field) {
                        $(this).find('i').removeClass('fa-sort').addClass(currentSort.direction === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
                    }
                });
            }

            // Sanitize HTML
            function sanitizeHTML(str) {
                const div = document.createElement('div');
                div.textContent = str || '';
                return div.innerHTML.replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }

            // Debounce function
            function debounce(func, wait) {
                let timeout;
                return function(...args) {
                    clearTimeout(timeout);
                    timeout = setTimeout(() => func.apply(this, args), wait);
                };
            }

            // Show error popup
            function showErrorPopup(message) {
                $('#errorMessage').text(message);
                $('#errorPopup').show();
            }

            // Handle file preview
            function handlePreview(event) {
                if (!validateCsrfToken(csrfToken)) {
                    showErrorPopup('Invalid CSRF token');
                    return;
                }
                const fileId = $(event.target).closest('button').data('file-id');
                const filePath = $(event.target).closest('button').data('file-path');
                logAdminAction(null, null, fileId, 'preview', 'completed', `Previewed file ID: ${fileId}`);
                notyf.success(`Previewing file ID: ${fileId}`);
                window.open(filePath, '_blank');
            }

            // Handle file download
            function handleDownload(event) {
                if (!validateCsrfToken(csrfToken)) {
                    showErrorPopup('Invalid CSRF token');
                    return;
                }
                const fileId = $(event.target).closest('button').data('file-id');
                const filePath = $(event.target).closest('button').data('file-path');
                $.ajax({
                    url: 'download_file.php',
                    method: 'POST',
                    data: {
                        file_id: fileId,
                        file_path: filePath,
                        csrf_token: csrfToken
                    },
                    success: response => {
                        if (response.success) {
                            logAdminAction(null, null, fileId, 'download', 'completed', `Downloaded file ID: ${fileId}`);
                            window.location.href = response.download_url;
                            notyf.success('Download initiated');
                        } else {
                            showErrorPopup(response.message || 'Failed to initiate download');
                        }
                    },
                    error: () => showErrorPopup('Error initiating download')
                });
            }

            // Handle file deletion
            function handleDelete(fileId) {
                if (!validateCsrfToken(csrfToken)) {
                    showErrorPopup('Invalid CSRF token');
                    return;
                }
                $.ajax({
                    url: 'delete_file.php',
                    method: 'POST',
                    data: {
                        file_id: fileId,
                        csrf_token: csrfToken
                    },
                    success: response => {
                        if (response.success) {
                            notyf.success('File deleted successfully');
                            allFiles.splice(allFiles.findIndex(f => f.file_id === fileId), 1);
                            filterAndRender();
                            renderAllFilesTable();
                        } else {
                            showErrorPopup(response.message || 'Failed to delete file');
                        }
                    },
                    error: () => showErrorPopup('Error deleting file')
                });
            }

            // Show delete confirmation
            function showDeleteConfirm(fileId, fileName) {
                $('#deleteFileName').text(fileName);
                $('#confirmDelete').data('file-id', fileId);
                $('#deleteConfirmModal').show();
                $('#confirmDelete').off('click').on('click', function() {
                    if (confirm(`Final confirmation: Permanently delete "${fileName}" (ID: ${fileId})?`)) {
                        handleDelete(fileId);
                        $('#deleteConfirmModal').hide();
                    }
                });
            }

            // Handle bulk action
            function handleBulkAction(action, fileIds) {
                $.ajax({
                    url: 'admin_search.php',
                    method: 'POST',
                    data: {
                        action: 'bulk_operation',
                        bulk_operation: action.replace('bulk_', ''),
                        file_ids: JSON.stringify(fileIds),
                        csrf_token: csrfToken
                    },
                    success: response => {
                        if (response.success) {
                            notyf.success(`Bulk ${action.replace('bulk_', '')} completed`);
                            if (action === 'bulk_delete') {
                                fileIds.forEach(id => {
                                    allFiles.splice(allFiles.findIndex(f => f.file_id === id), 1);
                                });
                            }
                            filterAndRender();
                            renderAllFilesTable();
                        } else {
                            showErrorPopup(response.message || `Failed to perform bulk ${action.replace('bulk_', '')}`);
                        }
                    },
                    error: () => showErrorPopup(`Error performing bulk ${action.replace('bulk_', '')}`)
                });
            }
        </script>
    </div>
</body>

</html>