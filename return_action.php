<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('my_returns.php');
}
verify_csrf();

$user = current_user();
$returnId = filter_input(INPUT_POST, 'return_id', FILTER_VALIDATE_INT);
$action = trim((string) ($_POST['action'] ?? ''));
if (!$returnId) {
    flash('warning', 'Pengajuan retur tidak valid.');
    redirect('my_returns.php');
}

try {
    $pdo->beginTransaction();
    $statement = $pdo->prepare('SELECT * FROM return_requests WHERE id = :id AND user_id = :user_id FOR UPDATE');
    $statement->execute(['id' => $returnId, 'user_id' => (int) $user['id']]);
    $return = $statement->fetch();
    if (!$return) {
        throw new RuntimeException('Pengajuan retur tidak ditemukan atau bukan milik Anda.');
    }

    if ($action === 'cancel') {
        if (!in_array((string) $return['status'], ['SUBMITTED', 'UNDER_REVIEW'], true)) {
            throw new RuntimeException('Pengajuan ini tidak dapat dibatalkan pada status sekarang.');
        }
        $pdo->prepare("UPDATE return_requests SET status = 'CANCELLED', cancelled_at = NOW(), updated_at = NOW() WHERE id = :id")
            ->execute(['id' => $returnId]);
        $pdo->prepare("UPDATE return_items SET item_status = 'CANCELLED' WHERE return_request_id = :id")
            ->execute(['id' => $returnId]);
        record_return_history($pdo, $returnId, 'CANCELLED', 'Pengajuan dibatalkan pelanggan', 'Pelanggan membatalkan pengajuan retur.', (int) $user['id']);
        create_notification($pdo, 'Retur dibatalkan pelanggan', (string) $return['return_number'] . ' telah dibatalkan pelanggan.', 'return_detail.php?id=' . $returnId, 'return', 'admin', null, 'return-cancelled-admin-' . $returnId, 'return', $returnId);
        $message = 'Pengajuan retur berhasil dibatalkan.';
    } elseif ($action === 'ship') {
        if ((string) $return['status'] !== 'APPROVED') {
            throw new RuntimeException('Nomor resi hanya dapat dikirim setelah retur disetujui.');
        }
        $courier = trim((string) ($_POST['customer_return_courier'] ?? ''));
        $tracking = trim((string) ($_POST['customer_return_tracking_number'] ?? ''));
        if (text_length($courier) < 2 || text_length($courier) > 100) {
            throw new RuntimeException('Nama kurir pengembalian tidak valid.');
        }
        if (text_length($tracking) < 4 || text_length($tracking) > 100) {
            throw new RuntimeException('Nomor resi pengembalian tidak valid.');
        }
        $pdo->prepare(
            "UPDATE return_requests
             SET status = 'CUSTOMER_SHIPPED', customer_return_courier = :courier,
                 customer_return_tracking_number = :tracking, customer_shipped_at = NOW(), updated_at = NOW()
             WHERE id = :id"
        )->execute(['courier' => $courier, 'tracking' => $tracking, 'id' => $returnId]);
        $pdo->prepare("UPDATE return_items SET item_status = 'CUSTOMER_SHIPPED' WHERE return_request_id = :id")
            ->execute(['id' => $returnId]);
        record_return_history($pdo, $returnId, 'CUSTOMER_SHIPPED', 'Barang dikirim pelanggan', 'Kurir: ' . $courier . ' · Resi: ' . $tracking, (int) $user['id']);
        create_notification($pdo, 'Barang retur dikirim pelanggan', (string) $return['return_number'] . ' dikirim melalui ' . $courier . ' dengan resi ' . $tracking . '.', 'return_detail.php?id=' . $returnId, 'return', 'admin', null, 'return-shipped-admin-' . $returnId, 'return', $returnId);
        $message = 'Nomor resi retur berhasil disimpan.';
    } else {
        throw new RuntimeException('Tindakan retur tidak valid.');
    }

    $pdo->commit();
    flash('success', $message);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'Tindakan retur gagal diproses.');
}

redirect('return_detail.php?id=' . $returnId);
