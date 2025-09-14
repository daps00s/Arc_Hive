$(document).ready(function() {
    // Constants
    const ITEMS_PER_PAGE = 20;
    const CSRF_TOKEN = $('meta[name="csrf-token"]').attr('content');
    const API_ENDPOINT = 'api/files.php';

    // State
    let currentPersonalPage = 1;
    let currentDepartmentPage = 1;
    let currentPersonalTab = 'uploaded';
    let currentDepartmentId = '';
    let currentView = 'grid';
    let personalFilters = {
        search: '',
        type: '',
        dateFrom: '',
        dateTo: '',
        access: '',
        sort: 'date-desc'
    };
    let departmentFilters = {
        search: '',
        type: '',
        dateFrom: '',
        dateTo: '',
        access: '',
        sort: 'date-desc'
    };

    // Initialize
    function init() {
        setupEventListeners();
        updatePagination('personal');
        updatePagination('department');
        loadDepartmentTree();
        setViewMode(currentView);
    }

    // Event Listeners
    function setupEventListeners() {
        // View Tabs
        $('.view-tab').click(function() {
            $('.view-tab').removeClass('active').attr('aria-selected', 'false');
            $(this).addClass('active').attr('aria-selected', 'true');
            $('.files-section').removeClass('active').attr('aria-hidden', 'true');
            $(`#${$(this).data('view')}FilesSection`).addClass('active').attr('aria-hidden', 'false');
            updatePagination($(this).data('view'));
        });

        // Personal Tabs
        $('.tab-buttons .tab-button').click(function() {
            $('.tab-buttons .tab-button').removeClass('active').attr('aria-selected', 'false');
            $(this).addClass('active').attr('aria-selected', 'true');
            $('.tab-content').removeClass('active').attr('aria-hidden', 'true');
            $(`#${$(this).data('tab')}Tab`).addClass('active').attr('aria-hidden', 'false');
            currentPersonalTab = $(this).data('tab');
            currentPersonalPage = 1;
            loadPersonalFiles();
        });

        // View Toggle
        $('.view-toggle .toggle-button').click(function() {
            currentView = $(this).data('view');
            setViewMode(currentView);
        });

        // Personal Filters
        $('#personalApplyFilters').click(loadPersonalFiles);
        $('#personalResetFilters').click(function() {
            $('#personalSearchBar').val('');
            $('#personalFileSort').val('date-desc');
            $('#personalFilterType').val('');
            $('#personalFilterDateFrom').val('');
            $('#personalFilterDateTo').val('');
            $('#personalFilterAccess').val('');
            personalFilters = { search: '', type: '', dateFrom: '', dateTo: '', access: '', sort: 'date-desc' };
            currentPersonalPage = 1;
            loadPersonalFiles();
        });

        // Department Filters
        $('#departmentApplyFilters').click(loadDepartmentFiles);
        $('#departmentResetFilters').click(function() {
            $('#departmentSearchBar').val('');
            $('#departmentFileSort').val('date-desc');
            $('#departmentFilterType').val('');
            $('#departmentFilterDateFrom').val('');
            $('#departmentFilterDateTo').val('');
            $('#departmentFilterAccess').val('');
            departmentFilters = { search: '', type: '', dateFrom: '', dateTo: '', access: '', sort: 'date-desc' };
            currentDepartmentPage = 1;
            loadDepartmentFiles();
        });

        // Pagination
        $('#personalPrevPage').click(function() {
            if (currentPersonalPage > 1) {
                currentPersonalPage--;
                loadPersonalFiles();
            }
        });

        $('#personalNextPage').click(function() {
            currentPersonalPage++;
            loadPersonalFiles();
        });

        $('#departmentPrevPage').click(function() {
            if (currentDepartmentPage > 1) {
                currentDepartmentPage--;
                loadDepartmentFiles();
            }
        });

        $('#departmentNextPage').click(function() {
            currentDepartmentPage++;
            loadDepartmentFiles();
        });

        // Department Selection
        $('#departmentSelect').change(function() {
            currentDepartmentId = $(this).val();
            currentDepartmentPage = 1;
            updateBreadcrumb();
            loadDepartmentFiles();
        });

        // File Actions
        $(document).on('click', '.kebab-menu', function(e) {
            e.stopPropagation();
            const $menu = $(this).siblings('.file-menu');
            $('.file-menu').not($menu).addClass('hidden').attr('aria-hidden', 'true');
            $menu.toggleClass('hidden').attr('aria-hidden', !$menu.hasClass('hidden'));
            $(this).attr('aria-expanded', !$menu.hasClass('hidden'));
        });

        $(document).click(function(e) {
            if (!$(e.target).closest('.kebab-menu, .file-menu').length) {
                $('.file-menu').addClass('hidden').attr('aria-hidden', 'true');
                $('.kebab-menu').attr('aria-expanded', 'false');
            }
        });

        // File Menu Actions
        $(document).on('click', '.download-file', function() {
            const fileId = $(this).closest('.file-item').data('file-id');
            downloadFile(fileId);
        });

        $(document).on('click', '.rename-file', function() {
            const fileId = $(this).closest('.file-item').data('file-id');
            const fileName = $(this).closest('.file-item').find('.file-name').text();
            openRenameModal(fileId, fileName);
        });

        $(document).on('click', '.share-file', function() {
            const fileId = $(this).closest('.file-item').data('file-id');
            openShareModal(fileId);
        });

        $(document).on('click', '.delete-file', function() {
            const fileId = $(this).closest('.file-item').data('file-id');
            openConfirmModal(fileId);
        });

        $(document).on('click', '.file-info', function() {
            const fileId = $(this).closest('.file-item').data('file-id');
            showFileInfo(fileId);
        });

        $(document).on('click', '.accept-file', function() {
            const fileId = $(this).closest('.file-item').data('file-id');
            handleFileAction(fileId, 'accept');
        });

        $(document).on('click', '.deny-file', function() {
            const fileId = $(this).closest('.file-item').data('file-id');
            handleFileAction(fileId, 'deny');
        });

        // Form Submissions
        $('#uploadFileForm').submit(handleFileUpload);
        $('#renameForm').submit(handleRename);
        $('#sendFileForm').submit(handleShare);
    }

    // View Mode
    function setViewMode(view) {
        $('.view-toggle .toggle-button').removeClass('active');
        $(`.view-toggle .toggle-button[data-view="${view}"]`).addClass('active');
        $('.files-grid').removeClass('grid-view list-view').addClass(`${view}-view`);
    }

    // Notifications
    function showNotification(message, type) {
        const $notification = $(`<div class="notification ${type}" role="alert"><span>${message}</span><button class="close-notification" aria-label="Close notification">&times;</button></div>`);
        $('#notifications').append($notification);
        setTimeout(() => $notification.fadeOut(300, () => $notification.remove()), 5000);
        $notification.find('.close-notification').click(() => $notification.remove());
    }

    // Modal Handling
    window.openModal = function(modalId) {
        $(`#${modalId}`).addClass('open').attr('aria-hidden', 'false');
        $(`#${modalId} input:first`).focus();
    };

    window.closeModal = function(modalId) {
        $(`#${modalId}`).removeClass('open').attr('aria-hidden', 'true');
        $('.kebab-menu').attr('aria-expanded', 'false');
        $('.file-menu').addClass('hidden').attr('aria-hidden', 'true');
    };

    // Load Personal Files
    function loadPersonalFiles() {
        personalFilters.search = $('#personalSearchBar').val().trim();
        personalFilters.type = $('#personalFilterType').val();
        personalFilters.dateFrom = $('#personalFilterDateFrom').val();
        personalFilters.dateTo = $('#personalFilterDateTo').val();
        personalFilters.access = $('#personalFilterAccess').val();
        personalFilters.sort = $('#personalFileSort').val();

        $('body').addClass('loading');
        $.ajax({
            url: API_ENDPOINT,
            method: 'GET',
            data: {
                section: 'personal',
                tab: currentPersonalTab,
                page: currentPersonalPage,
                per_page: ITEMS_PER_PAGE,
                search: personalFilters.search,
                type: personalFilters.type,
                date_from: personalFilters.dateFrom,
                date_to: personalFilters.dateTo,
                access: personalFilters.access,
                sort: personalFilters.sort,
                csrf_token: CSRF_TOKEN
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    renderFiles(`#${currentPersonalTab}Tab`, response.files, response.tab);
                    updatePagination('personal', response.total_pages, response.current_page);
                } else {
                    showNotification(response.message || 'Failed to load files.', 'error');
                    $(`#${currentPersonalTab}Tab`).html('<div class="no-files-message"><i class="fas fa-folder-open"></i><p>Error loading files.</p></div>');
                }
            },
            error: function(xhr) {
                showNotification('Network error. Please try again.', 'error');
                $(`#${currentPersonalTab}Tab`).html('<div class="no-files-message"><i class="fas fa-folder-open"></i><p>Error loading files.</p></div>');
            },
            complete: function() {
                $('body').removeClass('loading');
            }
        });
    }

    // Load Department Files
    function loadDepartmentFiles() {
        if (!currentDepartmentId) {
            $('#departmentTab').html('<div class="no-files-message"><i class="fas fa-building"></i><p>Select a department to view files.</p></div>');
            updatePagination('department', 0, 0);
            return;
        }

        departmentFilters.search = $('#departmentSearchBar').val().trim();
        departmentFilters.type = $('#departmentFilterType').val();
        departmentFilters.dateFrom = $('#departmentFilterDateFrom').val();
        departmentFilters.dateTo = $('#departmentFilterDateTo').val();
        departmentFilters.access = $('#departmentFilterAccess').val();
        departmentFilters.sort = $('#departmentFileSort').val();

        $('body').addClass('loading');
        $.ajax({
            url: API_ENDPOINT,
            method: 'GET',
            data: {
                section: 'department',
                department_id: currentDepartmentId,
                page: currentDepartmentPage,
                per_page: ITEMS_PER_PAGE,
                search: departmentFilters.search,
                type: departmentFilters.type,
                date_from: departmentFilters.dateFrom,
                date_to: departmentFilters.dateTo,
                access: departmentFilters.access,
                sort: departmentFilters.sort,
                csrf_token: CSRF_TOKEN
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    renderFiles('#departmentTab', response.files, 'department');
                    updatePagination('department', response.total_pages, response.current_page);
                } else {
                    showNotification(response.message || 'Failed to load department files.', 'error');
                    $('#departmentTab').html('<div class="no-files-message"><i class="fas fa-building"></i><p>Error loading department files.</p></div>');
                }
            },
            error: function(xhr) {
                showNotification('Network error. Please try again.', 'error');
                $('#departmentTab').html('<div class="no-files-message"><i class="fas fa-building"></i><p>Error loading department files.</p></div>');
            },
            complete: function() {
                $('body').removeClass('loading');
            }
        });
    }

    // Render Files
    function renderFiles(container, files, tab) {
        const $container = $(container);
        $container.empty();
        if (!files || files.length === 0) {
            $container.html(`<div class="no-files-message"><i class="fas fa-${tab === 'received' ? 'inbox' : tab === 'shared' ? 'share' : 'folder-open'}"></i><p>No ${tab} files found.</p></div>`);
            return;
        }

        files.forEach(file => {
            const fileTypeIcon = (file.file_type || 'alt').toLowerCase().replace(/pdf|doc|xls/g, { 'pdf': 'pdf', 'doc': 'word', 'xls': 'chart-bar' });
            let metaContent = '';
            if (tab === 'received') {
                metaContent = `
                    <span>From: ${escapeHtml(file.sender_username || 'Unknown')}</span>
                    <span>${formatDate(file.upload_date)}</span>
                    <span>${formatFileSize(file.file_size)}</span>
                `;
            } else if (tab === 'shared') {
                metaContent = `
                    <span>To: ${escapeHtml(file.recipient_username || 'Unknown')}</span>
                    <span>${formatDate(file.upload_date)}</span>
                    <span>${formatFileSize(file.file_size)}</span>
                `;
            } else {
                metaContent = `
                    <span>${escapeHtml(file.document_type || 'Unknown Type')}</span>
                    <span>${formatDate(file.upload_date)}</span>
                    <span>${formatFileSize(file.file_size)}</span>
                `;
            }

            const menuItems = tab === 'received' ? `
                <button class="menu-item download-file" role="menuitem" tabindex="-1">Download</button>
                <button class="menu-item accept-file" role="menuitem" tabindex="-1">Accept</button>
                <button class="menu-item deny-file" role="menuitem" tabindex="-1">Deny</button>
                <button class="menu-item file-info" role="menuitem" tabindex="-1">Info</button>
            ` : tab === 'shared' ? `
                <button class="menu-item download-file" role="menuitem" tabindex="-1">Download</button>
                <button class="menu-item file-info" role="menuitem" tabindex="-1">Info</button>
            ` : `
                <button class="menu-item download-file" role="menuitem" tabindex="-1">Download</button>
                <button class="menu-item rename-file" role="menuitem" tabindex="-1">Rename</button>
                <button class="menu-item share-file" role="menuitem" tabindex="-1">Share</button>
                <button class="menu-item delete-file" role="menuitem" tabindex="-1">Delete</button>
                <button class="menu-item file-info" role="menuitem" tabindex="-1">Info</button>
            `;

            const fileHtml = `
                <div class="file-item" data-file-id="${escapeHtml(file.file_id)}" tabindex="0" role="button" aria-label="File: ${escapeHtml(file.file_name)}">
                    <div class="file-icon">
                        <i class="fas fa-file-${fileTypeIcon}" aria-hidden="true"></i>
                    </div>
                    <div class="file-name">${escapeHtml(file.file_name)}</div>
                    <div class="file-meta">
                        ${metaContent}
                    </div>
                    <button class="kebab-menu" aria-label="More options for ${escapeHtml(file.file_name)}" aria-expanded="false">
                        <i class="fas fa-ellipsis-v" aria-hidden="true"></i>
                    </button>
                    <div class="file-menu hidden" role="menu">
                        ${menuItems}
                    </div>
                </div>
            `;
            $container.append(fileHtml);
        });
    }

    // Department Tree
    function loadDepartmentTree() {
        $.ajax({
            url: API_ENDPOINT,
            method: 'GET',
            data: { action: 'get_department_tree', csrf_token: CSRF_TOKEN },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    renderDepartmentTree(response.departments);
                } else {
                    showNotification('Failed to load department tree.', 'error');
                }
            },
            error: function() {
                showNotification('Network error loading department tree.', 'error');
            }
        });
    }

    function renderDepartmentTree(departments) {
        const $deptTree = $('#departmentTree').empty();
        const $subDeptTree = $('#subDepartmentTree').empty();
        const $dirTree = $('#directoryTree').empty();

        departments.forEach(dept => {
            if (!dept.parent_id) {
                const $deptItem = $(`
                    <li class="dept-item" data-dept-id="${escapeHtml(dept.id)}" aria-expanded="false" role="treeitem">
                        <div class="tree-node">
                            <i class="fas fa-chevron-right expand-icon" aria-hidden="true"></i>
                            <span>${escapeHtml(dept.name)}</span>
                        </div>
                    </li>
                `);
                const $children = $('<ul class="children" role="group"></ul>');
                departments.filter(sub => sub.parent_id === dept.id).forEach(subDept => {
                    const $subDeptItem = $(`
                        <li class="sub-dept-item" data-dept-id="${escapeHtml(subDept.id)}" aria-expanded="false" role="treeitem">
                            <div class="tree-node">
                                <i class="fas fa-chevron-right expand-icon" aria-hidden="true"></i>
                                <span>${escapeHtml(subDept.name)}</span>
                            </div>
                        </li>
                    `);
                    $children.append($subDeptItem);
                });
                if ($children.children().length) {
                    $deptItem.append($children);
                }
                $deptTree.append($deptItem);
            }
        });

        $deptTree.on('click', '.dept-item', function(e) {
            e.stopPropagation();
            const $item = $(this);
            const isExpanded = $item.attr('aria-expanded') === 'true';
            $item.attr('aria-expanded', !isExpanded);
            $item.find('.children').toggleClass('expanded', !isExpanded);
            currentDepartmentId = $item.data('dept-id');
            updateBreadcrumb();
            loadDepartmentFiles();
        });

        $subDeptTree.on('click', '.sub-dept-item', function(e) {
            e.stopPropagation();
            currentDepartmentId = $(this).data('dept-id');
            updateBreadcrumb();
            loadDepartmentFiles();
        });
    }

    // Breadcrumb
    function updateBreadcrumb() {
        const $breadcrumb = $('#breadcrumbPath').empty();
        if (!currentDepartmentId) return;

        $.ajax({
            url: API_ENDPOINT,
            method: 'GET',
            data: { action: 'get_department_path', department_id: currentDepartmentId, csrf_token: CSRF_TOKEN },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    response.path.forEach((node, index) => {
                        if (index > 0) {
                            $breadcrumb.append('<i class="fas fa-chevron-right" aria-hidden="true"></i>');
                        }
                        $breadcrumb.append(`<a href="#" data-dept-id="${escapeHtml(node.id)}">${escapeHtml(node.name)}</a>`);
                    });
                }
            }
        });

        $breadcrumb.on('click', 'a', function(e) {
            e.preventDefault();
            currentDepartmentId = $(this).data('dept-id');
            loadDepartmentFiles();
            updateBreadcrumb();
        });
    }

    // Pagination
    function updatePagination(section, totalPages = 0, currentPage = 1) {
        const $prev = $(`#${section}PrevPage`);
        const $next = $(`#${section}NextPage`);
        const $info = $(`#${section}PageInfo`);

        $prev.prop('disabled', currentPage <= 1);
        $next.prop('disabled', totalPages <= 0 || currentPage >= totalPages);
        $info.text(totalPages > 0 ? `Page ${currentPage} of ${totalPages}` : 'No pages');
    }

    // File Actions
    function downloadFile(fileId) {
        window.location.href = `api/download.php?file_id=${fileId}&csrf_token=${CSRF_TOKEN}`;
    }

    function openRenameModal(fileId, fileName) {
        $('#renameForm [name="file_id"]').val(fileId);
        $('#newFileName').val(fileName);
        openModal('renameModal');
    }

    function openShareModal(fileId) {
        $('#sendFileForm [name="file_id"]').val(fileId);
        loadRecipients();
        openModal('sendFileModal');
    }

    function openConfirmModal(fileId) {
        $('#confirmModal').data('file-id', fileId);
        openModal('confirmModal');
    }

    window.deleteFile = function(fileId) {
        $.ajax({
            url: API_ENDPOINT,
            method: 'POST',
            data: { action: 'delete', file_id: fileId, csrf_token: CSRF_TOKEN },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showNotification('File deleted successfully.', 'success');
                    loadPersonalFiles();
                    loadDepartmentFiles();
                } else {
                    showNotification(response.message || 'Failed to delete file.', 'error');
                }
                closeModal('confirmModal');
            },
            error: function() {
                showNotification('Network error. Please try again.', 'error');
                closeModal('confirmModal');
            }
        });
    };

    function showFileInfo(fileId) {
        $.ajax({
            url: API_ENDPOINT,
            method: 'GET',
            data: { action: 'get_file_info', file_id: fileId, csrf_token: CSRF_TOKEN },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const info = response.file;
                    const $sidebar = $('#fileInfoSidebar');
                    $sidebar.find('.file-name').text(info.file_name);
                    $sidebar.find('.file-type').text(info.document_type || 'Unknown');
                    $sidebar.find('.file-size').text(formatFileSize(info.file_size));
                    $sidebar.find('.upload-date').text(formatDate(info.upload_date));
                    $sidebar.find('.department').text(info.department_name || 'None');
                    $sidebar.find('.owner').text(info.owner_username || 'Unknown');
                    $sidebar.addClass('open').attr('aria-hidden', 'false');
                } else {
                    showNotification(response.message || 'Failed to load file info.', 'error');
                }
            },
            error: function() {
                showNotification('Network error. Please try again.', 'error');
            }
        });
    }

    function loadRecipients() {
        $.ajax({
            url: API_ENDPOINT,
            method: 'GET',
            data: { action: 'get_recipients', csrf_token: CSRF_TOKEN },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const $recipientList = $('#recipientList').empty();
                    response.users.forEach(user => {
                        $recipientList.append(`
                            <div class="recipient-item">
                                <input type="checkbox" name="recipients[]" value="user:${user.user_id}" id="recipient-${user.user_id}">
                                <label for="recipient-${user.user_id}">${escapeHtml(user.username)}</label>
                            </div>
                        `);
                    });
                    response.departments.forEach(dept => {
                        $recipientList.append(`
                            <div class="recipient-item">
                                <input type="checkbox" name="recipients[]" value="dept:${dept.department_id}" id="recipient-dept-${dept.department_id}">
                                <label for="recipient-dept-${dept.department_id}">${escapeHtml(dept.department_name)} (Dept)</label>
                            </div>
                        `);
                    });
                }
            }
        });
    }

    function handleFileUpload(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        formData.append('action', 'upload');
        $('body').addClass('loading');
        $.ajax({
            url: API_ENDPOINT,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showNotification('File uploaded successfully.', 'success');
                    loadPersonalFiles();
                    closeModal('uploadFileModal');
                    e.target.reset();
                } else {
                    showNotification(response.message || 'Failed to upload file.', 'error');
                }
            },
            error: function() {
                showNotification('Network error. Please try again.', 'error');
            },
            complete: function() {
                $('body').removeClass('loading');
            }
        });
    }

    function handleRename(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        formData.append('action', 'rename');
        $.ajax({
            url: API_ENDPOINT,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showNotification('File renamed successfully.', 'success');
                    loadPersonalFiles();
                    loadDepartmentFiles();
                    closeModal('renameModal');
                    e.target.reset();
                } else {
                    showNotification(response.message || 'Failed to rename file.', 'error');
                }
            },
            error: function() {
                showNotification('Network error. Please try again.', 'error');
            }
        });
    }

    function handleShare(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        formData.append('action', 'share');
        $.ajax({
            url: API_ENDPOINT,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showNotification('File shared successfully.', 'success');
                    loadPersonalFiles();
                    closeModal('sendFileModal');
                    e.target.reset();
                } else {
                    showNotification(response.message || 'Failed to share file.', 'error');
                }
            },
            error: function() {
                showNotification('Network error. Please try again.', 'error');
            }
        });
    }

    function handleFileAction(fileId, action) {
        $.ajax({
            url: API_ENDPOINT,
            method: 'POST',
            data: {
                action: action,
                file_id: fileId,
                csrf_token: CSRF_TOKEN
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showNotification(`File ${action}ed successfully.`, 'success');
                    loadPersonalFiles();
                } else {
                    showNotification(response.message || `Failed to ${action} file.`, 'error');
                }
            },
            error: function() {
                showNotification('Network error. Please try again.', 'error');
            }
        });
    }

    // Utility Functions
    function escapeHtml(str) {
        return $('<div>').text(str).html();
    }

    function formatDate(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function formatFileSize(bytes) {
        return bytes ? `${(bytes / 1024).toFixed(1)} KB` : '0 KB';
    }

    // Initialize
    init();
});