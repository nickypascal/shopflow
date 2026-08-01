<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
$customerId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$customerId) { flash('danger','Pelanggan tidak valid.'); redirect('customers.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $newStatus = trim((string) ($_POST['status'] ?? ''));
    $note = trim((string) ($_POST['admin_note'] ?? ''));
    if (!in_array($newStatus, ['active','inactive'], true)) {
        flash('warning','Status akun tidak valid.');
    } else {
        $pdo->prepare('UPDATE users SET status=:status, admin_note=:note, updated_at=NOW() WHERE id=:id AND role=\'customer\'')->execute(['status'=>$newStatus,'note'=>$note ?: null,'id'=>$customerId]);
        log_admin_activity($pdo, 'customer_status', 'Mengubah status pelanggan #' . $customerId . ' menjadi ' . $newStatus);
        flash('success','Status dan catatan pelanggan berhasil disimpan.');
    }
    redirect('customer_detail.php?id=' . $customerId);
}

$statement = $pdo->prepare(
    "SELECT u.*,
            COUNT(o.id) AS order_count,
            SUM(CASE WHEN o.status='Selesai' THEN 1 ELSE 0 END) completed_count,
            SUM(CASE WHEN o.status='Dibatalkan' THEN 1 ELSE 0 END) cancelled_count,
            SUM(CASE WHEN o.status='Ditolak' THEN 1 ELSE 0 END) rejected_count,
            COALESCE(SUM(CASE WHEN o.status='Selesai' THEN o.total_amount ELSE 0 END),0) total_spent
     FROM users u LEFT JOIN orders o ON o.user_id=u.id
     WHERE u.id=:id AND u.role='customer' GROUP BY u.id"
);
$statement->execute(['id'=>$customerId]);
$customer = $statement->fetch();
if (!$customer) { flash('warning','Pelanggan tidak ditemukan.'); redirect('customers.php'); }
$orderStatement = $pdo->prepare('SELECT id,order_number,status,payment_method,total_amount,created_at FROM orders WHERE user_id=:id ORDER BY id DESC LIMIT 50');
$orderStatement->execute(['id'=>$customerId]);
$orders = $orderStatement->fetchAll();

$pageTitle = 'Detail Pelanggan';
$activeMenu = 'customers';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1><?= e((string) $customer['name']) ?></h1><p><?= e((string) $customer['email']) ?> • bergabung <?= format_datetime((string) $customer['created_at']) ?></p></div><a class="admin-button admin-button-secondary" href="customers.php">← Kembali</a></div>
<section class="admin-grid admin-grid-4" style="margin-bottom:18px"><article class="admin-stat-card"><div class="admin-stat-copy"><span>Total pesanan</span><strong><?= (int) $customer['order_count'] ?></strong></div></article><article class="admin-stat-card"><div class="admin-stat-copy"><span>Pesanan selesai</span><strong><?= (int) $customer['completed_count'] ?></strong></div></article><article class="admin-stat-card"><div class="admin-stat-copy"><span>Dibatalkan/Ditolak</span><strong><?= (int) $customer['cancelled_count'] ?> / <?= (int) $customer['rejected_count'] ?></strong></div></article><article class="admin-stat-card"><div class="admin-stat-copy"><span>Total belanja</span><strong><?= rupiah($customer['total_spent']) ?></strong></div></article></section>
<div class="admin-grid admin-grid-2" style="align-items:start">
<section class="admin-card"><div class="admin-card-head"><h2>Profil Pelanggan</h2></div><div class="admin-detail-grid"><div class="admin-detail-item"><span>Nama</span><strong><?= e((string) $customer['name']) ?></strong></div><div class="admin-detail-item"><span>Email</span><strong><?= e((string) $customer['email']) ?></strong></div><div class="admin-detail-item"><span>Telepon</span><strong><?= e((string) ($customer['phone'] ?: '-')) ?></strong></div><div class="admin-detail-item"><span>Login terakhir</span><strong><?= format_datetime($customer['last_login_at']) ?></strong></div></div></section>
<section class="admin-card"><div class="admin-card-head"><h2>Status & Catatan</h2></div><form class="admin-form-stack" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label class="admin-field"><span>Status akun</span><select class="admin-select" name="status"><option value="active" <?= $customer['status'] === 'active' ? 'selected' : '' ?>>Aktif</option><option value="inactive" <?= $customer['status'] === 'inactive' ? 'selected' : '' ?>>Nonaktif</option></select></label><label class="admin-field"><span>Catatan internal</span><textarea class="admin-textarea" name="admin_note" maxlength="500"><?= e((string) ($customer['admin_note'] ?? '')) ?></textarea></label><button class="admin-button admin-button-primary" type="submit">Simpan</button></form></section>
</div>
<section class="admin-card" style="margin-top:18px"><div class="admin-card-head"><h2>Riwayat Pesanan</h2><span><?= count($orders) ?> ditampilkan</span></div><?php if ($orders === []): ?><div class="admin-empty"><strong>Belum ada pesanan</strong><p>Pelanggan belum melakukan transaksi.</p></div><?php else: ?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Pesanan</th><th>Tanggal</th><th>Pembayaran</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach ($orders as $order): ?><tr><td><strong><?= e(order_code($order)) ?></strong></td><td><?= format_datetime((string) $order['created_at']) ?></td><td><?= e(payment_method_label((string) $order['payment_method'])) ?></td><td><strong><?= rupiah($order['total_amount']) ?></strong></td><td><span class="admin-badge <?= e(status_css_class((string) $order['status'])) ?>"><?= e((string) $order['status']) ?></span></td><td><a class="admin-button admin-button-primary admin-button-small" href="order_detail.php?id=<?= (int) $order['id'] ?>">Detail</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
