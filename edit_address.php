<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
$returnTo = customer_return_path($_POST['return_to'] ?? $_GET['return_to'] ?? null);
$addressId = (int) ($_POST['address_id'] ?? $_GET['id'] ?? 0);

$statement = $pdo->prepare(
    'SELECT id, label, recipient_name, phone, province, city, district, village,
            postal_code, full_address, courier_note, is_primary
     FROM user_addresses
     WHERE id = :id AND user_id = :user_id
     LIMIT 1'
);
$statement->execute(['id' => $addressId, 'user_id' => (int) $user['id']]);
$storedAddress = $statement->fetch();

if (!$storedAddress) {
    flash('danger', 'Alamat tidak ditemukan atau bukan milik Anda.');
    redirect('addresses.php');
}

$address = $storedAddress;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    [$address, $errors] = validate_address_input($_POST);

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $makePrimary = (int) $storedAddress['is_primary'] === 1 || (int) $address['is_primary'] === 1;

            if ((int) $address['is_primary'] === 1) {
                $pdo->prepare('UPDATE user_addresses SET is_primary = 0 WHERE user_id = :user_id')
                    ->execute(['user_id' => (int) $user['id']]);
            }

            $update = $pdo->prepare(
                'UPDATE user_addresses SET
                    label = :label,
                    recipient_name = :recipient_name,
                    phone = :phone,
                    province = :province,
                    city = :city,
                    district = :district,
                    village = :village,
                    postal_code = :postal_code,
                    full_address = :full_address,
                    courier_note = :courier_note,
                    is_primary = :is_primary,
                    updated_at = NOW()
                 WHERE id = :id AND user_id = :user_id'
            );
            $update->execute([
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
                'id' => $addressId,
                'user_id' => (int) $user['id'],
            ]);

            $pdo->commit();
            flash('success', 'Alamat berhasil diperbarui.');
            redirect($returnTo);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Alamat gagal diperbarui. Silakan coba lagi.';
        }
    }
}

$pageTitle = 'Edit Alamat';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header address-page-header">
    <div class="container">
        <span class="eyebrow">Alamat pengguna</span>
        <h1>Edit <?= e((string) $storedAddress['label']) ?></h1>
        <p>Perubahan alamat tidak akan mengubah data pengiriman pada pesanan lama.</p>
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
            <form class="form-stack" method="post" action="edit_address.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="address_id" value="<?= $addressId ?>">
                <?php $submitLabel = 'Simpan Perubahan'; require __DIR__ . '/includes/address_form_fields.php'; ?>
            </form>
        </div>
        <aside class="address-help-card">
            <span>🔒</span>
            <h2>Data pesanan tetap aman</h2>
            <p>ShopFlow menyimpan salinan alamat pada saat checkout, sehingga perubahan ini hanya berlaku untuk checkout berikutnya.</p>
        </aside>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
