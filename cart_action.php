<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('cart.php');
}

verify_csrf();

$action = trim((string) ($_POST['action'] ?? ''));
$productId = (int) ($_POST['product_id'] ?? 0);
$variantId = max(0, (int) ($_POST['variant_id'] ?? 0));
$_SESSION['cart'] = normalize_cart($_SESSION['cart'] ?? []);

if ($action === 'clear') {
    $_SESSION['cart'] = [];
    flash('success', 'Keranjang berhasil dikosongkan.');
    redirect('cart.php');
}

if ($productId < 1) {
    flash('danger', 'Produk tidak valid.');
    redirect('cart.php');
}

$productStatement = $pdo->prepare(
    'SELECT id, name, stock, status, has_variants
     FROM products WHERE id = :id LIMIT 1'
);
$productStatement->execute(['id' => $productId]);
$product = $productStatement->fetch();

if (!$product || (string) ($product['status'] ?? 'inactive') !== 'active') {
    flash('danger', 'Produk tidak ditemukan atau sedang nonaktif.');
    redirect('cart.php');
}

$hasVariants = (int) $product['has_variants'] === 1;
$availableStock = (int) $product['stock'];
$displayName = (string) $product['name'];

if ($hasVariants) {
    if ($variantId <= 0) {
        flash('warning', 'Silakan pilih variasi produk terlebih dahulu.');
        redirect('product_detail.php?id=' . $productId);
    }

    $variantStatement = $pdo->prepare(
        'SELECT id, variant_name, stock, is_active
         FROM product_variants
         WHERE id = :id AND product_id = :product_id LIMIT 1'
    );
    $variantStatement->execute([
        'id' => $variantId,
        'product_id' => $productId,
    ]);
    $variant = $variantStatement->fetch();

    if (!$variant || (int) $variant['is_active'] !== 1) {
        flash('danger', 'Variasi produk tidak tersedia.');
        redirect('product_detail.php?id=' . $productId);
    }

    $availableStock = (int) $variant['stock'];
    $displayName .= ' (' . (string) $variant['variant_name'] . ')';
} else {
    $variantId = 0;
}

$cartKey = cart_key($productId, $variantId);
$currentQuantity = (int) ($_SESSION['cart'][$cartKey]['quantity'] ?? 0);

switch ($action) {
    case 'add':
        $requestedQuantity = max(1, (int) ($_POST['quantity'] ?? 1));
        if ($availableStock < 1) {
            flash('danger', 'Stok produk sedang habis.');
            break;
        }

        $newQuantity = min($currentQuantity + $requestedQuantity, $availableStock);
        $_SESSION['cart'][$cartKey] = [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'quantity' => $newQuantity,
        ];
        flash('success', $displayName . ' ditambahkan ke keranjang.');
        break;

    case 'update':
        $quantity = max(0, (int) ($_POST['quantity'] ?? 1));
        if ($quantity === 0) {
            unset($_SESSION['cart'][$cartKey]);
            flash('success', 'Produk dihapus dari keranjang.');
        } elseif ($availableStock < 1) {
            flash('warning', 'Stok produk sedang habis. Hapus produk dari keranjang.');
        } else {
            $_SESSION['cart'][$cartKey] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => min($quantity, $availableStock),
            ];
            flash('success', 'Jumlah produk diperbarui.');
        }
        break;

    case 'remove':
        unset($_SESSION['cart'][$cartKey]);
        flash('success', 'Produk dihapus dari keranjang.');
        break;

    default:
        flash('danger', 'Aksi keranjang tidak dikenal.');
}

$back = (string) ($_SERVER['HTTP_REFERER'] ?? 'cart.php');
if ($action === 'add' && str_contains($back, 'index.php')) {
    redirect('index.php#produk');
}
redirect('cart.php');
