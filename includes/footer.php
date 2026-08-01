<?php

$footerStoreName = isset($pdo) ? setting($pdo, 'store_name', 'ShopFlow') : 'ShopFlow';
$footerStoreLogo = isset($pdo) ? setting($pdo, 'store_logo', '') : '';
$footerTagline = isset($pdo)
    ? setting($pdo, 'footer_tagline', 'Belanja mudah, aman, dan terpercaya untuk kebutuhan Anda setiap hari.')
    : 'Belanja mudah, aman, dan terpercaya untuk kebutuhan Anda setiap hari.';
$footerEmail = isset($pdo) ? trim(setting($pdo, 'store_email', 'hello@shopflow.id')) : 'hello@shopflow.id';
$footerPhone = isset($pdo) ? trim(setting($pdo, 'store_phone', '+62 823-8415-6303')) : '+62 823-8415-6303';
$footerServiceHours = isset($pdo)
    ? trim(setting($pdo, 'service_hours', 'Senin–Sabtu, 08.00–20.00 WIB'))
    : 'Senin–Sabtu, 08.00–20.00 WIB';
$whatsAppDigits = preg_replace('/\D+/', '', $footerPhone) ?? '';
if (str_starts_with($whatsAppDigits, '0')) {
    $whatsAppDigits = '62' . substr($whatsAppDigits, 1);
}
?>
</main>

<footer class="site-footer">
    <div class="container footer-main">
        <section class="footer-brand-column" aria-label="Tentang <?= e($footerStoreName) ?>">
            <a class="brand brand-footer" href="index.php">
                <span class="brand-mark">
                    <?php if ($footerStoreLogo !== ''): ?>
                        <img src="<?= e($footerStoreLogo) ?>" alt="Logo <?= e($footerStoreName) ?>">
                    <?php else: ?>
                        <?= e(strtoupper(substr($footerStoreName, 0, 1))) ?>
                    <?php endif; ?>
                </span>
                <span><?= e($footerStoreName) ?></span>
            </a>
            <p class="footer-tagline"><?= e($footerTagline) ?></p>
            <div class="footer-trust-note">
                <span class="footer-trust-icon" aria-hidden="true">✓</span>
                <span>Belanja nyaman dengan layanan pelanggan yang responsif.</span>
            </div>
        </section>

        <nav class="footer-column" aria-label="Menu footer">
            <h2>Jelajahi</h2>
            <div class="footer-links">
                <a href="index.php">Produk</a>
                <a href="cart.php">Keranjang</a>
                <a href="wishlist.php">Wishlist</a>
                <a href="notifications.php">Notifikasi</a>
                <a href="orders.php">Pesanan</a>
                <a href="my_returns.php">Retur Saya</a>
            </div>
        </nav>

        <section class="footer-column footer-support" aria-label="Pusat bantuan">
            <div class="footer-heading-row">
                <div>
                    <span class="footer-eyebrow">Layanan pelanggan</span>
                    <h2>Pusat Bantuan</h2>
                </div>
                <span class="footer-support-badge">Siap membantu</span>
            </div>

            <div class="footer-contact-list">
                <?php if ($footerEmail !== ''): ?>
                    <a class="footer-contact-item" href="mailto:<?= e($footerEmail) ?>">
                        <span class="footer-contact-icon" aria-hidden="true">✉</span>
                        <span>
                            <small>Email</small>
                            <strong><?= e($footerEmail) ?></strong>
                        </span>
                    </a>
                <?php endif; ?>

                <?php if ($footerPhone !== ''): ?>
                    <a class="footer-contact-item" href="<?= $whatsAppDigits !== '' ? 'https://wa.me/' . e($whatsAppDigits) : '#' ?>" <?= $whatsAppDigits !== '' ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                        <span class="footer-contact-icon" aria-hidden="true">☎</span>
                        <span>
                            <small>WhatsApp</small>
                            <strong><?= e($footerPhone) ?></strong>
                        </span>
                    </a>
                <?php endif; ?>

                <?php if ($footerServiceHours !== ''): ?>
                    <div class="footer-contact-item footer-contact-static">
                        <span class="footer-contact-icon" aria-hidden="true">◷</span>
                        <span>
                            <small>Jam layanan</small>
                            <strong><?= e($footerServiceHours) ?></strong>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="container footer-lower">
        <p>&copy; <?= date('Y') ?> <?= e($footerStoreName) ?>. Semua hak dilindungi.</p>
        <div class="footer-legal-links">
            <a href="return_policy.php">Kebijakan Retur</a>
            <a href="admin/login.php">Portal Admin</a>
        </div>
    </div>
</footer>

<script>
    const toggle = document.querySelector('[data-nav-toggle]');
    const nav = document.querySelector('[data-nav]');
    toggle?.addEventListener('click', () => nav?.classList.toggle('open'));
</script>
<script src="assets/notifications.js" defer></script>
</body>

</html>