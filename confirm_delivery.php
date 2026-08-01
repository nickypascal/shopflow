<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}
verify_csrf();

$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
$user = current_user();
if (!$orderId) {
    flash('warning', 'Pesanan tidak valid.');
    redirect('orders.php');
}

try {
    $pdo->beginTransaction();
    $statement = $pdo->prepare('SELECT * FROM orders WHERE id = :id AND user_id = :user_id FOR UPDATE');
    $statement->execute(['id' => $orderId, 'user_id' => (int) $user['id']]);
    $order = $statement->fetch();
    if (!$order) {
        throw new RuntimeException('Pesanan tidak ditemukan atau bukan milik Anda.');
    }
    if ((string) $order['status'] !== 'Dikirim') {
        throw new RuntimeException('Pesanan hanya dapat diselesaikan setelah berstatus Dikirim.');
    }

    $sql = "UPDATE orders
            SET status = 'Selesai', delivered_at = NOW(), completed_at = NOW(), updated_at = NOW()";
    if (is_cod_payment((string) $order['payment_method'])) {
        $sql .= ", payment_status = 'Dibayar'";
    }
    $sql .= ' WHERE id = :id';
    $pdo->prepare($sql)->execute(['id' => $orderId]);

    record_order_status($pdo, $orderId, 'Selesai', 'Pelanggan mengonfirmasi paket telah diterima.', (int) $user['id']);
    record_shipment_history(
        $pdo,
        $orderId,
        'Paket Diterima',
        'Pelanggan mengonfirmasi paket telah diterima dan diperiksa.',
        (string) ($order['shipping_city'] ?: ''),
        (int) $user['id']
    );
    create_notification(
        $pdo,
        'Pesanan selesai',
        order_code($order) . ' telah selesai. Terima kasih telah berbelanja di ShopFlow.',
        'orders.php',
        'success',
        'customer',
        (int) $user['id'],
        'order-completed-customer-' . $orderId,
        'order',
        $orderId,
        'order_updates'
    );
    create_notification(
        $pdo,
        'Pesanan diterima pelanggan',
        order_code($order) . ' telah dikonfirmasi diterima oleh pelanggan.',
        'order_detail.php?id=' . $orderId,
        'success',
        'admin',
        null,
        'admin-order-received-' . $orderId,
        'order',
        $orderId
    );
    create_review_request_notifications(
        $pdo,
        $orderId,
        (int) $user['id'],
        order_code($order)
    );

    $pdo->commit();
    flash('success', 'Terima kasih. Pesanan telah ditandai selesai.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash(
        $exception instanceof RuntimeException ? 'warning' : 'danger',
        $exception instanceof RuntimeException ? $exception->getMessage() : 'Konfirmasi penerimaan gagal diproses.'
    );
}

redirect('track_order.php?order=' . (int) $orderId);
