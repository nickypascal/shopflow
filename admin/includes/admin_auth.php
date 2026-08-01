<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!is_logged_in()) {
    flash('warning', 'Silakan login sebagai admin untuk membuka dashboard.');
    redirect('login.php?redirect=index.php');
}

if (!is_admin()) {
    flash('danger', 'Halaman ini hanya dapat diakses oleh admin.');
    redirect('../index.php');
}

$adminTimeoutSeconds = 3600;
$lastActivity = (int) ($_SESSION['admin_last_activity'] ?? 0);
if ($lastActivity > 0 && (time() - $lastActivity) > $adminTimeoutSeconds) {
    unset($_SESSION['user'], $_SESSION['admin_last_activity']);
    flash('warning', 'Sesi admin berakhir karena tidak aktif selama 60 menit. Silakan login kembali.');
    redirect('login.php?redirect=index.php');
}
$_SESSION['admin_last_activity'] = time();

$adminUser = current_user();
