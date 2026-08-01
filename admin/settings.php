<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$definitions = [
    'store_name' => ['Nama toko', 'ShopFlow', 'text'],
    'store_email' => ['Email layanan pelanggan', 'hello@shopflow.id', 'email'],
    'store_phone' => ['Nomor WhatsApp/telepon', '+62 812-3456-7890', 'text'],
    'footer_tagline' => ['Deskripsi singkat footer', 'Belanja mudah, aman, dan terpercaya untuk kebutuhan Anda setiap hari.', 'textarea'],
    'service_hours' => ['Jam layanan pelanggan', 'Senin–Sabtu, 08.00–20.00 WIB', 'text'],
    'store_address' => ['Alamat toko', '', 'textarea'],
    'store_province' => ['Provinsi asal toko', 'Jawa Tengah', 'text'],
    'store_city' => ['Kota/Kabupaten asal', 'Rembang', 'text'],
    'store_district' => ['Kecamatan asal', 'Rembang', 'text'],
    'store_postal_code' => ['Kode pos asal', '59217', 'text'],
    'store_full_address' => ['Alamat lengkap gudang', '', 'textarea'],
    'shipping_rounding_grams' => ['Pembulatan berat pengiriman (gram)', '1000', 'number'],
    'currency' => ['Mata uang', 'IDR', 'text'],
    'default_low_stock' => ['Batas stok menipis bawaan', '5', 'number'],
    'cod_confirmation_hours' => ['Batas konfirmasi COD (jam)', '24', 'number'],
    'review_moderation_enabled' => ['Moderasi ulasan sebelum diterbitkan', '0', 'select'],
    'return_period_days' => ['Batas waktu pengajuan retur (hari)', '7', 'number'],
    'cod_policy' => ['Kebijakan COD', 'Pembayaran dilakukan kepada kurir saat pesanan diterima.', 'textarea'],
    'cod_check_policy' => ['Kebijakan COD Cek Dulu', 'Pelanggan dapat memeriksa kondisi dan kesesuaian produk sebelum membayar.', 'textarea'],
    'cancellation_policy' => ['Kebijakan pembatalan', 'Pesanan dapat dibatalkan selama masih menunggu konfirmasi atau pembayaran.', 'textarea'],
    'refund_policy' => ['Kebijakan pengembalian', 'Pengembalian dana mengikuti hasil verifikasi toko.', 'textarea'],
    'return_policy' => ['Kebijakan retur barang', 'Retur dapat diajukan maksimal 7 hari setelah pesanan selesai. Produk wajib dikembalikan lengkap dan bukti foto diperlukan untuk kerusakan, salah produk, atau barang tidak sesuai.', 'textarea'],
    'terms_conditions' => ['Syarat dan ketentuan', 'Gunakan layanan ShopFlow secara bertanggung jawab.', 'textarea'],
    'privacy_policy' => ['Kebijakan privasi', 'Data pelanggan digunakan untuk pemrosesan transaksi dan pengiriman.', 'textarea'],
];

$rows = $pdo->query('SELECT setting_key, setting_value FROM store_settings')->fetchAll();
$values = [];
foreach ($rows as $row) {
    $values[(string) $row['setting_key']] = (string) $row['setting_value'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $newLogo = save_uploaded_image($_FILES['store_logo_file'] ?? [], 'store', 'shopflow-logo');
        if ($newLogo !== null) {
            $oldLogo = (string) ($values['store_logo'] ?? '');
            if (str_starts_with($oldLogo, 'uploads/store/')) {
                $oldFile = __DIR__ . '/../' . $oldLogo;
                if (is_file($oldFile)) {
                    @unlink($oldFile);
                }
            }
            $values['store_logo'] = $newLogo;
        }

        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            'INSERT INTO store_settings (setting_key, setting_value, updated_by)
             VALUES (:key, :value, :admin)
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by),
                updated_at = NOW()'
        );

        foreach ($definitions as $key => $definition) {
            $value = trim((string) ($_POST[$key] ?? ''));
            if (strlen($value) > 5000) {
                throw new RuntimeException('Nilai pengaturan terlalu panjang.');
            }
            $statement->execute([
                'key' => $key,
                'value' => $value,
                'admin' => (int) $adminUser['id'],
            ]);
        }

        $statement->execute([
            'key' => 'store_logo',
            'value' => (string) ($values['store_logo'] ?? ''),
            'admin' => (int) $adminUser['id'],
        ]);

        log_admin_activity($pdo, 'settings_update', 'Memperbarui pengaturan toko.');
        $pdo->commit();
        flash('success', 'Pengaturan toko berhasil disimpan.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash(
            $exception instanceof RuntimeException ? 'warning' : 'danger',
            $exception instanceof RuntimeException ? $exception->getMessage() : 'Pengaturan gagal disimpan.'
        );
    }

    redirect('settings.php');
}

$pageTitle = 'Pengaturan Toko';
$activeMenu = 'settings';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head">
    <div><h1>Pengaturan Toko</h1><p>Atur identitas, operasional COD, dan kebijakan ShopFlow.</p></div>
</div>

<form class="admin-form-stack" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

    <section class="admin-card">
        <div class="admin-card-head"><h2>Identitas Toko</h2></div>
        <div class="admin-image-editor" style="margin-bottom:18px">
            <img class="admin-image-preview" data-image-preview src="<?= !empty($values['store_logo']) ? '../' . e($values['store_logo']) : '../assets/images/bag.svg' ?>" alt="Logo toko">
            <label class="admin-field">
                <span>Logo toko</span>
                <input class="admin-input" data-image-input type="file" name="store_logo_file" accept="image/jpeg,image/png,image/webp,image/gif">
                <small>Format JPG, PNG, WEBP, atau GIF. Maksimal 2 MB.</small>
            </label>
        </div>
        <div class="admin-form-grid">
            <?php foreach (['store_name', 'store_email', 'store_phone', 'currency'] as $key): ?>
                <?php [$label, $default, $type] = $definitions[$key]; ?>
                <label class="admin-field"><span><?= e($label) ?></span><input class="admin-input" type="<?= e($type) ?>" name="<?= e($key) ?>" value="<?= e($values[$key] ?? $default) ?>"></label>
            <?php endforeach; ?>
        </div>
        <label class="admin-field" style="margin-top:16px"><span>Alamat toko</span><textarea class="admin-textarea" name="store_address"><?= e($values['store_address'] ?? '') ?></textarea></label>
    </section>

    <section class="admin-card">
        <div class="admin-card-head">
            <h2>Footer & Layanan Pelanggan</h2>
            <span>Informasi ini tampil pada bagian bawah halaman pelanggan.</span>
        </div>
        <div class="admin-form-grid">
            <?php foreach (['service_hours'] as $key): ?>
                <?php [$label, $default, $type] = $definitions[$key]; ?>
                <label class="admin-field"><span><?= e($label) ?></span><input class="admin-input" type="<?= e($type) ?>" name="<?= e($key) ?>" value="<?= e($values[$key] ?? $default) ?>"></label>
            <?php endforeach; ?>
        </div>
        <?php [$footerLabel, $footerDefault] = $definitions['footer_tagline']; ?>
        <label class="admin-field" style="margin-top:16px">
            <span><?= e($footerLabel) ?></span>
            <textarea class="admin-textarea" name="footer_tagline"><?= e($values['footer_tagline'] ?? $footerDefault) ?></textarea>
            <small>Ganti email dan nomor WhatsApp contoh pada bagian Identitas Toko dengan kontak bisnis Anda sebelum website dipublikasikan.</small>
        </label>
    </section>

    <section class="admin-card">
        <div class="admin-card-head"><h2>Lokasi Asal Pengiriman</h2><span>Digunakan pada informasi resi dan pengiriman</span></div>
        <div class="admin-form-grid">
            <?php foreach (['store_province', 'store_city', 'store_district', 'store_postal_code'] as $key): ?>
                <?php [$label, $default, $type] = $definitions[$key]; ?>
                <label class="admin-field"><span><?= e($label) ?></span><input class="admin-input" type="<?= e($type) ?>" name="<?= e($key) ?>" value="<?= e($values[$key] ?? $default) ?>"></label>
            <?php endforeach; ?>
        </div>
        <label class="admin-field" style="margin-top:16px"><span>Alamat lengkap gudang</span><textarea class="admin-textarea" name="store_full_address"><?= e($values['store_full_address'] ?? '') ?></textarea></label>
    </section>

    <section class="admin-card">
        <div class="admin-card-head"><h2>Operasional</h2></div>
        <div class="admin-form-grid">
            <?php foreach (['default_low_stock', 'cod_confirmation_hours', 'shipping_rounding_grams', 'return_period_days'] as $key): ?>
                <?php [$label, $default] = $definitions[$key]; ?>
                <label class="admin-field"><span><?= e($label) ?></span><input class="admin-input" type="number" min="0" name="<?= e($key) ?>" value="<?= e($values[$key] ?? $default) ?>"></label>
            <?php endforeach; ?>
            <label class="admin-field"><span>Moderasi ulasan sebelum diterbitkan</span><select class="admin-select" name="review_moderation_enabled"><option value="0" <?= ($values['review_moderation_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>Tidak — langsung diterbitkan</option><option value="1" <?= ($values['review_moderation_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Ya — menunggu admin</option></select><small>Admin tetap dapat menyembunyikan atau menolak ulasan yang sudah terbit.</small></label>
        </div>
    </section>

    <section class="admin-card">
        <div class="admin-card-head"><h2>Kebijakan Toko</h2></div>
        <div class="admin-form-stack">
            <?php foreach (['cod_policy', 'cod_check_policy', 'cancellation_policy', 'refund_policy', 'return_policy', 'terms_conditions', 'privacy_policy'] as $key): ?>
                <?php [$label, $default] = $definitions[$key]; ?>
                <label class="admin-field"><span><?= e($label) ?></span><textarea class="admin-textarea" name="<?= e($key) ?>"><?= e($values[$key] ?? $default) ?></textarea></label>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="admin-form-actions"><button class="admin-button admin-button-primary" type="submit">Simpan Pengaturan</button></div>
</form>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
