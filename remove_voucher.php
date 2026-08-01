<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('checkout.php#voucher-checkout');
}

verify_csrf();
unset($_SESSION['voucher_code']);
flash('success', 'Voucher berhasil dihapus dari checkout.');
redirect('checkout.php#voucher-checkout');
