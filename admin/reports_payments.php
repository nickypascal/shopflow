<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/report_helpers.php';

$period=report_period();$params=['from_start'=>$period['from_start'],'to_end'=>$period['to_end']];
$rows=report_fetch_all($pdo,"SELECT payment_category,payment_method,payment_detail,
    COUNT(*) AS order_count,SUM(status='Selesai') AS completed_count,
    SUM(status='Dibatalkan') AS cancelled_count,SUM(status='Ditolak') AS rejected_count,
    COALESCE(SUM(CASE WHEN status='Selesai' THEN total_amount ELSE 0 END),0) AS completed_value
    FROM orders WHERE created_at BETWEEN :from_start AND :to_end
    GROUP BY payment_category,payment_method,payment_detail
    ORDER BY order_count DESC,completed_value DESC",$params);
$summary=report_fetch_one($pdo,"SELECT COUNT(*) AS total_orders,
    SUM(payment_method IN ('cod','cod_check')) AS cod_orders,
    SUM(payment_method NOT IN ('cod','cod_check')) AS simulated_online_orders,
    COALESCE(SUM(CASE WHEN status='Selesai' THEN total_amount ELSE 0 END),0) AS completed_value
    FROM orders WHERE created_at BETWEEN :from_start AND :to_end",$params);
$statusRows=report_fetch_all($pdo,"SELECT payment_status,COUNT(*) AS total FROM orders WHERE created_at BETWEEN :from_start AND :to_end GROUP BY payment_status ORDER BY total DESC",$params);
$pageTitle='Laporan Pembayaran';$activeMenu='reports';require __DIR__.'/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1>Laporan Pembayaran</h1><p>Analisis COD dan metode pembayaran online yang masih berupa simulasi.</p></div><div class="admin-page-actions"><a class="admin-button admin-button-secondary" target="_blank" href="<?=e(report_print_url('payments',$period))?>">Cetak</a><a class="admin-button admin-button-primary" href="<?=e(report_export_url('payments',$period))?>">Ekspor CSV</a></div></div>
<?php report_nav('payments',$period);report_filter_form($period); ?>
<div class="admin-alert admin-alert-warning"><strong>Catatan:</strong> DANA, OVO, GoPay, transfer bank, QRIS, dan kartu masih berstatus simulasi. COD dan COD Cek Dulu diproses melalui alur pesanan internal.</div>
<section class="admin-grid report-kpi-grid">
<article class="admin-stat-card"><div class="admin-stat-icon">💳</div><div class="admin-stat-copy"><span>Total Transaksi</span><strong><?= (int)($summary['total_orders']??0) ?></strong><small>Semua status pesanan</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">💵</div><div class="admin-stat-copy"><span>COD</span><strong><?= (int)($summary['cod_orders']??0) ?></strong><small>COD dan COD Cek Dulu</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">🧪</div><div class="admin-stat-copy"><span>Online Simulasi</span><strong><?= (int)($summary['simulated_online_orders']??0) ?></strong><small>Belum payment gateway nyata</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">✅</div><div class="admin-stat-copy"><span>Nilai Pesanan Selesai</span><strong><?=rupiah($summary['completed_value']??0)?></strong><small>Seluruh metode</small></div></article>
</section>
<section class="admin-grid admin-grid-2 report-section-gap"><article class="admin-card"><div class="admin-card-head"><h2>Metode Pembayaran</h2></div><?php if($rows===[]):?><div class="admin-empty"><strong>Belum ada transaksi</strong><p>Tidak ada data pada periode ini.</p></div><?php else:?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Metode</th><th>Pesanan</th><th>Selesai</th><th>Batal/Tolak</th><th>Nilai Selesai</th></tr></thead><tbody><?php foreach($rows as $row):?><tr><td><strong><?=e(payment_method_label((string)$row['payment_method'],(string)($row['payment_detail']??'')))?></strong><br><small><?=e(payment_category_label((string)$row['payment_category']))?><?=!is_cod_payment((string)$row['payment_method'])?' · Simulasi':''?></small></td><td><?= (int)$row['order_count'] ?></td><td><?= (int)$row['completed_count'] ?></td><td><?= (int)$row['cancelled_count']+(int)$row['rejected_count'] ?></td><td><strong><?=rupiah($row['completed_value'])?></strong></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></article>
<article class="admin-card"><div class="admin-card-head"><h2>Status Pembayaran</h2></div><?php if($statusRows===[]):?><div class="admin-empty"><strong>Belum ada status pembayaran</strong></div><?php else:?><div class="report-status-list"><?php foreach($statusRows as $row):$pct=report_percent((int)$row['total'],(int)($summary['total_orders']??0));?><div><div><span class="admin-badge <?=e(payment_status_css_class((string)$row['payment_status']))?>"><?=e((string)$row['payment_status'])?></span><strong><?= (int)$row['total'] ?> · <?=number_format($pct,1,',','.')?>%</strong></div><div class="report-progress"><span style="width:<?=e(number_format($pct,2,'.',''))?>%"></span></div></div><?php endforeach;?></div><?php endif;?></article></section>
<script src="assets/admin-reports.js"></script>
<?php require __DIR__.'/includes/admin_footer.php'; ?>
