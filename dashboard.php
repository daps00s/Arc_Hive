<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
require 'notification.php';

// Security: Session & CSRF
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    error_log("Generated new CSRF token for user_id=$userId: {$_SESSION['csrf_token']}");
}

// User context
$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT) ?: 0;
$userRole = trim($_SESSION['role'] ?? 'client');

// Fetch user details and departments, including profile picture
$stmt = $pdo->prepare("
    SELECT u.user_id, u.username, u.role, u.profile_picture, d.department_id, d.department_name, d2.department_id AS parent_dept_id, d2.department_name AS parent_dept_name
    FROM users u
    LEFT JOIN user_department_assignments ud ON u.user_id = ud.user_id
    LEFT JOIN departments d ON ud.department_id = d.department_id
    LEFT JOIN departments d2 ON d.parent_department_id = d2.department_id
    WHERE u.user_id = ?
");
$stmt->execute([$userId]);
$userData = $stmt->fetchAll(PDO::FETCH_ASSOC);
$user = $userData[0] ?? null;
$userDepartments = array_map(fn($row) => [
    'department_id' => $row['department_id'],
    'department_name' => $row['department_name'],
    'parent_dept_id' => $row['parent_dept_id'],
    'parent_dept_name' => $row['parent_dept_name']
], $userData);

// Organize departments for display
$departmentList = [];
foreach ($userDepartments as $dept) {
    if ($dept['parent_dept_id']) {
        $departmentList[$dept['parent_dept_name']]['sub_departments'][] = $dept['department_name'];
    } else {
        $departmentList[$dept['department_name']]['sub_departments'] = [];
    }
}

if (!$user) {
    error_log("User not found for ID: $userId");
    header('Location: logout.php');
    exit;
}

// Determine profile picture path
$profilePicture = !empty($user['profile_picture']) && file_exists($user['profile_picture'])
    ? htmlspecialchars($user['profile_picture'])
    : 'user.jpg';

// Debug: Log user ID and query results
error_log("User ID: $userId, Role: $userRole, Profile Picture: $profilePicture");

// Fetch document types
$stmt = $pdo->prepare("SELECT document_type_id, type_name AS name FROM document_types ORDER BY type_name ASC");
$stmt->execute();
$docTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all users for recipients
$stmt = $pdo->prepare("SELECT user_id, username FROM users WHERE user_id != ? ORDER BY username ASC");
$stmt->execute([$userId]);
$allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch departments for recipients
$stmt = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name ASC");
$allDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Batch fetch data
$queries = [
    // Recent files
    [
        'sql' => "
            SELECT f.file_id, f.file_name, f.upload_date, f.copy_type, dt.type_name AS document_type
            FROM files f
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            WHERE f.user_id = ? AND f.file_status != 'deleted'
            ORDER BY f.upload_date DESC
            LIMIT 5",
        'params' => [$userId]
    ],
    // Notifications
   // Notifications
[
    'sql' => "
        SELECT t.transaction_id AS id, t.file_id, t.transaction_status, t.transaction_time AS timestamp, 
               t.description AS message, COALESCE(f.file_name, 'Unknown File') AS file_name
        FROM transactions t
        LEFT JOIN files f ON t.file_id = f.file_id
        WHERE t.user_id = ? AND t.transaction_type IN ('notification', 'file_sent', 'receive_notification')
        ORDER BY t.transaction_time DESC",
    'params' => [$userId]
],
    // Activity logs
    [
        'sql' => "
            SELECT t.transaction_id, t.description AS action, t.transaction_time AS timestamp
            FROM transactions t
            WHERE t.user_id = ? AND t.transaction_type IN ('file_upload', 'file_sent', 'file_request', 'file_approve', 'file_reject', 'file_delete')
            ORDER BY t.transaction_time DESC
            LIMIT 10",
        'params' => [$userId]
    ],
    // All uploaded files
    [
        'sql' => "
            SELECT f.file_id, f.file_name, f.upload_date, f.copy_type, dt.type_name AS document_type, f.file_type, f.file_size, d.department_name
            FROM files f
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            LEFT JOIN departments d ON f.department_id = d.department_id
            WHERE f.user_id = ? AND f.file_status != 'deleted'
            ORDER BY f.upload_date DESC",
        'params' => [$userId]
    ],
    // Files sent to me
    [
        'sql' => "
            SELECT DISTINCT f.file_id, f.file_name, f.upload_date, f.copy_type, dt.type_name AS document_type, f.file_type, f.file_size,
                           u.username AS sender_username
            FROM files f
            JOIN transactions t ON f.file_id = t.file_id
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            LEFT JOIN users u ON f.user_id = u.user_id
            WHERE t.user_id = ? AND t.transaction_type = 'file_sent' AND t.transaction_status IN ('pending', 'accepted')
            ORDER BY f.upload_date DESC",
        'params' => [$userId]
    ]
];

$results = [];
foreach ($queries as $index => $query) {
    try {
        $stmt = $pdo->prepare($query['sql']);
        $stmt->execute($query['params']);
        $results[$index] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Query $index returned " . count($results[$index]) . " rows");
    } catch (PDOException $e) {
        error_log("Query $index failed: " . $e->getMessage());
        $results[$index] = [];
    }
}

$recentFiles = $results[0] ?? [];
$notifications = $results[1] ?? [];
$activityLogs = $results[2] ?? [];
$filesUploaded = $results[3] ?? [];
$filesReceived = $results[4] ?? [];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <title>Arc-Hive Dashboard</title>
    <!-- Add Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <link rel="stylesheet" href="style/client-sidebar.css">
    <link rel="stylesheet" href="style/dashboard.css">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
<style>
    #preview {
        margin-top: 20px;
        border: 1px solid #ddd;
        padding: 10px;
        border-radius: 4px;
        min-height: 200px;
        text-align: center;
        background: #f8f8f8;
    }
    #preview img {
        max-width: 100%;
        max-height: 400px;
        object-fit: contain;
    }
    #preview pre {
        text-align: left;
        background: #f8f8f8;
        padding: 10px;
        border-radius: 4px;
        max-height: 400px;
        overflow-y: auto;
    }
    #preview embed {
        width: 100%;
        height: 400px;
    }
    #previewError {
        color: red;
        text-align: center;
    }
</style>
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
        <a href="folders.php" class="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']) === 'my-folder.php' ? 'active' : '') ?>" data-tooltip="My Folder" aria-label="My Folder">
            <i class="fas fa-folder"></i><span class="link-text">My Folder</span>
        </a>
        <a href="logout.php" class="logout-btn" data-tooltip="Logout" aria-label="Logout">
            <i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span>
        </a>
    </aside>
    <div class="main-container">
        <nav class="top-nav">
            <h1>Dashboard</h1>
            <div class="search-container">
                <form id="searchForm" action="search.php" method="GET">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="text" id="searchInput" name="query" placeholder="Search files and content..." aria-label="Search files and content">
                    <button type="submit" class="search-button" aria-label="Search"><i class="fas fa-search"></i></button>
                </form>
                <button class="btn notifications-toggle position-relative" data-bs-toggle="offcanvas" data-bs-target="#notificationsOffcanvas" aria-label="Toggle Notifications">
                    <i class="fas fa-bell"></i>
                    <?php if (count(array_filter($notifications, fn($n) => $n['transaction_status'] === 'pending')) > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notification-badge">
                            <?= count(array_filter($notifications, fn($n) => $n['transaction_status'] === 'pending')) ?>
                            <span class="visually-hidden">unread notifications</span>
                        </span>
                    <?php endif; ?>
                </button>
                <button id="activityLogTrigger" class="activity-log-toggle" aria-label="View Activity Log">
                    <i class="fas fa-history"></i>
                </button>
            </div>
        </nav>
        <div class="offcanvas offcanvas-end" tabindex="-1" id="notificationsOffcanvas" aria-labelledby="notificationsOffcanvasLabel">
    <div class="offcanvas-header">
        <h3 class="offcanvas-title" id="notificationsOffcanvasLabel">Notifications</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
            <button class="btn mark-all-read w-100 mb-3">Mark all as read</button>
            <?php if (empty($notifications)): ?>
                <p class="text-muted text-center no-notifications">No notifications available.</p>
            <?php else: ?>
                <div class="list-group">
<?php foreach ($notifications as $notification): ?>
    <?php $isClickable = $notification['transaction_type'] === 'file_sent' || $notification['transaction_type'] === 'receive_notification'; ?>
    <div class="list-group-item list-group-item-action <?= $notification['transaction_status'] === 'pending' ? 'notification-item-pending' : '' ?> <?= $isClickable ? 'clickable-notification' : '' ?>" 
        data-notification-id="<?= htmlspecialchars($notification['id']) ?>" 
        data-file-id="<?= htmlspecialchars($notification['file_id']) ?>"
        data-file-name="<?= htmlspecialchars($notification['file_name']) ?>">
        <p class="notification-message mb-1"><?= htmlspecialchars($notification['message']) ?> (File: <?= htmlspecialchars($notification['file_name']) ?>)</p>
        <small class="text-muted"><?= date('M d, Y H:i', strtotime($notification['timestamp'])) ?></small>
        <?php if ($notification['transaction_status'] === 'pending' && $notification['transaction_type'] === 'notification'): ?>
            <div class="notification-actions d-flex gap-2 mt-2">
                <button class="btn btn-sm btn-success accept-notification" data-notification-id="<?= htmlspecialchars($notification['id']) ?>" data-file-id="<?= htmlspecialchars($notification['file_id']) ?>">Accept</button>
                <button class="btn btn-sm btn-danger reject-notification" data-notification-id="<?= htmlspecialchars($notification['id']) ?>" data-file-id="<?= htmlspecialchars($notification['file_id']) ?>">Reject</button>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
        <main class="main-content">
            <section class="user-profile-time">
                <div class="profile-info">
                    <img src="<?= $profilePicture ?>" alt="Profile picture for <?= htmlspecialchars($user['username'] ?? 'User') ?>" class="profile-picture">
                    <div class="profile-details">
                        <p class="profile-name"><?= htmlspecialchars($user['username'] ?? 'Unknown User') ?></p>
                        <p class="profile-role"><?= htmlspecialchars($user['role'] ?? 'No Role') ?></p>
                        <div class="profile-department">
                            <strong>Departments:</strong>
                            <ul class="department-list">
                                <?php if (empty($departmentList)): ?>
                                    <li>No Department</li>
                                <?php else: ?>
                                    <?php foreach ($departmentList as $deptName => $data): ?>
                                        <li>
                                            <?= htmlspecialchars($deptName) ?>
                                            <?php if (!empty($data['sub_departments'])): ?>
                                                <ul class="sub-department-list">
                                                    <?php foreach ($data['sub_departments'] as $subDept): ?>
                                                        <li><?= htmlspecialchars($subDept) ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>
            <section class="action-buttons">
                <button id="uploadFileButton" class="action-button"><i class="fas fa-upload"></i> Upload File</button>
                <button id="sendFileButton" class="action-button"><i class="fas fa-paper-plane"></i> Send File</button>
                <button id="scannerButton" class="action-button"><i class="fas fa-qrcode"></i> Scan QR</button>
            </section>
            <section class="recent-files">
                <div class="recent-files">
                    <div class="tab-container">
                        <button class="tab-button active" data-tab="uploaded">Uploaded</button>
                        <button class="tab-button" data-tab="received">Received</button>
                        <button class="tab-button" data-tab="shared">Shared</button>
                    </div>
                    <div class="files-controls">
                        <select id="fileSort">
                            <option value="date-desc">Newest First</option>
                            <option value="date-asc">Oldest First</option>
                            <option value="department">By Department</option>
                            <option value="sub-department">By Sub-Department</option>
                            <option value="personal">Personal</option>
                        </select>
                        <div class="view-buttons">
                            <button class="view-button active" data-view="grid" aria-label="Grid View"><i class="fas fa-th"></i></button>
                            <button class="view-button" data-view="list" aria-label="List View"><i class="fas fa-list"></i></button>
                        </div>
                    </div>
                    <div id="uploadedTab" class="tab-content files-grid grid-view">
                        <?php if (empty($filesUploaded)): ?>
                            <p class="no-files">No files uploaded.</p>
                        <?php else: ?>
                            <?php foreach ($filesUploaded as $file): ?>
                                <div class="file-item" data-file-id="<?= htmlspecialchars($file['file_id']) ?>">
                                    <p class="file-name"><?= htmlspecialchars($file['file_name']) ?></p>
                                    <p class="file-meta">
                                        Type: <?= htmlspecialchars($file['document_type'] ?? 'Unknown') ?> | Uploaded: <?= date('M d, Y', strtotime($file['upload_date'])) ?>
                                    </p>
                                    <button class="kebab-menu"><i class="fas fa-ellipsis-v"></i></button>
                                    <div class="file-menu hidden">
                                        <button class="download-file">Download</button>
                                        <button class="rename-file">Rename</button>
                                        <button class="delete-file">Delete</button>
                                        <button class="share-file">Share</button>
                                        <button class="file-info">File Info</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div id="sentTab" class="tab-content files-grid grid-view hidden">
                        <!-- Populated via JavaScript -->
                    </div>
                    <div id="receivedTab" class="tab-content files-grid grid-view hidden">
                        <!-- Populated via JavaScript -->
                    </div>
            </section>
            <div id="activityLogModal" class="modal hidden">
                <div class="modal-content">
                    <h3>Activity Log</h3>
                    <button class="close-modal"><i class="fas fa-times"></i></button>
                    <div class="activity-log">
                        <?php foreach ($activityLogs as $log): ?>
                            <div class="log-item">
                                <p><?= htmlspecialchars($log['action']) ?></p>
                                <small><?= date('M d, Y H:i', strtotime($log['timestamp'])) ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div id="uploadModal" class="modal hidden">
                <div class="modal-content">
                    <h3>Upload File</h3>
                    <button class="close-modal"><i class="fas fa-times"></i></button>
                    <div class="progress-bar">
                        <div class="progress-step active" data-step="1">1. Select File</div>
                        <div class="progress-step" data-step="2">2. Details</div>
                    </div>
                    <form id="uploadForm" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="modal-step" data-step="1">
                            <div class="drag-drop-area">
                                <p>Drag & Drop files here or</p>
                                <button type="button" class="choose-file-button">Choose File</button>
                                <input type="file" id="fileInput" name="files[]" multiple hidden>
                            </div>
                            <div id="filePreviewArea"></div>
                            <button type="button" class="next-step">Next</button>
                        </div>
                        <div class="modal-step hidden" data-step="2">
                            <label>Access Level</label>
                            <select name="access_level" id="accessLevel">
                                <option value="personal">Personal</option>
                                <option value="department">Department</option>
                                <option value="sub_department">Sub-Department</option>
                            </select>
                            <div id="departmentContainer" class="hidden">
                                <label>Department</label>
                                <select id="departmentSelect" name="department_id">
                                    <option value="">Select Department</option>
                                    <!-- Populated dynamically via JS -->
                                </select>
                                <label>Sub-Department</label>
                                <select id="subDepartmentSelect" name="sub_department_id">
                                    <option value="">No Sub-Department</option>
                                    <!-- Populated dynamically via JS -->
                                </select>
                            </div>
                            <label>Document Type</label>
                            <select name="document_type_id" id="documentType">
                                <option value="">Select Document Type</option>
                                <?php foreach ($docTypes as $doc): ?>
                                    <option value="<?= htmlspecialchars($doc['document_type_id']) ?>">
                                        <?= htmlspecialchars($doc['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="docTypeFields"></div>
                            <label><input type="checkbox" id="hardcopyCheckbox" name="is_hardcopy"> This is a hardcopy</label>
                            <div id="hardcopyOptions" class="hidden">
                                <label><input type="radio" name="hardcopyOption" id="hardcopyOptionNew" value="new" checked> New Hardcopy</label>
                                <label><input type="radio" name="hardcopyOption" value="existing"> Existing Hardcopy</label>
                                <label for="hardcopyFileName">Hardcopy File Name</label>
                                <input type="text" id="hardcopyFileName" name="hardcopy_file_name" placeholder="Enter file name" disabled>
                                <div id="storageSuggestion" class="hidden"></div>
                                <div id="hardcopySearchContainer" class="hidden">
                                    <label for="physicalStorage">Physical Storage Location</label>
                                    <input type="text" id="physicalStorage" name="physical_storage" placeholder="Search storage location">
                                </div>
                            </div>
                            <button type="button" class="prev-step">Previous</button>
                            <button type="submit" class="submit-button">Upload</button>
                        </div>
                    </form>
                </div>
            </div>
           <div id="sendFileModal" class="modal hidden">
    <div class="modal-content">
        <h3>Send File</h3>
        <button class="close-modal"><i class="fas fa-times"></i></button>
        
        <!-- Progress Bar -->
        <div class="progress-bar">
            <div class="progress-step active" data-step="1">1. Select Files</div>
            <div class="progress-step" data-step="2">2. Choose Recipients</div>
        </div>
        
        <form id="sendFileForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            
            <!-- Step 1: File Selection -->
            <div class="modal-step" data-step="1">
                <div class="modal-section">
                    <label>Select Files to Send</label>
                    <input type="text" id="fileSearchInput" placeholder="Search your files..." class="search-input">
                    <div class="files-controls">
                        <select id="sendFileSort">
                            <option value="date-desc">Newest First</option>
                            <option value="date-asc">Oldest First</option>
                            <option value="department">By Department</option>
                            <option value="sub-department">By Sub-Department</option>
                            <option value="personal">Personal</option>
                        </select>
                        <div class="view-buttons">
                            <button type="button" class="view-button active" data-view="grid" aria-label="Grid View"><i class="fas fa-th"></i></button>
                            <button type="button" class="view-button" data-view="list" aria-label="List View"><i class="fas fa-list"></i></button>
                        </div>
                    </div>
                    <div class="files-grid scrollable grid-view" id="fileSelectionGrid" style="max-height: 300px; overflow-y: auto;">
                        <!-- Files will be loaded dynamically -->
                    </div>
                </div>
                <button type="button" class="next-step send-next-step">Next</button>
            </div>
            
            <!-- Step 2: Recipient Selection -->
            <div class="modal-step hidden" data-step="2">
                <div class="modal-section">
                    <label>Select Recipients</label>
                    <input type="text" id="recipientSearch" placeholder="Search users or departments...">
                    <div id="recipientList" class="recipient-list scrollable" style="max-height: 250px; overflow-y: auto;"></div>
                    
                    <div class="selected-recipients-container" style="margin-top: 15px;">
                        <label>Selected Recipients:</label>
                        <div id="selectedRecipients" class="selected-recipients-chips"></div>
                    </div>
                </div>
                
                <div class="modal-section">
                    <label>Message (Optional)</label>
                    <textarea name="message" placeholder="Add a message..." rows="4"></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="prev-step send-prev-step">Previous</button>
                    <button type="submit" class="submit-button">Send</button>
                </div>
            </div>
        </form>
    </div>
</div>
            <div id="qrScannerModal" class="modal hidden">
                <div class="modal-content">
                    <h3>Scan QR Code</h3>
                    <button class="close-modal" id="closeQrScannerModal"><i class="fas fa-times"></i></button>
                    <div class="modal-section">
                        <div id="reader" class="qr-reader" aria-label="QR code scanner"></div>
                        <div class="qr-controls">
                            <button type="button" id="chooseQrFileButton" class="choose-file-button">Choose Image</button>
                            <input type="file" id="qr-input-file" accept="image/*" hidden>
                            <button type="button" id="stopScannerButton" class="action-button">Stop Scanner</button>
                        </div>
                        <div id="result" class="scan-result" aria-live="polite"></div>
                        <div id="error" class="scan-error" aria-live="assertive"></div>
                    </div>
                </div>
            </div>
    <div id="recipientFileModal" class="modal hidden">
    <div class="modal-content">
        <h3>File Preview</h3>
        <button class="close-modal" id="closeRecipientFileModal"><i class="fas fa-times"></i></button>
        <div id="recipientFileModalPreview" class="file-preview" aria-label="File preview"></div>
        <div id="recipientFileModalButtons" class="action-buttons"></div>
    </div>
</div>

<style>
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1300;
        justify-content: center;
        align-items: center;
    }

    .modal:not(.hidden) {
        display: flex;
    }

    .modal-content {
        background: var(--card-background, #fff);
        width: 90%;
        max-width: 600px;
        max-height: 90vh;
        padding: 24px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        overflow-y: auto;
        box-sizing: border-box;
        position: relative;
    }

    .modal-content h3 {
        font-size: 1.5rem;
        font-weight: 600;
        margin: 0 0 16px;
        line-height: 1.2;
    }

    .close-modal {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 1.25rem;
        color: var(--text-secondary, #6c757d);
        position: absolute;
        top: 16px;
        right: 16px;
        padding: 8px;
        border-radius: 4px;
        transition: all 0.2s ease;
    }

    .close-modal:hover,
    .close-modal:focus {
        background: var(--danger-hover, #f8d7da);
        color: var(--danger-color, #dc3545);
        outline: none;
    }

    .file-preview {
        text-align: center;
        padding: 16px;
        border: 1px solid var(--border-color, #dee2e6);
        border-radius: 8px;
        background: var(--background-secondary, #f8f9fa);
        min-height: 200px;
        max-height: 500px;
        overflow-y: auto;
        box-sizing: border-box;
    }

    .file-preview img {
        max-width: 100%;
        max-height: 100%;
        border-radius: 6px;
        object-fit: contain;
    }

    .file-preview pre {
        text-align: left;
        background: #f8f8f8;
        padding: 10px;
        border-radius: 4px;
        max-height: 100%;
        overflow-y: auto;
        font-size: 0.875rem;
        color: var(--text-color, #212529);
        margin: 0;
    }

    .file-preview embed {
        width: 100%;
        height: 100%;
        max-height: 500px;
        border: none;
    }

    .file-preview p.error {
        color: var(--danger-color, #dc3545);
        font-size: 0.875rem;
        margin: 8px 0 0;
    }

    .file-preview i {
        font-size: 2.5rem;
        color: var(--text-secondary, #6c757d);
    }

    .file-preview p {
        font-size: 0.875rem;
        color: var(--text-secondary, #6c757d);
        margin: 8px 0 0;
    }

    .action-buttons {
        margin-top: 16px;
        text-align: center;
        display: flex;
        justify-content: center;
        gap: 10px;
    }

    .action-buttons .btn {
        padding: 8px 16px;
        font-size: 0.875rem;
    }

    @media (max-width: 768px) {
        .modal-content {
            width: 95%;
            max-height: 80vh;
        }

        .file-preview {
            max-height: 300px;
        }
    }
</style>

<script>
    // Close recipient file modal
    $('#closeRecipientFileModal').on('click', function() {
        $('#recipientFileModal').addClass('hidden');
    });
</script>
            <?php include 'templates/file_info_sidebar.php'; ?>
            <?php include 'templates/recipient_file_sidebar.php'; ?>
        </main>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="script/dashboard.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        // QR Scanner Modal Logic
        let html5QrcodeScanner = null;

        // Open QR Scanner Modal
        document.getElementById('scannerButton').addEventListener('click', () => {
            const qrModal = document.getElementById('qrScannerModal');
            qrModal.classList.remove('hidden');
            startScanner();
        });

        // Close QR Scanner Modal
        document.getElementById('closeQrScannerModal').addEventListener('click', () => {
            stopScanner();
            document.getElementById('qrScannerModal').classList.add('hidden');
        });

        // Stop Scanner Button
        document.getElementById('stopScannerButton').addEventListener('click', () => {
            stopScanner();
            document.getElementById('qrScannerModal').classList.add('hidden');
        });

        // Choose File Button
        document.getElementById('chooseQrFileButton').addEventListener('click', () => {
            document.getElementById('qr-input-file').click();
        });

        function onScanSuccess(decodedText, decodedResult) {
            document.getElementById('result').innerText = `Scanned: ${decodedText}`;
            document.getElementById('error').innerText = '';
            stopScanner();
        }

        function onScanFailure(error) {
            console.warn(`Scan error: ${error}`);
        }

        function startScanner() {
            document.getElementById('result').innerText = '';
            document.getElementById('error').innerText = '';
            html5QrcodeScanner = new Html5Qrcode("reader");
            const config = { fps: 10, qrbox: { width: 250, height: 250 } };
            html5QrcodeScanner.start(
                { facingMode: "environment" },
                config,
                onScanSuccess,
                onScanFailure
            ).catch(err => {
                document.getElementById('error').innerText = `Error starting scanner: ${err}`;
            });
        }

        function stopScanner() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.stop().then(() => {
                    html5QrcodeScanner.clear();
                    document.getElementById('result').innerText = 'Scanner stopped.';
                    document.getElementById('error').innerText = '';
                }).catch(err => {
                    document.getElementById('error').innerText = `Error stopping scanner: ${err}`;
                });
            }
        }

        // Handle file upload for QR code scanning
        document.getElementById('qr-input-file').addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                document.getElementById('result').innerText = '';
                document.getElementById('error').innerText = '';
                const html5Qrcode = new Html5Qrcode("reader");
                html5Qrcode.scanFile(file, true)
                    .then(decodedText => {
                        document.getElementById('result').innerText = `Scanned: ${decodedText}`;
                    })
                    .catch(err => {
                        document.getElementById('error').innerText = `Error scanning file: ${err}`;
                    });
            }
        });
    </script>
</body>
</html>