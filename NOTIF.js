// script/dashboard.js
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
$('.tab-button').on('click', function() {
$('.tab-button').removeClass('active');
$(this).addClass('active');
$('.tab-content').addClass('hidden');
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
$('#dynamicFields').empty(); // Reset dynamic fields
});

// Send file modal
$('#sendFileButton').on('click', function() {
$('#sendFileModal').removeClass('hidden');
loadRecipients();
});

// Close modals
$('.close-modal').on('click', function() {
$(this).closest('.modal').addClass('hidden');
});

// Upload modal steps
$('.next-step').on('click', function() {
if ($('#fileInput').val() || $('#filePreviewArea').children().length > 0) {
showModalStep(2);
} else {
notyf.error('Please select at least one file');
}
});

$('.prev-step').on('click', function() {
showModalStep(1);
});

function showModalStep(step) {
$('.modal-step').addClass('hidden').filter(`[data-step="${step}"]`).removeClass('hidden');
$('.progress-step').removeClass('active').filter(`[data-step="${step}"]`).addClass('active');
}

// Drag and drop for file upload
const dragDropArea = $('.drag-drop-area');
const fileInput = $('#fileInput');

dragDropArea.on('dragover', function(e) {
e.preventDefault();
$(this).addClass('drag-over');
});

dragDropArea.on('dragleave', function() {
$(this).removeClass('drag-over');
});

dragDropArea.on('drop', function(e) {
e.preventDefault();
$(this).removeClass('drag-over');
const files = e.originalEvent.dataTransfer.files;
handleFiles(files);
});

// Choose File button
$('.choose-file-button').on('click', function() {
fileInput.click();
});

fileInput.on('change', function() {
handleFiles(this.files);
});

function handleFiles(files) {
const previewArea = $('#filePreviewArea');
previewArea.empty();
Array.from(files).forEach(file => {
const previewItem = $('<div class="file-preview-item"></div>');
const fileName = $('<p></p>').text(file.name);
previewItem.append(fileName);
if (file.type.startsWith('image/')) {
const reader = new FileReader();
reader.onload = function(e) {
previewItem.prepend(`<img src="${e.target.result}" alt="${file.name}">`);
};
reader.readAsDataURL(file);
}
previewArea.append(previewItem);
});
}

// Department change triggers sub-department load
$('#uploadType').on('change', function() {
if ($(this).val() === 'department') {
$('#departmentSelect').removeClass('hidden');
} else {
$('#departmentSelect').addClass('hidden');
$('select[name="sub_department_id"]').empty().append('<option value="">No Sub-Department</option>');
}
});

$('select[name="department_id"]').on('change', function() {
const deptId = $(this).val();
if (deptId) {
$.ajax({
url: 'api/file_operations.php',
method: 'POST',
data: {
action: 'fetch_sub_departments',
department_id: deptId,
csrf_token: $('meta[name="csrf-token"]').attr('content')
},
success: function(data) {
if (data.success) {
const subDeptSelect = $('select[name="sub_department_id"]');
subDeptSelect.empty().append('<option value="">No Sub-Department</option>');
data.sub_departments.forEach(dept => {
subDeptSelect.append(`<option value="${dept.department_id}">${dept.department_name}</option>`);
});
} else {
notyf.error(data.message || 'No sub-departments found');
}
},
error: function() {
notyf.error('Failed to load sub-departments');
}
});
} else {
$('select[name="sub_department_id"]').empty().append('<option value="">No Sub-Department</option>');
}
});

// Dynamic fields based on document type
$('#documentType').on('change', function() {
const docTypeId = $(this).val();
const dynamicFields = $('#dynamicFields');
dynamicFields.empty();
if (docTypeId) {
$.ajax({
url: 'api/file_operations.php',
method: 'POST',
data: {
action: 'fetch_doc_fields',
document_type_id: docTypeId,
csrf_token: $('meta[name="csrf-token"]').attr('content')
},
success: function(data) {
if (data.success) {
// Assuming document_types table or a related table has metadata for fields
// If no metadata exists, use type_name as a fallback
const fields = data.fields || [{ id: 'default', name: 'Details', type: 'text', required: false }];
fields.forEach(field => {
dynamicFields.append(`
<label for="field_${field.id}">${field.name}</label>
<input type="${field.type || 'text'}" name="fields[${field.id}]" placeholder="${field.name}" ${field.required ? 'required' : '' }>
`);
});
} else {
notyf.error(data.message || 'No fields available for this document type');
}
},
error: function() {
notyf.error('Failed to load document fields');
}
});
}
});

// Hardcopy options
$('#hardcopyCheckbox').on('change', function() {
$('#hardcopyOptions').toggleClass('hidden', !this.checked);
if (this.checked && $('input[name="hardcopyOption"][value="new"]').is(':checked')) {
fetchStorageSuggestion();
}
});

$('input[name="hardcopyOption"]').on('change', function() {
const suggestionDiv = $('#storageSuggestion');
const searchContainer = $('#hardcopySearchContainer');
if ($(this).val() === 'new') {
fetchStorageSuggestion();
suggestionDiv.removeClass('hidden');
searchContainer.addClass('hidden').empty();
} else {
suggestionDiv.addClass('hidden').empty();
searchContainer.removeClass('hidden').html(`
<input type="text" id="hardcopySearch" placeholder="Search hardcopy files...">
<div id="hardcopyList" class="autocomplete-suggestions"></div>
`);
initHardcopySearch();
}
});

function fetchStorageSuggestion() {
const deptId = $('select[name="department_id"]').val();
const subDeptId = $('select[name="sub_department_id"]').val();
$.ajax({
url: 'api/file_operations.php',
method: 'POST',
data: {
action: 'fetch_storage_suggestion',
department_id: deptId,
sub_department_id: subDeptId,
csrf_token: $('meta[name="csrf-token"]').attr('content')
},
success: function(data) {
if (data.success) {
$('#storageSuggestion').html(`Suggested Location: ${data.location || 'N/A'}`).removeClass('hidden');
} else {
notyf.error(data.message || 'No storage suggestion available');
}
},
error: function() {
notyf.error('Failed to fetch storage suggestion');
}
});
}

function initHardcopySearch() {
$('#hardcopySearch').on('input', debounce(function() {
const query = $(this).val();
$.ajax({
url: 'api/file_operations.php',
method: 'POST',
data: {
action: 'search_hardcopy',
query: query,
csrf_token: $('meta[name="csrf-token"]').attr('content')
},
success: function(data) {
if (data.success) {
const hardcopyList = $('#hardcopyList');
hardcopyList.empty();
data.files.forEach(file => {
hardcopyList.append(`
<div class="autocomplete-suggestion" data-file-id="${file.file_id}">${file.file_name}</div>
`);
});
} else {
notyf.error(data.message || 'No hardcopy files found');
}
},
error: function() {
notyf.error('Failed to search hardcopy files');
}
});
}, 300));
}

// Autocomplete for recipients
function loadRecipients() {
$.ajax({
url: 'api/file_operations.php',
method: 'POST',
data: {
action: 'fetch_recipients',
csrf_token: $('meta[name="csrf-token"]').attr('content')
},
success: function(data) {
if (data.success) {
const recipients = [...data.users, ...data.departments];
$('#recipientSearch').on('input', debounce(function() {
const query = $(this).val().toLowerCase();
$('.autocomplete-suggestions').remove();
const suggestions = recipients.filter(r =>
r.name.toLowerCase().includes(query)
);
const suggestionList = $('<div class="autocomplete-suggestions"></div>');
suggestions.forEach(r => {
suggestionList.append(`
<div class="autocomplete-suggestion" data-id="${r.id}" data-type="${r.type}">
    <span class="avatar">${r.name.charAt(0)}</span>
    ${r.name} <small>(${r.type})</small>
</div>
`);
});
$('#recipientSearch').after(suggestionList);
}, 300));

$(document).on('click', '.autocomplete-suggestion', function() {
const id = $(this).data('id');
const type = $(this).data('type');
const name = $(this).text().split(' (')[0];
$('#recipientList').append(`
<div class="recipient-item selected" data-id="${id}" data-type="${type}">
    <span>${name} (${type})</span>
    <span class="remove-chip"><i class="fas fa-times"></i></span>
</div>
`);
$('#recipientSearch').val('');
$('.autocomplete-suggestions').remove();
});

$('#recipientList').on('click', '.remove-chip', function() {
$(this).closest('.recipient-item').remove();
});
} else {
notyf.error(data.message || 'Failed to load recipients');
}
},
error: function() {
notyf.error('Failed to load recipients');
}
});
}

// File selection for send modal
$('#fileSelectionGrid').on('click', '.file-item.selectable', function() {
$(this).toggleClass('selected');
});

// File operations
$('.kebab-menu').on('click', function(e) {
e.stopPropagation();
$('.file-menu').addClass('hidden');
$(this).siblings('.file-menu').toggleClass('hidden');
});

$(document).on('click', function(e) {
if (!$(e.target).closest('.kebab-menu, .file-menu').length) {
$('.file-menu').addClass('hidden');
}
});

$('.rename-file').on('click', function() {
const fileId = $(this).closest('.file-item').data('file-id');
const newName = prompt('Enter new file name:');
if (newName) {
$.ajax({
url: 'api/file_operations.php',
method: 'POST',
data: {
action: 'rename_file',
file_id: fileId,
new_name: newName,
csrf_token: $('meta[name="csrf-token"]').attr('content')
},
success: function(response) {
if (response.success) {
notyf.success('File renamed successfully');
location.reload();
} else {
notyf.error(response.message || 'Failed to rename file');
}
},
error: function() {
notyf.error('Failed to rename file');
}
});
}
});

$('.delete-file').on('click', function() {
if (confirm('Are you sure you want to delete this file?')) {
const fileId = $(this).closest('.file-item').data('file-id');
$.ajax({
url: 'api/file_operations.php',
method: 'POST',
data: {
action: 'delete_file',
file_id: fileId,
csrf_token: $('meta[name="csrf-token"]').attr('content')
},
success: function(response) {
if (response.success) {
notyf.success('File deleted successfully');
location.reload();
} else {
notyf.error(response.message || 'Failed to delete file');
}
},
error: function() {
notyf.error('Failed to delete file');
}
});
}
});

$('.download-file').on('click', function() {
const fileId = $(this).closest('.file-item').data('file-id');
$.ajax({
url: 'api/file_operations.php',
method: 'POST',
data: {
action: 'download_file',
file_id: fileId,
csrf_token: $('meta[name="csrf-token"]').attr('content')
},
success: function(response) {
if (response.success) {
window.location.href = response.download_url;
} else {
notyf.error(response.message || 'Failed to download file');
}
},
error: function() {
notyf.error('Failed to download file');
}
});
});

$('.share-file').on('click', function() {
const fileId = $(this).closest('.file-item').data('file-id');
$(`.file-item[data-file-id="${fileId}"]`).addClass('selected');
$('#sendFileModal').removeClass('hidden');
loadRecipients();
});

$('.file-info').on('click', function() {
const fileId = $(this).closest('.file-item').data('file-id');
$.ajax({
url: 'api/file_operations.php',
method: 'POST',
data: {
action: 'fetch_file_info',
file_id: fileId,
csrf_token: $('meta[name="csrf-token"]').attr('content')
},
success: function(data) {
if (data.success) {
$('#fileInfoName').text(data.file_info.file_name || 'N/A');
$('#filePreview').html(data.file_info.preview_url ? `<img src="${data.file_info.preview_url}" alt="File Preview">` : '<p>No preview available</p>');
$('#fileAccess').text(data.file_info.access_level || 'N/A');
$('#fileQR').text(data.file_info.qr_code || 'N/A');
$('#fileType').text(data.file_info.file_type || 'N/A');
$('#fileSize').text(data.file_info.file_size || 'N/A');
$('#fileCategory').text(data.file_info.document_type || 'N/A');
$('#fileUploader').text(data.file_info.uploader || 'N/A');
$('#fileUploadDate').text(data.file_info.upload_date || 'N/A');
$('#filePhysicalLocation').text(data.file_info.physical_location || 'N/A');
$('#fileSentTo').text(data.file_info.sent_to || 'N/A');
$('#fileReceivedBy').text(data.file_info.received_by || 'N/A');
$('#fileCopiedBy').text(data.file_info.copied_by || 'N/A');
$('#fileRenamedTo').text(data.file_info.renamed_to || 'N/A');
$('#fileInfoSidebar').removeClass('hidden');
} else {
notyf.error(data.message || 'Failed to load file info');
}
},
error: function() {
notyf.error('Failed to load file info');
}
});
});

// File info sidebar
$('#closeFileInfo').on('click', function() {
$('#fileInfoSidebar').addClass('hidden');
});

$('.tab-button').on('click', function() {
$('.tab-button').removeClass('active');
$(this).addClass('active');
$('.tab-content').addClass('hidden');
$(`#${$(this).data('tab')}Tab`).removeClass('hidden');
});

// Notification actions
$('.mark-all-read').on('click', function() {
$.ajax({
url: 'api/file_operations.php',
method: 'POST',
data: {
action: 'mark_notifications_read',
csrf_token: $('meta[name="csrf-token"]').attr('content')
},
success: function(response) {
if (response.success) {
$('.notification-item').removeClass('pending');
$('.notification-badge').remove();
notyf.success('All notifications marked as read');
location.reload();
} else {
notyf.error(response.message || 'Failed to mark notifications as read');
}
},
error: function() {
notyf.error('Failed to mark notifications as read');
}
});
});

$('.accept-file').on('click', function() {
const notificationId = $(this).closest('.notification-item').data('notification-id');
const fileId = $(this).closest('.notification-item').data('file-id');
$.ajax({
url: 'api/file_operations.php',
method: 'POST',
data: {
action: 'accept_file',
notification_id: notificationId,
file_id: fileId,
csrf_token: $('meta[name="csrf-token"]').attr('content')
},
success: function(response) {
if (response.success) {
notyf.success('File accepted');
location.reload();
} else {
notyf.error(response.message || 'Failed to accept file');
}
},
error: function() {
notyf.error('Failed to accept file');
}
});
});

$('.deny-file').on('click', function() {
const notificationId = $(this).closest('.notification-item').data('notification-id');
const fileId = $(this).closest('.notification-item').data('file-id');
$.ajax({
url: 'api/file_operations.php',
method: 'POST',
data: {
action: 'deny_file',
notification_id: notificationId,
file_id: fileId,
csrf_token: $('meta[name="csrf-token"]').attr('content')
},
success: function(response) {
if (response.success) {
notyf.success('File denied');
location.reload();
} else {
notyf.error(response.message || 'Failed to deny file');
}
},
error: function() {
notyf.error('Failed to deny file');
}
});
});

// File upload form submission
$('#uploadForm').on('submit', function(e) {
e.preventDefault();
const formData = new FormData(this);
formData.append('action', 'upload_file');
formData.append('csrf_token', $('meta[name="csrf-token"]').attr('content'));

$.ajax({
url: 'api/file_operations.php',
method: 'POST',
data: formData,
processData: false,
contentType: false,
success: function(response) {
if (response.success) {
notyf.success('File uploaded successfully');
$('#uploadModal').addClass('hidden');
location.reload();
} else {
notyf.error(response.message || 'Failed to upload file');
}
},
error: function() {
notyf.error('Failed to upload file');
}
});
});

// File send form submission
$('#sendFileForm').on('submit', function(e) {
e.preventDefault();
const selectedFiles = $('.file-item.selected', '#fileSelectionGrid').map(function() {
return $(this).data('file-id');
}).get();
const recipients = $('.recipient-item.selected', '#recipientList').map(function() {
return { id: $(this).data('id'), type: $(this).data('type') };
}).get();
const message = $('textarea[name="message"]').val();

if (selectedFiles.length === 0) {
notyf.error('Please select at least one file');
return;
}
if (recipients.length === 0) {
notyf.error('Please select at least one recipient');
return;
}

$.ajax({
url: 'api/file_operations.php',
method: 'POST',
data: {
action: 'send_file',
file_ids: selectedFiles,
recipients: recipients,
message: message,
csrf_token: $('meta[name="csrf-token"]').attr('content')
},
success: function(response) {
if (response.success) {
notyf.success('Files sent successfully');
$('#sendFileModal').addClass('hidden');
location.reload();
} else {
notyf.error(response.message || 'Failed to send files');
}
},
error: function() {
notyf.error('Failed to send files');
}
});
});

// Sorting for recent files and send modal files
$('#recentSort, #fileSort').on('change', function() {
const sortType = $(this).val();
const container = $(this).closest('.recent-files, #sendFileModal');
const items = $('.file-item', container).get();
const tab = $('.tab-button.active').data('tab') || 'uploaded';

// Fetch sorted data from server to ensure accurate sorting
$.ajax({
url: 'api/file_operations.php',
method: 'POST',
data: {
action: `fetch_${tab}_files`,
sort: sortType,
csrf_token: $('meta[name="csrf-token"]').attr('content')
},
success: function(data) {
if (data.success) {
const grid = $(`#${tab}Tab .files-grid`, container).empty();
if (data.files.length === 0) {
grid.append('<p class="no-files">No files available.</p>');
} else {
data.files.forEach(file => {
const meta = `
Type: ${file.document_type || 'Unknown'} |
${tab === 'sent' ? 'Sent' : tab === 'received' ? 'Received' : 'Uploaded'}: ${new Date(file.upload_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} |
${tab === 'sent' ? `By: ${file.sender_username || 'Unknown'}` : tab === 'received' ? `From: ${file.sender_username || 'Unknown'}` : `Dept: ${file.department_name || 'None'}`}
`;
const item = `
<div class="file-item ${container.is('#sendFileModal') ? 'selectable' : ''}" data-file-id="${file.file_id}">
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
notyf.error(data.message || 'Failed to sort files');
}
},
error: function() {
notyf.error('Failed to sort files');
}
});
});

// Search functionality
$('#searchInput').on('input', debounce(function() {
const query = $(this).val();
$.ajax({
url: 'api/file_operations.php',
method: 'POST',
data: {
action: 'search_files',
query: query,
csrf_token: $('meta[name="csrf-token"]').attr('content')
},
success: function(data) {
if (data.success) {
const grid = $('#uploadedTab .files-grid').empty();
if (data.files.length === 0) {
grid.append('<p class="no-files">No files found.</p>');
} else {
data.files.forEach(file => {
grid.append(`
<div class="file-item" data-file-id="${file.file_id}">
    <p class="file-name">${file.file_name}</p>
    <p class="file-meta">
        Type: ${file.document_type || 'Unknown'} |
        Uploaded: ${new Date(file.upload_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} |
        Dept: ${file.department_name || 'None'}
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
`);
});
}
} else {
notyf.error(data.message || 'No files found');
}
},
error: function() {
notyf.error('Failed to search files');
}
});
}, 300));

// Notifications toggle
$('.notifications-toggle').on('click', function() {
$('.notifications-sidebar').toggleClass('hidden');
});
});