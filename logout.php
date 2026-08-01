<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$wasAdmin = is_admin();
unset($_SESSION['user'], $_SESSION['admin_last_activity']);
session_regenerate_id(true);

if ($wasAdmin) {
    flash('success', 'Anda berhasil keluar dari Dashboard Admin.');
    redirect('admin/login.php');
}

flash('success', 'Anda berhasil keluar dari akun pengguna.');
redirect('index.php');
