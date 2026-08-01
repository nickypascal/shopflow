USE shopflow;

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS average_rating DECIMAL(3,2) NOT NULL DEFAULT 0 AFTER default_weight_grams,
    ADD COLUMN IF NOT EXISTS review_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER average_rating;

CREATE TABLE IF NOT EXISTS product_reviews (
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
    INDEX idx_product_reviews_rating (rating)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_review_images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id BIGINT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_review_images_review FOREIGN KEY (review_id) REFERENCES product_reviews(id) ON DELETE CASCADE,
    INDEX idx_product_review_images_review (review_id, sort_order)
) ENGINE=InnoDB;

INSERT INTO store_settings (setting_key, setting_value)
SELECT 'review_moderation_enabled', '0'
WHERE NOT EXISTS (
    SELECT 1 FROM store_settings WHERE setting_key = 'review_moderation_enabled'
);

-- Sinkronkan statistik apabila tabel ulasan sudah berisi data.
UPDATE products p
LEFT JOIN (
    SELECT product_id, ROUND(AVG(rating), 2) AS average_rating, COUNT(*) AS review_count
    FROM product_reviews
    WHERE status = 'PUBLISHED'
    GROUP BY product_id
) r ON r.product_id = p.id
SET p.average_rating = COALESCE(r.average_rating, 0),
    p.review_count = COALESCE(r.review_count, 0);
