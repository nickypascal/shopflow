<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/report_helpers.php';

$period=report_period();$courier=trim((string)($_GET['courier']??''));
$params=['from_start'=>$period['from_start'],'to_end'=>$period['to_end']];$where=['created_at BETWEEN :from_start AND :to_end'];
if($courier!==''){$where[]='shipping_courier_code=:courier';$params['courier']=$courier;}$whereSql=implode(' AND ',$where);
$summary=report_fetch_one($pdo,"SELECT COUNT(*) AS order_count,
    SUM(status='Dikirim') AS shipped_count,SUM(status='Selesai') AS completed_count,
    SUM(status IN ('Diproses','Dikirim') AND (tracking_number IS NULL OR tracking_number='')) AS missing_tracking,
    COALESCE(SUM(shipping_cost),0) AS shipping_cost,
    COALESCE(SUM(shipping_discount),0) AS shipping_discount,
    COALESCE(SUM(cod_fee),0) AS cod_fee
    FROM orders WHERE {$whereSql}",$params);
$serviceRows=report_fetch_all($pdo,"SELECT COALESCE(shipping_courier_name,'Belum dipilih') AS courier_name,
    COALESCE(shipping_service_name,'-') AS service_name,COUNT(*) AS order_count,
    SUM(status='Selesai') AS completed_count,COALESCE(SUM(shipping_cost),0) AS shipping_cost,
    COALESCE(SUM(cod_fee),0) AS cod_fee
    FROM orders WHERE {$whereSql} GROUP BY shipping_courier_name,shipping_service_name ORDER BY order_count DESC",$params);
$destinationRows=report_fetch_all($pdo,"SELECT COALESCE(shipping_province,'-') AS province,COALESCE(shipping_city,'-') AS city,
    COUNT(*) AS order_count,COALESCE(SUM(shipping_cost),0) AS shipping_cost
    FROM orders WHERE {$whereSql} GROUP BY shipping_province,shipping_city ORDER BY order_count DESC LIMIT 20",$params);
$missingRows=report_fetch_all($pdo,"SELECT id,order_number,status,shipping_courier_name,shipping_service_name,created_at
    FROM orders WHERE {$whereSql} AND status IN ('Diproses','Dikirim') AND (tracking_number IS NULL OR tracking_number='')
    ORDER BY created_at ASC LIMIT 50",$params);
$couriers=$pdo->query("SELECT courier_code,courier_name FROM shipping_couriers ORDER BY courier_name")->fetchAll();
$pageTitle='Laporan Pengiriman';$activeMenu='reports';require __DIR__.'/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1>Laporan Pengiriman</h1><p>Kurir, layanan, ongkir, biaya COD, tujuan, dan kelengkapan nomor resi.</p></div><div class="admin-page-actions"><a class="admin-button admin-button-secondary" target="_blank" href="<?=e(report_print_url('shipping',$period,['courier'=>$courier]))?>">Cetak</a><a class="admin-button admin-button-primary" href="<?=e(report_export_url('shipping',$period,['courier'=>$courier]))?>">Ekspor CSV</a></div></div>
<?php report_nav('shipping',$period);$courierOptions=[''=>'Semua kurir'];foreach($couriers as $row)$courierOptions[(string)$row['courier_code']]=$row['courier_name'];report_filter_form($period,[['label'=>'Kurir','name'=>'courier','value'=>$courier,'options'=>$courierOptions]]); ?>
<section class="admin-grid report-kpi-grid">
<article class="admin-stat-card"><div class="admin-stat-icon">🚚</div><div class="admin-stat-copy"><span>Total Pengiriman</span><strong><?= (int)($summary['order_count']??0) ?></strong><small>Pesanan pada periode</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">📦</div><div class="admin-stat-copy"><span>Sedang Dikirim</span><strong><?= (int)($summary['shipped_count']??0) ?></strong><small>Status Dikirim</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">✅</div><div class="admin-stat-copy"><span>Selesai</span><strong><?= (int)($summary['completed_count']??0) ?></strong><small>Barang telah diterima</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">🧾</div><div class="admin-stat-copy"><span>Belum Ada Resi</span><strong><?= (int)($summary['missing_tracking']??0) ?></strong><small>Status Diproses/Dikirim</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">💰</div><div class="admin-stat-copy"><span>Ongkir Bersih</span><strong><?=rupiah(max(0,(float)($summary['shipping_cost']??0)-(float)($summary['shipping_discount']??0)))?></strong><small>Setelah diskon ongkir</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">💵</div><div class="admin-stat-copy"><span>Biaya COD</span><strong><?=rupiah($summary['cod_fee']??0)?></strong><small>Biaya COD terkumpul</small></div></article>
</section>
<section class="admin-grid admin-grid-2 report-section-gap"><article class="admin-card"><div class="admin-card-head"><h2>Kurir dan Layanan</h2></div><?php if($serviceRows===[]):?><div class="admin-empty"><strong>Belum ada data pengiriman</strong></div><?php else:?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Kurir/Layanan</th><th>Pesanan</th><th>Selesai</th><th>Ongkir</th><th>Biaya COD</th></tr></thead><tbody><?php foreach($serviceRows as $row):?><tr><td><strong><?=e((string)$row['courier_name'])?></strong><br><small><?=e((string)$row['service_name'])?></small></td><td><?= (int)$row['order_count'] ?></td><td><?= (int)$row['completed_count'] ?></td><td><?=rupiah($row['shipping_cost'])?></td><td><?=rupiah($row['cod_fee'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></article>
<article class="admin-card"><div class="admin-card-head"><h2>Tujuan Terbanyak</h2></div><?php if($destinationRows===[]):?><div class="admin-empty"><strong>Belum ada tujuan</strong></div><?php else:?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Tujuan</th><th>Pesanan</th><th>Ongkir</th></tr></thead><tbody><?php foreach($destinationRows as $row):?><tr><td><strong><?=e((string)$row['city'])?></strong><br><small><?=e((string)$row['province'])?></small></td><td><?= (int)$row['order_count'] ?></td><td><?=rupiah($row['shipping_cost'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></article></section>
<section class="admin-card report-section-gap"><div class="admin-card-head"><div><h2>Pesanan Belum Memiliki Resi</h2><small>Maksimal 50 pesanan.</small></div></div><?php if($missingRows===[]):?><div class="admin-empty"><strong>Semua resi sudah lengkap</strong><p>Tidak ada pesanan Diproses/Dikirim tanpa resi.</p></div><?php else:?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Pesanan</th><th>Status</th><th>Kurir</th><th>Dibuat</th><th>Aksi</th></tr></thead><tbody><?php foreach($missingRows as $row):?><tr><td><strong><?=e(order_code($row))?></strong></td><td><span class="admin-badge <?=e(status_css_class((string)$row['status']))?>"><?=e((string)$row['status'])?></span></td><td><?=e(trim((string)$row['shipping_courier_name'].' '.(string)$row['shipping_service_name']))?></td><td><?=e(format_datetime((string)$row['created_at']))?></td><td><a class="admin-button admin-button-primary admin-button-small" href="order_detail.php?id=<?= (int)$row['id'] ?>">Isi Resi</a></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<script src="assets/admin-reports.js"></script>
<?php require __DIR__.'/includes/admin_footer.php'; ?>
