<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$query = trim((string) ($_GET['q'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));

$sql = "SELECT p.id, p.name, p.slug, p.description, p.category, p.category_id, p.price, p.stock, p.image,\n               p.has_variants, p.default_weight_grams, p.average_rating, p.review_count,\n               (SELECT MIN(v.price) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS min_variant_price,\n               (SELECT MAX(v.price) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS max_variant_price,\n               (SELECT COALESCE(SUM(v.stock), 0) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS variant_stock,\n               (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS variant_count\n        FROM products p WHERE p.status = 'active'";
$params = [];

if ($query !== '') {
    $searchTerm = '%' . $query . '%';
    $sql .= ' AND (p.name LIKE :query_name OR p.description LIKE :query_description OR p.category LIKE :query_category)';
    $params['query_name'] = $searchTerm;
    $params['query_description'] = $searchTerm;
    $params['query_category'] = $searchTerm;
}

if ($category !== '') {
    $sql .= ' AND p.category = :category';
    $params['category'] = $category;
}

$sql .= ' ORDER BY p.id DESC';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$products = $statement->fetchAll();
$promotionBadges = promotion_product_badges($pdo, $products);
$wishlistMap = wishlist_product_map($pdo);

$categories = $pdo->query("SELECT DISTINCT category FROM products WHERE status = 'active' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$storeRating = $pdo->query("SELECT COALESCE(AVG(average_rating), 0) FROM products WHERE review_count > 0")->fetchColumn();
$storeRating = (float) $storeRating;

$pageTitle = 'Belanja Produk Terbaik';
require __DIR__ . '/includes/header.php';
?>
<section class="hero">
    <div class="container hero-grid">
        <div class="hero-copy">
            <span class="eyebrow">Platform belanja modern</span>
            <h1>Temukan produk favorit dengan lebih mudah.</h1>
            <p>Cari produk, masukkan ke keranjang, dan selesaikan checkout hanya dalam beberapa langkah.</p>
            <div class="hero-actions">
                <a class="button button-primary" href="#produk">Mulai Belanja</a>
                <a class="button button-secondary" href="cart.php">Lihat Keranjang</a>
            </div>
            <div class="stats-row">
                <div><strong>10K+</strong><span>Pengguna</span></div>
                <div><strong>5K+</strong><span>Produk</span></div>
                <div><strong><?= $storeRating > 0 ? number_format($storeRating, 1, ',', '.') : '-' ?></strong><span>Rating</span></div>
            </div>
        </div>
        <div class="hero-card">
            <div class="floating-card floating-one">🚚 Pengiriman cepat</div>
            <div class="hero-visual">
                <span>🛍️</span>
            </div>
            <div class="floating-card floating-two">✓ Checkout aman</div>
        </div>
    </div>
</section>

<section class="search-section">
    <div class="container">
        <form class="search-panel" method="get" action="index.php">
            <div class="search-input-wrap">
                <span>🔎</span>
                <input type="search" name="q" value="<?= e($query) ?>" placeholder="Cari nama, kategori, atau deskripsi produk...">
            </div>
            <select name="category" aria-label="Pilih kategori">
                <option value="">Semua kategori</option>
                <?php foreach ($categories as $item): ?>
                    <option value="<?= e($item) ?>" <?= $category === $item ? 'selected' : '' ?>><?= e($item) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="button button-primary" type="submit">Cari Produk</button>
        </form>
    </div>
</section>

<section class="section" id="produk">
    <div class="container">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Katalog ShopFlow</span>
                <h2><?= $query !== '' || $category !== '' ? 'Hasil Pencarian' : 'Produk Unggulan' ?></h2>
            </div>
            <span class="result-count"><?= count($products) ?> produk ditemukan</span>
        </div>

        <?php if ($products === []): ?>
            <div class="empty-state">
                <div class="empty-icon">🔍</div>
                <h3>Produk tidak ditemukan</h3>
                <p>Coba gunakan kata kunci atau kategori lain.</p>
                <a class="button button-primary" href="index.php">Tampilkan Semua</a>
            </div>
        <?php else: ?>
            <div class="product-grid">
                <?php foreach ($products as $product): ?>
                    <article class="product-card">
                        <div class="product-image">
                            <img src="<?= e($product['image']) ?>" alt="<?= e($product['name']) ?>">
                            <form class="product-wishlist-form" method="post" action="wishlist_action.php">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                <input type="hidden" name="variant_id" value="0">
                                <input type="hidden" name="return_to" value="index.php#produk">
                                <button class="product-wishlist-button <?= isset($wishlistMap[(int) $product['id']]) ? 'is-active' : '' ?>" type="submit" aria-label="<?= isset($wishlistMap[(int) $product['id']]) ? 'Hapus dari wishlist' : 'Tambah ke wishlist' ?>">
                                    <?= isset($wishlistMap[(int) $product['id']]) ? '♥' : '♡' ?>
                                </button>
                            </form>
                            <span class="category-badge"><?= category_icon($product['category']) ?> <?= e($product['category']) ?></span>
                            <?php if (isset($promotionBadges[(int) $product['id']])): ?><span class="product-promo-badge"><?= e((string) $promotionBadges[(int) $product['id']]['label']) ?></span><?php endif; ?>
                        </div>
                        <div class="product-body">
                            <div class="rating"><span class="rating-stars"><?= str_repeat('★', (int) round((float) $product['average_rating'])) ?><i><?= str_repeat('☆', 5 - (int) round((float) $product['average_rating'])) ?></i></span> <span><?= (int) $product['review_count'] > 0 ? number_format((float) $product['average_rating'], 1, ',', '.') . ' · ' . (int) $product['review_count'] . ' ulasan' : 'Belum ada ulasan' ?></span></div>
                            <h3><?= e($product['name']) ?></h3>
                            <p><?= e($product['description']) ?></p>
                            <?php
                            $hasVariants = (int) $product['has_variants'] === 1;
                            $displayStock = $hasVariants ? (int) $product['variant_stock'] : (int) $product['stock'];
                            $displayPrice = $hasVariants && $product['min_variant_price'] !== null
                                ? (float) $product['min_variant_price']
                                : (float) $product['price'];
                            ?>
                            <div class="product-meta-row">
                                <div class="stock <?= $displayStock > 0 ? 'available' : 'unavailable' ?>">
                                    <?= $displayStock > 0 ? '✓ Stok tersedia: ' . $displayStock : 'Stok habis' ?>
                                </div>
                                <?php if ($hasVariants): ?><span class="variant-mini-badge"><?= (int) $product['variant_count'] ?> variasi</span><?php endif; ?>
                            </div>
                            <div class="product-weight">⚖ <?= $hasVariants ? 'Berat sesuai variasi' : e(weight_label((int) $product['default_weight_grams'])) ?></div>
                            <div class="product-footer product-footer-voucher">
                                <strong><?= $hasVariants ? 'Mulai ' : '' ?><?= rupiah($displayPrice) ?></strong>
                                <div class="product-card-actions">
                                    <?php if ($hasVariants): ?>
                                        <a class="button button-primary button-small" href="product_detail.php?id=<?= (int) $product['id'] ?>#purchase">Pilih Variasi</a>
                                        <a class="button button-voucher button-small" href="product_detail.php?id=<?= (int) $product['id'] ?>&purchase=voucher#purchase">Beli dengan Voucher</a>
                                    <?php else: ?>
                                        <form method="post" action="cart_action.php">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="add">
                                            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                            <input type="hidden" name="variant_id" value="0">
                                            <button class="button button-primary button-small" type="submit" <?= $displayStock < 1 ? 'disabled' : '' ?>>+ Keranjang</button>
                                        </form>
                                        <a class="button button-voucher button-small <?= $displayStock < 1 ? 'is-disabled' : '' ?>" href="buy_with_voucher.php?product_id=<?= (int) $product['id'] ?>&variant_id=0&quantity=1" <?= $displayStock < 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Beli dengan Voucher</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a class="product-detail-link" href="product_detail.php?id=<?= (int) $product['id'] ?>">Lihat detail produk →</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="benefits section-soft">
    <div class="container benefit-grid">
        <div><span>✓</span><h3>Produk Berkualitas</h3><p>Produk pilihan dengan informasi stok yang jelas.</p></div>
        <div><span>⚡</span><h3>Belanja Mudah</h3><p>Pencarian dan keranjang dibuat sederhana.</p></div>
        <div><span>🔒</span><h3>Checkout Aman</h3><p>Transaksi diproses dengan validasi server.</p></div>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
