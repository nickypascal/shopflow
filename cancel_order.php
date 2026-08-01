<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}

verify_csrf();

$user = current_user();
$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
$reasonCode = trim((string) ($_POST['reason'] ?? ''));
$otherReason = trim((string) ($_POST['other_reason'] ?? ''));

$reasonOptions = [
    'wrong_product' => 'Salah memilih produk',
    'wrong_quantity' => 'Jumlah produk tidak sesuai',
    'wrong_address' => 'Alamat pengiriman salah',
    'change_payment' => 'Ingin mengubah metode pembayaran',
    'not_buying' => 'Tidak jadi membeli',
    'other' => 'Lainnya',
];

if (!$orderId || !array_key_exists($reasonCode, $reasonOptions)) {
    flash('danger', 'Data pembatalan pesanan tidak valid.');
    redirect('orders.php');
}

if ($reasonCode === 'other') {
    if (strlen($otherReason) < 5 || strlen($otherReason) > 200) {
        flash('warning', 'Alasan lainnya harus berisi 5 sampai 200 karakter.');
        redirect('orders.php');
    }

    $cancellationReason = 'Lainnya: ' . $otherReason;
} else {
    $cancellationReason = $reasonOptions[$reasonCode];
}

try {
    $pdo->beginTransaction();

    $orderStatement = $pdo->prepare(
        'SELECT id, status
         FROM orders
         WHERE id = :id AND user_id = :user_id
         FOR UPDATE'
    );
    $orderStatement->execute([
        'id' => $orderId,
        'user_id' => $user['id'],
    ]);
    $order = $orderStatement->fetch();

    if (!$order) {
        throw new RuntimeException('Pesanan tidak ditemukan.');
    }

    if (!in_array($order['status'], ['Menunggu Pembayaran', 'Menunggu Konfirmasi'], true)) {
        throw new RuntimeException('Pesanan ini sudah tidak dapat dibatalkan.');
    }

    $itemStatement = $pdo->prepare(
        'SELECT product_id, variant_id, quantity, variant_name FROM order_items WHERE order_id = :order_id'
    );
    $itemStatement->execute(['order_id' => $orderId]);
    $items = $itemStatement->fetchAll();

    foreach ($items as $item) {
        $productId = (int) $item['product_id'];
        $variantId = (int) ($item['variant_id'] ?? 0);

        if ($variantId > 0) {
            $variantLock = $pdo->prepare('SELECT stock FROM product_variants WHERE id = :id AND product_id = :product_id FOR UPDATE');
            $variantLock->execute(['id' => $variantId, 'product_id' => $productId]);
            $stockValue = $variantLock->fetchColumn();
            if ($stockValue === false) {
                throw new RuntimeException('Variasi produk pesanan tidak ditemukan.');
            }
            $before = (int) $stockValue;
            $after = $before + (int) $item['quantity'];
            $pdo->prepare('UPDATE product_variants SET stock = :stock, updated_at = NOW() WHERE id = :id')
                ->execute(['stock' => $after, 'id' => $variantId]);
            sync_product_variant_summary($pdo, $productId);
        } else {
            $productLock = $pdo->prepare('SELECT stock FROM products WHERE id = :id FOR UPDATE');
            $productLock->execute(['id' => $productId]);
            $before = (int) $productLock->fetchColumn();
            $after = $before + (int) $item['quantity'];
            $pdo->prepare('UPDATE products SET stock = :stock, updated_at = NOW() WHERE id = :id')
                ->execute(['stock' => $after, 'id' => $productId]);
        }

        record_stock_history(
            $pdo,
            $productId,
            'return',
            (int) $item['quantity'],
            $before,
            $after,
            'order_cancelled',
            (int) $orderId,
            'Stok kembali karena pesanan dibatalkan pelanggan.',
            null,
            $variantId > 0 ? $variantId : null
        );
    }

    $cancelStatement = $pdo->prepare(
        "UPDATE orders
         SET status = 'Dibatalkan', payment_status = 'Gagal', cancellation_reason = :cancellation_reason, cancelled_at = NOW()
         WHERE id = :id AND user_id = :user_id"
    );
    $cancelStatement->execute([
        'cancellation_reason' => $cancellationReason,
        'id' => $orderId,
        'user_id' => $user['id'],
    ]);

    $promotionRestored = restore_promotion_usage($pdo, (int) $orderId);
    record_order_status($pdo, (int) $orderId, 'Dibatalkan', $cancellationReason);
    $restoreText = $promotionRestored ? ' Kuota voucher juga telah dikembalikan.' : '';
    create_notification(
        $pdo,
        'Pesanan berhasil dibatalkan',
        order_code((int) $orderId) . ' telah dibatalkan. Stok produk sudah dikembalikan.' . $restoreText,
        'orders.php',
        'order',
        'customer',
        (int) $user['id'],
        'order-cancelled-' . (int) $orderId,
        'order',
        (int) $orderId,
        'order_updates'
    );
    create_notification(
        $pdo,
        'Pesanan dibatalkan pelanggan',
        order_code((int) $orderId) . ' dibatalkan pelanggan. Alasan: ' . $cancellationReason,
        'order_detail.php?id=' . (int) $orderId,
        'warning',
        'admin',
        null,
        'admin-order-cancelled-' . (int) $orderId,
        'order',
        (int) $orderId
    );

    $pdo->commit();
    flash('success', 'Pesanan berhasil dibatalkan dan stok produk telah dikembalikan.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    flash(
        $exception instanceof RuntimeException ? 'warning' : 'danger',
        $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Pesanan gagal dibatalkan. Silakan coba lagi.'
    );
}

redirect('orders.php');
