<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $inventoryType = trim((string) ($_POST['inventory_type'] ?? 'product'));
    $inventoryId = filter_input(INPUT_POST, 'inventory_id', FILTER_VALIDATE_INT);
    $mode = trim((string) ($_POST['mode'] ?? 'add'));
    $quantity = max(1, (int) ($_POST['quantity'] ?? 0));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if (!$inventoryId || !in_array($inventoryType, ['product', 'variant'], true) || !in_array($mode, ['add', 'subtract', 'set'], true)) {
        flash('danger', 'Data penyesuaian stok tidak valid.');
        redirect('stock.php');
    }

    try {
        $pdo->beginTransaction();

        if ($inventoryType === 'variant') {
            $statement = $pdo->prepare(
                'SELECT v.id, v.product_id, v.variant_name, v.stock, v.low_stock_threshold, p.name AS product_name
                 FROM product_variants v
                 INNER JOIN products p ON p.id = v.product_id
                 WHERE v.id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $inventoryId]);
            $inventory = $statement->fetch();
            if (!$inventory) {
                throw new RuntimeException('Variasi produk tidak ditemukan.');
            }

            $productId = (int) $inventory['product_id'];
            $variantId = (int) $inventory['id'];
            $inventoryName = (string) $inventory['product_name'] . ' — ' . (string) $inventory['variant_name'];
            $before = (int) $inventory['stock'];
            $threshold = (int) $inventory['low_stock_threshold'];
            $after = match ($mode) {
                'add' => $before + $quantity,
                'subtract' => $before - $quantity,
                'set' => $quantity,
            };
            if ($after < 0) {
                throw new RuntimeException('Stok tidak boleh menjadi negatif.');
            }

            $pdo->prepare('UPDATE product_variants SET stock = :stock, updated_at = NOW() WHERE id = :id')
                ->execute(['stock' => $after, 'id' => $variantId]);
            sync_product_variant_summary($pdo, $productId);
        } else {
            $statement = $pdo->prepare(
                'SELECT id, name, stock, low_stock_threshold, has_variants
                 FROM products WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $inventoryId]);
            $inventory = $statement->fetch();
            if (!$inventory) {
                throw new RuntimeException('Produk tidak ditemukan.');
            }
            if ((int) $inventory['has_variants'] === 1) {
                throw new RuntimeException('Stok produk ini dikelola per variasi. Pilih salah satu variasi.');
            }

            $productId = (int) $inventory['id'];
            $variantId = null;
            $inventoryName = (string) $inventory['name'];
            $before = (int) $inventory['stock'];
            $threshold = (int) $inventory['low_stock_threshold'];
            $after = match ($mode) {
                'add' => $before + $quantity,
                'subtract' => $before - $quantity,
                'set' => $quantity,
            };
            if ($after < 0) {
                throw new RuntimeException('Stok tidak boleh menjadi negatif.');
            }

            $pdo->prepare('UPDATE products SET stock = :stock, updated_at = NOW() WHERE id = :id')
                ->execute(['stock' => $after, 'id' => $productId]);
        }

        record_stock_history(
            $pdo,
            $productId,
            $mode === 'add' ? 'stock_in' : ($mode === 'subtract' ? 'stock_out' : 'adjustment'),
            abs($after - $before),
            $before,
            $after,
            'manual_adjustment',
            null,
            $notes ?: 'Penyesuaian stok manual.',
            (int) $adminUser['id'],
            $variantId
        );
        if ($before === 0 && $after > 0) {
            notify_wishlist_restock(
                $pdo,
                $productId,
                $inventoryType === 'variant' ? (string) $inventory['product_name'] : (string) $inventory['name'],
                $variantId,
                $inventoryType === 'variant' ? (string) $inventory['variant_name'] : null
            );
        }
        if ($before > $threshold && $after <= $threshold) {
            create_notification(
                $pdo,
                $variantId ? 'Stok variasi menipis' : 'Stok produk menipis',
                $inventoryName . ' tersisa ' . $after . ' unit.',
                'stock.php?product_id=' . $productId . ($variantId ? '&variant_id=' . $variantId : ''),
                'stock',
                'admin',
                null,
                'manual-low-stock-' . $productId . '-' . ($variantId ?: 0) . '-' . $after,
                $variantId ? 'variant' : 'product',
                $variantId ?: $productId
            );
        }

        log_admin_activity($pdo, 'stock_adjustment', 'Mengubah stok ' . $inventoryName . ' dari ' . $before . ' menjadi ' . $after);

        $pdo->commit();
        flash('success', 'Stok berhasil diperbarui.');
        $redirect = 'stock.php?product_id=' . $productId;
        if ($variantId) {
            $redirect .= '&variant_id=' . $variantId;
        }
        redirect($redirect);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash($exception instanceof RuntimeException ? 'warning' : 'danger', $exception->getMessage());
        redirect('stock.php');
    }
}

$selectedProductId = max(0, (int) ($_GET['product_id'] ?? 0));
$selectedVariantId = max(0, (int) ($_GET['variant_id'] ?? 0));

$productInventories = $pdo->query(
    "SELECT p.id, p.name, p.stock, p.low_stock_threshold, p.image, p.status,
            p.default_weight_grams
     FROM products p
     WHERE p.has_variants = 0
     ORDER BY p.stock ASC, p.name ASC"
)->fetchAll();

$variantInventories = $pdo->query(
    "SELECT v.id, v.product_id, v.variant_name, v.sku, v.stock, v.low_stock_threshold,
            v.weight_grams, COALESCE(v.image, p.image) AS image, v.is_active,
            p.name AS product_name
     FROM product_variants v
     INNER JOIN products p ON p.id = v.product_id
     ORDER BY v.stock ASC, p.name, v.variant_name"
)->fetchAll();

$params = [];
$where = [];
if ($selectedVariantId > 0) {
    $where[] = 'h.variant_id = :variant_id';
    $params['variant_id'] = $selectedVariantId;
} elseif ($selectedProductId > 0) {
    $where[] = 'h.product_id = :product_id';
    $params['product_id'] = $selectedProductId;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$historyStatement = $pdo->prepare(
    "SELECT h.*, p.name AS product_name, v.variant_name, v.sku AS variant_sku,
            u.name AS admin_name
     FROM stock_histories h
     INNER JOIN products p ON p.id = h.product_id
     LEFT JOIN product_variants v ON v.id = h.variant_id
     LEFT JOIN users u ON u.id = h.created_by
     {$whereSql}
     ORDER BY h.id DESC LIMIT 150"
);
$historyStatement->execute($params);
$histories = $historyStatement->fetchAll();

$pageTitle = 'Manajemen Stok';
$activeMenu = 'stock';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head">
    <div>
        <h1>Manajemen Stok</h1>
        <p>Kelola stok produk biasa dan setiap variasi secara terpisah.</p>
    </div>
</div>

<div class="admin-grid admin-grid-2" style="align-items:start">
    <section class="admin-card">
        <div class="admin-card-head"><h2>Penyesuaian Stok</h2></div>
        <form class="admin-form-stack" method="post" id="stock-adjustment-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="inventory_type" id="inventory-type" value="product">
            <input type="hidden" name="inventory_id" id="inventory-id" value="">

            <label class="admin-field">
                <span>Produk atau variasi</span>
                <select class="admin-select" id="inventory-selector" required>
                    <option value="">Pilih stok yang akan diubah</option>
                    <?php if ($productInventories): ?><optgroup label="Produk tanpa variasi"><?php endif; ?>
                    <?php foreach ($productInventories as $product): ?>
                        <option
                            value="product:<?= (int) $product['id'] ?>"
                            <?= $selectedProductId === (int) $product['id'] && $selectedVariantId === 0 ? 'selected' : '' ?>
                        ><?= e((string) $product['name']) ?> — stok <?= (int) $product['stock'] ?></option>
                    <?php endforeach; ?>
                    <?php if ($productInventories): ?></optgroup><?php endif; ?>

                    <?php if ($variantInventories): ?><optgroup label="Variasi produk"><?php endif; ?>
                    <?php foreach ($variantInventories as $variant): ?>
                        <option
                            value="variant:<?= (int) $variant['id'] ?>"
                            <?= $selectedVariantId === (int) $variant['id'] ? 'selected' : '' ?>
                        ><?= e((string) $variant['product_name']) ?> — <?= e((string) $variant['variant_name']) ?> (<?= e((string) $variant['sku']) ?>) — stok <?= (int) $variant['stock'] ?></option>
                    <?php endforeach; ?>
                    <?php if ($variantInventories): ?></optgroup><?php endif; ?>
                </select>
            </label>

            <div class="admin-form-grid">
                <label class="admin-field">
                    <span>Tindakan</span>
                    <select class="admin-select" name="mode">
                        <option value="add">Tambah stok</option>
                        <option value="subtract">Kurangi stok</option>
                        <option value="set">Tetapkan jumlah stok</option>
                    </select>
                </label>
                <label class="admin-field">
                    <span>Jumlah</span>
                    <input class="admin-input" type="number" name="quantity" min="1" value="1" required>
                </label>
            </div>

            <label class="admin-field">
                <span>Catatan</span>
                <textarea class="admin-textarea" name="notes" maxlength="255" placeholder="Contoh: Restok dari pemasok"></textarea>
            </label>
            <button class="admin-button admin-button-primary" type="submit">Simpan Penyesuaian</button>
        </form>
    </section>

    <section class="admin-card">
        <div class="admin-card-head"><h2>Stok Terendah</h2><a href="products.php">Kelola produk</a></div>
        <div class="admin-list">
            <?php
            $lowest = [];
            foreach ($productInventories as $product) {
                $lowest[] = [
                    'name' => (string) $product['name'],
                    'sub' => 'Produk tanpa variasi',
                    'stock' => (int) $product['stock'],
                    'threshold' => (int) $product['low_stock_threshold'],
                    'url' => 'stock.php?product_id=' . (int) $product['id'],
                    'image' => (string) $product['image'],
                ];
            }
            foreach ($variantInventories as $variant) {
                $lowest[] = [
                    'name' => (string) $variant['product_name'] . ' — ' . (string) $variant['variant_name'],
                    'sub' => 'SKU ' . (string) $variant['sku'],
                    'stock' => (int) $variant['stock'],
                    'threshold' => (int) $variant['low_stock_threshold'],
                    'url' => 'stock.php?product_id=' . (int) $variant['product_id'] . '&variant_id=' . (int) $variant['id'],
                    'image' => (string) $variant['image'],
                ];
            }
            usort($lowest, static fn (array $a, array $b): int => $a['stock'] <=> $b['stock']);
            ?>
            <?php foreach (array_slice($lowest, 0, 10) as $inventory): ?>
                <a class="admin-list-item" href="<?= e($inventory['url']) ?>">
                    <div class="admin-product-cell">
                        <img src="../<?= e($inventory['image']) ?>" alt="">
                        <div><strong><?= e($inventory['name']) ?></strong><small><?= e($inventory['sub']) ?> • batas <?= (int) $inventory['threshold'] ?></small></div>
                    </div>
                    <strong class="<?= $inventory['stock'] === 0 ? 'admin-stock-empty' : ($inventory['stock'] <= $inventory['threshold'] ? 'admin-stock-low' : 'admin-stock-ok') ?>"><?= (int) $inventory['stock'] ?></strong>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<section class="admin-card" style="margin-top:18px">
    <div class="admin-card-head">
        <h2>Riwayat Stok</h2>
        <?php if ($selectedProductId || $selectedVariantId): ?><a href="stock.php">Tampilkan semua</a><?php endif; ?>
    </div>
    <?php if ($histories === []): ?>
        <div class="admin-empty"><strong>Belum ada riwayat</strong><p>Perubahan stok akan dicatat di sini.</p></div>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th>Waktu</th><th>Produk/Variasi</th><th>Tipe</th><th>Jumlah</th><th>Sebelum</th><th>Sesudah</th><th>Catatan</th><th>Admin</th></tr></thead>
                <tbody>
                    <?php foreach ($histories as $history): ?>
                        <tr>
                            <td><?= format_datetime((string) $history['created_at']) ?></td>
                            <td><strong><?= e((string) $history['product_name']) ?></strong><?php if ($history['variant_name']): ?><br><small><?= e((string) $history['variant_name']) ?> • <?= e((string) $history['variant_sku']) ?></small><?php endif; ?></td>
                            <td><span class="admin-badge status-neutral"><?= e((string) $history['type']) ?></span></td>
                            <td><?= (int) $history['quantity'] ?></td>
                            <td><?= (int) $history['stock_before'] ?></td>
                            <td><strong><?= (int) $history['stock_after'] ?></strong></td>
                            <td><?= e((string) ($history['notes'] ?: '-')) ?></td>
                            <td><?= e((string) ($history['admin_name'] ?: 'Sistem')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<script>
(() => {
    const selector = document.getElementById('inventory-selector');
    const typeInput = document.getElementById('inventory-type');
    const idInput = document.getElementById('inventory-id');
    const form = document.getElementById('stock-adjustment-form');

    function syncSelection() {
        const [type, id] = String(selector?.value || '').split(':');
        if (typeInput) typeInput.value = type || '';
        if (idInput) idInput.value = id || '';
    }

    selector?.addEventListener('change', syncSelection);
    form?.addEventListener('submit', (event) => {
        syncSelection();
        if (!idInput?.value) {
            event.preventDefault();
            selector?.focus();
        }
    });
    syncSelection();
})();
</script>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
