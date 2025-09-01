<?php

declare(strict_types=1);
session_start();
require 'db_connection.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Content-Security-Policy: default-src \'self\'; style-src \'self\' \'unsafe-inline\'; script-src \'self\' \'unsafe-inline\' https://cdnjs.cloudflare.com');

// CSRF token handling
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

// Authentication check
if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
if ($userId === false) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Database operations
class StorageManager
{
    private PDO $pdo;
    private int $userId;
    private string $baseDir;

    public function __construct(PDO $pdo, int $userId, string $baseDir)
    {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->baseDir = $baseDir;
    }

    public function executeQuery(string $query, array $params = []): PDOStatement|false
    {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage());
            return false;
        }
    }

    public function logTransaction(string $status, string $type, string $message): bool
    {
        return $this->executeQuery(
            "INSERT INTO transactions (user_id, transaction_status, transaction_type, transaction_time, description)
             VALUES (?, ?, ?, NOW(), ?)",
            [$this->userId, $status, $type, $message]
        ) !== false;
    }

    public function createFolder(string $path): bool
    {
        if (!file_exists($path)) {
            return mkdir($path, 0777, true);
        }
        return true;
    }

    public function buildTree(int $departmentId, ?int $subDepartmentId = null): array
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

        $stmt = $this->executeQuery($query, $params);
        $locations = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $tree = [];
        foreach ($locations as $location) {
            $location['children'] = [];
            $tree[$location['storage_location_id']] = $location;
        }

        foreach ($tree as $id => &$node) {
            if ($node['parent_storage_location_id']) {
                if (isset($tree[$node['parent_storage_location_id']])) {
                    $tree[$node['parent_storage_location_id']]['children'][] = &$node;
                }
            }
        }

        return array_filter($tree, fn($node) => is_null($node['parent_storage_location_id']));
    }

    public function getFilesByLocation(int $locationId): array
    {
        $stmt = $this->executeQuery(
            "SELECT file_id, file_name FROM files WHERE storage_location_id = ?",
            [$locationId]
        );
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function getNextUnitType(string $currentType): ?string
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

    public function validateUnitType(string $parentType, string $childType): bool
    {
        return $this->getNextUnitType($parentType) === $childType;
    }

    public function addStorageUnit(array $data): array
    {
        $department_id = filter_var($data['department_id'], FILTER_VALIDATE_INT);
        $sub_department_id = filter_var($data['sub_department_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]);
        $parent_id = filter_var($data['parent_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]);
        $unit_type = filter_var($data['unit_type'], FILTER_SANITIZE_STRING);
        $unit_name = trim(filter_var($data['unit_name'], FILTER_SANITIZE_STRING));
        $folder_capacity = ($unit_type === 'folder') ? filter_var($data['folder_capacity'], FILTER_VALIDATE_INT) : 0;

        if (!$department_id || empty($unit_name) || !in_array($unit_type, ['room', 'cabinet', 'layer', 'box', 'folder'])) {
            $this->logTransaction('Failure', 'add_unit', 'Invalid input data.');
            return ['success' => false, 'message' => 'All required fields must be filled correctly.'];
        }

        if ($unit_type === 'folder' && $folder_capacity <= 0) {
            $this->logTransaction('Failure', 'add_unit', 'Invalid folder capacity.');
            return ['success' => false, 'message' => 'Folder capacity must be greater than 0.'];
        }

        if ($parent_id) {
            $parentStmt = $this->executeQuery(
                "SELECT unit_type FROM storage_locations WHERE storage_location_id = ?",
                [$parent_id]
            );
            if ($parentStmt) {
                $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$parent) {
                    $this->logTransaction('Failure', 'add_unit', 'Parent storage location not found.');
                    return ['success' => false, 'message' => 'Parent storage location not found.'];
                }
                if (!$this->validateUnitType($parent['unit_type'], $unit_type)) {
                    $this->logTransaction('Failure', 'add_unit', "Invalid child unit type for parent {$parent['unit_type']}.");
                    return ['success' => false, 'message' => "A {$parent['unit_type']} can only contain {$this->getNextUnitType($parent['unit_type'])} units."];
                }
            } else {
                $this->logTransaction('Failure', 'add_unit', 'Failed to fetch parent storage location.');
                return ['success' => false, 'message' => 'Failed to fetch parent storage location.'];
            }
        }

        try {
            $this->pdo->beginTransaction();

            $full_path = $this->buildFullPath($parent_id, $unit_name);
            $insertStmt = $this->executeQuery(
                "INSERT INTO storage_locations (department_id, sub_department_id, parent_storage_location_id, unit_name, unit_type, folder_capacity, full_path)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$department_id, $sub_department_id, $parent_id, $unit_name, $unit_type, $folder_capacity, $full_path]
            );

            if ($insertStmt) {
                $newId = (int)$this->pdo->lastInsertId();
                $pathStmt = $this->executeQuery(
                    "SELECT sl.full_path, d.folder_path 
                     FROM storage_locations sl 
                     JOIN departments d ON sl.department_id = d.department_id 
                     WHERE sl.storage_location_id = ?",
                    [$newId]
                );

                if ($pathStmt) {
                    $path = $pathStmt->fetch(PDO::FETCH_ASSOC);
                    if ($path && $this->createFolder($this->baseDir . $path['folder_path'] . '/' . $path['full_path'])) {
                        $this->pdo->commit();
                        $this->logTransaction('Success', 'add_unit', "Storage unit {$unit_name} added successfully.");
                        return ['success' => true, 'message' => 'Storage unit added successfully.', 'id' => $newId];
                    }
                }
                $this->pdo->rollBack();
                $this->logTransaction('Failure', 'add_unit', 'Failed to create folder structure.');
                return ['success' => false, 'message' => 'Failed to create folder structure.'];
            }
            $this->pdo->rollBack();
            $this->logTransaction('Failure', 'add_unit', 'Failed to insert storage unit.');
            return ['success' => false, 'message' => 'Failed to add storage unit.'];
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->logTransaction('Failure', 'add_unit', "Database error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    public function addStorageHierarchy(array $data): array
    {
        try {
            $this->pdo->beginTransaction();
            $parentId = null;
            $hierarchy = [
                'room' => $data['room_name'] ?? null,
                'cabinet' => $data['cabinet_name'] ?? null,
                'layer' => $data['layer_name'] ?? null,
                'box' => $data['box_name'] ?? null,
                'folder' => $data['folder_name'] ?? null
            ];

            $department_id = filter_var($data['department_id'], FILTER_VALIDATE_INT);
            $sub_department_id = filter_var($data['sub_department_id'], FILTER_VALIDATE_INT, ['options' => ['default' => null]]);
            $folder_capacity = filter_var($data['folder_capacity'], FILTER_VALIDATE_INT, ['options' => ['default' => 10]]);

            $anyUnitAdded = false;
            foreach ($hierarchy as $unit_type => $unit_name) {
                if (empty($unit_name)) {
                    continue;
                }
                $anyUnitAdded = true;

                $unitData = [
                    'department_id' => $department_id,
                    'sub_department_id' => $sub_department_id,
                    'parent_id' => $parentId,
                    'unit_type' => $unit_type,
                    'unit_name' => trim(filter_var($unit_name, FILTER_SANITIZE_STRING)),
                    'folder_capacity' => $unit_type === 'folder' ? $folder_capacity : 0
                ];

                $result = $this->addStorageUnit($unitData);
                if (!$result['success']) {
                    $this->pdo->rollBack();
                    return $result;
                }
                $parentId = $result['id'];
            }

            if (!$anyUnitAdded) {
                $this->pdo->rollBack();
                $this->logTransaction('Failure', 'add_hierarchy', 'No valid unit names provided.');
                return ['success' => false, 'message' => 'At least one unit name must be provided.'];
            }

            $this->pdo->commit();
            $this->logTransaction('Success', 'add_hierarchy', 'Storage hierarchy added successfully.');
            return ['success' => true, 'message' => 'Storage hierarchy added successfully.'];
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->logTransaction('Failure', 'add_hierarchy', "Database error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    public function updateStorageUnit(int $locationId, int $folderCapacity): array
    {
        if ($locationId <= 0 || $folderCapacity <= 0) {
            $this->logTransaction('Failure', 'edit_unit', 'Invalid location or capacity.');
            return ['success' => false, 'message' => 'Invalid location or capacity.'];
        }

        try {
            $this->pdo->beginTransaction();
            $updateStmt = $this->executeQuery(
                "UPDATE storage_locations SET folder_capacity = ? WHERE storage_location_id = ?",
                [$folderCapacity, $locationId]
            );

            if ($updateStmt) {
                $this->pdo->commit();
                $this->logTransaction('Success', 'edit_unit', "Storage unit {$locationId} updated successfully.");
                return ['success' => true, 'message' => 'Storage unit updated successfully.'];
            }

            $this->pdo->rollBack();
            $this->logTransaction('Failure', 'edit_unit', "Failed to update storage unit {$locationId}.");
            return ['success' => false, 'message' => 'Failed to update storage unit.'];
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->logTransaction('Failure', 'edit_unit', "Database error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    public function deleteStorageUnit(int $locationId): array
    {
        if ($locationId <= 0) {
            $this->logTransaction('Failure', 'delete_unit', 'Invalid location ID.');
            return ['success' => false, 'message' => 'Invalid location ID.'];
        }

        try {
            $this->pdo->beginTransaction();
            $stmt = $this->executeQuery(
                "SELECT COUNT(*) as count FROM storage_locations WHERE parent_storage_location_id = ?",
                [$locationId]
            );
            $count = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC)['count'] : 0;

            if ($count > 0) {
                $this->pdo->rollBack();
                $this->logTransaction('Failure', 'delete_unit', 'Cannot delete unit with child locations.');
                return ['success' => false, 'message' => 'Cannot delete unit with child locations.'];
            }

            $fileStmt = $this->executeQuery(
                "SELECT COUNT(*) as count FROM files WHERE storage_location_id = ?",
                [$locationId]
            );
            $fileCount = $fileStmt ? $fileStmt->fetch(PDO::FETCH_ASSOC)['count'] : 0;

            if ($fileCount > 0) {
                $this->pdo->rollBack();
                $this->logTransaction('Failure', 'delete_unit', 'Cannot delete unit with associated files.');
                return ['success' => false, 'message' => 'Cannot delete unit with associated files.'];
            }

            $deleteStmt = $this->executeQuery(
                "DELETE FROM storage_locations WHERE storage_location_id = ?",
                [$locationId]
            );

            if ($deleteStmt) {
                $this->pdo->commit();
                $this->logTransaction('Success', 'delete_unit', "Storage unit {$locationId} deleted successfully.");
                return ['success' => true, 'message' => 'Storage unit deleted successfully.'];
            }

            $this->pdo->rollBack();
            $this->logTransaction('Failure', 'delete_unit', "Failed to delete storage unit {$locationId}.");
            return ['success' => false, 'message' => 'Failed to delete storage unit.'];
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->logTransaction('Failure', 'delete_unit', "Database error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    public function removeFile(int $fileId): array
    {
        if ($fileId <= 0) {
            $this->logTransaction('Failure', 'remove_file', 'Invalid file ID.');
            return ['success' => false, 'message' => 'Invalid file ID.'];
        }

        try {
            $this->pdo->beginTransaction();
            $updateStmt = $this->executeQuery(
                "UPDATE files SET storage_location_id = NULL WHERE file_id = ?",
                [$fileId]
            );

            if ($updateStmt) {
                $this->pdo->commit();
                $this->logTransaction('Success', 'remove_file', "File {$fileId} removed from storage successfully.");
                return ['success' => true, 'message' => 'File removed from storage successfully.'];
            }

            $this->pdo->rollBack();
            $this->logTransaction('Failure', 'remove_file', "Failed to remove file {$fileId} from storage.");
            return ['success' => false, 'message' => 'Failed to remove file from storage.'];
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->logTransaction('Failure', 'remove_file', "Database error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    private function buildFullPath(?int $parentId, string $unitName): string
    {
        if (!$parentId) {
            return $unitName;
        }

        $stmt = $this->executeQuery(
            "SELECT full_path FROM storage_locations WHERE storage_location_id = ?",
            [$parentId]
        );
        $parent = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        return $parent ? $parent['full_path'] . '/' . $unitName : $unitName;
    }
}

$storageManager = new StorageManager($pdo, $userId, __DIR__ . '/');
$error = '';
$success = '';
$searchTerm = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?? '';

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);

    switch ($action) {
        case 'add_unit':
            $result = $storageManager->addStorageUnit($_POST);
            if ($result['success']) {
                $success = $result['message'];
                header("Location: physical_storage_management.php?success=" . urlencode($success));
                exit;
            }
            $error = $result['message'];
            break;

        case 'add_hierarchy':
            $result = $storageManager->addStorageHierarchy($_POST);
            if ($result['success']) {
                $success = $result['message'];
                header("Location: physical_storage_management.php?success=" . urlencode($success));
                exit;
            }
            $error = $result['message'];
            break;

        case 'edit_unit':
            $result = $storageManager->updateStorageUnit(
                filter_var($_POST['location_id'], FILTER_VALIDATE_INT),
                filter_var($_POST['folder_capacity'], FILTER_VALIDATE_INT)
            );
            if ($result['success']) {
                $success = $result['message'];
                header("Location: physical_storage_management.php?success=" . urlencode($success));
                exit;
            }
            $error = $result['message'];
            break;

        case 'delete_unit':
            $result = $storageManager->deleteStorageUnit(
                filter_var($_POST['location_id'], FILTER_VALIDATE_INT)
            );
            if ($result['success']) {
                $success = $result['message'];
                header("Location: physical_storage_management.php?success=" . urlencode($success));
                exit;
            }
            $error = $result['message'];
            break;

        case 'remove_file':
            $result = $storageManager->removeFile(
                filter_var($_POST['file_id'], FILTER_VALIDATE_INT)
            );
            if ($result['success']) {
                $success = $result['message'];
                header("Location: physical_storage_management.php?success=" . urlencode($success));
                exit;
            }
            $error = $result['message'];
            break;

        default:
            $error = 'Invalid action specified.';
            $storageManager->logTransaction('Failure', 'unknown_action', 'Invalid action: ' . $action);
            break;
    }
}

// Fetch departments and sub-departments
$deptStmt = $storageManager->executeQuery("SELECT * FROM departments WHERE department_type IN ('college', 'office')");
$departments = $deptStmt ? $deptStmt->fetchAll(PDO::FETCH_ASSOC) : [];
$subDeptsByDept = [];
foreach ($departments as $dept) {
    $subStmt = $storageManager->executeQuery(
        "SELECT * FROM departments WHERE parent_department_id = ?",
        [$dept['department_id']]
    );
    $subDeptsByDept[$dept['department_id']] = $subStmt ? $subStmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function getIcon(string $type): string
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
            </a>
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                <span class="link-text">Logout</span>
            </a>
        </div>

        <div class="top-nav">
            <h2>Physical Storage Management</h2>
            <input type="text" id="storage-search" placeholder="Search storage units or files..." value="<?php echo htmlspecialchars($searchTerm); ?>">
            <button class="primary-btn" onclick="performSearch()"><i class="fas fa-search"></i> Search</button>
        </div>

        <div class="main-content">
            <?php if ($success || ($success = filter_input(INPUT_GET, 'success', FILTER_SANITIZE_STRING))): ?>
                <div class="success-message"><?php echo htmlspecialchars($success); ?><span class="close-alert">&times;</span></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?><span class="close-alert">&times;</span></div>
            <?php endif; ?>

            <div class="controls-section">
                <button class="primary-btn" onclick="openModal('add', {dept_id: '<?php echo $departments[0]['department_id'] ?? ''; ?>'})">
                    <i class="fas fa-plus"></i> Add Storage Unit
                </button>
                <button class="primary-btn" onclick="openModal('add_hierarchy', {dept_id: '<?php echo $departments[0]['department_id'] ?? ''; ?>'})">
                    <i class="fas fa-sitemap"></i> Add Storage Hierarchy
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
                        <div class="tab-container">
                            <div class="tab-header">
                                <button class="tab-btn active" data-tab="dept-<?php echo $dept['department_id']; ?>">Main Department</button>
                                <?php foreach ($subDeptsByDept[$dept['department_id']] as $subDept): ?>
                                    <button class="tab-btn" data-tab="subdept-<?php echo $subDept['department_id']; ?>">
                                        <?php echo htmlspecialchars($subDept['department_name']); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <div class="tab-content active" id="dept-<?php echo $dept['department_id']; ?>">
                                <?php
                                $tree = $storageManager->buildTree($dept['department_id']);
                                $filesByLocation = [];
                                foreach ($tree as $node) {
                                    if ($node['unit_type'] === 'folder') {
                                        $filesByLocation[$node['storage_location_id']] = $storageManager->getFilesByLocation($node['storage_location_id']);
                                    }
                                }
                                renderStorageTree($tree, $filesByLocation, $storageManager);
                                ?>
                            </div>
                            <?php foreach ($subDeptsByDept[$dept['department_id']] as $subDept): ?>
                                <div class="tab-content" id="subdept-<?php echo $subDept['department_id']; ?>">
                                    <?php
                                    $tree = $storageManager->buildTree($dept['department_id'], $subDept['department_id']);
                                    $filesByLocation = [];
                                    foreach ($tree as $node) {
                                        if ($node['unit_type'] === 'folder') {
                                            $filesByLocation[$node['storage_location_id']] = $storageManager->getFilesByLocation($node['storage_location_id']);
                                        }
                                    }
                                    renderStorageTree($tree, $filesByLocation, $storageManager);
                                    ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="modal" id="storage-modal">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2 id="modal-title">Add Storage Unit</h2>
                <form id="storage-form" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                    <input type="hidden" name="action" id="form-action" value="add_unit">
                    <div id="form-content"></div>
                    <div class="modal-buttons">
                        <button type="submit" class="primary-btn">Save</button>
                        <button type="button" class="cancel-btn" onclick="closeModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

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
        // Debug function to log errors to console
        function logError(message, error = null) {
            console.error(`[Storage Management Error]: ${message}`, error);
        }

        function toggleSection(element) {
            try {
                const content = element.nextElementSibling;
                const icon = element.querySelector('.toggle-icon');
                if (!content || !icon) {
                    throw new Error('Missing content or icon element');
                }
                content.style.display = content.style.display === 'block' ? 'none' : 'block';
                icon.classList.toggle('fa-chevron-down');
                icon.classList.toggle('fa-chevron-up');
            } catch (error) {
                logError('Failed to toggle section', error);
            }
        }

        function toggleStorageNode(element) {
            try {
                const content = element.nextElementSibling;
                const icon = element.querySelector('.toggle-icon');
                if (!content || !icon) {
                    throw new Error('Missing content or icon element');
                }
                content.style.display = content.style.display === 'block' ? 'none' : 'block';
                icon.classList.toggle('fa-chevron-down');
                icon.classList.toggle('fa-chevron-up');
            } catch (error) {
                logError('Failed to toggle storage node', error);
            }
        }

        function closeModal() {
            try {
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.style.display = 'none';
                });
            } catch (error) {
                logError('Failed to close modal', error);
            }
        }

        function openModal(action, data) {
            try {
                const modal = document.getElementById('storage-modal');
                const title = document.getElementById('modal-title');
                const form = document.getElementById('storage-form');
                const formAction = document.getElementById('form-action');
                const formContent = document.getElementById('form-content');
                const submitBtn = form.querySelector('.primary-btn');

                if (!modal || !title || !form || !formAction || !formContent || !submitBtn) {
                    throw new Error('Missing modal elements');
                }

                modal.style.display = 'flex';
                formContent.innerHTML = '';

                if (action === 'add') {
                    title.textContent = 'Add Storage Unit';
                    submitBtn.textContent = 'Add';
                    formAction.value = 'add_unit';
                    const nextUnitType = data.parent_type ? getNextUnitType(data.parent_type) : 'room';
                    formContent.innerHTML = `
                        <div class="form-group">
                            <label for="department_id">Department:</label>
                            <select name="department_id" id="department_id" required onchange="updateSubDepartments(this)">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['department_id']; ?>" ${data.dept_id == '<?php echo $dept['department_id']; ?>' ? 'selected' : ''}>
                                        <?php echo htmlspecialchars($dept['department_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="sub_department_id">Sub-Department (Optional):</label>
                            <select name="sub_department_id" id="sub_department_id">
                                <option value="">None</option>
                                <?php if (!empty($subDeptsByDept[$departments[0]['department_id'] ?? 0])): ?>
                                    <?php foreach ($subDeptsByDept[$departments[0]['department_id'] ?? 0] as $subDept): ?>
                                        <option value="<?php echo $subDept['department_id']; ?>" ${data.sub_dept_id == '<?php echo $subDept['department_id']; ?>' ? 'selected' : ''}>
                                            <?php echo htmlspecialchars($subDept['department_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
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
                            <input type="text" name="unit_name" id="unit_name" required>
                        </div>
                        <div class="form-group" id="capacity-group" style="display:${nextUnitType === 'folder' ? 'block' : 'none'};">
                            <label for="folder_capacity">Folder Capacity:</label>
                            <input type="number" name="folder_capacity" id="folder_capacity" min="1" value="10">
                        </div>
                    `;
                } else if (action === 'add_hierarchy') {
                    title.textContent = 'Add Storage Hierarchy';
                    submitBtn.textContent = 'Create Hierarchy';
                    formAction.value = 'add_hierarchy';
                    formContent.innerHTML = `
                        <style>
                            .hierarchy-form-container {
                                display: flex;
                                flex-wrap: wrap;
                                gap: 20px;
                                align-items: flex-start;
                            }
                            .hierarchy-section {
                                display: flex;
                                flex-direction: column;
                                flex: 1;
                                min-width: 200px;
                            }
                            .hierarchy-section label {
                                margin-bottom: 5px;
                                font-weight: bold;
                            }
                            .hierarchy-section input, .hierarchy-section select {
                                width: 100%;
                                padding: 8px;
                                border: 1px solid #ccc;
                                border-radius: 4px;
                            }
                            .hierarchy-flow {
                                display: flex;
                                flex-wrap: wrap;
                                gap: 10px;
                                align-items: center;
                                margin-top: 10px;
                            }
                            .hierarchy-flow .form-group {
                                flex: 1;
                                min-width: 150px;
                                display: flex;
                                align-items: center;
                            }
                            .hierarchy-flow .form-group label {
                                margin-right: 10px;
                                white-space: nowrap;
                            }
                            .hierarchy-flow .form-group input {
                                flex: 1;
                            }
                            .hierarchy-flow .arrow {
                                margin: 0 10px;
                                font-size: 1.2em;
                                color: #555;
                            }
                            @media (max-width: 600px) {
                                .hierarchy-flow .form-group {
                                    flex: 100%;
                                    margin-bottom: 10px;
                                }
                                .hierarchy-flow .arrow {
                                    display: none;
                                }
                            }
                        </style>
                        <div class="hierarchy-form-container">
                            <div class="hierarchy-section">
                                <div class="form-group">
                                    <label for="department_id">Department:</label>
                                    <select name="department_id" id="department_id" required onchange="updateSubDepartments(this)">
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo $dept['department_id']; ?>" ${data.dept_id == '<?php echo $dept['department_id']; ?>' ? 'selected' : ''}>
                                                <?php echo htmlspecialchars($dept['department_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="sub_department_id">Sub-Department (Optional):</label>
                                    <select name="sub_department_id" id="sub_department_id">
                                        <option value="">None</option>
                                        <?php if (!empty($subDeptsByDept[$departments[0]['department_id'] ?? 0])): ?>
                                            <?php foreach ($subDeptsByDept[$departments[0]['department_id'] ?? 0] as $subDept): ?>
                                                <option value="<?php echo $subDept['department_id']; ?>" ${data.sub_dept_id == '<?php echo $subDept['department_id']; ?>' ? 'selected' : ''}>
                                                    <?php echo htmlspecialchars($subDept['department_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="hierarchy-section">
                                <div class="hierarchy-flow">
                                    <div class="form-group">
                                        <label for="room_name">Room:</label>
                                        <input type="text" name="room_name" id="room_name" placeholder="e.g., Lab 1">
                                    </div>
                                    <span class="arrow">&rarr;</span>
                                    <div class="form-group">
                                        <label for="cabinet_name">Cabinet:</label>
                                        <input type="text" name="cabinet_name" id="cabinet_name" placeholder="e.g., Cabinet A">
                                    </div>
                                    <span class="arrow">&rarr;</span>
                                    <div class="form-group">
                                        <label for="layer_name">Layer:</label>
                                        <input type="text" name="layer_name" id="layer_name" placeholder="e.g., Layer 1">
                                    </div>
                                    <span class="arrow">&rarr;</span>
                                    <div class="form-group">
                                        <label for="box_name">Box:</label>
                                        <input type="text" name="box_name" id="box_name" placeholder="e.g., Box 1">
                                    </div>
                                    <span class="arrow">&rarr;</span>
                                    <div class="form-group">
                                        <label for="folder_name">Folder:</label>
                                        <input type="text" name="folder_name" id="folder_name" placeholder="e.g., Folder 1">
                                    </div>
                                </div>
                                <div class="form-group" style="margin-top: 10px;">
                                    <label for="folder_capacity">Folder Capacity:</label>
                                    <input type="number" name="folder_capacity" id="folder_capacity" min="1" value="10">
                                </div>
                            </div>
                        </div>
                    `;
                } else if (action === 'edit') {
                    title.textContent = 'Edit Folder Capacity';
                    submitBtn.textContent = 'Update';
                    formAction.value = 'edit_unit';
                    formContent.innerHTML = `
                        <div class="form-group">
                            <label>Unit: ${data.unit_name} (${data.unit_type})</label>
                        </div>
                        <div class="form-group">
                            <label for="folder_capacity">Capacity:</label>
                            <input type="number" name="folder_capacity" id="folder_capacity" min="1" value="${data.folder_capacity}" required>
                        </div>
                    `;
                } else if (action === 'delete') {
                    const deleteModal = document.getElementById('warning-delete-unit-modal');
                    const deleteUnitName = document.getElementById('delete-unit-name');
                    if (!deleteModal || !deleteUnitName) {
                        throw new Error('Missing delete modal elements');
                    }
                    deleteUnitName.textContent = data.unit_name;
                    deleteModal.style.display = 'flex';
                    const confirmBtn = document.getElementById('confirm-delete-unit');
                    confirmBtn.onclick = function() {
                        try {
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.innerHTML = `
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                                <input type="hidden" name="action" value="delete_unit">
                                <input type="hidden" name="location_id" value="${data.location_id}">
                            `;
                            document.body.appendChild(form);
                            form.submit();
                        } catch (error) {
                            logError('Failed to submit delete form', error);
                        }
                    };
                    return; // Exit early as we're using a different modal
                } else {
                    throw new Error('Invalid modal action: ' + action);
                }
            } catch (error) {
                logError('Failed to open modal', error);
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
            try {
                const unitType = document.getElementById('unit_type');
                const capacityGroup = document.getElementById('capacity-group');
                if (!unitType || !capacityGroup) {
                    throw new Error('Missing unit_type or capacity-group elements');
                }
                capacityGroup.style.display = unitType.value === 'folder' ? 'block' : 'none';
            } catch (error) {
                logError('Failed to toggle capacity field', error);
            }
        }

        function confirmRemoveFile(fileId, fileName) {
            try {
                const modal = document.getElementById('warning-remove-file-modal');
                const fileNameSpan = document.getElementById('remove-file-name');
                if (!modal || !fileNameSpan) {
                    throw new Error('Missing remove file modal elements');
                }
                fileNameSpan.textContent = fileName;
                modal.style.display = 'flex';

                const confirmBtn = document.getElementById('confirm-remove-file');
                confirmBtn.onclick = function() {
                    try {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.innerHTML = `
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                            <input type="hidden" name="action" value="remove_file">
                            <input type="hidden" name="file_id" value="${fileId}">
                        `;
                        document.body.appendChild(form);
                        form.submit();
                    } catch (error) {
                        logError('Failed to submit remove file form', error);
                    }
                };
            } catch (error) {
                logError('Failed to open remove file modal', error);
            }
        }

        function performSearch() {
            try {
                const searchInput = document.getElementById('storage-search');
                if (!searchInput) {
                    throw new Error('Missing search input');
                }
                const searchTerm = searchInput.value.trim();
                window.location.href = `physical_storage_management.php?search=${encodeURIComponent(searchTerm)}`;
            } catch (error) {
                logError('Failed to perform search', error);
            }
        }

        function updateSubDepartments(selectElement) {
            try {
                const deptId = selectElement.value;
                const subDeptSelect = document.getElementById('sub_department_id');
                if (!subDeptSelect) {
                    throw new Error('Missing sub_department_id select');
                }
                subDeptSelect.innerHTML = '<option value="">None</option>';

                if (!deptId) return;

                fetchSubDepartments(deptId).then(subDepts => {
                    subDepts.forEach(subDept => {
                        const option = document.createElement('option');
                        option.value = subDept.department_id;
                        option.textContent = subDept.department_name;
                        subDeptSelect.appendChild(option);
                    });
                }).catch(error => {
                    logError('Failed to fetch sub-departments', error);
                });
            } catch (error) {
                logError('Failed to update sub-departments', error);
            }
        }

        async function fetchSubDepartments(deptId) {
            try {
                const response = await fetch(`get_sub_departments.php?dept_id=${deptId}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return await response.json();
            } catch (error) {
                logError('Failed to fetch sub-departments', error);
                return [];
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            try {
                // Initialize tab switching
                document.querySelectorAll('.tab-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        try {
                            const tabContainer = this.closest('.tab-container');
                            if (!tabContainer) {
                                throw new Error('Missing tab container');
                            }
                            const tabId = this.dataset.tab;
                            tabContainer.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                            this.classList.add('active');
                            tabContainer.querySelectorAll('.tab-content').forEach(content => {
                                content.style.display = content.id.includes(tabId) ? 'block' : 'none';
                            });
                        } catch (error) {
                            logError('Failed to switch tab', error);
                        }
                    });
                });

                // Handle search highlighting
                const searchTerm = "<?php echo addslashes($searchTerm); ?>";
                if (searchTerm) {
                    const searchLower = searchTerm.toLowerCase();
                    document.querySelectorAll('.storage-node, .file-name').forEach(node => {
                        try {
                            if (node.textContent.toLowerCase().includes(searchLower)) {
                                let current = node;
                                while (current && current !== document) {
                                    if (current.classList.contains('node-header')) {
                                        toggleStorageNode(current);
                                    } else if (current.classList.contains('department-header')) {
                                        toggleSection(current);
                                    } else if (current.classList.contains('tab-content')) {
                                        const tabContainer = current.closest('.tab-container');
                                        const tabId = current.id.split('-')[0];
                                        const tabBtn = tabContainer.querySelector(`.tab-btn[data-tab="${tabId}"]`);
                                        if (tabBtn) tabBtn.click();
                                    }
                                    current = current.parentElement;
                                }
                                node.style.backgroundColor = '#fff3cd';
                            }
                        } catch (error) {
                            logError('Failed to highlight search result', error);
                        }
                    });
                }

                // Close modals
                document.querySelectorAll('.close').forEach(close => {
                    close.addEventListener('click', closeModal);
                });

                // Close alerts
                document.querySelectorAll('.close-alert').forEach(btn => {
                    btn.addEventListener('click', () => {
                        try {
                            btn.parentElement.style.display = 'none';
                        } catch (error) {
                            logError('Failed to close alert', error);
                        }
                    });
                });

                // Initialize department sections
                document.querySelectorAll('.department-section').forEach(section => {
                    const content = section.querySelector('.dropdown-content');
                    if (content) {
                        content.style.display = 'none';
                    }
                });
            } catch (error) {
                logError('Failed to initialize DOM', error);
            }
        });
    </script>
</body>

</html>

<?php
function renderStorageTree(array $tree, array $filesByLocation, StorageManager $storageManager, int $depth = 0, string $parentType = ''): void
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
                $stmt = $storageManager->executeQuery(
                    "SELECT * FROM storage_locations WHERE storage_location_id = ?",
                    [$current['parent_storage_location_id']]
                );
                $current = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            } else {
                $current = null;
            }
        }
        $breadcrumbs = array_reverse($breadcrumbs);
        $breadcrumbs = array_slice($breadcrumbs, -3);

        echo '<div class="storage-node" data-id="' . htmlspecialchars((string)$node['storage_location_id']) . '" data-type="' . htmlspecialchars($node['unit_type']) . '" data-depth="' . $depth . '">';
        echo '<div class="node-header" onclick="toggleStorageNode(this)">';
        echo '<div class="node-icon"><i class="fas fa-' . htmlspecialchars($icon) . '"></i></div>';
        echo '<div class="node-info">';
        echo '<h4>' . htmlspecialchars($node['unit_name']) . '</h4>';
        echo '<div class="breadcrumbs">' . implode(' > ', array_map('htmlspecialchars', $breadcrumbs)) . '</div>';
        echo '<span class="node-type">' . htmlspecialchars(ucfirst($node['unit_type'])) . '</span>';
        if ($node['unit_type'] === 'folder') {
            echo '<span class="capacity">' . $fileCount . ' / ' . htmlspecialchars((string)$node['folder_capacity']) . ' files</span>';
        }
        echo '</div>';
        echo '<div class="node-actions">';
        echo '<i class="fas fa-chevron-down toggle-icon"></i>';
        echo '</div>';
        echo '</div>';

        echo '<div class="node-content" style="display: none;">';
        echo '<div class="action-buttons">';
        echo '<button class="primary-btn" onclick="openModal(\'add\', {parent_id: \'' . htmlspecialchars((string)$node['storage_location_id']) . '\', dept_id: \'' . htmlspecialchars((string)$node['department_id']) . '\', sub_dept_id: \'' . htmlspecialchars((string)($node['sub_department_id'] ?? '')) . '\', parent_type: \'' . htmlspecialchars($node['unit_type']) . '\'})">';
        echo '<i class="fas fa-plus"></i> Add Child Unit';
        echo '</button>';

        if ($node['unit_type'] === 'folder') {
            echo '<button class="secondary-btn edit-btn" onclick="openModal(\'edit\', ' . htmlspecialchars(json_encode($node, JSON_HEX_APOS | JSON_HEX_QUOT)) . ')">';
            echo '<i class="fas fa-edit"></i> Edit Capacity';
            echo '</button>';
        }

        echo '<button class="secondary-btn delete-btn" onclick="openModal(\'delete\', {location_id: \'' . htmlspecialchars((string)$node['storage_location_id']) . '\', unit_name: \'' . htmlspecialchars($node['unit_name']) . '\'})">';
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
                echo '<button class="btn-danger remove-btn" onclick="confirmRemoveFile(' . htmlspecialchars((string)$file['file_id']) . ', \'' . htmlspecialchars($file['file_name']) . '\')">';
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
                    $childFiles[$child['storage_location_id']] = $storageManager->getFilesByLocation($child['storage_location_id']);
                }
            }
            renderStorageTree($node['children'], $childFiles, $storageManager, $depth + 1, $node['unit_type']);
        }

        echo '</div>';
        echo '</div>';
    }
}
?>