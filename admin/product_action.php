<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('products.php');
verify_csrf();
$productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
$action = trim((string) ($_POST['action'] ?? ''));
if (!$productId) { flash('danger', 'Produk tidak valid.'); redirect('products.php'); }

try {
    if ($action === 'toggle_status') {
        $statement = $pdo->prepare('SELECT name, status FROM products WHERE id = :id');
        $statement->execute(['id' => $productId]);
        $product = $statement->fetch();
        if (!$product) throw new RuntimeException('Produk tidak ditemukan.');
        $newStatus = $product['status'] === 'active' ? 'inactive' : 'active';
        $pdo->prepare('UPDATE products SET status = :status, updated_at = NOW() WHERE id = :id')->execute(['status'=>$newStatus,'id'=>$productId]);
        log_admin_activity($pdo, 'product_status', 'Mengubah status produk ' . $product['name'] . ' menjadi ' . $newStatus);
        flash('success', 'Status produk berhasil diperbarui.');
    } elseif ($action === 'delete') {
        $statement = $pdo->prepare('SELECT name, image FROM products WHERE id = :id');
        $statement->execute(['id' => $productId]);
        $product = $statement->fetch();
        if (!$product) throw new RuntimeException('Produk tidak ditemukan.');
        $used = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE product_id = :id');
        $used->execute(['id' => $productId]);
        if ((int) $used->fetchColumn() > 0) throw new RuntimeException('Produk sudah memiliki riwayat pesanan. Nonaktifkan produk agar data transaksi tetap aman.');
        $pdo->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $productId]);
        $image = (string) ($product['image'] ?? '');
        if (str_starts_with($image, 'uploads/products/')) {
            $file = __DIR__ . '/../' . $image;
            if (is_file($file)) @unlink($file);
        }
        log_admin_activity($pdo, 'product_delete', 'Menghapus produk ' . $product['name']);
        flash('success', 'Produk berhasil dihapus permanen.');
    } else {
        throw new RuntimeException('Tindakan produk tidak dikenali.');
    }
} catch (Throwable $exception) {
    flash($exception instanceof RuntimeException ? 'warning' : 'danger', $exception->getMessage());
}
redirect('products.php');
