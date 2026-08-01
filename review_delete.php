<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('my_reviews.php');
}
verify_csrf();

$user = current_user();
$reviewId = filter_input(INPUT_POST, 'review_id', FILTER_VALIDATE_INT);
if (!$reviewId) {
    flash('warning', 'Ulasan tidak valid.');
    redirect('my_reviews.php');
}

$statement = $pdo->prepare(
    'SELECT id, product_id FROM product_reviews WHERE id = :id AND user_id = :user_id LIMIT 1'
);
$statement->execute(['id' => $reviewId, 'user_id' => (int) $user['id']]);
$review = $statement->fetch();
if (!$review) {
    flash('danger', 'Ulasan tidak ditemukan atau bukan milik akun Anda.');
    redirect('my_reviews.php');
}

$imageStatement = $pdo->prepare('SELECT image_path FROM product_review_images WHERE review_id = :review_id');
$imageStatement->execute(['review_id' => $reviewId]);
$imagePaths = $imageStatement->fetchAll(PDO::FETCH_COLUMN);

try {
    $pdo->beginTransaction();
    $delete = $pdo->prepare('DELETE FROM product_reviews WHERE id = :id AND user_id = :user_id');
    $delete->execute(['id' => $reviewId, 'user_id' => (int) $user['id']]);
    recalculate_product_rating($pdo, (int) $review['product_id']);
    $pdo->commit();

    foreach ($imagePaths as $path) {
        review_delete_image_file((string) $path);
    }
    flash('success', 'Ulasan berhasil dihapus.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', 'Ulasan belum dapat dihapus.');
}
redirect('my_reviews.php');
