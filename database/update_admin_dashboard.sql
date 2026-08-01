USE shopflow;

-- Jalankan SATU KALI pada database ShopFlow yang sudah memakai pembaruan COD Workflow.

ALTER TABLE users
    ADD COLUMN phone VARCHAR(30) NULL AFTER role,
    ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER phone,
    ADD COLUMN avatar VARCHAR(255) NULL AFTER status,
    ADD COLUMN admin_note VARCHAR(500) NULL AFTER avatar,
    ADD COLUMN last_login_at DATETIME NULL AFTER admin_note,
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD INDEX idx_users_role (role),
    ADD INDEX idx_users_status (status);

CREATE TABLE categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    slug VARCHAR(100) NOT NULL UNIQUE,
    icon VARCHAR(20) NOT NULL DEFAULT '🛍️',
    image VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_categories_status (status)
) ENGINE=InnoDB;

INSERT INTO categories (name, slug, icon, status) VALUES
('Elektronik', 'elektronik', '💻', 'active'),
('Aksesoris', 'aksesoris', '🎧', 'active'),
('Fashion', 'fashion', '👟', 'active'),
('Rumah Tangga', 'rumah-tangga', '🏠', 'active'),
('Kecantikan', 'kecantikan', '✨', 'active'),
('Makanan dan Minuman', 'makanan-dan-minuman', '🍱', 'active');

ALTER TABLE products
    ADD COLUMN slug VARCHAR(180) NULL AFTER name,
    ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER category,
    ADD COLUMN low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 5 AFTER stock,
    ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER image,
    ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD INDEX idx_products_category_id (category_id),
    ADD INDEX idx_products_status (status),
    ADD INDEX idx_products_stock (stock);

UPDATE products p
LEFT JOIN categories c ON c.name = p.category
SET p.category_id = c.id,
    p.slug = CONCAT(LOWER(REPLACE(REPLACE(REPLACE(TRIM(p.name), ' ', '-'), '/', '-'), '--', '-')), '-', p.id)
WHERE p.slug IS NULL OR p.slug = '';

ALTER TABLE products
    MODIFY slug VARCHAR(180) NOT NULL,
    ADD UNIQUE INDEX uk_products_slug (slug),
    ADD CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL;

ALTER TABLE orders
    ADD COLUMN order_number VARCHAR(40) NULL AFTER id,
    ADD COLUMN payment_status VARCHAR(30) NOT NULL DEFAULT 'Belum Dibayar' AFTER payment_detail,
    ADD COLUMN admin_notes TEXT NULL AFTER rejected_at,
    ADD COLUMN confirmed_at DATETIME NULL AFTER admin_notes,
    ADD COLUMN processed_at DATETIME NULL AFTER confirmed_at,
    ADD COLUMN shipped_at DATETIME NULL AFTER processed_at,
    ADD COLUMN completed_at DATETIME NULL AFTER shipped_at,
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD INDEX idx_orders_payment_status (payment_status),
    ADD INDEX idx_orders_created_at (created_at);

UPDATE orders
SET order_number = CONCAT('SF', DATE_FORMAT(created_at, '%Y%m%d'), '-', LPAD(id, 6, '0'))
WHERE order_number IS NULL OR order_number = '';

UPDATE orders SET completed_at = created_at, payment_status = 'Dibayar' WHERE status = 'Selesai' AND completed_at IS NULL;
UPDATE orders SET processed_at = created_at WHERE status = 'Diproses' AND processed_at IS NULL;
UPDATE orders SET shipped_at = created_at WHERE status = 'Dikirim' AND shipped_at IS NULL;

ALTER TABLE orders ADD UNIQUE INDEX uk_orders_order_number (order_number);

ALTER TABLE order_items ADD COLUMN product_image VARCHAR(255) NULL AFTER product_name;
UPDATE order_items oi LEFT JOIN products p ON p.id = oi.product_id SET oi.product_image = p.image WHERE oi.product_image IS NULL;

ALTER TABLE order_status_history
    ADD COLUMN changed_by BIGINT UNSIGNED NULL AFTER note,
    ADD CONSTRAINT fk_order_status_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE stock_histories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(30) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    stock_before INT NOT NULL,
    stock_after INT NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id BIGINT UNSIGNED NULL,
    notes VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_histories_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_histories_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_stock_histories_product (product_id),
    INDEX idx_stock_histories_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE admin_activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NOT NULL,
    activity_type VARCHAR(60) NOT NULL,
    description VARCHAR(500) NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_activity_user FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_admin_activity_admin (admin_id),
    INDEX idx_admin_activity_type (activity_type),
    INDEX idx_admin_activity_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    audience VARCHAR(20) NOT NULL DEFAULT 'admin',
    type VARCHAR(20) NOT NULL DEFAULT 'info',
    title VARCHAR(150) NOT NULL,
    message VARCHAR(500) NOT NULL,
    url VARCHAR(255) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notifications_audience_read (audience, is_read),
    INDEX idx_notifications_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE store_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_store_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO store_settings (setting_key, setting_value) VALUES
('store_name', 'ShopFlow'),
('store_logo', ''),
('store_email', 'admin@shopflow.test'),
('store_phone', ''),
('store_address', ''),
('currency', 'IDR'),
('default_low_stock', '5'),
('cod_confirmation_hours', '24'),
('cod_policy', 'Pembayaran dilakukan kepada kurir saat pesanan diterima.'),
('cod_check_policy', 'Pelanggan dapat memeriksa kondisi dan kesesuaian produk sebelum membayar.'),
('cancellation_policy', 'Pesanan dapat dibatalkan selama masih menunggu konfirmasi atau pembayaran.'),
('refund_policy', 'Pengembalian dana mengikuti hasil verifikasi toko.'),
('terms_conditions', 'Gunakan layanan ShopFlow secara bertanggung jawab.'),
('privacy_policy', 'Data pelanggan digunakan untuk pemrosesan transaksi dan pengiriman.');

INSERT IGNORE INTO users (name, email, password, role, status)
VALUES ('Admin ShopFlow', 'admin@shopflow.test', '$2y$12$9vIvGoxNJ7IercjSD4FItuMC22rfTcwfBQvE/tKlzHoHfNm13BUTu', 'admin', 'active');
