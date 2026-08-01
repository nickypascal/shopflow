<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
$orderItemId = filter_input(INPUT_GET, 'order_item', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'order_item_id', FILTER_VALIDATE_INT);

if (!$orderItemId) {
    flash('warning', 'Produk pesanan tidak valid.');
    redirect('orders.php');
}

$itemStatement = $pdo->prepare(
    "SELECT oi.*, o.user_id, o.status AS order_status, o.order_number, o.completed_at,
            o.delivered_at, o.shipping_name, o.shipping_phone,
            COALESCE(oi.product_image, p.image) AS display_image
     FROM order_items oi
     INNER JOIN orders o ON o.id = oi.order_id
     LEFT JOIN products p ON p.id = oi.product_id
     WHERE oi.id = :order_item_id AND o.user_id = :user_id
     LIMIT 1"
);
$itemStatement->execute([
    'order_item_id' => $orderItemId,
    'user_id' => (int) $user['id'],
]);
$item = $itemStatement->fetch();

if (!$item) {
    flash('danger', 'Produk pesanan tidak ditemukan atau bukan milik akun Anda.');
    redirect('orders.php');
}

$periodDays = return_period_days($pdo);
$completedAt = (string) ($item['completed_at'] ?: $item['delivered_at'] ?: '');
$returnedQuantity = returned_quantity_for_order_item($pdo, (int) $item['id']);
$availableQuantity = max(0, (int) $item['quantity'] - $returnedQuantity);
$deadline = return_deadline($completedAt, $periodDays);

if ((string) $item['order_status'] !== 'Selesai') {
    flash('warning', 'Retur hanya dapat diajukan setelah pesanan selesai.');
    redirect('orders.php');
}
if (!return_is_open($completedAt, $periodDays)) {
    flash('warning', 'Periode retur untuk produk ini telah berakhir.');
    redirect('orders.php');
}
if ($availableQuantity <= 0) {
    flash('info', 'Seluruh jumlah produk ini sudah masuk dalam pengajuan retur.');
    redirect('orders.php');
}

$errors = [];
$quantity = 1;
$reasonCode = '';
$customerNote = '';
$resolutionType = 'REFUND';
$refundMethod = '';
$refundAccountName = '';
$refundAccountNumber = '';
$refundBankName = '';
$reasons = return_reasons();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $quantity = filter_input(INPUT_POST, 'return_quantity', FILTER_VALIDATE_INT) ?: 0;
    $reasonCode = trim((string) ($_POST['reason_code'] ?? ''));
    $customerNote = trim((string) ($_POST['customer_note'] ?? ''));
    $resolutionType = strtoupper(trim((string) ($_POST['resolution_type'] ?? 'REFUND')));
    $refundMethod = trim((string) ($_POST['refund_method'] ?? ''));
    $refundAccountName = trim((string) ($_POST['refund_account_name'] ?? ''));
    $refundAccountNumber = trim((string) ($_POST['refund_account_number'] ?? ''));
    $refundBankName = trim((string) ($_POST['refund_bank_name'] ?? ''));

    if ($quantity < 1 || $quantity > $availableQuantity) {
        $errors[] = 'Jumlah retur harus antara 1 dan ' . $availableQuantity . '.';
    }
    if (!array_key_exists($reasonCode, $reasons)) {
        $errors[] = 'Pilih alasan retur yang tersedia.';
    }
    if ($reasonCode === 'other' && text_length($customerNote) < 10) {
        $errors[] = 'Jelaskan alasan retur lainnya minimal 10 karakter.';
    }
    if (text_length($customerNote) > 2000) {
        $errors[] = 'Keterangan retur maksimal 2.000 karakter.';
    }
    if (!in_array($resolutionType, ['REFUND', 'REPLACEMENT'], true)) {
        $errors[] = 'Jenis penyelesaian retur tidak valid.';
    }
    if ($resolutionType === 'REFUND') {
        if ($refundMethod === '' || $refundAccountName === '' || $refundAccountNumber === '') {
            $errors[] = 'Lengkapi metode, nama pemilik, dan nomor tujuan pengembalian dana.';
        }
        if (text_length($refundBankName) > 100 || text_length($refundAccountNumber) > 100) {
            $errors[] = 'Data tujuan pengembalian dana terlalu panjang.';
        }
    }

    $uploadedCount = 0;
    if (isset($_FILES['evidence_images']['error']) && is_array($_FILES['evidence_images']['error'])) {
        foreach ($_FILES['evidence_images']['error'] as $uploadError) {
            if ((int) $uploadError !== UPLOAD_ERR_NO_FILE) {
                $uploadedCount++;
            }
        }
    }
    if (return_reason_requires_image($reasonCode) && $uploadedCount === 0) {
        $errors[] = 'Foto bukti wajib diunggah untuk alasan retur yang dipilih.';
    }
    if ($uploadedCount > 5) {
        $errors[] = 'Maksimal lima foto bukti retur.';
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            $lockStatement = $pdo->prepare(
                "SELECT oi.*, o.user_id, o.status AS order_status, o.completed_at, o.delivered_at,
                        o.order_number, COALESCE(oi.product_image, p.image) AS display_image
                 FROM order_items oi
                 INNER JOIN orders o ON o.id = oi.order_id
                 LEFT JOIN products p ON p.id = oi.product_id
                 WHERE oi.id = :order_item_id AND o.user_id = :user_id
                 FOR UPDATE"
            );
            $lockStatement->execute([
                'order_item_id' => $orderItemId,
                'user_id' => (int) $user['id'],
            ]);
            $lockedItem = $lockStatement->fetch();
            if (!$lockedItem || (string) $lockedItem['order_status'] !== 'Selesai') {
                throw new RuntimeException('Produk tidak lagi memenuhi syarat retur.');
            }
            $lockedCompletedAt = (string) ($lockedItem['completed_at'] ?: $lockedItem['delivered_at'] ?: '');
            if (!return_is_open($lockedCompletedAt, $periodDays)) {
                throw new RuntimeException('Periode retur telah berakhir.');
            }
            $lockedReturned = returned_quantity_for_order_item($pdo, $orderItemId);
            $lockedAvailable = max(0, (int) $lockedItem['quantity'] - $lockedReturned);
            if ($quantity < 1 || $quantity > $lockedAvailable) {
                throw new RuntimeException('Jumlah produk yang masih dapat diretur adalah ' . $lockedAvailable . '.');
            }

            $returnNumber = return_number($pdo);
            $requestedRefund = (float) $lockedItem['price'] * $quantity;
            $insertRequest = $pdo->prepare(
                "INSERT INTO return_requests (
                    return_number, user_id, order_id, resolution_type, status,
                    reason_summary, customer_note,
                    refund_method, refund_account_name, refund_account_number, refund_bank_name,
                    refund_amount, refund_status
                 ) VALUES (
                    :return_number, :user_id, :order_id, :resolution_type, 'SUBMITTED',
                    :reason_summary, :customer_note,
                    :refund_method, :refund_account_name, :refund_account_number, :refund_bank_name,
                    :refund_amount, :refund_status
                 )"
            );
            $insertRequest->execute([
                'return_number' => $returnNumber,
                'user_id' => (int) $user['id'],
                'order_id' => (int) $lockedItem['order_id'],
                'resolution_type' => $resolutionType,
                'reason_summary' => (string) $reasons[$reasonCode],
                'customer_note' => $customerNote !== '' ? $customerNote : null,
                'refund_method' => $resolutionType === 'REFUND' ? $refundMethod : null,
                'refund_account_name' => $resolutionType === 'REFUND' ? $refundAccountName : null,
                'refund_account_number' => $resolutionType === 'REFUND' ? $refundAccountNumber : null,
                'refund_bank_name' => $resolutionType === 'REFUND' ? ($refundBankName !== '' ? $refundBankName : null) : null,
                'refund_amount' => $resolutionType === 'REFUND' ? $requestedRefund : 0,
                'refund_status' => $resolutionType === 'REFUND' ? 'PENDING' : 'NOT_REQUIRED',
            ]);
            $returnRequestId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare(
                "INSERT INTO return_items (
                    return_request_id, order_item_id, product_id, variant_id,
                    product_name, product_image, variant_name, variant_sku,
                    purchased_quantity, return_quantity, item_price,
                    requested_refund_amount, approved_refund_amount,
                    reason, condition_note, item_status
                 ) VALUES (
                    :return_request_id, :order_item_id, :product_id, :variant_id,
                    :product_name, :product_image, :variant_name, :variant_sku,
                    :purchased_quantity, :return_quantity, :item_price,
                    :requested_refund_amount, 0,
                    :reason, :condition_note, 'SUBMITTED'
                 )"
            );
            $insertItem->execute([
                'return_request_id' => $returnRequestId,
                'order_item_id' => (int) $lockedItem['id'],
                'product_id' => (int) $lockedItem['product_id'],
                'variant_id' => !empty($lockedItem['variant_id']) ? (int) $lockedItem['variant_id'] : null,
                'product_name' => (string) $lockedItem['product_name'],
                'product_image' => trim((string) ($lockedItem['display_image'] ?? '')) !== '' ? (string) $lockedItem['display_image'] : null,
                'variant_name' => trim((string) ($lockedItem['variant_name'] ?? '')) !== '' ? (string) $lockedItem['variant_name'] : null,
                'variant_sku' => trim((string) ($lockedItem['variant_sku'] ?? '')) !== '' ? (string) $lockedItem['variant_sku'] : null,
                'purchased_quantity' => (int) $lockedItem['quantity'],
                'return_quantity' => $quantity,
                'item_price' => (float) $lockedItem['price'],
                'requested_refund_amount' => $requestedRefund,
                'reason' => (string) $reasons[$reasonCode],
                'condition_note' => $customerNote !== '' ? $customerNote : null,
            ]);
            $returnItemId = (int) $pdo->lastInsertId();

            $uploadErrors = store_return_images(
                $pdo,
                $returnRequestId,
                $returnItemId,
                $_FILES['evidence_images'] ?? [],
                'EVIDENCE'
            );
            if ($uploadErrors !== []) {
                throw new RuntimeException(implode(' ', $uploadErrors));
            }

            record_return_history(
                $pdo,
                $returnRequestId,
                'SUBMITTED',
                'Pengajuan retur dibuat',
                'Pelanggan mengajukan retur untuk ' . (string) $lockedItem['product_name'] . '.',
                (int) $user['id']
            );
            create_notification(
                $pdo,
                'Pengajuan retur diterima',
                $returnNumber . ' berhasil dibuat dan menunggu pemeriksaan admin.',
                'return_detail.php?id=' . $returnRequestId,
                'return',
                'customer',
                (int) $user['id'],
                'return-submitted-customer-' . $returnRequestId,
                'return',
                $returnRequestId,
                'order_updates'
            );
            create_notification(
                $pdo,
                'Pengajuan retur baru',
                $returnNumber . ' untuk ' . (string) $lockedItem['product_name'] . ' menunggu pemeriksaan.',
                'return_detail.php?id=' . $returnRequestId,
                'return',
                'admin',
                null,
                'return-submitted-admin-' . $returnRequestId,
                'return',
                $returnRequestId
            );

            $pdo->commit();
            flash('success', 'Pengajuan retur berhasil dibuat.');
            redirect('return_detail.php?id=' . $returnRequestId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Pengajuan retur gagal disimpan.';
        }
    }
}

$pageTitle = 'Ajukan Retur';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header"><div class="container"><span class="eyebrow">Layanan setelah pembelian</span><h1>Ajukan Retur Barang</h1><p>Ajukan pengembalian dana atau penggantian barang untuk produk yang sudah diterima.</p></div></section>
<section class="section"><div class="container return-form-layout">
    <div>
        <?php if ($errors !== []): ?><div class="alert alert-danger"><strong>Periksa kembali pengajuan:</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form class="return-form-card" method="post" enctype="multipart/form-data" data-return-form>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="order_item_id" value="<?= (int) $item['id'] ?>">
            <div class="return-product-summary">
                <img src="<?= e((string) ($item['display_image'] ?: 'assets/images/bag.svg')) ?>" alt="<?= e((string) $item['product_name']) ?>">
                <div><span>Pesanan <?= e(order_code($item)) ?></span><h2><?= e((string) $item['product_name']) ?></h2><?php if ($item['variant_name']): ?><p><?= e((string) $item['variant_name']) ?><?= $item['variant_sku'] ? ' · SKU ' . e((string) $item['variant_sku']) : '' ?></p><?php endif; ?><strong><?= rupiah($item['price']) ?> × <?= (int) $item['quantity'] ?></strong></div>
            </div>
            <div class="form-grid-two">
                <label><span>Jumlah yang diretur</span><input type="number" name="return_quantity" min="1" max="<?= $availableQuantity ?>" value="<?= $quantity ?>" required><small>Tersedia untuk retur: <?= $availableQuantity ?> produk.</small></label>
                <label><span>Jenis penyelesaian</span><select name="resolution_type" data-return-resolution required><option value="REFUND" <?= $resolutionType === 'REFUND' ? 'selected' : '' ?>>Pengembalian Dana</option><option value="REPLACEMENT" <?= $resolutionType === 'REPLACEMENT' ? 'selected' : '' ?>>Penggantian Barang</option></select></label>
            </div>
            <label><span>Alasan retur</span><select name="reason_code" required><option value="">Pilih alasan</option><?php foreach ($reasons as $code => $label): ?><option value="<?= e($code) ?>" <?= $reasonCode === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            <label><span>Jelaskan kondisi atau masalah produk</span><textarea name="customer_note" rows="5" maxlength="2000" placeholder="Jelaskan kondisi produk, kelengkapan, dan masalah yang ditemukan..."> <?= e($customerNote) ?></textarea></label>
            <div class="return-refund-fields" data-refund-fields>
                <h3>Tujuan Pengembalian Dana</h3>
                <div class="form-grid-two">
                    <label><span>Metode</span><select name="refund_method"><option value="">Pilih metode</option><?php foreach (['Transfer Bank','DANA','OVO','GoPay'] as $method): ?><option value="<?= e($method) ?>" <?= $refundMethod === $method ? 'selected' : '' ?>><?= e($method) ?></option><?php endforeach; ?></select></label>
                    <label><span>Nama bank/e-wallet</span><input type="text" name="refund_bank_name" maxlength="100" value="<?= e($refundBankName) ?>" placeholder="Contoh: BCA atau DANA"></label>
                    <label><span>Nama pemilik</span><input type="text" name="refund_account_name" maxlength="100" value="<?= e($refundAccountName) ?>"></label>
                    <label><span>Nomor rekening/e-wallet</span><input type="text" name="refund_account_number" maxlength="100" value="<?= e($refundAccountNumber) ?>"></label>
                </div>
            </div>
            <label class="return-upload-field"><span>Foto bukti (maksimal 5 foto)</span><input type="file" name="evidence_images[]" accept="image/jpeg,image/png,image/webp" multiple><small>JPG, PNG, atau WEBP. Maksimal 2 MB per foto. Foto wajib untuk produk rusak, salah, kurang, atau tidak sesuai.</small></label>
            <label class="return-policy-check"><input type="checkbox" required><span>Saya telah membaca dan menyetujui <a href="return_policy.php" target="_blank">kebijakan retur ShopFlow</a>.</span></label>
            <button class="button button-primary" type="submit">Kirim Pengajuan Retur</button>
        </form>
    </div>
    <aside class="return-info-card"><h3>Informasi Retur</h3><ul><li>Batas pengajuan <?= $periodDays ?> hari setelah pesanan selesai.</li><li>Jumlah retur tidak boleh melebihi jumlah pembelian.</li><li>Barang harus dikembalikan lengkap sesuai keputusan admin.</li><li>Pengembalian dana diproses secara manual.</li></ul><?php if ($deadline): ?><div class="return-deadline"><span>Batas pengajuan</span><strong><?= e($deadline->format('d M Y, H:i')) ?></strong></div><?php endif; ?></aside>
</div></section>
<script>
(() => { const select=document.querySelector('[data-return-resolution]'); const fields=document.querySelector('[data-refund-fields]'); function sync(){const show=select?.value==='REFUND'; fields?.classList.toggle('is-hidden',!show); fields?.querySelectorAll('input,select').forEach((el)=>{if(['refund_method','refund_account_name','refund_account_number'].includes(el.name)) el.required=show;});} select?.addEventListener('change',sync); sync(); })();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
