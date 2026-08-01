<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('checkout.php#voucher-checkout');
}

verify_csrf();

$code = normalize_voucher_code($_POST['voucher_code'] ?? '');
if ($code === '' || strlen($code) > 50) {
    flash('warning', 'Masukkan kode voucher yang valid.');
    redirect('checkout.php#voucher-checkout');
}

$statement = $pdo->prepare(
    "SELECT id, code, is_active, start_at, end_at, total_usage_limit, current_usage
     FROM promotions
     WHERE UPPER(TRIM(code)) = :code
     LIMIT 1"
);
$statement->execute(['code' => $code]);
$promotion = $statement->fetch();

if (!$promotion) {
    flash('warning', 'Kode voucher tidak ditemukan.');
    redirect('checkout.php#voucher-checkout');
}

$now = time();
$start = strtotime((string) $promotion['start_at']);
$end = strtotime((string) $promotion['end_at']);
if ((int) $promotion['is_active'] !== 1) {
    flash('warning', 'Voucher sedang tidak aktif.');
} elseif ($start !== false && $now < $start) {
    flash('warning', 'Voucher belum dapat digunakan.');
} elseif ($end !== false && $now > $end) {
    flash('warning', 'Voucher telah berakhir.');
} elseif ($promotion['total_usage_limit'] !== null && (int) $promotion['current_usage'] >= (int) $promotion['total_usage_limit']) {
    flash('warning', 'Kuota voucher telah habis.');
} else {
    $_SESSION['voucher_code'] = $code;
    flash('success', 'Voucher ' . $code . ' diterapkan. Nilai potongan akan menyesuaikan checkout.');
}

redirect('checkout.php#voucher-checkout');
