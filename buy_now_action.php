<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php#produk');
}

if (!is_logged_in() || is_admin()) {
    flash('warning', 'Silakan login sebagai pengguna untuk melanjutkan pembelian.');
    redirect('login.php');
}

verify_csrf();

$productId = max(0, (int) ($_POST['product_id'] ?? 0));
$variantId = max(0, (int) ($_POST['variant_id'] ?? 0));
$quantity = max(1, min(999, (int) ($_POST['quantity'] ?? 1)));
$manualCode = normalize_voucher_code($_POST['manual_voucher_code'] ?? '');
$selectedCode = normalize_voucher_code($_POST['voucher_code'] ?? '');
$voucherCode = $manualCode !== '' ? $manualCode : $selectedCode;

$returnTo = 'buy_with_voucher.php?' . http_build_query([
    'product_id' => $productId,
    'variant_id' => $variantId,
    'quantity' => $quantity,
]);

if ($productId <= 0) {
    flash('danger', 'Produk tidak valid.');
    redirect('index.php#produk');
}
if ($voucherCode === '' || strlen($voucherCode) > 50) {
    flash('warning', 'Silakan pilih atau masukkan kode voucher.');
    redirect($returnTo);
}

$productStatement = $pdo->prepare(
    "SELECT id, name, category_id, price, stock, status, has_variants, default_weight_grams
     FROM products
     WHERE id = :id
     LIMIT 1"
);
$productStatement->execute(['id' => $productId]);
$product = $productStatement->fetch();
if (!$product || (string) $product['status'] !== 'active') {
    flash('danger', 'Produk tidak tersedia.');
    redirect('index.php#produk');
}

$hasVariants = (int) $product['has_variants'] === 1;
$price = (float) $product['price'];
$stock = (int) $product['stock'];
$weight = (int) $product['default_weight_grams'];

if ($hasVariants) {
    $variantStatement = $pdo->prepare(
        "SELECT id, price, stock, weight_grams, is_active
         FROM product_variants
         WHERE id = :id AND product_id = :product_id
         LIMIT 1"
    );
    $variantStatement->execute([
        'id' => $variantId,
        'product_id' => $productId,
    ]);
    $variant = $variantStatement->fetch();

    if (!$variant || (int) $variant['is_active'] !== 1) {
        flash('warning', 'Variasi produk tidak tersedia.');
        redirect('product_detail.php?id=' . $productId . '&purchase=voucher#purchase');
    }

    $price = (float) $variant['price'];
    $stock = (int) $variant['stock'];
    $weight = (int) $variant['weight_grams'];
} else {
    $variantId = 0;
}

if ($stock <= 0 || $quantity > $stock) {
    flash('warning', 'Jumlah pembelian melebihi stok yang tersedia.');
    redirect($returnTo);
}
if ($weight <= 0) {
    flash('warning', 'Berat produk belum diatur oleh admin.');
    redirect($returnTo);
}

$promotionStatement = $pdo->prepare(
    "SELECT * FROM promotions
     WHERE UPPER(TRIM(code)) = :code
     LIMIT 1"
);
$promotionStatement->execute(['code' => $voucherCode]);
$promotion = $promotionStatement->fetch();

if (!$promotion) {
    flash('warning', 'Kode voucher tidak ditemukan.');
    redirect($returnTo);
}

$now = time();
$start = strtotime((string) ($promotion['start_at'] ?? ''));
$end = strtotime((string) ($promotion['end_at'] ?? ''));
if ((int) ($promotion['is_active'] ?? 0) !== 1) {
    flash('warning', 'Voucher sedang tidak aktif.');
    redirect($returnTo);
}
if ($start !== false && $now < $start) {
    flash('warning', 'Voucher belum dapat digunakan.');
    redirect($returnTo);
}
if ($end !== false && $now > $end) {
    flash('warning', 'Voucher telah berakhir.');
    redirect($returnTo);
}
if ($promotion['total_usage_limit'] !== null
    && (int) $promotion['current_usage'] >= (int) $promotion['total_usage_limit']) {
    flash('warning', 'Kuota voucher telah habis.');
    redirect($returnTo);
}

$userId = (int) current_user()['id'];
$perUserLimit = max(1, (int) ($promotion['usage_limit_per_user'] ?? 1));
if (promotion_user_usage_count($pdo, (int) $promotion['id'], $userId) >= $perUserLimit) {
    flash('warning', 'Batas penggunaan voucher untuk akun Anda telah tercapai.');
    redirect($returnTo);
}

$subtotal = $price * $quantity;
$item = [
    'id' => $productId,
    'product_id' => $productId,
    'category_id' => (int) ($product['category_id'] ?? 0),
    'subtotal' => $subtotal,
];
$rules = get_promotion_rules($pdo, (int) $promotion['id']);
if (promotion_eligible_subtotal([$item], $rules) <= 0) {
    flash('warning', 'Voucher tidak berlaku untuk produk atau kategori ini.');
    redirect($returnTo);
}
if ($subtotal < (float) ($promotion['minimum_purchase'] ?? 0)) {
    flash('warning', 'Minimal pembelian voucher adalah ' . rupiah((float) $promotion['minimum_purchase']) . '.');
    redirect($returnTo);
}

$_SESSION['direct_purchase'] = [
    'product_id' => $productId,
    'variant_id' => $variantId,
    'quantity' => $quantity,
    'source' => 'voucher',
];
$_SESSION['voucher_code'] = $voucherCode;

flash('success', 'Voucher ' . $voucherCode . ' dipilih. Lengkapi checkout untuk membeli sekarang.');
redirect('checkout.php#voucher-checkout');
