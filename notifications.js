document.addEventListener('DOMContentLoaded', function() {
    const notificationWrapper = document.getElementById('notification-wrapper');
    if (!notificationWrapper) return;

    const bell = notificationWrapper.querySelector('.notification-bell');
    const badge = notificationWrapper.querySelector('.notification-badge');
    const panel = notificationWrapper.querySelector('.notification-panel');
    const notificationList = panel.querySelector('.notification-list');
    const markAllReadBtn = panel.querySelector('#mark-all-read');
    const clearAllBtn = panel.querySelector('#clear-all');

    const fetchNotifications = async () => {
        try {
            const response = await fetch('fetch_notifications.php');
            if (!response.ok) {
                console.error('Failed to fetch notifications');
                return;
            }
            const data = await response.json();
            updateNotificationUI(data);
        } catch (error) {
            console.error('Error fetching notifications:', error);
        }
    };

    const updateNotificationUI = (data) => {
        // Update badge
        if (data.unread_count > 0) {
            badge.textContent = data.unread_count;
            badge.style.display = 'block';
        } else {
            badge.style.display = 'none';
        }

        // Update panel
        notificationList.innerHTML = '';
        if (data.notifications.length === 0) {
            const emptyItem = document.createElement('li');
            emptyItem.className = 'notification-item empty';
            emptyItem.textContent = 'No notifications yet.';
            notificationList.appendChild(emptyItem);
        } else {
            data.notifications.forEach(notif => {
                const item = document.createElement('li');
                item.classList.add('notification-item');
                item.dataset.id = notif.id;
                if (Number(notif.is_read) === 0) {
                    item.classList.add('unread');
                }

                const timeAgo = getTimeAgo(new Date(notif.created_at));
                const link = document.createElement('a');
                link.href = notif.link || '#';
                link.className = 'notification-link';

                const message = document.createElement('div');
                message.className = 'notification-message';
                message.textContent = notif.message;

                const timestamp = document.createElement('div');
                timestamp.className = 'notification-timestamp';
                timestamp.textContent = timeAgo;

                link.appendChild(message);
                link.appendChild(timestamp);
                item.appendChild(link);

                if (Number(notif.is_read) === 0) {
                    const markButton = document.createElement('button');
                    markButton.type = 'button';
                    markButton.className = 'mark-read-btn';
                    markButton.title = 'Mark as read';
                    markButton.setAttribute('aria-label', 'Mark as read');
                    markButton.textContent = '✓';
                    item.appendChild(markButton);
                }
                notificationList.appendChild(item);
            });
        }
    };

    const markAsRead = async (notificationId) => {
        try {
            const response = await fetch('mark_notifications_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notification_id: notificationId })
            });
            if (response.ok) {
                fetchNotifications(); // Refresh list
            }
        } catch (error) {
            console.error('Error marking as read:', error);
        }
    };

    const markAllAsRead = async () => {
        try {
            const response = await fetch('mark_notifications_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mark_all_as_read: true })
            });
            if (response.ok) {
                fetchNotifications(); // Refresh list
            }
        } catch (error) {
            console.error('Error marking all as read:', error);
        }
    };

    const clearAll = async () => {
        if (!confirm('Delete all notifications?')) return;

        try {
            const response = await fetch('clear_notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
            if (response.ok) {
                fetchNotifications();
            }
        } catch (error) {
            console.error('Error clearing notifications:', error);
        }
    };

    // Event Listeners
    bell.addEventListener('click', (e) => {
        e.stopPropagation();
        panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
    });

    document.addEventListener('click', (e) => {
        if (!panel.contains(e.target)) {
            panel.style.display = 'none';
        }
    });

    markAllReadBtn.addEventListener('click', (e) => {
        e.preventDefault();
        markAllAsRead();
    });

    clearAllBtn.addEventListener('click', (e) => {
        e.preventDefault();
        clearAll();
    });

    notificationList.addEventListener('click', (e) => {
        if (e.target.classList.contains('mark-read-btn')) {
            e.preventDefault();
            e.stopPropagation();
            const notifItem = e.target.closest('.notification-item');
            const notifId = notifItem.dataset.id;
            markAsRead(notifId);
        }
    });

    // Initial fetch and polling
    fetchNotifications();
    setInterval(fetchNotifications, 15000); // Poll every 15 seconds

    // Helper to calculate time ago
    function getTimeAgo(date) {
        const seconds = Math.floor((new Date() - date) / 1000);
        let interval = seconds / 31536000;
        if (interval > 1) return Math.floor(interval) + " years ago";
        interval = seconds / 2592000;
        if (interval > 1) return Math.floor(interval) + " months ago";
        interval = seconds / 86400;
        if (interval > 1) return Math.floor(interval) + " days ago";
        interval = seconds / 3600;
        if (interval > 1) return Math.floor(interval) + " hours ago";
        interval = seconds / 60;
        if (interval > 1) return Math.floor(interval) + " minutes ago";
        return Math.floor(seconds) + " seconds ago";
    }
});
