<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
$isEdit = (bool) $productId;
$categories = $pdo->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name")->fetchAll();
$product = [
    'name' => '',
    'slug' => '',
    'description' => '',
    'category_id' => '',
    'category' => '',
    'price' => '',
    'stock' => 0,
    'low_stock_threshold' => max(0, (int) setting($pdo, 'default_low_stock', '5')),
    'image' => 'assets/images/bag.svg',
    'status' => 'active',
    'is_featured' => 0,
    'has_variants' => 0,
    'default_weight_grams' => 500,
];

if ($isEdit) {
    $statement = $pdo->prepare('SELECT * FROM products WHERE id = :id');
    $statement->execute(['id' => $productId]);
    $found = $statement->fetch();
    if (!$found) {
        flash('warning', 'Produk tidak ditemukan.');
        redirect('products.php');
    }
    $product = $found;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $product['name'] = trim((string) ($_POST['name'] ?? ''));
    $product['slug'] = trim((string) ($_POST['slug'] ?? ''));
    $product['description'] = trim((string) ($_POST['description'] ?? ''));
    $product['category_id'] = (int) ($_POST['category_id'] ?? 0);
    $product['price'] = (float) ($_POST['price'] ?? 0);
    $product['stock'] = max(0, (int) ($_POST['stock'] ?? 0));
    $product['low_stock_threshold'] = max(0, (int) ($_POST['low_stock_threshold'] ?? 5));
    $product['default_weight_grams'] = max(0, (int) ($_POST['default_weight_grams'] ?? 0));
    $product['has_variants'] = isset($_POST['has_variants']) ? 1 : 0;
    $product['status'] = trim((string) ($_POST['status'] ?? 'active'));
    $product['is_featured'] = isset($_POST['is_featured']) ? 1 : 0;

    if (strlen($product['name']) < 3 || strlen($product['name']) > 150) {
        $errors[] = 'Nama produk harus berisi 3–150 karakter.';
    }
    if (strlen($product['description']) < 10) {
        $errors[] = 'Deskripsi produk minimal 10 karakter.';
    }
    if ((int) $product['category_id'] <= 0) {
        $errors[] = 'Pilih kategori produk.';
    }
    if ((float) $product['price'] <= 0) {
        $errors[] = 'Harga awal produk harus lebih dari nol.';
    }
    if ((int) $product['default_weight_grams'] <= 0) {
        $errors[] = 'Berat produk harus lebih dari 0 gram.';
    }
    if (!in_array($product['status'], ['active', 'inactive', 'coming_soon'], true)) {
        $errors[] = 'Status produk tidak valid.';
    }

    $categoryStatement = $pdo->prepare('SELECT name FROM categories WHERE id = :id');
    $categoryStatement->execute(['id' => $product['category_id']]);
    $categoryName = $categoryStatement->fetchColumn();
    if ($categoryName === false) {
        $errors[] = 'Kategori tidak ditemukan.';
    }
    $product['category'] = (string) ($categoryName ?: 'Lainnya');

    $slug = slugify($product['slug'] !== '' ? $product['slug'] : $product['name']);
    $slugStatement = $pdo->prepare('SELECT id FROM products WHERE slug = :slug AND id <> :id LIMIT 1');
    $slugStatement->execute(['slug' => $slug, 'id' => (int) $productId]);
    if ($slugStatement->fetch()) {
        $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }
    $product['slug'] = $slug;

    $newImagePath = (string) $product['image'];
    if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $uploaded = save_uploaded_image($_FILES['image'], 'products', 'product-' . $product['name']);
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
                $lock = $pdo->prepare('SELECT stock, has_variants, price FROM products WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $productId]);
                $old = $lock->fetch();
                if (!$old) {
                    throw new RuntimeException('Produk tidak ditemukan.');
                }

                $oldStock = (int) $old['stock'];
                $oldPrice = (float) $old['price'];
                $oldHasVariants = (int) $old['has_variants'] === 1;
                $newHasVariants = (int) $product['has_variants'] === 1;
                $newStock = $newHasVariants ? ($oldHasVariants ? $oldStock : 0) : (int) $product['stock'];

                $statement = $pdo->prepare(
                    'UPDATE products SET
                        name = :name, slug = :slug, description = :description,
                        category_id = :category_id, category = :category,
                        price = :price, stock = :stock, low_stock_threshold = :threshold,
                        default_weight_grams = :weight, has_variants = :has_variants,
                        image = :image, status = :status, is_featured = :featured,
                        updated_at = NOW()
                     WHERE id = :id'
                );
                $statement->execute([
                    'name' => $product['name'],
                    'slug' => $product['slug'],
                    'description' => $product['description'],
                    'category_id' => $product['category_id'],
                    'category' => $product['category'],
                    'price' => $product['price'],
                    'stock' => $newStock,
                    'threshold' => $product['low_stock_threshold'],
                    'weight' => $product['default_weight_grams'],
                    'has_variants' => $product['has_variants'],
                    'image' => $newImagePath,
                    'status' => $product['status'],
                    'featured' => $product['is_featured'],
                    'id' => $productId,
                ]);

                if (!$newHasVariants && $oldStock !== $newStock) {
                    record_stock_history(
                        $pdo,
                        (int) $productId,
                        'adjustment',
                        abs($newStock - $oldStock),
                        $oldStock,
                        $newStock,
                        'product_edit',
                        (int) $productId,
                        'Stok produk tanpa variasi diubah melalui formulir produk.',
                        (int) $adminUser['id']
                    );
                }

                if ($newHasVariants) {
                    sync_product_variant_summary($pdo, (int) $productId);
                } else {
                    notify_wishlist_price_drop(
                        $pdo,
                        (int) $productId,
                        (string) $product['name'],
                        $oldPrice,
                        (float) $product['price']
                    );
                    if ($oldStock === 0 && $newStock > 0) {
                        notify_wishlist_restock($pdo, (int) $productId, (string) $product['name']);
                    }
                    if ($oldStock > (int) $product['low_stock_threshold']
                        && $newStock <= (int) $product['low_stock_threshold']) {
                        create_notification(
                            $pdo,
                            'Stok produk menipis',
                            (string) $product['name'] . ' tersisa ' . $newStock . ' unit.',
                            'stock.php?product_id=' . (int) $productId,
                            'stock',
                            'admin',
                            null,
                            'product-low-stock-' . (int) $productId . '-' . $newStock,
                            'product',
                            (int) $productId
                        );
                    }
                }

                log_admin_activity($pdo, 'product_update', 'Memperbarui produk ' . $product['name']);
                $message = 'Produk berhasil diperbarui.';
            } else {
                $initialStock = (int) $product['has_variants'] === 1 ? 0 : (int) $product['stock'];
                $statement = $pdo->prepare(
                    'INSERT INTO products (
                        name, slug, description, category_id, category, price, stock,
                        low_stock_threshold, default_weight_grams, has_variants,
                        image, status, is_featured
                     ) VALUES (
                        :name, :slug, :description, :category_id, :category, :price, :stock,
                        :threshold, :weight, :has_variants, :image, :status, :featured
                     )'
                );
                $statement->execute([
                    'name' => $product['name'],
                    'slug' => $product['slug'],
                    'description' => $product['description'],
                    'category_id' => $product['category_id'],
                    'category' => $product['category'],
                    'price' => $product['price'],
                    'stock' => $initialStock,
                    'threshold' => $product['low_stock_threshold'],
                    'weight' => $product['default_weight_grams'],
                    'has_variants' => $product['has_variants'],
                    'image' => $newImagePath,
                    'status' => $product['status'],
                    'featured' => $product['is_featured'],
                ]);
                $productId = (int) $pdo->lastInsertId();

                if ($initialStock > 0) {
                    record_stock_history(
                        $pdo,
                        $productId,
                        'initial',
                        $initialStock,
                        0,
                        $initialStock,
                        'product_create',
                        $productId,
                        'Stok awal produk.',
                        (int) $adminUser['id']
                    );
                }

                log_admin_activity($pdo, 'product_create', 'Menambahkan produk ' . $product['name']);
                $message = 'Produk baru berhasil ditambahkan.';
            }

            $pdo->commit();
            flash('success', $message);

            if ((int) $product['has_variants'] === 1) {
                redirect('product_variants.php?product_id=' . (int) $productId);
            }
            redirect('products.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Produk gagal disimpan: ' . $exception->getMessage();
        }
    }

    $product['image'] = $newImagePath;
}

$pageTitle = $isEdit ? 'Edit Produk' : 'Tambah Produk';
$activeMenu = 'products';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p>Atur data utama, tipe stok, harga awal, dan berat produk.</p>
    </div>
    <a class="admin-button admin-button-secondary" href="products.php">← Kembali</a>
</div>

<?php if ($errors): ?>
    <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
    </ul>
<?php endif; ?>

<form class="admin-card admin-form-stack" method="post" enctype="multipart/form-data" id="product-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <?php if ($isEdit): ?><input type="hidden" name="product_id" value="<?= (int) $productId ?>"><?php endif; ?>

    <div class="admin-image-editor">
        <img class="admin-image-preview" data-image-preview src="../<?= e((string) $product['image']) ?>" alt="Pratinjau produk">
        <label class="admin-field">
            <span>Foto produk</span>
            <input class="admin-input" data-image-input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
            <small>Maksimal 2 MB. Variasi dapat memiliki foto khusus.</small>
        </label>
    </div>

    <div class="admin-form-grid">
        <label class="admin-field">
            <span>Nama produk</span>
            <input class="admin-input" type="text" name="name" maxlength="150" value="<?= e((string) $product['name']) ?>" required>
        </label>
        <label class="admin-field">
            <span>Slug URL</span>
            <input class="admin-input" type="text" name="slug" maxlength="170" value="<?= e((string) $product['slug']) ?>" placeholder="Otomatis dari nama produk">
        </label>
        <label class="admin-field">
            <span>Kategori</span>
            <select class="admin-select" name="category_id" required>
                <option value="">Pilih kategori</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>" <?= (int) $product['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e((string) $category['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="admin-field">
            <span>Harga awal/default (Rupiah)</span>
            <input class="admin-input" type="number" name="price" min="1" step="1" value="<?= e((string) $product['price']) ?>" required>
            <small>Untuk produk bervariasi, harga katalog akan mengikuti harga variasi termurah.</small>
        </label>
        <label class="admin-field" id="base-stock-field">
            <span>Stok produk</span>
            <input class="admin-input" id="base-stock-input" type="number" name="stock" min="0" value="<?= (int) $product['stock'] ?>" required>
            <small>Dinonaktifkan jika stok dikelola per variasi.</small>
        </label>
        <label class="admin-field">
            <span>Berat default (gram)</span>
            <input class="admin-input" type="number" name="default_weight_grams" min="1" value="<?= (int) $product['default_weight_grams'] ?>" required>
            <small>Wajib diisi. Variasi dapat menggunakan berat berbeda.</small>
        </label>
        <label class="admin-field">
            <span>Batas stok menipis</span>
            <input class="admin-input" type="number" name="low_stock_threshold" min="0" value="<?= (int) $product['low_stock_threshold'] ?>" required>
        </label>
        <label class="admin-field">
            <span>Status produk</span>
            <select class="admin-select" name="status">
                <option value="active" <?= $product['status'] === 'active' ? 'selected' : '' ?>>Aktif</option>
                <option value="inactive" <?= $product['status'] === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                <option value="coming_soon" <?= $product['status'] === 'coming_soon' ? 'selected' : '' ?>>Segera tersedia</option>
            </select>
        </label>
        <div class="admin-field">
            <span>Pengaturan</span>
            <div class="admin-checkbox-row admin-checkbox-stack">
                <label><input type="checkbox" name="is_featured" value="1" <?= (int) $product['is_featured'] === 1 ? 'checked' : '' ?>> Produk unggulan</label>
                <label><input id="has-variants-input" type="checkbox" name="has_variants" value="1" <?= (int) $product['has_variants'] === 1 ? 'checked' : '' ?>> Produk memiliki variasi</label>
            </div>
        </div>
    </div>

    <div class="admin-alert admin-alert-info" id="variant-help">
        Jika produk memiliki variasi, simpan produk terlebih dahulu. Setelah itu Anda akan diarahkan untuk menambahkan SKU, warna, ukuran, harga, stok, berat, dan foto setiap variasi.
    </div>

    <label class="admin-field">
        <span>Deskripsi produk</span>
        <textarea class="admin-textarea" name="description" required><?= e((string) $product['description']) ?></textarea>
    </label>

    <div class="admin-form-actions">
        <button class="admin-button admin-button-primary" type="submit"><?= $isEdit ? 'Simpan Perubahan' : 'Tambah Produk' ?></button>
        <?php if ($isEdit && (int) $product['has_variants'] === 1): ?>
            <a class="admin-button admin-button-success" href="product_variants.php?product_id=<?= (int) $productId ?>">Kelola Variasi</a>
        <?php endif; ?>
        <a class="admin-button admin-button-secondary" href="products.php">Batal</a>
    </div>
</form>

<script>
(() => {
    const checkbox = document.getElementById('has-variants-input');
    const stockInput = document.getElementById('base-stock-input');
    const stockField = document.getElementById('base-stock-field');
    const help = document.getElementById('variant-help');

    function updateVariantMode() {
        const enabled = Boolean(checkbox && checkbox.checked);
        if (stockInput) {
            stockInput.disabled = enabled;
            stockInput.required = !enabled;
        }
        if (stockField) stockField.classList.toggle('admin-field-disabled', enabled);
        if (help) help.style.display = enabled ? '' : 'none';
    }

    checkbox?.addEventListener('change', updateVariantMode);
    updateVariantMode();
})();
</script>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
