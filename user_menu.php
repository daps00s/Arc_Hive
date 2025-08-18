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