<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
$returnTo = customer_return_path($_GET['return_to'] ?? null);

$statement = $pdo->prepare(
    'SELECT id, label, recipient_name, phone, province, city, district, village,
            postal_code, full_address, courier_note, is_primary, created_at, updated_at
     FROM user_addresses
     WHERE user_id = :user_id
     ORDER BY is_primary DESC, id DESC'
);
$statement->execute(['user_id' => (int) $user['id']]);
$addresses = $statement->fetchAll();

$pageTitle = 'Alamat Saya';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header address-page-header">
    <div class="container address-page-heading">
        <div>
            <span class="eyebrow">Akun saya</span>
            <h1>Alamat Pengiriman</h1>
            <p>Simpan beberapa alamat agar proses checkout menjadi lebih cepat dan rapi.</p>
        </div>
        <a class="button button-primary" href="add_address.php?return_to=<?= e($returnTo) ?>">+ Tambah Alamat</a>
    </div>
</section>

<section class="section address-section">
    <div class="container">
        <div class="address-summary-bar">
            <div>
                <span class="address-summary-icon">📍</span>
                <div><strong><?= count($addresses) ?> alamat tersimpan</strong><span>Anda dapat menyimpan maksimal 10 alamat.</span></div>
            </div>
            <?php if ($returnTo === 'checkout.php'): ?>
                <a class="button button-secondary button-small" href="checkout.php">Kembali ke Checkout</a>
            <?php endif; ?>
        </div>

        <?php if ($addresses === []): ?>
            <div class="empty-state address-empty-state">
                <div class="empty-icon">🏠</div>
                <h2>Belum ada alamat tersimpan</h2>
                <p>Tambahkan alamat rumah, kantor, atau kos untuk digunakan saat checkout.</p>
                <a class="button button-primary" href="add_address.php?return_to=<?= e($returnTo) ?>">Tambah Alamat Pertama</a>
            </div>
        <?php else: ?>
            <div class="address-card-grid">
                <?php foreach ($addresses as $address): ?>
                    <article class="address-card <?= (int) $address['is_primary'] === 1 ? 'is-primary' : '' ?>">
                        <div class="address-card-top">
                            <div class="address-label-wrap">
                                <span class="address-card-icon"><?= e(address_icon((string) $address['label'])) ?></span>
                                <div>
                                    <div class="address-title-line">
                                        <h2><?= e((string) $address['label']) ?></h2>
                                        <?php if ((int) $address['is_primary'] === 1): ?>
                                            <span class="primary-address-badge">Alamat Utama</span>
                                        <?php endif; ?>
                                    </div>
                                    <span><?= e((string) $address['recipient_name']) ?> · <?= e((string) $address['phone']) ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="address-card-body">
                            <p><?= nl2br(e((string) $address['full_address'])) ?></p>
                            <p><?= e(address_region_text($address)) ?></p>
                            <?php if (trim((string) $address['courier_note']) !== ''): ?>
                                <div class="courier-note"><strong>Catatan kurir:</strong> <?= e((string) $address['courier_note']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="address-card-actions">
                            <a class="button button-secondary button-small" href="edit_address.php?id=<?= (int) $address['id'] ?>&return_to=<?= e($returnTo) ?>">Edit</a>

                            <?php if ((int) $address['is_primary'] !== 1): ?>
                                <form method="post" action="address_action.php">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="address_id" value="<?= (int) $address['id'] ?>">
                                    <input type="hidden" name="action" value="set_primary">
                                    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                                    <button class="button button-ghost button-small" type="submit">Jadikan Utama</button>
                                </form>
                            <?php endif; ?>

                            <form method="post" action="address_action.php" data-delete-address>
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="address_id" value="<?= (int) $address['id'] ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                                <button class="button button-danger button-small" type="submit">Hapus</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
(() => {
    document.querySelectorAll('[data-delete-address]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!confirm('Hapus alamat ini? Alamat pada pesanan lama tetap tersimpan.')) {
                event.preventDefault();
            }
        });
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
