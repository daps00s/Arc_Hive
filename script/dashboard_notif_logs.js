/**
 * Notification and Activity Log Management
 * Uses Server-Sent Events for real-time updates and efficient event handling.
 */
class NotificationManager {
    constructor() {
        this.notyf = new Notyf();
        this.container = $('.notification-log .log-entries');
        this.eventSource = null;
        this.csrfToken = $('meta[name="csrf-token"]').attr('content');
    }

    /**
     * Initializes Server-Sent Events for real-time notifications.
     */
    initSSE() {
        if (this.eventSource) {
            this.eventSource.close();
        }
        this.eventSource = new EventSource(`fetch_notifications.php?csrf_token=${encodeURIComponent(this.csrfToken)}`);
        this.eventSource.onmessage = (event) => {
            const data = JSON.parse(event.data);
            if (data.success) {
                this.renderNotifications(data.notifications);
            } else {
                this.notyf.error(data.message);
            }
        };
        this.eventSource.onerror = () => {
            this.notyf.error('Failed to connect to notification stream. Retrying...');
            setTimeout(() => this.initSSE(), 5000);
        };
    }

    /**
     * Renders notifications in the container.
     * @param {Array} notifications List of notification objects.
     */
    renderNotifications(notifications) {
        const currentIds = this.container.find('.notification-item').map((_, el) => $(el).data('notification-id')).get();
        const newIds = notifications.map(n => n.id);

        if (JSON.stringify(currentIds) !== JSON.stringify(newIds)) {
            this.container.empty();
            if (notifications.length > 0) {
                notifications.forEach(n => {
                    const notificationClass = n.status === 'pending' ? 'pending-access' : 'processed-access';
                    const escapedMessage = $('<div/>').text(n.message).html();
                    this.container.append(`
                        <div class="log-entry notification-item ${notificationClass}"
                             data-notification-id="${n.id}"
                             data-file-id="${n.file_id || ''}"
                             data-message="${escapedMessage}"
                             data-status="${n.status}">
                            <i class="fas fa-bell"></i>
                            <p>${escapedMessage}</p>
                            <span>${new Date(n.timestamp).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</span>
                        </div>
                    `);
                });
            } else {
                this.container.html('<div class="log-entry no-notifications"><p>No new notifications.</p></div>');
            }
        }
    }

    /**
     * Handles notification click events.
     * @param {Event} e The click event.
     */
    handleNotificationClick(e) {
        const $item = $(e.currentTarget);
        const status = $item.data('status');
        const fileId = $item.data('file-id');
        const notificationId = $item.data('notification-id');
        const message = $item.data('message');

        if (status !== 'pending') {
            $('#alreadyProcessedMessage').text('This request has already been processed.');
            $('#alreadyProcessedPopup').show();
            return;
        }

        $('#fileAcceptanceTitle').text('Review File');
        $('#fileAcceptanceMessage').text(message);
        $('#fileAcceptancePopup').data('notification-id', notificationId).data('file-id', fileId).show();
        this.showFilePreview(fileId);
    }

    /**
     * Loads and displays a file preview.
     * @param {number} fileId The ID of the file to preview.
     */
    showFilePreview(fileId) {
        if (!fileId) {
            $('#filePreview').html('<p>No file selected.</p>');
            return;
        }
        $.ajax({
            url: 'get_file_preview.php',
            method: 'GET',
            data: {
                file_id: fileId,
                csrf_token: this.csrfToken
            },
            success: (data) => {
                if (data.success) {
                    $('#filePreview').html(data.preview_content);
                } else {
                    $('#filePreview').html('<p>Unable to load preview.</p>');
                    this.notyf.error(data.message);
                }
            },
            error: () => {
                $('#filePreview').html('<p>Unable to load preview.</p>');
                this.notyf.error('Failed to load file preview.');
            }
        });
    }

    /**
     * Handles file acceptance or denial actions.
     * @param {number} notificationId The notification ID.
     * @param {number} fileId The file ID.
     * @param {string} action 'accept' or 'deny'.
     */
    handleFileAction(notificationId, fileId, action) {
        if (!notificationId || !fileId) {
            this.notyf.error('Invalid notification or file ID.');
            return;
        }
        $.ajax({
            url: 'handle_file_acceptance.php',
            method: 'POST',
            data: {
                notification_id: notificationId,
                file_id: fileId,
                action: action,
                csrf_token: this.csrfToken
            },
            dataType: 'json',
            success: (response) => {
                if (response.success) {
                    this.notyf.success(response.message);
                    $('#fileAcceptancePopup').hide();
                    $(`.notification-item[data-notification-id="${notificationId}"]`)
                        .removeClass('pending-access')
                        .addClass('processed-access')
                        .off('click')
                        .find('p').text(response.message + ' (Processed)');
                    if (response.redirect) {
                        window.location.href = response.redirect;
                    }
                } else {
                    this.notyf.error(response.message);
                }
            },
            error: () => {
                this.notyf.error('Error processing file action.');
            }
        });
    }

    /**
     * Sets up activity log toggle behavior.
     */
    setupActivityLog() {
        const $log = $('#activityLog');
        $('.activity-log-icon').on('click', (e) => {
            e.stopPropagation();
            $log.toggle();
            if ($log.is(':visible')) {
                $log.focus();
            }
        });

        $(document).on('click', (event) => {
            if (!$(event.target).closest('#activityLog, .activity-log-icon').length) {
                $log.hide();
            }
        });

        $log.on('keydown', (e) => {
            if (e.key === 'Escape') {
                $log.hide();
                $('.activity-log-icon').focus();
            }
        });
    }

    /**
     * Initializes the notification system.
     */
    init() {
        this.setupActivityLog();
        this.container.on('click', '.notification-item', (e) => this.handleNotificationClick(e));
        $('#acceptFileButton').on('click', () => {
            const notificationId = $('#fileAcceptancePopup').data('notification-id');
            const fileId = $('#fileAcceptancePopup').data('file-id');
            this.handleFileAction(notificationId, fileId, 'accept');
        });
        $('#denyFileButton').on('click', () => {
            const notificationId = $('#fileAcceptancePopup').data('notification-id');
            const fileId = $('#fileAcceptancePopup').data('file-id');
            this.handleFileAction(notificationId, fileId, 'deny');
        });
        this.initSSE();
    }
}

$(document).ready(() => {
    const manager = new NotificationManager();
    manager.init();
});