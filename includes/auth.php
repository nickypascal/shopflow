<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (!is_logged_in()) {
    $currentPage = basename((string) ($_SERVER['PHP_SELF'] ?? 'index.php'));
    $allowedRedirects = ['checkout.php', 'orders.php', 'cart.php', 'addresses.php', 'add_address.php', 'wishlist.php', 'notifications.php', 'notification_preferences.php', 'my_reviews.php', 'review_create.php', 'review_edit.php', 'my_returns.php', 'return_create.php', 'return_detail.php'];
    $redirectTo = in_array($currentPage, $allowedRedirects, true) ? $currentPage : 'index.php';

    flash('warning', 'Silakan login sebagai pengguna untuk melanjutkan.');
    redirect('login.php?redirect=' . urlencode($redirectTo));
}
