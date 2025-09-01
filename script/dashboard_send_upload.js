// File Upload and Send functionality
let selectedFile = null;
let selectedHardcopyId = null;
let storageMetadata = null;

function setupUploadHandlers() {
    // File input change handler
    $('#fileInput').on('change', function(e) {
        selectedFile = e.target.files[0];
        if (selectedFile) {
            $('#fileDetailsPopup').show();
        }
    });

    // Upload button click handler
    $('#uploadFileButton').on('click', function() {
        $('#fileInput').click();
    });

    // Document selection button
    $('#selectDocumentButton').on('click', function() {
        $('#fileSelectionPopup').show();
    });

    // Department change handler to populate sub-departments
    $('#departmentId').on('change', function() {
        const departmentId = $(this).val();
        const $subDepartmentId = $('#subDepartmentId');
        $subDepartmentId.empty().append('<option value="">No Sub-Department</option>');
        
        if (departmentId) {
            $.ajax({
                url: 'fetch_sub_departments.php',
                method: 'POST',
                data: {
                    department_id: departmentId,
                    csrf_token: $('meta[name="csrf-token"]').attr('content')
                },
                dataType: 'json',
                success: function(data) {
                    if (data.success && data.sub_departments.length > 0) {
                        data.sub_departments.forEach(dept => {
                            $subDepartmentId.append(`<option value="${dept.department_id}">${dept.department_name}</option>`);
                        });
                    } else {
                        notyf.info(data.message || 'No sub-departments available for this department.');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Sub-department fetch error:', textStatus, errorThrown, jqXHR.responseText);
                    notyf.error('Failed to fetch sub-departments. Please try again.');
                }
            });
        } else {
            $subDepartmentId.val('').trigger('change');
        }
    });

    // Hardcopy checkbox handler
    $('#hardcopyCheckbox').on('change', function() {
        if ($(this).is(':checked')) {
            $('#hardcopyOptions').show();
            if ($('input[name="hardcopyOption"]:checked').val() === 'new') {
                fetchStorageSuggestion();
            }
        } else {
            $('#hardcopyOptions').hide();
            $('#storageSuggestion').hide().empty();
        }
    });

    // Hardcopy option radio buttons
    $('input[name="hardcopyOption"]').on('change', function() {
        if ($(this).val() === 'new') {
            fetchStorageSuggestion();
        } else {
            $('#storageSuggestion').hide().empty();
        }
    });

    // Document type change handler
    $('#documentType').on('change', function() {
        const docTypeName = $(this).val();
        const dynamicFields = $('#dynamicFields');
        dynamicFields.empty();

        if (docTypeName) {
            $.ajax({
                url: 'get_document_type_field.php',
                method: 'POST',
                data: {
                    document_type_name: docTypeName,
                    csrf_token: $('meta[name="csrf-token"]').attr('content')
                },
                dataType: 'json',
                success: function(data) {
                    if (data.success && Array.isArray(data.data.fields) && data.data.fields.length > 0) {
                        data.data.fields.forEach(field => {
                            const requiredAttr = field.is_required ? 'required' : '';
                            let inputField = '';
                            switch (field.field_type) {
                                case 'text':
                                    inputField = `<input type="text" id="${field.field_name}" name="${field.field_name}" ${requiredAttr}>`;
                                    break;
                                case 'textarea':
                                    inputField = `<textarea id="${field.field_name}" name="${field.field_name}" ${requiredAttr}></textarea>`;
                                    break;
                                case 'date':
                                    inputField = `<input type="date" id="${field.field_name}" name="${field.field_name}" ${requiredAttr}>`;
                                    break;
                            }
                            dynamicFields.append(`
                                <label for="${field.field_name}">${field.field_label}${field.is_required ? ' *' : ''}:</label>
                                ${inputField}
                            `);
                        });
                    } else {
                        dynamicFields.append(`<p>${data.message || 'No metadata fields defined for this document type.'}</p>`);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('AJAX error:', textStatus, errorThrown, jqXHR.responseText);
                    notyf.error('Failed to load metadata fields.');
                }
            });
        }
    });

    // File details form submission
    $('#fileDetailsForm').on('submit', function(e) {
        e.preventDefault();
        proceedToHardcopy();
    });

    // File selection in popup
    $(document).on('click', '.select-file-button', function() {
        $('.file-item').removeClass('selected');
        const $fileItem = $(this).closest('.file-item');
        $fileItem.addClass('selected');
        $('#sendFilePopup').data('selected-file-id', $fileItem.data('file-id'));
        $('#fileSelectionPopup').hide();
        $('#sendFilePopup').show();
    });

    // Send file form submission
    $('#sendFileForm').on('submit', function(e) {
        e.preventDefault();
        sendFile();
    });

    // Link hardcopy button
    $('#linkHardcopyButton').on('click', function() {
        linkHardcopy();
    });
}

function proceedToHardcopy() {
    const documentType = $('#documentType').val();
    const departmentId = $('#departmentId').val();
    const subDepartmentId = $('#subDepartmentId').val();
    
    if (!documentType) {
        notyf.error('Please select a document type.');
        return;
    }
    
    // Automatically set access level based on department selection
    let accessLevel = 'personal';
    if (subDepartmentId) {
        accessLevel = 'sub_department';
    } else if (departmentId) {
        accessLevel = 'college';
    }
    $('#accessLevel').val(accessLevel);
    
    if ($('#hardcopyCheckbox').is(':checked') && $('input[name="hardcopyOption"]:checked').val() === 'link') {
        fetchHardcopyFiles();
        $('#fileDetailsPopup').hide();
        $('#linkHardcopyPopup').show();
    } else {
        uploadFile();
    }
}

function fetchHardcopyFiles() {
    const departmentId = $('#departmentId').val();
    const subDepartmentId = $('#subDepartmentId').val();
    const documentType = $('#documentType').val();
    
    if (!departmentId || !documentType) {
        notyf.error('Please select both a department and a document type.');
        return;
    }
    
    $.ajax({
        url: 'fetch_hardcopy_files.php',
        method: 'POST',
        data: {
            department_id: departmentId,
            sub_department_id: subDepartmentId,
            document_type: documentType,
            csrf_token: $('meta[name="csrf-token"]').attr('content')
        },
        dataType: 'json',
        success: function(data) {
            const hardcopyList = $('#hardcopyList');
            hardcopyList.empty();
            
            if (data.success && data.files.length > 0) {
                data.files.forEach(file => {
                    const metadata = file.meta_data ? JSON.parse(file.meta_data) : {};
                    const location = metadata.location_id ? 
                        `Room: ${metadata.room || 'N/A'}, Cabinet: ${metadata.cabinet || 'N/A'}, Layer: ${metadata.layer || 'N/A'}, Box: ${metadata.box || 'N/A'}, Folder: ${metadata.folder || 'N/A'}` : 
                        'No location specified';
                    
                    hardcopyList.append(`
                        <div class="file-item" data-file-id="${file.id}">
                            <input type="radio" name="hardcopyFile" value="${file.id}">
                            <span>${file.file_name} (${location})</span>
                        </div>
                    `);
                });
                
                hardcopyList.find('input').on('change', function() {
                    selectedHardcopyId = $(this).val();
                    $('#linkHardcopyButton').prop('disabled', !selectedHardcopyId);
                });
            } else {
                hardcopyList.append('<p>No hardcopy files available for this department and document type.</p>');
                $('#linkHardcopyButton').prop('disabled', true);
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            console.error('Hardcopy fetch error:', textStatus, errorThrown, jqXHR.responseText);
            notyf.error('Failed to fetch hardcopy files.');
        }
    });
}

function filterHardcopies() {
    const searchTerm = $('#hardcopySearch').val().toLowerCase();
    $('#hardcopyList .file-item').each(function() {
        const fileName = $(this).find('span').text().toLowerCase();
        $(this).toggle(fileName.includes(searchTerm));
    });
}

function linkHardcopy() {
    if (!selectedHardcopyId) {
        notyf.error('Please select a hardcopy to link.');
        return;
    }
    
    $('#linkHardcopyPopup').hide();
    uploadFile();
}

function fetchStorageSuggestion() {
    const departmentId = $('#departmentId').val();
    const subDepartmentId = $('#subDepartmentId').val();
    const documentType = $('#documentType').val();
    
    if (!departmentId || !documentType) {
        $('#storageSuggestion').html('<p>Please select both a department and a document type.</p>').show();
        return;
    }
    
    $.ajax({
        url: 'get_storage_suggestions.php',
        method: 'POST',
        data: {
            department_id: departmentId,
            sub_department_id: subDepartmentId,
            document_type: documentType,
            csrf_token: $('meta[name="csrf-token"]').attr('content')
        },
        dataType: 'json',
        success: function(data) {
            if (data.success && data.metadata && data.metadata.available_capacity > 0) {
                storageMetadata = data.metadata;
                $('#storageSuggestion').html(`
                    <p>Suggested Location: 
                    ${data.metadata.room ? `Room ${data.metadata.room}, ` : ''}
                    Cabinet ${data.metadata.cabinet || 'N/A'}, 
                    Layer ${data.metadata.layer || 'N/A'}, 
                    Box ${data.metadata.box || 'N/A'}, 
                    Folder ${data.metadata.folder || 'N/A'} 
                    (Available Capacity: ${data.metadata.available_capacity})</p>
                `).show();
            } else {
                $('#storageSuggestion').html(`<p>${data.message || 'No storage locations with available capacity.'}</p>`).show();
                storageMetadata = null;
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            console.error('Storage suggestion error:', textStatus, errorThrown, jqXHR.responseText);
            $('#storageSuggestion').html('<p>Failed to fetch storage suggestion.</p>').show();
            storageMetadata = null;
        }
    });
}

function uploadFile() {
    const documentType = $('#documentType').val();
    const departmentId = $('#departmentId').val();
    const subDepartmentId = $('#subDepartmentId').val();
    
    // Automatically determine access level
    let accessLevel = 'personal';
    if (subDepartmentId) {
        accessLevel = 'sub_department';
    } else if (departmentId) {
        accessLevel = 'college';
    }
    
    if (!documentType) {
        notyf.error('Please select a document type.');
        return;
    }

    const formData = new FormData();
    if (selectedFile) {
        formData.append('file', selectedFile);
    }
    
    formData.append('document_type_id', documentType);
    formData.append('user_id', $('#user_id').val() || $('input[name="user_id"]').val());
    formData.append('csrf_token', $('meta[name="csrf-token"]').attr('content'));
    formData.append('department_id', departmentId);
    formData.append('sub_department_id', subDepartmentId);
    formData.append('access_level', accessLevel);
    formData.append('copy_type', $('#hardcopyCheckbox').is(':checked') ? 'hard_copy' : 'soft_copy');

    if ($('#hardcopyCheckbox').is(':checked')) {
        const hardcopyOption = $('input[name="hardcopyOption"]:checked').val();
        if (hardcopyOption === 'link' && selectedHardcopyId) {
            formData.append('link_hardcopy_id', selectedHardcopyId);
        } else if (hardcopyOption === 'new' && storageMetadata) {
            formData.append('room', storageMetadata.room || '');
            formData.append('cabinet', storageMetadata.cabinet || '');
            formData.append('layer', storageMetadata.layer || '');
            formData.append('box', storageMetadata.box || '');
            formData.append('folder', storageMetadata.folder || '');
        } else if (hardcopyOption === 'new') {
            notyf.error('No storage suggestion available for new hardcopy.');
            return;
        } else {
            notyf.error('Please select a hardcopy file to link or create a new storage location.');
            return;
        }
    }

    // Add metadata fields
    $('#fileDetailsForm').find('input:not([type="file"]), textarea, select').each(function() {
        const name = $(this).attr('name');
        const value = $(this).val();
        if (name && value && !['department_id', 'sub_department_id', 'document_type_id', 'csrf_token', 'access_level', 'hard_copy_available', 'hardcopyOption'].includes(name)) {
            formData.append(`metadata[${name}]`, value);
        }
    });

    let progressBar = $('#progressBar');
    let progressContainer = $('#progressContainer');
    progressContainer.show();
    progressBar.width('0%');

    $.ajax({
        url: 'upload_handler.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        xhr: function() {
            const xhr = new window.XMLHttpRequest();
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const percentComplete = (e.loaded / e.total) * 100;
                    progressBar.width(percentComplete + '%');
                }
            }, false);
            return xhr;
        },
        success: function(data) {
            progressContainer.hide();
            if (data.success) {
                notyf.success(data.message || 'File uploaded successfully!');
                $('#fileDetailsPopup').hide();
                $('#fileInput').val('');
                selectedFile = null;
                selectedHardcopyId = null;
                storageMetadata = null;
                $('#fileDetailsForm')[0].reset();
                $('#dynamicFields').empty();
                $('#hardcopyOptions').hide();
                $('#storageSuggestion').hide().empty();
                $('input[name="hardcopyOption"][value="new"]').prop('checked', true);
                
                // Refresh file list if on dashboard
                if (typeof refreshFileList === 'function') {
                    refreshFileList();
                }
            } else {
                notyf.error(data.message || 'Upload failed. Please try again.');
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            progressContainer.hide();
            console.error('Upload error:', textStatus, errorThrown, jqXHR.responseText);
            notyf.error('Upload failed: ' + (jqXHR.responseJSON?.message || 'Server error. Please try again.'));
        }
    });
}

function sendFile() {
    const selectedFileId = $('#sendFilePopup').data('selected-file-id');
    const recipientId = $('#recipientId').val();
    const message = $('#sendMessage').val();
    
    if (!selectedFileId || !recipientId) {
        notyf.error('Please select a file and a recipient.');
        return;
    }
    
    $.ajax({
        url: 'send_file_handler.php',
        method: 'POST',
        data: {
            file_id: selectedFileId,
            recipient_id: recipientId,
            message: message,
            csrf_token: $('meta[name="csrf-token"]').attr('content')
        },
        dataType: 'json',
        success: function(data) {
            if (data.success) {
                notyf.success(data.message || 'File sent successfully!');
                $('#sendFilePopup').hide();
                $('#fileSelectionPopup').hide();
                $('#sendFileForm')[0].reset();
            } else {
                notyf.error(data.message || 'Failed to send file.');
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            console.error('Send file error:', textStatus, errorThrown, jqXHR.responseText);
            notyf.error('Failed to send file: ' + (jqXHR.responseJSON?.message || 'Server error. Please try again.'));
        }
    });
}

// Close popups when clicking outside
$(document).on('click', function(e) {
    if ($(e.target).hasClass('popup')) {
        $(e.target).hide();
    }
});

// Initialize when document is ready
$(document).ready(function() {
    setupUploadHandlers();
});