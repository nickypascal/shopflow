USE shopflow;

CREATE TABLE IF NOT EXISTS wishlist_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    preferred_variant_id BIGINT UNSIGNED NULL,
    price_when_added DECIMAL(15,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wishlist_items_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_wishlist_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_wishlist_items_variant FOREIGN KEY (preferred_variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
    UNIQUE KEY uq_wishlist_user_product (user_id, product_id),
    INDEX idx_wishlist_items_product (product_id),
    INDEX idx_wishlist_items_variant (preferred_variant_id),
    INDEX idx_wishlist_items_created_at (created_at)
) ENGINE=InnoDB;
