<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$search = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? 'Semua'));
if (!in_array($status, ['Semua','active','inactive'], true)) $status = 'Semua';
$where = ["u.role = 'customer'"];
$params = [];
if ($search !== '') { $where[] = '(u.name LIKE :search OR u.email LIKE :search OR u.phone LIKE :search)'; $params['search'] = '%' . $search . '%'; }
if ($status !== 'Semua') { $where[] = 'u.status = :status'; $params['status'] = $status; }

$statement = $pdo->prepare(
    "SELECT u.id, u.name, u.email, u.phone, u.status, u.created_at, u.last_login_at,
            COUNT(o.id) AS order_count,
            SUM(CASE WHEN o.status = 'Selesai' THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN o.status = 'Dibatalkan' THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN o.status = 'Ditolak' THEN 1 ELSE 0 END) AS rejected_count,
            COALESCE(SUM(CASE WHEN o.status = 'Selesai' THEN o.total_amount ELSE 0 END), 0) AS total_spent
     FROM users u LEFT JOIN orders o ON o.user_id = u.id
     WHERE " . implode(' AND ', $where) . "
     GROUP BY u.id ORDER BY u.id DESC"
);
$statement->execute($params);
$customers = $statement->fetchAll();
$summary = $pdo->query("SELECT COUNT(*) total, SUM(status='active') active_count, SUM(status='inactive') inactive_count FROM users WHERE role='customer'")->fetch();

$pageTitle = 'Data Pelanggan';
$activeMenu = 'customers';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1>Data Pelanggan</h1><p>Lihat profil, aktivitas transaksi, dan status akun pelanggan.</p></div></div>
<section class="admin-grid admin-grid-3" style="margin-bottom:18px"><article class="admin-stat-card"><div class="admin-stat-copy"><span>Total pelanggan</span><strong><?= (int) ($summary['total'] ?? 0) ?></strong></div></article><article class="admin-stat-card"><div class="admin-stat-copy"><span>Akun aktif</span><strong><?= (int) ($summary['active_count'] ?? 0) ?></strong></div></article><article class="admin-stat-card"><div class="admin-stat-copy"><span>Akun nonaktif</span><strong><?= (int) ($summary['inactive_count'] ?? 0) ?></strong></div></article></section>
<div class="admin-filter-card"><form class="admin-filter-form" method="get" style="grid-template-columns:2fr 1fr auto"><label class="admin-field"><span>Pencarian</span><input class="admin-input" type="search" name="q" value="<?= e($search) ?>" placeholder="Nama, email, atau telepon"></label><label class="admin-field"><span>Status akun</span><select class="admin-select" name="status"><option value="Semua">Semua status</option><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Aktif</option><option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Nonaktif</option></select></label><button class="admin-button admin-button-primary" type="submit">Terapkan</button></form></div>
<?php if ($customers === []): ?><div class="admin-empty"><strong>Pelanggan tidak ditemukan</strong><p>Ubah filter pencarian.</p></div><?php else: ?>
<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Pelanggan</th><th>Pesanan</th><th>Selesai</th><th>Batal/Tolak</th><th>Total Transaksi</th><th>Status</th><th>Terdaftar</th><th>Aksi</th></tr></thead><tbody>
<?php foreach ($customers as $customer): ?><tr><td><div class="admin-customer-cell"><strong><?= e((string) $customer['name']) ?></strong><small><?= e((string) $customer['email']) ?></small><small><?= e((string) ($customer['phone'] ?: '-')) ?></small></div></td><td><strong><?= (int) $customer['order_count'] ?></strong></td><td><?= (int) $customer['completed_count'] ?></td><td><?= (int) $customer['cancelled_count'] ?> / <?= (int) $customer['rejected_count'] ?></td><td><strong><?= rupiah($customer['total_spent']) ?></strong></td><td><span class="admin-badge <?= $customer['status'] === 'active' ? 'status-complete' : 'status-rejected' ?>"><?= $customer['status'] === 'active' ? 'Aktif' : 'Nonaktif' ?></span></td><td><?= format_datetime((string) $customer['created_at']) ?></td><td><a class="admin-button admin-button-primary admin-button-small" href="customer_detail.php?id=<?= (int) $customer['id'] ?>">Detail</a></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
