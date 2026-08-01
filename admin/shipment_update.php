<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('orders.php');
}
verify_csrf();

$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
$status = trim((string) ($_POST['shipment_status'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$location = trim((string) ($_POST['location'] ?? ''));

if (!$orderId) {
    flash('warning', 'Pesanan tidak valid.');
    redirect('orders.php');
}
if (strlen($status) < 3 || strlen($status) > 100) {
    flash('warning', 'Status perjalanan harus terdiri dari 3 sampai 100 karakter.');
    redirect('order_detail.php?id=' . $orderId);
}
if (strlen($description) > 255 || strlen($location) > 150) {
    flash('warning', 'Keterangan atau lokasi terlalu panjang.');
    redirect('order_detail.php?id=' . $orderId);
}

$statement = $pdo->prepare("SELECT id, order_number, status, user_id, tracking_number, shipping_courier_name, shipping_service_name FROM orders WHERE id = :id LIMIT 1");
$statement->execute(['id' => $orderId]);
$order = $statement->fetch();
if (!$order || !in_array((string) $order['status'], ['Diproses', 'Dikirim'], true)) {
    flash('warning', 'Riwayat pengiriman hanya dapat ditambahkan pada pesanan Diproses atau Dikirim.');
    redirect('order_detail.php?id=' . $orderId);
}

record_shipment_history(
    $pdo,
    $orderId,
    $status,
    $description ?: 'Status perjalanan paket diperbarui.',
    $location ?: null,
    (int) $adminUser['id']
);
create_notification(
    $pdo,
    'Perjalanan paket diperbarui',
    order_code($order) . ': ' . $status . ($description !== '' ? ' — ' . $description : '') . ($location !== '' ? ' Lokasi: ' . $location . '.' : ''),
    'track_order.php?order=' . $orderId,
    'shipping',
    'customer',
    (int) $order['user_id'],
    'shipment-update-' . $orderId . '-' . date('YmdHis'),
    'order',
    $orderId,
    'shipping_updates'
);
log_admin_activity($pdo, 'shipment_update', 'Menambahkan riwayat pengiriman ' . order_code($order) . ': ' . $status);
flash('success', 'Riwayat pengiriman berhasil ditambahkan dan pelanggan telah diberi notifikasi.');
redirect('order_detail.php?id=' . $orderId);
