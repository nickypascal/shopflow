<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$reviewId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'review_id', FILTER_VALIDATE_INT);
if (!$reviewId) {
    flash('warning', 'Ulasan tidak valid.');
    redirect('reviews.php');
}

$reviewStatement = $pdo->prepare(
    "SELECT r.*, p.name AS product_name, p.image AS product_image,
            u.name AS customer_name, u.email AS customer_email,
            o.order_number
     FROM product_reviews r
     INNER JOIN products p ON p.id = r.product_id
     INNER JOIN users u ON u.id = r.user_id
     INNER JOIN orders o ON o.id = r.order_id
     WHERE r.id = :id LIMIT 1"
);
$reviewStatement->execute(['id' => $reviewId]);
$review = $reviewStatement->fetch();
if (!$review) {
    flash('danger', 'Ulasan tidak ditemukan.');
    redirect('reviews.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'status') {
            $newStatus = strtoupper(trim((string) ($_POST['status'] ?? '')));
            $allowed = ['PENDING', 'PUBLISHED', 'REJECTED', 'HIDDEN'];
            $reasonCode = trim((string) ($_POST['reason_code'] ?? ''));
            $customReason = trim((string) ($_POST['custom_reason'] ?? ''));
            $reasonMap = [
                'inappropriate' => 'Mengandung kata tidak pantas',
                'personal_info' => 'Mengandung informasi pribadi',
                'irrelevant' => 'Tidak berkaitan dengan produk',
                'spam' => 'Mengandung spam',
                'wrong_photo' => 'Foto tidak sesuai',
                'dangerous_link' => 'Mengandung tautan berbahaya',
                'duplicate' => 'Ulasan duplikat',
                'other' => $customReason,
            ];
            if (!in_array($newStatus, $allowed, true)) {
                throw new RuntimeException('Status ulasan tidak valid.');
            }
            $reason = $newStatus === 'REJECTED' ? trim((string) ($reasonMap[$reasonCode] ?? '')) : null;
            if ($newStatus === 'REJECTED' && $reason === '') {
                throw new RuntimeException('Alasan penolakan wajib diisi.');
            }

            $pdo->beginTransaction();
            $update = $pdo->prepare(
                'UPDATE product_reviews SET status = :status, rejection_reason = :reason, updated_at = NOW() WHERE id = :id'
            );
            $update->execute(['status' => $newStatus, 'reason' => $reason, 'id' => $reviewId]);
            recalculate_product_rating($pdo, (int) $review['product_id']);

            if ($newStatus === 'REJECTED') {
                create_notification($pdo, 'Ulasan perlu diperbaiki', 'Ulasan Anda untuk ' . (string)$review['product_name'] . ' belum dapat diterbitkan. Alasan: ' . $reason . '.', 'review_edit.php?id=' . $reviewId, 'review', 'customer', (int)$review['user_id'], 'review-rejected-' . $reviewId . '-' . date('YmdHis'), 'review', $reviewId);
            } elseif ($newStatus === 'PUBLISHED') {
                create_notification($pdo, 'Ulasan diterbitkan', 'Ulasan Anda untuk ' . (string)$review['product_name'] . ' sudah diterbitkan.', 'my_reviews.php', 'review', 'customer', (int)$review['user_id'], 'review-published-' . $reviewId, 'review', $reviewId);
            }
            log_admin_activity($pdo, 'REVIEW_STATUS', 'Mengubah status ulasan #' . $reviewId . ' menjadi ' . $newStatus . '.');
            $pdo->commit();
            flash('success', 'Status ulasan berhasil diperbarui.');
        } elseif ($action === 'reply') {
            $reply = trim((string) ($_POST['admin_reply'] ?? ''));
            if (text_length($reply) < 3 || text_length($reply) > 1500) {
                throw new RuntimeException('Balasan harus terdiri dari 3 sampai 1.500 karakter.');
            }
            $update = $pdo->prepare(
                'UPDATE product_reviews SET admin_reply = :reply, replied_by = :admin_id, replied_at = NOW() WHERE id = :id'
            );
            $update->execute(['reply' => $reply, 'admin_id' => (int)$adminUser['id'], 'id' => $reviewId]);
            create_notification($pdo, 'Ulasan Anda dibalas', 'Toko memberikan balasan untuk ulasan ' . (string)$review['product_name'] . '.', 'my_reviews.php', 'review', 'customer', (int)$review['user_id'], 'review-replied-' . $reviewId . '-' . date('YmdHis'), 'review', $reviewId);
            log_admin_activity($pdo, 'REVIEW_REPLY', 'Membalas ulasan #' . $reviewId . '.');
            flash('success', 'Balasan toko berhasil disimpan.');
        } elseif ($action === 'delete_image') {
            $imageId = filter_input(INPUT_POST, 'image_id', FILTER_VALIDATE_INT);
            if (!$imageId) {
                throw new RuntimeException('Foto ulasan tidak valid.');
            }
            $imageStatement = $pdo->prepare('SELECT image_path FROM product_review_images WHERE id = :id AND review_id = :review_id');
            $imageStatement->execute(['id' => $imageId, 'review_id' => $reviewId]);
            $path = $imageStatement->fetchColumn();
            if ($path === false) {
                throw new RuntimeException('Foto ulasan tidak ditemukan.');
            }
            $pdo->prepare('DELETE FROM product_review_images WHERE id = :id AND review_id = :review_id')->execute(['id' => $imageId, 'review_id' => $reviewId]);
            review_delete_image_file((string)$path);
            log_admin_activity($pdo, 'REVIEW_IMAGE_DELETE', 'Menghapus foto pada ulasan #' . $reviewId . '.');
            flash('success', 'Foto ulasan berhasil dihapus.');
        } else {
            throw new RuntimeException('Tindakan ulasan tidak valid.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $exception->getMessage());
    }
    redirect('review_detail.php?id=' . $reviewId);
}

$imageStatement = $pdo->prepare('SELECT id, image_path FROM product_review_images WHERE review_id = :review_id ORDER BY sort_order, id');
$imageStatement->execute(['review_id' => $reviewId]);
$images = $imageStatement->fetchAll();

$pageTitle = 'Detail Ulasan';
$activeMenu = 'reviews';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1>Detail Ulasan</h1><p>Ulasan #<?= $reviewId ?> dari pembelian terverifikasi.</p></div><div class="admin-page-actions"><a class="admin-button admin-button-secondary" href="reviews.php">← Kembali</a><a class="admin-button admin-button-primary" href="../product_detail.php?id=<?= (int)$review['product_id'] ?>#ulasan" target="_blank">Lihat Produk</a></div></div>

<div class="admin-detail-grid">
<section class="admin-card">
    <div class="admin-product-cell" style="margin-bottom:18px"><img src="../<?= e((string)($review['product_image'] ?: 'assets/images/bag.svg')) ?>" alt=""><div><strong><?= e((string)$review['product_name']) ?></strong><?php if($review['variant_name']): ?><small><?= e((string)$review['variant_name']) ?><?php if($review['variant_sku']): ?> · <?= e((string)$review['variant_sku']) ?><?php endif; ?></small><?php endif; ?><small>Pesanan <?= e(order_code($review)) ?></small></div></div>
    <div class="admin-review-stars admin-review-stars-large"><?= str_repeat('★',(int)$review['rating']) ?><span><?= str_repeat('☆',5-(int)$review['rating']) ?></span></div>
    <?php if($review['review_title']): ?><h2><?= e((string)$review['review_title']) ?></h2><?php endif; ?>
    <p class="admin-review-text"><?= nl2br(e((string)$review['review_text'])) ?></p>
    <div class="admin-review-meta"><span>Oleh <?= e((string)$review['customer_name']) ?> (<?= e((string)$review['customer_email']) ?>)</span><span><?= format_datetime((string)$review['created_at']) ?><?= (int)$review['is_edited']===1?' · Diedit':'' ?></span></div>
    <?php if($images!==[]): ?><div class="admin-review-images"><?php foreach($images as $image): ?><div><a href="../<?= e((string)$image['image_path']) ?>" target="_blank"><img src="../<?= e((string)$image['image_path']) ?>" alt="Foto ulasan"></a><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="review_id" value="<?= $reviewId ?>"><input type="hidden" name="action" value="delete_image"><input type="hidden" name="image_id" value="<?= (int)$image['id'] ?>"><button class="admin-button admin-button-danger admin-button-small" type="submit" data-confirm="Hapus foto ini?">Hapus Foto</button></form></div><?php endforeach; ?></div><?php endif; ?>
    <?php if($review['admin_reply']): ?><div class="admin-review-reply-box"><strong>Balasan Toko</strong><p><?= nl2br(e((string)$review['admin_reply'])) ?></p><small><?= format_datetime((string)$review['replied_at']) ?></small></div><?php endif; ?>
</section>

<aside class="admin-side-stack">
<section class="admin-card"><div class="admin-card-head"><h2>Status Moderasi</h2><span class="admin-badge <?= e(review_status_css_class((string)$review['status'])) ?>"><?= e(review_status_label((string)$review['status'])) ?></span></div>
<form method="post" class="admin-form-grid single">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="review_id" value="<?= $reviewId ?>"><input type="hidden" name="action" value="status">
    <label><span>Status</span><select name="status" data-review-status><?php foreach(['PENDING'=>'Menunggu Moderasi','PUBLISHED'=>'Diterbitkan','HIDDEN'=>'Disembunyikan','REJECTED'=>'Ditolak'] as $key=>$label): ?><option value="<?= $key ?>" <?= (string)$review['status']===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label data-rejection-fields><span>Alasan penolakan</span><select name="reason_code"><option value="">Pilih alasan</option><option value="inappropriate">Mengandung kata tidak pantas</option><option value="personal_info">Mengandung informasi pribadi</option><option value="irrelevant">Tidak berkaitan dengan produk</option><option value="spam">Mengandung spam</option><option value="wrong_photo">Foto tidak sesuai</option><option value="dangerous_link">Mengandung tautan berbahaya</option><option value="duplicate">Ulasan duplikat</option><option value="other">Lainnya</option></select></label>
    <label data-rejection-fields><span>Alasan lainnya</span><textarea name="custom_reason" rows="3" maxlength="255"></textarea></label>
    <?php if($review['rejection_reason']): ?><div class="admin-alert admin-alert-warning">Alasan sebelumnya: <?= e((string)$review['rejection_reason']) ?></div><?php endif; ?>
    <button class="admin-button admin-button-primary" type="submit">Simpan Status</button>
</form></section>
<section class="admin-card"><div class="admin-card-head"><h2>Balasan Toko</h2></div><form method="post" class="admin-form-grid single"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="review_id" value="<?= $reviewId ?>"><input type="hidden" name="action" value="reply"><label><span>Balasan</span><textarea name="admin_reply" rows="6" minlength="3" maxlength="1500" required><?= e((string)($review['admin_reply']??'')) ?></textarea></label><button class="admin-button admin-button-primary" type="submit"><?= $review['admin_reply']?'Perbarui Balasan':'Kirim Balasan' ?></button></form></section>
</aside>
</div>
<script>const statusSelect=document.querySelector('[data-review-status]');const fields=document.querySelectorAll('[data-rejection-fields]');function syncReason(){const show=statusSelect&&statusSelect.value==='REJECTED';fields.forEach((el)=>el.style.display=show?'grid':'none');}statusSelect?.addEventListener('change',syncReason);syncReason();</script>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
