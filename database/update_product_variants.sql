USE shopflow;

-- Jalankan file ini satu kali pada database ShopFlow yang sudah memiliki fitur alamat pengguna.

ALTER TABLE products
    ADD COLUMN has_variants TINYINT(1) NOT NULL DEFAULT 0 AFTER is_featured,
    ADD COLUMN default_weight_grams INT UNSIGNED NOT NULL DEFAULT 0 AFTER has_variants;

CREATE TABLE product_variants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(100) NOT NULL UNIQUE,
    variant_name VARCHAR(150) NOT NULL,
    color VARCHAR(100) NULL,
    size VARCHAR(100) NULL,
    material VARCHAR(100) NULL,
    price DECIMAL(15,2) NOT NULL,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 5,
    weight_grams INT UNSIGNED NOT NULL DEFAULT 0,
    image VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_variants_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_variants_product (product_id),
    INDEX idx_product_variants_active (product_id, is_active),
    INDEX idx_product_variants_stock (stock)
) ENGINE=InnoDB;

ALTER TABLE orders
    ADD COLUMN total_weight_grams INT UNSIGNED NOT NULL DEFAULT 0 AFTER shipping_cost;

ALTER TABLE order_items
    ADD COLUMN variant_id BIGINT UNSIGNED NULL AFTER product_id,
    ADD COLUMN variant_name VARCHAR(150) NULL AFTER product_image,
    ADD COLUMN variant_sku VARCHAR(100) NULL AFTER variant_name,
    ADD COLUMN variant_color VARCHAR(100) NULL AFTER variant_sku,
    ADD COLUMN variant_size VARCHAR(100) NULL AFTER variant_color,
    ADD COLUMN variant_material VARCHAR(100) NULL AFTER variant_size,
    ADD COLUMN item_weight_grams INT UNSIGNED NOT NULL DEFAULT 0 AFTER subtotal,
    ADD COLUMN total_weight_grams INT UNSIGNED NOT NULL DEFAULT 0 AFTER item_weight_grams,
    ADD INDEX idx_order_items_variant (variant_id),
    ADD CONSTRAINT fk_order_items_variant
        FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL;

ALTER TABLE stock_histories
    ADD COLUMN variant_id BIGINT UNSIGNED NULL AFTER product_id,
    ADD INDEX idx_stock_histories_variant (variant_id),
    ADD CONSTRAINT fk_stock_histories_variant
        FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL;

-- Berat awal produk tanpa variasi untuk kebutuhan perhitungan total berat.
UPDATE products SET default_weight_grams = CASE slug
    WHEN 'laptop-premium-14' THEN 2200
    WHEN 'smartphone-modern' THEN 350
    WHEN 'headphone-wireless' THEN 450
    WHEN 'smart-watch-active' THEN 200
    WHEN 'sneakers-urban' THEN 900
    WHEN 'tas-kerja-minimalis' THEN 750
    WHEN 'lampu-meja-led' THEN 1200
    WHEN 'kursi-kerja-nyaman' THEN 9000
    ELSE 500
END
WHERE default_weight_grams = 0;

-- Data contoh: Sneakers Urban menggunakan variasi warna dan ukuran.
UPDATE products
SET has_variants = 1, stock = 20, price = 625000, default_weight_grams = 900
WHERE slug = 'sneakers-urban';

INSERT INTO product_variants
(product_id, sku, variant_name, color, size, material, price, stock, low_stock_threshold, weight_grams, image, is_active)
SELECT id, 'SNK-BLK-40', 'Hitam / 40', 'Hitam', '40', 'Mesh & Sintetis', 625000, 5, 2, 880, image, 1
FROM products WHERE slug = 'sneakers-urban';

INSERT INTO product_variants
(product_id, sku, variant_name, color, size, material, price, stock, low_stock_threshold, weight_grams, image, is_active)
SELECT id, 'SNK-BLK-41', 'Hitam / 41', 'Hitam', '41', 'Mesh & Sintetis', 625000, 5, 2, 900, image, 1
FROM products WHERE slug = 'sneakers-urban';

INSERT INTO product_variants
(product_id, sku, variant_name, color, size, material, price, stock, low_stock_threshold, weight_grams, image, is_active)
SELECT id, 'SNK-WHT-40', 'Putih / 40', 'Putih', '40', 'Mesh & Sintetis', 650000, 5, 2, 880, image, 1
FROM products WHERE slug = 'sneakers-urban';

INSERT INTO product_variants
(product_id, sku, variant_name, color, size, material, price, stock, low_stock_threshold, weight_grams, image, is_active)
SELECT id, 'SNK-WHT-41', 'Putih / 41', 'Putih', '41', 'Mesh & Sintetis', 650000, 5, 2, 900, image, 1
FROM products WHERE slug = 'sneakers-urban';

UPDATE products p
SET p.stock = (
        SELECT COALESCE(SUM(v.stock), 0)
        FROM product_variants v
        WHERE v.product_id = p.id AND v.is_active = 1
    ),
    p.price = (
        SELECT COALESCE(MIN(v.price), p.price)
        FROM product_variants v
        WHERE v.product_id = p.id AND v.is_active = 1
    )
WHERE p.has_variants = 1;
