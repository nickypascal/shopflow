<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/report_helpers.php';

$period=report_period();$search=trim((string)($_GET['search']??''));
$summaryParams=[
    'from_orders'=>$period['from_start'],'to_orders'=>$period['to_end'],
    'from_new'=>$period['from_start'],'to_new'=>$period['to_end'],
];
$rowParams=[
    'from_orders'=>$period['from_start'],'to_orders'=>$period['to_end'],
    'from_returns'=>$period['from_start'],'to_returns'=>$period['to_end'],
];
$searchSql='';if($search!==''){
    $searchSql=' AND (u.name LIKE :search_name OR u.email LIKE :search_email)';
    $searchValue='%'.$search.'%';
    $summaryParams['search_name']=$searchValue; $summaryParams['search_email']=$searchValue;
    $rowParams['search_name']=$searchValue; $rowParams['search_email']=$searchValue;
}
$summary=report_fetch_one($pdo,"SELECT
    (SELECT COUNT(*) FROM users WHERE role='customer') AS total_customers,
    (SELECT COUNT(*) FROM users WHERE role='customer' AND created_at BETWEEN :from_new AND :to_new) AS new_customers,
    COUNT(DISTINCT o.user_id) AS active_customers
    FROM orders o INNER JOIN users u ON u.id=o.user_id
    WHERE o.created_at BETWEEN :from_orders AND :to_orders {$searchSql}",$summaryParams);
$neverPurchased=(int)$pdo->query("SELECT COUNT(*) FROM users u WHERE u.role='customer' AND NOT EXISTS(SELECT 1 FROM orders o WHERE o.user_id=u.id)")->fetchColumn();
$rows=report_fetch_all($pdo,"SELECT u.id,u.name,u.email,u.status,u.created_at,
    COUNT(o.id) AS order_count,
    SUM(o.status='Selesai') AS completed_count,
    SUM(o.status='Dibatalkan') AS cancelled_count,
    SUM(o.status='Ditolak') AS rejected_count,
    COALESCE(SUM(CASE WHEN o.status='Selesai' THEN o.total_amount ELSE 0 END),0) AS total_spent,
    (SELECT COUNT(*) FROM return_requests rr WHERE rr.user_id=u.id AND rr.created_at BETWEEN :from_returns AND :to_returns) AS return_count
    FROM users u LEFT JOIN orders o ON o.user_id=u.id AND o.created_at BETWEEN :from_orders AND :to_orders
    WHERE u.role='customer' {$searchSql}
    GROUP BY u.id,u.name,u.email,u.status,u.created_at
    ORDER BY total_spent DESC,completed_count DESC,u.created_at DESC LIMIT 200",$rowParams);

$pageTitle='Laporan Pelanggan';$activeMenu='reports';require __DIR__.'/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1>Laporan Pelanggan</h1><p>Pelanggan baru, aktif, nilai belanja, pembatalan, penolakan, dan retur.</p></div><div class="admin-page-actions"><a class="admin-button admin-button-secondary" target="_blank" href="<?=e(report_print_url('customers',$period,['search'=>$search]))?>">Cetak</a><a class="admin-button admin-button-primary" href="<?=e(report_export_url('customers',$period,['search'=>$search]))?>">Ekspor CSV</a></div></div>
<?php report_nav('customers',$period);report_filter_form($period,[['label'=>'Cari pelanggan','name'=>'search','value'=>$search,'type'=>'text','placeholder'=>'Nama atau email']]); ?>
<section class="admin-grid report-kpi-grid">
<article class="admin-stat-card"><div class="admin-stat-icon">👥</div><div class="admin-stat-copy"><span>Total Pelanggan</span><strong><?= (int)($summary['total_customers']??0) ?></strong><small>Seluruh akun pelanggan</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">🆕</div><div class="admin-stat-copy"><span>Pelanggan Baru</span><strong><?= (int)($summary['new_customers']??0) ?></strong><small>Pada periode filter</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">🛒</div><div class="admin-stat-copy"><span>Pelanggan Aktif</span><strong><?= (int)($summary['active_customers']??0) ?></strong><small>Memiliki pesanan pada periode</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">💤</div><div class="admin-stat-copy"><span>Belum Pernah Belanja</span><strong><?= $neverPurchased ?></strong><small>Sepanjang waktu</small></div></article>
</section>
<section class="admin-card report-section-gap"><div class="admin-card-head"><div><h2>Performa Pelanggan</h2><small>Data pribadi sensitif seperti alamat tidak disertakan.</small></div></div><?php if($rows===[]):?><div class="admin-empty"><strong>Pelanggan tidak ditemukan</strong><p>Ubah kata pencarian atau periode.</p></div><?php else:?><div class="admin-table-wrap"><table class="admin-table report-table-wide"><thead><tr><th>Pelanggan</th><th>Terdaftar</th><th>Pesanan</th><th>Selesai</th><th>Total Belanja</th><th>Batal</th><th>Ditolak</th><th>Retur</th><th>Aksi</th></tr></thead><tbody><?php foreach($rows as $row):?><tr><td><strong><?=e((string)$row['name'])?></strong><br><small><?=e((string)$row['email'])?> · <?=e((string)$row['status'])?></small></td><td><?=e(date('d M Y',strtotime((string)$row['created_at'])))?></td><td><?= (int)$row['order_count'] ?></td><td><?= (int)$row['completed_count'] ?></td><td><strong><?=rupiah($row['total_spent'])?></strong></td><td><?= (int)$row['cancelled_count'] ?></td><td><?= (int)$row['rejected_count'] ?></td><td><?= (int)$row['return_count'] ?></td><td><a class="admin-button admin-button-primary admin-button-small" href="customer_detail.php?id=<?= (int)$row['id'] ?>">Detail</a></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<script src="assets/admin-reports.js"></script>
<?php require __DIR__.'/includes/admin_footer.php'; ?>
