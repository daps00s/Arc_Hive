<?php
session_start();
require 'db_connection.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CSRF token handling
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

// Authentication check
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

// Database query execution
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

// Transaction logging
function logTransaction($pdo, $userId, $status, $type, $message)
{
    return executeQuery(
        $pdo,
        "INSERT INTO transactions (user_id, transaction_status, transaction_type, transaction_time, description)
         VALUES (?, ?, ?, NOW(), ?)",
        [$userId, $status, $type, $message]
    ) !== false;
}

// Folder creation
function createFolder($path)
{
    if (!file_exists($path)) {
        return mkdir($path, 0777, true);
    }
    return true;
}

// Build storage hierarchy
function buildTree($pdo, $departmentId, $subDepartmentId = null)
{
    $query = "
        SELECT sl.*, d.department_name, d2.department_name as sub_dept_name
        FROM storage_locations sl
        JOIN departments d ON sl.department_id = d.department_id
        LEFT JOIN departments d2 ON sl.sub_department_id = d2.department_id
        WHERE sl.department_id = ? " . ($subDepartmentId ? "AND sl.sub_department_id = ?" : "AND sl.sub_department_id IS NULL");

    $params = [$departmentId];
    if ($subDepartmentId) {
        $params[] = $subDepartmentId;
    }

    $stmt = executeQuery($pdo, $query, $params);
    $locations = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $tree = [];
    foreach ($locations as $location) {
        $location['children'] = [];
        $tree[$location['storage_location_id']] = $location;
    }

    foreach ($tree as $id => &$node) {
        if ($node['parent_storage_location_id']) {
            $tree[$node['parent_storage_location_id']]['children'][] = &$node;
        }
    }

    return array_filter($tree, fn($node) => is_null($node['parent_storage_location_id']));
}

// Get files by location
function getFilesByLocation($pdo, $locationId)
{
    $stmt = executeQuery(
        $pdo,
        "SELECT file_id, file_name FROM files WHERE storage_location_id = ?",
        [$locationId]
    );
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

// Get unit icon
function getIcon($type)
{
    return match ($type) {
        'room' => 'door-open',
        'cabinet' => 'archive',
        'layer' => 'layer-group',
        'box' => 'box-open',
        'folder' => 'folder',
        default => 'question',
    };
}

// Get next unit type
function getNextUnitType($currentType)
{
    $hierarchy = [
        'room' => 'cabinet',
        'cabinet' => 'layer',
        'layer' => 'box',
        'box' => 'folder',
        'folder' => null
    ];
    return $hierarchy[$currentType] ?? 'room';
}

// Handle form submissions
$error = '';
$success = '';
$baseDir = __DIR__ . '/';
$searchTerm = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?? '';

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && validateCsrfToken($_POST['csrf_token'])) {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);

    if ($action === 'add_unit') {
        $department_id = filter_var($_POST['department_id'], FILTER_VALIDATE_INT);
        $sub_department_id = filter_var($_POST['sub_department_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]);
        $parent_id = filter_var($_POST['parent_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]);
        $unit_type = filter_input(INPUT_POST, 'unit_type', FILTER_SANITIZE_STRING);
        $unit_name = trim(filter_input(INPUT_POST, 'unit_name', FILTER_SANITIZE_STRING));
        $folder_capacity = ($unit_type === 'folder') ? filter_var($_POST['folder_capacity'], FILTER_VALIDATE_INT) : 0;

        if (!$department_id || empty($unit_name) || !in_array($unit_type, ['room', 'cabinet', 'layer', 'box', 'folder'])) {
            $error = "All required fields must be filled correctly.";
            logTransaction($pdo, $userId, 'Failure', 'add_unit', $error);
        } elseif ($unit_type === 'folder' && $folder_capacity <= 0) {
            $error = "Folder capacity must be greater than 0.";
            logTransaction($pdo, $userId, 'Failure', 'add_unit', $error);
        } else {
            if ($parent_id) {
                $parentStmt = executeQuery(
                    $pdo,
                    "SELECT unit_type FROM storage_locations WHERE storage_location_id = ?",
                    [$parent_id]
                );
                if ($parentStmt) {
                    $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);
                    $expectedChildType = getNextUnitType($parent['unit_type']);
                    if ($unit_type !== $expectedChildType) {
                        $error = "A {$parent['unit_type']} can only contain {$expectedChildType} units.";
                        logTransaction($pdo, $userId, 'Failure', 'add_unit', $error);
                    }
                }
            }

            if (empty($error)) {
                $insertStmt = executeQuery(
                    $pdo,
                    "INSERT INTO storage_locations (department_id, sub_department_id, parent_storage_location_id, unit_name, unit_type, folder_capacity) 
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [$department_id, $sub_department_id, $parent_id, $unit_name, $unit_type, $folder_capacity]
                );

                if ($insertStmt) {
                    $newId = $pdo->lastInsertId();
                    $pathStmt = executeQuery(
                        $pdo,
                        "SELECT sl.full_path, d.folder_path 
                         FROM storage_locations sl 
                         JOIN departments d ON sl.department_id = d.department_id 
                         WHERE sl.storage_location_id = ?",
                        [$newId]
                    );

                    if ($pathStmt) {
                        $path = $pathStmt->fetch(PDO::FETCH_ASSOC);
                        if ($path) {
                            $folderPath = $path['folder_path'] . '/' . $path['full_path'];
                            if (createFolder($baseDir . $folderPath)) {
                                $success = "Storage unit added successfully.";
                                logTransaction($pdo, $userId, 'Success', 'add_unit', $success);
                                header("Location: physical_storage_management.php");
                                exit();
                            } else {
                                $error = "Failed to create folder structure.";
                                logTransaction($pdo, $userId, 'Failure', 'add_unit', $error);
                            }
                        }
                    }
                } else {
                    $error = "Failed to add storage unit.";
                    logTransaction($pdo, $userId, 'Failure', 'add_unit', $error);
                }
            }
        }
    } elseif ($action === 'edit_unit') {
        $location_id = filter_var($_POST['location_id'], FILTER_VALIDATE_INT);
        $folder_capacity = filter_var($_POST['folder_capacity'], FILTER_VALIDATE_INT);

        if (!$location_id || $folder_capacity <= 0) {
            $error = "Invalid location or capacity.";
            logTransaction($pdo, $userId, 'Failure', 'edit_unit', $error);
        } else {
            $updateStmt = executeQuery(
                $pdo,
                "UPDATE storage_locations SET folder_capacity = ? WHERE storage_location_id = ?",
                [$folder_capacity, $location_id]
            );

            if ($updateStmt) {
                $success = "Storage unit updated successfully.";
                logTransaction($pdo, $userId, 'Success', 'edit_unit', $success);
                header("Location: physical_storage_management.php");
                exit();
            } else {
                $error = "Failed to update storage unit.";
                logTransaction($pdo, $userId, 'Failure', 'edit_unit', $error);
            }
        }
    } elseif ($action === 'delete_unit') {
        $location_id = filter_var($_POST['location_id'], FILTER_VALIDATE_INT);
        if (!$location_id) {
            $error = "Invalid location ID.";
            logTransaction($pdo, $userId, 'Failure', 'delete_unit', $error);
        } else {
            $stmt = executeQuery(
                $pdo,
                "SELECT COUNT(*) as count FROM storage_locations WHERE parent_storage_location_id = ?",
                [$location_id]
            );
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            if ($count > 0) {
                $error = "Cannot delete unit with child locations.";
                logTransaction($pdo, $userId, 'Failure', 'delete_unit', $error);
            } else {
                $deleteStmt = executeQuery(
                    $pdo,
                    "DELETE FROM storage_locations WHERE storage_location_id = ?",
                    [$location_id]
                );

                if ($deleteStmt) {
                    $success = "Storage unit deleted successfully.";
                    logTransaction($pdo, $userId, 'Success', 'delete_unit', $success);
                    header("Location: physical_storage_management.php");
                    exit();
                } else {
                    $error = "Failed to delete storage unit.";
                    logTransaction($pdo, $userId, 'Failure', 'delete_unit', $error);
                }
            }
        }
    } elseif ($action === 'remove_file') {
        $file_id = filter_var($_POST['file_id'], FILTER_VALIDATE_INT);
        if (!$file_id) {
            $error = "Invalid file ID.";
            logTransaction($pdo, $userId, 'Failure', 'remove_file', $error);
        } else {
            $updateStmt = executeQuery(
                $pdo,
                "UPDATE files SET storage_location_id = NULL WHERE file_id = ?",
                [$file_id]
            );

            if ($updateStmt) {
                $success = "File removed from storage successfully.";
                logTransaction($pdo, $userId, 'Success', 'remove_file', $success);
                header("Location: physical_storage_management.php");
                exit();
            } else {
                $error = "Failed to remove file from storage.";
                logTransaction($pdo, $userId, 'Failure', 'remove_file', $error);
            }
        }
    }
}

// Fetch departments
$deptStmt = executeQuery($pdo, "SELECT * FROM departments WHERE department_type IN ('college', 'office')");
$departments = $deptStmt ? $deptStmt->fetchAll(PDO::FETCH_ASSOC) : [];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Physical Storage Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="style/Physical_Storage_Management.css">
    <link rel="stylesheet" href="style/admin-sidebar.css">
</head>

<body>
    <div class="app-container">

        <div class="sidebar">
            <!--         <button class="toggle-btn" title="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button> -->
            <h2 class="sidebar-title">Admin Panel</h2>
            <a href="dashboard.php" class="client-btn">
                <i class="fas fa-exchange-alt"></i>
                <span class="link-text">Switch to Client View</span>
            </a>
            <a href="admin_dashboard.php">
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
            <a href="physical_storage_management.php" class="active">
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

        <!-- Top Navigation -->
        <div class="top-nav">
            <h2>Physical Storage Management</h2>
            <input type="text" id="storage-search" placeholder="Search storage units or files..." value="<?php echo htmlspecialchars($searchTerm); ?>">
            <button class="primary-btn" onclick="performSearch()"><i class="fas fa-search"></i> Search</button>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <?php if ($success): ?>
                <div class="success-message"><?php echo htmlspecialchars($success); ?><span class="close-alert">&times;</span></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?><span class="close-alert">&times;</span></div>
            <?php endif; ?>

            <div class="controls-section">
                <button class="primary-btn" onclick="openModal('add', {dept_id: '<?php echo $departments[0]['department_id'] ?? ''; ?>'})">
                    <i class="fas fa-plus"></i> Add New Storage Unit
                </button>
            </div>

            <?php foreach ($departments as $dept): ?>
                <div class="department-section">
                    <div class="department-header" onclick="toggleSection(this)">
                        <i class="fas fa-building dept-icon"></i>
                        <h3><?php echo htmlspecialchars($dept['department_name']); ?></h3>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </div>
                    <div class="dropdown-content">
                        <?php
                        $subDepts = executeQuery(
                            $pdo,
                            "SELECT * FROM departments WHERE parent_department_id = ?",
                            [$dept['department_id']]
                        );
                        $subDepts = $subDepts ? $subDepts->fetchAll(PDO::FETCH_ASSOC) : [];
                        ?>
                        <div class="tab-container">
                            <div class="tab-header">
                                <button class="tab-btn active" data-tab="dept-<?php echo $dept['department_id']; ?>">Main Department</button>
                                <?php foreach ($subDepts as $subDept): ?>
                                    <button class="tab-btn" data-tab="subdept-<?php echo $subDept['department_id']; ?>">
                                        <?php echo htmlspecialchars($subDept['department_name']); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <div class="tab-content active" id="dept-<?php echo $dept['department_id']; ?>">
                                <?php
                                $tree = buildTree($pdo, $dept['department_id']);
                                $filesByLocation = [];
                                foreach ($tree as $node) {
                                    if ($node['unit_type'] === 'folder') {
                                        $filesByLocation[$node['storage_location_id']] = getFilesByLocation($pdo, $node['storage_location_id']);
                                    }
                                }
                                renderStorageTree($tree, $filesByLocation);
                                ?>
                            </div>
                            <?php foreach ($subDepts as $subDept): ?>
                                <div class="tab-content" id="subdept-<?php echo $subDept['department_id']; ?>">
                                    <?php
                                    $tree = buildTree($pdo, $dept['department_id'], $subDept['department_id']);
                                    $filesByLocation = [];
                                    foreach ($tree as $node) {
                                        if ($node['unit_type'] === 'folder') {
                                            $filesByLocation[$node['storage_location_id']] = getFilesByLocation($pdo, $node['storage_location_id']);
                                        }
                                    }
                                    renderStorageTree($tree, $filesByLocation);
                                    ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Add/Edit Modal -->
        <div class="modal" id="storage-modal">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2 id="modal-title">Add Storage Unit</h2>
                <form id="storage-form" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="add_unit">
                    <div id="form-content"></div>
                    <div class="modal-buttons">
                        <button type="submit" class="primary-btn">Save</button>
                        <button type="button" class="cancel-btn" onclick="closeModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div class="modal" id="warning-delete-unit-modal">
            <div class="warning-modal-content">
                <span class="close">&times;</span>
                <h2>Confirm Deletion</h2>
                <p>Are you sure you want to delete <span id="delete-unit-name"></span>?</p>
                <div class="buttons">
                    <button class="primary-btn confirm-btn" id="confirm-delete-unit">Delete</button>
                    <button class="cancel-btn" onclick="closeModal()">Cancel</button>
                </div>
            </div>
        </div>

        <!-- Remove File Confirmation Modal -->
        <div class="modal" id="warning-remove-file-modal">
            <div class="warning-modal-content">
                <span class="close">&times;</span>
                <h2>Confirm Removal</h2>
                <p>Are you sure you want to remove <span id="remove-file-name"></span> from storage?</p>
                <div class="buttons">
                    <button class="primary-btn confirm-btn" id="confirm-remove-file">Remove</button>
                    <button class="cancel-btn" onclick="closeModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleSection(element) {
            const content = element.nextElementSibling;
            const icon = element.querySelector('.toggle-icon');
            content.style.display = content.style.display === 'block' ? 'none' : 'block';
            icon.classList.toggle('fa-chevron-down');
            icon.classList.toggle('fa-chevron-up');
        }

        function toggleStorageNode(element) {
            const content = element.nextElementSibling;
            const icon = element.querySelector('.toggle-icon');
            content.style.display = content.style.display === 'block' ? 'none' : 'block';
            icon.classList.toggle('fa-chevron-down');
            icon.classList.toggle('fa-chevron-up');
        }

        function closeModal() {
            document.querySelectorAll('.modal').forEach(modal => modal.style.display = 'none');
        }

        function openModal(action, data) {
            const modal = document.getElementById('storage-modal');
            const title = document.getElementById('modal-title');
            const form = document.getElementById('storage-form');
            const formContent = document.getElementById('form-content');
            const submitBtn = form.querySelector('.primary-btn');

            modal.style.display = 'flex';
            form.action = '';
            formContent.innerHTML = '';

            if (action === 'add') {
                title.textContent = 'Add Storage Unit';
                submitBtn.textContent = 'Add';
                form.querySelector('input[name="action"]').value = 'add_unit';
                const nextUnitType = data.parent_type ? getNextUnitType(data.parent_type) : 'room';
                formContent.innerHTML = `
                    <div class="form-group">
                        <label for="department_id">Department:</label>
                        <select name="department_id" required>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['department_id']; ?>" ${data.dept_id == '<?php echo $dept['department_id']; ?>' ? 'selected' : ''}>
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="sub_department_id">Sub-Department (Optional):</label>
                        <select name="sub_department_id">
                            <option value="">None</option>
                            <?php foreach ($subDepts as $subDept): ?>
                                <option value="<?php echo $subDept['department_id']; ?>" ${data.sub_dept_id == '<?php echo $subDept['department_id']; ?>' ? 'selected' : ''}>
                                    <?php echo htmlspecialchars($subDept['department_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" name="parent_id" value="${data.parent_id || ''}">
                    <div class="form-group">
                        <label for="unit_type">Unit Type:</label>
                        <select name="unit_type" id="unit_type" onchange="toggleCapacity()" ${data.parent_type ? 'disabled' : ''}>
                            <option value="room" ${nextUnitType === 'room' ? 'selected' : ''}>Room</option>
                            <option value="cabinet" ${nextUnitType === 'cabinet' ? 'selected' : ''}>Cabinet</option>
                            <option value="layer" ${nextUnitType === 'layer' ? 'selected' : ''}>Layer</option>
                            <option value="box" ${nextUnitType === 'box' ? 'selected' : ''}>Box</option>
                            <option value="folder" ${nextUnitType === 'folder' ? 'selected' : ''}>Folder</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="unit_name">Unit Name:</label>
                        <input type="text" name="unit_name" required>
                    </div>
                    <div class="form-group" id="capacity-group" style="display:${nextUnitType === 'folder' ? 'block' : 'none'};">
                        <label for="folder_capacity">Folder Capacity:</label>
                        <input type="number" name="folder_capacity" min="1" value="10">
                    </div>
                `;
            } else if (action === 'edit') {
                title.textContent = 'Edit Folder Capacity';
                submitBtn.textContent = 'Update';
                form.querySelector('input[name="action"]').value = 'edit_unit';
                formContent.innerHTML = `
                    <input type="hidden" name="location_id" value="${data.storage_location_id}">
                    <div class="form-group">
                        <label>Unit: ${data.unit_name} (${data.unit_type})</label>
                    </div>
                    <div class="form-group">
                        <label for="folder_capacity">Capacity:</label>
                        <input type="number" name="folder_capacity" min="1" value="${data.folder_capacity}" required>
                    </div>
                `;
            }
        }

        function getNextUnitType(currentType) {
            const hierarchy = {
                'room': 'cabinet',
                'cabinet': 'layer',
                'layer': 'box',
                'box': 'folder',
                'folder': null
            };
            return hierarchy[currentType] || 'room';
        }

        function toggleCapacity() {
            const unitType = document.getElementById('unit_type').value;
            const capacityGroup = document.getElementById('capacity-group');
            capacityGroup.style.display = unitType === 'folder' ? 'block' : 'none';
        }

        function confirmRemoveFile(fileId, fileName) {
            document.getElementById('remove-file-name').textContent = fileName;
            const modal = document.getElementById('warning-remove-file-modal');
            modal.style.display = 'flex';

            document.getElementById('confirm-remove-file').onclick = function() {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="remove_file">
                    <input type="hidden" name="file_id" value="${fileId}">
                `;
                document.body.appendChild(form);
                form.submit();
            };
        }

        function performSearch() {
            const searchTerm = document.getElementById('storage-search').value.trim();
            window.location.href = `physical_storage_management.php?search=${encodeURIComponent(searchTerm)}`;
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const tabContainer = this.closest('.tab-container');
                    const tabId = this.dataset.tab;
                    tabContainer.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    tabContainer.querySelectorAll('.tab-content').forEach(content => {
                        content.style.display = content.id.includes(tabId) ? 'block' : 'none';
                    });
                });
            });

            // Auto-expand sections with search results
            const searchTerm = "<?php echo $searchTerm; ?>";
            if (searchTerm) {
                const searchLower = searchTerm.toLowerCase();
                document.querySelectorAll('.storage-node, .file-name').forEach(node => {
                    if (node.textContent.toLowerCase().includes(searchLower)) {
                        let current = node;
                        while (current && current !== document) {
                            if (current.classList.contains('node-header')) {
                                toggleStorageNode(current);
                            } else if (current.classList.contains('department-header') || current.classList.contains('sub-header')) {
                                toggleSection(current);
                            } else if (current.classList.contains('tab-content')) {
                                const tabContainer = current.closest('.tab-container');
                                const tabId = current.id.split('-')[0];
                                tabContainer.querySelector(`.tab-btn[data-tab="${tabId}"]`).click();
                            }
                            current = current.parentElement;
                        }
                        node.style.backgroundColor = '#fff3cd';
                    }
                });
            }

            // Close modals
            document.querySelectorAll('.close').forEach(close => {
                close.addEventListener('click', closeModal);
            });

            // Close alerts
            document.querySelectorAll('.close-alert').forEach(btn => {
                btn.addEventListener('click', () => btn.parentElement.style.display = 'none');
            });

            // Toggle sidebar
            document.querySelector('.toggle-btn').addEventListener('click', () => {
                document.querySelector('.app-container').classList.toggle('sidebar-minimized');
                document.querySelector('.sidebar').classList.toggle('minimized');
            });
        });
    </script>
</body>

</html>

<?php
function renderStorageTree(array $tree, array $filesByLocation, int $depth = 0, string $parentType = ''): void
{
    foreach ($tree as $node) {
        $fileCount = isset($filesByLocation[$node['storage_location_id']]) ? count($filesByLocation[$node['storage_location_id']]) : 0;
        $hasChildren = !empty($node['children']);
        $icon = getIcon($node['unit_type']);
        $breadcrumbs = [];
        $current = $node;
        while ($current) {
            $breadcrumbs[] = $current['unit_name'];
            if ($current['parent_storage_location_id']) {
                $stmt = executeQuery($GLOBALS['pdo'], "SELECT * FROM storage_locations WHERE storage_location_id = ?", [$current['parent_storage_location_id']]);
                $current = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            } else {
                $current = null;
            }
        }
        $breadcrumbs = array_reverse($breadcrumbs);
        $breadcrumbs = array_slice($breadcrumbs, -3); // Limit to last 3 for brevity

        echo '<div class="storage-node" data-id="' . $node['storage_location_id'] . '" data-type="' . $node['unit_type'] . '" data-depth="' . $depth . '">';
        echo '<div class="node-header" onclick="toggleStorageNode(this)">';
        echo '<div class="node-icon"><i class="fas fa-' . $icon . '"></i></div>';
        echo '<div class="node-info">';
        echo '<h4>' . htmlspecialchars($node['unit_name']) . '</h4>';
        echo '<div class="breadcrumbs">' . implode(' > ', array_map('htmlspecialchars', $breadcrumbs)) . '</div>';
        echo '<span class="node-type">' . ucfirst($node['unit_type']) . '</span>';
        if ($node['unit_type'] === 'folder') {
            echo '<span class="capacity">' . $fileCount . ' / ' . htmlspecialchars($node['folder_capacity']) . ' files</span>';
        }
        echo '</div>';
        echo '<div class="node-actions">';
        echo '<i class="fas fa-chevron-down toggle-icon"></i>';
        echo '</div>';
        echo '</div>';

        echo '<div class="node-content" style="display: none;">';
        echo '<div class="action-buttons">';
        echo '<button class="primary-btn" onclick="openModal(\'add\', {parent_id: \'' . $node['storage_location_id'] . '\', dept_id: \'' . $node['department_id'] . '\', sub_dept_id: \'' . ($node['sub_department_id'] ?? '') . '\', parent_type: \'' . $node['unit_type'] . '\'})">';
        echo '<i class="fas fa-plus"></i> Add Child Unit';
        echo '</button>';

        if ($node['unit_type'] === 'folder') {
            echo '<button class="secondary-btn edit-btn" onclick="openModal(\'edit\', ' . htmlspecialchars(json_encode($node)) . ')">';
            echo '<i class="fas fa-edit"></i> Edit Capacity';
            echo '</button>';
        }

        echo '<button class="secondary-btn delete-btn" onclick="openModal(\'delete\', {location_id: \'' . $node['storage_location_id'] . '\', unit_name: \'' . htmlspecialchars($node['unit_name']) . '\'})">';
        echo '<i class="fas fa-trash"></i> Delete';
        echo '</button>';
        echo '</div>';

        if ($node['unit_type'] === 'folder' && $fileCount > 0) {
            echo '<div class="file-list">';
            echo '<h5>Files in this folder:</h5>';
            echo '<div class="file-masonry">';
            foreach ($filesByLocation[$node['storage_location_id']] as $file) {
                echo '<div class="file-item">';
                echo '<div class="file-icon"><i class="fas fa-file"></i></div>';
                echo '<div class="file-name">' . htmlspecialchars($file['file_name']) . '</div>';
                echo '<button class="btn-danger remove-btn" onclick="confirmRemoveFile(' . $file['file_id'] . ', \'' . htmlspecialchars($file['file_name']) . '\')">';
                echo '<i class="fas fa-times"></i> Remove';
                echo '</button>';
                echo '</div>';
            }
            echo '</div>';
            echo '</div>';
        }

        if ($hasChildren) {
            $childFiles = [];
            foreach ($node['children'] as $child) {
                if ($child['unit_type'] === 'folder') {
                    $childFiles[$child['storage_location_id']] = getFilesByLocation($GLOBALS['pdo'], $child['storage_location_id']);
                }
            }
            renderStorageTree($node['children'], $childFiles, $depth + 1, $node['unit_type']);
        }

        echo '</div>';
        echo '</div>';
    }
}
?>