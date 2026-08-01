<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$user = current_user();
$statement = $pdo->query(
    "SELECT p.*,
            (SELECT GROUP_CONCAT(pr.name ORDER BY pr.name SEPARATOR ', ')
             FROM promotion_products pp INNER JOIN products pr ON pr.id = pp.product_id
             WHERE pp.promotion_id = p.id) AS product_names,
            (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
             FROM promotion_categories pc INNER JOIN categories c ON c.id = pc.category_id
             WHERE pc.promotion_id = p.id) AS category_names,
            (SELECT GROUP_CONCAT(pm.payment_method ORDER BY pm.payment_method SEPARATOR ',')
             FROM promotion_payment_methods pm WHERE pm.promotion_id = p.id) AS payment_methods,
            (SELECT GROUP_CONCAT(CONCAT(sc.courier_name, ' ', ss.service_code) ORDER BY sc.courier_name SEPARATOR ', ')
             FROM promotion_shipping_services ps
             INNER JOIN shipping_services ss ON ss.id = ps.shipping_service_id
             INNER JOIN shipping_couriers sc ON sc.id = ss.courier_id
             WHERE ps.promotion_id = p.id) AS shipping_names
     FROM promotions p
     WHERE p.is_active = 1 AND p.start_at <= NOW() AND p.end_at >= NOW()
     ORDER BY p.trigger_type = 'CODE' DESC, p.end_at ASC"
);
$promotions = $statement->fetchAll();

$usageCounts = [];
if ($user) {
    $usageStatement = $pdo->prepare(
        "SELECT promotion_id, COUNT(*) AS total
         FROM promotion_usages
         WHERE user_id = :user_id AND usage_status = 'USED'
         GROUP BY promotion_id"
    );
    $usageStatement->execute(['user_id' => (int) $user['id']]);
    foreach ($usageStatement->fetchAll() as $usage) {
        $usageCounts[(int) $usage['promotion_id']] = (int) $usage['total'];
    }
}

$pageTitle = 'Voucher & Promo';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header promo-page-header">
    <div class="container">
        <span class="eyebrow">Hemat lebih banyak</span>
        <h1>Voucher & Promo ShopFlow</h1>
        <p>Pilih voucher yang sesuai, lalu masukkan kodenya pada halaman checkout.</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if ($promotions === []): ?>
            <div class="empty-state"><div class="empty-icon">🎟️</div><h2>Belum ada promo aktif</h2><p>Promo baru akan tampil di halaman ini.</p><a class="button button-primary" href="index.php">Kembali Belanja</a></div>
        <?php else: ?>
            <div class="promo-grid">
                <?php foreach ($promotions as $promotion): ?>
                    <?php
                    $usage = $usageCounts[(int) $promotion['id']] ?? 0;
                    $remainingUser = max(0, (int) $promotion['usage_limit_per_user'] - $usage);
                    $quotaText = $promotion['total_usage_limit'] === null
                        ? 'Kuota tidak dibatasi'
                        : max(0, (int) $promotion['total_usage_limit'] - (int) $promotion['current_usage']) . ' kuota tersisa';
                    ?>
                    <article class="promo-card">
                        <div class="promo-card-top">
                            <span class="promo-type-badge"><?= e(promotion_trigger_label((string) $promotion['trigger_type'])) ?></span>
                            <span class="promo-expiry">s.d. <?= date('d M Y', strtotime((string) $promotion['end_at'])) ?></span>
                        </div>
                        <div class="promo-value"><?= e(promotion_value_label($promotion)) ?></div>
                        <h2><?= e((string) $promotion['name']) ?></h2>
                        <p><?= e((string) ($promotion['description'] ?: 'Promo khusus pelanggan ShopFlow.')) ?></p>

                        <?php if ((string) $promotion['trigger_type'] === 'CODE'): ?>
                            <div class="promo-code-row"><code><?= e((string) $promotion['code']) ?></code><span>Masukkan saat checkout</span></div>
                        <?php else: ?>
                            <div class="promo-auto-row">✨ Diterapkan otomatis jika menjadi promo terbaik.</div>
                        <?php endif; ?>

                        <div class="promo-rules">
                            <div><span>Minimal belanja</span><strong><?= rupiah($promotion['minimum_purchase']) ?></strong></div>
                            <?php if ($promotion['maximum_discount'] !== null): ?><div><span>Maksimal diskon</span><strong><?= rupiah($promotion['maximum_discount']) ?></strong></div><?php endif; ?>
                            <?php if ($promotion['maximum_shipping_discount'] !== null): ?><div><span>Maks. potongan ongkir</span><strong><?= rupiah($promotion['maximum_shipping_discount']) ?></strong></div><?php endif; ?>
                            <div><span>Pemakaian akun</span><strong><?= $remainingUser ?> kali tersisa</strong></div>
                            <div><span>Kuota promo</span><strong><?= e($quotaText) ?></strong></div>
                        </div>

                        <?php if (trim((string) $promotion['category_names']) !== ''): ?><p class="promo-scope"><strong>Kategori:</strong> <?= e((string) $promotion['category_names']) ?></p><?php endif; ?>
                        <?php if (trim((string) $promotion['product_names']) !== ''): ?><p class="promo-scope"><strong>Produk:</strong> <?= e((string) $promotion['product_names']) ?></p><?php endif; ?>
                        <?php if (trim((string) $promotion['shipping_names']) !== ''): ?><p class="promo-scope"><strong>Pengiriman:</strong> <?= e((string) $promotion['shipping_names']) ?></p><?php endif; ?>

                        <a class="button button-primary button-full" href="<?= $user ? 'checkout.php' : 'login.php?redirect=checkout.php' ?>"><?= $user ? 'Gunakan di Checkout' : 'Login untuk Menggunakan' ?></a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
