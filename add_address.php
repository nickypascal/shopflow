<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
$returnTo = customer_return_path($_POST['return_to'] ?? $_GET['return_to'] ?? null);

$countStatement = $pdo->prepare('SELECT COUNT(*) FROM user_addresses WHERE user_id = :user_id');
$countStatement->execute(['user_id' => (int) $user['id']]);
$addressCount = (int) $countStatement->fetchColumn();

$phoneStatement = $pdo->prepare('SELECT phone FROM users WHERE id = :id');
$phoneStatement->execute(['id' => (int) $user['id']]);
$userPhone = (string) ($phoneStatement->fetchColumn() ?: '');

$address = [
    'label' => 'Rumah',
    'recipient_name' => (string) ($user['name'] ?? ''),
    'phone' => $userPhone,
    'province' => '',
    'city' => '',
    'district' => '',
    'village' => '',
    'postal_code' => '',
    'full_address' => '',
    'courier_note' => '',
    'is_primary' => $addressCount === 0 ? 1 : 0,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    [$address, $errors] = validate_address_input($_POST);

    if ($addressCount >= 10) {
        $errors[] = 'Maksimal 10 alamat dapat disimpan pada satu akun.';
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $makePrimary = $addressCount === 0 || (int) $address['is_primary'] === 1;

            if ($makePrimary) {
                $pdo->prepare('UPDATE user_addresses SET is_primary = 0 WHERE user_id = :user_id')
                    ->execute(['user_id' => (int) $user['id']]);
            }

            $statement = $pdo->prepare(
                'INSERT INTO user_addresses (
                    user_id, label, recipient_name, phone, province, city, district,
                    village, postal_code, full_address, courier_note, is_primary
                 ) VALUES (
                    :user_id, :label, :recipient_name, :phone, :province, :city, :district,
                    :village, :postal_code, :full_address, :courier_note, :is_primary
                 )'
            );
            $statement->execute([
                'user_id' => (int) $user['id'],
                'label' => $address['label'],
                'recipient_name' => $address['recipient_name'],
                'phone' => $address['phone'],
                'province' => $address['province'],
                'city' => $address['city'],
                'district' => $address['district'],
                'village' => $address['village'] !== '' ? $address['village'] : null,
                'postal_code' => $address['postal_code'],
                'full_address' => $address['full_address'],
                'courier_note' => $address['courier_note'] !== '' ? $address['courier_note'] : null,
                'is_primary' => $makePrimary ? 1 : 0,
            ]);

            $pdo->prepare("UPDATE users SET phone = :phone, updated_at = NOW() WHERE id = :id AND (phone IS NULL OR phone = '')")
                ->execute(['phone' => $address['phone'], 'id' => (int) $user['id']]);

            $pdo->commit();
            flash('success', 'Alamat baru berhasil disimpan.');
            redirect($returnTo);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Alamat gagal disimpan. Silakan coba lagi.';
        }
    }
}

$pageTitle = 'Tambah Alamat';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header address-page-header">
    <div class="container">
        <span class="eyebrow">Alamat pengguna</span>
        <h1>Tambah Alamat Baru</h1>
        <p>Lengkapi data penerima dan lokasi pengiriman dengan benar.</p>
    </div>
</section>
<section class="section address-section">
    <div class="container address-form-layout">
        <div class="address-form-card">
            <?php if ($errors): ?>
                <div class="alert alert-danger address-form-errors">
                    <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form class="form-stack" method="post" action="add_address.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <?php $submitLabel = 'Simpan Alamat'; require __DIR__ . '/includes/address_form_fields.php'; ?>
            </form>
        </div>
        <aside class="address-help-card">
            <span>💡</span>
            <h2>Pastikan alamat mudah ditemukan</h2>
            <p>Tuliskan nomor rumah, RT/RW, nama jalan, dan patokan lokasi agar kurir tidak kesulitan.</p>
            <ul><li>Gunakan nomor telepon aktif.</li><li>Isi kode pos dengan 5 angka.</li><li>Jangan masukkan data pembayaran.</li></ul>
        </aside>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
