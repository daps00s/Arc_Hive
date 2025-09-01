// UI and Interaction Management
function setupUI() {
    // Sidebar toggle
    $('.toggle-btn').on('click', function() {
        $('.sidebar').toggleClass('minimized');
        $('.top-nav, .main-content').toggleClass('resized', $('.sidebar').hasClass('minimized'));
    });

    // Popup management
    $('.exit-button').on('click', function() {
        const popupId = $(this).closest('.popup, .popup-file-selection, .popup-questionnaire').attr('id');
        closePopup(popupId);
    });

    // Activity log toggle
    $('.activity-log-icon').on('click', function() {
        toggleActivityLog();
    });

    // File search autocomplete
    $("#searchInput").autocomplete({
        source: function(request, response) {
            $.ajax({
                url: "autocomplete.php",
                dataType: "json",
                data: {
                    term: request.term,
                    csrf_token: $('meta[name="csrf-token"]').attr('content')
                },
                success: function(data) {
                    if (data.success) {
                        response(data.results);
                    } else {
                        notyf.error(data.message);
                    }
                },
                error: function() {
                    notyf.error('Error fetching autocomplete suggestions.');
                }
            });
        },
        minLength: 2,
        select: function(event, ui) {
            $("#searchInput").val(ui.item.value);
            if (ui.item.document_type) $("#document-type").val(ui.item.document_type.toLowerCase());
            if (ui.item.department_id) $("#folder").val("department-" + ui.item.department_id);
            $("#search-form").submit();
        }
    });

    // File filtering and sorting
    setupFileFilters();

    // Digital clock
    updateClock();
    setInterval(updateClock, 1000);
}

function closePopup(popupId) {
    $(`#${popupId}`).hide();
    if (popupId === 'sendFilePopup') {
        $('.file-item').removeClass('selected');
        $('#sendFilePopup').removeData('selected-file-id');
    }
    if (popupId === 'fileDetailsPopup' || popupId === 'linkHardcopyPopup') {
        resetUploadForm();
    }
}

function toggleActivityLog() {
    $('#activityLog').toggle();
}

function updateClock() {
    const now = new Date();
    const dateStr = now.toLocaleDateString('en-US', { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
    const timeStr = now.toLocaleTimeString('en-US', { 
        hour: '2-digit', 
        minute: '2-digit', 
        second: '2-digit' 
    });
    
    $('#currentDate').text(dateStr);
    $('#currentTime').text(timeStr);
}

function setupFileFilters() {
    // Personal files filtering
    $('#sort-personal-name, #sort-personal-type, #sort-personal-source').on('change', sortPersonalFiles);
    $('#hardCopyPersonalFilter').on('change', filterPersonalFilesByHardCopy);

    // Department files filtering
    $('.sort-department-name, .sort-department-type').on('change', function() {
        const deptId = $(this).data('dept-id');
        sortDepartmentFiles(deptId);
    });

    $('.hard-copy-department-filter').on('change', function() {
        const deptId = $(this).data('dept-id');
        filterDepartmentFilesByHardCopy(deptId);
    });

    // File selection popup filters
    $('#fileSearch').on('input', filterFiles);
    $('#documentTypeFilter').on('change', filterFilesByType);
    
    // View toggle
    $('#thumbnailViewButton, #listViewButton').on('click', function() {
        switchView($(this).attr('id').replace('ViewButton', ''));
    });
}

function sortPersonalFiles() {
    const sortName = $('#sort-personal-name').val();
    const sortType = $('#sort-personal-type').val();
    const sortSource = $('#sort-personal-source').val();
    const isHardCopy = $('#hardCopyPersonalFilter').is(':checked');

    const $files = $('#personalFiles .file-item').get();
    $files.sort(function(a, b) {
        let valA, valB;
        if (sortName) {
            valA = $(a).data('file-name').toLowerCase();
            valB = $(b).data('file-name').toLowerCase();
            return sortName === 'name-asc' ? valA.localeCompare(valB) : valB.localeCompare(valA);
        } else if (sortType) {
            valA = $(a).data('document-type').toLowerCase();
            valB = $(b).data('document-type').toLowerCase();
            return valA.localeCompare(valB);
        } else if (sortSource) {
            valA = $(a).data('source');
            valB = $(b).data('source');
            return valA.localeCompare(valB);
        }
        return 0;
    });

    if (isHardCopy) {
        $files.filter(function() {
            return !$(this).data('hard-copy');
        }).remove();
    }

    $('#personalFiles').empty().append($files);
}

function filterPersonalFilesByHardCopy() {
    const isHardCopy = $('#hardCopyPersonalFilter').is(':checked');
    $('#personalFiles .file-item').each(function() {
        $(this).toggle($(this).data('hard-copy') || !isHardCopy);
    });
}

function sortDepartmentFiles(deptId) {
    const $grid = $('#departmentFiles-' + deptId);
    const sortName = $grid.closest('.file-subsection').find('.sort-department-name').val();
    const sortType = $grid.closest('.file-subsection').find('.sort-department-type').val();
    const isHardCopy = $grid.closest('.file-subsection').find('.hard-copy-department-filter').is(':checked');

    const $files = $grid.find('.file-item').get();
    $files.sort(function(a, b) {
        let valA, valB;
        if (sortName) {
            valA = $(a).data('file-name').toLowerCase();
            valB = $(b).data('file-name').toLowerCase();
            return sortName === 'name-asc' ? valA.localeCompare(valB) : valB.localeCompare(valA);
        } else if (sortType) {
            valA = $(a).data('document-type').toLowerCase();
            valB = $(b).data('document-type').toLowerCase();
            return valA.localeCompare(valB);
        }
        return 0;
    });

    if (isHardCopy) {
        $files.filter(function() {
            return !$(this).data('hard-copy');
        }).remove();
    }

    $grid.empty().append($files);
}

function filterDepartmentFilesByHardCopy(deptId) {
    const isHardCopy = $('#departmentFiles-' + deptId).closest('.file-subsection').find('.hard-copy-department-filter').is(':checked');
    $('#departmentFiles-' + deptId + ' .file-item').each(function() {
        $(this).toggle($(this).data('hard-copy') || !isHardCopy);
    });
}

function filterFiles() {
    const searchTerm = $('#fileSearch').val().toLowerCase();
    $('#fileDisplay .file-item').each(function() {
        const fileName = $(this).find('p').text().toLowerCase();
        $(this).toggle(fileName.includes(searchTerm));
    });
}

function filterFilesByType() {
    const typeFilter = $('#documentTypeFilter').val().toLowerCase();
    $('#fileDisplay .file-item').each(function() {
        const docType = $(this).data('document-type').toLowerCase();
        $(this).toggle(!typeFilter || docType === typeFilter);
    });
}

function switchView(viewType) {
    const $display = $('#fileDisplay');
    $display.removeClass('thumbnail-view list-view').addClass(viewType + '-view');
    $('#thumbnailViewButton').toggleClass('active', viewType === 'thumbnail');
    $('#listViewButton').toggleClass('active', viewType === 'list');
}

// Initialize UI
$(document).ready(function() {
    setupUI();
});