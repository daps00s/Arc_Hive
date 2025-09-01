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

// Function to validate and save profile picture
function saveProfilePicture($pdo, $userId, $file, $username)
{
    if (empty($file['name']) || $file['size'] === 0) {
        return null;
    }

    $allowedTypes = ['image/jpeg', 'image/png'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    if (!in_array($file['type'], $allowedTypes) || $file['size'] > $maxSize) {
        return false;
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid($username . '_profile_') . '.' . $ext;
    $path = 'Uploads/ProfilePictures/' . $filename;

    if (!is_dir('Uploads/ProfilePictures')) {
        if (!mkdir('Uploads/ProfilePictures', 0777, true)) {
            error_log("Failed to create directory: Uploads/ProfilePictures");
            return false;
        }
    }

    if (move_uploaded_file($file['tmp_name'], $path)) {
        // Delete old profile picture if updating
        if ($userId) {
            $oldPictureStmt = executeQuery($pdo, "SELECT profile_picture FROM users WHERE user_id = ?", [$userId]);
            if ($oldPictureStmt && $oldPicture = $oldPictureStmt->fetchColumn()) {
                if (file_exists($oldPicture) && $oldPicture !== $path) {
                    unlink($oldPicture);
                }
            }
        }
        return $path;
    }
    return false;
}

// Handle form submissions
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $username = trim($_POST['username'] ?? '');
        $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $password = password_hash($_POST['password'] ?? '', PASSWORD_BCRYPT);
        $role = in_array($_POST['role'] ?? '', ['admin', 'client']) ? $_POST['role'] : 'client';
        $department_ids = isset($_POST['departments']) ? array_map('intval', $_POST['departments']) : [];
        $sub_department_ids = isset($_POST['sub_departments']) ? array_map('intval', $_POST['sub_departments']) : [];
        $profile_picture = isset($_FILES['profile_picture']) ? $_FILES['profile_picture'] : [];

        // Validate username and email uniqueness
        $checkStmt = executeQuery($pdo, "SELECT COUNT(*) FROM users WHERE username = ? OR email = ?", [$username, $email]);
        if ($checkStmt && $checkStmt->fetchColumn() > 0) {
            $errorMessage = 'Username or email already exists.';
        } elseif (!empty($username) && !empty($email) && !empty($_POST['password'])) {
            $personal_folder = 'Uploads/Users/' . $username;
            $profile_picture_path = saveProfilePicture($pdo, null, $profile_picture, $username);

            if ($profile_picture_path !== false) {
                $insertUserStmt = executeQuery(
                    $pdo,
                    "INSERT INTO users (username, email, password, role, personal_folder, profile_picture)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [$username, $email, $password, $role, $personal_folder, $profile_picture_path]
                );
                if ($insertUserStmt) {
                    $new_user_id = $pdo->lastInsertId();
                    foreach ($department_ids as $department_id) {
                        executeQuery($pdo, "INSERT INTO user_department_assignments (user_id, department_id) VALUES (?, ?)", [$new_user_id, $department_id]);
                    }
                    foreach ($sub_department_ids as $sub_department_id) {
                        executeQuery($pdo, "INSERT INTO user_department_assignments (user_id, department_id) VALUES (?, ?)", [$new_user_id, $sub_department_id]);
                    }
                    executeQuery(
                        $pdo,
                        "INSERT INTO transactions (user_id, transaction_status, transaction_time, description, transaction_type)
                         VALUES (?, 'completed', NOW(), ?, 'other')",
                        [$userId, "Added user: $username"]
                    );
                    $successMessage = 'User added successfully.';
                } else {
                    $errorMessage = 'Failed to add user.';
                }
            } else {
                $errorMessage = 'Invalid profile picture or upload failed.';
            }
        } else {
            $errorMessage = 'All required fields must be filled.';
        }
    } elseif ($action === 'edit_user') {
        $edit_user_id = (int)($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $role = in_array($_POST['role'] ?? '', ['admin', 'client']) ? $_POST['role'] : 'client';
        $department_ids = isset($_POST['departments']) ? array_map('intval', $_POST['departments']) : [];
        $sub_department_ids = isset($_POST['sub_departments']) ? array_map('intval', $_POST['sub_departments']) : [];
        $profile_picture = isset($_FILES['profile_picture']) ? $_FILES['profile_picture'] : [];

        $personal_folder = 'Uploads/Users/' . $username;
        $profile_picture_path = saveProfilePicture($pdo, $edit_user_id, $profile_picture, $username);

        $password_sql = '';
        $profile_sql = '';
        $params = [$username, $email, $role, $personal_folder];
        if ($profile_picture_path) {
            $profile_sql = ', profile_picture = ?';
            $params[] = $profile_picture_path;
        }
        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
            $password_sql = ', password = ?';
            $params[] = $password;
        }
        $params[] = $edit_user_id;

        if ($edit_user_id > 0 && !empty($username) && !empty($email)) {
            $checkStmt = executeQuery($pdo, "SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND user_id != ?", [$username, $email, $edit_user_id]);
            if ($checkStmt && $checkStmt->fetchColumn() > 0) {
                $errorMessage = 'Username or email already exists.';
            } else {
                $updateUserStmt = executeQuery(
                    $pdo,
                    "UPDATE users SET username = ?, email = ?, role = ?, personal_folder = ? $password_sql $profile_sql WHERE user_id = ?",
                    $params
                );
                if ($updateUserStmt) {
                    executeQuery($pdo, "DELETE FROM user_department_assignments WHERE user_id = ?", [$edit_user_id]);
                    foreach ($department_ids as $department_id) {
                        executeQuery($pdo, "INSERT INTO user_department_assignments (user_id, department_id) VALUES (?, ?)", [$edit_user_id, $department_id]);
                    }
                    foreach ($sub_department_ids as $sub_department_id) {
                        executeQuery($pdo, "INSERT INTO user_department_assignments (user_id, department_id) VALUES (?, ?)", [$edit_user_id, $sub_department_id]);
                    }
                    executeQuery(
                        $pdo,
                        "INSERT INTO transactions (user_id, transaction_status, transaction_time, description, transaction_type)
                         VALUES (?, 'completed', NOW(), ?, 'other')",
                        [$userId, "Edited user: $username"]
                    );
                    $successMessage = 'User updated successfully.';
                } else {
                    $errorMessage = 'Failed to update user.';
                }
            }
        } else {
            $errorMessage = 'Invalid data for update.';
        }
    } elseif ($action === 'delete_user') {
        $delete_user_id = (int)($_POST['user_id'] ?? 0);
        if ($delete_user_id > 0 && $delete_user_id != $userId) {
            $deleteStmt = executeQuery($pdo, "DELETE FROM users WHERE user_id = ?", [$delete_user_id]);
            if ($deleteStmt) {
                executeQuery($pdo, "DELETE FROM user_department_assignments WHERE user_id = ?", [$delete_user_id]);
                executeQuery(
                    $pdo,
                    "INSERT INTO transactions (user_id, transaction_status, transaction_time, description, transaction_type)
                     VALUES (?, 'completed', NOW(), ?, 'other')",
                    [$userId, "Deleted user ID: $delete_user_id"]
                );
                $successMessage = 'User deleted successfully.';
            } else {
                $errorMessage = 'Failed to delete user.';
            }
        } else {
            $errorMessage = 'Cannot delete your own account or invalid user ID.';
        }
    }
}

// Fetch departments and sub-departments
$departmentsStmt = executeQuery($pdo, "
    SELECT department_id, department_name, parent_department_id
    FROM departments
    WHERE department_type IN ('college', 'office')
    ORDER BY department_name");
$departments = $departmentsStmt ? $departmentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$subDepartmentsStmt = executeQuery($pdo, "
    SELECT department_id, department_name, parent_department_id
    FROM departments
    WHERE department_type = 'sub_department'
    ORDER BY department_name");
$subDepartments = $subDepartmentsStmt ? $subDepartmentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch users with filtering, searching, and pagination
$roleFilter = isset($_GET['role_filter']) ? trim($_GET['role_filter']) : '';
$roleFilter = in_array($roleFilter, ['admin', 'client']) ? $roleFilter : '';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';

$itemsPerPage = isset($_GET['items_per_page']) ? (int)$_GET['items_per_page'] : 5;
if (!in_array($itemsPerPage, [5, 10, 20, -1])) {
    $itemsPerPage = 5;
}
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$currentPage = max(1, $currentPage);
$offset = ($currentPage - 1) * $itemsPerPage;

$whereClause = [];
$params = [];
if ($roleFilter) {
    $whereClause[] = "u.role = ?";
    $params[] = $roleFilter;
}
if ($searchQuery) {
    $whereClause[] = "(u.username LIKE ? OR u.email LIKE ? OR d.department_name LIKE ? OR d2.department_name LIKE ?)";
    $searchTerm = "%$searchQuery%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}
$whereClause = $whereClause ? "WHERE " . implode(" AND ", $whereClause) : "";

$query = "
    SELECT 
        u.user_id, 
        u.username, 
        u.email, 
        u.role, 
        u.profile_picture,
        GROUP_CONCAT(DISTINCT CASE 
            WHEN d.department_type IN ('college', 'office') THEN d.department_name 
            ELSE NULL 
        END) AS main_departments,
        GROUP_CONCAT(DISTINCT CASE 
            WHEN d.department_type = 'sub_department' THEN d.department_name 
            ELSE NULL 
        END) AS sub_departments
    FROM users u
    LEFT JOIN user_department_assignments ud ON u.user_id = ud.user_id
    LEFT JOIN departments d ON ud.department_id = d.department_id
    $whereClause
    GROUP BY u.user_id
    ORDER BY u.username";
if ($itemsPerPage !== -1) {
    $query .= " LIMIT ?, ?";
    $params[] = $offset;
    $params[] = $itemsPerPage;
}
$usersStmt = executeQuery($pdo, $query, $params);
$users = $usersStmt ? $usersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$countParams = $params;
if ($itemsPerPage !== -1) {
    array_pop($countParams);
    array_pop($countParams);
}
$countStmt = executeQuery($pdo, "
    SELECT COUNT(*) 
    FROM users u
    LEFT JOIN user_department_assignments ud ON u.user_id = ud.user_id
    LEFT JOIN departments d ON ud.department_id = d.department_id
    $whereClause", $countParams);
$totalUsers = $countStmt ? $countStmt->fetchColumn() : 0;
$totalPages = $itemsPerPage === -1 ? 1 : max(1, ceil($totalUsers / $itemsPerPage));

// Ensure current page is within valid bounds
if ($currentPage > $totalPages && $totalPages > 0) {
    $currentPage = $totalPages;
    // Redirect to the corrected page
    $queryParams = $_GET;
    $queryParams['page'] = $currentPage;
    header('Location: user_management.php?' . http_build_query($queryParams));
    exit();
}

$csrfToken = generateCsrfToken();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <link rel="stylesheet" href="style/user_management.css">
    <link rel="stylesheet" href="style/admin-search.css">
    <link rel="stylesheet" href="style/admin-sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.0.0/dist/css/tom-select.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.5.12/dist/cropper.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.5.12/dist/cropper.min.css">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.0.0/dist/js/tom-select.complete.min.js"></script>
</head>

<body class="admin-dashboard">
    <?php
    include 'admin_menu.php';
    ?>
    <div class="top-nav">
        <h2>User Management</h2>
        <input type="text" id="filterSearch" placeholder="Search by username, email, or department..." autocomplete="off" aria-label="Search users">
    </div>
    <div class="main-content">
        <?php if ($successMessage): ?>
            <div class="success-message">
                <?php echo sanitizeHTML($successMessage); ?>
                <span class="close-alert" onclick="this.parentElement.style.display='none'">&times;</span>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div class="error-message">
                <?php echo sanitizeHTML($errorMessage); ?>
                <span class="close-alert" onclick="this.parentElement.style.display='none'">&times;</span>
            </div>
        <?php endif; ?>
        <div class="filter-container">
            <label for="role_filter">Filter by Role:</label>
            <select id="role_filter" onchange="updateRoleFilter()">
                <option value="">All</option>
                <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                <option value="client" <?php echo $roleFilter === 'client' ? 'selected' : ''; ?>>Client</option>
            </select>
            <label for="items_per_page">Items per page:</label>
            <select id="items_per_page" onchange="updateItemsPerPage()">
                <option value="5" <?php echo $itemsPerPage === 5 ? 'selected' : ''; ?>>5</option>
                <option value="10" <?php echo $itemsPerPage === 10 ? 'selected' : ''; ?>>10</option>
                <option value="20" <?php echo $itemsPerPage === 20 ? 'selected' : ''; ?>>20</option>
                <option value="-1" <?php echo $itemsPerPage === -1 ? 'selected' : ''; ?>>All</option>
            </select>
            <button class="primary-btn" onclick="openModal('add')">
                <i class="fas fa-plus"></i> Add User
            </button>
        </div>
        <div class="table-container">
            <table class="department-table">
                <thead>
                    <tr>
                        <th>Profile</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Departments</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <?php if ($user['profile_picture']): ?>
                                    <img src="<?php echo sanitizeHTML($user['profile_picture']); ?>" alt="Profile" class="profile-pic">
                                <?php else: ?>
                                    <i class="fas fa-user-circle fa-2x"></i>
                                <?php endif; ?>
                            </td>
                            <td><?php echo sanitizeHTML($user['username']); ?></td>
                            <td><?php echo sanitizeHTML($user['email']); ?></td>
                            <td><?php echo sanitizeHTML($user['role']); ?></td>
                            <td>
                                <?php
                                $departments_display = [];
                                if (!empty($user['main_departments'])) {
                                    $departments_display[] = sanitizeHTML($user['main_departments']);
                                }
                                if (!empty($user['sub_departments'])) {
                                    $departments_display[] = sanitizeHTML($user['sub_departments']);
                                }
                                echo !empty($departments_display) ? implode(' → ', $departments_display) : 'None';
                                ?>
                            </td>
                            <td>
                                <button class="secondary-btn edit-btn" onclick='openModal("edit", <?php echo json_encode($user); ?>)'>
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="secondary-btn delete-btn" onclick='openModal("delete", <?php echo json_encode($user); ?>)'>
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($itemsPerPage !== -1): ?>
            <div class="pagination">
                <button onclick="goToPage(<?php echo $currentPage - 1; ?>)" <?php echo $currentPage === 1 ? 'disabled' : ''; ?>>Previous</button>
                <span>Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
                <button onclick="goToPage(<?php echo $currentPage + 1; ?>)" <?php echo $currentPage === $totalPages ? 'disabled' : ''; ?>>Next</button>
            </div>
        <?php endif; ?>
    </div>
    <div class="modal" id="user-modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 id="modal-title"></h2>
            <form id="user-form" enctype="multipart/form-data" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" id="form-action">
                <input type="hidden" name="user_id" id="user_id">
                <div id="form-content"></div>
                <div class="modal-buttons">
                    <button type="button" class="cancel-btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="primary-btn" id="form-submit" data-action=""></button>
                </div>
            </form>
        </div>
    </div>
    <div class="cropper-modal-wrapper" id="cropper-modal-wrapper">
        <div class="cropper-content">
            <div class="cropper-header">
                <h3>Crop Profile Picture</h3>
                <button class="close-cropper" onclick="cancelCrop()">&times;</button>
            </div>
            <div class="cropper-body">
                <div class="cropper-container">
                    <img id="cropper-image" alt="Image to crop">
                </div>
                <div class="cropper-preview">
                    <h4>Preview</h4>
                    <div class="preview-container">
                        <img id="preview-image" alt="Preview">
                    </div>
                    <div class="error-text" id="cropper-error"></div>
                </div>
            </div>
            <div class="cropper-controls">
                <button type="button" id="cropper-zoom-in" title="Zoom In"><i class="fas fa-search-plus"></i></button>
                <button type="button" id="cropper-zoom-out" title="Zoom Out"><i class="fas fa-search-minus"></i></button>
                <button type="button" id="cropper-rotate-left" title="Rotate Left"><i class="fas fa-undo"></i></button>
                <button type="button" id="cropper-rotate-right" title="Rotate Right"><i class="fas fa-redo"></i></button>
                <button type="button" id="cropper-flip-horizontal" title="Flip Horizontal"><i class="fas fa-arrows-alt-h"></i></button>
                <button type="button" id="cropper-flip-vertical" title="Flip Vertical"><i class="fas fa-arrows-alt-v"></i></button>
            </div>
            <div class="cropper-buttons">
                <button type="button" class="cancel-btn" onclick="cancelCrop()">Cancel</button>
                <button type="button" class="primary-btn" id="cropper-save">Save</button>
            </div>
        </div>
    </div>
    <script>
        let cropper = null;
        let isCropperActive = false;
        const departmentsData = <?php echo json_encode($departments); ?>;
        const subDepartmentsData = <?php echo json_encode($subDepartments); ?>;

        function openModal(action, user = {}) {
            const modal = document.getElementById('user-modal');
            const modalTitle = document.getElementById('modal-title');
            const formAction = document.getElementById('form-action');
            const formSubmit = document.getElementById('form-submit');
            const formContent = document.getElementById('form-content');
            const userIdInput = document.getElementById('user_id');

            // Fetch user's assigned department and sub-department IDs
            let userDepartmentIds = [];
            let userSubDepartmentIds = [];
            if (action === 'edit' && user.user_id) {
                <?php
                // Fetch department assignments for the user being edited
                $userAssignmentsStmt = executeQuery($pdo, "
                    SELECT department_id
                    FROM user_department_assignments
                    WHERE user_id = ?",
                    [isset($user['user_id']) ? (int)$user['user_id'] : 0]
                );
                $userAssignments = $userAssignmentsStmt ? $userAssignmentsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
                ?>
                const userAssignments = <?php echo json_encode($userAssignments); ?>;
                userDepartmentIds = departmentsData
                    .filter(dept => userAssignments.includes(dept.department_id))
                    .map(dept => dept.department_id);
                userSubDepartmentIds = subDepartmentsData
                    .filter(subDept => userAssignments.includes(subDept.department_id))
                    .map(subDept => subDept.department_id);
            }

            if (action === 'add') {
                modalTitle.textContent = 'Add New User';
                formAction.value = 'add_user';
                formSubmit.textContent = 'Add User';
                formSubmit.setAttribute('data-action', 'add');
                userIdInput.value = '';
                formContent.innerHTML = `
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required>
                    <div class="error-text" id="username-error"></div>
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                    <div class="error-text" id="email-error"></div>
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                    <div class="error-text" id="password-error"></div>
                    <label for="role">Role</label>
                    <select id="role" name="role">
                        <option value="client">Client</option>
                        <option value="admin">Admin</option>
                    </select>
                    <label for="departments">Departments</label>
                    <select id="departments" name="departments[]" multiple></select>
                    <label for="sub_departments">Sub-Departments</label>
                    <select id="sub_departments" name="sub_departments[]" multiple></select>
                    <label for="profile_picture">Profile Picture</label>
                    <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png">
                    <div class="error-text" id="profile-error"></div>
                    <div class="profile-pic-preview" id="profile-pic-preview"></div>
                `;
            } else if (action === 'edit') {
                modalTitle.textContent = 'Edit User';
                formAction.value = 'edit_user';
                formSubmit.textContent = 'Update User';
                formSubmit.setAttribute('data-action', 'edit');
                userIdInput.value = user.user_id || '';
                formContent.innerHTML = `
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="${sanitizeHTML(user.username)}" required>
                    <div class="error-text" id="username-error"></div>
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="${sanitizeHTML(user.email)}" required>
                    <div class="error-text" id="email-error"></div>
                    <label for="password">Password (leave blank to keep current)</label>
                    <input type="password" id="password" name="password">
                    <div class="error-text" id="password-error"></div>
                    <label for="role">Role</label>
                    <select id="role" name="role">
                        <option value="client" ${user.role === 'client' ? 'selected' : ''}>Client</option>
                        <option value="admin" ${user.role === 'admin' ? 'selected' : ''}>Admin</option>
                    </select>
                    <label for="departments">Departments</label>
                    <select id="departments" name="departments[]" multiple></select>
                    <label for="sub_departments">Sub-Departments</label>
                    <select id="sub_departments" name="sub_departments[]" multiple></select>
                    <label for="profile_picture">Profile Picture</label>
                    <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png">
                    <div class="error-text" id="profile-error"></div>
                    <div class="profile-pic-preview" id="profile-pic-preview">
                        ${user.profile_picture ? `<img src="${sanitizeHTML(user.profile_picture)}" alt="Profile Preview">` : ''}
                    </div>
                `;
            } else if (action === 'delete') {
                modalTitle.textContent = 'Delete User';
                formAction.value = 'delete_user';
                formSubmit.textContent = 'Delete User';
                formSubmit.setAttribute('data-action', 'delete');
                userIdInput.value = user.user_id || '';
                formContent.innerHTML = `
                    <p>Are you sure you want to delete the user <strong>${sanitizeHTML(user.username)}</strong>?</p>
                `;
            }

            modal.style.display = 'flex';
            const profileInput = document.getElementById('profile_picture');
            const profilePreview = document.getElementById('profile-pic-preview');
            const cropperModal = document.getElementById('cropper-modal-wrapper');
            const cropperImage = document.getElementById('cropper-image');
            const previewImage = document.getElementById('preview-image');
            const cropperError = document.getElementById('cropper-error');

            // Initialize TomSelect for departments
            const departmentTomSelect = new TomSelect('#departments', {
                options: departmentsData.map(dept => ({
                    value: dept.department_id,
                    text: dept.department_name
                })),
                items: action === 'edit' ? userDepartmentIds : [],
                placeholder: 'Select departments...',
                searchField: ['text'],
                maxItems: null,
                render: {
                    option: function(data) {
                        return `<div>${data.text}</div>`;
                    },
                    item: function(data) {
                        return `<div>${data.text}</div>`;
                    }
                },
                onChange: function(values) {
                    updateSubDepartments(values);
                }
            });

            // Initialize TomSelect for sub-departments
            const subDepartmentTomSelect = new TomSelect('#sub_departments', {
                options: [],
                items: action === 'edit' ? userSubDepartmentIds : [],
                placeholder: 'Select sub-departments...',
                searchField: ['text'],
                maxItems: null,
                render: {
                    option: function(data) {
                        return `<div>${data.text} (Parent: ${data.parent_name})</div>`;
                    },
                    item: function(data) {
                        return `<div>${data.text}</div>`;
                    }
                }
            });

            function updateSubDepartments(selectedDepartmentIds) {
                const filteredSubDepartments = subDepartmentsData.filter(subDept =>
                    selectedDepartmentIds.map(String).includes(String(subDept.parent_department_id))
                );
                subDepartmentTomSelect.clear();
                subDepartmentTomSelect.clearOptions();
                subDepartmentTomSelect.addOptions(filteredSubDepartments.map(subDept => ({
                    value: subDept.department_id,
                    text: subDept.department_name,
                    parent_name: departmentsData.find(dept => dept.department_id === subDept.parent_department_id)?.department_name || 'Unknown'
                })));
                if (action === 'edit') {
                    // Ensure sub-departments are set based on user's current assignments
                    subDepartmentTomSelect.setValue(userSubDepartmentIds);
                }
            }

            if (action === 'edit') {
                updateSubDepartments(userDepartmentIds);
            }

            // Initialize Cropper.js with social media-style cropping
            profileInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                const profileError = document.getElementById('profile-error');
                profileError.textContent = '';
                cropperError.textContent = '';
                profilePreview.innerHTML = '';
                if (file) {
                    const allowedTypes = ['image/jpeg', 'image/png'];
                    const maxSize = 5 * 1024 * 1024; // 5MB
                    if (!allowedTypes.includes(file.type)) {
                        profileError.textContent = 'Please select a JPEG or PNG image.';
                        profileInput.value = '';
                        return;
                    }
                    if (file.size > maxSize) {
                        profileError.textContent = 'Image size must be less than 5MB.';
                        profileInput.value = '';
                        return;
                    }

                    const reader = new FileReader();
                    reader.onload = function(event) {
                        cropperImage.src = event.target.result;
                        document.getElementById('user-modal').style.display = 'none';
                        cropperModal.style.display = 'flex';
                        isCropperActive = true;
                        if (cropper) {
                            cropper.destroy();
                        }
                        try {
                            cropper = new Cropper(cropperImage, {
                                aspectRatio: 1,
                                viewMode: 1,
                                autoCropArea: 0.9,
                                responsive: true,
                                guides: true,
                                center: true,
                                highlight: true,
                                cropBoxMovable: true,
                                cropBoxResizable: true,
                                dragMode: 'move',
                                background: false,
                                zoomable: true,
                                rotatable: true,
                                scalable: true,
                                zoomOnTouch: true,
                                zoomOnWheel: true,
                                toggleDragModeOnDblclick: false,
                                minCropBoxWidth: 200,
                                minCropBoxHeight: 200,
                                ready: function() {
                                    const containerData = cropper.getContainerData();
                                    const imageData = cropper.getImageData();
                                    const cropBoxSize = Math.min(containerData.width, containerData.height, imageData.naturalWidth, imageData.naturalHeight) * 0.9;
                                    cropper.setCropBoxData({
                                        width: cropBoxSize,
                                        height: cropBoxSize,
                                        left: (containerData.width - cropBoxSize) / 2,
                                        top: (containerData.height - cropBoxSize) / 2
                                    });
                                    updatePreview();
                                    document.getElementById('cropper-save').disabled = false;
                                },
                                crop: function() {
                                    updatePreview();
                                }
                            });

                            // Initialize cropper controls
                            document.getElementById('cropper-zoom-in').addEventListener('click', () => cropper.zoom(0.1));
                            document.getElementById('cropper-zoom-out').addEventListener('click', () => cropper.zoom(-0.1));
                            document.getElementById('cropper-rotate-left').addEventListener('click', () => cropper.rotate(-90));
                            document.getElementById('cropper-rotate-right').addEventListener('click', () => cropper.rotate(90));
                            document.getElementById('cropper-flip-horizontal').addEventListener('click', () => {
                                cropper.scaleX(cropper.getImageData().scaleX === 1 ? -1 : 1);
                                updatePreview();
                            });
                            document.getElementById('cropper-flip-vertical').addEventListener('click', () => {
                                cropper.scaleY(cropper.getImageData().scaleY === 1 ? -1 : 1);
                                updatePreview();
                            });
                        } catch (error) {
                            cropperError.textContent = 'Failed to initialize image cropping. Please try another image.';
                            document.getElementById('cropper-save').disabled = true;
                            isCropperActive = false;
                            cropperModal.style.display = 'none';
                            document.getElementById('user-modal').style.display = 'flex';
                        }
                    };
                    reader.onerror = function() {
                        cropperError.textContent = 'Error reading the image file. Please try again.';
                        profileInput.value = '';
                        profilePreview.innerHTML = '';
                        isCropperActive = false;
                        cropperModal.style.display = 'none';
                        document.getElementById('user-modal').style.display = 'flex';
                    };
                    reader.readAsDataURL(file);
                }
            });

            function updatePreview() {
                if (!cropper) return;

                try {
                    const canvas = cropper.getCroppedCanvas({
                        width: 200,
                        height: 200,
                        imageSmoothingEnabled: true,
                        imageSmoothingQuality: 'high'
                    });

                    if (canvas) {
                        previewImage.src = canvas.toDataURL('image/jpeg', 0.9);
                    } else {
                        document.getElementById('cropper-error').textContent = 'Failed to generate preview.';
                    }
                } catch (error) {
                    document.getElementById('cropper-error').textContent = 'Error updating preview.';
                }
            }

            document.getElementById('cropper-save').addEventListener('click', function() {
                if (!cropper) {
                    document.getElementById('cropper-error').textContent = 'No image to crop.';
                    return;
                }
                const formSubmit = document.getElementById('form-submit');
                formSubmit.disabled = true;
                formSubmit.textContent = 'Saving...';
                try {
                    cropper.getCroppedCanvas({
                        width: 200,
                        height: 200,
                        imageSmoothingEnabled: true,
                        imageSmoothingQuality: 'high'
                    }).toBlob(function(blob) {
                        if (!blob) {
                            document.getElementById('cropper-error').textContent = 'Failed to crop image. Please try again.';
                            formSubmit.disabled = false;
                            formSubmit.textContent = action === 'add' ? 'Add User' : 'Update User';
                            return;
                        }
                        const url = URL.createObjectURL(blob);
                        profilePreview.innerHTML = `<img src="${url}" alt="Profile Preview">`;
                        const file = new File([blob], 'cropped_profile.jpg', {
                            type: 'image/jpeg',
                            lastModified: Date.now()
                        });
                        const dataTransfer = new DataTransfer();
                        dataTransfer.items.add(file);
                        profileInput.files = dataTransfer.files;
                        cropperModal.style.display = 'none';
                        document.getElementById('user-modal').style.display = 'flex';
                        isCropperActive = false;
                        if (cropper) {
                            cropper.destroy();
                            cropper = null;
                        }
                        formSubmit.disabled = false;
                        formSubmit.textContent = action === 'add' ? 'Add User' : 'Update User';
                    }, 'image/jpeg', 0.9);
                } catch (error) {
                    document.getElementById('cropper-error').textContent = 'Error cropping image. Please try again.';
                    formSubmit.disabled = false;
                    formSubmit.textContent = action === 'add' ? 'Add User' : 'Update User';
                    isCropperActive = false;
                    cropperModal.style.display = 'none';
                    document.getElementById('user-modal').style.display = 'flex';
                }
            });
        }

        function cancelCrop() {
            const cropperModal = document.getElementById('cropper-modal-wrapper');
            const userModal = document.getElementById('user-modal');
            const profileInput = document.getElementById('profile_picture');
            const profileError = document.getElementById('profile-error');
            const cropperError = document.getElementById('cropper-error');
            const profilePreview = document.getElementById('profile-pic-preview');
            const formSubmit = document.getElementById('form-submit');
            const action = formSubmit ? formSubmit.getAttribute('data-action') : 'add';

            cropperModal.style.display = 'none';
            userModal.style.display = 'flex';
            if (profileInput) profileInput.value = '';
            if (profileError) profileError.textContent = '';
            if (cropperError) cropperError.textContent = '';
            if (profilePreview) profilePreview.innerHTML = '';
            isCropperActive = false;
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
            if (formSubmit) {
                formSubmit.disabled = false;
                formSubmit.textContent = action === 'add' ? 'Add User' : 'Update User';
            }
        }

        function closeModal() {
            const userModal = document.getElementById('user-modal');
            const cropperModal = document.getElementById('cropper-modal-wrapper');
            const profileInput = document.getElementById('profile_picture');
            const profilePreview = document.getElementById('profile-pic-preview');
            const cropperError = document.getElementById('cropper-error');

            userModal.style.display = 'none';
            cropperModal.style.display = 'none';
            isCropperActive = false;
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
            if (profileInput) profileInput.value = '';
            if (profilePreview) profilePreview.innerHTML = '';
            if (cropperError) cropperError.textContent = '';
        }

        function sanitizeHTML(str) {
            const div = document.createElement('div');
            div.textContent = str ?? '';
            return div.innerHTML;
        }

        function updateItemsPerPage() {
            const itemsPerPage = document.getElementById('items_per_page').value;
            const roleFilter = document.getElementById('role_filter').value;
            const searchQuery = document.getElementById('filterSearch').value;
            window.location.href = `user_management.php?items_per_page=${itemsPerPage}&page=1&role_filter=${roleFilter}&search=${encodeURIComponent(searchQuery)}`;
        }

        function goToPage(page) {
            const itemsPerPage = document.getElementById('items_per_page').value;
            const roleFilter = document.getElementById('role_filter').value;
            const searchQuery = document.getElementById('filterSearch').value;
            window.location.href = `user_management.php?items_per_page=${itemsPerPage}&page=${page}&role_filter=${roleFilter}&search=${encodeURIComponent(searchQuery)}`;
        }

        function updateRoleFilter() {
            const roleFilter = document.getElementById('role_filter').value;
            const itemsPerPage = document.getElementById('items_per_page').value;
            const searchQuery = document.getElementById('filterSearch').value;
            window.location.href = `user_management.php?items_per_page=${itemsPerPage}&page=1&role_filter=${roleFilter}&search=${encodeURIComponent(searchQuery)}`;
        }

        document.addEventListener('DOMContentLoaded', () => {
            const userModal = document.getElementById('user-modal');
            const cropperModal = document.getElementById('cropper-modal-wrapper');
            userModal.style.display = 'none';
            cropperModal.style.display = 'none';
            isCropperActive = false;
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }

            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const topNav = document.querySelector('.top-nav');
            mainContent.classList.add(sidebar?.classList.contains('minimized') ? 'resized' : '');
            topNav.classList.add(sidebar?.classList.contains('minimized') ? 'resized' : '');

            // Event listener for clicking outside modals
            window.addEventListener('click', (event) => {
                if (event.target === cropperModal && isCropperActive) {
                    cancelCrop();
                } else if (event.target === userModal && !isCropperActive) {
                    closeModal();
                }
            });

            // Event listener for close-cropper button
            const closeCropperBtn = document.querySelector('.close-cropper');
            if (closeCropperBtn) {
                closeCropperBtn.addEventListener('click', cancelCrop);
            }

            // Real-time form validation
            const form = document.getElementById('user-form');
            if (form) {
                form.addEventListener('input', function() {
                    const action = document.getElementById('form-submit').getAttribute('data-action');
                    const username = document.getElementById('username')?.value.trim();
                    const email = document.getElementById('email')?.value.trim();
                    const password = document.getElementById('password')?.value;
                    const submitBtn = document.getElementById('form-submit');

                    if (action === 'delete') {
                        submitBtn.disabled = false;
                        return;
                    }

                    const usernameError = document.getElementById('username-error');
                    const emailError = document.getElementById('email-error');
                    const passwordError = document.getElementById('password-error');

                    let isValid = true;

                    // Username validation
                    if (!username) {
                        usernameError.textContent = 'Username is required.';
                        isValid = false;
                    } else if (username.length < 3) {
                        usernameError.textContent = 'Username must be at least 3 characters.';
                        isValid = false;
                    } else {
                        usernameError.textContent = '';
                    }

                    // Email validation
                    if (!email) {
                        emailError.textContent = 'Email is required.';
                        isValid = false;
                    } else if (!/\S+@\S+\.\S+/.test(email)) {
                        emailError.textContent = 'Please enter a valid email address.';
                        isValid = false;
                    } else {
                        emailError.textContent = '';
                    }

                    // Password validation for new users
                    if (action === 'add' && !password) {
                        passwordError.textContent = 'Password is required.';
                        isValid = false;
                    } else if (password && password.length < 6) {
                        passwordError.textContent = 'Password must be at least 6 characters.';
                        isValid = false;
                    } else {
                        passwordError.textContent = '';
                    }

                    submitBtn.disabled = !isValid;
                });
            }

            // Search functionality with autocomplete
            $('#filterSearch').autocomplete({
                source: function(request, response) {
                    $.ajax({
                        url: 'user_management.php',
                        dataType: 'json',
                        data: {
                            term: request.term
                        },
                        success: function(data) {
                            response(data);
                        }
                    });
                },
                minLength: 2,
                select: function(event, ui) {
                    const itemsPerPage = document.getElementById('items_per_page').value;
                    const roleFilter = document.getElementById('role_filter').value;
                    window.location.href = `user_management.php?items_per_page=${itemsPerPage}&page=1&role_filter=${roleFilter}&search=${encodeURIComponent(ui.item.value)}`;
                }
            });

            // Handle search input on enter
            document.getElementById('filterSearch').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    const searchQuery = this.value.trim();
                    const itemsPerPage = document.getElementById('items_per_page').value;
                    const roleFilter = document.getElementById('role_filter').value;
                    window.location.href = `user_management.php?items_per_page=${itemsPerPage}&page=1&role_filter=${roleFilter}&search=${encodeURIComponent(searchQuery)}`;
                }
            });
        });

        // Handle AJAX for autocomplete
        <?php
        if (isset($_GET['term'])) {
            $term = '%' . trim($_GET['term']) . '%';
            $stmt = executeQuery($pdo, "
                SELECT DISTINCT username
                FROM users
                WHERE username LIKE ? OR email LIKE ?
                LIMIT 10", [$term, $term]);
            $results = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            echo 'if (isset($_GET["term"])) { echo json_encode(' . json_encode($results) . '); exit(); }';
        }
        ?>
    </script>
</body>

</html>