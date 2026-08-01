<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('wishlist.php');
}

verify_csrf();

$returnTo = safe_customer_redirect((string) ($_POST['return_to'] ?? 'wishlist.php'), 'wishlist.php');
if (!is_logged_in() || is_admin()) {
    flash('warning', 'Silakan login sebagai pengguna untuk mengelola wishlist.');
    redirect('login.php?redirect=' . urlencode($returnTo));
}

$userId = customer_id();
$action = trim((string) ($_POST['action'] ?? ''));
$productId = max(0, (int) ($_POST['product_id'] ?? 0));
$variantId = max(0, (int) ($_POST['variant_id'] ?? 0));

if ($productId <= 0) {
    flash('danger', 'Produk wishlist tidak valid.');
    redirect($returnTo);
}

$productStatement = $pdo->prepare(
    "SELECT p.id, p.name, p.price, p.status, p.has_variants,
            (SELECT MIN(v.price)
             FROM product_variants v
             WHERE v.product_id = p.id AND v.is_active = 1) AS minimum_variant_price
     FROM products p
     WHERE p.id = :id
     LIMIT 1"
);
$productStatement->execute(['id' => $productId]);
$product = $productStatement->fetch();

if (!$product) {
    flash('danger', 'Produk tidak ditemukan.');
    redirect($returnTo);
}

$existingStatement = $pdo->prepare(
    'SELECT id, preferred_variant_id
     FROM wishlist_items
     WHERE user_id = :user_id AND product_id = :product_id
     LIMIT 1'
);
$existingStatement->execute([
    'user_id' => $userId,
    'product_id' => $productId,
]);
$existing = $existingStatement->fetch();

if ($action === 'remove') {
    $delete = $pdo->prepare(
        'DELETE FROM wishlist_items
         WHERE user_id = :user_id AND product_id = :product_id'
    );
    $delete->execute([
        'user_id' => $userId,
        'product_id' => $productId,
    ]);
    flash('success', 'Produk dihapus dari wishlist.');
    redirect($returnTo);
}

if ($action === 'toggle' && $existing) {
    $delete = $pdo->prepare(
        'DELETE FROM wishlist_items
         WHERE id = :id AND user_id = :user_id'
    );
    $delete->execute([
        'id' => (int) $existing['id'],
        'user_id' => $userId,
    ]);
    flash('success', 'Produk dihapus dari wishlist.');
    redirect($returnTo);
}

if ((string) $product['status'] !== 'active') {
    flash('warning', 'Produk sedang tidak aktif dan tidak dapat ditambahkan ke wishlist.');
    redirect($returnTo);
}

$selectedVariant = null;
if ($variantId > 0) {
    $variantStatement = $pdo->prepare(
        'SELECT id, product_id, variant_name, price, is_active
         FROM product_variants
         WHERE id = :id AND product_id = :product_id
         LIMIT 1'
    );
    $variantStatement->execute([
        'id' => $variantId,
        'product_id' => $productId,
    ]);
    $selectedVariant = $variantStatement->fetch();

    if (!$selectedVariant || (int) $selectedVariant['is_active'] !== 1) {
        flash('danger', 'Variasi yang dipilih tidak tersedia untuk produk ini.');
        redirect($returnTo);
    }
}

if ($action === 'update_variant') {
    if (!$existing) {
        flash('warning', 'Produk belum tersimpan di wishlist.');
        redirect($returnTo);
    }
    if ((int) $product['has_variants'] !== 1 || !$selectedVariant) {
        flash('danger', 'Pilih variasi produk yang valid.');
        redirect($returnTo);
    }

    $update = $pdo->prepare(
        'UPDATE wishlist_items
         SET preferred_variant_id = :variant_id,
             price_when_added = :price_when_added,
             updated_at = NOW()
         WHERE id = :id AND user_id = :user_id AND product_id = :product_id'
    );
    $update->execute([
        'variant_id' => (int) $selectedVariant['id'],
        'price_when_added' => (float) $selectedVariant['price'],
        'id' => (int) $existing['id'],
        'user_id' => $userId,
        'product_id' => $productId,
    ]);
    flash('success', 'Variasi pilihan wishlist berhasil diperbarui.');
    redirect($returnTo);
}

if ($action !== 'toggle') {
    flash('danger', 'Aksi wishlist tidak dikenal.');
    redirect($returnTo);
}

$priceWhenAdded = $selectedVariant
    ? (float) $selectedVariant['price']
    : ((int) $product['has_variants'] === 1 && $product['minimum_variant_price'] !== null
        ? (float) $product['minimum_variant_price']
        : (float) $product['price']);

$insert = $pdo->prepare(
    'INSERT INTO wishlist_items (
        user_id, product_id, preferred_variant_id, price_when_added
     ) VALUES (
        :user_id, :product_id, :preferred_variant_id, :price_when_added
     )'
);
$insert->execute([
    'user_id' => $userId,
    'product_id' => $productId,
    'preferred_variant_id' => $selectedVariant ? (int) $selectedVariant['id'] : null,
    'price_when_added' => $priceWhenAdded,
]);

$message = $selectedVariant
    ? $product['name'] . ' (' . $selectedVariant['variant_name'] . ') disimpan ke wishlist.'
    : $product['name'] . ' disimpan ke wishlist.';
flash('success', $message);
redirect($returnTo);
