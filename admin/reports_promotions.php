<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/report_helpers.php';

$period=report_period();$params=['from_start'=>$period['from_start'],'to_end'=>$period['to_end']];
$summary=report_fetch_one($pdo,"SELECT COUNT(*) AS usage_rows,
    SUM(pu.usage_status='USED') AS used_count,SUM(pu.usage_status='RESTORED') AS restored_count,
    COALESCE(SUM(CASE WHEN pu.usage_status='USED' THEN pu.discount_amount+pu.shipping_discount ELSE 0 END),0) AS active_discount,
    COALESCE(SUM(CASE WHEN pu.usage_status='USED' AND o.status='Selesai' THEN o.total_amount ELSE 0 END),0) AS completed_sales
    FROM promotion_usages pu INNER JOIN orders o ON o.id=pu.order_id
    WHERE pu.used_at BETWEEN :from_start AND :to_end",$params);
$rows=report_fetch_all($pdo,"SELECT p.id,p.name,p.code,p.trigger_type,p.discount_type,p.discount_value,p.total_usage_limit,p.current_usage,p.is_active,p.start_at,p.end_at,
    COUNT(pu.id) AS usage_rows,SUM(pu.usage_status='USED') AS used_count,SUM(pu.usage_status='RESTORED') AS restored_count,
    COALESCE(SUM(CASE WHEN pu.usage_status='USED' THEN pu.discount_amount ELSE 0 END),0) AS product_discount,
    COALESCE(SUM(CASE WHEN pu.usage_status='USED' THEN pu.shipping_discount ELSE 0 END),0) AS shipping_discount,
    COALESCE(SUM(CASE WHEN pu.usage_status='USED' AND o.status='Selesai' THEN o.total_amount ELSE 0 END),0) AS completed_sales
    FROM promotions p LEFT JOIN promotion_usages pu ON pu.promotion_id=p.id AND pu.used_at BETWEEN :from_start AND :to_end
    LEFT JOIN orders o ON o.id=pu.order_id
    GROUP BY p.id,p.name,p.code,p.trigger_type,p.discount_type,p.discount_value,p.total_usage_limit,p.current_usage,p.is_active,p.start_at,p.end_at
    ORDER BY used_count DESC,completed_sales DESC,p.created_at DESC",$params);
$pageTitle='Laporan Voucher & Promo';$activeMenu='reports';require __DIR__.'/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1>Laporan Voucher & Promo</h1><p>Pemakaian voucher, pengembalian kuota, nilai diskon, dan penjualan yang dihasilkan.</p></div><div class="admin-page-actions"><a class="admin-button admin-button-secondary" target="_blank" href="<?=e(report_print_url('promotions',$period))?>">Cetak</a><a class="admin-button admin-button-primary" href="<?=e(report_export_url('promotions',$period))?>">Ekspor CSV</a></div></div>
<?php report_nav('promotions',$period);report_filter_form($period); ?>
<section class="admin-grid report-kpi-grid">
<article class="admin-stat-card"><div class="admin-stat-icon">🎟️</div><div class="admin-stat-copy"><span>Penggunaan Aktif</span><strong><?= (int)($summary['used_count']??0) ?></strong><small>Pada periode filter</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">↩️</div><div class="admin-stat-copy"><span>Kuota Dikembalikan</span><strong><?= (int)($summary['restored_count']??0) ?></strong><small>Pesanan batal/ditolak</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">💸</div><div class="admin-stat-copy"><span>Total Diskon</span><strong><?=rupiah($summary['active_discount']??0)?></strong><small>Penggunaan berstatus USED</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">💰</div><div class="admin-stat-copy"><span>Penjualan Selesai</span><strong><?=rupiah($summary['completed_sales']??0)?></strong><small>Pesanan promo yang selesai</small></div></article>
</section>
<section class="admin-card report-section-gap"><div class="admin-card-head"><div><h2>Performa Setiap Promo</h2><small>Kuota saat ini ditampilkan dari konfigurasi promo.</small></div></div><?php if($rows===[]):?><div class="admin-empty"><strong>Belum ada promo</strong><p>Buat promo melalui menu Voucher & Promo.</p></div><?php else:?><div class="admin-table-wrap"><table class="admin-table report-table-wide"><thead><tr><th>Promo</th><th>Jenis</th><th>Periode</th><th>Digunakan</th><th>Dikembalikan</th><th>Kuota Saat Ini</th><th>Total Diskon</th><th>Penjualan</th><th>Status</th></tr></thead><tbody><?php foreach($rows as $row):$usagePct=$row['total_usage_limit']!==null?report_percent((int)$row['current_usage'],(int)$row['total_usage_limit']):0;?><tr><td><strong><?=e((string)$row['name'])?></strong><br><small><?=e((string)($row['code']?:'Otomatis'))?></small></td><td><?=e((string)$row['discount_type'])?><br><small><?=e((string)$row['trigger_type'])?></small></td><td><?=e(date('d/m/Y',strtotime((string)$row['start_at'])))?><br><small>s.d. <?=e(date('d/m/Y',strtotime((string)$row['end_at'])))?></small></td><td><?= (int)$row['used_count'] ?></td><td><?= (int)$row['restored_count'] ?></td><td><?php if($row['total_usage_limit']===null):?>Tanpa batas<?php else:?><strong><?= (int)$row['current_usage'] ?>/<?= (int)$row['total_usage_limit'] ?></strong><br><small><?=number_format($usagePct,1,',','.')?>%</small><?php endif;?></td><td><?=rupiah((float)$row['product_discount']+(float)$row['shipping_discount'])?></td><td><strong><?=rupiah($row['completed_sales'])?></strong></td><td><span class="admin-badge <?= (int)$row['is_active']===1?'status-complete':'status-neutral' ?>"><?= (int)$row['is_active']===1?'Aktif':'Nonaktif' ?></span></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<script src="assets/admin-reports.js"></script>
<?php require __DIR__.'/includes/admin_footer.php'; ?>
