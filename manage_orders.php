<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (!is_logged_in()) {
    flash('warning', 'Silakan login sebagai admin.');
    redirect('admin/login.php?redirect=orders.php');
}
if (!is_admin()) {
    flash('danger', 'Halaman ini hanya dapat diakses admin.');
    redirect('index.php');
}
redirect('admin/orders.php');
