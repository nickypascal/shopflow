<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$cart = get_cart_details($pdo);
$pageTitle = 'Keranjang Belanja';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header">
    <div class="container">
        <span class="eyebrow">Keranjang belanja</span>
        <h1>Periksa produk pilihan Anda.</h1>
        <p>Variasi, jumlah, harga, dan berat produk akan digunakan pada proses checkout.</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if ($cart['items'] === []): ?>
            <div class="empty-state">
                <div class="empty-icon">🛒</div>
                <h2>Keranjang masih kosong</h2>
                <p>Temukan produk yang Anda butuhkan dan tambahkan ke keranjang.</p>
                <a class="button button-primary" href="index.php#produk">Mulai Belanja</a>
            </div>
        <?php else: ?>
            <div class="cart-layout">
                <div class="cart-list">
                    <?php foreach ($cart['items'] as $item): ?>
                        <article class="cart-item">
                            <img src="<?= e((string) $item['image']) ?>" alt="<?= e((string) $item['name']) ?>">
                            <div class="cart-item-info">
                                <span class="category-label"><?= e((string) $item['category']) ?></span>
                                <h3><?= e((string) $item['name']) ?></h3>
                                <?php if ((int) $item['has_variants'] === 1): ?>
                                    <div class="cart-variant-info">
                                        <strong><?= e(variant_description($item)) ?></strong>
                                        <span>SKU: <?= e((string) $item['variant_sku']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <p><?= rupiah($item['price']) ?> per produk</p>
                                <p class="cart-weight-line">⚖ <?= e(weight_label((int) $item['weight_grams'])) ?> per produk</p>
                                <span class="stock <?= (int) $item['stock'] > 0 ? 'available' : 'unavailable' ?>">
                                    <?= (int) $item['stock'] > 0 ? 'Stok tersedia: ' . (int) $item['stock'] : 'Stok habis' ?>
                                </span>
                            </div>
                            <form class="quantity-form" method="post" action="cart_action.php">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="product_id" value="<?= (int) $item['product_id'] ?>">
                                <input type="hidden" name="variant_id" value="<?= (int) $item['variant_id'] ?>">
                                <label>
                                    <span>Jumlah</span>
                                    <input type="number" name="quantity" min="0" max="<?= max(0, (int) $item['stock']) ?>" value="<?= (int) $item['quantity'] ?>">
                                </label>
                                <button class="button button-secondary button-small" type="submit">Perbarui</button>
                            </form>
                            <div class="cart-item-total">
                                <strong><?= rupiah($item['subtotal']) ?></strong>
                                <small><?= e(weight_label((int) $item['total_weight_grams'])) ?></small>
                                <form method="post" action="cart_action.php">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="product_id" value="<?= (int) $item['product_id'] ?>">
                                    <input type="hidden" name="variant_id" value="<?= (int) $item['variant_id'] ?>">
                                    <button class="link-danger" type="submit">Hapus</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>

                    <form method="post" action="cart_action.php">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="clear">
                        <button class="button button-ghost" type="submit">Kosongkan Keranjang</button>
                    </form>
                </div>

                <aside class="summary-card">
                    <h2>Ringkasan Belanja</h2>
                    <div class="summary-row"><span>Subtotal</span><strong><?= rupiah($cart['subtotal']) ?></strong></div>
                    <div class="summary-row"><span>Total berat</span><strong><?= e(weight_label((int) $cart['total_weight_grams'])) ?></strong></div>
                    <div class="summary-row"><span>Ongkir</span><strong>Dihitung saat checkout</strong></div>
                    <div class="summary-divider"></div>
                    <div class="summary-row summary-total"><span>Subtotal</span><strong><?= rupiah($cart['subtotal']) ?></strong></div>
                    <a class="button button-primary button-full" href="checkout.php">Lanjut ke Checkout</a>
                    <a class="continue-link" href="index.php#produk">← Lanjut Belanja</a>
                </aside>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
