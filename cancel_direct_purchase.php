<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php#produk');
}

verify_csrf();
clear_direct_purchase();
unset($_SESSION['voucher_code']);
flash('success', 'Pembelian langsung dibatalkan. Isi keranjang Anda tetap tersimpan.');
redirect('index.php#produk');
