<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$isDirectPurchase = is_array($_SESSION['direct_purchase'] ?? null);
$cart = $isDirectPurchase
    ? get_direct_purchase_details($pdo, $_SESSION['direct_purchase'])
    : get_cart_details($pdo);

if ($cart['items'] === []) {
    if ($isDirectPurchase) {
        clear_direct_purchase();
        unset($_SESSION['voucher_code']);
        flash('warning', 'Produk pembelian langsung tidak tersedia lagi. Silakan pilih produk kembali.');
        redirect('index.php#produk');
    }

    flash('warning', 'Keranjang Anda masih kosong.');
    redirect('cart.php');
}

$paymentMethods = [
    'dana' => ['category' => 'e_wallet', 'label' => 'DANA'],
    'ovo' => ['category' => 'e_wallet', 'label' => 'OVO'],
    'gopay' => ['category' => 'e_wallet', 'label' => 'GoPay'],
    'seabank' => ['category' => 'transfer_bank', 'label' => 'SeaBank'],
    'mandiri' => ['category' => 'transfer_bank', 'label' => 'Bank Mandiri'],
    'bca' => ['category' => 'transfer_bank', 'label' => 'BCA'],
    'bri' => ['category' => 'transfer_bank', 'label' => 'BRI'],
    'bni' => ['category' => 'transfer_bank', 'label' => 'BNI'],
    'permata' => ['category' => 'transfer_bank', 'label' => 'PermataBank'],
    'bsi' => ['category' => 'transfer_bank', 'label' => 'BSI'],
    'cimb_niaga' => ['category' => 'transfer_bank', 'label' => 'CIMB Niaga'],
    'bank_lainnya' => ['category' => 'transfer_bank', 'label' => 'Bank Lainnya'],
    'qris' => ['category' => 'qris', 'label' => 'QRIS'],
    'cod' => ['category' => 'cod', 'label' => 'Bayar di Tempat (COD)'],
    'cod_check' => ['category' => 'cod_check', 'label' => 'COD Cek Dulu'],
    'credit_debit_card' => ['category' => 'card', 'label' => 'Kartu Kredit/Debit'],
];

$errors = [];
$user = current_user();

$addressStatement = $pdo->prepare(
    'SELECT id, label, recipient_name, phone, province, city, district, village,
            postal_code, full_address, courier_note, is_primary
     FROM user_addresses
     WHERE user_id = :user_id
     ORDER BY is_primary DESC, id DESC'
);
$addressStatement->execute(['user_id' => (int) $user['id']]);
$addresses = $addressStatement->fetchAll();

$selectedAddressId = 0;
foreach ($addresses as $address) {
    if ((int) $address['is_primary'] === 1) {
        $selectedAddressId = (int) $address['id'];
        break;
    }
}
if ($selectedAddressId === 0 && $addresses !== []) {
    $selectedAddressId = (int) $addresses[0]['id'];
}

$paymentMethod = 'dana';
$otherBankName = '';
$selectedShippingRateId = 0;
$appliedVoucherCode = normalize_voucher_code($_SESSION['voucher_code'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $selectedAddressId = (int) ($_POST['address_id'] ?? 0);
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
    $otherBankName = trim((string) ($_POST['other_bank_name'] ?? ''));
    $selectedShippingRateId = (int) ($_POST['shipping_rate_id'] ?? 0);

    if ($selectedAddressId <= 0) {
        $errors[] = 'Silakan pilih alamat pengiriman.';
    }
    if (!isset($paymentMethods[$paymentMethod])) {
        $errors[] = 'Silakan pilih metode pembayaran yang tersedia.';
    }
    if ($selectedShippingRateId <= 0) {
        $errors[] = 'Silakan pilih kurir dan layanan pengiriman.';
    }
    if ($paymentMethod === 'bank_lainnya' && strlen($otherBankName) < 3) {
        $errors[] = 'Tuliskan nama bank yang akan digunakan.';
    }
    if (strlen($otherBankName) > 100) {
        $errors[] = 'Nama bank maksimal 100 karakter.';
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            $lockedAddressStatement = $pdo->prepare(
                'SELECT id, label, recipient_name, phone, province, city, district, village,
                        postal_code, full_address, courier_note
                 FROM user_addresses
                 WHERE id = :id AND user_id = :user_id
                 LIMIT 1 FOR UPDATE'
            );
            $lockedAddressStatement->execute([
                'id' => $selectedAddressId,
                'user_id' => (int) $user['id'],
            ]);
            $selectedAddress = $lockedAddressStatement->fetch();
            if (!$selectedAddress) {
                throw new RuntimeException('Alamat pengiriman tidak ditemukan atau bukan milik Anda.');
            }

            $lockedItems = [];
            $subtotal = 0.0;
            $totalWeight = 0;

            $productLock = $pdo->prepare(
                'SELECT id, name, category_id, price, stock, low_stock_threshold, image, status,
                        has_variants, default_weight_grams
                 FROM products WHERE id = :id FOR UPDATE'
            );
            $variantLock = $pdo->prepare(
                'SELECT id, product_id, sku, variant_name, color, size, material,
                        price, stock, low_stock_threshold, weight_grams, image, is_active
                 FROM product_variants
                 WHERE id = :id AND product_id = :product_id FOR UPDATE'
            );

            foreach ($cart['items'] as $item) {
                $productLock->execute(['id' => (int) $item['product_id']]);
                $freshProduct = $productLock->fetch();
                if (!$freshProduct || (string) ($freshProduct['status'] ?? 'inactive') !== 'active') {
                    throw new RuntimeException('Produk ' . $item['name'] . ' sudah tidak tersedia.');
                }

                $hasVariants = (int) $freshProduct['has_variants'] === 1;
                $variant = null;
                if ($hasVariants) {
                    $variantLock->execute([
                        'id' => (int) $item['variant_id'],
                        'product_id' => (int) $freshProduct['id'],
                    ]);
                    $variant = $variantLock->fetch();
                    if (!$variant || (int) $variant['is_active'] !== 1) {
                        throw new RuntimeException('Variasi ' . ($item['variant_name'] ?: $item['name']) . ' sudah tidak tersedia.');
                    }
                }

                $freshStock = $hasVariants ? (int) $variant['stock'] : (int) $freshProduct['stock'];
                if ($freshStock < (int) $item['quantity']) {
                    throw new RuntimeException('Stok ' . $item['name'] . ($hasVariants ? ' (' . $variant['variant_name'] . ')' : '') . ' tidak mencukupi.');
                }

                $freshPrice = $hasVariants ? (float) $variant['price'] : (float) $freshProduct['price'];
                $freshWeight = $hasVariants ? (int) $variant['weight_grams'] : (int) $freshProduct['default_weight_grams'];
                if ($freshWeight <= 0) {
                    throw new RuntimeException('Berat ' . $item['name'] . ' belum diatur oleh admin.');
                }

                $itemSubtotal = $freshPrice * (int) $item['quantity'];
                $itemTotalWeight = $freshWeight * (int) $item['quantity'];
                $subtotal += $itemSubtotal;
                $totalWeight += $itemTotalWeight;

                $lockedItems[] = [
                    'id' => (int) $freshProduct['id'],
                    'name' => (string) $freshProduct['name'],
                    'category_id' => (int) ($freshProduct['category_id'] ?? 0),
                    'price' => $freshPrice,
                    'quantity' => (int) $item['quantity'],
                    'subtotal' => $itemSubtotal,
                    'image' => $hasVariants && trim((string) ($variant['image'] ?? '')) !== ''
                        ? (string) $variant['image'] : (string) $freshProduct['image'],
                    'stock_before' => $freshStock,
                    'low_stock_threshold' => $hasVariants
                        ? (int) $variant['low_stock_threshold'] : (int) $freshProduct['low_stock_threshold'],
                    'has_variants' => $hasVariants,
                    'variant_id' => $hasVariants ? (int) $variant['id'] : null,
                    'variant_name' => $hasVariants ? (string) $variant['variant_name'] : null,
                    'variant_sku' => $hasVariants ? (string) $variant['sku'] : null,
                    'variant_color' => $hasVariants ? (string) ($variant['color'] ?? '') : null,
                    'variant_size' => $hasVariants ? (string) ($variant['size'] ?? '') : null,
                    'variant_material' => $hasVariants ? (string) ($variant['material'] ?? '') : null,
                    'item_weight_grams' => $freshWeight,
                    'total_weight_grams' => $itemTotalWeight,
                ];
            }

            $shippingOption = find_shipping_option(
                $pdo,
                $selectedShippingRateId,
                $selectedAddress,
                $totalWeight,
                $paymentMethod
            );
            if ($shippingOption === null) {
                throw new RuntimeException('Layanan pengiriman tidak tersedia untuk alamat atau metode pembayaran yang dipilih.');
            }

            $shipping = (float) $shippingOption['shipping_cost'];
            $codFee = (float) $shippingOption['cod_fee'];
            $promotion = resolve_checkout_promotion(
                $pdo,
                (int) $user['id'],
                $lockedItems,
                $subtotal,
                $shipping,
                $paymentMethod,
                (int) $shippingOption['service_id'],
                $appliedVoucherCode,
                true
            );
            $productDiscount = (float) ($promotion['product_discount'] ?? 0);
            $shippingDiscount = (float) ($promotion['shipping_discount'] ?? 0);
            $amountBeforeDiscount = $subtotal + $shipping + $codFee;
            $total = max(0.0, $subtotal - $productDiscount + $shipping - $shippingDiscount + $codFee);
            $selectedPayment = $paymentMethods[$paymentMethod];
            $paymentDetail = $paymentMethod === 'bank_lainnya' ? $otherBankName : null;
            $initialStatus = is_cod_payment($paymentMethod) ? 'Menunggu Konfirmasi' : 'Menunggu Pembayaran';

            $orderStatement = $pdo->prepare(
                'INSERT INTO orders (
                    user_id, address_id, subtotal_amount, total_amount, shipping_cost, cod_fee,
                    promotion_id, voucher_code, promotion_name, promotion_trigger_type,
                    discount_type, discount_value, product_discount, shipping_discount,
                    amount_before_discount,
                    total_weight_grams, shipping_courier_id, shipping_service_id,
                    shipping_courier_code, shipping_courier_name, shipping_service_code,
                    shipping_service_name, charged_weight_kg, shipping_estimation,
                    status, payment_status, payment_category, payment_method, payment_detail,
                    shipping_name, shipping_phone, shipping_address, shipping_address_label,
                    shipping_province, shipping_city, shipping_district, shipping_village,
                    shipping_postal_code, courier_note
                 ) VALUES (
                    :user_id, :address_id, :subtotal_amount, :total_amount, :shipping_cost, :cod_fee,
                    :promotion_id, :voucher_code, :promotion_name, :promotion_trigger_type,
                    :discount_type, :discount_value, :product_discount, :shipping_discount,
                    :amount_before_discount,
                    :total_weight_grams, :shipping_courier_id, :shipping_service_id,
                    :shipping_courier_code, :shipping_courier_name, :shipping_service_code,
                    :shipping_service_name, :charged_weight_kg, :shipping_estimation,
                    :status, :payment_status, :payment_category, :payment_method, :payment_detail,
                    :shipping_name, :shipping_phone, :shipping_address, :shipping_address_label,
                    :shipping_province, :shipping_city, :shipping_district, :shipping_village,
                    :shipping_postal_code, :courier_note
                 )'
            );
            $orderStatement->execute([
                'user_id' => (int) $user['id'],
                'address_id' => (int) $selectedAddress['id'],
                'subtotal_amount' => $subtotal,
                'total_amount' => $total,
                'shipping_cost' => $shipping,
                'cod_fee' => $codFee,
                'promotion_id' => $promotion ? (int) $promotion['id'] : null,
                'voucher_code' => $promotion ? ((string) ($promotion['code'] ?? '') ?: null) : null,
                'promotion_name' => $promotion ? (string) $promotion['name'] : null,
                'promotion_trigger_type' => $promotion ? (string) $promotion['trigger_type'] : null,
                'discount_type' => $promotion ? (string) $promotion['discount_type'] : null,
                'discount_value' => $promotion ? (float) $promotion['discount_value'] : 0,
                'product_discount' => $productDiscount,
                'shipping_discount' => $shippingDiscount,
                'amount_before_discount' => $amountBeforeDiscount,
                'total_weight_grams' => $totalWeight,
                'shipping_courier_id' => (int) $shippingOption['courier_id'],
                'shipping_service_id' => (int) $shippingOption['service_id'],
                'shipping_courier_code' => $shippingOption['courier_code'],
                'shipping_courier_name' => $shippingOption['courier_name'],
                'shipping_service_code' => $shippingOption['service_code'],
                'shipping_service_name' => $shippingOption['service_name'],
                'charged_weight_kg' => (int) $shippingOption['charged_weight_kg'],
                'shipping_estimation' => $shippingOption['estimation'],
                'status' => $initialStatus,
                'payment_status' => 'Belum Dibayar',
                'payment_category' => $selectedPayment['category'],
                'payment_method' => $paymentMethod,
                'payment_detail' => $paymentDetail,
                'shipping_name' => $selectedAddress['recipient_name'],
                'shipping_phone' => $selectedAddress['phone'],
                'shipping_address' => $selectedAddress['full_address'],
                'shipping_address_label' => $selectedAddress['label'],
                'shipping_province' => $selectedAddress['province'],
                'shipping_city' => $selectedAddress['city'],
                'shipping_district' => $selectedAddress['district'],
                'shipping_village' => $selectedAddress['village'],
                'shipping_postal_code' => $selectedAddress['postal_code'],
                'courier_note' => $selectedAddress['courier_note'],
            ]);

            $orderId = (int) $pdo->lastInsertId();
            $pdo->prepare("UPDATE users SET phone = :phone, updated_at = NOW() WHERE id = :id AND (phone IS NULL OR phone = '')")
                ->execute(['phone' => $selectedAddress['phone'], 'id' => (int) $user['id']]);
            $orderNumber = 'SF' . date('Ymd') . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE orders SET order_number = :order_number WHERE id = :id')
                ->execute(['order_number' => $orderNumber, 'id' => $orderId]);

            if ($promotion !== null) {
                $usageStatement = $pdo->prepare(
                    "INSERT INTO promotion_usages (
                        promotion_id, user_id, order_id, voucher_code,
                        discount_amount, shipping_discount, usage_status, used_at
                     ) VALUES (
                        :promotion_id, :user_id, :order_id, :voucher_code,
                        :discount_amount, :shipping_discount, 'USED', NOW()
                     )"
                );
                $usageStatement->execute([
                    'promotion_id' => (int) $promotion['id'],
                    'user_id' => (int) $user['id'],
                    'order_id' => $orderId,
                    'voucher_code' => ((string) ($promotion['code'] ?? '')) ?: null,
                    'discount_amount' => $productDiscount,
                    'shipping_discount' => $shippingDiscount,
                ]);
                $pdo->prepare(
                    'UPDATE promotions SET current_usage = current_usage + 1, updated_at = NOW() WHERE id = :id'
                )->execute(['id' => (int) $promotion['id']]);
            }

            record_order_status(
                $pdo,
                $orderId,
                $initialStatus,
                is_cod_payment($paymentMethod)
                    ? 'Pesanan COD dibuat dan menunggu konfirmasi toko.'
                    : 'Pesanan dibuat dan menunggu pembayaran.'
            );
            record_shipment_history(
                $pdo,
                $orderId,
                'Pesanan Dibuat',
                'Pengiriman ' . $shippingOption['courier_name'] . ' ' . $shippingOption['service_name'] . ' dipilih.',
                (string) $selectedAddress['city']
            );

            $itemStatement = $pdo->prepare(
                'INSERT INTO order_items (
                    order_id, product_id, variant_id, product_name, product_image,
                    variant_name, variant_sku, variant_color, variant_size, variant_material,
                    price, quantity, subtotal, item_weight_grams, total_weight_grams
                 ) VALUES (
                    :order_id, :product_id, :variant_id, :product_name, :product_image,
                    :variant_name, :variant_sku, :variant_color, :variant_size, :variant_material,
                    :price, :quantity, :subtotal, :item_weight_grams, :total_weight_grams
                 )'
            );
            $productStockUpdate = $pdo->prepare('UPDATE products SET stock = :stock, updated_at = NOW() WHERE id = :id');
            $variantStockUpdate = $pdo->prepare('UPDATE product_variants SET stock = :stock, updated_at = NOW() WHERE id = :id');

            foreach ($lockedItems as $item) {
                $itemStatement->execute([
                    'order_id' => $orderId,
                    'product_id' => $item['id'],
                    'variant_id' => $item['variant_id'],
                    'product_name' => $item['name'],
                    'product_image' => $item['image'],
                    'variant_name' => $item['variant_name'],
                    'variant_sku' => $item['variant_sku'],
                    'variant_color' => $item['variant_color'],
                    'variant_size' => $item['variant_size'],
                    'variant_material' => $item['variant_material'],
                    'price' => $item['price'],
                    'quantity' => $item['quantity'],
                    'subtotal' => $item['subtotal'],
                    'item_weight_grams' => $item['item_weight_grams'],
                    'total_weight_grams' => $item['total_weight_grams'],
                ]);

                $stockAfter = $item['stock_before'] - $item['quantity'];
                if ($item['has_variants']) {
                    $variantStockUpdate->execute(['stock' => $stockAfter, 'id' => $item['variant_id']]);
                    sync_product_variant_summary($pdo, $item['id']);
                } else {
                    $productStockUpdate->execute(['stock' => $stockAfter, 'id' => $item['id']]);
                }

                record_stock_history(
                    $pdo, $item['id'], 'sale', $item['quantity'], $item['stock_before'], $stockAfter,
                    'order', $orderId, 'Stok berkurang saat checkout.', null, $item['variant_id']
                );

                // Tandai produk wishlist sebagai telah dibeli untuk laporan konversi wishlist.
                $pdo->prepare(
                    'UPDATE wishlist_items
                     SET purchased_at = NOW(), purchased_order_id = :order_id, updated_at = NOW()
                     WHERE user_id = :user_id AND product_id = :product_id'
                )->execute([
                    'order_id' => $orderId,
                    'user_id' => (int) $user['id'],
                    'product_id' => (int) $item['id'],
                ]);

                if ($stockAfter <= $item['low_stock_threshold']) {
                    $variantText = $item['variant_name'] ? ' - ' . $item['variant_name'] : '';
                    create_notification(
                        $pdo,
                        'Stok produk menipis',
                        $item['name'] . $variantText . ' tersisa ' . $stockAfter . ' unit.',
                        'stock.php?product_id=' . $item['id'] . ($item['variant_id'] ? '&variant_id=' . $item['variant_id'] : ''),
                        'stock',
                        'admin',
                        null,
                        'low-stock-order-' . $orderId . '-' . $item['id'] . '-' . ($item['variant_id'] ?: 0),
                        'product',
                        (int) $item['id']
                    );
                }
            }

            create_notification(
                $pdo,
                'Pesanan berhasil dibuat',
                $orderNumber . ' berhasil dibuat dan sedang menunggu konfirmasi toko.',
                'orders.php',
                'order',
                'customer',
                (int) $user['id'],
                'order-created-' . $orderId,
                'order',
                $orderId,
                'order_updates'
            );
            create_notification(
                $pdo,
                'Pesanan baru',
                $orderNumber . ' dibuat oleh ' . $selectedAddress['recipient_name'] . ' menggunakan ' . payment_method_label($paymentMethod) . '.',
                'order_detail.php?id=' . $orderId,
                'order',
                'admin',
                null,
                'admin-order-created-' . $orderId,
                'order',
                $orderId
            );
            $pdo->commit();
            if ($isDirectPurchase) {
                clear_direct_purchase();
            } else {
                $_SESSION['cart'] = [];
            }
            unset($_SESSION['voucher_code']);
            $_SESSION['last_order_id'] = $orderId;
            redirect('order_success.php?id=' . $orderId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Checkout gagal diproses. Silakan coba lagi.';
        }
    }
}

$shippingMatrix = [];
foreach ($addresses as $address) {
    $addressId = (int) $address['id'];
    $shippingMatrix[$addressId] = [
        'online' => get_shipping_options($pdo, $address, (int) $cart['total_weight_grams'], 'dana'),
        'cod' => get_shipping_options($pdo, $address, (int) $cart['total_weight_grams'], 'cod'),
        'cod_check' => get_shipping_options($pdo, $address, (int) $cart['total_weight_grams'], 'cod_check'),
    ];
}

$voucherPreview = $appliedVoucherCode !== ''
    ? promotion_preview_data($pdo, (int) $user['id'], $cart['items'], $appliedVoucherCode)
    : [];
$automaticPromotionPreviews = $appliedVoucherCode === ''
    ? promotion_preview_data($pdo, (int) $user['id'], $cart['items'])
    : [];
$promotionPreviews = array_values(array_merge($voucherPreview, $automaticPromotionPreviews));

$pageTitle = 'Checkout';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header">
    <div class="container">
        <span class="eyebrow">Checkout aman</span>
        <h1>Lengkapi pengiriman dan pembayaran.</h1>
        <p>Ongkos kirim dihitung berdasarkan alamat tujuan, layanan kurir, dan berat produk.</p>
    </div>
</section>

<section class="section">
    <div class="container checkout-layout">
        <div class="checkout-form-card">
            <?php if ($isDirectPurchase): ?>
                <div class="direct-purchase-notice">
                    <div>
                        <strong>🎟 Pembelian langsung dengan voucher</strong>
                        <span>Checkout ini hanya memproses produk yang dipilih. Isi keranjang biasa tetap tersimpan.</span>
                    </div>
                    <form method="post" action="cancel_direct_purchase.php">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <button class="button button-secondary button-small" type="submit">Batalkan</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <section class="voucher-checkout-box" id="voucher-checkout">
                <div class="checkout-section-title">
                    <div><span class="checkout-step-number">🎟</span><div><h2>Voucher & Promo</h2><p>Satu pesanan menggunakan maksimal satu promo.</p></div></div>
                    <a href="promotions.php">Lihat promo</a>
                </div>
                <?php if ($appliedVoucherCode !== ''): ?>
                    <div class="voucher-applied">
                        <div><span>Voucher aktif</span><strong><?= e($appliedVoucherCode) ?></strong><small>Validasi akhir dilakukan kembali saat pesanan dibuat.</small></div>
                        <form method="post" action="remove_voucher.php">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <button class="button button-secondary button-small" type="submit">Hapus</button>
                        </form>
                    </div>
                <?php else: ?>
                    <form class="voucher-apply-form" method="post" action="apply_voucher.php">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="text" name="voucher_code" maxlength="50" autocomplete="off" placeholder="Contoh: SHOPFLOW10" required>
                        <button class="button button-primary" type="submit">Terapkan</button>
                    </form>
                    <p class="voucher-helper">Tanpa kode, sistem memilih promo otomatis terbaik yang memenuhi syarat.</p>
                <?php endif; ?>
                <div class="voucher-preview-message" id="voucher-preview-message" aria-live="polite"></div>
            </section>

            <form class="form-stack" method="post" action="checkout.php" id="checkout-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <fieldset class="checkout-addresses">
                    <div class="checkout-section-title">
                        <div><span class="checkout-step-number">1</span><div><h2>Alamat Pengiriman</h2><p>Pilih alamat tujuan paket.</p></div></div>
                        <a class="button button-secondary button-small" href="add_address.php?return_to=checkout.php">+ Tambah Alamat</a>
                    </div>

                    <?php if ($addresses === []): ?>
                        <div class="checkout-address-empty">
                            <span>🏠</span><div><strong>Anda belum memiliki alamat pengiriman.</strong><p>Tambahkan alamat terlebih dahulu sebelum membuat pesanan.</p></div>
                            <a class="button button-primary button-small" href="add_address.php?return_to=checkout.php">Tambah Alamat</a>
                        </div>
                    <?php else: ?>
                        <div class="checkout-address-list">
                            <?php foreach ($addresses as $address): ?>
                                <div class="checkout-address-option">
                                    <label class="checkout-address-choice">
                                        <input type="radio" name="address_id" value="<?= (int) $address['id'] ?>" <?= $selectedAddressId === (int) $address['id'] ? 'checked' : '' ?> required>
                                        <span class="checkout-address-radio"></span>
                                        <span class="checkout-address-content">
                                            <span class="checkout-address-heading"><strong><?= e(address_icon((string) $address['label']) . ' ' . (string) $address['label']) ?></strong><?php if ((int) $address['is_primary'] === 1): ?><small>Utama</small><?php endif; ?></span>
                                            <b><?= e((string) $address['recipient_name']) ?> · <?= e((string) $address['phone']) ?></b>
                                            <span><?= e((string) $address['full_address']) ?></span>
                                            <span><?= e(address_region_text($address)) ?></span>
                                            <?php if (trim((string) $address['courier_note']) !== ''): ?><em>Catatan: <?= e((string) $address['courier_note']) ?></em><?php endif; ?>
                                        </span>
                                    </label>
                                    <a class="checkout-address-edit" href="edit_address.php?id=<?= (int) $address['id'] ?>&return_to=checkout.php">Edit</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <a class="checkout-manage-addresses" href="addresses.php?return_to=checkout.php">Kelola semua alamat →</a>
                    <?php endif; ?>
                </fieldset>

                <fieldset class="payment-methods" <?= $addresses === [] ? 'disabled' : '' ?>>
                    <div class="checkout-section-title checkout-payment-title">
                        <div><span class="checkout-step-number">2</span><div><h2>Metode Pembayaran</h2><p>Pilihan COD akan memfilter kurir yang mendukung.</p></div></div>
                    </div>

                    <div class="payment-group">
                        <div class="payment-group-title"><span class="payment-group-icon">📱</span><div><strong>E-Wallet</strong><small>DANA, OVO, atau GoPay</small></div></div>
                        <div class="payment-choice-grid payment-choice-grid-three">
                            <?php foreach (['dana' => 'DANA', 'ovo' => 'OVO', 'gopay' => 'GoPay'] as $value => $label): ?>
                                <label class="payment-choice"><input type="radio" name="payment_method" value="<?= e($value) ?>" <?= $paymentMethod === $value ? 'checked' : '' ?> required><span class="payment-brand"><?= e($label) ?></span><span class="payment-check">✓</span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="payment-group">
                        <div class="payment-group-title"><span class="payment-group-icon">🏦</span><div><strong>Transfer Bank</strong><small>Pilih bank tujuan transfer</small></div></div>
                        <div class="payment-choice-grid payment-bank-grid">
                            <?php $banks = ['seabank'=>'SeaBank','mandiri'=>'Mandiri','bca'=>'BCA','bri'=>'BRI','bni'=>'BNI','permata'=>'Permata','bsi'=>'BSI','cimb_niaga'=>'CIMB Niaga','bank_lainnya'=>'Bank Lainnya']; ?>
                            <?php foreach ($banks as $value => $label): ?>
                                <label class="payment-choice"><input type="radio" name="payment_method" value="<?= e($value) ?>" <?= $paymentMethod === $value ? 'checked' : '' ?> required><span class="payment-brand"><?= e($label) ?></span><span class="payment-check">✓</span></label>
                            <?php endforeach; ?>
                        </div>
                        <label class="other-bank-field" id="other-bank-field"><span>Nama Bank Lainnya</span><input type="text" name="other_bank_name" id="other-bank-name" maxlength="100" value="<?= e($otherBankName) ?>" placeholder="Contoh: Bank Jatim"></label>
                    </div>

                    <div class="payment-group">
                        <div class="payment-group-title"><span class="payment-group-icon">💳</span><div><strong>Metode Lainnya</strong><small>QRIS, COD, dan kartu</small></div></div>
                        <div class="payment-special-list">
                            <label class="payment-choice payment-choice-wide"><input type="radio" name="payment_method" value="qris" <?= $paymentMethod === 'qris' ? 'checked' : '' ?> required><span class="payment-special-icon">▦</span><span class="payment-choice-copy"><strong>QRIS</strong><small>Pindai kode QR untuk membayar.</small></span><span class="payment-check">✓</span></label>
                            <label class="payment-choice payment-choice-wide"><input type="radio" name="payment_method" value="cod" <?= $paymentMethod === 'cod' ? 'checked' : '' ?> required><span class="payment-special-icon">🚚</span><span class="payment-choice-copy"><strong>COD</strong><small>Bayar kepada kurir saat pesanan diterima.</small></span><span class="payment-check">✓</span></label>
                            <label class="payment-choice payment-choice-wide"><input type="radio" name="payment_method" value="cod_check" <?= $paymentMethod === 'cod_check' ? 'checked' : '' ?> required><span class="payment-special-icon">📦</span><span class="payment-choice-copy"><strong>COD Cek Dulu</strong><small>Periksa paket sebelum membayar.</small></span><span class="payment-check">✓</span></label>
                            <label class="payment-choice payment-choice-wide"><input type="radio" name="payment_method" value="credit_debit_card" <?= $paymentMethod === 'credit_debit_card' ? 'checked' : '' ?> required><span class="payment-special-icon">💳</span><span class="payment-choice-copy"><strong>Kartu Kredit/Debit</strong><small>Data kartu tidak disimpan pada aplikasi demo.</small></span><span class="payment-check">✓</span></label>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="shipping-methods" <?= $addresses === [] ? 'disabled' : '' ?>>
                    <div class="checkout-section-title">
                        <div><span class="checkout-step-number">3</span><div><h2>Kurir dan Ongkos Kirim</h2><p>Pilih layanan yang tersedia untuk tujuan dan pembayaran Anda.</p></div></div>
                    </div>
                    <div class="shipping-option-list" id="shipping-option-list" aria-live="polite"></div>
                    <div class="payment-note"><span>⚖</span><p>Berat aktual <?= e(weight_label((int) $cart['total_weight_grams'])) ?> dibulatkan ke kilogram pengiriman sesuai pengaturan toko.</p></div>
                </fieldset>

                <button class="button button-primary button-full" id="checkout-submit" type="submit" <?= $addresses === [] ? 'disabled' : '' ?>>Buat Pesanan</button>
            </form>
        </div>

        <aside class="summary-card sticky-summary">
            <h2>Ringkasan Pesanan</h2>
            <div class="checkout-products">
                <?php foreach ($cart['items'] as $item): ?>
                    <div class="checkout-product">
                        <img src="<?= e((string) $item['image']) ?>" alt="<?= e((string) $item['name']) ?>">
                        <div><strong><?= e((string) $item['name']) ?></strong><?php if ((int) $item['has_variants'] === 1): ?><small><?= e(variant_description($item)) ?> • SKU <?= e((string) $item['variant_sku']) ?></small><?php endif; ?><span><?= (int) $item['quantity'] ?> × <?= rupiah($item['price']) ?> • <?= e(weight_label((int) $item['total_weight_grams'])) ?></span></div>
                        <b><?= rupiah($item['subtotal']) ?></b>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="summary-divider"></div>
            <div class="summary-row"><span>Subtotal</span><strong><?= rupiah($cart['subtotal']) ?></strong></div>
            <div class="summary-row" id="summary-product-discount-row" hidden><span>Diskon produk</span><strong id="summary-product-discount">-Rp 0</strong></div>
            <div class="summary-row"><span>Total berat</span><strong><?= e(weight_label((int) $cart['total_weight_grams'])) ?></strong></div>
            <div class="summary-row"><span>Berat dikenakan</span><strong id="summary-charged-weight">-</strong></div>
            <div class="summary-row"><span>Ongkos kirim</span><strong id="summary-shipping">Rp 0</strong></div>
            <div class="summary-row" id="summary-shipping-discount-row" hidden><span>Diskon ongkir</span><strong id="summary-shipping-discount">-Rp 0</strong></div>
            <div class="summary-row" id="summary-cod-row" hidden><span>Biaya COD</span><strong id="summary-cod-fee">Rp 0</strong></div>
            <div class="summary-row summary-total"><span>Total</span><strong id="summary-total"><?= rupiah($cart['subtotal']) ?></strong></div>
            <p class="summary-address-note" id="summary-shipping-note">Pilih alamat, pembayaran, dan layanan pengiriman.</p>
        </aside>
    </div>
</section>

<script>
(() => {
    const shippingMatrix = <?= json_encode($shippingMatrix, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const subtotal = <?= json_encode((float) $cart['subtotal']) ?>;
    const promotionPreviews = <?= json_encode($promotionPreviews, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const appliedVoucherCode = <?= json_encode($appliedVoucherCode, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const selectedRateFromServer = <?= (int) $selectedShippingRateId ?>;
    const addressRadios = [...document.querySelectorAll('input[name="address_id"]')];
    const paymentRadios = [...document.querySelectorAll('input[name="payment_method"]')];
    const list = document.getElementById('shipping-option-list');
    const submitButton = document.getElementById('checkout-submit');
    const otherBankField = document.getElementById('other-bank-field');
    const otherBankInput = document.getElementById('other-bank-name');
    const shippingText = document.getElementById('summary-shipping');
    const productDiscountText = document.getElementById('summary-product-discount');
    const productDiscountRow = document.getElementById('summary-product-discount-row');
    const shippingDiscountText = document.getElementById('summary-shipping-discount');
    const shippingDiscountRow = document.getElementById('summary-shipping-discount-row');
    const voucherPreviewMessage = document.getElementById('voucher-preview-message');
    const codFeeText = document.getElementById('summary-cod-fee');
    const codRow = document.getElementById('summary-cod-row');
    const totalText = document.getElementById('summary-total');
    const chargedWeightText = document.getElementById('summary-charged-weight');
    const shippingNote = document.getElementById('summary-shipping-note');
    let firstRender = true;

    const money = (value) => new Intl.NumberFormat('id-ID', {
        style: 'currency', currency: 'IDR', maximumFractionDigits: 0
    }).format(Number(value || 0));
    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;').replaceAll("'", '&#039;');

    function selectedValue(name) {
        return document.querySelector(`input[name="${name}"]:checked`)?.value || '';
    }

    function paymentGroup(method) {
        if (method === 'cod') return 'cod';
        if (method === 'cod_check') return 'cod_check';
        return 'online';
    }

    function updateOtherBankField() {
        const visible = selectedValue('payment_method') === 'bank_lainnya';
        otherBankField?.classList.toggle('is-visible', visible);
        if (otherBankInput) {
            otherBankInput.required = visible;
            if (!visible) otherBankInput.value = '';
        }
    }

    function evaluatePromotion(promotion, option, method) {
        if (!promotion || !promotion.usage_available) return null;
        if (subtotal < Number(promotion.minimum_purchase || 0)) return null;
        if (Number(promotion.eligible_subtotal || 0) <= 0) return null;
        if (promotion.payment_methods?.length && !promotion.payment_methods.includes(method)) return null;
        if (promotion.shipping_service_ids?.length && !promotion.shipping_service_ids.includes(Number(option?.service_id || 0))) return null;

        let productDiscount = 0;
        let shippingDiscount = 0;
        const eligible = Number(promotion.eligible_subtotal || 0);
        const shipping = Number(option?.shipping_cost || 0);

        if (promotion.discount_type === 'PERCENTAGE') {
            productDiscount = eligible * Number(promotion.discount_value || 0) / 100;
            if (promotion.maximum_discount !== null) {
                productDiscount = Math.min(productDiscount, Number(promotion.maximum_discount));
            }
            productDiscount = Math.min(productDiscount, eligible);
        } else if (promotion.discount_type === 'FIXED_AMOUNT') {
            productDiscount = Math.min(Number(promotion.discount_value || 0), eligible);
        } else if (promotion.discount_type === 'FREE_SHIPPING') {
            const maximum = promotion.maximum_shipping_discount === null
                ? shipping : Number(promotion.maximum_shipping_discount);
            shippingDiscount = Math.min(shipping, maximum);
        }

        const benefit = productDiscount + shippingDiscount;
        return benefit > 0 ? { promotion, productDiscount, shippingDiscount, benefit } : null;
    }

    function selectedPromotion(option, method) {
        const candidates = promotionPreviews
            .map((promotion) => evaluatePromotion(promotion, option, method))
            .filter(Boolean);
        if (!candidates.length) return null;
        if (appliedVoucherCode) {
            return candidates.find((item) => item.promotion.code === appliedVoucherCode) || null;
        }
        return candidates.sort((a, b) => b.benefit - a.benefit)[0];
    }

    function updateSummary(option = null) {
        const shipping = option ? Number(option.shipping_cost) : 0;
        const codFee = option ? Number(option.cod_fee) : 0;
        const method = selectedValue('payment_method');
        const promo = option ? selectedPromotion(option, method) : null;
        const productDiscount = promo ? promo.productDiscount : 0;
        const shippingDiscount = promo ? promo.shippingDiscount : 0;

        shippingText.textContent = money(shipping);
        codFeeText.textContent = money(codFee);
        codRow.hidden = codFee <= 0;
        productDiscountRow.hidden = productDiscount <= 0;
        shippingDiscountRow.hidden = shippingDiscount <= 0;
        productDiscountText.textContent = `-${money(productDiscount)}`;
        shippingDiscountText.textContent = `-${money(shippingDiscount)}`;
        chargedWeightText.textContent = option ? `${option.charged_weight_kg} kg` : '-';
        totalText.textContent = money(Math.max(0, subtotal - productDiscount + shipping - shippingDiscount + codFee));
        shippingNote.textContent = option
            ? `${option.courier_name} ${option.service_name} • Estimasi ${option.estimation}`
            : 'Pilih alamat, pembayaran, dan layanan pengiriman.';

        if (voucherPreviewMessage) {
            if (promo) {
                const label = promo.promotion.code || 'PROMO OTOMATIS';
                voucherPreviewMessage.className = 'voucher-preview-message is-success';
                voucherPreviewMessage.textContent = `${label} memberi potongan ${money(promo.benefit)}.`;
            } else if (appliedVoucherCode && option) {
                voucherPreviewMessage.className = 'voucher-preview-message is-warning';
                voucherPreviewMessage.textContent = 'Voucher tidak memenuhi pilihan pembayaran, pengiriman, atau nilai belanja saat ini.';
            } else {
                voucherPreviewMessage.className = 'voucher-preview-message';
                voucherPreviewMessage.textContent = '';
            }
        }
    }

    function renderShippingOptions() {
        if (!list) return;
        const addressId = selectedValue('address_id');
        const method = selectedValue('payment_method');
        const group = paymentGroup(method);
        const options = shippingMatrix[addressId]?.[group] || [];
        list.innerHTML = '';

        if (!addressId) {
            list.innerHTML = '<div class="shipping-empty-state"><strong>Pilih alamat pengiriman.</strong><span>Layanan kurir akan muncul setelah alamat dipilih.</span></div>';
            submitButton.disabled = true;
            updateSummary();
            return;
        }

        if (options.length === 0) {
            const message = group === 'cod_check'
                ? 'COD Cek Dulu belum tersedia untuk alamat ini.'
                : group === 'cod'
                    ? 'Layanan COD belum tersedia untuk alamat ini.'
                    : 'Tarif pengiriman belum tersedia untuk alamat ini.';
            list.innerHTML = `<div class="shipping-empty-state"><strong>${message}</strong><span>Ubah metode pembayaran atau hubungi admin untuk menambahkan tarif tujuan.</span></div>`;
            submitButton.disabled = true;
            updateSummary();
            return;
        }

        let selectedOption = null;
        options.forEach((option, index) => {
            const label = document.createElement('label');
            label.className = 'shipping-choice';
            const shouldCheck = firstRender && selectedRateFromServer > 0
                ? Number(option.rate_id) === selectedRateFromServer
                : index === 0;
            label.innerHTML = `
                <input type="radio" name="shipping_rate_id" value="${option.rate_id}" ${shouldCheck ? 'checked' : ''} required>
                <span class="shipping-choice-radio"></span>
                <span class="shipping-choice-main">
                    <span class="shipping-choice-heading"><strong>${escapeHtml(option.courier_name)}</strong><b>${escapeHtml(option.service_code)}</b></span>
                    <span>${escapeHtml(option.service_name)} • Estimasi ${escapeHtml(option.estimation)}</span>
                    <small>Berat dikenakan ${Number(option.charged_weight_kg)} kg${Number(option.cod_fee) > 0 ? ` • Biaya COD ${money(option.cod_fee)}` : ''}</small>
                </span>
                <strong class="shipping-choice-price">${money(option.shipping_cost)}</strong>`;
            const input = label.querySelector('input');
            input.addEventListener('change', () => updateSummary(option));
            list.appendChild(label);
            if (shouldCheck) selectedOption = option;
        });

        if (!selectedOption) {
            list.querySelector('input')?.click();
        } else {
            updateSummary(selectedOption);
        }
        submitButton.disabled = false;
        firstRender = false;
    }

    addressRadios.forEach((radio) => radio.addEventListener('change', renderShippingOptions));
    paymentRadios.forEach((radio) => radio.addEventListener('change', () => {
        updateOtherBankField();
        firstRender = false;
        renderShippingOptions();
    }));
    updateOtherBankField();
    renderShippingOptions();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
