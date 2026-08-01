<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$couriers = $pdo->query(
    "SELECT c.*,
            (SELECT COUNT(*) FROM shipping_services s WHERE s.courier_id = c.id) AS service_count,
            (SELECT COUNT(*) FROM orders o WHERE o.shipping_courier_id = c.id) AS order_count
     FROM shipping_couriers c ORDER BY c.courier_name"
)->fetchAll();

$pageTitle = 'Daftar Kurir';
$activeMenu = 'shipping';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head">
    <div><h1>Daftar Kurir</h1><p>Atur kurir dan dukungan pembayaran di tempat.</p></div>
    <div class="admin-page-actions"><a class="admin-button admin-button-secondary" href="shipping.php">← Pengiriman</a><a class="admin-button admin-button-primary" href="shipping_courier_form.php">+ Tambah Kurir</a></div>
</div>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>Kurir</th><th>Kode</th><th>Layanan</th><th>COD</th><th>COD Cek Dulu</th><th>Status</th><th>Aksi</th></tr></thead>
<tbody>
<?php foreach ($couriers as $courier): ?>
<tr>
    <td><strong><?= e((string) $courier['courier_name']) ?></strong><br><small><?= (int) $courier['order_count'] ?> pesanan</small></td>
    <td><?= e((string) $courier['courier_code']) ?></td>
    <td><?= (int) $courier['service_count'] ?> layanan</td>
    <td><?= (int) $courier['supports_cod'] === 1 ? '✓ Ya' : '— Tidak' ?></td>
    <td><?= (int) $courier['supports_cod_check'] === 1 ? '✓ Ya' : '— Tidak' ?></td>
    <td><span class="admin-badge <?= (int) $courier['is_active'] === 1 ? 'status-complete' : 'status-neutral' ?>"><?= (int) $courier['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
    <td><div class="admin-table-actions"><a class="admin-button admin-button-primary admin-button-small" href="shipping_courier_form.php?id=<?= (int) $courier['id'] ?>">Edit</a><a class="admin-button admin-button-secondary admin-button-small" href="shipping_services.php?courier_id=<?= (int) $courier['id'] ?>">Layanan</a><form method="post" action="shipping_courier_action.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="courier_id" value="<?= (int) $courier['id'] ?>"><input type="hidden" name="action" value="toggle"><button class="admin-button admin-button-warning admin-button-small" type="submit" data-confirm="Ubah status kurir ini?">Toggle</button></form></div></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
