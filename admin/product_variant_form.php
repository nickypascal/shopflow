<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$productId = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
$variantId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'variant_id', FILTER_VALIDATE_INT);
$isEdit = (bool) $variantId;

if (!$productId) {
    flash('warning', 'Produk tidak valid.');
    redirect('products.php');
}

$productStatement = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
$productStatement->execute(['id' => $productId]);
$product = $productStatement->fetch();
if (!$product || (int) $product['has_variants'] !== 1) {
    flash('warning', 'Produk tidak ditemukan atau belum menggunakan variasi.');
    redirect('products.php');
}

$variant = [
    'sku' => '',
    'variant_name' => '',
    'color' => '',
    'size' => '',
    'material' => '',
    'price' => (string) $product['price'],
    'stock' => 0,
    'low_stock_threshold' => (int) $product['low_stock_threshold'],
    'weight_grams' => (int) $product['default_weight_grams'],
    'image' => '',
    'is_active' => 1,
];

if ($isEdit) {
    $statement = $pdo->prepare('SELECT * FROM product_variants WHERE id = :id AND product_id = :product_id LIMIT 1');
    $statement->execute(['id' => $variantId, 'product_id' => $productId]);
    $found = $statement->fetch();
    if (!$found) {
        flash('warning', 'Variasi tidak ditemukan.');
        redirect('product_variants.php?product_id=' . $productId);
    }
    $variant = $found;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $variant['sku'] = strtoupper(trim((string) ($_POST['sku'] ?? '')));
    $variant['variant_name'] = trim((string) ($_POST['variant_name'] ?? ''));
    $variant['color'] = trim((string) ($_POST['color'] ?? ''));
    $variant['size'] = trim((string) ($_POST['size'] ?? ''));
    $variant['material'] = trim((string) ($_POST['material'] ?? ''));
    $variant['price'] = (float) ($_POST['price'] ?? 0);
    $variant['stock'] = max(0, (int) ($_POST['stock'] ?? 0));
    $variant['low_stock_threshold'] = max(0, (int) ($_POST['low_stock_threshold'] ?? 0));
    $variant['weight_grams'] = max(0, (int) ($_POST['weight_grams'] ?? 0));
    $variant['is_active'] = isset($_POST['is_active']) ? 1 : 0;

    if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{2,99}$/', (string) $variant['sku'])) {
        $errors[] = 'SKU harus berisi 3–100 karakter berupa huruf, angka, titik, garis bawah, atau tanda hubung.';
    }
    if (strlen((string) $variant['variant_name']) < 2 || strlen((string) $variant['variant_name']) > 150) {
        $errors[] = 'Nama variasi harus berisi 2–150 karakter.';
    }
    if ((float) $variant['price'] <= 0) {
        $errors[] = 'Harga variasi harus lebih dari nol.';
    }
    if ((int) $variant['weight_grams'] <= 0) {
        $errors[] = 'Berat variasi harus lebih dari 0 gram.';
    }
    foreach (['color' => 'Warna', 'size' => 'Ukuran', 'material' => 'Bahan'] as $key => $label) {
        if (strlen((string) $variant[$key]) > 100) {
            $errors[] = $label . ' maksimal 100 karakter.';
        }
    }

    $skuStatement = $pdo->prepare('SELECT id FROM product_variants WHERE sku = :sku AND id <> :id LIMIT 1');
    $skuStatement->execute(['sku' => $variant['sku'], 'id' => (int) $variantId]);
    if ($skuStatement->fetch()) {
        $errors[] = 'SKU sudah digunakan variasi lain.';
    }

    $newImagePath = (string) ($variant['image'] ?? '');
    if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $uploaded = save_uploaded_image($_FILES['image'], 'products', 'variant-' . $variant['sku']);
            if ($uploaded !== null) {
                $newImagePath = $uploaded;
            }
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            if ($isEdit) {
                $lock = $pdo->prepare('SELECT stock, price FROM product_variants WHERE id = :id AND product_id = :product_id FOR UPDATE');
                $lock->execute(['id' => $variantId, 'product_id' => $productId]);
                $oldVariant = $lock->fetch();
                if (!$oldVariant) {
                    throw new RuntimeException('Variasi tidak ditemukan.');
                }

                $statement = $pdo->prepare(
                    'UPDATE product_variants SET
                        sku = :sku, variant_name = :variant_name, color = :color,
                        size = :size, material = :material, price = :price,
                        stock = :stock, low_stock_threshold = :threshold,
                        weight_grams = :weight, image = :image, is_active = :is_active,
                        updated_at = NOW()
                     WHERE id = :id AND product_id = :product_id'
                );
                $statement->execute([
                    'sku' => $variant['sku'],
                    'variant_name' => $variant['variant_name'],
                    'color' => $variant['color'] ?: null,
                    'size' => $variant['size'] ?: null,
                    'material' => $variant['material'] ?: null,
                    'price' => $variant['price'],
                    'stock' => $variant['stock'],
                    'threshold' => $variant['low_stock_threshold'],
                    'weight' => $variant['weight_grams'],
                    'image' => $newImagePath ?: null,
                    'is_active' => $variant['is_active'],
                    'id' => $variantId,
                    'product_id' => $productId,
                ]);

                if ((int) $oldVariant['stock'] !== (int) $variant['stock']) {
                    record_stock_history(
                        $pdo,
                        $productId,
                        'adjustment',
                        abs((int) $variant['stock'] - (int) $oldVariant['stock']),
                        (int) $oldVariant['stock'],
                        (int) $variant['stock'],
                        'variant_edit',
                        (int) $variantId,
                        'Stok variasi diubah melalui formulir.',
                        (int) $adminUser['id'],
                        (int) $variantId
                    );
                }

                notify_wishlist_price_drop(
                    $pdo,
                    $productId,
                    (string) $product['name'],
                    (float) $oldVariant['price'],
                    (float) $variant['price'],
                    (int) $variantId,
                    (string) $variant['variant_name']
                );
                if ((int) $oldVariant['stock'] === 0 && (int) $variant['stock'] > 0) {
                    notify_wishlist_restock(
                        $pdo,
                        $productId,
                        (string) $product['name'],
                        (int) $variantId,
                        (string) $variant['variant_name']
                    );
                }
                if ((int) $oldVariant['stock'] > (int) $variant['low_stock_threshold']
                    && (int) $variant['stock'] <= (int) $variant['low_stock_threshold']) {
                    create_notification(
                        $pdo,
                        'Stok variasi menipis',
                        (string) $product['name'] . ' — ' . (string) $variant['variant_name'] . ' tersisa ' . (int) $variant['stock'] . ' unit.',
                        'stock.php?product_id=' . $productId . '&variant_id=' . (int) $variantId,
                        'stock',
                        'admin',
                        null,
                        'variant-low-stock-' . (int) $variantId . '-' . (int) $variant['stock'],
                        'variant',
                        (int) $variantId
                    );
                }

                log_admin_activity($pdo, 'variant_update', 'Memperbarui variasi ' . $variant['variant_name'] . ' pada produk ' . $product['name']);
                $message = 'Variasi berhasil diperbarui.';
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO product_variants (
                        product_id, sku, variant_name, color, size, material,
                        price, stock, low_stock_threshold, weight_grams, image, is_active
                     ) VALUES (
                        :product_id, :sku, :variant_name, :color, :size, :material,
                        :price, :stock, :threshold, :weight, :image, :is_active
                     )'
                );
                $statement->execute([
                    'product_id' => $productId,
                    'sku' => $variant['sku'],
                    'variant_name' => $variant['variant_name'],
                    'color' => $variant['color'] ?: null,
                    'size' => $variant['size'] ?: null,
                    'material' => $variant['material'] ?: null,
                    'price' => $variant['price'],
                    'stock' => $variant['stock'],
                    'threshold' => $variant['low_stock_threshold'],
                    'weight' => $variant['weight_grams'],
                    'image' => $newImagePath ?: null,
                    'is_active' => $variant['is_active'],
                ]);
                $variantId = (int) $pdo->lastInsertId();

                if ((int) $variant['stock'] > 0) {
                    record_stock_history(
                        $pdo,
                        $productId,
                        'initial',
                        (int) $variant['stock'],
                        0,
                        (int) $variant['stock'],
                        'variant_create',
                        $variantId,
                        'Stok awal variasi.',
                        (int) $adminUser['id'],
                        $variantId
                    );
                }

                log_admin_activity($pdo, 'variant_create', 'Menambahkan variasi ' . $variant['variant_name'] . ' pada produk ' . $product['name']);
                $message = 'Variasi baru berhasil ditambahkan.';
            }

            sync_product_variant_summary($pdo, $productId);
            $pdo->commit();
            flash('success', $message);
            redirect('product_variants.php?product_id=' . $productId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Variasi gagal disimpan: ' . $exception->getMessage();
        }
    }

    $variant['image'] = $newImagePath;
}

$pageTitle = $isEdit ? 'Edit Variasi' : 'Tambah Variasi';
$activeMenu = 'products';
require __DIR__ . '/includes/admin_header.php';
$previewImage = trim((string) ($variant['image'] ?? '')) !== '' ? (string) $variant['image'] : (string) $product['image'];
?>
<div class="admin-page-head">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p><?= e((string) $product['name']) ?> — setiap kombinasi pilihan dibuat sebagai satu variasi.</p>
    </div>
    <a class="admin-button admin-button-secondary" href="product_variants.php?product_id=<?= (int) $productId ?>">← Kembali</a>
</div>

<?php if ($errors): ?>
    <ul class="admin-error-list"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<form class="admin-card admin-form-stack" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="product_id" value="<?= (int) $productId ?>">
    <?php if ($isEdit): ?><input type="hidden" name="variant_id" value="<?= (int) $variantId ?>"><?php endif; ?>

    <div class="admin-image-editor">
        <img class="admin-image-preview" data-image-preview src="../<?= e($previewImage) ?>" alt="Pratinjau variasi">
        <label class="admin-field">
            <span>Foto khusus variasi</span>
            <input class="admin-input" data-image-input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
            <small>Opsional. Jika kosong, foto utama produk digunakan.</small>
        </label>
    </div>

    <div class="admin-form-grid">
        <label class="admin-field"><span>Nama variasi</span><input class="admin-input" type="text" name="variant_name" maxlength="150" value="<?= e((string) $variant['variant_name']) ?>" placeholder="Contoh: Hitam / XL" required></label>
        <label class="admin-field"><span>SKU unik</span><input class="admin-input" type="text" name="sku" maxlength="100" value="<?= e((string) $variant['sku']) ?>" placeholder="Contoh: KPS-HIT-XL" required></label>
        <label class="admin-field"><span>Warna</span><input class="admin-input" type="text" name="color" maxlength="100" value="<?= e((string) $variant['color']) ?>" placeholder="Contoh: Hitam"></label>
        <label class="admin-field"><span>Ukuran</span><input class="admin-input" type="text" name="size" maxlength="100" value="<?= e((string) $variant['size']) ?>" placeholder="Contoh: XL atau 42"></label>
        <label class="admin-field"><span>Bahan</span><input class="admin-input" type="text" name="material" maxlength="100" value="<?= e((string) $variant['material']) ?>" placeholder="Contoh: Katun"></label>
        <label class="admin-field"><span>Harga (Rupiah)</span><input class="admin-input" type="number" name="price" min="1" step="1" value="<?= e((string) $variant['price']) ?>" required></label>
        <label class="admin-field"><span>Stok</span><input class="admin-input" type="number" name="stock" min="0" value="<?= (int) $variant['stock'] ?>" required></label>
        <label class="admin-field"><span>Batas stok menipis</span><input class="admin-input" type="number" name="low_stock_threshold" min="0" value="<?= (int) $variant['low_stock_threshold'] ?>" required></label>
        <label class="admin-field"><span>Berat (gram)</span><input class="admin-input" type="number" name="weight_grams" min="1" value="<?= (int) $variant['weight_grams'] ?>" required></label>
        <div class="admin-field"><span>Status</span><div class="admin-checkbox-row"><label><input type="checkbox" name="is_active" value="1" <?= (int) $variant['is_active'] === 1 ? 'checked' : '' ?>> Variasi aktif dan dapat dibeli</label></div></div>
    </div>

    <div class="admin-form-actions">
        <button class="admin-button admin-button-primary" type="submit"><?= $isEdit ? 'Simpan Perubahan' : 'Tambah Variasi' ?></button>
        <a class="admin-button admin-button-secondary" href="product_variants.php?product_id=<?= (int) $productId ?>">Batal</a>
    </div>
</form>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
