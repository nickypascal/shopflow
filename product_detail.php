<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$slug = trim((string) ($_GET['slug'] ?? ''));

if ($productId) {
    $statement = $pdo->prepare(
        "SELECT p.*, c.name AS category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id = :id AND p.status = 'active' LIMIT 1"
    );
    $statement->execute(['id' => $productId]);
} else {
    $statement = $pdo->prepare(
        "SELECT p.*, c.name AS category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.slug = :slug AND p.status = 'active' LIMIT 1"
    );
    $statement->execute(['slug' => $slug]);
}
$product = $statement->fetch();

if (!$product) {
    http_response_code(404);
    $pageTitle = 'Produk Tidak Ditemukan';
    require __DIR__ . '/includes/header.php';
    echo '<section class="section"><div class="container"><div class="empty-state"><div class="empty-icon">🔍</div><h1>Produk tidak ditemukan</h1><p>Produk mungkin sudah tidak aktif atau alamatnya salah.</p><a class="button button-primary" href="index.php#produk">Kembali ke Produk</a></div></div></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$variants = [];
if ((int) $product['has_variants'] === 1) {
    $variantStatement = $pdo->prepare(
        'SELECT id, sku, variant_name, color, size, material, price, stock,
                weight_grams, image, is_active
         FROM product_variants
         WHERE product_id = :product_id AND is_active = 1
         ORDER BY color, size, variant_name, id'
    );
    $variantStatement->execute(['product_id' => (int) $product['id']]);
    $variants = $variantStatement->fetchAll();
}

$availableVariants = array_values(array_filter(
    $variants,
    static fn (array $variant): bool => (int) $variant['stock'] > 0
));
$wishlistMap = wishlist_product_map($pdo);
$productWishlist = $wishlistMap[(int) $product['id']] ?? null;
$preferredWishlistVariantId = (int) ($productWishlist['preferred_variant_id'] ?? 0);
$preferredWishlistVariant = null;
if ($preferredWishlistVariantId > 0) {
    foreach ($variants as $variant) {
        if ((int) $variant['id'] === $preferredWishlistVariantId && (int) $variant['stock'] > 0) {
            $preferredWishlistVariant = $variant;
            break;
        }
    }
}
$defaultVariant = $preferredWishlistVariant ?? ($availableVariants[0] ?? ($variants[0] ?? null));
$promotionBadges = promotion_product_badges($pdo, [$product]);
$productPromotion = $promotionBadges[(int) $product['id']] ?? null;

$reviewRatingFilter = filter_input(INPUT_GET, 'review_rating', FILTER_VALIDATE_INT) ?: 0;
if ($reviewRatingFilter < 1 || $reviewRatingFilter > 5) {
    $reviewRatingFilter = 0;
}
$reviewPhotoOnly = (string) ($_GET['review_photo'] ?? '') === '1';
$reviewSort = (string) ($_GET['review_sort'] ?? 'latest');
if (!in_array($reviewSort, ['latest', 'highest', 'lowest'], true)) {
    $reviewSort = 'latest';
}
$reviewPage = max(1, filter_input(INPUT_GET, 'review_page', FILTER_VALIDATE_INT) ?: 1);
$reviewPerPage = 10;
$reviewOffset = ($reviewPage - 1) * $reviewPerPage;

$distributionStatement = $pdo->prepare(
    "SELECT rating, COUNT(*) AS total
     FROM product_reviews
     WHERE product_id = :product_id AND status = 'PUBLISHED'
     GROUP BY rating"
);
$distributionStatement->execute(['product_id' => (int) $product['id']]);
$reviewDistribution = array_fill(1, 5, 0);
foreach ($distributionStatement->fetchAll() as $row) {
    $reviewDistribution[(int) $row['rating']] = (int) $row['total'];
}

$reviewWhere = ["r.product_id = :product_id", "r.status = 'PUBLISHED'"];
$reviewParams = ['product_id' => (int) $product['id']];
if ($reviewRatingFilter > 0) {
    $reviewWhere[] = 'r.rating = :rating';
    $reviewParams['rating'] = $reviewRatingFilter;
}
if ($reviewPhotoOnly) {
    $reviewWhere[] = 'EXISTS (SELECT 1 FROM product_review_images x WHERE x.review_id = r.id)';
}
$orderBy = match ($reviewSort) {
    'highest' => 'r.rating DESC, r.created_at DESC',
    'lowest' => 'r.rating ASC, r.created_at DESC',
    default => 'r.created_at DESC, r.id DESC',
};
$countStatement = $pdo->prepare('SELECT COUNT(*) FROM product_reviews r WHERE ' . implode(' AND ', $reviewWhere));
$countStatement->execute($reviewParams);
$filteredReviewCount = (int) $countStatement->fetchColumn();
$reviewPageCount = max(1, (int) ceil($filteredReviewCount / $reviewPerPage));
if ($reviewPage > $reviewPageCount) {
    $reviewPage = $reviewPageCount;
    $reviewOffset = ($reviewPage - 1) * $reviewPerPage;
}

$reviewSql = "SELECT r.*, u.name AS customer_name,
                    GROUP_CONCAT(ri.image_path ORDER BY ri.sort_order, ri.id SEPARATOR '||') AS image_paths
             FROM product_reviews r
             INNER JOIN users u ON u.id = r.user_id
             LEFT JOIN product_review_images ri ON ri.review_id = r.id
             WHERE " . implode(' AND ', $reviewWhere) . "
             GROUP BY r.id
             ORDER BY {$orderBy}
             LIMIT {$reviewPerPage} OFFSET {$reviewOffset}";
$reviewStatement = $pdo->prepare($reviewSql);
$reviewStatement->execute($reviewParams);
$productReviews = $reviewStatement->fetchAll();

$pageTitle = (string) $product['name'];
require __DIR__ . '/includes/header.php';
?>
<section class="page-header product-detail-header">
    <div class="container">
        <a class="continue-link" href="index.php#produk">← Kembali ke katalog</a>
    </div>
</section>

<section class="section product-detail-section">
    <div class="container product-detail-layout">
        <div class="product-detail-media">
            <img
                id="product-detail-image"
                src="<?= e((string) (($defaultVariant['image'] ?? '') ?: $product['image'])) ?>"
                alt="<?= e((string) $product['name']) ?>"
            >
            <span class="category-badge"><?= category_icon((string) $product['category']) ?> <?= e((string) ($product['category_name'] ?: $product['category'])) ?></span>
            <?php if ($productPromotion): ?><span class="product-promo-badge product-promo-detail"><?= e((string) $productPromotion['label']) ?></span><?php endif; ?>
        </div>

        <div class="product-detail-copy" id="purchase">
            <span class="eyebrow">Detail Produk</span>
            <h1><?= e((string) $product['name']) ?></h1>
            <div class="rating" aria-label="Rating produk">
                <span class="rating-stars"><?= str_repeat('★', (int) round((float) $product['average_rating'])) ?><i><?= str_repeat('☆', 5 - (int) round((float) $product['average_rating'])) ?></i></span>
                <span><?= (int) $product['review_count'] > 0 ? number_format((float) $product['average_rating'], 1, ',', '.') . ' · ' . (int) $product['review_count'] . ' ulasan' : 'Belum ada ulasan' ?></span>
            </div>
            <?php if ($productPromotion): ?><div class="product-promo-callout"><strong><?= e((string) $productPromotion['name']) ?></strong><?php if ((string) $productPromotion['code'] !== ''): ?><span>Kode: <?= e((string) $productPromotion['code']) ?></span><?php else: ?><span>Diterapkan otomatis saat checkout</span><?php endif; ?></div><?php endif; ?>
            <p class="product-detail-description"><?= nl2br(e((string) $product['description'])) ?></p>

            <?php if ((string) ($_GET['purchase'] ?? '') === 'voucher'): ?>
                <div class="alert alert-info voucher-purchase-hint">
                    Pilih variasi dan jumlah, lalu tekan <strong>Beli dengan Voucher</strong>.
                </div>
            <?php endif; ?>

            <form class="product-detail-wishlist-form" method="post" action="wishlist_action.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                <input id="wishlist-variant-id" type="hidden" name="variant_id" value="<?= (int) ($defaultVariant['id'] ?? 0) ?>">
                <input type="hidden" name="return_to" value="product_detail.php?id=<?= (int) $product['id'] ?>">
                <button class="button <?= $productWishlist ? 'button-wishlist-active' : 'button-secondary' ?> button-full" type="submit">
                    <?= $productWishlist ? '♥ Tersimpan di Wishlist' : '♡ Simpan ke Wishlist' ?>
                </button>
                <?php if ((int) $product['has_variants'] === 1): ?>
                    <small>Variasi yang sedang dipilih akan ikut disimpan.</small>
                <?php endif; ?>
            </form>

            <?php if ((int) $product['has_variants'] === 1): ?>
                <form method="post" action="cart_action.php" class="variant-purchase-form" id="variant-purchase-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

                    <div class="variant-section-heading">
                        <div>
                            <span>Pilih Variasi</span>
                            <strong id="selected-variant-name"><?= e((string) ($defaultVariant['variant_name'] ?? 'Belum tersedia')) ?></strong>
                        </div>
                        <span class="variant-count-badge"><?= count($variants) ?> pilihan</span>
                    </div>

                    <?php if ($variants === []): ?>
                        <div class="alert alert-warning">Produk ini belum mempunyai variasi aktif. Silakan hubungi toko.</div>
                    <?php else: ?>
                        <div class="variant-option-grid">
                            <?php foreach ($variants as $index => $variant): ?>
                                <?php
                                $isSelected = $defaultVariant && (int) $defaultVariant['id'] === (int) $variant['id'];
                                $variantImage = trim((string) ($variant['image'] ?? '')) !== '' ? (string) $variant['image'] : (string) $product['image'];
                                ?>
                                <label class="variant-option <?= (int) $variant['stock'] < 1 ? 'is-disabled' : '' ?>">
                                    <input
                                        type="radio"
                                        name="variant_id"
                                        value="<?= (int) $variant['id'] ?>"
                                        data-name="<?= e((string) $variant['variant_name']) ?>"
                                        data-price="<?= e((string) $variant['price']) ?>"
                                        data-stock="<?= (int) $variant['stock'] ?>"
                                        data-weight="<?= (int) $variant['weight_grams'] ?>"
                                        data-image="<?= e($variantImage) ?>"
                                        <?= $isSelected ? 'checked' : '' ?>
                                        <?= (int) $variant['stock'] < 1 ? 'disabled' : '' ?>
                                        required
                                    >
                                    <span class="variant-option-main">
                                        <strong><?= e((string) $variant['variant_name']) ?></strong>
                                        <small>SKU <?= e((string) $variant['sku']) ?></small>
                                    </span>
                                    <span class="variant-option-meta">
                                        <b><?= rupiah($variant['price']) ?></b>
                                        <small><?= (int) $variant['stock'] > 0 ? 'Stok ' . (int) $variant['stock'] : 'Habis' ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="product-purchase-summary">
                            <div>
                                <span>Harga</span>
                                <strong id="selected-variant-price"><?= $defaultVariant ? rupiah($defaultVariant['price']) : '-' ?></strong>
                            </div>
                            <div>
                                <span>Stok</span>
                                <strong id="selected-variant-stock"><?= $defaultVariant ? (int) $defaultVariant['stock'] . ' unit' : '-' ?></strong>
                            </div>
                            <div>
                                <span>Berat</span>
                                <strong id="selected-variant-weight"><?= $defaultVariant ? e(weight_label((int) $defaultVariant['weight_grams'])) : '-' ?></strong>
                            </div>
                        </div>

                        <label class="purchase-quantity">
                            <span>Jumlah</span>
                            <input id="purchase-quantity" type="number" name="quantity" min="1" max="<?= (int) ($defaultVariant['stock'] ?? 1) ?>" value="1" required>
                        </label>
                        <div class="purchase-action-grid">
                            <button class="button button-primary button-full" id="add-variant-button" type="submit" <?= $availableVariants === [] ? 'disabled' : '' ?>>+ Tambahkan ke Keranjang</button>
                            <button class="button button-voucher button-full" id="voucher-variant-button" type="submit" formaction="buy_with_voucher.php" <?= $availableVariants === [] ? 'disabled' : '' ?>>Beli dengan Voucher</button>
                        </div>
                    <?php endif; ?>
                </form>
            <?php else: ?>
                <div class="product-purchase-summary">
                    <div><span>Harga</span><strong><?= rupiah($product['price']) ?></strong></div>
                    <div><span>Stok</span><strong><?= (int) $product['stock'] ?> unit</strong></div>
                    <div><span>Berat</span><strong><?= e(weight_label((int) $product['default_weight_grams'])) ?></strong></div>
                </div>
                <form method="post" action="cart_action.php" class="variant-purchase-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                    <input type="hidden" name="variant_id" value="0">
                    <label class="purchase-quantity">
                        <span>Jumlah</span>
                        <input type="number" name="quantity" min="1" max="<?= max(1, (int) $product['stock']) ?>" value="1" required>
                    </label>
                    <div class="purchase-action-grid">
                        <button class="button button-primary button-full" type="submit" <?= (int) $product['stock'] < 1 ? 'disabled' : '' ?>><?= (int) $product['stock'] > 0 ? '+ Tambahkan ke Keranjang' : 'Stok Habis' ?></button>
                        <button class="button button-voucher button-full" type="submit" formaction="buy_with_voucher.php" <?= (int) $product['stock'] < 1 ? 'disabled' : '' ?>>Beli dengan Voucher</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="section product-reviews-section" id="ulasan">
    <div class="container">
        <div class="section-heading review-section-heading">
            <div><span class="eyebrow">Pembelian terverifikasi</span><h2>Ulasan Pelanggan</h2><p>Pengalaman pelanggan yang benar-benar membeli produk ini.</p></div>
            <div class="review-score-box"><strong><?= number_format((float) $product['average_rating'], 1, ',', '.') ?></strong><div><span class="review-stars"><?= str_repeat('★', (int) round((float) $product['average_rating'])) ?><span><?= str_repeat('☆', 5 - (int) round((float) $product['average_rating'])) ?></span></span><small><?= (int) $product['review_count'] ?> ulasan</small></div></div>
        </div>

        <div class="review-summary-layout">
            <div class="review-distribution">
                <?php for ($star = 5; $star >= 1; $star--): ?>
                    <?php $percentage = (int) $product['review_count'] > 0 ? round($reviewDistribution[$star] / (int) $product['review_count'] * 100) : 0; ?>
                    <div><span><?= $star ?> bintang</span><div class="review-bar"><i style="width:<?= $percentage ?>%"></i></div><strong><?= $reviewDistribution[$star] ?></strong></div>
                <?php endfor; ?>
            </div>
            <form class="review-filter-form" method="get" action="product_detail.php#ulasan">
                <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                <label><span>Rating</span><select name="review_rating"><option value="0">Semua rating</option><?php for ($star=5;$star>=1;$star--): ?><option value="<?= $star ?>" <?= $reviewRatingFilter===$star?'selected':'' ?>><?= $star ?> bintang</option><?php endfor; ?></select></label>
                <label><span>Urutkan</span><select name="review_sort"><option value="latest" <?= $reviewSort==='latest'?'selected':'' ?>>Terbaru</option><option value="highest" <?= $reviewSort==='highest'?'selected':'' ?>>Rating tertinggi</option><option value="lowest" <?= $reviewSort==='lowest'?'selected':'' ?>>Rating terendah</option></select></label>
                <label class="review-photo-check"><input type="checkbox" name="review_photo" value="1" <?= $reviewPhotoOnly?'checked':'' ?>> Hanya dengan foto</label>
                <button class="button button-secondary" type="submit">Terapkan Filter</button>
            </form>
        </div>

        <?php if ($productReviews === []): ?>
            <div class="empty-state review-empty"><div class="empty-icon">⭐</div><h3>Belum ada ulasan yang sesuai</h3><p>Ulasan pelanggan akan tampil di bagian ini.</p></div>
        <?php else: ?>
            <div class="product-review-list">
                <?php foreach ($productReviews as $review): ?>
                    <?php $reviewImages = trim((string) ($review['image_paths'] ?? '')) !== '' ? explode('||', (string) $review['image_paths']) : []; ?>
                    <article class="product-review-card">
                        <div class="product-review-head"><div><strong><?= e(mask_customer_name((string) $review['customer_name'])) ?></strong><span class="verified-purchase-badge">✓ Pembelian Terverifikasi</span></div><small><?= format_datetime((string) $review['created_at']) ?><?= (int)$review['is_edited']===1?' · Diedit':'' ?></small></div>
                        <div class="review-stars" aria-label="<?= (int) $review['rating'] ?> dari 5 bintang"><?= str_repeat('★', (int) $review['rating']) ?><span><?= str_repeat('☆', 5-(int)$review['rating']) ?></span></div>
                        <?php if ($review['variant_name']): ?><small class="review-variant">Variasi: <?= e((string) $review['variant_name']) ?></small><?php endif; ?>
                        <?php if ($review['review_title']): ?><h3><?= e((string) $review['review_title']) ?></h3><?php endif; ?>
                        <p><?= nl2br(e((string) $review['review_text'])) ?></p>
                        <?php if ($reviewImages !== []): ?><div class="review-image-grid"><?php foreach ($reviewImages as $image): ?><a href="<?= e($image) ?>" target="_blank" rel="noopener"><img src="<?= e($image) ?>" alt="Foto ulasan pelanggan"></a><?php endforeach; ?></div><?php endif; ?>
                        <?php if ($review['admin_reply']): ?><div class="admin-review-reply"><strong>Balasan Toko</strong><p><?= nl2br(e((string) $review['admin_reply'])) ?></p><small><?= format_datetime((string) $review['replied_at']) ?></small></div><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($reviewPageCount > 1): ?><nav class="review-pagination" aria-label="Halaman ulasan"><?php for ($page=1;$page<=$reviewPageCount;$page++): ?><?php $query=http_build_query(['id'=>(int)$product['id'],'review_rating'=>$reviewRatingFilter,'review_sort'=>$reviewSort,'review_photo'=>$reviewPhotoOnly?'1':null,'review_page'=>$page]); ?><a class="<?= $page===$reviewPage?'active':'' ?>" href="product_detail.php?<?= e($query) ?>#ulasan"><?= $page ?></a><?php endfor; ?></nav><?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<script>
(() => {
    const radios = document.querySelectorAll('input[name="variant_id"]');
    const name = document.getElementById('selected-variant-name');
    const price = document.getElementById('selected-variant-price');
    const stock = document.getElementById('selected-variant-stock');
    const weight = document.getElementById('selected-variant-weight');
    const image = document.getElementById('product-detail-image');
    const quantity = document.getElementById('purchase-quantity');
    const button = document.getElementById('add-variant-button');
    const voucherButton = document.getElementById('voucher-variant-button');
    const wishlistVariant = document.getElementById('wishlist-variant-id');

    const formatRupiah = (value) => new Intl.NumberFormat('id-ID', {
        style: 'currency', currency: 'IDR', maximumFractionDigits: 0
    }).format(Number(value || 0));
    const formatWeight = (grams) => {
        const value = Number(grams || 0);
        if (value >= 1000) return `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(value / 1000)} kg`;
        return `${new Intl.NumberFormat('id-ID').format(value)} gram`;
    };

    function updateVariant() {
        const selected = document.querySelector('input[name="variant_id"]:checked');
        if (!selected) return;
        const available = Number(selected.dataset.stock || 0);
        if (name) name.textContent = selected.dataset.name || '-';
        if (price) price.textContent = formatRupiah(selected.dataset.price);
        if (stock) stock.textContent = `${available} unit`;
        if (weight) weight.textContent = formatWeight(selected.dataset.weight);
        if (image && selected.dataset.image) image.src = selected.dataset.image;
        if (wishlistVariant) wishlistVariant.value = selected.value;
        if (quantity) {
            quantity.max = String(Math.max(1, available));
            if (Number(quantity.value) > available) quantity.value = String(Math.max(1, available));
        }
        if (button) button.disabled = available < 1;
        if (voucherButton) voucherButton.disabled = available < 1;
    }

    radios.forEach((radio) => radio.addEventListener('change', updateVariant));
    updateVariant();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
