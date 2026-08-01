-- ShopFlow CI Database
-- Struktur database dan data sintetis untuk Playwright/GitHub Actions.
-- Tidak memuat transaksi atau data pelanggan produksi.
-- Database dibuat ulang pada setiap eksekusi workflow CI.

CREATE DATABASE IF NOT EXISTS shopflow
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE shopflow;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS notification_preferences;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS return_histories;
DROP TABLE IF EXISTS return_images;
DROP TABLE IF EXISTS return_items;
DROP TABLE IF EXISTS return_requests;
DROP TABLE IF EXISTS product_review_images;
DROP TABLE IF EXISTS product_reviews;
DROP TABLE IF EXISTS promotion_usages;
DROP TABLE IF EXISTS promotion_shipping_services;
DROP TABLE IF EXISTS promotion_payment_methods;
DROP TABLE IF EXISTS promotion_categories;
DROP TABLE IF EXISTS promotion_products;
DROP TABLE IF EXISTS promotions;
DROP TABLE IF EXISTS admin_activity_logs;
DROP TABLE IF EXISTS stock_histories;
DROP TABLE IF EXISTS store_settings;
DROP TABLE IF EXISTS shipment_histories;
DROP TABLE IF EXISTS order_status_history;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS shipping_rates;
DROP TABLE IF EXISTS shipping_services;
DROP TABLE IF EXISTS shipping_couriers;
DROP TABLE IF EXISTS wishlist_items;
DROP TABLE IF EXISTS user_addresses;
DROP TABLE IF EXISTS product_variants;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'customer',
    phone VARCHAR(30) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    avatar VARCHAR(255) NULL,
    admin_note VARCHAR(500) NULL,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role (role),
    INDEX idx_users_status (status)
) ENGINE=InnoDB;

CREATE TABLE user_addresses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(50) NOT NULL,
    recipient_name VARCHAR(100) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    province VARCHAR(100) NOT NULL,
    city VARCHAR(100) NOT NULL,
    district VARCHAR(100) NOT NULL,
    village VARCHAR(100) NULL,
    postal_code VARCHAR(10) NOT NULL,
    full_address TEXT NOT NULL,
    courier_note VARCHAR(255) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_addresses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_addresses_user (user_id),
    INDEX idx_user_addresses_primary (user_id, is_primary)
) ENGINE=InnoDB;

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

CREATE TABLE products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(180) NOT NULL UNIQUE,
    description TEXT NOT NULL,
    category VARCHAR(80) NOT NULL,
    category_id BIGINT UNSIGNED NULL,
    price DECIMAL(15,2) NOT NULL,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 5,
    image VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    has_variants TINYINT(1) NOT NULL DEFAULT 0,
    default_weight_grams INT UNSIGNED NOT NULL DEFAULT 0,
    average_rating DECIMAL(3,2) NOT NULL DEFAULT 0,
    review_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_products_name (name),
    INDEX idx_products_category (category),
    INDEX idx_products_category_id (category_id),
    INDEX idx_products_status (status),
    INDEX idx_products_stock (stock)
 ) ENGINE=InnoDB;

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
    CONSTRAINT fk_product_variants_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_variants_product (product_id),
    INDEX idx_product_variants_active (product_id, is_active),
    INDEX idx_product_variants_stock (stock)

) ENGINE=InnoDB;

CREATE TABLE wishlist_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    preferred_variant_id BIGINT UNSIGNED NULL,
    price_when_added DECIMAL(15,2) NULL,
    purchased_at DATETIME NULL,
    purchased_order_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wishlist_items_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_wishlist_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_wishlist_items_variant FOREIGN KEY (preferred_variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
    UNIQUE KEY uq_wishlist_user_product (user_id, product_id),
    INDEX idx_wishlist_items_product (product_id),
    INDEX idx_wishlist_items_variant (preferred_variant_id),
    INDEX idx_wishlist_items_created_at (created_at),
    INDEX idx_wishlist_purchased_report (purchased_at),
    INDEX idx_wishlist_product_purchased_report (product_id, purchased_at)
) ENGINE=InnoDB;


CREATE TABLE shipping_couriers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    courier_code VARCHAR(30) NOT NULL UNIQUE,
    courier_name VARCHAR(100) NOT NULL,
    logo VARCHAR(255) NULL,
    supports_cod TINYINT(1) NOT NULL DEFAULT 0,
    supports_cod_check TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_shipping_couriers_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE shipping_services (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    courier_id BIGINT UNSIGNED NOT NULL,
    service_code VARCHAR(50) NOT NULL,
    service_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    min_delivery_days INT UNSIGNED NOT NULL DEFAULT 1,
    max_delivery_days INT UNSIGNED NOT NULL DEFAULT 3,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_shipping_services_courier FOREIGN KEY (courier_id) REFERENCES shipping_couriers(id) ON DELETE CASCADE,
    UNIQUE KEY uq_shipping_service_code (courier_id, service_code),
    INDEX idx_shipping_services_active (courier_id, is_active)
) ENGINE=InnoDB;

CREATE TABLE shipping_rates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shipping_service_id BIGINT UNSIGNED NOT NULL,
    province VARCHAR(100) NOT NULL,
    city VARCHAR(100) NOT NULL,
    district VARCHAR(100) NULL,
    first_kg_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    next_kg_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    supports_cod TINYINT(1) NOT NULL DEFAULT 0,
    supports_cod_check TINYINT(1) NOT NULL DEFAULT 0,
    cod_fee DECIMAL(15,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_shipping_rates_service FOREIGN KEY (shipping_service_id) REFERENCES shipping_services(id) ON DELETE CASCADE,
    INDEX idx_shipping_rates_destination (province, city, district),
    INDEX idx_shipping_rates_active (shipping_service_id, is_active)
) ENGINE=InnoDB;

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
    notification_sent_at DATETIME NULL,
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
    CONSTRAINT fk_promotion_products_promotion FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    CONSTRAINT fk_promotion_products_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_promotion_product (promotion_id, product_id),
    INDEX idx_promotion_products_product (product_id)
) ENGINE=InnoDB;

CREATE TABLE promotion_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    CONSTRAINT fk_promotion_categories_promotion FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    CONSTRAINT fk_promotion_categories_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    UNIQUE KEY uq_promotion_category (promotion_id, category_id),
    INDEX idx_promotion_categories_category (category_id)
) ENGINE=InnoDB;

CREATE TABLE promotion_payment_methods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id BIGINT UNSIGNED NOT NULL,
    payment_method VARCHAR(80) NOT NULL,
    CONSTRAINT fk_promotion_payments_promotion FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    UNIQUE KEY uq_promotion_payment (promotion_id, payment_method),
    INDEX idx_promotion_payment_method (payment_method)
) ENGINE=InnoDB;

CREATE TABLE promotion_shipping_services (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id BIGINT UNSIGNED NOT NULL,
    shipping_service_id BIGINT UNSIGNED NOT NULL,
    CONSTRAINT fk_promotion_shipping_promotion FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    CONSTRAINT fk_promotion_shipping_service FOREIGN KEY (shipping_service_id) REFERENCES shipping_services(id) ON DELETE CASCADE,
    UNIQUE KEY uq_promotion_shipping_service (promotion_id, shipping_service_id),
    INDEX idx_promotion_shipping_service (shipping_service_id)
) ENGINE=InnoDB;

CREATE TABLE orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(40) NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    address_id BIGINT UNSIGNED NULL,
    subtotal_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL,
    shipping_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
    cod_fee DECIMAL(15,2) NOT NULL DEFAULT 0,
    promotion_id BIGINT UNSIGNED NULL,
    voucher_code VARCHAR(50) NULL,
    promotion_name VARCHAR(150) NULL,
    promotion_trigger_type VARCHAR(20) NULL,
    discount_type VARCHAR(30) NULL,
    discount_value DECIMAL(15,2) NOT NULL DEFAULT 0,
    product_discount DECIMAL(15,2) NOT NULL DEFAULT 0,
    shipping_discount DECIMAL(15,2) NOT NULL DEFAULT 0,
    amount_before_discount DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_weight_grams INT UNSIGNED NOT NULL DEFAULT 0,
    shipping_courier_id BIGINT UNSIGNED NULL,
    shipping_service_id BIGINT UNSIGNED NULL,
    shipping_courier_code VARCHAR(30) NULL,
    shipping_courier_name VARCHAR(100) NULL,
    shipping_service_code VARCHAR(50) NULL,
    shipping_service_name VARCHAR(100) NULL,
    charged_weight_kg INT UNSIGNED NOT NULL DEFAULT 1,
    shipping_estimation VARCHAR(100) NULL,
    tracking_number VARCHAR(100) NULL,
    payment_category VARCHAR(40) NOT NULL DEFAULT 'transfer_bank',
    payment_method VARCHAR(80) NOT NULL DEFAULT 'bca',
    payment_detail VARCHAR(100) NULL,
    payment_status VARCHAR(30) NOT NULL DEFAULT 'Belum Dibayar',
    status VARCHAR(50) NOT NULL DEFAULT 'Menunggu Pembayaran',
    shipping_name VARCHAR(100) NOT NULL,
    shipping_phone VARCHAR(30) NOT NULL,
    shipping_address TEXT NOT NULL,
    shipping_address_label VARCHAR(50) NULL,
    shipping_province VARCHAR(100) NULL,
    shipping_city VARCHAR(100) NULL,
    shipping_district VARCHAR(100) NULL,
    shipping_village VARCHAR(100) NULL,
    shipping_postal_code VARCHAR(10) NULL,
    courier_note VARCHAR(255) NULL,
    cancellation_reason VARCHAR(255) NULL,
    cancelled_at DATETIME NULL,
    rejection_reason VARCHAR(255) NULL,
    rejected_at DATETIME NULL,
    admin_notes TEXT NULL,
    confirmed_at DATETIME NULL,
    processed_at DATETIME NULL,
    shipped_at DATETIME NULL,
    delivered_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_orders_address FOREIGN KEY (address_id) REFERENCES user_addresses(id) ON DELETE SET NULL,
    CONSTRAINT fk_orders_promotion FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE SET NULL,
    CONSTRAINT fk_orders_shipping_courier FOREIGN KEY (shipping_courier_id) REFERENCES shipping_couriers(id) ON DELETE SET NULL,
    CONSTRAINT fk_orders_shipping_service FOREIGN KEY (shipping_service_id) REFERENCES shipping_services(id) ON DELETE SET NULL,
    INDEX idx_orders_user (user_id),
    INDEX idx_orders_address (address_id),
    INDEX idx_orders_promotion (promotion_id),
    INDEX idx_orders_voucher_code (voucher_code),
    INDEX idx_orders_shipping_courier (shipping_courier_id),
    INDEX idx_orders_shipping_service (shipping_service_id),
    INDEX idx_orders_tracking_number (tracking_number),
    INDEX idx_orders_payment_method (payment_method),
    INDEX idx_orders_payment_status (payment_status),
    INDEX idx_orders_status (status),
    INDEX idx_orders_created_at (created_at),
    INDEX idx_orders_status_created_report (status, created_at),
    INDEX idx_orders_user_created_report (user_id, created_at),
    INDEX idx_orders_payment_created_report (payment_method, created_at),
    INDEX idx_orders_category_created_report (payment_category, created_at),
    INDEX idx_orders_shipping_created_report (shipping_courier_code, created_at)
) ENGINE=InnoDB;

ALTER TABLE wishlist_items
    ADD CONSTRAINT fk_wishlist_purchased_order
    FOREIGN KEY (purchased_order_id) REFERENCES orders(id) ON DELETE SET NULL;

CREATE TABLE order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NULL,
    product_name VARCHAR(150) NOT NULL,
    product_image VARCHAR(255) NULL,
    variant_name VARCHAR(150) NULL,
    variant_sku VARCHAR(100) NULL,
    variant_color VARCHAR(100) NULL,
    variant_size VARCHAR(100) NULL,
    variant_material VARCHAR(100) NULL,
    price DECIMAL(15,2) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    subtotal DECIMAL(15,2) NOT NULL,
    item_weight_grams INT UNSIGNED NOT NULL DEFAULT 0,
    total_weight_grams INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_order_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
    INDEX idx_order_items_order (order_id),
    INDEX idx_order_items_product (product_id),
    INDEX idx_order_items_variant (variant_id),
    INDEX idx_order_items_product_order_report (product_id, order_id),
    INDEX idx_order_items_variant_order_report (variant_id, order_id)
) ENGINE=InnoDB;


CREATE TABLE product_reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    review_title VARCHAR(150) NULL,
    review_text TEXT NOT NULL,
    variant_name VARCHAR(150) NULL,
    variant_sku VARCHAR(100) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PUBLISHED',
    rejection_reason VARCHAR(255) NULL,
    admin_reply TEXT NULL,
    replied_by BIGINT UNSIGNED NULL,
    replied_at DATETIME NULL,
    is_edited TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_reviews_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_reviews_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
    CONSTRAINT fk_product_reviews_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_reviews_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_reviews_reply_admin FOREIGN KEY (replied_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_product_reviews_order_item (order_item_id),
    INDEX idx_product_reviews_product_status_created (product_id, status, created_at),
    INDEX idx_product_reviews_user (user_id, created_at),
    INDEX idx_product_reviews_rating (rating),
    INDEX idx_reviews_status_created_report (status, created_at),
    INDEX idx_reviews_rating_created_report (rating, created_at)
) ENGINE=InnoDB;

CREATE TABLE product_review_images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id BIGINT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_review_images_review FOREIGN KEY (review_id) REFERENCES product_reviews(id) ON DELETE CASCADE,
    INDEX idx_product_review_images_review (review_id, sort_order)
) ENGINE=InnoDB;

CREATE TABLE return_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_number VARCHAR(50) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    resolution_type VARCHAR(20) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'SUBMITTED',
    reason_summary VARCHAR(150) NOT NULL,
    customer_note TEXT NULL,
    refund_method VARCHAR(50) NULL,
    refund_account_name VARCHAR(100) NULL,
    refund_account_number VARCHAR(100) NULL,
    refund_bank_name VARCHAR(100) NULL,
    refund_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    refund_status VARCHAR(30) NOT NULL DEFAULT 'NOT_REQUIRED',
    refund_reference VARCHAR(100) NULL,
    refunded_at DATETIME NULL,
    customer_return_courier VARCHAR(100) NULL,
    customer_return_tracking_number VARCHAR(100) NULL,
    customer_shipped_at DATETIME NULL,
    inspection_result VARCHAR(100) NULL,
    inspection_note TEXT NULL,
    received_by_store_at DATETIME NULL,
    processed_at DATETIME NULL,
    completed_at DATETIME NULL,
    replacement_product_id BIGINT UNSIGNED NULL,
    replacement_variant_id BIGINT UNSIGNED NULL,
    replacement_tracking_number VARCHAR(100) NULL,
    replacement_shipped_at DATETIME NULL,
    rejection_reason VARCHAR(255) NULL,
    admin_note TEXT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_return_requests_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_return_requests_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_return_requests_admin FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_return_requests_replacement_product FOREIGN KEY (replacement_product_id) REFERENCES products(id) ON DELETE SET NULL,
    CONSTRAINT fk_return_requests_replacement_variant FOREIGN KEY (replacement_variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
    INDEX idx_return_requests_user_status (user_id, status, created_at),
    INDEX idx_return_requests_order (order_id),
    INDEX idx_return_requests_status_created (status, created_at),
    INDEX idx_return_requests_resolution (resolution_type),
    INDEX idx_returns_refund_date_report (refund_status, refunded_at),
    INDEX idx_returns_resolution_created_report (resolution_type, created_at)
) ENGINE=InnoDB;

CREATE TABLE return_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_request_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NULL,
    product_name VARCHAR(150) NOT NULL,
    product_image VARCHAR(255) NULL,
    variant_name VARCHAR(150) NULL,
    variant_sku VARCHAR(100) NULL,
    purchased_quantity INT UNSIGNED NOT NULL,
    return_quantity INT UNSIGNED NOT NULL,
    item_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    requested_refund_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    approved_refund_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    reason VARCHAR(150) NOT NULL,
    condition_note TEXT NULL,
    item_status VARCHAR(40) NOT NULL DEFAULT 'SUBMITTED',
    restock_status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
    restocked_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    restocked_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_return_items_request FOREIGN KEY (return_request_id) REFERENCES return_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_return_items_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id),
    CONSTRAINT fk_return_items_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_return_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
    INDEX idx_return_items_request (return_request_id),
    INDEX idx_return_items_order_item (order_item_id),
    INDEX idx_return_items_product (product_id),
    INDEX idx_return_items_variant (variant_id),
    INDEX idx_return_items_product_request_report (product_id, return_request_id),
    INDEX idx_return_items_reason_report (reason)
) ENGINE=InnoDB;

CREATE TABLE return_images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_request_id BIGINT UNSIGNED NOT NULL,
    return_item_id BIGINT UNSIGNED NULL,
    image_path VARCHAR(255) NOT NULL,
    image_type VARCHAR(50) NOT NULL DEFAULT 'EVIDENCE',
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_return_images_request FOREIGN KEY (return_request_id) REFERENCES return_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_return_images_item FOREIGN KEY (return_item_id) REFERENCES return_items(id) ON DELETE CASCADE,
    INDEX idx_return_images_request (return_request_id, sort_order),
    INDEX idx_return_images_item (return_item_id)
) ENGINE=InnoDB;

CREATE TABLE return_histories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_request_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(40) NOT NULL,
    title VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    changed_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_return_histories_request FOREIGN KEY (return_request_id) REFERENCES return_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_return_histories_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_return_histories_request (return_request_id, created_at),
    INDEX idx_return_histories_status (status)
) ENGINE=InnoDB;

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
    CONSTRAINT fk_promotion_usages_promotion FOREIGN KEY (promotion_id) REFERENCES promotions(id),
    CONSTRAINT fk_promotion_usages_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_promotion_usages_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    UNIQUE KEY uq_promotion_usage_order (order_id),
    INDEX idx_promotion_usages_user (promotion_id, user_id, usage_status),
    INDEX idx_promotion_usages_status (usage_status),
    INDEX idx_promotion_usages_date_report (used_at),
    INDEX idx_promotion_usages_status_date_report (usage_status, used_at)
) ENGINE=InnoDB;

CREATE TABLE order_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(50) NOT NULL,
    note VARCHAR(255) NULL,
    changed_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_status_history_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_status_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_order_status_history_order (order_id),
    INDEX idx_order_status_history_status (status)
) ENGINE=InnoDB;


CREATE TABLE shipment_histories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    location VARCHAR(150) NULL,
    changed_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_shipment_histories_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_shipment_histories_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_shipment_histories_order (order_id, created_at),
    INDEX idx_shipment_histories_status (status)
) ENGINE=InnoDB;

CREATE TABLE stock_histories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NULL,
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
    CONSTRAINT fk_stock_histories_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
    CONSTRAINT fk_stock_histories_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_stock_histories_product (product_id),
    INDEX idx_stock_histories_variant (variant_id),
    INDEX idx_stock_histories_created_at (created_at),
    INDEX idx_stock_histories_type_created_report (type, created_at),
    INDEX idx_stock_histories_product_created_report (product_id, created_at)
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
    notification_key VARCHAR(191) NULL,
    related_type VARCHAR(50) NULL,
    related_id BIGINT UNSIGNED NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_notifications_user_key (user_id, notification_key),
    INDEX idx_notifications_audience_read (audience, is_read),
    INDEX idx_notifications_user_read_created (user_id, is_read, created_at),
    INDEX idx_notifications_related (related_type, related_id)
) ENGINE=InnoDB;

CREATE TABLE notification_preferences (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    order_updates TINYINT(1) NOT NULL DEFAULT 1,
    shipping_updates TINYINT(1) NOT NULL DEFAULT 1,
    promotion_updates TINYINT(1) NOT NULL DEFAULT 1,
    wishlist_price_updates TINYINT(1) NOT NULL DEFAULT 1,
    wishlist_stock_updates TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

INSERT INTO users (name, email, password, role, status) VALUES
('Pelanggan CI ShopFlow', 'customer-ci@shopflow.test', '$2y$12$Q1xpDC70Go3wirYL/tmawesSowrFaUbfKWjq0mHIGGpWoggO6o5TO', 'customer', 'active'),
('Admin CI ShopFlow', 'admin-ci@shopflow.test', '$2y$12$f/ldKqbMjIoG0Gy5dlGmhugTS8fdCAx0sDHnTt/mhZt/BdL0KP6s2', 'admin', 'active');

INSERT INTO notification_preferences (user_id)
SELECT id FROM users WHERE role = 'customer';

INSERT INTO user_addresses (
    user_id, label, recipient_name, phone, province, city, district,
    village, postal_code, full_address, courier_note, is_primary
)
SELECT id, 'Alamat Pengujian', 'Pelanggan CI ShopFlow', '081200000000',
       'Jawa Tengah', 'Rembang', 'Rembang', 'Leteh', '59217',
       'Alamat sintetis khusus pengujian GitHub Actions.',
       'Data ini bukan alamat pelanggan sebenarnya.', 1
FROM users WHERE email = 'customer-ci@shopflow.test';

INSERT INTO categories (name, slug, icon, status) VALUES
('Elektronik', 'elektronik', '💻', 'active'),
('Aksesoris', 'aksesoris', '🎧', 'active'),
('Fashion', 'fashion', '👟', 'active'),
('Rumah Tangga', 'rumah-tangga', '🏠', 'active'),
('Kecantikan', 'kecantikan', '✨', 'active'),
('Makanan dan Minuman', 'makanan-dan-minuman', '🍱', 'active');

INSERT INTO products (name, slug, description, category, category_id, price, stock, low_stock_threshold, image, status, is_featured) VALUES
('Laptop Premium 14', 'laptop-premium-14', 'Laptop ringan untuk belajar, bekerja, dan kebutuhan produktivitas harian.', 'Elektronik', 1, 8500000, 12, 5, 'assets/images/laptop.svg', 'active', 1),
('Smartphone Modern', 'smartphone-modern', 'Smartphone dengan layar jernih, kamera tajam, dan baterai tahan lama.', 'Elektronik', 1, 4250000, 18, 5, 'assets/images/phone.svg', 'active', 1),
('Headphone Wireless', 'headphone-wireless', 'Audio nyaman dengan koneksi Bluetooth dan desain modern.', 'Aksesoris', 2, 875000, 25, 5, 'assets/images/headphone.svg', 'active', 1),
('Smart Watch Active', 'smart-watch-active', 'Pantau aktivitas harian, notifikasi, dan kesehatan dari pergelangan tangan.', 'Aksesoris', 2, 1250000, 15, 5, 'assets/images/watch.svg', 'active', 0),
('Sneakers Urban', 'sneakers-urban', 'Sepatu kasual yang nyaman digunakan untuk aktivitas sehari-hari.', 'Fashion', 3, 625000, 20, 5, 'assets/images/shoes.svg', 'active', 0),
('Tas Kerja Minimalis', 'tas-kerja-minimalis', 'Tas kerja dengan ruang laptop dan kompartemen yang tertata rapi.', 'Fashion', 3, 475000, 14, 5, 'assets/images/bag.svg', 'active', 0),
('Lampu Meja LED', 'lampu-meja-led', 'Lampu meja hemat energi dengan tingkat kecerahan yang dapat disesuaikan.', 'Rumah Tangga', 4, 285000, 30, 5, 'assets/images/lamp.svg', 'active', 0),
('Kursi Kerja Nyaman', 'kursi-kerja-nyaman', 'Kursi ergonomis untuk mendukung posisi duduk saat bekerja atau belajar.', 'Rumah Tangga', 4, 1350000, 8, 5, 'assets/images/chair.svg', 'active', 0);

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
END;

UPDATE products SET has_variants = 1 WHERE slug = 'sneakers-urban';

INSERT INTO product_variants
(product_id, sku, variant_name, color, size, material, price, stock, low_stock_threshold, weight_grams, image, is_active)
SELECT id, 'SNK-BLK-40', 'Hitam / 40', 'Hitam', '40', 'Mesh & Sintetis', 625000, 5, 2, 880, image, 1 FROM products WHERE slug = 'sneakers-urban';
INSERT INTO product_variants
(product_id, sku, variant_name, color, size, material, price, stock, low_stock_threshold, weight_grams, image, is_active)
SELECT id, 'SNK-BLK-41', 'Hitam / 41', 'Hitam', '41', 'Mesh & Sintetis', 625000, 5, 2, 900, image, 1 FROM products WHERE slug = 'sneakers-urban';
INSERT INTO product_variants
(product_id, sku, variant_name, color, size, material, price, stock, low_stock_threshold, weight_grams, image, is_active)
SELECT id, 'SNK-WHT-40', 'Putih / 40', 'Putih', '40', 'Mesh & Sintetis', 650000, 5, 2, 880, image, 1 FROM products WHERE slug = 'sneakers-urban';
INSERT INTO product_variants
(product_id, sku, variant_name, color, size, material, price, stock, low_stock_threshold, weight_grams, image, is_active)
SELECT id, 'SNK-WHT-41', 'Putih / 41', 'Putih', '41', 'Mesh & Sintetis', 650000, 5, 2, 900, image, 1 FROM products WHERE slug = 'sneakers-urban';


INSERT INTO shipping_couriers
(courier_code, courier_name, supports_cod, supports_cod_check, is_active) VALUES
('JNE', 'JNE', 1, 0, 1),
('JNT', 'J&T Express', 1, 1, 1),
('SICEPAT', 'SiCepat', 1, 0, 1),
('ANTERAJA', 'AnterAja', 0, 0, 1),
('TOKO', 'Kurir Toko', 1, 1, 1);

INSERT INTO shipping_services
(courier_id, service_code, service_name, description, min_delivery_days, max_delivery_days, is_active)
SELECT id, 'REG', 'Reguler', 'Layanan reguler JNE.', 2, 4, 1 FROM shipping_couriers WHERE courier_code = 'JNE';
INSERT INTO shipping_services
(courier_id, service_code, service_name, description, min_delivery_days, max_delivery_days, is_active)
SELECT id, 'YES', 'Yakin Esok Sampai', 'Layanan cepat JNE.', 1, 1, 1 FROM shipping_couriers WHERE courier_code = 'JNE';
INSERT INTO shipping_services
(courier_id, service_code, service_name, description, min_delivery_days, max_delivery_days, is_active)
SELECT id, 'EZ', 'EZ Reguler', 'Layanan reguler J&T Express.', 2, 4, 1 FROM shipping_couriers WHERE courier_code = 'JNT';
INSERT INTO shipping_services
(courier_id, service_code, service_name, description, min_delivery_days, max_delivery_days, is_active)
SELECT id, 'REG', 'Reguler', 'Layanan reguler SiCepat.', 2, 4, 1 FROM shipping_couriers WHERE courier_code = 'SICEPAT';
INSERT INTO shipping_services
(courier_id, service_code, service_name, description, min_delivery_days, max_delivery_days, is_active)
SELECT id, 'REG', 'Regular Service', 'Layanan reguler AnterAja.', 2, 5, 1 FROM shipping_couriers WHERE courier_code = 'ANTERAJA';
INSERT INTO shipping_services
(courier_id, service_code, service_name, description, min_delivery_days, max_delivery_days, is_active)
SELECT id, 'SAME_DAY', 'Same Day', 'Pengiriman lokal oleh kurir toko.', 0, 1, 1 FROM shipping_couriers WHERE courier_code = 'TOKO';

INSERT INTO shipping_rates
(shipping_service_id, province, city, district, first_kg_price, next_kg_price, supports_cod, supports_cod_check, cod_fee, is_active)
SELECT s.id, 'Jawa Tengah', 'Rembang', NULL, 12000, 7000, 1, 0, 2000, 1
FROM shipping_services s INNER JOIN shipping_couriers c ON c.id = s.courier_id
WHERE c.courier_code = 'JNE' AND s.service_code = 'REG';
INSERT INTO shipping_rates
(shipping_service_id, province, city, district, first_kg_price, next_kg_price, supports_cod, supports_cod_check, cod_fee, is_active)
SELECT s.id, 'Jawa Tengah', 'Rembang', NULL, 22000, 12000, 0, 0, 0, 1
FROM shipping_services s INNER JOIN shipping_couriers c ON c.id = s.courier_id
WHERE c.courier_code = 'JNE' AND s.service_code = 'YES';
INSERT INTO shipping_rates
(shipping_service_id, province, city, district, first_kg_price, next_kg_price, supports_cod, supports_cod_check, cod_fee, is_active)
SELECT s.id, 'Jawa Tengah', 'Rembang', NULL, 11000, 7000, 1, 1, 2500, 1
FROM shipping_services s INNER JOIN shipping_couriers c ON c.id = s.courier_id
WHERE c.courier_code = 'JNT' AND s.service_code = 'EZ';
INSERT INTO shipping_rates
(shipping_service_id, province, city, district, first_kg_price, next_kg_price, supports_cod, supports_cod_check, cod_fee, is_active)
SELECT s.id, 'Jawa Tengah', 'Rembang', NULL, 10000, 6000, 1, 0, 1500, 1
FROM shipping_services s INNER JOIN shipping_couriers c ON c.id = s.courier_id
WHERE c.courier_code = 'SICEPAT' AND s.service_code = 'REG';
INSERT INTO shipping_rates
(shipping_service_id, province, city, district, first_kg_price, next_kg_price, supports_cod, supports_cod_check, cod_fee, is_active)
SELECT s.id, 'Jawa Tengah', 'Rembang', NULL, 9000, 6000, 0, 0, 0, 1
FROM shipping_services s INNER JOIN shipping_couriers c ON c.id = s.courier_id
WHERE c.courier_code = 'ANTERAJA' AND s.service_code = 'REG';
INSERT INTO shipping_rates
(shipping_service_id, province, city, district, first_kg_price, next_kg_price, supports_cod, supports_cod_check, cod_fee, is_active)
SELECT s.id, 'Jawa Tengah', 'Rembang', 'Rembang', 8000, 3000, 1, 1, 1000, 1
FROM shipping_services s INNER JOIN shipping_couriers c ON c.id = s.courier_id
WHERE c.courier_code = 'TOKO' AND s.service_code = 'SAME_DAY';

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

INSERT INTO store_settings (setting_key, setting_value) VALUES
('store_name', 'ShopFlow'),
('store_logo', ''),
('store_email', 'ci@shopflow.test'),
('store_phone', '+62 812-0000-0000'),
('footer_tagline', 'Belanja mudah, aman, dan terpercaya untuk kebutuhan Anda setiap hari.'),
('service_hours', 'Senin–Sabtu, 08.00–20.00 WIB'),
('store_address', ''),
('currency', 'IDR'),
('default_low_stock', '5'),
('cod_confirmation_hours', '24'),
('cod_policy', 'Pembayaran dilakukan kepada kurir saat pesanan diterima.'),
('cod_check_policy', 'Pelanggan dapat memeriksa kondisi dan kesesuaian produk sebelum membayar.'),
('cancellation_policy', 'Pesanan dapat dibatalkan selama masih menunggu konfirmasi atau pembayaran.'),
('refund_policy', 'Pengembalian dana mengikuti hasil verifikasi toko.'),
('terms_conditions', 'Gunakan layanan ShopFlow secara bertanggung jawab.'),
('privacy_policy', 'Data pelanggan digunakan untuk pemrosesan transaksi dan pengiriman.'),
('store_province', 'Jawa Tengah'),
('store_city', 'Rembang'),
('store_district', 'Rembang'),
('store_postal_code', '59217'),
('store_full_address', 'Alamat Toko Sintetis untuk CI, Rembang'),
('shipping_rounding_grams', '1000'),
('review_moderation_enabled', '0'),
('return_period_days', '7'),
('return_policy', 'Retur dapat diajukan maksimal 7 hari setelah pesanan selesai. Produk wajib dikembalikan lengkap dan bukti foto diperlukan untuk kerusakan, salah produk, atau barang tidak sesuai.');
