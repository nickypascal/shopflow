<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/report_helpers.php';

$period=report_period();$params=['from_start'=>$period['from_start'],'to_end'=>$period['to_end']];
$summary=report_fetch_one($pdo,"SELECT COUNT(*) AS total_items,COUNT(DISTINCT user_id) AS customers,
    COUNT(DISTINCT product_id) AS products,SUM(purchased_at IS NOT NULL) AS purchased_items
    FROM wishlist_items WHERE created_at BETWEEN :from_start AND :to_end",$params);
$rows=report_fetch_all($pdo,"SELECT p.id,p.name,p.image,p.stock,p.status,p.price,p.average_rating,p.review_count,
    COUNT(wi.id) AS saved_count,COUNT(DISTINCT wi.user_id) AS customer_count,
    SUM(wi.purchased_at IS NOT NULL) AS purchased_count,
    COALESCE(AVG(wi.price_when_added),p.price) AS average_saved_price,
    MIN(wi.created_at) AS first_saved_at,MAX(wi.updated_at) AS last_activity_at
    FROM wishlist_items wi INNER JOIN products p ON p.id=wi.product_id
    WHERE wi.created_at BETWEEN :from_start AND :to_end
    GROUP BY p.id,p.name,p.image,p.stock,p.status,p.price,p.average_rating,p.review_count
    ORDER BY saved_count DESC,purchased_count DESC,last_activity_at DESC LIMIT 100",$params);
$stockAlerts=report_fetch_all($pdo,"SELECT p.id,p.name,p.stock,p.status,COUNT(wi.id) AS saved_count
    FROM wishlist_items wi INNER JOIN products p ON p.id=wi.product_id
    WHERE (p.stock=0 OR p.status<>'active') GROUP BY p.id,p.name,p.stock,p.status ORDER BY saved_count DESC LIMIT 20");
$conversion=report_percent((int)($summary['purchased_items']??0),(int)($summary['total_items']??0));
$pageTitle='Laporan Wishlist';$activeMenu='reports';require __DIR__.'/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1>Laporan Wishlist</h1><p>Minat pelanggan, produk favorit, perubahan harga, stok, dan konversi pembelian.</p></div><div class="admin-page-actions"><a class="admin-button admin-button-secondary" target="_blank" href="<?=e(report_print_url('wishlist',$period))?>">Cetak</a><a class="admin-button admin-button-primary" href="<?=e(report_export_url('wishlist',$period))?>">Ekspor CSV</a></div></div>
<?php report_nav('wishlist',$period);report_filter_form($period); ?>
<section class="admin-grid report-kpi-grid">
<article class="admin-stat-card"><div class="admin-stat-icon">♥</div><div class="admin-stat-copy"><span>Item Disimpan</span><strong><?= (int)($summary['total_items']??0) ?></strong><small>Pada periode filter</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">👥</div><div class="admin-stat-copy"><span>Pelanggan</span><strong><?= (int)($summary['customers']??0) ?></strong><small>Memiliki wishlist baru</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">🛍️</div><div class="admin-stat-copy"><span>Produk Diminati</span><strong><?= (int)($summary['products']??0) ?></strong><small>Produk unik</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">✅</div><div class="admin-stat-copy"><span>Ditandai Dibeli</span><strong><?= (int)($summary['purchased_items']??0) ?></strong><small>Konversi <?=number_format($conversion,1,',','.')?>%</small></div></article>
</section>
<section class="admin-card report-section-gap"><div class="admin-card-head"><div><h2>Produk Paling Diminati</h2><small>Konversi ditandai saat produk wishlist ikut dibeli pada checkout.</small></div></div><?php if($rows===[]):?><div class="admin-empty"><strong>Belum ada wishlist</strong><p>Tidak ada item baru pada periode ini.</p></div><?php else:?><div class="admin-table-wrap"><table class="admin-table report-table-wide"><thead><tr><th>Produk</th><th>Disimpan</th><th>Pelanggan</th><th>Dibeli</th><th>Konversi</th><th>Harga Saat Disimpan</th><th>Harga Kini</th><th>Stok</th><th>Rating</th></tr></thead><tbody><?php foreach($rows as $row):$rowConversion=report_percent((int)$row['purchased_count'],(int)$row['saved_count']);$difference=(float)$row['average_saved_price']-(float)$row['price'];?><tr><td><div class="admin-product-cell"><img src="../<?=e((string)$row['image'])?>" alt=""><div><strong><?=e((string)$row['name'])?></strong><small><?=e((string)$row['status'])?></small></div></div></td><td><?= (int)$row['saved_count'] ?></td><td><?= (int)$row['customer_count'] ?></td><td><?= (int)$row['purchased_count'] ?></td><td><?=number_format($rowConversion,1,',','.')?>%</td><td><?=rupiah($row['average_saved_price'])?></td><td><strong><?=rupiah($row['price'])?></strong><?php if($difference>0):?><br><small class="report-positive">Turun <?=rupiah($difference)?></small><?php elseif($difference<0):?><br><small class="report-negative">Naik <?=rupiah(abs($difference))?></small><?php endif;?></td><td><?= (int)$row['stock'] ?></td><td><?=number_format((float)$row['average_rating'],1,',','.')?> (<?= (int)$row['review_count'] ?>)</td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<section class="admin-card report-section-gap"><div class="admin-card-head"><h2>Produk Wishlist Tidak Tersedia</h2></div><?php if($stockAlerts===[]):?><div class="admin-empty"><strong>Semua produk wishlist tersedia</strong><p>Tidak ada produk habis atau nonaktif.</p></div><?php else:?><div class="admin-list"><?php foreach($stockAlerts as $row):?><a class="admin-list-item" href="product_form.php?id=<?= (int)$row['id'] ?>"><div class="admin-list-copy"><strong><?=e((string)$row['name'])?></strong><span><?= (int)$row['saved_count'] ?> pelanggan menyimpan · Status <?=e((string)$row['status'])?></span></div><span class="admin-badge <?= (int)$row['stock']===0?'status-rejected':'status-neutral' ?>"><?= (int)$row['stock']===0?'Stok Habis':'Nonaktif' ?></span></a><?php endforeach;?></div><?php endif;?></section>
<script src="assets/admin-reports.js"></script>
<?php require __DIR__.'/includes/admin_footer.php'; ?>
