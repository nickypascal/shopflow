<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (!is_logged_in()) {
    flash('warning', 'Silakan login sebagai admin untuk mengelola pesanan COD.');
    redirect('admin/login.php?redirect=orders.php');
}

if (!is_admin()) {
    flash('danger', 'Halaman ini hanya dapat diakses oleh admin.');
    redirect('index.php');
}
