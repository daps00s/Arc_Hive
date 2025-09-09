<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
require 'notification.php';

// ---- Security: Session & CSRF ----
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ---- User context ----
$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT) ?: 0;
$userRole = trim($_SESSION['role'] ?? 'client');

// ---- Fetch user details + departments ----
$stmt = $pdo->prepare("
    SELECT u.user_id, u.username, u.role, u.profile_picture, 
           d.department_id, d.department_name, 
           d2.department_id AS parent_dept_id, d2.department_name AS parent_dept_name
    FROM users u
    LEFT JOIN user_department_assignments ud ON u.user_id = ud.user_id
    LEFT JOIN departments d ON ud.department_id = d.department_id
    LEFT JOIN departments d2 ON d.parent_department_id = d2.department_id
    WHERE u.user_id = ?
");
$stmt->execute([$userId]);
$userData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$user = $userData[0] ?? null;
if (!$user) {
    error_log("User not found for ID: $userId");
    header('Location: logout.php');
    exit;
}

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

// ---- Profile picture ----
$profilePicture = !empty($user['profile_picture']) && file_exists($user['profile_picture'])
    ? htmlspecialchars($user['profile_picture'])
    : 'user.jpg';

// ---- Fetch document types ----
$stmt = $pdo->query("SELECT document_type_id, type_name AS name FROM document_types ORDER BY type_name ASC");
$docTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Fetch recipients ----
$stmt = $pdo->prepare("SELECT user_id, username FROM users WHERE user_id != ? ORDER BY username ASC");
$stmt->execute([$userId]);
$allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name ASC");
$allDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Batch queries ----
$queries = [
    // recent files
    [
        'sql' => "
            SELECT f.file_id, f.file_name, f.upload_date, f.copy_type, dt.type_name AS document_type, f.file_path
            FROM files f
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            WHERE f.user_id = ? AND f.file_status != 'deleted'
            ORDER BY f.upload_date DESC
            LIMIT 5",
        'params' => [$userId]
    ],
    // notifications
    [
        'sql' => "
            SELECT t.transaction_id AS id, t.file_id, t.transaction_status, t.transaction_time AS timestamp, 
                   t.description AS message, COALESCE(f.file_name, 'Unknown File') AS file_name
            FROM transactions t
            LEFT JOIN files f ON t.file_id = f.file_id
            WHERE t.user_id = ? AND t.transaction_type = 'notification'
            ORDER BY t.transaction_time DESC",
        'params' => [$userId]
    ],
    // activity logs
    [
        'sql' => "
            SELECT t.transaction_id, t.description AS action, t.transaction_time AS timestamp
            FROM transactions t
            WHERE t.user_id = ? AND t.transaction_type IN ('file_upload', 'file_sent', 'file_request', 'file_approve', 'file_reject', 'file_delete')
            ORDER BY t.transaction_time DESC
            LIMIT 10",
        'params' => [$userId]
    ],
    // uploaded files
    [
        'sql' => "
            SELECT f.file_id, f.file_name, f.upload_date, f.copy_type, dt.type_name AS document_type, f.file_type, f.file_size, d.department_name, f.file_path
            FROM files f
            LEFT JOIN document_types dt ON f.document_type_id = dt.document_type_id
            LEFT JOIN departments d ON f.department_id = d.department_id
            WHERE f.user_id = ? AND f.file_status != 'deleted'
            ORDER BY f.upload_date DESC",
        'params' => [$userId]
    ],
    // received files
    [
        'sql' => "
            SELECT DISTINCT f.file_id, f.file_name, f.upload_date, f.copy_type, dt.type_name AS document_type, f.file_type, f.file_size,
                           u.username AS sender_username, f.file_path
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
    } catch (PDOException $e) {
        error_log("Query $index failed: " . $e->getMessage());
        $results[$index] = [];
    }
}

$recentFiles   = $results[0] ?? [];
$notifications = $results[1] ?? [];
$activityLogs  = $results[2] ?? [];
$filesUploaded = $results[3] ?? [];
$filesReceived = $results[4] ?? [];

?>
<!-- ---- Rest of your HTML (unchanged) ---- -->

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <title>Arc-Hive Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <link rel="stylesheet" href="style/client-sidebar.css">
    <link rel="stylesheet" href="style/dashboard.css">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        .file-preview-sidebar {
            position: fixed;
            top: 0;
            right: -400px;
            width: 400px;
            height: 100%;
            background: #fff;
            box-shadow: -2px 0 5px rgba(0,0,0,0.2);
            transition: right 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
            padding: 20px;
        }
        .file-preview-sidebar.active {
            right: 0;
        }
        .file-preview-sidebar .close-preview {
            position: absolute;
            top: 10px;
            right: 10px;
            background: none;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
        }
        .file-preview-sidebar h3 {
            margin: 0 0 20px;
            font-size: 1.5em;
        }
        .file-preview-sidebar .preview-content img,
        .file-preview-sidebar .preview-content iframe {
            max-width: 100%;
            height: auto;
            margin-bottom: 20px;
        }
        .file-preview-sidebar .metadata {
            font-size: 0.9em;
        }
        .file-preview-sidebar .metadata p {
            margin: 5px 0;
        }
        .file-preview-sidebar .no-preview {
            color: #666;
            font-style: italic;
        }
        .file-item {
            cursor: pointer;
        }
        .file-item:hover {
            background-color: #f0f0f0;
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
                <button class="notifications-toggle" aria-label="Toggle Notifications">
                    <i class="fas fa-bell"></i>
                    <?php if (count(array_filter($notifications, fn($n) => $n['transaction_status'] === 'pending')) > 0): ?>
                        <span class="notification-badge"><?= count(array_filter($notifications, fn($n) => $n['transaction_status'] === 'pending')) ?></span>
                    <?php endif; ?>
                </button>
                <button id="activityLogTrigger" class="activity-log-toggle" aria-label="View Activity Log">
                    <i class="fas fa-history"></i>
                </button>
            </div>
        </nav>
        <div class="notifications-sidebar hidden">
            <h3>Notifications</h3>
            <button class="mark-all-read">Mark all as read</button>
            <?php if (empty($notifications)): ?>
                <p class="no-notifications">No notifications available.</p>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification-item <?= $notification['transaction_status'] === 'pending' ? 'pending' : '' ?>" data-notification-id="<?= htmlspecialchars($notification['id']) ?>" data-file-id="<?= htmlspecialchars($notification['file_id']) ?>">
                        <p><?= htmlspecialchars($notification['message']) ?> (File: <?= htmlspecialchars($notification['file_name']) ?>)</p>
                        <small><?= date('M d, Y H:i', strtotime($notification['timestamp'])) ?></small>
                        <?php if ($notification['transaction_status'] === 'pending'): ?>
                            <div class="notification-actions">
                                <button class="accept-file">Accept</button>
                                <button class="deny-file">Deny</button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
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
                        <?php if (empty($filesReceived)): ?>
                            <p class="no-files">No files received.</p>
                        <?php else: ?>
                            <?php foreach ($filesReceived as $file): ?>
                                <div class="file-item" data-file-id="<?= htmlspecialchars($file['file_id']) ?>">
                                    <p class="file-name"><?= htmlspecialchars($file['file_name']) ?></p>
                                    <p class="file-meta">
                                        Type: <?= htmlspecialchars($file['document_type'] ?? 'Unknown') ?> | 
                                        Uploaded: <?= date('M d, Y', strtotime($file['upload_date'])) ?> | 
                                        Sender: <?= htmlspecialchars($file['sender_username'] ?? 'Unknown') ?>
                                    </p>
                                    <button class="kebab-menu"><i class="fas fa-ellipsis-v"></i></button>
                                    <div class="file-menu hidden">
                                        <button class="download-file">Download</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
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
                    <form id="sendFileForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="modal-section">
                            <label>Select Files</label>
                            <div class="files-grid scrollable" id="fileSelectionGrid">
                                <?php if (empty($filesUploaded)): ?>
                                    <p class="no-files">No files available to send.</p>
                                <?php else: ?>
                                    <?php foreach ($filesUploaded as $file): ?>
                                        <div class="file-item selectable" data-file-id="<?= htmlspecialchars($file['file_id']) ?>">
                                            <p class="file-name"><?= htmlspecialchars($file['file_name']) ?></p>
                                            <p class="file-meta">
                                                Type: <?= htmlspecialchars($file['document_type'] ?? 'Unknown') ?> |
                                                Uploaded: <?= date('M d, Y', strtotime($file['upload_date'])) ?> |
                                                Dept: <?= htmlspecialchars($file['department_name'] ?? 'None') ?>
                                            </p>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
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
                        </div>
                        <div class="modal-section">
                            <label>Select Recipients</label>
                            <input type="text" id="recipientSearch" placeholder="Search users or departments...">
                            <div id="recipientList" class="recipient-list"></div>
                        </div>
                        <div class="modal-section">
                            <label>Message (Optional)</label>
                            <textarea name="message" placeholder="Add a message..." rows="4"></textarea>
                        </div>
                        <div class="modal-actions">
                            <button type="submit" class="submit-button">Send</button>
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
            <div id="filePreviewSidebar" class="file-preview-sidebar hidden">
                <button class="close-preview" aria-label="Close Preview"><i class="fas fa-times"></i></button>
                <h3>File Preview</h3>
                <div class="preview-content"></div>
                <div class="metadata">
                    <p><strong>File Name:</strong> <span id="previewFileName"></span></p>
                    <p><strong>Document Type:</strong> <span id="previewDocumentType"></span></p>
                    <p><strong>Upload Date:</strong> <span id="previewUploadDate"></span></p>
                    <p><strong>File Type:</strong> <span id="previewFileType"></span></p>
                    <p><strong>File Size:</strong> <span id="previewFileSize"></span></p>
                    <p><strong>Department:</strong> <span id="previewDepartment"></span></p>
                    <p><strong>Copy Type:</strong> <span id="previewCopyType"></span></p>
                </div>
                <div id="previewError" class="no-preview" style="display:none;"></div>
            </div>
            <?php include 'templates/file_info_sidebar.php'; ?>
        </main>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="script/dashboard.js"></script>
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

        // File Preview Logic
        const filePreviewSidebar = document.getElementById('filePreviewSidebar');
        const closePreviewButton = document.querySelector('.close-preview');
        const previewContent = document.querySelector('.file-preview-sidebar .preview-content');
        const previewError = document.getElementById('previewError');

        // Close preview sidebar
        closePreviewButton.addEventListener('click', () => {
            filePreviewSidebar.classList.remove('active');
            previewError.style.display = 'none';
        });

        // Handle file item click for preview
        document.querySelectorAll('.file-item').forEach(item => {
            item.addEventListener('click', async (e) => {
                // Prevent preview when clicking kebab menu
                if (e.target.closest('.kebab-menu') || e.target.closest('.file-menu')) {
                    return;
                }

                const fileId = item.dataset.fileId;
                previewError.style.display = 'none';
                filePreviewSidebar.classList.add('active');

                // Fetch file details via AJAX
                try {
                    const response = await fetch(`get_file_details.php?file_id=${fileId}`, {
                        headers: {
                            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                        }
                    });
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    const file = await response.json();

                    if (file.error) {
                        throw new Error(file.error);
                    }

                    // Populate metadata
                    document.getElementById('previewFileName').textContent = file.file_name || 'Unknown';
                    document.getElementById('previewDocumentType').textContent = file.document_type || 'Unknown';
                    document.getElementById('previewUploadDate').textContent = file.upload_date ? new Date(file.upload_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Unknown';
                    document.getElementById('previewFileType').textContent = file.file_type || 'Unknown';
                    document.getElementById('previewFileSize').textContent = file.file_size ? `${(file.file_size / 1024).toFixed(2)} KB` : 'Unknown';
                    document.getElementById('previewDepartment').textContent = file.department_name || 'None';
                    document.getElementById('previewCopyType').textContent = file.copy_type || 'Unknown';

                    // Display file preview
                    previewContent.innerHTML = '';
                    if (file.file_type && file.file_path) {
                        if (file.file_type.startsWith('image/')) {
                            previewContent.innerHTML = `<img src="${file.file_path}" alt="File preview" style="max-height: 300px;">`;
                        } else if (file.file_type === 'application/pdf') {
                            previewContent.innerHTML = `<iframe src="${file.file_path}" style="width:100%; height:300px;" frameborder="0"></iframe>`;
                        } else {
                            previewContent.innerHTML = '<p class="no-preview">Preview not available for this file type.</p>';
                        }
                    } else {
                        previewContent.innerHTML = '<p class="no-preview">No preview available.</p>';
                    }
                } catch (error) {
                    console.error('Preview error:', error);
                    previewContent.innerHTML = '';
                    previewError.textContent = `Failed to load preview: ${error.message}. Check console for details.`;
                    previewError.style.display = 'block';
                }
            });
        });
    </script>
</body>
</html>