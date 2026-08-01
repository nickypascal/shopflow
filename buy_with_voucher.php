<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $productId = max(0, (int) ($_POST['product_id'] ?? 0));
    $variantId = max(0, (int) ($_POST['variant_id'] ?? 0));
    $quantity = max(1, min(999, (int) ($_POST['quantity'] ?? 1)));

    redirect('buy_with_voucher.php?' . http_build_query([
        'product_id' => $productId,
        'variant_id' => $variantId,
        'quantity' => $quantity,
    ]));
}

$productId = max(0, (int) ($_GET['product_id'] ?? $_GET['id'] ?? 0));
$variantId = max(0, (int) ($_GET['variant_id'] ?? 0));
$quantity = max(1, min(999, (int) ($_GET['quantity'] ?? 1)));

if ($productId <= 0) {
    flash('warning', 'Produk yang dipilih tidak valid.');
    redirect('index.php#produk');
}

$returnTo = 'buy_with_voucher.php?' . http_build_query([
    'product_id' => $productId,
    'variant_id' => $variantId,
    'quantity' => $quantity,
]);

if (!is_logged_in() || is_admin()) {
    flash('warning', 'Silakan login sebagai pengguna untuk memilih voucher dan membeli produk.');
    redirect('login.php?redirect=' . urlencode($returnTo));
}

$productStatement = $pdo->prepare(
    "SELECT id, name, description, category, category_id, price, stock, image, status,
            has_variants, default_weight_grams
     FROM products
     WHERE id = :id AND status = 'active'
     LIMIT 1"
);
$productStatement->execute(['id' => $productId]);
$product = $productStatement->fetch();

if (!$product) {
    flash('warning', 'Produk tidak ditemukan atau sudah tidak aktif.');
    redirect('index.php#produk');
}

$hasVariants = (int) $product['has_variants'] === 1;
$variant = null;
if ($hasVariants) {
    if ($variantId <= 0) {
        flash('warning', 'Silakan pilih variasi produk terlebih dahulu.');
        redirect('product_detail.php?id=' . $productId . '&purchase=voucher#purchase');
    }

    $variantStatement = $pdo->prepare(
        "SELECT id, sku, variant_name, color, size, material, price, stock,
                weight_grams, image, is_active
         FROM product_variants
         WHERE id = :id AND product_id = :product_id AND is_active = 1
         LIMIT 1"
    );
    $variantStatement->execute([
        'id' => $variantId,
        'product_id' => $productId,
    ]);
    $variant = $variantStatement->fetch();

    if (!$variant) {
        flash('warning', 'Variasi produk tidak tersedia.');
        redirect('product_detail.php?id=' . $productId . '&purchase=voucher#purchase');
    }
} else {
    $variantId = 0;
}

$stock = $hasVariants ? (int) $variant['stock'] : (int) $product['stock'];
if ($stock <= 0) {
    flash('warning', 'Stok produk sedang habis.');
    redirect('product_detail.php?id=' . $productId . '#purchase');
}
$quantity = min($quantity, $stock);
$price = $hasVariants ? (float) $variant['price'] : (float) $product['price'];
$weight = $hasVariants ? (int) $variant['weight_grams'] : (int) $product['default_weight_grams'];
$image = $hasVariants && trim((string) ($variant['image'] ?? '')) !== ''
    ? (string) $variant['image']
    : (string) $product['image'];
$subtotal = $price * $quantity;

$itemForPromotion = [
    'id' => $productId,
    'product_id' => $productId,
    'category_id' => (int) ($product['category_id'] ?? 0),
    'subtotal' => $subtotal,
];

$voucherStatement = $pdo->query(
    "SELECT * FROM promotions
     WHERE code IS NOT NULL
       AND TRIM(code) <> ''
       AND is_active = 1
       AND start_at <= NOW()
       AND end_at >= NOW()
     ORDER BY minimum_purchase ASC, id DESC"
);

$vouchers = [];
foreach ($voucherStatement->fetchAll() as $promotion) {
    $rules = get_promotion_rules($pdo, (int) $promotion['id']);
    $eligibleSubtotal = promotion_eligible_subtotal([$itemForPromotion], $rules);
    if ($eligibleSubtotal <= 0) {
        continue;
    }

    $usageCount = promotion_user_usage_count(
        $pdo,
        (int) $promotion['id'],
        (int) current_user()['id']
    );
    $perUserLimit = max(1, (int) ($promotion['usage_limit_per_user'] ?? 1));
    $quotaAvailable = $promotion['total_usage_limit'] === null
        || (int) $promotion['current_usage'] < (int) $promotion['total_usage_limit'];
    $usageAvailable = $usageCount < $perUserLimit;

    $vouchers[] = [
        'id' => (int) $promotion['id'],
        'name' => (string) $promotion['name'],
        'code' => (string) $promotion['code'],
        'description' => (string) ($promotion['description'] ?? ''),
        'discount_type' => (string) $promotion['discount_type'],
        'discount_value' => (float) $promotion['discount_value'],
        'maximum_discount' => $promotion['maximum_discount'] === null
            ? null : (float) $promotion['maximum_discount'],
        'maximum_shipping_discount' => $promotion['maximum_shipping_discount'] === null
            ? null : (float) $promotion['maximum_shipping_discount'],
        'minimum_purchase' => (float) $promotion['minimum_purchase'],
        'quota_available' => $quotaAvailable,
        'usage_available' => $usageAvailable,
        'has_payment_rule' => ($rules['payment_methods'] ?? []) !== [],
        'has_shipping_rule' => ($rules['shipping_service_ids'] ?? []) !== [],
    ];
}

$pageTitle = 'Beli dengan Voucher';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header voucher-buy-header">
    <div class="container">
        <span class="eyebrow">Belanja lebih hemat</span>
        <h1>Pilih voucher, lalu lanjutkan Beli Sekarang.</h1>
        <p>Produk akan diproses sebagai pembelian langsung dan tidak mencampur isi keranjang Anda.</p>
    </div>
</section>

<section class="section">
    <div class="container voucher-buy-layout">
        <section class="voucher-buy-product-card">
            <img src="<?= e($image) ?>" alt="<?= e((string) $product['name']) ?>">
            <div>
                <span class="eyebrow">Produk dipilih</span>
                <h2><?= e((string) $product['name']) ?></h2>
                <?php if ($hasVariants): ?>
                    <p class="voucher-selected-variant"><?= e((string) $variant['variant_name']) ?> · SKU <?= e((string) $variant['sku']) ?></p>
                <?php endif; ?>
                <div class="voucher-product-facts">
                    <span>Harga <strong><?= rupiah($price) ?></strong></span>
                    <span>Stok <strong><?= $stock ?> unit</strong></span>
                    <span>Berat <strong><?= e(weight_label($weight)) ?></strong></span>
                </div>
                <a class="product-detail-link" href="product_detail.php?id=<?= $productId ?>#purchase">Ubah produk atau variasi →</a>
            </div>
        </section>

        <form class="voucher-buy-form" method="post" action="buy_now_action.php" id="voucher-buy-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="product_id" value="<?= $productId ?>">
            <input type="hidden" name="variant_id" value="<?= $variantId ?>">

            <section class="voucher-buy-section">
                <div class="checkout-section-title">
                    <div><span class="checkout-step-number">1</span><div><h2>Tentukan Jumlah</h2><p>Jumlah akan dihitung ulang bersama nilai voucher.</p></div></div>
                </div>
                <label class="purchase-quantity voucher-quantity-field">
                    <span>Jumlah produk</span>
                    <input id="voucher-quantity" type="number" name="quantity" min="1" max="<?= $stock ?>" value="<?= $quantity ?>" required>
                </label>
            </section>

            <section class="voucher-buy-section">
                <div class="checkout-section-title">
                    <div><span class="checkout-step-number">2</span><div><h2>Pilih Voucher</h2><p>Voucher akan diverifikasi kembali setelah pembayaran dan kurir dipilih.</p></div></div>
                </div>

                <?php if ($vouchers === []): ?>
                    <div class="shipping-empty-state">
                        <strong>Belum ada voucher yang sesuai untuk produk ini.</strong>
                        <span>Admin dapat menambahkan voucher melalui menu Voucher & Promo.</span>
                    </div>
                <?php else: ?>
                    <div class="voucher-choice-list">
                        <?php foreach ($vouchers as $voucher): ?>
                            <?php $available = $voucher['quota_available'] && $voucher['usage_available']; ?>
                            <label class="voucher-choice-card <?= !$available ? 'is-disabled' : '' ?>">
                                <input
                                    type="radio"
                                    name="voucher_code"
                                    value="<?= e($voucher['code']) ?>"
                                    data-type="<?= e($voucher['discount_type']) ?>"
                                    data-value="<?= e((string) $voucher['discount_value']) ?>"
                                    data-maximum="<?= e((string) ($voucher['maximum_discount'] ?? 0)) ?>"
                                    data-shipping-maximum="<?= e((string) ($voucher['maximum_shipping_discount'] ?? 0)) ?>"
                                    data-minimum="<?= e((string) $voucher['minimum_purchase']) ?>"
                                    <?= !$available ? 'disabled' : '' ?>
                                >
                                <span class="voucher-choice-check">✓</span>
                                <span class="voucher-choice-copy">
                                    <span class="voucher-code-label"><?= e($voucher['code']) ?></span>
                                    <strong><?= e($voucher['name']) ?></strong>
                                    <small><?= e($voucher['description']) ?></small>
                                    <span class="voucher-choice-meta">
                                        <?= e(promotion_value_label($voucher)) ?>
                                        · Min. <?= rupiah($voucher['minimum_purchase']) ?>
                                    </span>
                                    <?php if ($voucher['has_payment_rule'] || $voucher['has_shipping_rule']): ?>
                                        <em>Berlaku pada pembayaran atau layanan pengiriman tertentu.</em>
                                    <?php endif; ?>
                                    <?php if (!$voucher['quota_available']): ?><em class="voucher-unavailable">Kuota voucher habis.</em><?php endif; ?>
                                    <?php if (!$voucher['usage_available']): ?><em class="voucher-unavailable">Batas penggunaan akun tercapai.</em><?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <label class="manual-voucher-field">
                    <span>Atau masukkan kode voucher</span>
                    <input type="text" name="manual_voucher_code" id="manual-voucher-code" maxlength="50" autocomplete="off" placeholder="Contoh: SHOPFLOW10">
                </label>
                <div class="voucher-selection-message" id="voucher-selection-message" aria-live="polite">Pilih salah satu voucher untuk melanjutkan.</div>
            </section>

            <section class="voucher-buy-summary">
                <div><span>Harga satuan</span><strong id="voucher-unit-price"><?= rupiah($price) ?></strong></div>
                <div><span>Subtotal</span><strong id="voucher-subtotal"><?= rupiah($subtotal) ?></strong></div>
                <div><span>Perkiraan potongan</span><strong id="voucher-discount">-Rp 0</strong></div>
                <div class="voucher-total-row"><span>Perkiraan setelah voucher</span><strong id="voucher-total"><?= rupiah($subtotal) ?></strong></div>
                <small>Ongkos kirim dan potongan gratis ongkir dihitung pada halaman checkout.</small>
            </section>

            <button class="button button-primary button-full voucher-buy-now-button" id="voucher-buy-now" type="submit" disabled>
                Beli Sekarang
            </button>
        </form>
    </div>
</section>

<script>
(() => {
    const price = <?= json_encode($price) ?>;
    const quantity = document.getElementById('voucher-quantity');
    const radios = [...document.querySelectorAll('input[name="voucher_code"]')];
    const manual = document.getElementById('manual-voucher-code');
    const button = document.getElementById('voucher-buy-now');
    const subtotalText = document.getElementById('voucher-subtotal');
    const discountText = document.getElementById('voucher-discount');
    const totalText = document.getElementById('voucher-total');
    const message = document.getElementById('voucher-selection-message');

    const money = (value) => new Intl.NumberFormat('id-ID', {
        style: 'currency', currency: 'IDR', maximumFractionDigits: 0
    }).format(Number(value || 0));

    function selectedVoucher() {
        return document.querySelector('input[name="voucher_code"]:checked');
    }

    function update() {
        const qty = Math.max(1, Number(quantity?.value || 1));
        const subtotal = price * qty;
        const selected = selectedVoucher();
        const manualCode = String(manual?.value || '').trim();
        let discount = 0;
        let validSelection = false;
        let info = 'Pilih salah satu voucher untuk melanjutkan.';

        subtotalText.textContent = money(subtotal);

        if (manualCode !== '') {
            validSelection = manualCode.length >= 3;
            info = validSelection
                ? 'Kode voucher akan diverifikasi saat Beli Sekarang ditekan.'
                : 'Kode voucher minimal 3 karakter.';
        } else if (selected) {
            const minimum = Number(selected.dataset.minimum || 0);
            if (subtotal < minimum) {
                info = `Minimal pembelian voucher ini ${money(minimum)}.`;
            } else {
                validSelection = true;
                const type = selected.dataset.type;
                const value = Number(selected.dataset.value || 0);
                const maximum = Number(selected.dataset.maximum || 0);

                if (type === 'PERCENTAGE') {
                    discount = subtotal * value / 100;
                    if (maximum > 0) discount = Math.min(discount, maximum);
                } else if (type === 'FIXED_AMOUNT') {
                    discount = Math.min(value, subtotal);
                } else if (type === 'FREE_SHIPPING') {
                    info = 'Voucher gratis ongkir dipastikan setelah kurir dipilih.';
                }

                if (type !== 'FREE_SHIPPING') {
                    info = `Perkiraan potongan ${money(discount)}.`;
                }
            }
        }

        discountText.textContent = `-${money(discount)}`;
        totalText.textContent = money(Math.max(0, subtotal - discount));
        message.textContent = info;
        message.classList.toggle('is-success', validSelection);
        button.disabled = !validSelection;
    }

    radios.forEach((radio) => radio.addEventListener('change', () => {
        if (manual) manual.value = '';
        update();
    }));
    manual?.addEventListener('input', () => {
        if (manual.value.trim() !== '') {
            radios.forEach((radio) => { radio.checked = false; });
        }
        update();
    });
    quantity?.addEventListener('input', update);
    update();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
