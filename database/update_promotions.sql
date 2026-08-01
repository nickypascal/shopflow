USE shopflow;

-- Jalankan file ini satu kali setelah fitur pengiriman dan ongkos kirim terpasang.

CREATE TABLE promotions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NULL UNIQUE,
    description TEXT NULL,
    trigger_type VARCHAR(20) NOT NULL DEFAULT 'CODE',
    discount_type VARCHAR(30) NOT NULL,
    discount_value DECIMAL(15,2) NOT NULL DEFAULT 0,
    maximum_discount DECIMAL(15,2) NULL,
    minimum_purchase DECIMAL(15,2) NOT NULL DEFAULT 0,
    maximum_shipping_discount DECIMAL(15,2) NULL,
    total_usage_limit INT UNSIGNED NULL,
    usage_limit_per_user INT UNSIGNED NOT NULL DEFAULT 1,
    current_usage INT UNSIGNED NOT NULL DEFAULT 0,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_promotions_code (code),
    INDEX idx_promotions_active_period (is_active, start_at, end_at),
    INDEX idx_promotions_trigger (trigger_type)
) ENGINE=InnoDB;

CREATE TABLE promotion_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    CONSTRAINT fk_promotion_products_promotion
        FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    CONSTRAINT fk_promotion_products_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_promotion_product (promotion_id, product_id),
    INDEX idx_promotion_products_product (product_id)
) ENGINE=InnoDB;

CREATE TABLE promotion_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    CONSTRAINT fk_promotion_categories_promotion
        FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    CONSTRAINT fk_promotion_categories_category
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    UNIQUE KEY uq_promotion_category (promotion_id, category_id),
    INDEX idx_promotion_categories_category (category_id)
) ENGINE=InnoDB;

CREATE TABLE promotion_payment_methods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id BIGINT UNSIGNED NOT NULL,
    payment_method VARCHAR(80) NOT NULL,
    CONSTRAINT fk_promotion_payments_promotion
        FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    UNIQUE KEY uq_promotion_payment (promotion_id, payment_method),
    INDEX idx_promotion_payment_method (payment_method)
) ENGINE=InnoDB;

CREATE TABLE promotion_shipping_services (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id BIGINT UNSIGNED NOT NULL,
    shipping_service_id BIGINT UNSIGNED NOT NULL,
    CONSTRAINT fk_promotion_shipping_promotion
        FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    CONSTRAINT fk_promotion_shipping_service
        FOREIGN KEY (shipping_service_id) REFERENCES shipping_services(id) ON DELETE CASCADE,
    UNIQUE KEY uq_promotion_shipping_service (promotion_id, shipping_service_id),
    INDEX idx_promotion_shipping_service (shipping_service_id)
) ENGINE=InnoDB;

ALTER TABLE orders
    ADD COLUMN promotion_id BIGINT UNSIGNED NULL AFTER cod_fee,
    ADD COLUMN voucher_code VARCHAR(50) NULL AFTER promotion_id,
    ADD COLUMN promotion_name VARCHAR(150) NULL AFTER voucher_code,
    ADD COLUMN promotion_trigger_type VARCHAR(20) NULL AFTER promotion_name,
    ADD COLUMN discount_type VARCHAR(30) NULL AFTER promotion_trigger_type,
    ADD COLUMN discount_value DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER discount_type,
    ADD COLUMN product_discount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER discount_value,
    ADD COLUMN shipping_discount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER product_discount,
    ADD COLUMN amount_before_discount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER shipping_discount,
    ADD INDEX idx_orders_promotion (promotion_id),
    ADD INDEX idx_orders_voucher_code (voucher_code),
    ADD CONSTRAINT fk_orders_promotion
        FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE SET NULL;

CREATE TABLE promotion_usages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    voucher_code VARCHAR(50) NULL,
    discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    shipping_discount DECIMAL(15,2) NOT NULL DEFAULT 0,
    usage_status VARCHAR(20) NOT NULL DEFAULT 'USED',
    used_at DATETIME NOT NULL,
    restored_at DATETIME NULL,
    CONSTRAINT fk_promotion_usages_promotion
        FOREIGN KEY (promotion_id) REFERENCES promotions(id),
    CONSTRAINT fk_promotion_usages_user
        FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_promotion_usages_order
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    UNIQUE KEY uq_promotion_usage_order (order_id),
    INDEX idx_promotion_usages_user (promotion_id, user_id, usage_status),
    INDEX idx_promotion_usages_status (usage_status)
) ENGINE=InnoDB;

-- Menjaga nilai dasar pesanan lama tetap konsisten.
UPDATE orders
SET amount_before_discount = subtotal_amount + shipping_cost + cod_fee
WHERE amount_before_discount = 0;

-- Voucher contoh untuk pengujian fungsional.
INSERT INTO promotions
(name, code, description, trigger_type, discount_type, discount_value,
 maximum_discount, minimum_purchase, maximum_shipping_discount,
 total_usage_limit, usage_limit_per_user, current_usage, start_at, end_at, is_active)
VALUES
('Diskon Pengguna ShopFlow', 'SHOPFLOW10', 'Diskon 10% untuk seluruh produk.', 'CODE', 'PERCENTAGE', 10,
 25000, 100000, NULL, 100, 2, 0, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 365 DAY), 1),
('Hemat Fashion', 'HEMAT20K', 'Potongan Rp20.000 untuk kategori Fashion.', 'CODE', 'FIXED_AMOUNT', 20000,
 NULL, 150000, NULL, 50, 1, 0, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 365 DAY), 1),
('Gratis Ongkir ShopFlow', 'GRATISONGKIR', 'Potongan ongkir maksimal Rp15.000.', 'CODE', 'FREE_SHIPPING', 0,
 NULL, 75000, 15000, 100, 2, 0, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 365 DAY), 1),
('Promo Otomatis 5%', NULL, 'Diskon otomatis 5% maksimal Rp10.000.', 'AUTOMATIC', 'PERCENTAGE', 5,
 10000, 50000, NULL, NULL, 99, 0, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 365 DAY), 1);

INSERT INTO promotion_categories (promotion_id, category_id)
SELECT p.id, c.id
FROM promotions p
INNER JOIN categories c ON c.slug = 'fashion'
WHERE p.code = 'HEMAT20K';
