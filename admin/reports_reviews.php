<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/report_helpers.php';

$period=report_period();$params=['from_start'=>$period['from_start'],'to_end'=>$period['to_end']];
$summary=report_fetch_one($pdo,"SELECT COUNT(*) AS total_reviews,
    COALESCE(AVG(CASE WHEN status='PUBLISHED' THEN rating END),0) AS average_rating,
    SUM(status='PENDING') AS pending_count,SUM(status='PUBLISHED') AS published_count,
    SUM(status='REJECTED') AS rejected_count,SUM(status='HIDDEN') AS hidden_count,
    SUM(admin_reply IS NOT NULL AND admin_reply<>'') AS replied_count
    FROM product_reviews WHERE created_at BETWEEN :from_start AND :to_end",$params);
$ratingRows=report_fetch_all($pdo,"SELECT rating,COUNT(*) AS total FROM product_reviews WHERE status='PUBLISHED' AND created_at BETWEEN :from_start AND :to_end GROUP BY rating ORDER BY rating DESC",$params);
$productRows=report_fetch_all($pdo,"SELECT p.id,p.name,
    COUNT(pr.id) AS review_count,COALESCE(AVG(pr.rating),0) AS average_rating,
    SUM(pr.rating=5) AS five_star,SUM(pr.rating<=2) AS low_rating,
    SUM(pr.admin_reply IS NOT NULL AND pr.admin_reply<>'') AS replied_count
    FROM product_reviews pr INNER JOIN products p ON p.id=pr.product_id
    WHERE pr.status='PUBLISHED' AND pr.created_at BETWEEN :from_start AND :to_end
    GROUP BY p.id,p.name ORDER BY average_rating DESC,review_count DESC LIMIT 100",$params);
$withoutReviews=(int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='active' AND review_count=0")->fetchColumn();
$pageTitle='Laporan Ulasan';$activeMenu='reports';require __DIR__.'/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1>Laporan Ulasan Produk</h1><p>Rating pelanggan, status moderasi, balasan admin, dan performa ulasan produk.</p></div><div class="admin-page-actions"><a class="admin-button admin-button-secondary" target="_blank" href="<?=e(report_print_url('reviews',$period))?>">Cetak</a><a class="admin-button admin-button-primary" href="<?=e(report_export_url('reviews',$period))?>">Ekspor CSV</a></div></div>
<?php report_nav('reviews',$period);report_filter_form($period); ?>
<section class="admin-grid report-kpi-grid">
<article class="admin-stat-card"><div class="admin-stat-icon">⭐</div><div class="admin-stat-copy"><span>Total Ulasan</span><strong><?= (int)($summary['total_reviews']??0) ?></strong><small>Pada periode filter</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">🌟</div><div class="admin-stat-copy"><span>Rating Rata-rata</span><strong><?=number_format((float)($summary['average_rating']??0),2,',','.')?></strong><small>Ulasan diterbitkan</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">⏳</div><div class="admin-stat-copy"><span>Menunggu Moderasi</span><strong><?= (int)($summary['pending_count']??0) ?></strong><small>Perlu ditinjau admin</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">💬</div><div class="admin-stat-copy"><span>Dibalas Admin</span><strong><?= (int)($summary['replied_count']??0) ?></strong><small>Dari seluruh ulasan periode</small></div></article>
<article class="admin-stat-card"><div class="admin-stat-icon">🕳️</div><div class="admin-stat-copy"><span>Produk Tanpa Ulasan</span><strong><?= $withoutReviews ?></strong><small>Produk aktif saat ini</small></div></article>
</section>
<section class="admin-grid admin-grid-2 report-section-gap"><article class="admin-card"><div class="admin-card-head"><h2>Distribusi Rating</h2></div><?php if($ratingRows===[]):?><div class="admin-empty"><strong>Belum ada rating</strong></div><?php else:?><div class="report-status-list"><?php foreach($ratingRows as $row):$pct=report_percent((int)$row['total'],(int)($summary['published_count']??0));?><div><div><span class="admin-review-stars"><?=str_repeat('★',(int)$row['rating']).str_repeat('☆',5-(int)$row['rating'])?></span><strong><?= (int)$row['total'] ?> · <?=number_format($pct,1,',','.')?>%</strong></div><div class="report-progress"><span style="width:<?=e(number_format($pct,2,'.',''))?>%"></span></div></div><?php endforeach;?></div><?php endif;?></article><article class="admin-card"><div class="admin-card-head"><h2>Status Moderasi</h2></div><div class="report-attention-grid report-attention-compact"><a href="reviews.php?status=PUBLISHED"><span>✅</span><strong><?= (int)($summary['published_count']??0) ?></strong><small>Diterbitkan</small></a><a href="reviews.php?status=PENDING"><span>⏳</span><strong><?= (int)($summary['pending_count']??0) ?></strong><small>Menunggu</small></a><a href="reviews.php?status=REJECTED"><span>❌</span><strong><?= (int)($summary['rejected_count']??0) ?></strong><small>Ditolak</small></a><a href="reviews.php?status=HIDDEN"><span>🙈</span><strong><?= (int)($summary['hidden_count']??0) ?></strong><small>Disembunyikan</small></a></div></article></section>
<section class="admin-card report-section-gap"><div class="admin-card-head"><div><h2>Performa Ulasan per Produk</h2><small>Maksimal 100 produk.</small></div></div><?php if($productRows===[]):?><div class="admin-empty"><strong>Belum ada ulasan diterbitkan</strong></div><?php else:?><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Produk</th><th>Rating</th><th>Ulasan</th><th>5 Bintang</th><th>Rating Rendah</th><th>Dibalas</th><th>Rasio Balasan</th></tr></thead><tbody><?php foreach($productRows as $row):?><tr><td><strong><?=e((string)$row['name'])?></strong></td><td><strong><?=number_format((float)$row['average_rating'],2,',','.')?></strong></td><td><?= (int)$row['review_count'] ?></td><td><?= (int)$row['five_star'] ?></td><td><?= (int)$row['low_rating'] ?></td><td><?= (int)$row['replied_count'] ?></td><td><?=number_format(report_percent((int)$row['replied_count'],(int)$row['review_count']),1,',','.')?>%</td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<script src="assets/admin-reports.js"></script>
<?php require __DIR__.'/includes/admin_footer.php'; ?>
