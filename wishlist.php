<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$userId = customer_id();
$statement = $pdo->prepare(
    "SELECT wi.id AS wishlist_id, wi.product_id, wi.preferred_variant_id,
            wi.price_when_added, wi.created_at AS wishlist_created_at,
            p.id AS id, p.name, p.slug, p.description, p.category, p.category_id, p.price,
            p.stock, p.low_stock_threshold, p.image, p.status, p.has_variants,
            p.default_weight_grams,
            v.id AS selected_variant_id, v.variant_name, v.sku AS variant_sku,
            v.color AS variant_color, v.size AS variant_size,
            v.material AS variant_material, v.price AS variant_price,
            v.stock AS variant_stock, v.low_stock_threshold AS variant_low_stock_threshold,
            v.weight_grams AS variant_weight_grams, v.image AS variant_image,
            v.is_active AS variant_active,
            (SELECT MIN(pv.price) FROM product_variants pv
             WHERE pv.product_id = p.id AND pv.is_active = 1) AS minimum_variant_price,
            (SELECT COALESCE(SUM(pv.stock), 0) FROM product_variants pv
             WHERE pv.product_id = p.id AND pv.is_active = 1) AS total_variant_stock,
            (SELECT COUNT(*) FROM product_variants pv
             WHERE pv.product_id = p.id AND pv.is_active = 1) AS active_variant_count
     FROM wishlist_items wi
     INNER JOIN products p ON p.id = wi.product_id
     LEFT JOIN product_variants v ON v.id = wi.preferred_variant_id
     WHERE wi.user_id = :user_id
     ORDER BY wi.updated_at DESC, wi.id DESC"
);
$statement->execute(['user_id' => $userId]);
$items = $statement->fetchAll();

$productIds = array_values(array_unique(array_map(
    static fn (array $item): int => (int) $item['product_id'],
    array_filter($items, static fn (array $item): bool => (int) $item['has_variants'] === 1)
)));

$variantOptions = [];
if ($productIds !== []) {
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $variantStatement = $pdo->prepare(
        "SELECT id, product_id, variant_name, sku, color, size, material,
                price, stock, weight_grams, image, is_active
         FROM product_variants
         WHERE product_id IN ({$placeholders}) AND is_active = 1
         ORDER BY product_id, color, size, variant_name, id"
    );
    $variantStatement->execute($productIds);
    foreach ($variantStatement->fetchAll() as $variant) {
        $variantOptions[(int) $variant['product_id']][] = $variant;
    }
}

$promotionBadges = promotion_product_badges($pdo, $items);
$pageTitle = 'Wishlist Saya';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header wishlist-page-header">
    <div class="container page-header-row">
        <div>
            <span class="eyebrow">Produk favorit</span>
            <h1>Wishlist Saya</h1>
            <p>Simpan produk yang Anda sukai dan pantau perubahan harga serta stoknya.</p>
        </div>
        <div class="wishlist-summary-chip">♥ <?= count($items) ?> produk</div>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if ($items === []): ?>
            <div class="empty-state">
                <div class="empty-icon">♡</div>
                <h2>Wishlist masih kosong</h2>
                <p>Tekan ikon hati pada produk yang ingin Anda simpan.</p>
                <a class="button button-primary" href="index.php#produk">Cari Produk</a>
            </div>
        <?php else: ?>
            <div class="wishlist-grid">
                <?php foreach ($items as $item): ?>
                    <?php
                    $productId = (int) $item['product_id'];
                    $hasVariants = (int) $item['has_variants'] === 1;
                    $selectedVariantValid = $item['selected_variant_id'] !== null
                        && (int) ($item['variant_active'] ?? 0) === 1;
                    $currentPrice = $hasVariants
                        ? ($selectedVariantValid
                            ? (float) $item['variant_price']
                            : (float) ($item['minimum_variant_price'] ?? $item['price']))
                        : (float) $item['price'];
                    $currentStock = $hasVariants
                        ? ($selectedVariantValid
                            ? (int) $item['variant_stock']
                            : (int) $item['total_variant_stock'])
                        : (int) $item['stock'];
                    $stockThreshold = $hasVariants && $selectedVariantValid
                        ? (int) $item['variant_low_stock_threshold']
                        : (int) $item['low_stock_threshold'];
                    $weight = $hasVariants && $selectedVariantValid
                        ? (int) $item['variant_weight_grams']
                        : (int) $item['default_weight_grams'];
                    $image = $selectedVariantValid && trim((string) ($item['variant_image'] ?? '')) !== ''
                        ? (string) $item['variant_image']
                        : (string) $item['image'];
                    $productActive = (string) $item['status'] === 'active';
                    $needsVariant = $hasVariants && !$selectedVariantValid;
                    $canAdd = $productActive && !$needsVariant && $currentStock > 0;
                    $priceInfo = wishlist_price_message((float) ($item['price_when_added'] ?? 0), $currentPrice);
                    $promo = $promotionBadges[$productId] ?? null;

                    if (!$productActive) {
                        $availability = ['label' => 'Produk tidak aktif', 'class' => 'unavailable'];
                    } elseif ($needsVariant) {
                        $availability = ['label' => 'Pilih variasi', 'class' => 'warning'];
                    } elseif ($currentStock <= 0) {
                        $availability = ['label' => 'Stok habis', 'class' => 'unavailable'];
                    } elseif ($currentStock <= $stockThreshold) {
                        $availability = ['label' => 'Stok menipis: ' . $currentStock, 'class' => 'warning'];
                    } else {
                        $availability = ['label' => 'Tersedia: ' . $currentStock, 'class' => 'available'];
                    }
                    ?>
                    <article class="wishlist-card">
                        <a class="wishlist-card-media" href="product_detail.php?id=<?= $productId ?>">
                            <img src="<?= e($image) ?>" alt="<?= e((string) $item['name']) ?>">
                            <span class="category-badge"><?= category_icon((string) $item['category']) ?> <?= e((string) $item['category']) ?></span>
                            <?php if ($promo): ?><span class="product-promo-badge"><?= e((string) $promo['label']) ?></span><?php endif; ?>
                        </a>

                        <div class="wishlist-card-body">
                            <div class="wishlist-card-heading">
                                <div>
                                    <h2><a href="product_detail.php?id=<?= $productId ?>"><?= e((string) $item['name']) ?></a></h2>
                                    <span>Disimpan <?= format_datetime((string) $item['wishlist_created_at']) ?></span>
                                </div>
                                <form method="post" action="wishlist_action.php">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="product_id" value="<?= $productId ?>">
                                    <input type="hidden" name="return_to" value="wishlist.php">
                                    <button class="wishlist-remove-button" type="submit" aria-label="Hapus <?= e((string) $item['name']) ?> dari wishlist">×</button>
                                </form>
                            </div>

                            <div class="wishlist-price-row">
                                <strong><?= $hasVariants && !$selectedVariantValid ? 'Mulai ' : '' ?><?= rupiah($currentPrice) ?></strong>
                                <span class="wishlist-price-change is-<?= e($priceInfo['class']) ?>"><?= e($priceInfo['label']) ?></span>
                            </div>

                            <div class="wishlist-meta-row">
                                <span class="wishlist-stock is-<?= e($availability['class']) ?>"><?= e($availability['label']) ?></span>
                                <?php if ($weight > 0): ?><span>⚖ <?= e(weight_label($weight)) ?></span><?php endif; ?>
                            </div>

                            <?php if ($promo): ?>
                                <div class="wishlist-promo-note">
                                    <strong><?= e((string) $promo['name']) ?></strong>
                                    <span><?= (string) $promo['code'] !== '' ? 'Kode ' . e((string) $promo['code']) : 'Diterapkan otomatis saat checkout' ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($hasVariants): ?>
                                <form class="wishlist-variant-form" method="post" action="wishlist_action.php">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update_variant">
                                    <input type="hidden" name="product_id" value="<?= $productId ?>">
                                    <input type="hidden" name="return_to" value="wishlist.php">
                                    <label>
                                        <span>Variasi pilihan</span>
                                        <select name="variant_id" required>
                                            <option value="">Pilih variasi</option>
                                            <?php foreach ($variantOptions[$productId] ?? [] as $variant): ?>
                                                <option value="<?= (int) $variant['id'] ?>" <?= (int) ($item['preferred_variant_id'] ?? 0) === (int) $variant['id'] ? 'selected' : '' ?>>
                                                    <?= e((string) $variant['variant_name']) ?> — <?= rupiah($variant['price']) ?> — stok <?= (int) $variant['stock'] ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <button class="button button-secondary button-small" type="submit">Simpan Variasi</button>
                                </form>
                            <?php elseif ($item['selected_variant_id'] !== null): ?>
                                <div class="wishlist-variant-selected"><?= e((string) $item['variant_name']) ?></div>
                            <?php endif; ?>

                            <?php if ($selectedVariantValid): ?>
                                <div class="wishlist-variant-selected">
                                    <strong><?= e((string) $item['variant_name']) ?></strong>
                                    <span>SKU <?= e((string) $item['variant_sku']) ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="wishlist-actions">
                                <a class="button button-secondary button-small" href="product_detail.php?id=<?= $productId ?>">Lihat Detail</a>
                                <form method="post" action="cart_action.php">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id" value="<?= $productId ?>">
                                    <input type="hidden" name="variant_id" value="<?= $selectedVariantValid ? (int) $item['selected_variant_id'] : 0 ?>">
                                    <input type="hidden" name="quantity" value="1">
                                    <button class="button button-primary button-small" type="submit" <?= !$canAdd ? 'disabled' : '' ?>>+ Keranjang</button>
                                </form>
                                <?php if ($hasVariants && !$selectedVariantValid): ?>
                                    <a class="button button-voucher button-small" href="product_detail.php?id=<?= $productId ?>&purchase=voucher#purchase">Beli dengan Voucher</a>
                                <?php else: ?>
                                    <a class="button button-voucher button-small <?= !$canAdd ? 'is-disabled' : '' ?>" href="buy_with_voucher.php?product_id=<?= $productId ?>&variant_id=<?= $selectedVariantValid ? (int) $item['selected_variant_id'] : 0 ?>&quantity=1" <?= !$canAdd ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Beli dengan Voucher</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
