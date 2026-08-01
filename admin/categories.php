<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$editId = (int) ($_GET['edit'] ?? 0);
$editCategory = null;
if ($editId > 0) {
    $statement = $pdo->prepare('SELECT * FROM categories WHERE id = :id');
    $statement->execute(['id' => $editId]);
    $editCategory = $statement->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = trim((string) ($_POST['action'] ?? 'save'));
    $categoryId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);

    try {
        if ($action === 'delete') {
            if (!$categoryId) throw new RuntimeException('Kategori tidak valid.');
            $count = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = :id');
            $count->execute(['id' => $categoryId]);
            if ((int) $count->fetchColumn() > 0) throw new RuntimeException('Kategori masih digunakan produk dan tidak dapat dihapus. Nonaktifkan kategori sebagai gantinya.');
            $name = $pdo->prepare('SELECT name FROM categories WHERE id = :id');
            $name->execute(['id' => $categoryId]);
            $categoryName = (string) $name->fetchColumn();
            $pdo->prepare('DELETE FROM categories WHERE id = :id')->execute(['id' => $categoryId]);
            log_admin_activity($pdo, 'category_delete', 'Menghapus kategori ' . $categoryName);
            flash('success', 'Kategori berhasil dihapus.');
            redirect('categories.php');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $icon = trim((string) ($_POST['icon'] ?? '🛍️'));
        $image = trim((string) ($_POST['image'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'active'));
        if (strlen($name) < 2 || strlen($name) > 80) throw new RuntimeException('Nama kategori harus berisi 2–80 karakter.');
        if (strlen($image) > 255) throw new RuntimeException('Path gambar kategori maksimal 255 karakter.');
        if (!in_array($status, ['active','inactive'], true)) throw new RuntimeException('Status kategori tidak valid.');
        $slug = slugify($name);
        $check = $pdo->prepare('SELECT id FROM categories WHERE (name = :name OR slug = :slug) AND id <> :id LIMIT 1');
        $check->execute(['name'=>$name,'slug'=>$slug,'id'=>(int) $categoryId]);
        if ($check->fetch()) throw new RuntimeException('Nama kategori sudah digunakan.');

        if ($categoryId) {
            $pdo->prepare('UPDATE categories SET name=:name, slug=:slug, icon=:icon, image=:image, status=:status, updated_at=NOW() WHERE id=:id')->execute(['name'=>$name,'slug'=>$slug,'icon'=>$icon ?: '🛍️','image'=>$image ?: null,'status'=>$status,'id'=>$categoryId]);
            $pdo->prepare('UPDATE products SET category=:name, updated_at=NOW() WHERE category_id=:id')->execute(['name'=>$name,'id'=>$categoryId]);
            log_admin_activity($pdo, 'category_update', 'Memperbarui kategori ' . $name);
            flash('success', 'Kategori berhasil diperbarui.');
        } else {
            $pdo->prepare('INSERT INTO categories (name,slug,icon,image,status) VALUES (:name,:slug,:icon,:image,:status)')->execute(['name'=>$name,'slug'=>$slug,'icon'=>$icon ?: '🛍️','image'=>$image ?: null,'status'=>$status]);
            log_admin_activity($pdo, 'category_create', 'Menambahkan kategori ' . $name);
            flash('success', 'Kategori berhasil ditambahkan.');
        }
    } catch (Throwable $exception) {
        flash($exception instanceof RuntimeException ? 'warning' : 'danger', $exception->getMessage());
    }
    redirect('categories.php');
}

$categories = $pdo->query(
    "SELECT c.*, COUNT(p.id) AS product_count, SUM(CASE WHEN p.status='active' THEN 1 ELSE 0 END) AS active_product_count
     FROM categories c LEFT JOIN products p ON p.category_id = c.id
     GROUP BY c.id ORDER BY c.name"
)->fetchAll();

$pageTitle = 'Kategori Produk';
$activeMenu = 'categories';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1>Kategori Produk</h1><p>Atur kelompok produk yang ditampilkan pada halaman toko.</p></div></div>
<div class="admin-grid admin-grid-2" style="align-items:start">
    <section class="admin-card">
        <div class="admin-card-head"><h2><?= $editCategory ? 'Edit Kategori' : 'Tambah Kategori' ?></h2><?php if ($editCategory): ?><a href="categories.php">Batal edit</a><?php endif; ?></div>
        <form class="admin-form-stack" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><?php if ($editCategory): ?><input type="hidden" name="category_id" value="<?= (int) $editCategory['id'] ?>"><?php endif; ?>
            <label class="admin-field"><span>Nama kategori</span><input class="admin-input" type="text" name="name" maxlength="80" value="<?= e((string) ($editCategory['name'] ?? '')) ?>" required></label>
            <label class="admin-field"><span>Ikon/emoji</span><input class="admin-input" type="text" name="icon" maxlength="20" value="<?= e((string) ($editCategory['icon'] ?? '🛍️')) ?>"><small>Contoh: 💻, 👟, 🏠, ✨</small></label>
            <label class="admin-field"><span>Path gambar kategori (opsional)</span><input class="admin-input" type="text" name="image" maxlength="255" value="<?= e((string) ($editCategory['image'] ?? '')) ?>" placeholder="assets/images/kategori.png"><small>Gunakan path gambar yang tersedia di folder aplikasi.</small></label>
            <label class="admin-field"><span>Status</span><select class="admin-select" name="status"><option value="active" <?= ($editCategory['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Aktif</option><option value="inactive" <?= ($editCategory['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Nonaktif</option></select></label>
            <button class="admin-button admin-button-primary" type="submit"><?= $editCategory ? 'Simpan Perubahan' : 'Tambah Kategori' ?></button>
        </form>
    </section>

    <section class="admin-card">
        <div class="admin-card-head"><h2>Daftar Kategori</h2><span><?= count($categories) ?> kategori</span></div>
        <?php if ($categories === []): ?><div class="admin-empty"><strong>Belum ada kategori</strong><p>Tambahkan kategori pertama melalui formulir.</p></div><?php else: ?>
            <div class="admin-list">
                <?php foreach ($categories as $category): ?>
                    <div class="admin-list-item">
                        <div style="display:flex;align-items:center;gap:12px"><div class="admin-stat-icon"><?php if (!empty($category['image'])): ?><img src="../<?= e((string) $category['image']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit"><?php else: ?><?= e((string) $category['icon']) ?><?php endif; ?></div><div class="admin-list-copy"><strong><?= e((string) $category['name']) ?></strong><span><?= (int) $category['product_count'] ?> produk • <?= (int) $category['active_product_count'] ?> aktif</span><small><?= e((string) $category['slug']) ?></small></div></div>
                        <div class="admin-table-actions"><span class="admin-badge <?= $category['status'] === 'active' ? 'status-complete' : 'status-neutral' ?>"><?= $category['status'] === 'active' ? 'Aktif' : 'Nonaktif' ?></span><a class="admin-button admin-button-secondary admin-button-small" href="categories.php?edit=<?= (int) $category['id'] ?>">Edit</a><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>"><button class="admin-button admin-button-danger admin-button-small" type="submit" data-confirm="Hapus kategori ini?" <?= (int) $category['product_count'] > 0 ? 'disabled title="Masih digunakan produk"' : '' ?>>Hapus</button></form></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
