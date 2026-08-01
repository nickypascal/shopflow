<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('orders.php');
verify_csrf();

$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
$targetStatus = trim((string) ($_POST['target_status'] ?? ''));
if (!$orderId) {
    flash('danger', 'Pesanan tidak valid.');
    redirect('orders.php');
}

if ($targetStatus === 'save_notes') {
    $notes = trim((string) ($_POST['admin_notes'] ?? ''));
    if (strlen($notes) > 1000) {
        flash('warning', 'Catatan maksimal 1.000 karakter.');
    } else {
        $statement = $pdo->prepare('UPDATE orders SET admin_notes = :notes, updated_at = NOW() WHERE id = :id');
        $statement->execute(['notes' => $notes ?: null, 'id' => $orderId]);
        log_admin_activity($pdo, 'order_note', 'Memperbarui catatan internal pesanan ' . order_code($orderId));
        flash('success', 'Catatan internal berhasil disimpan.');
    }
    redirect('order_detail.php?id=' . $orderId);
}

$allowedTransitions = [
    'Menunggu Konfirmasi' => ['Diproses', 'Ditolak'],
    'Menunggu Pembayaran' => ['Diproses', 'Ditolak'],
    'Diproses' => ['Dikirim'],
    'Dikirim' => ['Selesai'],
];
if (!in_array($targetStatus, ['Diproses', 'Dikirim', 'Selesai', 'Ditolak'], true)) {
    flash('danger', 'Perubahan status tidak valid.');
    redirect('order_detail.php?id=' . $orderId);
}

$rejectionReason = trim((string) ($_POST['rejection_reason'] ?? ''));
$otherReason = trim((string) ($_POST['other_reason'] ?? ''));
$trackingNumber = trim((string) ($_POST['tracking_number'] ?? ''));
$shipmentLocation = trim((string) ($_POST['shipment_location'] ?? ''));
$allowedReasons = [
    'Alamat pengiriman tidak lengkap',
    'Nomor telepon tidak dapat dihubungi',
    'Produk sedang tidak tersedia',
    'Wilayah tidak mendukung COD',
    'Pesanan terindikasi tidak valid',
    'Pelanggan memiliki riwayat penolakan COD',
    'Lainnya',
];
if ($targetStatus === 'Ditolak') {
    if (!in_array($rejectionReason, $allowedReasons, true)) {
        flash('warning', 'Silakan pilih alasan penolakan.');
        redirect('order_detail.php?id=' . $orderId);
    }
    if ($rejectionReason === 'Lainnya') {
        if (strlen($otherReason) < 5 || strlen($otherReason) > 255) {
            flash('warning', 'Keterangan penolakan harus berisi 5–255 karakter.');
            redirect('order_detail.php?id=' . $orderId);
        }
        $rejectionReason = 'Lainnya: ' . $otherReason;
    }
}

if ($targetStatus === 'Dikirim') {
    if (strlen($trackingNumber) < 3 || strlen($trackingNumber) > 100) {
        flash('warning', 'Nomor resi harus terdiri dari 3 sampai 100 karakter.');
        redirect('order_detail.php?id=' . $orderId);
    }
    if (strlen($shipmentLocation) > 150) {
        flash('warning', 'Lokasi pengiriman maksimal 150 karakter.');
        redirect('order_detail.php?id=' . $orderId);
    }
}

try {
    $pdo->beginTransaction();
    $orderStatement = $pdo->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
    $orderStatement->execute(['id' => $orderId]);
    $order = $orderStatement->fetch();
    if (!$order) throw new RuntimeException('Pesanan tidak ditemukan.');

    $currentStatus = (string) $order['status'];
    if (!in_array($targetStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
        throw new RuntimeException('Status tidak dapat diubah dari ' . $currentStatus . ' menjadi ' . $targetStatus . '.');
    }

    $adminId = (int) ($adminUser['id'] ?? 0);
    if ($targetStatus === 'Ditolak') {
        $itemStatement = $pdo->prepare('SELECT product_id, variant_id, quantity, variant_name FROM order_items WHERE order_id = :order_id');
        $itemStatement->execute(['order_id' => $orderId]);
        foreach ($itemStatement->fetchAll() as $item) {
            $productId = (int) $item['product_id'];
            $variantId = (int) ($item['variant_id'] ?? 0);

            if ($variantId > 0) {
                $lock = $pdo->prepare('SELECT stock FROM product_variants WHERE id = :id AND product_id = :product_id FOR UPDATE');
                $lock->execute(['id' => $variantId, 'product_id' => $productId]);
                $stockValue = $lock->fetchColumn();
                if ($stockValue === false) {
                    throw new RuntimeException('Variasi produk pesanan tidak ditemukan.');
                }
                $before = (int) $stockValue;
                $after = $before + (int) $item['quantity'];
                $pdo->prepare('UPDATE product_variants SET stock = :stock, updated_at = NOW() WHERE id = :id')
                    ->execute(['stock' => $after, 'id' => $variantId]);
                sync_product_variant_summary($pdo, $productId);
            } else {
                $lock = $pdo->prepare('SELECT stock FROM products WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $productId]);
                $before = (int) $lock->fetchColumn();
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
                'order_rejected',
                $orderId,
                'Stok kembali karena pesanan ditolak.',
                $adminId,
                $variantId > 0 ? $variantId : null
            );
        }

        $pdo->prepare("UPDATE orders SET status = 'Ditolak', payment_status = 'Gagal', rejection_reason = :reason, rejected_at = NOW(), updated_at = NOW() WHERE id = :id")
            ->execute(['reason' => $rejectionReason, 'id' => $orderId]);
        restore_promotion_usage($pdo, $orderId);
        record_order_status($pdo, $orderId, 'Ditolak', $rejectionReason, $adminId);
    } else {
        $timeColumn = match ($targetStatus) {
            'Diproses' => 'processed_at',
            'Dikirim' => 'shipped_at',
            'Selesai' => 'completed_at',
            default => 'updated_at',
        };

        $sql = "UPDATE orders SET status = :status, {$timeColumn} = NOW(), updated_at = NOW()";
        $parameters = ['status' => $targetStatus, 'id' => $orderId];
        if ($targetStatus === 'Diproses') $sql .= ', confirmed_at = NOW()';
        if ($targetStatus === 'Diproses' && $currentStatus === 'Menunggu Pembayaran') $sql .= ", payment_status = 'Dibayar'";
        if ($targetStatus === 'Dikirim') {
            $sql .= ', tracking_number = :tracking_number';
            $parameters['tracking_number'] = $trackingNumber;
        }
        if ($targetStatus === 'Selesai') {
            $sql .= ', delivered_at = NOW()';
            if (is_cod_payment((string) $order['payment_method'])) $sql .= ", payment_status = 'Dibayar'";
        }
        $sql .= ' WHERE id = :id';
        $pdo->prepare($sql)->execute($parameters);

        $note = match ($targetStatus) {
            'Diproses' => $currentStatus === 'Menunggu Pembayaran' ? 'Pembayaran dikonfirmasi dan pesanan mulai diproses.' : 'Pesanan dikonfirmasi dan sedang disiapkan.',
            'Dikirim' => 'Pesanan telah diserahkan kepada ' . ($order['shipping_courier_name'] ?: 'kurir') . ' dengan resi ' . $trackingNumber . '.',
            'Selesai' => 'Pesanan telah diterima dan transaksi selesai.',
            default => 'Status pesanan diperbarui.',
        };
        record_order_status($pdo, $orderId, $targetStatus, $note, $adminId);

        if ($targetStatus === 'Diproses') {
            record_shipment_history($pdo, $orderId, 'Pesanan Diproses', 'Pesanan sedang disiapkan oleh toko.', setting($pdo, 'store_city', ''), $adminId);
        } elseif ($targetStatus === 'Dikirim') {
            record_shipment_history(
                $pdo,
                $orderId,
                'Paket Diserahkan ke Kurir',
                'Paket diserahkan kepada ' . ($order['shipping_courier_name'] ?: 'kurir') . ' dengan nomor resi ' . $trackingNumber . '.',
                $shipmentLocation ?: setting($pdo, 'store_city', ''),
                $adminId
            );
        } elseif ($targetStatus === 'Selesai') {
            record_shipment_history($pdo, $orderId, 'Paket Diterima', 'Pesanan ditandai selesai oleh admin.', (string) ($order['shipping_city'] ?: ''), $adminId);
        }
    }

    $customerTitle = match ($targetStatus) {
        'Diproses' => 'Pesanan sedang diproses',
        'Dikirim' => 'Pesanan sudah dikirim',
        'Selesai' => 'Pesanan selesai',
        'Ditolak' => 'Pesanan ditolak',
        default => 'Status pesanan diperbarui',
    };
    $customerMessage = match ($targetStatus) {
        'Diproses' => order_code($order) . ' telah dikonfirmasi dan sedang disiapkan oleh toko.',
        'Dikirim' => order_code($order) . ' telah dikirim menggunakan ' . (($order['shipping_courier_name'] ?? '') ?: 'kurir') . ' ' . (($order['shipping_service_name'] ?? '') ?: '') . '. Nomor resi: ' . $trackingNumber . '.',
        'Selesai' => order_code($order) . ' telah selesai. Terima kasih telah berbelanja.',
        'Ditolak' => order_code($order) . ' ditolak. Alasan: ' . $rejectionReason . '.',
        default => order_code($order) . ' sekarang berstatus ' . $targetStatus . '.',
    };
    $customerType = match ($targetStatus) {
        'Dikirim' => 'shipping',
        'Ditolak' => 'danger',
        'Selesai' => 'success',
        default => 'order',
    };
    create_notification(
        $pdo,
        $customerTitle,
        trim($customerMessage),
        'orders.php',
        $customerType,
        'customer',
        (int) $order['user_id'],
        'order-status-' . $orderId . '-' . strtolower(str_replace(' ', '-', $targetStatus)),
        'order',
        $orderId,
        $targetStatus === 'Dikirim' ? 'shipping_updates' : 'order_updates'
    );
    if ($targetStatus === 'Selesai') {
        create_review_request_notifications(
            $pdo,
            $orderId,
            (int) $order['user_id'],
            order_code($order)
        );
    }
    log_admin_activity($pdo, 'order_status', 'Mengubah status ' . order_code($order) . ' dari ' . $currentStatus . ' menjadi ' . $targetStatus, $adminId);
    $pdo->commit();
    flash('success', 'Status pesanan berhasil diubah menjadi ' . $targetStatus . '.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash($exception instanceof RuntimeException ? 'warning' : 'danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'Status pesanan gagal diperbarui.');
}

redirect('order_detail.php?id=' . $orderId);
