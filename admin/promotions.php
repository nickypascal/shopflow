<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$status = trim((string) ($_GET['status'] ?? ''));
$type = trim((string) ($_GET['type'] ?? ''));
$sql = "SELECT p.*,
               (SELECT COUNT(*) FROM promotion_usages pu WHERE pu.promotion_id = p.id AND pu.usage_status = 'USED') AS active_usage,
               (SELECT COALESCE(SUM(pu.discount_amount + pu.shipping_discount), 0) FROM promotion_usages pu WHERE pu.promotion_id = p.id AND pu.usage_status = 'USED') AS total_discount
        FROM promotions p WHERE 1=1";
$params = [];
if ($status === 'active') { $sql .= ' AND p.is_active = 1 AND p.start_at <= NOW() AND p.end_at >= NOW()'; }
elseif ($status === 'inactive') { $sql .= ' AND p.is_active = 0'; }
elseif ($status === 'expired') { $sql .= ' AND p.end_at < NOW()'; }
if (in_array($type, ['CODE','AUTOMATIC'], true)) { $sql .= ' AND p.trigger_type = :type'; $params['type'] = $type; }
$sql .= ' ORDER BY p.id DESC';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$promotions = $statement->fetchAll();

$pageTitle = 'Voucher & Promo';
$activeMenu = 'promotions';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head">
    <div><h1>Voucher & Promo</h1><p>Kelola voucher kode, promo otomatis, batas penggunaan, dan cakupan promo.</p></div>
    <a class="admin-button admin-button-primary" href="promotion_form.php">+ Tambah Promo</a>
</div>

<form class="admin-filter-bar" method="get">
    <select class="admin-select" name="status"><option value="">Semua status</option><option value="active" <?= $status==='active'?'selected':'' ?>>Aktif</option><option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Nonaktif</option><option value="expired" <?= $status==='expired'?'selected':'' ?>>Berakhir</option></select>
    <select class="admin-select" name="type"><option value="">Semua penerapan</option><option value="CODE" <?= $type==='CODE'?'selected':'' ?>>Voucher Kode</option><option value="AUTOMATIC" <?= $type==='AUTOMATIC'?'selected':'' ?>>Promo Otomatis</option></select>
    <button class="admin-button admin-button-secondary" type="submit">Terapkan Filter</button>
</form>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>Promo</th><th>Jenis</th><th>Nilai</th><th>Periode</th><th>Penggunaan</th><th>Total Diskon</th><th>Status</th><th>Aksi</th></tr></thead>
<tbody>
<?php if ($promotions === []): ?><tr><td colspan="8"><div class="admin-empty"><strong>Belum ada promo</strong><p>Tambahkan voucher atau promo otomatis pertama.</p></div></td></tr><?php endif; ?>
<?php foreach ($promotions as $promotion): ?>
<?php
$isRunning = (int)$promotion['is_active']===1 && strtotime((string)$promotion['start_at']) <= time() && strtotime((string)$promotion['end_at']) >= time();
$statusLabel = (int)$promotion['is_active']!==1 ? 'Nonaktif' : (strtotime((string)$promotion['end_at']) < time() ? 'Berakhir' : (strtotime((string)$promotion['start_at']) > time() ? 'Terjadwal' : 'Aktif'));
?>
<tr>
<td><strong><?= e((string)$promotion['name']) ?></strong><br><small><?= $promotion['code'] ? 'Kode: '.e((string)$promotion['code']) : 'Diterapkan otomatis' ?></small></td>
<td><?= e(promotion_trigger_label((string)$promotion['trigger_type'])) ?><br><small><?= e(promotion_discount_type_label((string)$promotion['discount_type'])) ?></small></td>
<td><strong><?= e(promotion_value_label($promotion)) ?></strong><?php if($promotion['maximum_discount']!==null):?><br><small>Maks. <?=rupiah($promotion['maximum_discount'])?></small><?php endif;?></td>
<td><?= date('d M Y',strtotime((string)$promotion['start_at'])) ?><br><small>s.d. <?= date('d M Y',strtotime((string)$promotion['end_at'])) ?></small></td>
<td><?= (int)$promotion['active_usage'] ?><?= $promotion['total_usage_limit']!==null ? ' / '.(int)$promotion['total_usage_limit'] : '' ?><br><small>Maks. <?= (int)$promotion['usage_limit_per_user'] ?> per akun</small></td>
<td><?= rupiah($promotion['total_discount']) ?></td>
<td><span class="admin-badge <?= $isRunning?'status-complete':($statusLabel==='Berakhir'?'status-rejected':'status-neutral') ?>"><?= e($statusLabel) ?></span></td>
<td><div class="admin-table-actions">
<a class="admin-button admin-button-primary admin-button-small" href="promotion_form.php?id=<?= (int)$promotion['id'] ?>">Edit</a>
<a class="admin-button admin-button-secondary admin-button-small" href="promotion_detail.php?id=<?= (int)$promotion['id'] ?>">Detail</a>
<?php if ($isRunning && empty($promotion['notification_sent_at'])): ?>
<form method="post" action="send_promotion_notification.php"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="promotion_id" value="<?=(int)$promotion['id']?>"><button class="admin-button admin-button-success admin-button-small" type="submit" data-confirm="Kirim notifikasi promo ini kepada pelanggan?">Umumkan</button></form>
<?php elseif (!empty($promotion['notification_sent_at'])): ?>
<span class="admin-badge status-complete">Diumumkan</span>
<?php endif; ?>
<form method="post" action="promotion_action.php"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="promotion_id" value="<?=(int)$promotion['id']?>"><input type="hidden" name="action" value="toggle"><button class="admin-button admin-button-warning admin-button-small" type="submit">Toggle</button></form>
<form method="post" action="promotion_action.php"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="promotion_id" value="<?=(int)$promotion['id']?>"><input type="hidden" name="action" value="delete"><button class="admin-button admin-button-danger admin-button-small" type="submit" data-confirm="Hapus promo ini? Promo yang sudah digunakan tidak dapat dihapus.">Hapus</button></form>
</div></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
