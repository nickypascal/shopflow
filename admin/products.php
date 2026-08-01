<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$search = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? 'Semua'));
$categoryId = (int) ($_GET['category_id'] ?? 0);
$stockFilter = trim((string) ($_GET['stock'] ?? 'Semua'));
$allowedStatuses = ['Semua','active','inactive','coming_soon'];
$allowedStock = ['Semua','ready','low','empty'];
if (!in_array($status, $allowedStatuses, true)) $status = 'Semua';
if (!in_array($stockFilter, $allowedStock, true)) $stockFilter = 'Semua';

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$where = ['1=1'];
$params = [];
if ($search !== '') { $where[] = '(p.name LIKE :search OR p.description LIKE :search OR p.category LIKE :search)'; $params['search'] = '%' . $search . '%'; }
if ($status !== 'Semua') { $where[] = 'p.status = :status'; $params['status'] = $status; }
if ($categoryId > 0) { $where[] = 'p.category_id = :category_id'; $params['category_id'] = $categoryId; }
if ($stockFilter === 'ready') $where[] = 'p.stock > p.low_stock_threshold';
if ($stockFilter === 'low') $where[] = 'p.stock > 0 AND p.stock <= p.low_stock_threshold';
if ($stockFilter === 'empty') $where[] = 'p.stock = 0';

$statement = $pdo->prepare(
    'SELECT p.*, c.name AS category_name,
            (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) AS variant_count
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY p.id DESC'
);
$statement->execute($params);
$products = $statement->fetchAll();

$summary = $pdo->query("SELECT COUNT(*) total, SUM(status='active') active_count, SUM(stock=0) empty_count, SUM(stock>0 AND stock<=low_stock_threshold) low_count FROM products")->fetch();

$pageTitle = 'Manajemen Produk';
$activeMenu = 'products';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head">
    <div><h1>Manajemen Produk</h1><p>Tambah, edit, nonaktifkan, dan pantau stok produk toko.</p></div>
    <div class="admin-page-actions"><a class="admin-button admin-button-primary" href="product_form.php">+ Tambah Produk</a></div>
</div>

<section class="admin-grid admin-grid-4" style="margin-bottom:18px">
    <article class="admin-stat-card"><div class="admin-stat-copy"><span>Total produk</span><strong><?= (int) ($summary['total'] ?? 0) ?></strong></div></article>
    <article class="admin-stat-card"><div class="admin-stat-copy"><span>Produk aktif</span><strong><?= (int) ($summary['active_count'] ?? 0) ?></strong></div></article>
    <article class="admin-stat-card"><div class="admin-stat-copy"><span>Stok menipis</span><strong><?= (int) ($summary['low_count'] ?? 0) ?></strong></div></article>
    <article class="admin-stat-card"><div class="admin-stat-copy"><span>Stok habis</span><strong><?= (int) ($summary['empty_count'] ?? 0) ?></strong></div></article>
</section>

<div class="admin-filter-card">
    <form class="admin-filter-form" method="get">
        <label class="admin-field"><span>Pencarian</span><input class="admin-input" type="search" name="q" value="<?= e($search) ?>" placeholder="Nama atau deskripsi produk"></label>
        <label class="admin-field"><span>Kategori</span><select class="admin-select" name="category_id"><option value="0">Semua kategori</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= e((string) $category['name']) ?></option><?php endforeach; ?></select></label>
        <label class="admin-field"><span>Status</span><select class="admin-select" name="status"><option value="Semua">Semua status</option><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Aktif</option><option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Nonaktif</option><option value="coming_soon" <?= $status === 'coming_soon' ? 'selected' : '' ?>>Segera tersedia</option></select></label>
        <label class="admin-field"><span>Stok</span><select class="admin-select" name="stock"><option value="Semua">Semua stok</option><option value="ready" <?= $stockFilter === 'ready' ? 'selected' : '' ?>>Aman</option><option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Menipis</option><option value="empty" <?= $stockFilter === 'empty' ? 'selected' : '' ?>>Habis</option></select></label>
        <button class="admin-button admin-button-primary" type="submit">Terapkan</button>
    </form>
</div>

<?php if ($products === []): ?>
    <div class="admin-empty"><strong>Produk tidak ditemukan</strong><p>Tambahkan produk baru atau ubah filter pencarian.</p><a class="admin-button admin-button-primary" href="product_form.php">Tambah Produk</a></div>
<?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Produk</th><th>Kategori</th><th>Harga</th><th>Stok</th><th>Tipe & Berat</th><th>Status</th><th>Unggulan</th><th>Diperbarui</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($products as $product): ?>
                <?php $stockClass = (int) $product['stock'] === 0 ? 'admin-stock-empty' : ((int) $product['stock'] <= (int) $product['low_stock_threshold'] ? 'admin-stock-low' : 'admin-stock-ok'); ?>
                <tr>
                    <td><div class="admin-product-cell"><img src="../<?= e((string) $product['image']) ?>" alt=""><div><strong><?= e((string) $product['name']) ?></strong><small>#<?= (int) $product['id'] ?> • <?= e((string) $product['slug']) ?></small></div></div></td>
                    <td><?= e((string) ($product['category_name'] ?: $product['category'])) ?></td>
                    <td><strong><?= rupiah($product['price']) ?></strong></td>
                    <td><strong class="<?= $stockClass ?>"><?= (int) $product['stock'] ?> unit</strong><br><small><?= (int) $product['has_variants'] === 1 ? 'Total stok variasi' : 'Batas ' . (int) $product['low_stock_threshold'] ?></small></td>
                    <td>
                        <?php if ((int) $product['has_variants'] === 1): ?>
                            <span class="admin-badge status-processing"><?= (int) $product['variant_count'] ?> variasi</span><br>
                            <small>Berat berbeda per variasi</small>
                        <?php else: ?>
                            <span class="admin-badge status-neutral">Tanpa variasi</span><br>
                            <small><?= e(weight_label((int) $product['default_weight_grams'])) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="admin-badge <?= $product['status'] === 'active' ? 'status-complete' : ($product['status'] === 'coming_soon' ? 'status-waiting' : 'status-neutral') ?>"><?= e($product['status'] === 'active' ? 'Aktif' : ($product['status'] === 'coming_soon' ? 'Segera tersedia' : 'Nonaktif')) ?></span></td>
                    <td><?= (int) $product['is_featured'] === 1 ? '⭐ Ya' : 'Tidak' ?></td>
                    <td><?= format_datetime((string) ($product['updated_at'] ?: $product['created_at'])) ?></td>
                    <td><div class="admin-table-actions"><a class="admin-button admin-button-primary admin-button-small" href="product_form.php?id=<?= (int) $product['id'] ?>">Edit</a><?php if ((int) $product['has_variants'] === 1): ?><a class="admin-button admin-button-success admin-button-small" href="product_variants.php?product_id=<?= (int) $product['id'] ?>">Variasi</a><?php endif; ?><a class="admin-button admin-button-secondary admin-button-small" href="stock.php?product_id=<?= (int) $product['id'] ?>">Stok</a><form method="post" action="product_action.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>"><input type="hidden" name="action" value="toggle_status"><button class="admin-button admin-button-<?= $product['status'] === 'active' ? 'warning' : 'success' ?> admin-button-small" type="submit" data-confirm="Ubah status produk ini?"><?= $product['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?></button></form><form method="post" action="product_action.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>"><input type="hidden" name="action" value="delete"><button class="admin-button admin-button-danger admin-button-small" type="submit" data-confirm="Hapus produk ini secara permanen? Hanya produk tanpa riwayat pesanan yang dapat dihapus.">Hapus</button></form></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
