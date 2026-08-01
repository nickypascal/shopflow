USE shopflow;

CREATE TABLE IF NOT EXISTS return_requests (
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
    INDEX idx_return_requests_resolution (resolution_type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS return_items (
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
    INDEX idx_return_items_variant (variant_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS return_images (
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

CREATE TABLE IF NOT EXISTS return_histories (
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

INSERT INTO store_settings (setting_key, setting_value)
SELECT 'return_period_days', '7'
WHERE NOT EXISTS (
    SELECT 1 FROM store_settings WHERE setting_key = 'return_period_days'
);

INSERT INTO store_settings (setting_key, setting_value)
SELECT 'return_policy', 'Retur dapat diajukan maksimal 7 hari setelah pesanan selesai. Produk wajib dikembalikan lengkap dan bukti foto diperlukan untuk kerusakan, salah produk, atau barang tidak sesuai.'
WHERE NOT EXISTS (
    SELECT 1 FROM store_settings WHERE setting_key = 'return_policy'
);
