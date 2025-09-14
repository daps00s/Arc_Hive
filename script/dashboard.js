/* global bootstrap, notyf */
const notyf = new Notyf({
    duration: 4000,
    position: { x: 'right', y: 'top' },
    ripple: true
});

// Utility function for debouncing
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

$(document).ready(function() {
    // Ensure CSRF token is available
    const csrfToken = $('input[name="csrf_token"]').val();
    if (!csrfToken) {
        console.error('CSRF token is missing from form');
        notyf.error('Session error: Please refresh the page');
        return;
    }

    // Load jQuery UI for autocomplete
    if (!$.ui || !$.ui.autocomplete) {
        $.getScript('https://code.jquery.com/ui/1.12.1/jquery-ui.min.js', function() {
            $('<link>')
                .appendTo('head')
                .attr({
                    type: 'text/css', 
                    rel: 'stylesheet',
                    href: 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css'
                });
        });
    }

    // Load Font Awesome for file icons
    $('<link>')
        .appendTo('head')
        .attr({
            type: 'text/css',
            rel: 'stylesheet',
            href: 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css'
        });

    // Sidebar toggle for client-sidebar
    $('.sidebar .toggle-btn').on('click', function() {
        $('.sidebar').toggleClass('minimized');
        $('.main-container, .top-nav').toggleClass('resized');
    });

    // Activity log modal
    $('#activityLogTrigger').on('click', function(e) {
        e.preventDefault();
        $('#activityLogModal').removeClass('hidden');
    });

    // Recent files tabs
    $('.recent-files .tab-button').on('click', function(e) {
        e.preventDefault();
        console.log('Recent files tab clicked:', $(this).data('tab'));
        $('.recent-files .tab-button').removeClass('active');
        $(this).addClass('active');
        $('.recent-files .tab-content').addClass('hidden');
        $(`#${$(this).data('tab')}Tab`).removeClass('hidden');
    });

    // File view toggle (grid/list) for recent files and send modal
    $('.view-button').on('click', function() {
        const view = $(this).data('view');
        const container = $(this).closest('.recent-files, #sendFileModal');
        $('.view-button', container).removeClass('active');
        $(this).addClass('active');
        $('.files-grid', container).removeClass('grid-view list-view').addClass(`${view}-view`);
    });

    // Upload modal
    $('#uploadFileButton').on('click', function() {
        $('#uploadModal').removeClass('hidden');
        showModalStep(1);
        $('#docTypeFields').empty();
        $('#storageSuggestion').empty().addClass('hidden');
        $('#physicalStorage').val('').prop('disabled', true);
        $('#hardcopyFileName').val('').prop('disabled', true);
        $('#hardcopySearchContainer').addClass('hidden');
        $('#fileInput').val('');
        $('#filePreviewArea').empty();
        $('#accessLevel').val('personal');
        $('#departmentContainer').addClass('hidden');
        $('#departmentSelect').val('').prop('disabled', true);
        $('#subDepartmentSelect').val('').prop('disabled', true);
        $('#hardcopyCheckbox').prop('checked', false);
        $('#hardcopyOptionNew').prop('checked', true);
        $('#hardcopyOptions').addClass('hidden');
        fetchDepartments();
    });

    // Add change event for access level to toggle department fields
    $('#accessLevel').on('change', function() {
        const value = $(this).val();
        if (value === 'department' || value === 'sub_department') {
            $('#departmentContainer').removeClass('hidden');
            $('#departmentSelect').prop('disabled', false);
            $('#subDepartmentSelect').prop('disabled', value === 'department');
            $('#fileInput').prop('disabled', $('#hardcopyCheckbox').is(':checked'));
        } else {
            $('#departmentContainer').addClass('hidden');
            $('#departmentSelect').prop('disabled', true);
            $('#subDepartmentSelect').prop('disabled', true);
            $('#fileInput').prop('disabled', $('#hardcopyCheckbox').is(':checked'));
        }
        fetchStorageSuggestion();
    });

    // Add change event for department to fetch sub-departments
    $('#departmentSelect').on('change', function() {
        const deptId = $(this).val();
        if (deptId) {
            fetchSubDepartments(deptId);
        } else {
            $('#subDepartmentSelect').empty().append('<option value="">No Sub-Department</option>').prop('disabled', true);
        }
        fetchStorageSuggestion();
    });

    // Add change event for sub-department
    $('#subDepartmentSelect').on('change', function() {
        fetchStorageSuggestion();
    });

    // Hardcopy checkbox toggle
    $('#hardcopyCheckbox').on('change', function() {
        if ($(this).is(':checked')) {
            $('#hardcopyOptions').removeClass('hidden');
            $('#fileInput').prop('disabled', true).val('');
            $('#filePreviewArea').empty();
            $('#hardcopyFileName').prop('disabled', false);
            fetchStorageSuggestion();
        } else {
            $('#hardcopyOptions').addClass('hidden');
            $('#fileInput').prop('disabled', false);
            $('#storageSuggestion').empty().addClass('hidden');
            $('#physicalStorage').val('').prop('disabled', true);
            $('#hardcopyFileName').val('').prop('disabled', true);
            $('#hardcopySearchContainer').addClass('hidden');
        }
    });

    // Hardcopy option change
    $('input[name="hardcopyOption"]').on('change', function() {
        const option = $(this).val();
        if (option === 'existing') {
            $('#physicalStorage').prop('disabled', false);
            $('#hardcopyFileName').prop('disabled', true).val('');
            $('#hardcopySearchContainer').removeClass('hidden');
            $('#storageSuggestion').empty().addClass('hidden');
            setupStorageAutocomplete();
        } else {
            $('#physicalStorage').prop('disabled', true).val('');
            $('#hardcopyFileName').prop('disabled', false);
            $('#hardcopySearchContainer').addClass('hidden');
            fetchStorageSuggestion();
        }
    });

    // Document type change to fetch fields
    $('#documentType').on('change', function() {
        const docTypeId = $(this).val();
        if (docTypeId) {
            fetchDocumentFields(docTypeId);
        } else {
            $('#docTypeFields').empty();
        }
    });

// Update the send file button click handler
$('#sendFileButton').on('click', function() {
    $('#sendFileModal').removeClass('hidden');
    showSendModalStep(1); // Start at step 1
    loadFilesForSending();
    loadRecipients();
});

    // Close modals
    $('.close-modal').on('click', function() {
        $(this).closest('.modal').addClass('hidden');
    });

// Upload modal steps - SPECIFIC to upload modal
$('#uploadModal .next-step').on('click', function(e) {
    e.preventDefault();
    const accessLevel = $('#accessLevel').val();
    const isHardcopy = $('#hardcopyCheckbox').is(':checked');
    const files = $('#fileInput').prop('files');
    const departmentId = $('#departmentSelect').val();
    const subDepartmentId = $('#subDepartmentSelect').val();
    const hardcopyOption = $('input[name="hardcopyOption"]:checked').val();
    const physicalStorage = $('#physicalStorage').val();
    const hardcopyFileName = $('#hardcopyFileName').val();

    console.log('Upload modal next step - Validation state:', {
        files: files ? Array.from(files).map(f => f.name) : [],
        isHardcopy,
        accessLevel,
        departmentId,
        subDepartmentId,
        hardcopyOption,
        physicalStorage,
        hardcopyFileName
    });

    if (!isHardcopy && (!files || files.length === 0)) {
        notyf.error('Please select a file for soft copy upload');
        return;
    }
    if (accessLevel === 'department' && !departmentId) {
        notyf.error('Please select a department');
        return;
    }
    if (accessLevel === 'sub_department' && !subDepartmentId) {
        notyf.error('Please select a sub-department');
        return;
    }
    if (isHardcopy && hardcopyOption === 'existing' && !physicalStorage) {
        notyf.error('Please provide a physical storage location');
        return;
    }
    if (isHardcopy && hardcopyOption === 'new' && !hardcopyFileName) {
        notyf.error('Please provide a hardcopy file name');
        return;
    }
    // Validate file size (10MB limit)
    if (!isHardcopy && files) {
        for (let file of files) {
            if (file.size > 10 * 1024 * 1024) {
                notyf.error(`File ${file.name} exceeds 10MB limit`);
                return;
            }
        }
    }

    showModalStep(2);
});

    // Send file modal next step - DIFFERENT validation
$('.send-next-step').on('click', function(e) {
    e.preventDefault();
    const selectedFiles = $('#fileSelectionGrid .selected').length;
    
    console.log('Send file modal next step - Selected files:', selectedFiles);
    
    if (selectedFiles === 0) {
        notyf.error('Please select at least one file to send');
        return;
    }
    
    showSendModalStep(2);
});



function showModalStep(step) {
    // For upload modal
    $('#uploadModal .modal-step').addClass('hidden').filter(`[data-step="${step}"]`).removeClass('hidden');
    $('#uploadModal .progress-step').removeClass('active').filter(`[data-step="${step}"]`).addClass('active');
}

function showSendModalStep(step) {
    // For send file modal
    $('#sendFileModal .modal-step').addClass('hidden').filter(`[data-step="${step}"]`).removeClass('hidden');
    $('#sendFileModal .progress-step').removeClass('active').filter(`[data-step="${step}"]`).addClass('active');
}

    // Drag and drop for file upload
    const dragDropArea = $('.drag-drop-area');
    const fileInput = $('#fileInput');

    dragDropArea.on('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('drag-over');
    });

    dragDropArea.on('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('drag-over');
    });

    dragDropArea.on('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('drag-over');
        const files = e.originalEvent?.dataTransfer?.files || [];
        console.log('Files dropped:', files);
        if (files.length > 0) {
            const dataTransfer = new DataTransfer();
            Array.from(files).forEach(file => dataTransfer.items.add(file));
            fileInput[0].files = dataTransfer.files;
            console.log('Files assigned to fileInput:', fileInput[0].files);
            handleFiles(files);
        } else {
            notyf.error('No files detected in drop event');
        }
    });

    $('.choose-file-button').on('click', function() {
        fileInput.click();
    });

    fileInput.on('change', function() {
        handleFiles(this.files);
    });

    function handleFiles(files) {
        const previewArea = $('#filePreviewArea').empty();
        Array.from(files).forEach(file => {
            const previewItem = $('<div class="file-preview-item selected"></div>');
            const fileName = $('<p></p>').text(file.name);
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewItem.prepend(`<img src="${e.target.result}" alt="${file.name}">`);
                };
                reader.readAsDataURL(file);
            } else {
                const icon = file.type.includes('pdf') ? 'fas fa-file-pdf' : 
                            (file.type.includes('msword') || file.type.includes('wordprocessingml') ? 'fas fa-file-word' : 'fas fa-file');
                previewItem.prepend(`<i class="${icon} file-icon"></i>`);
            }
            previewItem.append(fileName);
            previewArea.append(previewItem);
        });
    }

    // Setup autocomplete for physical storage
    function setupStorageAutocomplete() {
        const deptId = $('#departmentSelect').val();
        const subDeptId = $('#subDepartmentSelect').val();
        $('#physicalStorage').autocomplete({
            source: function(request, response) {
                $.ajax({
                    url: 'api/file_operations.php',
                    method: 'POST',
                    data: {
                        action: 'fetch_storage_locations',
                        department_id: deptId || null,
                        sub_department_id: subDeptId || null,
                        csrf_token: csrfToken
                    },
                    success: function(data) {
                        console.log('Storage autocomplete response:', data);
                        if (data.success && data.locations) {
                            response(data.locations.map(location => ({
                                label: location.full_path,
                                value: location.full_path
                            })));
                        } else {
                            response([]);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Storage autocomplete error:', status, error, xhr.responseText);
                        response([]);
                    }
                });
            },
            minLength: 2
        });
    }

    // Form submission with submission guard
    let isSubmitting = false;
    $('#uploadForm').off('submit').on('submit', function(e) {
        e.preventDefault();
        if (isSubmitting) {
            console.log('Submission blocked: Already in progress');
            return;
        }
        isSubmitting = true;
        $('#uploadModal .next-step').prop('disabled', true);

        const accessLevel = $('#accessLevel').val();
        const documentTypeId = $('#documentType').val();
        const isHardcopy = $('#hardcopyCheckbox').is(':checked');
        const hardcopyOption = $('input[name="hardcopyOption"]:checked').val();
        const physicalStorage = $('#physicalStorage').val();
        const hardcopyFileName = $('#hardcopyFileName').val();
        const departmentId = $('#departmentSelect').val();
        const subDepartmentId = $('#subDepartmentSelect').val();
        const files = $('#fileInput').prop('files');

        console.log('Form submission data:', {
            accessLevel,
            documentTypeId,
            isHardcopy,
            hardcopyOption,
            physicalStorage,
            hardcopyFileName,
            departmentId,
            subDepartmentId,
            files: files ? Array.from(files).map(f => f.name) : [],
            csrfToken: csrfToken ? '[present]' : '[missing]'
        });

        if (!csrfToken) {
            notyf.error('CSRF token is missing. Please refresh the page.');
            isSubmitting = false;
            $('.next-step').prop('disabled', false);
            return;
        }
        if (!documentTypeId) {
            notyf.error('Please select a document type');
            isSubmitting = false;
            $('.next-step').prop('disabled', false);
            return;
        }
        if (accessLevel === 'department' && !departmentId) {
            notyf.error('Please select a department');
            isSubmitting = false;
            $('.next-step').prop('disabled', false);
            return;
        }
        if (accessLevel === 'sub_department' && !subDepartmentId) {
            notyf.error('Please select a sub-department');
            isSubmitting = false;
            $('.next-step').prop('disabled', false);
            return;
        }
        if (!isHardcopy && (!files || files.length === 0)) {
            notyf.error('Please select a file for soft copy upload');
            isSubmitting = false;
            $('.next-step').prop('disabled', false);
            return;
        }
        if (isHardcopy && hardcopyOption === 'existing' && !physicalStorage) {
            notyf.error('Please provide a physical storage location');
            isSubmitting = false;
            $('.next-step').prop('disabled', false);
            return;
        }
        if (isHardcopy && hardcopyOption === 'new' && !hardcopyFileName) {
            notyf.error('Please provide a hardcopy file name');
            isSubmitting = false;
            $('.next-step').prop('disabled', false);
            return;
        }

        const formData = new FormData(this);
        formData.append('is_hardcopy', isHardcopy ? '1' : '0');
        formData.append('hardcopyOption', hardcopyOption || '');

        const docTypeFields = {};
        $('#docTypeFields input').each(function() {
            const nameMatch = $(this).attr('name').match(/doc_type_fields\[(.*?)\]/);
            if (nameMatch && $(this).val()) {
                docTypeFields[nameMatch[1]] = $(this).val();
            }
        });
        formData.append('doc_type_fields', JSON.stringify(docTypeFields));

        $.ajax({
            url: 'api/upload_handler.php',
            method: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function(response) {
                console.log('Upload response:', response);
                if (response.success) {
                    notyf.success('Files uploaded successfully');
                    $('#uploadModal').addClass('hidden');
                    $('#fileInput').val('');
                    $('#filePreviewArea').empty();
                    $('.recent-files .tab-button[data-tab="uploaded"]').trigger('click');
                    setTimeout(() => {
                        fetchUploadedFiles();
                    }, 500);
                } else {
                    notyf.error(response.message || 'Failed to upload file');
                }
            },
            error: function(xhr, status, error) {
                console.error('Upload error:', status, error, xhr.responseText);
                try {
                    const response = JSON.parse(xhr.responseText);
                    notyf.error(response.message || 'Failed to upload file: Server error');
                } catch (e) {
                    notyf.error('Failed to upload file: Server error');
                }
            },
            complete: function() {
                isSubmitting = false;
                $('.next-step').prop('disabled', false);
            }
        });
    });

    // Search form submission
    $('#searchForm').on('submit', function(e) {
        e.preventDefault();
        const query = $('#searchInput').val().trim();
        if (query) {
            window.location.href = `search.php?query=${encodeURIComponent(query)}&csrf_token=${encodeURIComponent(csrfToken)}`;
        } else {
            notyf.error('Please enter a search query');
        }
    });
    function fetchDepartments() {
        $.ajax({
            url: 'api/file_operations.php',
            method: 'POST',
            data: {
                action: 'fetch_sub_departments',
                csrf_token: csrfToken
            },
            success: function(data) {
                console.log('Fetch departments response:', data);
                if (data.success && data.sub_departments) {
                    const select = $('#departmentSelect').empty().append('<option value="">Select Department</option>');
                    data.sub_departments.forEach(dept => {
                        select.append(`<option value="${dept.department_id}">${dept.department_name}</option>`);
                    });
                    fetchStorageSuggestion(); // Trigger suggestion after initial load
                } else {
                    notyf.error(data.message || 'Failed to fetch departments');
                }
            },
            error: function(xhr, status, error) {
                console.error('Departments fetch error:', status, error, xhr.responseText);
                notyf.error('Failed to fetch departments');
            }
        });
    }
    // Fetch sub-departments
    function fetchSubDepartments(deptId) {
        $.ajax({
            url: 'api/file_operations.php',
            method: 'POST',
            data: {
                action: 'fetch_sub_departments',
                department_id: deptId,
                csrf_token: csrfToken
            },
            success: function(data) {
                console.log('Sub-departments response:', data);
                const select = $('#subDepartmentSelect').empty().append('<option value="">No Sub-Department</option>');
                if (data.success && data.sub_departments) {
                    if (data.sub_departments.length === 0) {
                        console.warn('No sub-departments found for department ID:', deptId);
                    }
                    data.sub_departments.forEach(dept => {
                        select.append(`<option value="${dept.department_id}">${dept.department_name}</option>`);
                    });
                    select.prop('disabled', data.sub_departments.length === 0);
                } else {
                    notyf.error(data.message || 'Failed to load sub-departments');
                    select.prop('disabled', true);
                }
                fetchStorageSuggestion();
            },
            error: function(xhr, status, error) {
                console.error('Sub-departments fetch error:', status, error, xhr.responseText);
                notyf.error('Failed to load sub-departments');
                $('#subDepartmentSelect').prop('disabled', true);
            }
        });
    }

    // Fetch document fields
    function fetchDocumentFields(docTypeId) {
        $.ajax({
            url: 'api/file_operations.php',
            method: 'POST',
            data: {
                action: 'fetch_doc_fields',
                document_type_id: docTypeId,
                csrf_token: csrfToken
            },
            success: function(data) {
                console.log('Document fields response:', data);
                if (data.success && data.fields) {
                    const $docTypeFields = $('#docTypeFields').empty();
                    data.fields.forEach(field => {
                        const inputType = field.type === 'date' ? 'date' : 'text';
                        const required = field.required ? 'required' : '';
                        $docTypeFields.append(`
                            <label for="${field.id}">${field.name}</label>
                            <input type="${inputType}" id="${field.id}" name="doc_type_fields[${field.name}]" ${required} placeholder="Enter ${field.name}">
                        `);
                    });
                } else {
                    notyf.error(data.message || 'Failed to load document fields');
                    $('#docTypeFields').empty();
                }
            },
            error: function(xhr, status, error) {
                console.error('Fetch document fields error:', status, error, xhr.responseText);
                notyf.error('Failed to load document fields');
                $('#docTypeFields').empty();
            }
        });
    }

    // Fetch storage suggestion
    function fetchStorageSuggestion() {
        const accessLevel = $('#accessLevel').val();
        const isHardcopy = $('#hardcopyCheckbox').is(':checked');
        const hardcopyOption = $('input[name="hardcopyOption"]:checked').val();
        const deptId = $('#departmentSelect').val();
        const subDeptId = $('#subDepartmentSelect').val();

        if (!isHardcopy || hardcopyOption === 'existing' || accessLevel === 'personal') {
            $('#storageSuggestion').empty().addClass('hidden');
            $('#physicalStorage').val('');
            return;
        }

        if (!deptId && !subDeptId) {
            $('#storageSuggestion').empty().addClass('hidden');
            $('#physicalStorage').val('');
            return;
        }

        $.ajax({
            url: 'api/file_operations.php',
            method: 'POST',
            data: {
                action: 'fetch_storage_locations',
                department_id: deptId || null,
                sub_department_id: subDeptId || null,
                csrf_token: csrfToken
            },
            success: function(data) {
                console.log('Storage locations response:', data);
                if (data.success && data.locations && data.locations.length > 0) {
                    const location = data.locations[0];
                    $('#storageSuggestion').text(`Suggested Location: ${location.full_path}`).removeClass('hidden');
                    $('#physicalStorage').val(location.full_path);
                } else {
                    $('#storageSuggestion').text('No available storage locations').removeClass('hidden');
                    $('#physicalStorage').val('');
                }
            },
            error: function(xhr, status, error) {
                console.error('Storage locations fetch error:', status, error, xhr.responseText);
                notyf.error('Failed to fetch storage locations');
            }
        });
    }

    // Fetch uploaded files
    function fetchUploadedFiles() {
        $.ajax({
            url: 'api/file_operations.php',
            method: 'POST',
            data: {
                action: 'fetch_uploaded_files',
                sort: 'date-desc',
                csrf_token: csrfToken,
                _t: new Date().getTime()
            },
            success: function(data) {
                console.log('Uploaded files response:', data);
                if (data.success) {
                    const grid = $('#uploadedTab .files-grid').empty();
                    if (data.files.length === 0) {
                        grid.append('<p class="no-files">No files available.</p>');
                    } else {
                        data.files.forEach(file => {
                            const meta = `
                                Type: ${file.document_type || 'Unknown'} | 
                                Uploaded: ${new Date(file.upload_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} | 
                                Dept: ${file.department_name || 'None'}
                            `;
                            const item = `
                                <div class="file-item" data-file-id="${file.file_id}">
                                    <p class="file-name">${file.file_name}</p>
                                    <p class="file-meta">${meta}</p>
                                    <button class="kebab-menu"><i class="fas fa-ellipsis-v"></i></button>
                                    <div class="file-menu hidden">
                                        <button class="download-file">Download</button>
                                        <button class="rename-file">Rename</button>
                                        <button class="delete-file">Delete</button>
                                        <button class="share-file">Share</button>
                                        <button class="file-info">File Info</button>
                                    </div>
                                </div>
                            `;
                            grid.append(item);
                        });
                    }
                } else {
                    notyf.error(data.message || 'Failed to load files');
                }
            },
            error: function(xhr, status, error) {
                console.error('Uploaded files fetch error:', status, error, xhr.responseText);
                notyf.error('Failed to load files');
            }
        });
    }

    // Initial fetch for uploaded files
    fetchUploadedFiles();

// Fetch notifications
function fetchNotifications() {
    $.ajax({
        url: 'api/file_operations.php',
        method: 'POST',
        data: {
            action: 'fetch_notifications',
            csrf_token: csrfToken
        },
        success: function(data) {
            console.log('Notifications response:', data);
            if (data.success && data.notifications) {
                const offcanvasBody = $('#notificationsOffcanvas .offcanvas-body').empty();
                offcanvasBody.append('<button class="btn mark-all-read w-100 mb-3">Mark all as read</button>');
                // Filter notifications: exclude 'notification' type with "You have received a file"
                const filteredNotifications = data.notifications.filter(notification => 
                    !(notification.transaction_type === 'notification' && 
                      notification.message.startsWith('You have received a file'))
                );
                if (filteredNotifications.length === 0) {
                    offcanvasBody.append('<p class="text-muted text-center no-notifications">No notifications available.</p>');
                } else {
                    const listGroup = $('<div class="list-group"></div>');
                    filteredNotifications.forEach(notification => {
                        const isFileSent = notification.transaction_type === 'file_sent';
                        const isPending = notification.transaction_status === 'pending';
                        const item = `
                            <div class="list-group-item list-group-item-action ${isFileSent && isPending ? 'notification-item-pending clickable-notification' : ''}" 
                                 data-notification-id="${notification.id}" 
                                 data-file-id="${notification.file_id}"
                                 data-file-name="${notification.file_name}">
                                <p class="notification-message mb-1">${notification.message} (File: ${notification.file_name})</p>
                                <small class="text-muted">${new Date(notification.timestamp).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: 'numeric' })}</small>
                            </div>
                        `;
                        listGroup.append(item);
                    });
                    offcanvasBody.append(listGroup);
                }
                $('.notification-badge').text(filteredNotifications.filter(n => n.transaction_status === 'pending').length || '');
            } else {
                notyf.error(data.message || 'Failed to fetch notifications');
            }
        },
        error: function(xhr, status, error) {
            console.error('Notifications fetch error:', status, error, xhr.responseText);
            notyf.error('Failed to fetch notifications');
        }
    });
}

$(document).on('click', '.clickable-notification', function() {
    const notificationId = $(this).data('notification-id');
    const fileId = $(this).data('file-id');
    const fileName = $(this).data('file-name');
    const isPending = $(this).hasClass('notification-item-pending');

    console.log('Clickable notification triggered:', { notificationId, fileId, fileName, isPending });

    if (!notificationId || !fileId) {
        console.error('Missing notification or file data:', { notificationId, fileId, fileName });
        notyf.error('Invalid notification or file data');
        return;
    }

    // Close any open modal
    if (!$('#recipientFileModal').hasClass('hidden')) {
        console.log('Recipient modal already open, closing first');
        $('#recipientFileModal').addClass('hidden');
    }

    // Clear previous content
    $('#recipientFileModalPreview').empty();
    $('#recipientFileModalButtons').empty();

    // Fetch file preview
    $.ajax({
        url: 'api/file_operations.php',
        method: 'POST',
        data: {
            action: 'fetch_file_preview',
            file_id: fileId,
            csrf_token: csrfToken
        },
        success: function(data) {
            console.log('File preview response for notification:', data);
            const $preview = $('#recipientFileModalPreview').empty();
            if (data.success && data.file_path) {
                const fileType = data.file_type || '';
                const filePath = data.file_path;
                const fileName = data.file_name || 'Unknown File';

                if (fileType.startsWith('image/')) {
                    const img = $('<img>').attr({
                        src: filePath,
                        alt: 'Preview of ' + fileName,
                        class: 'file-preview-image'
                    });
                    $preview.append(img);
                } else if (fileType === 'text/plain') {
                    $.get(filePath, function(text) {
                        const pre = $('<pre>').text(text).css({
                            'max-height': '400px',
                            'overflow-y': 'auto',
                            'background': '#f8f8f8',
                            'padding': '10px',
                            'border-radius': '4px'
                        });
                        $preview.append(pre);
                    }).fail(function(xhr, status, error) {
                        console.error('Text file fetch error for notification:', { status, error, response: xhr.responseText });
                        $preview.append('<p class="error">Failed to load text content: ' + (xhr.responseText || 'Unknown error') + '</p>');
                    });
                } else if (fileType === 'application/pdf') {
                    $preview.append(
                        `<embed src="${filePath}" type="application/pdf" width="100%" height="400px">` +
                        `<p>PDF preview not supported in some browsers. <a href="${filePath}" target="_blank">Download PDF</a></p>`
                    );
                } else if (fileType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || 
           fileType === 'application/msword') {
    $preview.append(
        `<i class="fas fa-file-word fa-3x"></i>` +
        `<p>Preview not available for Word documents.</p>` +
        `<p><a href="${filePath}" target="_blank" class="btn btn-primary">Download ${fileName}</a></p>`
    );

    // Add Accept/Deny buttons for pending notifications even if preview isn't available
    const $actionButtons = $('#recipientFileModalButtons').empty();
    if (isPending) {
        $actionButtons.append(`
            <button class="btn btn-success accept-notification" data-notification-id="${notificationId}" data-file-id="${fileId}">Accept</button>
            <button class="btn btn-danger reject-notification" data-notification-id="${notificationId}" data-file-id="${fileId}">Deny</button>
        `);
    }
} else {
                    $preview.append(
                        `<i class="fas fa-file fa-3x"></i><p>Preview not available for this file type (${fileType}). <a href="${filePath}" target="_blank">Download ${fileName}</a></p>`
                    );
                }

                // Add Accept/Deny buttons for pending notifications
                const $actionButtons = $('#recipientFileModalButtons').empty();
                if (isPending) {
                    $actionButtons.append(`
                        <button class="btn btn-success accept-notification" data-notification-id="${notificationId}" data-file-id="${fileId}">Accept</button>
                        <button class="btn btn-danger reject-notification" data-notification-id="${notificationId}" data-file-id="${fileId}">Deny</button>
                    `);
                }

                // Show modal
                console.log('Showing recipientFileModal');
                $('#recipientFileModal').removeClass('hidden');
} else {
    console.error('Preview failed for notification:', { message: data.message, response: data });
    
    // Show appropriate message based on whether preview is available or not
    if (data.message === 'Preview not available for this file type') {
        $preview.append(`
            <i class="fas fa-file fa-3x"></i>
            <p>Preview not available for this file type (${data.file_type || 'Unknown'}).</p>
            <p><a href="${data.file_path}" target="_blank" class="btn btn-primary">Download ${data.file_name}</a></p>
        `);
    } else {
        $preview.append('<p class="error">Failed to load file preview: ' + (data.message || 'Unknown error') + '</p>');
    }
    
    // Add Accept/Deny buttons for pending notifications even if preview failed
    const $actionButtons = $('#recipientFileModalButtons').empty();
    if (isPending) {
        $actionButtons.append(`
            <button class="btn btn-success accept-notification" data-notification-id="${notificationId}" data-file-id="${fileId}">Accept</button>
            <button class="btn btn-danger reject-notification" data-notification-id="${notificationId}" data-file-id="${fileId}">Deny</button>
        `);
    }

    // Show modal even if preview failed
    console.log('Showing recipientFileModal (preview failed)');
    $('#recipientFileModal').removeClass('hidden');
    notyf.error('Failed to load file preview: ' + (data.message || 'Unknown error'));
}
        },
error: function(xhr, status, error) {
    console.error('File preview AJAX error for notification:', { status, error, response: xhr.responseText });
    
    const $preview = $('#recipientFileModalPreview').empty();
    $preview.append(`
        <i class="fas fa-file fa-3x"></i>
        <p>Unable to load file preview due to server error.</p>
        <p class="error">Error: ${xhr.responseText || 'Unknown error'}</p>
    `);
    
    // Add Accept/Deny buttons for pending notifications even if AJAX failed
    const $actionButtons = $('#recipientFileModalButtons').empty();
    if (isPending) {
        $actionButtons.append(`
            <button class="btn btn-success accept-notification" data-notification-id="${notificationId}" data-file-id="${fileId}">Accept</button>
            <button class="btn btn-danger reject-notification" data-notification-id="${notificationId}" data-file-id="${fileId}">Deny</button>
        `);
    }

    // Show modal even if AJAX failed
    console.log('Showing recipientFileModal (AJAX error)');
    $('#recipientFileModal').removeClass('hidden');
    notyf.error('Failed to load file preview: Server error');
}
    });
});

// Mark all as read
$(document).on('click', '.mark-all-read', function() {
    $.ajax({
        url: 'api/file_operations.php',
        method: 'POST',
        data: {
            action: 'mark_all_notifications_read',
            csrf_token: csrfToken
        },
        success: function(response) {
            console.log('Mark all read response:', response);
            if (response.success) {
                notyf.success('All notifications marked as read');
                fetchNotifications();
            } else {
                notyf.error(response.message || 'Failed to mark notifications as read');
            }
        },
        error: function(xhr, status, error) {
            console.error('Mark all read error:', status, error, xhr.responseText);
            notyf.error('Failed to mark notifications as read');
        }
    });
});

    // Poll notifications every 30 seconds
    setInterval(fetchNotifications, 30000);
    fetchNotifications();

    // Notifications toggle
    $('.notifications-toggle').on('click', function() {
        $('.notifications-sidebar').toggleClass('hidden');
    });

    // Notification actions


// Accept notification handler
$(document).on('click', '.accept-notification', function() {
    const notificationId = $(this).data('notification-id');
    const fileId = $(this).data('file-id');
    $.ajax({
        url: 'api/file_operations.php',
        method: 'POST',
        data: {
            action: 'accept_file',
            notification_id: notificationId,
            file_id: fileId,
            csrf_token: csrfToken
        },
        success: function(response) {
            console.log('Accept file response:', response);
            if (response.success) {
                notyf.success('File accepted');
                $('#recipientFileModal').addClass('hidden');
                fetchNotifications();
            } else {
                notyf.error(response.message || 'Failed to accept file');
            }
        },
        error: function(xhr, status, error) {
            console.error('Accept file error:', status, error, xhr.responseText);
            notyf.error('Failed to accept file');
        }
    });
});

// Reject notification handler
$(document).on('click', '.reject-notification', function() {
    const notificationId = $(this).data('notification-id');
    const fileId = $(this).data('file-id');
    $.ajax({
        url: 'api/file_operations.php',
        method: 'POST',
        data: {
            action: 'reject_file',
            notification_id: notificationId,
            file_id: fileId,
            csrf_token: csrfToken
        },
        success: function(response) {
            console.log('Reject file response:', response);
            if (response.success) {
                notyf.success('File rejected');
                $('#recipientFileModal').addClass('hidden');
                fetchNotifications();
            } else {
                notyf.error(response.message || 'Failed to reject file');
            }
        },
        error: function(xhr, status, error) {
            console.error('Reject file error:', status, error, xhr.responseText);
            notyf.error('Failed to reject file');
        }
    });
});

    // Close notifications sidebar when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.notifications-toggle, .notifications-sidebar').length) {
            $('.notifications-sidebar').addClass('hidden');
        }
    });

    // Prevent clicks inside the sidebar from closing it
    $('.notifications-sidebar').on('click', function(e) {
        e.stopPropagation();
    });

    // Kebab menu toggle
    $(document).on('click', '.kebab-menu', function(e) {
        e.stopPropagation();
        const $fileItem = $(this).closest('.file-item');
        const $fileMenu = $fileItem.find('.file-menu');
        $('.file-menu').not($fileMenu).addClass('hidden');
        $fileMenu.toggleClass('hidden');
    });

    // Close file menu when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.kebab-menu, .file-menu').length) {
            $('.file-menu').addClass('hidden');
        }
    });

// File menu button clicks
$(document).on('click', '.file-menu button', function(e) {
    e.stopPropagation();
    const $fileItem = $(this).closest('.file-item');
    const fileId = $fileItem.data('file-id');
    const action = $(this).attr('class');

if (action === 'file-info') {
    // Fetch file preview using fetch_file_preview endpoint
    $.ajax({
        url: 'api/file_operations.php',
        method: 'POST',
        data: {
            action: 'fetch_file_preview',
            file_id: fileId,
            csrf_token: csrfToken
        },
        success: function(data) {
            console.log('File preview response:', data);
            const $preview = $('#filePreview').empty();
            if (data.success) {
                const fileType = data.file_type || '';
                const filePath = data.file_path;
                const fileName = data.file_name || 'Unknown File';
                const isPreviewable = data.is_previewable;

if (isPreviewable && filePath) {
    if (fileType.startsWith('image/')) {
        const img = $('<img>').attr({
            src: filePath,
            alt: 'Preview of ' + fileName,
            class: 'file-preview-image'
        });
        $preview.append(img);
    } else if (fileType === 'text/plain') {
        $.get(filePath, function(text) {
            const pre = $('<pre>').text(text).css({
                'max-height': '400px',
                'overflow-y': 'auto',
                'background': '#f8f8f8',
                'padding': '10px',
                'border-radius': '4px'
            });
            $preview.append(pre);
        }).fail(function() {
            $preview.append('<p class="error">Failed to load text content</p>');
        });
    } else if (fileType === 'application/pdf') {
        $preview.append(
            `<embed src="${filePath}" type="application/pdf" width="100%" height="400px">` +
            `<p>PDF preview not supported in some browsers. <a href="${filePath}" target="_blank">Download PDF</a></p>`
        );
    } else if (fileType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || 
               fileType === 'application/msword') {
        $preview.append(
            `<i class="fas fa-file-word fa-3x"></i>` +
            `<p>Preview not available for Word documents. <a href="${filePath}" target="_blank">Download ${fileName}</a></p>`
        );
    }
} else {
    $preview.append(
        `<i class="fas fa-file fa-3x"></i><p>Preview not available for this file type (${fileType || 'Unknown'}).</p>`
    );
}

                // Fetch file details for the details tab
                $.ajax({
                    url: 'api/file_operations.php',
                    method: 'POST',
                    data: {
                        action: 'fetch_file_info',
                        file_id: fileId,
                        csrf_token: csrfToken
                    },
                    success: function(data) {
                        console.log('File info response:', data);
                        if (data.success) {
                            const $detailsTab = $('#detailsTab').empty();
                            $detailsTab.append('<div class="file-info-section"><strong>Access:</strong> ' + (data.file.access_level || 'Unknown') + '</div>');
                            $detailsTab.append('<div class="file-info-section"><strong>QR Code:</strong> ' + (data.file.qr_path ? 'Available' : 'Not Available') + '</div>');
                            $detailsTab.append('<div class="file-info-section"><strong>File Type:</strong> ' + (data.file.file_type || data.file.document_type || 'Unknown') + '</div>');
                            $detailsTab.append('<div class="file-info-section"><strong>File Size:</strong> ' + 
                                (data.file.file_size ? (data.file.file_size / 1024).toFixed(2) + ' KB' : 'N/A') + '</div>');
                            $detailsTab.append('<div class="file-info-section"><strong>Category:</strong> ' + (data.file.document_type || 'Unknown') + '</div>');
                            $detailsTab.append('<div class="file-info-section"><strong>Uploader:</strong> ' + (data.file.uploader_name || 'Unknown') + '</div>');
                            $detailsTab.append('<div class="file-info-section"><strong>Upload Date:</strong> ' + 
                                (data.file.upload_date ? new Date(data.file.upload_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Unknown') + '</div>');
                            $detailsTab.append('<div class="file-info-section"><strong>Physical Location:</strong> ' + (data.file.physical_location || 'None') + '</div>');
                            $detailsTab.append('<div class="file-info-section"><strong>Document Type:</strong> ' + (data.file.document_type || 'Unknown') + '</div>');
                            if (data.formatted_fields && data.formatted_fields.length > 0) {
                                let fieldsHtml = '<div class="file-info-section"><strong>Fields:</strong><ul class="fields-list">';
                                data.formatted_fields.forEach(field => {
                                    fieldsHtml += `<li>${field.key}: ${field.value}</li>`;
                                });
                                fieldsHtml += '</ul></div>';
                                $detailsTab.append(fieldsHtml);
                            } else {
                                $detailsTab.append('<div class="file-info-section"><strong>Fields:</strong> None</div>');
                            }
                            $('#fileSentTo').text(data.activity.sent_to ? data.activity.sent_to.join(', ') : 'None');
                            $('#fileReceivedBy').text(data.activity.received_by ? data.activity.received_by.join(', ') : 'None');
                            $('#fileCopiedBy').text(data.activity.copied_by ? data.activity.copied_by.join(', ') : 'None');
                            $('#fileRenamedTo').text(data.activity.renamed_to || 'None');
                            $('#fileInfoSidebar')
                                .removeClass('hidden')
                                .css({ right: '-400px' })
                                .animate({ right: '0' }, 300)
                                .attr('aria-hidden', 'false');
                        } else {
                            notyf.error(data.message || 'Failed to load file information');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('File info fetch error:', status, error, xhr.responseText);
                        notyf.error('Failed to load file information');
                    }
                });
            } else {
                // Handle preview failure but still attempt to fetch file info
                console.error('Preview failed:', data.message || 'No file path returned');
                $preview.append('<p class="error">Failed to load file preview: ' + (data.message || 'Unknown error') + '</p>');

                // Proceed to fetch file info and open sidebar
                $.ajax({
                    url: 'api/file_operations.php',
                    method: 'POST',
                    data: {
                        action: 'fetch_file_info',
                        file_id: fileId,
                        csrf_token: csrfToken
                    },
                    success: function(data) {
                        console.log('File info response:', data);
                        if (data.success) {
                            const $detailsTab = $('#detailsTab').empty();
                            $detailsTab.append('<div class="file-info-section"><strong>Access:</strong> ' + (data.file.access_level || 'Unknown') + '</div>');
                            $detailsTab.append('<div class="file-info-section"><strong>QR Code:</strong> ' + (data.file.qr_path ? 'Available' : 'Not Available') + '</div>');
                            $detailsTab.append('<div class="file-info-section"><strong>File Type:</strong> ' + (data.file.file_type || data.file.document_type || 'Unknown') + '</div>');
                            $detailsTab.append('<div class="file-info-section"><strong>File Size:</strong> ' + 
                                (data.file.file_size ? (data.file.file_size / 1024).toFixed(2) + ' KB' : 'N/A') + '</div>');
                            $detailsTab.append('<div class="file-info-section"><strong>Category:</strong> ' + (data.file.document_type || 'Unknown') + '</div>');
                            $detailsTab.append('<div class="file-info-section"><strong>Uploader:</strong> ' + (data.file.uploader_name || 'Unknown') + '</div>');
                            $detailsTab.append('<div class="file-info-section"><strong>Upload Date:</strong> ' + 
                                (data.file.upload_date ? new Date(data.file.upload_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Unknown') + '</div>');
                            $detailsTab.append('<div class="file-info-section"><strong>Physical Location:</strong> ' + (data.file.physical_location || 'None') + '</div>');
                            $detailsTab.append('<div class="file-info-section"><strong>Document Type:</strong> ' + (data.file.document_type || 'Unknown') + '</div>');
                            if (data.formatted_fields && data.formatted_fields.length > 0) {
                                let fieldsHtml = '<div class="file-info-section"><strong>Fields:</strong><ul class="fields-list">';
                                data.formatted_fields.forEach(field => {
                                    fieldsHtml += `<li>${field.key}: ${field.value}</li>`;
                                });
                                fieldsHtml += '</ul></div>';
                                $detailsTab.append(fieldsHtml);
                            } else {
                                $detailsTab.append('<div class="file-info-section"><strong>Fields:</strong> None</div>');
                            }
                            $('#fileSentTo').text(data.activity.sent_to ? data.activity.sent_to.join(', ') : 'None');
                            $('#fileReceivedBy').text(data.activity.received_by ? data.activity.received_by.join(', ') : 'None');
                            $('#fileCopiedBy').text(data.activity.copied_by ? data.activity.copied_by.join(', ') : 'None');
                            $('#fileRenamedTo').text(data.activity.renamed_to || 'None');
                            $('#fileInfoSidebar')
                                .removeClass('hidden')
                                .css({ right: '-400px' })
                                .animate({ right: '0' }, 300)
                                .attr('aria-hidden', 'false');
                        } else {
                            notyf.error(data.message || 'Failed to load file information');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('File info fetch error:', status, error, xhr.responseText);
                        notyf.error('Failed to load file information');
                    }
                });
            }
        },
        error: function(xhr, status, error) {
            console.error('File preview fetch error:', status, error, xhr.responseText);
            $('#filePreview').empty().append('<p class="error">Failed to load file preview: Server error</p>');

            // Proceed to fetch file info and open sidebar even if preview fails
            $.ajax({
                url: 'api/file_operations.php',
                method: 'POST',
                data: {
                    action: 'fetch_file_info',
                    file_id: fileId,
                    csrf_token: csrfToken
                },
                success: function(data) {
                    console.log('File info response:', data);
                    if (data.success) {
                        const $detailsTab = $('#detailsTab').empty();
                        $detailsTab.append('<div class="file-info-section"><strong>Access:</strong> ' + (data.file.access_level || 'Unknown') + '</div>');
                        $detailsTab.append('<div class="file-info-section"><strong>QR Code:</strong> ' + (data.file.qr_path ? 'Available' : 'Not Available') + '</div>');
                        $detailsTab.append('<div class="file-info-section"><strong>File Type:</strong> ' + (data.file.file_type || data.file.document_type || 'Unknown') + '</div>');
                        $detailsTab.append('<div class="file-info-section"><strong>File Size:</strong> ' + 
                            (data.file.file_size ? (data.file.file_size / 1024).toFixed(2) + ' KB' : 'N/A') + '</div>');
                        $detailsTab.append('<div class="file-info-section"><strong>Category:</strong> ' + (data.file.document_type || 'Unknown') + '</div>');
                        $detailsTab.append('<div class="file-info-section"><strong>Uploader:</strong> ' + (data.file.uploader_name || 'Unknown') + '</div>');
                        $detailsTab.append('<div class="file-info-section"><strong>Upload Date:</strong> ' + 
                            (data.file.upload_date ? new Date(data.file.upload_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Unknown') + '</div>');
                        $detailsTab.append('<div class="file-info-section"><strong>Physical Location:</strong> ' + (data.file.physical_location || 'None') + '</div>');
                        $detailsTab.append('<div class="file-info-section"><strong>Document Type:</strong> ' + (data.file.document_type || 'Unknown') + '</div>');
                        if (data.formatted_fields && data.formatted_fields.length > 0) {
                            let fieldsHtml = '<div class="file-info-section"><strong>Fields:</strong><ul class="fields-list">';
                            data.formatted_fields.forEach(field => {
                                fieldsHtml += `<li>${field.key}: ${field.value}</li>`;
                            });
                            fieldsHtml += '</ul></div>';
                            $detailsTab.append(fieldsHtml);
                        } else {
                            $detailsTab.append('<div class="file-info-section"><strong>Fields:</strong> None</div>');
                        }
                        $('#fileSentTo').text(data.activity.sent_to ? data.activity.sent_to.join(', ') : 'None');
                        $('#fileReceivedBy').text(data.activity.received_by ? data.activity.received_by.join(', ') : 'None');
                        $('#fileCopiedBy').text(data.activity.copied_by ? data.activity.copied_by.join(', ') : 'None');
                        $('#fileRenamedTo').text(data.activity.renamed_to || 'None');
                        $('#fileInfoSidebar')
                            .removeClass('hidden')
                            .css({ right: '-400px' })
                            .animate({ right: '0' }, 300)
                            .attr('aria-hidden', 'false');
                    } else {
                        notyf.error(data.message || 'Failed to load file information');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('File info fetch error:', status, error, xhr.responseText);
                    notyf.error('Failed to load file information');
                }
            });
        },
            error: function(xhr, status, error) {
                console.error('File preview fetch error:', status, error, xhr.responseText);
                $('#filePreview').empty().append('<p class="error">Failed to load file preview: Server error</p>');
                notyf.error('Failed to load file preview');
            }
        });
    }
    $('.file-menu').addClass('hidden');
});

    // --- New Code for Send File Modal ---

    let sendingFiles = []; // Store loaded files for sorting/re-rendering

    // Load files for sending
    function loadFilesForSending() {
        $.ajax({
            url: 'api/file_operations.php',
            method: 'POST',
            data: {
                action: 'load_files_for_sending',
                csrf_token: csrfToken
            },
            success: function(data) {
                console.log('Files for sending response:', data);
                if (data.success) {
                    sendingFiles = data.files;
                    renderSendingFiles(sendingFiles);
                } else {
                    notyf.error(data.message || 'Failed to load files for sending');
                }
            },
            error: function(xhr, status, error) {
                console.error('Files for sending fetch error:', status, error, xhr.responseText);
                notyf.error('Failed to load files for sending');
            }
        });
    }

    // Render files in the modal (preserves selections during re-render)
    function renderSendingFiles(files) {
        const grid = $('#fileSelectionGrid').empty();
        if (files.length === 0) {
            grid.append('<p class="no-files">No files available to send.</p>');
            return;
        }
        files.forEach(file => {
            const meta = `
                Type: ${file.document_type || 'Unknown'} | 
                Uploaded: ${new Date(file.upload_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} | 
                Dept: ${file.department_name || 'None'}
            `;
            const item = `
                <div class="file-item selectable" data-file-id="${file.file_id}">
                    <p class="file-name">${file.file_name}</p>
                    <p class="file-meta">${meta}</p>
                </div>
            `;
            grid.append(item);
        });
    }

// Update your loadRecipients function
function loadRecipients() {
    $.ajax({
        url: 'api/file_operations.php',
        method: 'POST',
        data: {
            action: 'load_recipients',
            csrf_token: csrfToken
        },
        success: function(data) {
            console.log('Recipients response:', data);
            if (data.success) {
                const list = $('#recipientList').empty();
                
                // Users
                data.users.forEach(user => {
                    const item = `
                        <div class="recipient-item selectable" data-type="user" data-id="${user.user_id}">
                            <span>${user.username}</span>
                            <span class="recipient-type">User</span>
                        </div>
                    `;
                    list.append(item);
                });
                
                // Departments (including all types: college, office, sub-department)
                data.departments.forEach(dept => {
                    const item = `
                        <div class="recipient-item selectable" data-type="${dept.type}" data-id="${dept.department_id}">
                            <span>${dept.department_name}</span>
                            <span class="recipient-type">${dept.type.replace('_', ' ').charAt(0).toUpperCase() + dept.type.replace('_', ' ').slice(1)}</span>
                        </div>
                    `;
                    list.append(item);
                });
            } else {
                notyf.error(data.message || 'Failed to load recipients');
            }
        },
        error: function(xhr, status, error) {
            console.error('Recipients fetch error:', status, error, xhr.responseText);
            notyf.error('Failed to load recipients');
        }
    });
}

    // Handle selection for files and recipients (minimal UI: just toggle class)
    $(document).on('click', '.selectable', function() {
        $(this).toggleClass('selected');
    });

    // Client-side sorting for files in send modal (minimal: date/dept/personal filters, preserves selections)
    $('#sendFileModal #fileSort').on('change', function() {
        const sort = $(this).val();
        const selectedIds = $('#fileSelectionGrid .selected').map(function() { return $(this).data('file-id'); }).get(); // Preserve selections

        let sortedFiles = [...sendingFiles];
        if (sort === 'date-desc') {
            sortedFiles.sort((a, b) => new Date(b.upload_date) - new Date(a.upload_date));
        } else if (sort === 'date-asc') {
            sortedFiles.sort((a, b) => new Date(a.upload_date) - new Date(b.upload_date));
        } else if (sort === 'department') {
            sortedFiles = sortedFiles.filter(f => f.department_name && !f.parent_department_id) // Assume top-level depts
                .sort((a, b) => (a.department_name || '').localeCompare(b.department_name || ''));
        } else if (sort === 'sub-department') {
            sortedFiles = sortedFiles.filter(f => f.department_name && f.parent_department_id) // Assume sub-depts
                .sort((a, b) => (a.department_name || '').localeCompare(b.department_name || ''));
        } else if (sort === 'personal') {
            sortedFiles = sortedFiles.filter(f => !f.department_name);
        }

        renderSendingFiles(sortedFiles);
        // Re-apply selections
        selectedIds.forEach(id => {
            $(`#fileSelectionGrid [data-file-id="${id}"]`).addClass('selected');
        });
    });

// Recipient search functionality
$('#recipientSearch').on('input', debounce(function() {
    const query = $(this).val().trim();
    
    // Client-side filtering for immediate feedback
    $('.recipient-item').each(function() {
        const name = $(this).find('span:first').text().toLowerCase();
        const type = $(this).find('.recipient-type').text().toLowerCase();
        const matches = name.includes(query.toLowerCase()) || type.includes(query.toLowerCase());
        $(this).toggle(matches);
    });
    
    // Server-side search for more comprehensive results
    if (query.length >= 2) {
        $.ajax({
            url: 'api/file_operations.php',
            method: 'POST',
            data: {
                action: 'search_recipients',
                query: query,
                csrf_token: csrfToken
            },
            success: function(data) {
                console.log('Search recipients response:', data);
                if (data.success) {
                    const list = $('#recipientList').empty();
                    data.results.forEach(result => {
                        const item = `
                            <div class="recipient-item selectable" data-type="${result.type}" data-id="${result.id}">
                                <span>${result.name}</span>
                                <span class="recipient-type">${result.type.charAt(0).toUpperCase() + result.type.slice(1)}</span>
                            </div>
                        `;
                        list.append(item);
                    });
                }
            },
            error: function(xhr, status, error) {
                console.error('Search recipients error:', status, error, xhr.responseText);
            }
        });
    } else if (query.length === 0) {
        loadRecipients(); // Reset to full list
    }
}, 300));

$('#sendFileForm').on('submit', function(e) {
    e.preventDefault();
    const selectedFiles = $('#fileSelectionGrid .selected').map(function() {
        return $(this).data('file-id');
    }).get();
    
    const selectedRecipients = $('#recipientList .selected').map(function() {
        return `${$(this).data('type')}:${$(this).data('id')}`;
    }).get();
    
    const message = $('textarea[name="message"]', this).val();

    if (selectedFiles.length === 0) {
        notyf.error('Please select at least one file to send');
        return;
    }
    if (selectedRecipients.length === 0) {
        notyf.error('Please select at least one recipient');
        return;
    }

    $.ajax({
        url: 'api/send_file_handler.php',
        method: 'POST',
        data: {
            file_ids: JSON.stringify(selectedFiles),
            recipients: JSON.stringify(selectedRecipients),
            message: message,
            csrf_token: csrfToken
        },
        success: function(response) {
            console.log('Send file response:', response);
            if (response.success) {
                notyf.success(`Files sent successfully to ${response.recipient_count} recipients`);
                $('#sendFileModal').addClass('hidden');
                // Reset the modal for next use
                showSendModalStep(1);
                $('#fileSelectionGrid .selected').removeClass('selected');
                $('#recipientList .selected').removeClass('selected');
                $('#selectedRecipients').empty();
                $('textarea[name="message"]').val('');
                
                // Optional: Refresh notifications or files
                fetchNotifications();
            } else {
                notyf.error(response.message || 'Failed to send files');
            }
        },
        error: function(xhr, status, error) {
            console.error('Send file error:', status, error, xhr.responseText);
            notyf.error('Failed to send files: Server error');
        }
    });
});


    // Send modal steps navigation
$('.send-next-step').on('click', function(e) {
    e.preventDefault();
    const selectedFiles = $('#fileSelectionGrid .selected').length;
    
    if (selectedFiles === 0) {
        notyf.error('Please select at least one file to send');
        return;
    }
    
    showSendModalStep(2);
});

$('.send-prev-step').on('click', function() {
    showSendModalStep(1);
});

$('#uploadModal .prev-step').on('click', function() {
    showModalStep(1);
});
function showSendModalStep(step) {
    $('#sendFileModal .modal-step').addClass('hidden').filter(`[data-step="${step}"]`).removeClass('hidden');
    $('#sendFileModal .progress-step').removeClass('active').filter(`[data-step="${step}"]`).addClass('active');
}

// File search functionality
$('#fileSearchInput').on('input', debounce(function() {
    const query = $(this).val().trim();
    if (query.length === 0) {
        renderSendingFiles(sendingFiles);
        return;
    }
    
    // Client-side search first for immediate feedback
    const filteredFiles = sendingFiles.filter(file => 
        file.file_name.toLowerCase().includes(query.toLowerCase()) ||
        (file.document_type && file.document_type.toLowerCase().includes(query.toLowerCase()))
    );
    
    renderSendingFiles(filteredFiles);
    
    // Server-side search for more comprehensive results
    $.ajax({
        url: 'api/file_operations.php',
        method: 'POST',
        data: {
            action: 'search_files',
            query: query,
            csrf_token: csrfToken
        },
        success: function(data) {
            console.log('File search response:', data);
            if (data.success) {
                renderSendingFiles(data.files);
            }
        },
        error: function(xhr, status, error) {
            console.error('File search error:', status, error, xhr.responseText);
        }
    });
}, 300));


// Handle recipient selection
$(document).on('click', '#recipientList .selectable', function() {
    $(this).toggleClass('selected');
    updateSelectedRecipientsDisplay();
});


// Update selected recipients display
function updateSelectedRecipientsDisplay() {
    const selected = $('#recipientList .selected');
    const container = $('#selectedRecipients').empty();
    
    selected.each(function() {
        const name = $(this).find('span:first').text();
        const type = $(this).data('type');
        const id = $(this).data('id');
        
        const chip = $(`
            <div class="recipient-chip" data-type="${type}" data-id="${id}">
                ${name} (${type})
                <span class="remove-chip">&times;</span>
            </div>
        `);
        container.append(chip);
    });
}

// Remove recipient chip
$(document).on('click', '.remove-chip', function() {
    const chip = $(this).closest('.recipient-chip');
    const type = chip.data('type');
    const id = chip.data('id');
    
    $(`#recipientList .selectable[data-type="${type}"][data-id="${id}"]`).removeClass('selected');
    chip.remove();
});

// Handle recipient selection
$(document).on('click', '#recipientList .selectable', function() {
    $(this).toggleClass('selected');
    updateSelectedRecipientsDisplay();
});

});