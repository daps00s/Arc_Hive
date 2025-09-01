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

// Redirect to login if not authenticated or not an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
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

// Function to execute prepared queries safely
function executeQuery($pdo, $query, $params = [])
{
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    }
}

// Function to sanitize HTML output
function sanitizeHTML($data)
{
    return htmlspecialchars($data ?? '', ENT_QUOTES, 'UTF-8');
}

// Function to create folder
function createFolder($path)
{
    if (!file_exists($path)) {
        return mkdir($path, 0777, true);
    }
    return true;
}

// Base directory for file storage
$baseDir = __DIR__ . '/Uploads/';

// Handle form submissions for add/edit/delete departments
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_department') {
        $name = trim($_POST['department_name'] ?? '');
        $type = trim($_POST['department_type'] ?? '');
        $nameType = trim($_POST['name_type'] ?? '');
        $parentId = !empty($_POST['parent_department_id']) ? (int)$_POST['parent_department_id'] : null;

        if (!empty($name) && !empty($type) && !empty($nameType)) {
            // Generate folder path
            $folderPath = $baseDir . $name;
            if ($type === 'sub_department' && $parentId) {
                $parentStmt = executeQuery($pdo, "SELECT folder_path FROM departments WHERE department_id = ?", [$parentId]);
                $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);
                $folderPath = $parent['folder_path'] . '/' . $name;
            }

            // Create folder
            if (createFolder($folderPath)) {
                $insertStmt = executeQuery(
                    $pdo,
                    "
                    INSERT INTO departments (department_name, department_type, name_type, parent_department_id, folder_path)
                    VALUES (?, ?, ?, ?, ?)",
                    [$name, $type, $nameType, $parentId, $folderPath]
                );
                if ($insertStmt) {
                    $successMessage = 'Department added successfully.';
                    executeQuery(
                        $pdo,
                        "
                        INSERT INTO transactions (user_id, transaction_status, transaction_time, description)
                        VALUES (?, 'Success', NOW(), ?)",
                        [$userId, "Added department: $name"]
                    );
                } else {
                    $errorMessage = 'Failed to add department.';
                }
            } else {
                $errorMessage = 'Failed to create folder for department.';
            }
        } else {
            $errorMessage = 'All fields are required.';
        }
    } elseif ($action === 'edit_department') {
        $id = (int)($_POST['department_id'] ?? 0);
        $name = trim($_POST['department_name'] ?? '');
        $type = trim($_POST['department_type'] ?? '');
        $nameType = trim($_POST['name_type'] ?? '');
        $parentId = !empty($_POST['parent_department_id']) ? (int)$_POST['parent_department_id'] : null;

        if ($id > 0 && !empty($name) && !empty($type) && !empty($nameType)) {
            // Generate folder path
            $folderPath = $baseDir . $name;
            if ($type === 'sub_department' && $parentId) {
                $parentStmt = executeQuery($pdo, "SELECT folder_path FROM departments WHERE department_id = ?", [$parentId]);
                $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);
                $folderPath = $parent['folder_path'] . '/' . $name;
            }

            if (createFolder($folderPath)) {
                $updateStmt = executeQuery(
                    $pdo,
                    "
                    UPDATE departments 
                    SET department_name = ?, department_type = ?, name_type = ?, parent_department_id = ?, folder_path = ?
                    WHERE department_id = ?",
                    [$name, $type, $nameType, $parentId, $folderPath, $id]
                );
                if ($updateStmt) {
                    $successMessage = 'Department updated successfully.';
                    executeQuery(
                        $pdo,
                        "
                        INSERT INTO transactions (user_id, transaction_status, transaction_time, description)
                        VALUES (?, 'Success', NOW(), ?)",
                        [$userId, "Edited department ID: $id"]
                    );
                } else {
                    $errorMessage = 'Failed to update department.';
                }
            } else {
                $errorMessage = 'Failed to create folder for department.';
            }
        } else {
            $errorMessage = 'Invalid data for update.';
        }
    } elseif ($action === 'delete_department') {
        $id = (int)($_POST['department_id'] ?? 0);
        if ($id > 0) {
            $childrenStmt = executeQuery($pdo, "SELECT COUNT(*) FROM departments WHERE parent_department_id = ?", [$id]);
            $childrenCount = $childrenStmt->fetchColumn();
            if ($childrenCount > 0) {
                $errorMessage = 'Cannot delete department with sub-departments.';
            } else {
                $deleteStmt = executeQuery($pdo, "DELETE FROM departments WHERE department_id = ?", [$id]);
                if ($deleteStmt) {
                    $successMessage = 'Department deleted successfully.';
                    executeQuery(
                        $pdo,
                        "
                        INSERT INTO transactions (user_id, transaction_status, transaction_time, description)
                        VALUES (?, 'Success', NOW(), ?)",
                        [$userId, "Deleted department ID: $id"]
                    );
                } else {
                    $errorMessage = 'Failed to delete department.';
                }
            }
        } else {
            $errorMessage = 'Invalid department ID.';
        }
    }
}

// Fetch all departments
$allDepartments = executeQuery($pdo, "
    SELECT d.*, p.department_name as parent_name 
    FROM departments d 
    LEFT JOIN departments p ON d.parent_department_id = p.department_id
    ORDER BY d.department_type, d.department_name
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch parent departments for dropdown
$parentDepartments = executeQuery($pdo, "
    SELECT department_id, department_name 
    FROM departments 
    WHERE department_type IN ('college', 'office')
")->fetchAll(PDO::FETCH_ASSOC);

// Organize departments hierarchically
$departmentTree = [];
foreach ($allDepartments as $dept) {
    if (!$dept['parent_department_id']) {
        $departmentTree[$dept['department_id']] = [
            'dept' => $dept,
            'sub_departments' => []
        ];
    } else {
        $departmentTree[$dept['parent_department_id']]['sub_departments'][] = $dept;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/noty/3.1.4/noty.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/noty/3.1.4/noty.min.js"></script>
    <link rel="stylesheet" href="style/admin-sidebar.css">
    <link rel="stylesheet" href="style/department_management.css">
    <style>
        /* Additional styles for improved UX/UI */
        .dept-section {
            background: var(--card-background);
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .dept-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            background: var(--secondary-color);
            color: var(--button-text-light);
            cursor: pointer;
            transition: var(--transition);
        }

        .dept-header:hover {
            background: var(--secondary-hover);
        }

        .dept-header h3 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
        }

        .dept-content {
            display: none;
            padding: 16px;
        }

        .dept-content.active {
            display: block;
        }

        .subdept-item {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .subdept-item:last-child {
            border-bottom: none;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .modal-content {
            padding: 24px;
            max-width: 500px;
        }

        .modal-form label {
            font-weight: 600;
            margin-bottom: 8px;
            display: block;
        }

        .modal-form input,
        .modal-form select {
            padding: 10px;
            font-size: 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            width: 100%;
            box-sizing: border-box;
        }

        .modal-buttons {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .error-message,
        .success-message {
            display: none;
            /* Handled by Noty */
        }
    </style>
</head>

<body class="department-management">
    <div class="app-container">
        <div class="sidebar">
            <h2 class="sidebar-title">Admin Panel</h2>
            <a href="dashboard.php" class="client-btn">
                <i class="fas fa-exchange-alt"></i>
                <span class="link-text">Switch to Client View</span>
            </a>
            <a href="admin_dashboard.php" class="active">
                <i class="fas fa-tachometer-alt"></i>
                <span class="link-text">Dashboard</span>
            </a>
            <a href="admin_search.php">
                <i class="fas fa-search"></i>
                <span class="link-text">View All Files</span>
            </a>
            <a href="user_management.php">
                <i class="fas fa-users"></i>
                <span class="link-text">User Management</span>
            </a>
            <a href="department_management.php">
                <i class="fas fa-building"></i>
                <span class="link-text">Department Management</span>
            </a>
            <a href="physical_storage_management.php">
                <i class="fas fa-archive"></i>
                <span class="link-text">Physical Storage</span>
            </a>
            <a href="document_type_management.php">
                <i class="fas fa-file-alt"></i>
                <span class="link-text">Document Type Management</span>
            </a>
            <a href="backup.php">
                <i class="fas fa-database"></i>
                <span class="link-text">System Backup</span>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="link-text">Logout</span>
                </a>
        </div>
        <div class="top-nav">
            <h2>Department Management</h2>
        </div>
        <div class="main-content">
            <div class="action-buttons-container">
                <button class="primary-btn" onclick="openModal('add')"><i class="fas fa-plus"></i> Add Department</button>
            </div>
            <?php if (!empty($errorMessage)): ?>
                <p class="error-message"><?php echo htmlspecialchars($errorMessage); ?></p>
            <?php endif; ?>
            <?php if (!empty($successMessage)): ?>
                <p class="success-message"><?php echo htmlspecialchars($successMessage); ?></p>
            <?php endif; ?>
            <?php foreach ($departmentTree as $parent): ?>
                <div class="dept-section">
                    <div class="dept-header" onclick="toggleDeptSection(this)">
                        <h3><?php echo htmlspecialchars($parent['dept']['department_name']); ?> (<?php echo htmlspecialchars($parent['dept']['department_type']); ?>)</h3>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="dept-content">
                        <div class="department-container">
                            <p><strong>Name Type:</strong> <?php echo htmlspecialchars($parent['dept']['name_type']); ?></p>
                            <p><strong>Folder Path:</strong> <?php echo htmlspecialchars($parent['dept']['folder_path'] ?? 'N/A'); ?></p>
                            <div class="action-buttons">
                                <button class="secondary-btn" onclick="openModal('edit', <?php echo htmlspecialchars(json_encode($parent['dept'])); ?>)"><i class="fas fa-edit"></i> Edit</button>
                                <form action="department_management.php" method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                                    <input type="hidden" name="action" value="delete_department">
                                    <input type="hidden" name="department_id" value="<?php echo htmlspecialchars($parent['dept']['department_id']); ?>">
                                    <button type="submit" class="secondary-btn danger-btn"><i class="fas fa-trash"></i> Delete</button>
                                </form>
                            </div>
                        </div>
                        <?php if (!empty($parent['sub_departments'])): ?>
                            <h4>Sub-Departments</h4>
                            <?php foreach ($parent['sub_departments'] as $subdept): ?>
                                <div class="subdept-item">
                                    <div>
                                        <strong><?php echo htmlspecialchars($subdept['department_name']); ?></strong>
                                        <p>Name Type: <?php echo htmlspecialchars($subdept['name_type']); ?></p>
                                        <p>Folder Path: <?php echo htmlspecialchars($subdept['folder_path'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="action-buttons">
                                        <button class="secondary-btn" onclick="openModal('edit', <?php echo htmlspecialchars(json_encode($subdept)); ?>)"><i class="fas fa-edit"></i> Edit</button>
                                        <form action="department_management.php" method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                                            <input type="hidden" name="action" value="delete_department">
                                            <input type="hidden" name="department_id" value="<?php echo htmlspecialchars($subdept['department_id']); ?>">
                                            <button type="submit" class="secondary-btn danger-btn"><i class="fas fa-trash"></i> Delete</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="department-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal-content">
            <h2 id="modal-title">Add Department</h2>
            <form id="department-form" class="modal-form" action="department_management.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                <input type="hidden" name="action" id="form-action" value="add">
                <input type="hidden" name="department_id" id="department_id">
                <div id="form-content">
                    <div class="form-group">
                        <label for="department_name">Department Name</label>
                        <input type="text" id="department_name" name="department_name" required aria-required="true">
                    </div>
                    <div class="form-group">
                        <label for="department_type">Department Type</label>
                        <select id="department_type" name="department_type" required aria-required="true">
                            <option value="college">College</option>
                            <option value="office">Office</option>
                            <option value="sub_department">Sub-Department</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="name_type">Name Type</label>
                        <select id="name_type" name="name_type" required aria-required="true">
                            <option value="Academic">Academic</option>
                            <option value="Administrative">Administrative</option>
                            <option value="Program">Program</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="parent_department_id">Parent Department (Optional)</label>
                        <select id="parent_department_id" name="parent_department_id">
                            <option value="">None</option>
                            <?php foreach ($parentDepartments as $parent): ?>
                                <option value="<?php echo $parent['department_id']; ?>">
                                    <?php echo sanitizeHTML($parent['department_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-buttons">
                    <button type="submit" id="form-submit" class="primary-btn">Add Department</button>
                    <button type="button" class="cancel-btn" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const notyf = new Noty({
            timeout: 3000,
            theme: 'metroui'
        });

        <?php if (!empty($successMessage)): ?>
            notyf.success('<?php echo addslashes($successMessage); ?>');
        <?php endif; ?>
        <?php if (!empty($errorMessage)): ?>
            notyf.error('<?php echo addslashes($errorMessage); ?>');
        <?php endif; ?>

        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const appContainer = document.querySelector('.app-container');
            const topNav = document.querySelector('.top-nav');
            sidebar.classList.toggle('minimized');
            appContainer.classList.toggle('sidebar-minimized');
            topNav.classList.toggle('sidebar-minimized');
        }

        function toggleDeptSection(header) {
            const content = header.nextElementSibling;
            content.classList.toggle('active');
            const icon = header.querySelector('i');
            icon.classList.toggle('fa-chevron-down');
            icon.classList.toggle('fa-chevron-up');
        }

        function openModal(action, data = {}) {
            const modal = document.getElementById('department-modal');
            const form = document.getElementById('department-form');
            const title = document.getElementById('modal-title');
            const formAction = document.getElementById('form-action');
            const formSubmit = document.getElementById('form-submit');

            formAction.value = action === 'add' ? 'add_department' : 'edit_department';
            title.textContent = action === 'add' ? 'Add Department' : 'Edit Department';
            document.getElementById('department_id').value = data.department_id || '';
            document.getElementById('department_name').value = data.department_name || '';
            document.getElementById('department_type').value = data.department_type || 'college';
            document.getElementById('name_type').value = data.name_type || 'Academic';
            document.getElementById('parent_department_id').value = data.parent_department_id || '';
            formSubmit.textContent = action === 'add' ? 'Add Department' : 'Update Department';
            modal.style.display = 'flex';

            // Focus trapping
            const focusableElements = modal.querySelectorAll('input, select, button');
            const firstFocusable = focusableElements[0];
            const lastFocusable = focusableElements[focusableElements.length - 1];
            firstFocusable.focus();

            modal.addEventListener('keydown', function trapFocus(event) {
                if (event.key === 'Tab') {
                    if (event.shiftKey) {
                        if (document.activeElement === firstFocusable) {
                            event.preventDefault();
                            lastFocusable.focus();
                        }
                    } else {
                        if (document.activeElement === lastFocusable) {
                            event.preventDefault();
                            firstFocusable.focus();
                        }
                    }
                }
            }, {
                once: true
            });
        }

        function closeModal() {
            const modal = document.getElementById('department-modal');
            modal.style.display = 'none';
        }

        function sanitizeHTML(str) {
            const div = document.createElement('div');
            div.textContent = str ?? '';
            return div.innerHTML;
        }

        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.querySelector('.sidebar');
            const appContainer = document.querySelector('.app-container');
            const topNav = document.querySelector('.top-nav');

            if (sidebar.classList.contains('minimized')) {
                appContainer.classList.add('sidebar-minimized');
                topNav.classList.add('sidebar-minimized');
            }

            window.addEventListener('click', (event) => {
                const modal = document.getElementById('department-modal');
                if (event.target === modal) {
                    closeModal();
                }
            });

            document.getElementById('department-modal').addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeModal();
                }
            });

            // Form validation
            document.getElementById('department-form').addEventListener('submit', (event) => {
                const deptName = document.getElementById('department_name').value.trim();
                if (!deptName) {
                    event.preventDefault();
                    notyf.error('Department name is required');
                }
            });
        });
    </script>
</body>

</html>