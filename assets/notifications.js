(() => {
    const badges = document.querySelectorAll('[data-notification-badge]');
    if (!badges.length) return;

    async function refreshNotificationCount() {
        try {
            const response = await fetch('notification_count.php', {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (!response.ok) return;
            const payload = await response.json();
            const count = Number(payload.unread_count || 0);
            badges.forEach((badge) => {
                badge.textContent = count > 99 ? '99+' : String(count);
                badge.hidden = count <= 0;
            });
        } catch (_) {
            // Halaman tetap berfungsi tanpa pembaruan otomatis.
        }
    }

    window.setInterval(refreshNotificationCount, 30000);
})();
