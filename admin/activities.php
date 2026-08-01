<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$search = trim((string) ($_GET['q'] ?? ''));
$type = trim((string) ($_GET['type'] ?? 'Semua'));
$date = trim((string) ($_GET['date'] ?? ''));
$types = $pdo->query('SELECT DISTINCT activity_type FROM admin_activity_logs ORDER BY activity_type')->fetchAll(PDO::FETCH_COLUMN);
$where=['1=1'];$params=[];
if($search!==''){$where[]='(a.description LIKE :search OR u.name LIKE :search)';$params['search']='%'.$search.'%';}
if($type!=='Semua'&&in_array($type,$types,true)){$where[]='a.activity_type=:type';$params['type']=$type;}
if($date!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){$where[]='DATE(a.created_at)=:date';$params['date']=$date;}
$stmt=$pdo->prepare('SELECT a.*,u.name admin_name,u.email admin_email FROM admin_activity_logs a INNER JOIN users u ON u.id=a.admin_id WHERE '.implode(' AND ',$where).' ORDER BY a.id DESC LIMIT 300');
$stmt->execute($params);$activities=$stmt->fetchAll();

$pageTitle='Riwayat Aktivitas';$activeMenu='activities';require __DIR__.'/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1>Riwayat Aktivitas Admin</h1><p>Audit perubahan pesanan, produk, stok, pelanggan, dan pengaturan.</p></div></div>
<div class="admin-filter-card"><form class="admin-filter-form" method="get" style="grid-template-columns:2fr 1fr 1fr auto"><label class="admin-field"><span>Pencarian</span><input class="admin-input" type="search" name="q" value="<?= e($search) ?>" placeholder="Deskripsi atau nama admin"></label><label class="admin-field"><span>Jenis aktivitas</span><select class="admin-select" name="type"><option value="Semua">Semua jenis</option><?php foreach($types as $item): ?><option value="<?= e((string)$item) ?>" <?= $type===$item?'selected':'' ?>><?= e((string)$item) ?></option><?php endforeach; ?></select></label><label class="admin-field"><span>Tanggal</span><input class="admin-input" type="date" name="date" value="<?= e($date) ?>"></label><button class="admin-button admin-button-primary" type="submit">Terapkan</button></form></div>
<?php if($activities===[]): ?><div class="admin-empty"><strong>Aktivitas tidak ditemukan</strong><p>Belum ada catatan yang sesuai filter.</p></div><?php else: ?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Waktu</th><th>Admin</th><th>Jenis</th><th>Deskripsi</th><th>Alamat IP</th></tr></thead><tbody><?php foreach($activities as $activity): ?><tr><td><?= format_datetime((string)$activity['created_at']) ?></td><td><div class="admin-customer-cell"><strong><?= e((string)$activity['admin_name']) ?></strong><small><?= e((string)$activity['admin_email']) ?></small></div></td><td><span class="admin-badge status-neutral"><?= e((string)$activity['activity_type']) ?></span></td><td><?= e((string)$activity['description']) ?></td><td><?= e((string)($activity['ip_address']?:'-')) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php require __DIR__.'/includes/admin_footer.php'; ?>
