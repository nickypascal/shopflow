<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/report_helpers.php';

$period = report_period();
$type = trim((string)($_GET['movement_type'] ?? ''));
$stockStatus = report_safe_choice($_GET['stock_status'] ?? '', ['', 'safe', 'low', 'empty']);
$productId = max(0, (int)($_GET['product_id'] ?? 0));

$params=['from_start'=>$period['from_start'],'to_end'=>$period['to_end']];
$where=['sh.created_at BETWEEN :from_start AND :to_end'];
if($type!==''){ $where[]='sh.type=:type';$params['type']=$type; }
if($productId>0){$where[]='sh.product_id=:product_id';$params['product_id']=$productId;}
$whereSql=implode(' AND ',$where);

$movementSummary=report_fetch_one($pdo,"SELECT COUNT(*) AS movement_count,
    COALESCE(SUM(CASE WHEN stock_after>stock_before THEN quantity ELSE 0 END),0) AS stock_in,
    COALESCE(SUM(CASE WHEN stock_after<stock_before THEN quantity ELSE 0 END),0) AS stock_out
    FROM stock_histories sh WHERE {$whereSql}",$params);
$movements=report_fetch_all($pdo,"SELECT sh.*,p.name AS product_name,p.image,
    pv.variant_name,pv.sku,u.name AS actor_name
    FROM stock_histories sh INNER JOIN products p ON p.id=sh.product_id
    LEFT JOIN product_variants pv ON pv.id=sh.variant_id
    LEFT JOIN users u ON u.id=sh.created_by
    WHERE {$whereSql} ORDER BY sh.created_at DESC LIMIT 250",$params);

$inventoryWhere=[];$inventoryParams=[];
if($stockStatus==='safe')$inventoryWhere[]='stock > threshold';
if($stockStatus==='low')$inventoryWhere[]='stock > 0 AND stock <= threshold';
if($stockStatus==='empty')$inventoryWhere[]='stock = 0';
$inventorySql="SELECT * FROM (
    SELECT p.id AS product_id,NULL AS variant_id,p.name AS product_name,NULL AS variant_name,NULL AS sku,
        p.stock,p.low_stock_threshold AS threshold,p.status AS item_status,'product' AS item_type
    FROM products p WHERE p.has_variants=0
    UNION ALL
    SELECT p.id,pv.id,p.name,pv.variant_name,pv.sku,pv.stock,pv.low_stock_threshold,
        CASE WHEN pv.is_active=1 THEN 'active' ELSE 'inactive' END,'variant'
    FROM product_variants pv INNER JOIN products p ON p.id=pv.product_id
) inventory";
if($inventoryWhere)$inventorySql.=' WHERE '.implode(' AND ',$inventoryWhere);
$inventorySql.=' ORDER BY stock ASC,product_name,variant_name';
$inventory=report_fetch_all($pdo,$inventorySql,$inventoryParams);
$currentSummary=report_fetch_one($pdo,"SELECT COUNT(*) AS item_count,
    SUM(stock=0) AS empty_count,SUM(stock>0 AND stock<=threshold) AS low_count,
    COALESCE(SUM(stock),0) AS total_stock FROM (
    SELECT stock,low_stock_threshold AS threshold FROM products WHERE has_variants=0 AND status='active'
    UNION ALL SELECT stock,low_stock_threshold FROM product_variants WHERE is_active=1
) x");
$types=$pdo->query("SELECT DISTINCT type FROM stock_histories ORDER BY type")->fetchAll(PDO::FETCH_COLUMN);
$products=$pdo->query("SELECT id,name FROM products ORDER BY name")->fetchAll();

$pageTitle='Laporan Stok';$activeMenu='reports';require __DIR__.'/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1>Laporan Stok</h1><p>Kondisi persediaan saat ini dan riwayat seluruh pergerakan stok.</p></div><div class="admin-page-actions"><a class="admin-button admin-button-secondary" target="_blank" href="<?=e(report_print_url('stock',$period,['movement_type'=>$type,'stock_status'=>$stockStatus,'product_id'=>$productId]))?>">Cetak</a><a class="admin-button admin-button-primary" href="<?=e(report_export_url('stock',$period,['movement_type'=>$type,'stock_status'=>$stockStatus,'product_id'=>$productId]))?>">Ekspor CSV</a></div></div>
<?php report_nav('stock',$period); ?>
<?php $typeOptions=[''=>'Semua pergerakan'];foreach($types as $row)$typeOptions[(string)$row]=ucwords(str_replace('_',' ',(string)$row));$productOptions=['0'=>'Semua produk'];foreach($products as $row)$productOptions[(string)$row['id']]=$row['name'];report_filter_form($period,[['label'=>'Jenis pergerakan','name'=>'movement_type','value'=>$type,'options'=>$typeOptions],['label'=>'Status stok saat ini','name'=>'stock_status','value'=>$stockStatus,'options'=>[''=>'Semua stok','safe'=>'Aman','low'=>'Menipis','empty'=>'Habis']],['label'=>'Produk','name'=>'product_id','value'=>(string)$productId,'options'=>$productOptions]]); ?>
<section class="admin-grid report-kpi-grid">
<article class="admin-stat-card"><div class="admin-stat-icon">📦</div><div class="admin-stat-copy"><span>Total Persediaan</span><strong><?= (int)($currentSummary['total_stock']??0) ?></strong><small><?= (int)($currentSummary['item_count']??0) ?> produk/varian</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">⚠️</div><div class="admin-stat-copy"><span>Stok Menipis</span><strong><?= (int)($currentSummary['low_count']??0) ?></strong><small>Di bawah batas minimum</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">⛔</div><div class="admin-stat-copy"><span>Stok Habis</span><strong><?= (int)($currentSummary['empty_count']??0) ?></strong><small>Perlu penambahan</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">➕</div><div class="admin-stat-copy"><span>Stok Masuk</span><strong><?= (int)($movementSummary['stock_in']??0) ?></strong><small>Pada periode filter</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">➖</div><div class="admin-stat-copy"><span>Stok Keluar</span><strong><?= (int)($movementSummary['stock_out']??0) ?></strong><small>Pada periode filter</small></div></article>
</section>
<section class="admin-card report-section-gap"><div class="admin-card-head"><div><h2>Persediaan Saat Ini</h2><small>Produk tanpa varian dan semua varian aktif/nonaktif.</small></div></div><?php if($inventory===[]):?><div class="admin-empty"><strong>Tidak ada data stok</strong><p>Ubah filter status stok.</p></div><?php else:?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Produk/Varian</th><th>SKU</th><th>Stok</th><th>Batas Minimum</th><th>Kondisi</th></tr></thead><tbody><?php foreach($inventory as $row):$state=(int)$row['stock']===0?'Habis':((int)$row['stock']<=(int)$row['threshold']?'Menipis':'Aman');?><tr><td><strong><?=e((string)$row['product_name'])?></strong><?php if($row['variant_name']):?><br><small><?=e((string)$row['variant_name'])?></small><?php endif;?></td><td><?=e((string)($row['sku']??'-'))?></td><td><strong><?= (int)$row['stock'] ?></strong></td><td><?= (int)$row['threshold'] ?></td><td><span class="admin-badge <?= $state==='Aman'?'status-complete':($state==='Habis'?'status-rejected':'status-waiting') ?>"><?=e($state)?></span></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<section class="admin-card report-section-gap"><div class="admin-card-head"><div><h2>Riwayat Pergerakan Stok</h2><small>Maksimal 250 aktivitas terbaru pada periode.</small></div></div><?php if($movements===[]):?><div class="admin-empty"><strong>Belum ada pergerakan</strong><p>Tidak ada aktivitas stok pada filter ini.</p></div><?php else:?><div class="admin-table-wrap"><table class="admin-table report-table-wide"><thead><tr><th>Waktu</th><th>Produk/Varian</th><th>Jenis</th><th>Jumlah</th><th>Sebelum</th><th>Sesudah</th><th>Referensi</th><th>Pelaku</th></tr></thead><tbody><?php foreach($movements as $row):?><tr><td><?=e(format_datetime((string)$row['created_at']))?></td><td><strong><?=e((string)$row['product_name'])?></strong><?php if($row['variant_name']):?><br><small><?=e((string)$row['variant_name'])?> · <?=e((string)$row['sku'])?></small><?php endif;?></td><td><?=e(ucwords(str_replace('_',' ',(string)$row['type'])))?></td><td><?= (int)$row['quantity'] ?></td><td><?= (int)$row['stock_before'] ?></td><td><strong><?= (int)$row['stock_after'] ?></strong></td><td><?=e((string)($row['reference_type']??'-'))?><?=!empty($row['reference_id'])?' #'.(int)$row['reference_id']:''?></td><td><?=e((string)($row['actor_name']??'Sistem'))?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<script src="assets/admin-reports.js"></script>
<?php require __DIR__.'/includes/admin_footer.php'; ?>
