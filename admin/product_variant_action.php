<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('products.php');
}
verify_csrf();

$productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
$variantId = filter_input(INPUT_POST, 'variant_id', FILTER_VALIDATE_INT);
$action = trim((string) ($_POST['action'] ?? ''));

if (!$productId || !$variantId) {
    flash('danger', 'Variasi produk tidak valid.');
    redirect('products.php');
}

try {
    $pdo->beginTransaction();
    $statement = $pdo->prepare(
        'SELECT v.*, p.name AS product_name
         FROM product_variants v
         INNER JOIN products p ON p.id = v.product_id
         WHERE v.id = :id AND v.product_id = :product_id FOR UPDATE'
    );
    $statement->execute(['id' => $variantId, 'product_id' => $productId]);
    $variant = $statement->fetch();
    if (!$variant) {
        throw new RuntimeException('Variasi tidak ditemukan.');
    }

    if ($action === 'toggle_status') {
        $newStatus = (int) $variant['is_active'] === 1 ? 0 : 1;
        $pdo->prepare('UPDATE product_variants SET is_active = :status, updated_at = NOW() WHERE id = :id')
            ->execute(['status' => $newStatus, 'id' => $variantId]);
        sync_product_variant_summary($pdo, $productId);
        log_admin_activity($pdo, 'variant_status', 'Mengubah status variasi ' . $variant['variant_name'] . ' pada ' . $variant['product_name']);
        flash('success', 'Status variasi berhasil diperbarui.');
    } elseif ($action === 'delete') {
        $used = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE variant_id = :id');
        $used->execute(['id' => $variantId]);
        if ((int) $used->fetchColumn() > 0) {
            throw new RuntimeException('Variasi sudah memiliki riwayat pesanan. Nonaktifkan variasi agar data transaksi tetap aman.');
        }

        $image = (string) ($variant['image'] ?? '');
        $pdo->prepare('DELETE FROM product_variants WHERE id = :id')->execute(['id' => $variantId]);
        sync_product_variant_summary($pdo, $productId);

        if (str_starts_with($image, 'uploads/products/')) {
            $file = __DIR__ . '/../' . $image;
            if (is_file($file)) {
                @unlink($file);
            }
        }

        log_admin_activity($pdo, 'variant_delete', 'Menghapus variasi ' . $variant['variant_name'] . ' pada ' . $variant['product_name']);
        flash('success', 'Variasi berhasil dihapus.');
    } else {
        throw new RuntimeException('Tindakan variasi tidak dikenali.');
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash($exception instanceof RuntimeException ? 'warning' : 'danger', $exception->getMessage());
}

redirect('product_variants.php?product_id=' . $productId);
