<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$adminBaseUrl = rtrim(
    str_replace('\\\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/shipping.php'))),
    '/'
);

$summary = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM shipping_couriers) AS couriers,
        (SELECT COUNT(*) FROM shipping_services) AS services,
        (SELECT COUNT(*) FROM shipping_rates) AS rates,
        (SELECT COUNT(*) FROM orders WHERE status = 'Diproses') AS ready_to_ship,
        (SELECT COUNT(*) FROM orders WHERE status = 'Dikirim') AS in_transit"
)->fetch();

$orders = $pdo->query(
    "SELECT id, order_number, shipping_name, shipping_city, shipping_courier_name,
            shipping_service_code, tracking_number, status, created_at
     FROM orders
     WHERE status IN ('Diproses', 'Dikirim')
     ORDER BY updated_at DESC
     LIMIT 10"
)->fetchAll();

$pageTitle = 'Pengiriman';
$activeMenu = 'shipping';
require __DIR__ . '/includes/admin_header.php';
?>

<div class="admin-page-head">
    <div>
        <h1>Pengiriman &amp; Ongkos Kirim</h1>
        <p>Kelola kurir, layanan, tarif tujuan, nomor resi, dan perjalanan paket.</p>
    </div>
    <div class="admin-page-actions">
        <a class="admin-button admin-button-primary"
           href="<?= e($adminBaseUrl . '/shipping_rate_form.php') ?>">
            + Tambah Tarif
        </a>
    </div>
</div>

<section class="admin-grid admin-grid-4" style="margin-bottom:18px">
    <article class="admin-stat-card">
        <div class="admin-stat-copy">
            <span>Kurir</span>
            <strong><?= (int) ($summary['couriers'] ?? 0) ?></strong>
        </div>
    </article>

    <article class="admin-stat-card">
        <div class="admin-stat-copy">
            <span>Layanan</span>
            <strong><?= (int) ($summary['services'] ?? 0) ?></strong>
        </div>
    </article>

    <article class="admin-stat-card">
        <div class="admin-stat-copy">
            <span>Tarif tujuan</span>
            <strong><?= (int) ($summary['rates'] ?? 0) ?></strong>
        </div>
    </article>

    <article class="admin-stat-card">
        <div class="admin-stat-copy">
            <span>Siap dikirim</span>
            <strong><?= (int) ($summary['ready_to_ship'] ?? 0) ?></strong>
        </div>
    </article>
</section>

<section class="admin-grid admin-grid-3" style="margin-bottom:18px">
    <a class="admin-card admin-shipping-nav-card"
       href="<?= e($adminBaseUrl . '/shipping_couriers.php') ?>"
       aria-label="Buka daftar kurir">
        <span aria-hidden="true">🚚</span>
        <div>
            <strong>Daftar Kurir</strong>
            <small>Aktifkan COD dan COD Cek Dulu.</small>
        </div>
    </a>

    <a class="admin-card admin-shipping-nav-card"
       href="<?= e($adminBaseUrl . '/shipping_services.php') ?>"
       aria-label="Buka layanan kurir">
        <span aria-hidden="true">⚡</span>
        <div>
            <strong>Layanan Kurir</strong>
            <small>Atur kode layanan dan estimasi.</small>
        </div>
    </a>

    <a class="admin-card admin-shipping-nav-card"
       href="<?= e($adminBaseUrl . '/shipping_rates.php') ?>"
       aria-label="Buka tarif ongkir">
        <span aria-hidden="true">💰</span>
        <div>
            <strong>Tarif Ongkir</strong>
            <small>Atur tarif berdasarkan tujuan.</small>
        </div>
    </a>
</section>

<section class="admin-card">
    <div class="admin-card-head">
        <h2>Pesanan Dalam Pengiriman</h2>
        <span><?= (int) ($summary['in_transit'] ?? 0) ?> sedang dikirim</span>
    </div>

    <?php if ($orders === []): ?>
        <div class="admin-empty">
            <strong>Belum ada pesanan pengiriman aktif</strong>
            <p>Pesanan Diproses dan Dikirim akan muncul di sini.</p>
        </div>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Pesanan</th>
                        <th>Penerima</th>
                        <th>Kurir</th>
                        <th>Resi</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td>
                            <strong><?= e(order_code($order)) ?></strong><br>
                            <small><?= format_datetime((string) $order['created_at']) ?></small>
                        </td>
                        <td>
                            <?= e((string) $order['shipping_name']) ?><br>
                            <small><?= e((string) $order['shipping_city']) ?></small>
                        </td>
                        <td>
                            <?= e((string) ($order['shipping_courier_name'] ?: '-')) ?>
                            <?= e((string) ($order['shipping_service_code'] ?: '')) ?>
                        </td>
                        <td><?= e((string) ($order['tracking_number'] ?: 'Belum diisi')) ?></td>
                        <td>
                            <span class="admin-badge <?= e(status_css_class((string) $order['status'])) ?>">
                                <?= e((string) $order['status']) ?>
                            </span>
                        </td>
                        <td>
                            <a class="admin-button admin-button-primary admin-button-small"
                               href="<?= e($adminBaseUrl . '/order_detail.php?id=' . (int) $order['id']) ?>">
                                Detail
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
