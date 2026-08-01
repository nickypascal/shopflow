<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$returnId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'return_id', FILTER_VALIDATE_INT);
if (!$returnId) {
    flash('warning', 'Pengajuan retur tidak valid.');
    redirect('returns.php');
}

$loadReturn = static function () use ($pdo, $returnId): array|false {
    $statement = $pdo->prepare(
        "SELECT rr.*, o.order_number, o.total_amount, o.shipping_name, o.shipping_phone,
                o.shipping_address, o.shipping_city, o.shipping_province,
                u.name AS customer_name, u.email AS customer_email
         FROM return_requests rr
         INNER JOIN orders o ON o.id = rr.order_id
         INNER JOIN users u ON u.id = rr.user_id
         WHERE rr.id = :id LIMIT 1"
    );
    $statement->execute(['id' => $returnId]);
    return $statement->fetch();
};
$return = $loadReturn();
if (!$return) {
    flash('danger', 'Pengajuan retur tidak ditemukan.');
    redirect('returns.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = trim((string) ($_POST['action'] ?? ''));
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT * FROM return_requests WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $returnId]);
        $current = $lock->fetch();
        if (!$current) {
            throw new RuntimeException('Pengajuan retur tidak ditemukan.');
        }

        $status = (string) $current['status'];
        $message = '';
        if ($action === 'start_review') {
            if ($status !== 'SUBMITTED') throw new RuntimeException('Retur tidak dapat mulai ditinjau pada status sekarang.');
            $pdo->prepare("UPDATE return_requests SET status='UNDER_REVIEW', reviewed_by=:admin, reviewed_at=NOW() WHERE id=:id")
                ->execute(['admin'=>(int)$adminUser['id'],'id'=>$returnId]);
            $pdo->prepare("UPDATE return_items SET item_status='UNDER_REVIEW' WHERE return_request_id=:id")->execute(['id'=>$returnId]);
            record_return_history($pdo,$returnId,'UNDER_REVIEW','Retur sedang ditinjau','Admin mulai memeriksa pengajuan dan bukti retur.',(int)$adminUser['id']);
            create_notification($pdo,'Retur sedang ditinjau',(string)$current['return_number'].' sedang diperiksa oleh admin.','return_detail.php?id='.$returnId,'return','customer',(int)$current['user_id'],'return-reviewing-'.$returnId,'return',$returnId,'order_updates');
            $message='Pengajuan ditandai sedang ditinjau.';
        } elseif ($action === 'approve') {
            if (!in_array($status,['SUBMITTED','UNDER_REVIEW'],true)) throw new RuntimeException('Retur tidak dapat disetujui pada status sekarang.');
            $adminNote=trim((string)($_POST['admin_note']??''));
            $approvedAmount=(float)($_POST['approved_refund_amount']??0);
            if ((string)$current['resolution_type']==='REFUND' && $approvedAmount < 0) throw new RuntimeException('Nilai pengembalian dana tidak valid.');
            $pdo->prepare("UPDATE return_requests SET status='APPROVED', reviewed_by=:admin, reviewed_at=NOW(), admin_note=:note, refund_amount=:amount, refund_status=CASE WHEN resolution_type='REFUND' THEN 'PENDING' ELSE 'NOT_REQUIRED' END WHERE id=:id")
                ->execute(['admin'=>(int)$adminUser['id'],'note'=>$adminNote!==''?$adminNote:null,'amount'=>(string)$current['resolution_type']==='REFUND'?$approvedAmount:0,'id'=>$returnId]);
            $pdo->prepare("UPDATE return_items SET item_status='APPROVED', approved_refund_amount=CASE WHEN :is_refund=1 THEN :approved_amount ELSE 0 END WHERE return_request_id=:id")
                ->execute(['is_refund'=>(string)$current['resolution_type']==='REFUND'?1:0,'approved_amount'=>(string)$current['resolution_type']==='REFUND'?$approvedAmount:0,'id'=>$returnId]);
            record_return_history($pdo,$returnId,'APPROVED','Pengajuan retur disetujui','Pelanggan dapat mengirim barang kembali dan memasukkan nomor resi.',(int)$adminUser['id']);
            create_notification($pdo,'Retur disetujui',(string)$current['return_number'].' disetujui. Silakan kirim barang kembali dan masukkan nomor resi.','return_detail.php?id='.$returnId,'return','customer',(int)$current['user_id'],'return-approved-'.$returnId,'return',$returnId,'order_updates');
            $message='Pengajuan retur berhasil disetujui.';
        } elseif ($action === 'reject') {
            if (!in_array($status,['SUBMITTED','UNDER_REVIEW'],true)) throw new RuntimeException('Retur tidak dapat ditolak pada status sekarang.');
            $reason=trim((string)($_POST['rejection_reason']??''));
            if (text_length($reason)<5 || text_length($reason)>255) throw new RuntimeException('Alasan penolakan harus terdiri dari 5 sampai 255 karakter.');
            $pdo->prepare("UPDATE return_requests SET status='REJECTED', rejection_reason=:reason, reviewed_by=:admin, reviewed_at=NOW() WHERE id=:id")
                ->execute(['reason'=>$reason,'admin'=>(int)$adminUser['id'],'id'=>$returnId]);
            $pdo->prepare("UPDATE return_items SET item_status='REJECTED' WHERE return_request_id=:id")->execute(['id'=>$returnId]);
            record_return_history($pdo,$returnId,'REJECTED','Pengajuan retur ditolak',$reason,(int)$adminUser['id']);
            create_notification($pdo,'Retur ditolak',(string)$current['return_number'].' ditolak. Alasan: '.$reason.'.','return_detail.php?id='.$returnId,'return','customer',(int)$current['user_id'],'return-rejected-'.$returnId,'return',$returnId,'order_updates');
            $message='Pengajuan retur berhasil ditolak.';
        } elseif ($action === 'receive') {
            if ($status !== 'CUSTOMER_SHIPPED') throw new RuntimeException('Barang hanya dapat diterima setelah pelanggan mengirim retur.');
            $inspection=trim((string)($_POST['inspection_result']??''));
            $note=trim((string)($_POST['inspection_note']??''));
            if ($inspection==='') throw new RuntimeException('Pilih hasil pemeriksaan awal.');
            $pdo->prepare("UPDATE return_requests SET status='STORE_RECEIVED', inspection_result=:inspection, inspection_note=:note, received_by_store_at=NOW() WHERE id=:id")
                ->execute(['inspection'=>$inspection,'note'=>$note!==''?$note:null,'id'=>$returnId]);
            $pdo->prepare("UPDATE return_items SET item_status='STORE_RECEIVED' WHERE return_request_id=:id")->execute(['id'=>$returnId]);
            $firstItemStatement = $pdo->prepare('SELECT id FROM return_items WHERE return_request_id=:id ORDER BY id LIMIT 1');
            $firstItemStatement->execute(['id'=>$returnId]);
            $firstReturnItemId = (int) ($firstItemStatement->fetchColumn() ?: 0);
            $existingImageStatement = $pdo->prepare('SELECT COUNT(*) FROM return_images WHERE return_request_id=:id');
            $existingImageStatement->execute(['id'=>$returnId]);
            $uploadErrors = store_return_images($pdo,$returnId,$firstReturnItemId>0?$firstReturnItemId:null,$_FILES['inspection_images']??[],'STORE_INSPECTION',(int)$existingImageStatement->fetchColumn());
            if ($uploadErrors !== []) throw new RuntimeException(implode(' ', $uploadErrors));
            record_return_history($pdo,$returnId,'STORE_RECEIVED','Barang retur diterima toko',$inspection.($note!==''?' · '.$note:''),(int)$adminUser['id']);
            create_notification($pdo,'Barang retur diterima',(string)$current['return_number'].' sudah diterima toko dan sedang diperiksa.','return_detail.php?id='.$returnId,'return','customer',(int)$current['user_id'],'return-received-'.$returnId,'return',$returnId,'order_updates');
            $message='Barang retur ditandai telah diterima.';
        } elseif ($action === 'process') {
            if ($status !== 'STORE_RECEIVED') throw new RuntimeException('Retur hanya dapat diproses setelah barang diterima toko.');
            $restock=trim((string)($_POST['restock_action']??'PENDING'));
            $note=trim((string)($_POST['admin_note']??''));
            $itemStatement=$pdo->prepare('SELECT * FROM return_items WHERE return_request_id=:id FOR UPDATE');
            $itemStatement->execute(['id'=>$returnId]);
            $items=$itemStatement->fetchAll();
            if ($restock==='RESTOCKED') {
                foreach($items as $item) restock_return_item($pdo,$item,(int)$adminUser['id']);
            } elseif ($restock==='NOT_RESTOCKABLE') {
                $pdo->prepare("UPDATE return_items SET restock_status='NOT_RESTOCKABLE', restocked_quantity=0 WHERE return_request_id=:id AND restock_status='PENDING'")->execute(['id'=>$returnId]);
            }
            $pdo->prepare("UPDATE return_requests SET status='PROCESSING', processed_at=NOW(), admin_note=:note, refund_status=CASE WHEN resolution_type='REFUND' THEN 'PROCESSING' ELSE refund_status END WHERE id=:id")
                ->execute(['note'=>$note!==''?$note:$current['admin_note'],'id'=>$returnId]);
            $pdo->prepare("UPDATE return_items SET item_status='PROCESSING' WHERE return_request_id=:id")->execute(['id'=>$returnId]);
            record_return_history($pdo,$returnId,'PROCESSING','Penyelesaian retur sedang diproses',$note!==''?$note:'Toko memproses penyelesaian retur.',(int)$adminUser['id']);
            create_notification($pdo,'Retur sedang diproses',(string)$current['return_number'].' sedang masuk tahap penyelesaian.','return_detail.php?id='.$returnId,'return','customer',(int)$current['user_id'],'return-processing-'.$returnId,'return',$returnId,'order_updates');
            $message='Retur masuk tahap pemrosesan.';
        } elseif ($action === 'complete_refund') {
            if ($status !== 'PROCESSING' || (string)$current['resolution_type']!=='REFUND') throw new RuntimeException('Pengembalian dana tidak dapat diselesaikan pada status sekarang.');
            $reference=trim((string)($_POST['refund_reference']??''));
            $amount=(float)($_POST['refund_amount']??$current['refund_amount']);
            if ($amount<0 || text_length($reference)<3) throw new RuntimeException('Nilai dana atau referensi transaksi tidak valid.');
            $pdo->prepare("UPDATE return_requests SET status='COMPLETED', refund_amount=:amount, refund_status='COMPLETED', refund_reference=:reference, refunded_at=NOW(), completed_at=NOW() WHERE id=:id")
                ->execute(['amount'=>$amount,'reference'=>$reference,'id'=>$returnId]);
            $pdo->prepare("UPDATE return_items SET item_status='COMPLETED' WHERE return_request_id=:id")->execute(['id'=>$returnId]);
            record_return_history($pdo,$returnId,'COMPLETED','Pengembalian dana selesai','Dana '.rupiah($amount).' telah diproses. Referensi: '.$reference,(int)$adminUser['id']);
            create_notification($pdo,'Pengembalian dana selesai','Dana '.rupiah($amount).' untuk '.(string)$current['return_number'].' telah diproses.','return_detail.php?id='.$returnId,'return','customer',(int)$current['user_id'],'return-refunded-'.$returnId,'return',$returnId,'order_updates');
            $message='Pengembalian dana dan retur berhasil diselesaikan.';
        } elseif ($action === 'ship_replacement') {
            if ($status !== 'PROCESSING' || (string)$current['resolution_type']!=='REPLACEMENT') throw new RuntimeException('Barang pengganti tidak dapat dikirim pada status sekarang.');
            $tracking=trim((string)($_POST['replacement_tracking_number']??''));
            if (text_length($tracking)<4 || text_length($tracking)>100) throw new RuntimeException('Nomor resi pengganti tidak valid.');
            $itemStatement=$pdo->prepare('SELECT * FROM return_items WHERE return_request_id=:id ORDER BY id LIMIT 1 FOR UPDATE');
            $itemStatement->execute(['id'=>$returnId]);
            $item=$itemStatement->fetch();
            if (!$item) throw new RuntimeException('Produk retur tidak ditemukan.');
            $qty=(int)$item['return_quantity']; $productId=(int)$item['product_id']; $variantId=(int)($item['variant_id']??0);
            if ($variantId>0) {
                $stockStmt=$pdo->prepare('SELECT stock FROM product_variants WHERE id=:id AND product_id=:product_id FOR UPDATE');
                $stockStmt->execute(['id'=>$variantId,'product_id'=>$productId]); $before=$stockStmt->fetchColumn();
                if ($before===false || (int)$before<$qty) throw new RuntimeException('Stok variasi pengganti tidak mencukupi.');
                $after=(int)$before-$qty; $pdo->prepare('UPDATE product_variants SET stock=:stock WHERE id=:id')->execute(['stock'=>$after,'id'=>$variantId]); sync_product_variant_summary($pdo,$productId);
                record_stock_history($pdo,$productId,'RETURN_REPLACEMENT',$qty,(int)$before,$after,'return_request',$returnId,'Stok dikurangi untuk barang pengganti.',(int)$adminUser['id'],$variantId);
            } else {
                $stockStmt=$pdo->prepare('SELECT stock FROM products WHERE id=:id FOR UPDATE'); $stockStmt->execute(['id'=>$productId]); $before=$stockStmt->fetchColumn();
                if ($before===false || (int)$before<$qty) throw new RuntimeException('Stok produk pengganti tidak mencukupi.');
                $after=(int)$before-$qty; $pdo->prepare('UPDATE products SET stock=:stock WHERE id=:id')->execute(['stock'=>$after,'id'=>$productId]);
                record_stock_history($pdo,$productId,'RETURN_REPLACEMENT',$qty,(int)$before,$after,'return_request',$returnId,'Stok dikurangi untuk barang pengganti.',(int)$adminUser['id']);
            }
            $pdo->prepare("UPDATE return_requests SET status='COMPLETED', replacement_product_id=:product_id, replacement_variant_id=:variant_id, replacement_tracking_number=:tracking, replacement_shipped_at=NOW(), completed_at=NOW() WHERE id=:id")
                ->execute(['product_id'=>$productId,'variant_id'=>$variantId>0?$variantId:null,'tracking'=>$tracking,'id'=>$returnId]);
            $pdo->prepare("UPDATE return_items SET item_status='COMPLETED' WHERE return_request_id=:id")->execute(['id'=>$returnId]);
            record_return_history($pdo,$returnId,'COMPLETED','Produk pengganti dikirim','Nomor resi pengganti: '.$tracking,(int)$adminUser['id']);
            create_notification($pdo,'Produk pengganti dikirim','Produk pengganti untuk '.(string)$current['return_number'].' dikirim. Resi: '.$tracking.'.','return_detail.php?id='.$returnId,'return','customer',(int)$current['user_id'],'return-replacement-'.$returnId,'return',$returnId,'order_updates');
            $message='Barang pengganti dikirim dan retur diselesaikan.';
        } else {
            throw new RuntimeException('Tindakan retur tidak valid.');
        }

        log_admin_activity($pdo,'RETURN_UPDATE','Memproses retur '.(string)$current['return_number'].' dengan tindakan '.$action.'.');
        $pdo->commit();
        flash('success',$message);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger',$exception instanceof RuntimeException?$exception->getMessage():'Proses retur gagal dijalankan.');
    }
    redirect('return_detail.php?id='.$returnId);
}

$return=$loadReturn();
$itemStatement=$pdo->prepare('SELECT * FROM return_items WHERE return_request_id=:id ORDER BY id'); $itemStatement->execute(['id'=>$returnId]); $items=$itemStatement->fetchAll();
$imageStatement=$pdo->prepare('SELECT * FROM return_images WHERE return_request_id=:id ORDER BY sort_order,id'); $imageStatement->execute(['id'=>$returnId]); $images=$imageStatement->fetchAll();
$historyStatement=$pdo->prepare('SELECT rh.*,u.name AS changed_by_name FROM return_histories rh LEFT JOIN users u ON u.id=rh.changed_by WHERE rh.return_request_id=:id ORDER BY rh.id'); $historyStatement->execute(['id'=>$returnId]); $histories=$historyStatement->fetchAll();
$pageTitle='Detail Retur'; $activeMenu='returns'; require __DIR__.'/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1><?= e((string)$return['return_number']) ?></h1><p>Pesanan <?= e(order_code($return)) ?> · <?= e((string)$return['customer_name']) ?></p></div><div class="admin-page-actions"><a class="admin-button admin-button-secondary" href="returns.php">← Kembali</a><a class="admin-button admin-button-secondary" href="../return_detail.php?id=<?= $returnId ?>" target="_blank">Tampilan Pelanggan</a></div></div>
<div class="admin-detail-grid"><div class="admin-side-stack">
<section class="admin-card"><div class="admin-card-head"><h2>Ringkasan Retur</h2><span class="admin-badge <?= e(return_status_css_class((string)$return['status'])) ?>"><?= e(return_status_label((string)$return['status'])) ?></span></div><div class="admin-detail-list"><div><span>Penyelesaian</span><strong><?= e(return_resolution_label((string)$return['resolution_type'])) ?></strong></div><div><span>Alasan</span><strong><?= e((string)$return['reason_summary']) ?></strong></div><div><span>Diajukan</span><strong><?= format_datetime((string)$return['created_at']) ?></strong></div><?php if((string)$return['resolution_type']==='REFUND'): ?><div><span>Dana</span><strong><?= rupiah($return['refund_amount']) ?> · <?= e(return_refund_status_label((string)$return['refund_status'])) ?></strong></div><?php endif; ?></div><?php if($return['customer_note']): ?><div class="admin-alert admin-alert-info"><?= nl2br(e((string)$return['customer_note'])) ?></div><?php endif; ?><?php if($return['rejection_reason']): ?><div class="admin-alert admin-alert-danger"><?= e((string)$return['rejection_reason']) ?></div><?php endif; ?></section>
<section class="admin-card"><div class="admin-card-head"><h2>Produk</h2></div><?php foreach($items as $item): ?><div class="admin-product-cell return-admin-product"><img src="../<?= e((string)($item['product_image']?:'assets/images/bag.svg')) ?>" alt=""><div><strong><?= e((string)$item['product_name']) ?></strong><?php if($item['variant_name']): ?><small><?= e((string)$item['variant_name']) ?> · <?= e((string)$item['variant_sku']) ?></small><?php endif; ?><small><?= (int)$item['return_quantity'] ?> dari <?= (int)$item['purchased_quantity'] ?> · <?= rupiah($item['requested_refund_amount']) ?></small><small>Restock: <?= e((string)$item['restock_status']) ?></small></div></div><?php endforeach; ?><?php if($images!==[]): ?><div class="admin-review-images return-admin-images"><?php foreach($images as $image): ?><a href="../<?= e((string)$image['image_path']) ?>" target="_blank"><img src="../<?= e((string)$image['image_path']) ?>" alt="Bukti retur"><small><?= e((string)$image['image_type']) ?></small></a><?php endforeach; ?></div><?php endif; ?></section>
<section class="admin-card"><div class="admin-card-head"><h2>Timeline</h2></div><div class="admin-return-timeline"><?php foreach($histories as $history): ?><div><span></span><article><strong><?= e((string)$history['title']) ?></strong><small><?= format_datetime((string)$history['created_at']) ?><?= $history['changed_by_name']?' · '.e((string)$history['changed_by_name']):'' ?></small><?php if($history['description']): ?><p><?= e((string)$history['description']) ?></p><?php endif; ?></article></div><?php endforeach; ?></div></section>
</div><aside class="admin-side-stack">
<?php if((string)$return['status']==='SUBMITTED'): ?><section class="admin-card"><h2>Mulai Pemeriksaan</h2><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="return_id" value="<?= $returnId ?>"><input type="hidden" name="action" value="start_review"><button class="admin-button admin-button-primary" type="submit">Mulai Tinjau</button></form></section><?php endif; ?>
<?php if(in_array((string)$return['status'],['SUBMITTED','UNDER_REVIEW'],true)): ?><section class="admin-card"><h2>Keputusan Admin</h2><form method="post" class="admin-form-grid single"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="return_id" value="<?= $returnId ?>"><input type="hidden" name="action" value="approve"><label><span>Nilai dana disetujui</span><input class="admin-input" type="number" name="approved_refund_amount" min="0" value="<?= (float)$return['refund_amount'] ?>" <?= (string)$return['resolution_type']==='REFUND'?'':'disabled' ?>></label><label><span>Catatan persetujuan</span><textarea class="admin-textarea" name="admin_note" rows="3"></textarea></label><button class="admin-button admin-button-primary" type="submit">Setujui Retur</button></form><hr><form method="post" class="admin-form-grid single"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="return_id" value="<?= $returnId ?>"><input type="hidden" name="action" value="reject"><label><span>Alasan penolakan</span><textarea class="admin-textarea" name="rejection_reason" rows="3" minlength="5" maxlength="255" required></textarea></label><button class="admin-button admin-button-danger" type="submit">Tolak Retur</button></form></section><?php endif; ?>
<?php if((string)$return['status']==='CUSTOMER_SHIPPED'): ?><section class="admin-card"><h2>Terima dan Periksa Barang</h2><p><strong><?= e((string)$return['customer_return_courier']) ?></strong><br>Resi: <?= e((string)$return['customer_return_tracking_number']) ?></p><form method="post" enctype="multipart/form-data" class="admin-form-grid single"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="return_id" value="<?= $returnId ?>"><input type="hidden" name="action" value="receive"><label><span>Hasil pemeriksaan awal</span><select class="admin-select" name="inspection_result" required><option value="">Pilih</option><?php foreach(['Sesuai bukti','Kerusakan lebih parah','Kerusakan tidak ditemukan','Barang tidak lengkap','Produk berbeda','Kemasan rusak','Produk telah digunakan'] as $v): ?><option><?= e($v) ?></option><?php endforeach; ?></select></label><label><span>Catatan pemeriksaan</span><textarea class="admin-textarea" name="inspection_note" rows="4"></textarea></label><label><span>Foto hasil pemeriksaan (opsional)</span><input class="admin-input" type="file" name="inspection_images[]" accept="image/jpeg,image/png,image/webp" multiple><small>Maksimal total 5 foto per retur, 2 MB per foto.</small></label><button class="admin-button admin-button-primary" type="submit">Tandai Barang Diterima</button></form></section><?php endif; ?>
<?php if((string)$return['status']==='STORE_RECEIVED'): ?><section class="admin-card"><h2>Proses Penyelesaian</h2><form method="post" class="admin-form-grid single"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="return_id" value="<?= $returnId ?>"><input type="hidden" name="action" value="process"><label><span>Keputusan stok</span><select class="admin-select" name="restock_action"><option value="PENDING">Tentukan nanti</option><option value="RESTOCKED">Kembalikan ke stok</option><option value="NOT_RESTOCKABLE">Tidak layak dijual kembali</option></select></label><label><span>Catatan penyelesaian</span><textarea class="admin-textarea" name="admin_note" rows="4"></textarea></label><button class="admin-button admin-button-primary" type="submit">Mulai Proses</button></form></section><?php endif; ?>
<?php if((string)$return['status']==='PROCESSING' && (string)$return['resolution_type']==='REFUND'): ?><section class="admin-card"><h2>Selesaikan Pengembalian Dana</h2><form method="post" class="admin-form-grid single"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="return_id" value="<?= $returnId ?>"><input type="hidden" name="action" value="complete_refund"><label><span>Nilai dana</span><input class="admin-input" type="number" min="0" name="refund_amount" value="<?= (float)$return['refund_amount'] ?>" required></label><label><span>Referensi transaksi</span><input class="admin-input" type="text" name="refund_reference" minlength="3" maxlength="100" required></label><button class="admin-button admin-button-primary" type="submit">Tandai Dana Selesai</button></form></section><?php endif; ?>
<?php if((string)$return['status']==='PROCESSING' && (string)$return['resolution_type']==='REPLACEMENT'): ?><section class="admin-card"><h2>Kirim Barang Pengganti</h2><form method="post" class="admin-form-grid single"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="return_id" value="<?= $returnId ?>"><input type="hidden" name="action" value="ship_replacement"><label><span>Nomor resi pengganti</span><input class="admin-input" type="text" name="replacement_tracking_number" minlength="4" maxlength="100" required></label><button class="admin-button admin-button-primary" type="submit">Kirim dan Selesaikan</button></form></section><?php endif; ?>
<section class="admin-card"><h2>Informasi Pelanggan</h2><p><strong><?= e((string)$return['customer_name']) ?></strong><br><?= e((string)$return['customer_email']) ?><br><?= e((string)$return['shipping_phone']) ?></p><p><?= nl2br(e((string)$return['shipping_address'])) ?><br><?= e((string)$return['shipping_city']) ?>, <?= e((string)$return['shipping_province']) ?></p><?php if((string)$return['resolution_type']==='REFUND'): ?><hr><p><strong><?= e((string)$return['refund_method']) ?></strong><br><?= e((string)$return['refund_bank_name']) ?><br><?= e((string)$return['refund_account_name']) ?><br><?= e((string)$return['refund_account_number']) ?></p><?php endif; ?></section>
</aside></div>
<?php require __DIR__.'/includes/admin_footer.php'; ?>
