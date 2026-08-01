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
    "SELECT oi.id, oi.order_id, oi.product_id, oi.variant_id, oi.product_name, oi.product_image,
            oi.variant_name, oi.variant_sku, oi.price, oi.quantity,
            o.order_number, o.status AS order_status, o.user_id,
            pr.id AS review_id
     FROM order_items oi
     INNER JOIN orders o ON o.id = oi.order_id
     LEFT JOIN product_reviews pr ON pr.order_item_id = oi.id
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
if ((string) $item['order_status'] !== 'Selesai') {
    flash('warning', 'Ulasan hanya dapat diberikan setelah pesanan selesai.');
    redirect('orders.php');
}
if (!empty($item['review_id'])) {
    flash('info', 'Produk ini sudah pernah Anda ulas.');
    redirect('review_edit.php?id=' . (int) $item['review_id']);
}

$errors = [];
$rating = 5;
$reviewTitle = '';
$reviewText = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT) ?: 0;
    $reviewTitle = trim((string) ($_POST['review_title'] ?? ''));
    $reviewText = trim((string) ($_POST['review_text'] ?? ''));

    if ($rating < 1 || $rating > 5) {
        $errors[] = 'Pilih rating antara 1 sampai 5 bintang.';
    }
    if (text_length($reviewTitle) > 150) {
        $errors[] = 'Judul ulasan maksimal 150 karakter.';
    }
    if (text_length($reviewText) < 10) {
        $errors[] = 'Isi ulasan minimal 10 karakter.';
    }
    if (text_length($reviewText) > 2000) {
        $errors[] = 'Isi ulasan maksimal 2.000 karakter.';
    }

    if ($errors === []) {
        $status = setting($pdo, 'review_moderation_enabled', '0') === '1' ? 'PENDING' : 'PUBLISHED';

        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO product_reviews (
                    user_id, product_id, variant_id, order_id, order_item_id,
                    rating, review_title, review_text, variant_name, variant_sku, status
                 ) VALUES (
                    :user_id, :product_id, :variant_id, :order_id, :order_item_id,
                    :rating, :review_title, :review_text, :variant_name, :variant_sku, :status
                 )'
            );
            $insert->execute([
                'user_id' => (int) $user['id'],
                'product_id' => (int) $item['product_id'],
                'variant_id' => !empty($item['variant_id']) ? (int) $item['variant_id'] : null,
                'order_id' => (int) $item['order_id'],
                'order_item_id' => (int) $item['id'],
                'rating' => $rating,
                'review_title' => $reviewTitle !== '' ? $reviewTitle : null,
                'review_text' => $reviewText,
                'variant_name' => trim((string) ($item['variant_name'] ?? '')) !== '' ? (string) $item['variant_name'] : null,
                'variant_sku' => trim((string) ($item['variant_sku'] ?? '')) !== '' ? (string) $item['variant_sku'] : null,
                'status' => $status,
            ]);
            $reviewId = (int) $pdo->lastInsertId();

            if ($status === 'PUBLISHED') {
                recalculate_product_rating($pdo, (int) $item['product_id']);
            }

            create_notification(
                $pdo,
                'Ulasan produk baru',
                (string) $user['name'] . ' memberikan rating ' . $rating . ' bintang untuk ' . (string) $item['product_name'] . '.',
                'review_detail.php?id=' . $reviewId,
                'review',
                'admin',
                null,
                'review-created-' . $reviewId,
                'review',
                $reviewId
            );
            $pdo->commit();

            $uploadErrors = isset($_FILES['review_images'])
                ? store_review_images($pdo, $reviewId, $_FILES['review_images'])
                : [];

            if ($uploadErrors !== []) {
                flash('warning', 'Ulasan berhasil disimpan, tetapi: ' . implode(' ', $uploadErrors));
            } else {
                flash('success', $status === 'PUBLISHED'
                    ? 'Terima kasih. Ulasan Anda berhasil diterbitkan.'
                    : 'Ulasan berhasil dikirim dan menunggu moderasi admin.');
            }
            redirect('my_reviews.php');
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                flash('info', 'Produk ini sudah pernah Anda ulas.');
                redirect('my_reviews.php');
            }
            $errors[] = 'Ulasan belum dapat disimpan. Silakan coba kembali.';
        }
    }
}

$pageTitle = 'Beri Ulasan';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header">
    <div class="container">
        <span class="eyebrow">Pembelian terverifikasi</span>
        <h1>Beri Ulasan Produk</h1>
        <p>Bagikan pengalaman Anda untuk membantu pelanggan lain memilih produk.</p>
    </div>
</section>

<section class="section">
    <div class="container review-form-layout">
        <aside class="review-product-summary">
            <img src="<?= e((string) ($item['product_image'] ?: 'assets/images/bag.svg')) ?>" alt="<?= e((string) $item['product_name']) ?>">
            <div>
                <span class="verified-purchase-badge">✓ Pembelian Terverifikasi</span>
                <h2><?= e((string) $item['product_name']) ?></h2>
                <?php if (trim((string) ($item['variant_name'] ?? '')) !== ''): ?>
                    <p>Variasi: <?= e((string) $item['variant_name']) ?></p>
                    <small>SKU <?= e((string) ($item['variant_sku'] ?? '-')) ?></small>
                <?php endif; ?>
                <p>Pesanan <?= e(order_code($item)) ?></p>
            </div>
        </aside>

        <div class="review-form-card">
            <?php if ($errors !== []): ?>
                <div class="alert alert-danger"><strong>Periksa kembali:</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="review-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="order_item_id" value="<?= (int) $item['id'] ?>">

                <fieldset class="rating-fieldset">
                    <legend>Berikan rating</legend>
                    <div class="star-rating" data-star-rating>
                        <?php for ($star = 5; $star >= 1; $star--): ?>
                            <input id="rating-<?= $star ?>" type="radio" name="rating" value="<?= $star ?>" <?= $rating === $star ? 'checked' : '' ?> required>
                            <label for="rating-<?= $star ?>" title="<?= $star ?> bintang">★</label>
                        <?php endfor; ?>
                    </div>
                    <small data-rating-label><?= $rating ?> bintang</small>
                </fieldset>

                <label>
                    <span>Judul ulasan <small>(opsional)</small></span>
                    <input type="text" name="review_title" maxlength="150" value="<?= e($reviewTitle) ?>" placeholder="Contoh: Produk sesuai dan nyaman digunakan">
                </label>

                <label>
                    <span>Isi ulasan</span>
                    <textarea name="review_text" rows="7" minlength="10" maxlength="2000" required placeholder="Ceritakan kualitas produk, kesesuaian variasi, dan pengalaman Anda..."><?= e($reviewText) ?></textarea>
                    <small>Minimal 10 karakter dan maksimal 2.000 karakter.</small>
                </label>

                <label>
                    <span>Foto ulasan <small>(opsional)</small></span>
                    <input type="file" name="review_images[]" accept="image/jpeg,image/png,image/webp" multiple data-review-images>
                    <small>Maksimal 3 foto, masing-masing 2 MB. Format JPG, PNG, atau WEBP.</small>
                </label>

                <div class="review-form-actions">
                    <a class="button button-secondary" href="orders.php">Kembali</a>
                    <button class="button button-primary" type="submit">Kirim Ulasan</button>
                </div>
            </form>
        </div>
    </div>
</section>
<script src="assets/reviews.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
