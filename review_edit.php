<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
$reviewId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'review_id', FILTER_VALIDATE_INT);

if (!$reviewId) {
    flash('warning', 'Ulasan tidak valid.');
    redirect('my_reviews.php');
}

$statement = $pdo->prepare(
    "SELECT r.*, p.name AS product_name, p.image AS product_image,
            o.order_number, o.status AS order_status
     FROM product_reviews r
     INNER JOIN products p ON p.id = r.product_id
     INNER JOIN orders o ON o.id = r.order_id
     WHERE r.id = :id AND r.user_id = :user_id
     LIMIT 1"
);
$statement->execute(['id' => $reviewId, 'user_id' => (int) $user['id']]);
$review = $statement->fetch();

if (!$review) {
    flash('danger', 'Ulasan tidak ditemukan atau bukan milik akun Anda.');
    redirect('my_reviews.php');
}

$imageStatement = $pdo->prepare(
    'SELECT id, image_path, sort_order FROM product_review_images WHERE review_id = :review_id ORDER BY sort_order, id'
);
$imageStatement->execute(['review_id' => $reviewId]);
$images = $imageStatement->fetchAll();

$errors = [];
$rating = (int) $review['rating'];
$reviewTitle = (string) ($review['review_title'] ?? '');
$reviewText = (string) $review['review_text'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT) ?: 0;
    $reviewTitle = trim((string) ($_POST['review_title'] ?? ''));
    $reviewText = trim((string) ($_POST['review_text'] ?? ''));
    $removeImageIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['remove_images'] ?? [])))));

    if ($rating < 1 || $rating > 5) {
        $errors[] = 'Pilih rating antara 1 sampai 5 bintang.';
    }
    if (text_length($reviewTitle) > 150) {
        $errors[] = 'Judul ulasan maksimal 150 karakter.';
    }
    if (text_length($reviewText) < 10 || text_length($reviewText) > 2000) {
        $errors[] = 'Isi ulasan harus terdiri dari 10 sampai 2.000 karakter.';
    }

    $allowedImageIds = array_map(static fn(array $image): int => (int) $image['id'], $images);
    foreach ($removeImageIds as $imageId) {
        if (!in_array($imageId, $allowedImageIds, true)) {
            $errors[] = 'Pilihan foto yang akan dihapus tidak valid.';
            break;
        }
    }

    if ($errors === []) {
        $status = setting($pdo, 'review_moderation_enabled', '0') === '1' ? 'PENDING' : 'PUBLISHED';
        $oldStatus = (string) $review['status'];
        $deletedPaths = [];

        try {
            $pdo->beginTransaction();

            if ($removeImageIds !== []) {
                $placeholders = implode(',', array_fill(0, count($removeImageIds), '?'));
                $pathQuery = $pdo->prepare(
                    "SELECT image_path FROM product_review_images
                     WHERE review_id = ? AND id IN ({$placeholders})"
                );
                $pathQuery->execute(array_merge([$reviewId], $removeImageIds));
                $deletedPaths = $pathQuery->fetchAll(PDO::FETCH_COLUMN);

                $deleteImages = $pdo->prepare(
                    "DELETE FROM product_review_images
                     WHERE review_id = ? AND id IN ({$placeholders})"
                );
                $deleteImages->execute(array_merge([$reviewId], $removeImageIds));
            }

            $update = $pdo->prepare(
                'UPDATE product_reviews
                 SET rating = :rating,
                     review_title = :review_title,
                     review_text = :review_text,
                     status = :status,
                     rejection_reason = NULL,
                     is_edited = 1,
                     updated_at = NOW()
                 WHERE id = :id AND user_id = :user_id'
            );
            $update->execute([
                'rating' => $rating,
                'review_title' => $reviewTitle !== '' ? $reviewTitle : null,
                'review_text' => $reviewText,
                'status' => $status,
                'id' => $reviewId,
                'user_id' => (int) $user['id'],
            ]);

            recalculate_product_rating($pdo, (int) $review['product_id']);
            create_notification(
                $pdo,
                'Ulasan produk diperbarui',
                (string) $user['name'] . ' memperbarui ulasan untuk ' . (string) $review['product_name'] . '.',
                'review_detail.php?id=' . $reviewId,
                'review',
                'admin',
                null,
                'review-edited-' . $reviewId . '-' . date('YmdHis'),
                'review',
                $reviewId
            );
            $pdo->commit();

            foreach ($deletedPaths as $path) {
                review_delete_image_file((string) $path);
            }

            $remainingCount = max(0, count($images) - count($removeImageIds));
            $uploadErrors = isset($_FILES['review_images'])
                ? store_review_images($pdo, $reviewId, $_FILES['review_images'], $remainingCount)
                : [];

            flash($uploadErrors === [] ? 'success' : 'warning', $uploadErrors === []
                ? ($status === 'PUBLISHED' ? 'Ulasan berhasil diperbarui.' : 'Ulasan diperbarui dan menunggu moderasi admin.')
                : 'Ulasan diperbarui, tetapi: ' . implode(' ', $uploadErrors));
            redirect('my_reviews.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Ulasan belum dapat diperbarui. Silakan coba kembali.';
        }
    }
}

$pageTitle = 'Edit Ulasan';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header"><div class="container"><span class="eyebrow">Ulasan saya</span><h1>Edit Ulasan</h1><p>Perbarui rating, komentar, atau foto ulasan Anda.</p></div></section>
<section class="section">
<div class="container review-form-layout">
    <aside class="review-product-summary">
        <img src="<?= e((string) ($review['product_image'] ?: 'assets/images/bag.svg')) ?>" alt="<?= e((string) $review['product_name']) ?>">
        <div><span class="verified-purchase-badge">✓ Pembelian Terverifikasi</span><h2><?= e((string) $review['product_name']) ?></h2><?php if ($review['variant_name']): ?><p>Variasi: <?= e((string) $review['variant_name']) ?></p><?php endif; ?><p>Pesanan <?= e(order_code($review)) ?></p><span class="status-pill <?= e(review_status_css_class((string) $review['status'])) ?>"><?= e(review_status_label((string) $review['status'])) ?></span></div>
    </aside>
    <div class="review-form-card">
        <?php if ($errors !== []): ?><div class="alert alert-danger"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="review-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="review_id" value="<?= $reviewId ?>">
            <fieldset class="rating-fieldset"><legend>Rating</legend><div class="star-rating" data-star-rating><?php for ($star=5;$star>=1;$star--): ?><input id="rating-<?= $star ?>" type="radio" name="rating" value="<?= $star ?>" <?= $rating===$star?'checked':'' ?> required><label for="rating-<?= $star ?>">★</label><?php endfor; ?></div><small data-rating-label><?= $rating ?> bintang</small></fieldset>
            <label><span>Judul ulasan <small>(opsional)</small></span><input type="text" name="review_title" maxlength="150" value="<?= e($reviewTitle) ?>"></label>
            <label><span>Isi ulasan</span><textarea name="review_text" rows="7" minlength="10" maxlength="2000" required><?= e($reviewText) ?></textarea></label>
            <?php if ($images !== []): ?>
                <div><span class="review-label">Foto saat ini</span><div class="review-image-manage"><?php foreach ($images as $image): ?><label><img src="<?= e((string) $image['image_path']) ?>" alt="Foto ulasan"><span><input type="checkbox" name="remove_images[]" value="<?= (int) $image['id'] ?>"> Hapus</span></label><?php endforeach; ?></div></div>
            <?php endif; ?>
            <label><span>Tambah foto baru</span><input type="file" name="review_images[]" accept="image/jpeg,image/png,image/webp" multiple data-review-images><small>Total maksimal 3 foto, masing-masing maksimal 2 MB.</small></label>
            <div class="review-form-actions"><a class="button button-secondary" href="my_reviews.php">Batal</a><button class="button button-primary" type="submit">Simpan Perubahan</button></div>
        </form>
    </div>
</div>
</section>
<script src="assets/reviews.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
