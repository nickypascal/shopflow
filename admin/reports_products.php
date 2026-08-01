<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/report_helpers.php';

$period = report_period();
$categoryId = max(0, (int) ($_GET['category_id'] ?? 0));
$search = trim((string) ($_GET['search'] ?? ''));
$params = ['from_start'=>$period['from_start'],'to_end'=>$period['to_end']];
$where = ["o.status='Selesai'", 'o.created_at BETWEEN :from_start AND :to_end'];
if ($categoryId > 0) { $where[]='p.category_id=:category_id'; $params['category_id']=$categoryId; }
if ($search !== '') {
    $where[]='(p.name LIKE :search_product OR oi.product_name LIKE :search_snapshot OR oi.variant_name LIKE :search_variant OR oi.variant_sku LIKE :search_sku)';
    $searchValue='%'.$search.'%';
    $params['search_product']=$searchValue; $params['search_snapshot']=$searchValue;
    $params['search_variant']=$searchValue; $params['search_sku']=$searchValue;
}
$whereSql=implode(' AND ',$where);

$rows = report_fetch_all($pdo, "SELECT p.id AS product_id, oi.variant_id, oi.product_name,
        COALESCE(oi.variant_name,'') AS variant_name, COALESCE(oi.variant_sku,'') AS variant_sku,
        COALESCE(c.name,p.category) AS category_name,
        SUM(oi.quantity) AS sold_quantity, SUM(oi.subtotal) AS gross_sales,
        p.stock AS product_stock, p.low_stock_threshold, p.average_rating, p.review_count,
        (SELECT COUNT(*) FROM wishlist_items wi WHERE wi.product_id=p.id) AS wishlist_count,
        (SELECT COALESCE(SUM(ri.return_quantity),0) FROM return_items ri INNER JOIN return_requests rr ON rr.id=ri.return_request_id WHERE ri.product_id=p.id AND rr.status NOT IN ('REJECTED','CANCELLED')) AS returned_quantity
    FROM order_items oi
    INNER JOIN orders o ON o.id=oi.order_id
    INNER JOIN products p ON p.id=oi.product_id
    LEFT JOIN categories c ON c.id=p.category_id
    WHERE {$whereSql}
    GROUP BY p.id, oi.variant_id, oi.product_name, oi.variant_name, oi.variant_sku, c.name, p.category, p.stock, p.low_stock_threshold, p.average_rating, p.review_count
    ORDER BY sold_quantity DESC, gross_sales DESC LIMIT 200", $params);

$summary = report_fetch_one($pdo, "SELECT COUNT(DISTINCT oi.product_id) AS products_sold,
        COUNT(DISTINCT CONCAT(oi.product_id,'-',COALESCE(oi.variant_id,0))) AS variants_sold,
        COALESCE(SUM(oi.quantity),0) AS units_sold,
        COALESCE(SUM(oi.subtotal),0) AS gross_sales
    FROM order_items oi INNER JOIN orders o ON o.id=oi.order_id INNER JOIN products p ON p.id=oi.product_id
    WHERE {$whereSql}", $params);
$noSales = (int) report_fetch_one($pdo, "SELECT COUNT(*) AS total FROM products p WHERE p.status='active' AND NOT EXISTS (SELECT 1 FROM order_items oi INNER JOIN orders o ON o.id=oi.order_id WHERE oi.product_id=p.id AND o.status='Selesai' AND o.created_at BETWEEN :from_start AND :to_end)", ['from_start'=>$period['from_start'],'to_end'=>$period['to_end']])['total'];
$lowStock = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='active' AND stock<=low_stock_threshold")->fetchColumn() + (int)$pdo->query("SELECT COUNT(*) FROM product_variants WHERE is_active=1 AND stock<=low_stock_threshold")->fetchColumn();
$categories=$pdo->query("SELECT id,name FROM categories ORDER BY name")->fetchAll();

$pageTitle='Laporan Produk';$activeMenu='reports';require __DIR__.'/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1>Laporan Produk</h1><p>Performa produk dan varian berdasarkan pesanan selesai, wishlist, rating, dan retur.</p></div><div class="admin-page-actions"><a class="admin-button admin-button-secondary" target="_blank" href="<?=e(report_print_url('products',$period,['category_id'=>$categoryId,'search'=>$search]))?>">Cetak</a><a class="admin-button admin-button-primary" href="<?=e(report_export_url('products',$period,['category_id'=>$categoryId,'search'=>$search]))?>">Ekspor CSV</a></div></div>
<?php report_nav('products',$period); ?>
<?php $categoryOptions=['0'=>'Semua kategori'];foreach($categories as $category)$categoryOptions[(string)$category['id']]=$category['name'];report_filter_form($period,[['label'=>'Kategori','name'=>'category_id','value'=>(string)$categoryId,'options'=>$categoryOptions],['label'=>'Cari produk/varian','name'=>'search','value'=>$search,'type'=>'text','placeholder'=>'Nama, varian, atau SKU']]); ?>
<section class="admin-grid report-kpi-grid">
<article class="admin-stat-card"><div class="admin-stat-icon">🛍️</div><div class="admin-stat-copy"><span>Produk Terjual</span><strong><?= (int)($summary['products_sold']??0) ?></strong><small><?= (int)($summary['variants_sold']??0) ?> kombinasi produk/varian</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">📦</div><div class="admin-stat-copy"><span>Unit Terjual</span><strong><?= (int)($summary['units_sold']??0) ?></strong><small>Dari pesanan selesai</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">💰</div><div class="admin-stat-copy"><span>Penjualan Kotor</span><strong><?= rupiah($summary['gross_sales']??0) ?></strong><small>Sebelum pembagian diskon pesanan</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">🕳️</div><div class="admin-stat-copy"><span>Belum Terjual</span><strong><?= $noSales ?></strong><small>Produk aktif pada periode</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">⚠️</div><div class="admin-stat-copy"><span>Stok Perlu Perhatian</span><strong><?= $lowStock ?></strong><small>Produk dan varian</small></div></article>
</section>
<section class="admin-card report-section-gap"><div class="admin-card-head"><div><h2>Performa Produk dan Varian</h2><small>Maksimal 200 baris.</small></div></div>
<?php if($rows===[]):?><div class="admin-empty"><strong>Belum ada penjualan produk</strong><p>Ubah periode atau kategori.</p></div><?php else:?><div class="admin-table-wrap"><table class="admin-table report-table-wide"><thead><tr><th>Produk/Varian</th><th>Kategori</th><th>Terjual</th><th>Penjualan</th><th>Wishlist</th><th>Rating</th><th>Retur</th><th>Stok Produk</th></tr></thead><tbody>
<?php foreach($rows as $row):$returnRate=report_percent((int)$row['returned_quantity'],(int)$row['sold_quantity']);?><tr><td><strong><?=e((string)$row['product_name'])?></strong><?php if($row['variant_name']!==''):?><br><small><?=e((string)$row['variant_name'])?><?= $row['variant_sku']!==''?' · '.e((string)$row['variant_sku']):'' ?></small><?php endif;?></td><td><?=e((string)$row['category_name'])?></td><td><strong><?= (int)$row['sold_quantity'] ?></strong> unit</td><td><?=rupiah($row['gross_sales'])?></td><td><?= (int)$row['wishlist_count'] ?></td><td><?=number_format((float)$row['average_rating'],1,',','.')?> <small>(<?= (int)$row['review_count'] ?>)</small></td><td><?= (int)$row['returned_quantity'] ?> <small>(<?=number_format($returnRate,1,',','.')?>%)</small></td><td><span class="<?= (int)$row['product_stock']<=(int)$row['low_stock_threshold']?'admin-stock-low':'' ?>"><?= (int)$row['product_stock'] ?></span></td></tr><?php endforeach;?>
</tbody></table></div><?php endif;?></section>
<script src="assets/admin-reports.js"></script>
<?php require __DIR__.'/includes/admin_footer.php'; ?>
