<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/report_helpers.php';

$period=report_period();$params=['from_start'=>$period['from_start'],'to_end'=>$period['to_end']];
$summary=report_fetch_one($pdo,"SELECT COUNT(*) AS total_requests,
    SUM(status='COMPLETED') AS completed_count,SUM(status='REJECTED') AS rejected_count,
    SUM(status='CANCELLED') AS cancelled_count,SUM(status NOT IN ('COMPLETED','REJECTED','CANCELLED')) AS active_count,
    SUM(resolution_type='REFUND') AS refund_requests,SUM(resolution_type='REPLACEMENT') AS replacement_requests,
    COALESCE(SUM(CASE WHEN refund_status='COMPLETED' THEN refund_amount ELSE 0 END),0) AS refunded_amount
    FROM return_requests WHERE created_at BETWEEN :from_start AND :to_end",$params);
$itemSummary=report_fetch_one($pdo,"SELECT COALESCE(SUM(ri.return_quantity),0) AS returned_quantity,
    COALESCE(SUM(ri.restocked_quantity),0) AS restocked_quantity
    FROM return_items ri INNER JOIN return_requests rr ON rr.id=ri.return_request_id
    WHERE rr.created_at BETWEEN :from_start AND :to_end",$params);
$statusRows=report_fetch_all($pdo,"SELECT status,COUNT(*) AS total,COALESCE(SUM(CASE WHEN refund_status='COMPLETED' THEN refund_amount ELSE 0 END),0) AS refund_amount FROM return_requests WHERE created_at BETWEEN :from_start AND :to_end GROUP BY status ORDER BY total DESC",$params);
$productRows=report_fetch_all($pdo,"SELECT ri.product_name,COALESCE(ri.variant_name,'') AS variant_name,
    SUM(ri.return_quantity) AS returned_quantity,COUNT(DISTINCT rr.id) AS request_count,
    COALESCE(SUM(ri.approved_refund_amount),0) AS approved_amount
    FROM return_items ri INNER JOIN return_requests rr ON rr.id=ri.return_request_id
    WHERE rr.created_at BETWEEN :from_start AND :to_end AND rr.status NOT IN ('REJECTED','CANCELLED')
    GROUP BY ri.product_id,ri.variant_id,ri.product_name,ri.variant_name ORDER BY returned_quantity DESC,request_count DESC LIMIT 100",$params);
$reasonRows=report_fetch_all($pdo,"SELECT ri.reason,COUNT(DISTINCT rr.id) AS request_count,SUM(ri.return_quantity) AS returned_quantity FROM return_items ri INNER JOIN return_requests rr ON rr.id=ri.return_request_id WHERE rr.created_at BETWEEN :from_start AND :to_end GROUP BY ri.reason ORDER BY returned_quantity DESC LIMIT 20",$params);
$pageTitle='Laporan Retur';$activeMenu='reports';require __DIR__.'/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1>Laporan Retur Barang</h1><p>Status retur, refund, penggantian, alasan, produk, dan pengembalian stok.</p></div><div class="admin-page-actions"><a class="admin-button admin-button-secondary" target="_blank" href="<?=e(report_print_url('returns',$period))?>">Cetak</a><a class="admin-button admin-button-primary" href="<?=e(report_export_url('returns',$period))?>">Ekspor CSV</a></div></div>
<?php report_nav('returns',$period);report_filter_form($period); ?>
<section class="admin-grid report-kpi-grid">
<article class="admin-stat-card"><div class="admin-stat-icon">↩️</div><div class="admin-stat-copy"><span>Total Pengajuan</span><strong><?= (int)($summary['total_requests']??0) ?></strong><small><?= (int)($itemSummary['returned_quantity']??0) ?> produk diajukan</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">⏳</div><div class="admin-stat-copy"><span>Masih Diproses</span><strong><?= (int)($summary['active_count']??0) ?></strong><small>Belum selesai/ditolak/batal</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">✅</div><div class="admin-stat-copy"><span>Retur Selesai</span><strong><?= (int)($summary['completed_count']??0) ?></strong><small><?=number_format(report_percent((int)($summary['completed_count']??0),(int)($summary['total_requests']??0)),1,',','.')?>%</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">💸</div><div class="admin-stat-copy"><span>Refund Selesai</span><strong><?=rupiah($summary['refunded_amount']??0)?></strong><small><?= (int)($summary['refund_requests']??0) ?> pengajuan refund</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">🔄</div><div class="admin-stat-copy"><span>Penggantian Barang</span><strong><?= (int)($summary['replacement_requests']??0) ?></strong><small>Jenis penyelesaian replacement</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">📦</div><div class="admin-stat-copy"><span>Kembali ke Stok</span><strong><?= (int)($itemSummary['restocked_quantity']??0) ?></strong><small>Produk dinilai layak jual</small></div></article>
</section>
<section class="admin-grid admin-grid-2 report-section-gap"><article class="admin-card"><div class="admin-card-head"><h2>Status Retur</h2></div><?php if($statusRows===[]):?><div class="admin-empty"><strong>Belum ada retur</strong></div><?php else:?><div class="admin-list"><?php foreach($statusRows as $row):?><div class="admin-list-item"><span class="admin-badge <?=e(return_status_css_class((string)$row['status']))?>"><?=e(return_status_label((string)$row['status']))?></span><div style="text-align:right"><strong><?= (int)$row['total'] ?> pengajuan</strong><br><small>Refund <?=rupiah($row['refund_amount'])?></small></div></div><?php endforeach;?></div><?php endif;?></article><article class="admin-card"><div class="admin-card-head"><h2>Alasan Retur Terbanyak</h2></div><?php if($reasonRows===[]):?><div class="admin-empty"><strong>Belum ada alasan</strong></div><?php else:?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Alasan</th><th>Pengajuan</th><th>Produk</th></tr></thead><tbody><?php foreach($reasonRows as $row):?><tr><td><strong><?=e((string)$row['reason'])?></strong></td><td><?= (int)$row['request_count'] ?></td><td><?= (int)$row['returned_quantity'] ?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></article></section>
<section class="admin-card report-section-gap"><div class="admin-card-head"><div><h2>Produk Paling Sering Diretur</h2><small>Retur ditolak dan dibatalkan tidak dihitung.</small></div></div><?php if($productRows===[]):?><div class="admin-empty"><strong>Belum ada produk diretur</strong></div><?php else:?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Produk/Varian</th><th>Jumlah Produk</th><th>Pengajuan</th><th>Refund Disetujui</th></tr></thead><tbody><?php foreach($productRows as $row):?><tr><td><strong><?=e((string)$row['product_name'])?></strong><?php if($row['variant_name']!==''):?><br><small><?=e((string)$row['variant_name'])?></small><?php endif;?></td><td><?= (int)$row['returned_quantity'] ?></td><td><?= (int)$row['request_count'] ?></td><td><?=rupiah($row['approved_amount'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<script src="assets/admin-reports.js"></script>
<?php require __DIR__.'/includes/admin_footer.php'; ?>
