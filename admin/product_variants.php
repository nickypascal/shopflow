<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$productId = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT);
if (!$productId) {
    flash('warning', 'Produk tidak valid.');
    redirect('products.php');
}

$productStatement = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
$productStatement->execute(['id' => $productId]);
$product = $productStatement->fetch();
if (!$product) {
    flash('warning', 'Produk tidak ditemukan.');
    redirect('products.php');
}

if ((int) $product['has_variants'] !== 1) {
    flash('warning', 'Aktifkan opsi produk memiliki variasi terlebih dahulu.');
    redirect('product_form.php?id=' . $productId);
}

$statement = $pdo->prepare(
    'SELECT v.*,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.variant_id = v.id) AS order_usage
     FROM product_variants v
     WHERE v.product_id = :product_id
     ORDER BY v.is_active DESC, v.color, v.size, v.variant_name, v.id'
);
$statement->execute(['product_id' => $productId]);
$variants = $statement->fetchAll();

$summary = $pdo->prepare(
    'SELECT COUNT(*) AS total_variants,
            SUM(is_active = 1) AS active_variants,
            COALESCE(SUM(CASE WHEN is_active = 1 THEN stock ELSE 0 END), 0) AS total_stock,
            MIN(CASE WHEN is_active = 1 THEN price END) AS minimum_price,
            MAX(CASE WHEN is_active = 1 THEN price END) AS maximum_price
     FROM product_variants WHERE product_id = :product_id'
);
$summary->execute(['product_id' => $productId]);
$variantSummary = $summary->fetch() ?: [];

$pageTitle = 'Variasi ' . (string) $product['name'];
$activeMenu = 'products';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head">
    <div>
        <h1>Variasi Produk</h1>
        <p>Kelola SKU, atribut, harga, stok, berat, dan foto untuk <?= e((string) $product['name']) ?>.</p>
    </div>
    <div class="admin-page-actions">
        <a class="admin-button admin-button-secondary" href="products.php">← Produk</a>
        <a class="admin-button admin-button-primary" href="product_variant_form.php?product_id=<?= (int) $productId ?>">+ Tambah Variasi</a>
    </div>
</div>

<section class="admin-card admin-variant-product-head">
    <div class="admin-product-cell">
        <img src="../<?= e((string) $product['image']) ?>" alt="">
        <div>
            <strong><?= e((string) $product['name']) ?></strong>
            <small><?= e((string) $product['category']) ?> • Berat default <?= e(weight_label((int) $product['default_weight_grams'])) ?></small>
        </div>
    </div>
    <a class="admin-button admin-button-secondary admin-button-small" href="product_form.php?id=<?= (int) $productId ?>">Edit Produk Utama</a>
</section>

<section class="admin-grid admin-grid-4" style="margin:18px 0">
    <article class="admin-stat-card"><div class="admin-stat-copy"><span>Total variasi</span><strong><?= (int) ($variantSummary['total_variants'] ?? 0) ?></strong></div></article>
    <article class="admin-stat-card"><div class="admin-stat-copy"><span>Variasi aktif</span><strong><?= (int) ($variantSummary['active_variants'] ?? 0) ?></strong></div></article>
    <article class="admin-stat-card"><div class="admin-stat-copy"><span>Total stok aktif</span><strong><?= (int) ($variantSummary['total_stock'] ?? 0) ?></strong></div></article>
    <article class="admin-stat-card"><div class="admin-stat-copy"><span>Rentang harga</span><strong><?= $variantSummary['minimum_price'] !== null ? rupiah($variantSummary['minimum_price']) : '-' ?></strong><small><?= $variantSummary['maximum_price'] !== null && (float) $variantSummary['maximum_price'] !== (float) $variantSummary['minimum_price'] ? 's.d. ' . rupiah($variantSummary['maximum_price']) : '' ?></small></div></article>
</section>

<?php if ($variants === []): ?>
    <div class="admin-empty">
        <strong>Belum ada variasi</strong>
        <p>Tambahkan minimal satu variasi agar pelanggan dapat membeli produk ini.</p>
        <a class="admin-button admin-button-primary" href="product_variant_form.php?product_id=<?= (int) $productId ?>">Tambah Variasi Pertama</a>
    </div>
<?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Variasi</th>
                    <th>SKU</th>
                    <th>Atribut</th>
                    <th>Harga</th>
                    <th>Stok</th>
                    <th>Berat</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($variants as $variant): ?>
                    <?php
                    $stockClass = (int) $variant['stock'] === 0
                        ? 'admin-stock-empty'
                        : ((int) $variant['stock'] <= (int) $variant['low_stock_threshold'] ? 'admin-stock-low' : 'admin-stock-ok');
                    $variantImage = trim((string) ($variant['image'] ?? '')) !== '' ? (string) $variant['image'] : (string) $product['image'];
                    ?>
                    <tr>
                        <td>
                            <div class="admin-product-cell">
                                <img src="../<?= e($variantImage) ?>" alt="">
                                <div>
                                    <strong><?= e((string) $variant['variant_name']) ?></strong>
                                    <small>#<?= (int) $variant['id'] ?><?= (int) $variant['order_usage'] > 0 ? ' • pernah dipesan' : '' ?></small>
                                </div>
                            </div>
                        </td>
                        <td><code><?= e((string) $variant['sku']) ?></code></td>
                        <td>
                            <div class="admin-variant-attributes">
                                <?php if ($variant['color']): ?><span>Warna: <?= e((string) $variant['color']) ?></span><?php endif; ?>
                                <?php if ($variant['size']): ?><span>Ukuran: <?= e((string) $variant['size']) ?></span><?php endif; ?>
                                <?php if ($variant['material']): ?><span>Bahan: <?= e((string) $variant['material']) ?></span><?php endif; ?>
                            </div>
                        </td>
                        <td><strong><?= rupiah($variant['price']) ?></strong></td>
                        <td><strong class="<?= $stockClass ?>"><?= (int) $variant['stock'] ?> unit</strong><br><small>Batas <?= (int) $variant['low_stock_threshold'] ?></small></td>
                        <td><?= e(weight_label((int) $variant['weight_grams'])) ?></td>
                        <td><span class="admin-badge <?= (int) $variant['is_active'] === 1 ? 'status-complete' : 'status-neutral' ?>"><?= (int) $variant['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td>
                            <div class="admin-table-actions">
                                <a class="admin-button admin-button-primary admin-button-small" href="product_variant_form.php?product_id=<?= (int) $productId ?>&id=<?= (int) $variant['id'] ?>">Edit</a>
                                <a class="admin-button admin-button-secondary admin-button-small" href="stock.php?product_id=<?= (int) $productId ?>&variant_id=<?= (int) $variant['id'] ?>">Stok</a>
                                <form method="post" action="product_variant_action.php">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="product_id" value="<?= (int) $productId ?>">
                                    <input type="hidden" name="variant_id" value="<?= (int) $variant['id'] ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <button class="admin-button admin-button-<?= (int) $variant['is_active'] === 1 ? 'warning' : 'success' ?> admin-button-small" type="submit" data-confirm="Ubah status variasi ini?"><?= (int) $variant['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                                </form>
                                <form method="post" action="product_variant_action.php">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="product_id" value="<?= (int) $productId ?>">
                                    <input type="hidden" name="variant_id" value="<?= (int) $variant['id'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button class="admin-button admin-button-danger admin-button-small" type="submit" data-confirm="Hapus variasi ini? Variasi yang pernah dipesan hanya dapat dinonaktifkan.">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
