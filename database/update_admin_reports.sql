USE shopflow;

-- Data konversi wishlist. Kolom diisi otomatis ketika produk wishlist dibeli.
ALTER TABLE wishlist_items
    ADD COLUMN IF NOT EXISTS purchased_at DATETIME NULL AFTER price_when_added,
    ADD COLUMN IF NOT EXISTS purchased_order_id BIGINT UNSIGNED NULL AFTER purchased_at;

-- Index laporan agar filter tanggal, status, pengguna, pembayaran, produk,
-- promosi, ulasan, retur, dan konversi wishlist tetap cepat.
ALTER TABLE orders
    ADD INDEX IF NOT EXISTS idx_orders_status_created_report (status, created_at),
    ADD INDEX IF NOT EXISTS idx_orders_user_created_report (user_id, created_at),
    ADD INDEX IF NOT EXISTS idx_orders_payment_created_report (payment_method, created_at),
    ADD INDEX IF NOT EXISTS idx_orders_category_created_report (payment_category, created_at),
    ADD INDEX IF NOT EXISTS idx_orders_shipping_created_report (shipping_courier_code, created_at);

ALTER TABLE order_items
    ADD INDEX IF NOT EXISTS idx_order_items_product_order_report (product_id, order_id),
    ADD INDEX IF NOT EXISTS idx_order_items_variant_order_report (variant_id, order_id);

ALTER TABLE stock_histories
    ADD INDEX IF NOT EXISTS idx_stock_histories_type_created_report (type, created_at),
    ADD INDEX IF NOT EXISTS idx_stock_histories_product_created_report (product_id, created_at);

ALTER TABLE product_reviews
    ADD INDEX IF NOT EXISTS idx_reviews_status_created_report (status, created_at),
    ADD INDEX IF NOT EXISTS idx_reviews_rating_created_report (rating, created_at);

ALTER TABLE return_requests
    ADD INDEX IF NOT EXISTS idx_returns_refund_date_report (refund_status, refunded_at),
    ADD INDEX IF NOT EXISTS idx_returns_resolution_created_report (resolution_type, created_at);

ALTER TABLE return_items
    ADD INDEX IF NOT EXISTS idx_return_items_product_request_report (product_id, return_request_id),
    ADD INDEX IF NOT EXISTS idx_return_items_reason_report (reason);

ALTER TABLE promotion_usages
    ADD INDEX IF NOT EXISTS idx_promotion_usages_date_report (used_at),
    ADD INDEX IF NOT EXISTS idx_promotion_usages_status_date_report (usage_status, used_at);

ALTER TABLE wishlist_items
    ADD INDEX IF NOT EXISTS idx_wishlist_created_report (created_at),
    ADD INDEX IF NOT EXISTS idx_wishlist_purchased_report (purchased_at),
    ADD INDEX IF NOT EXISTS idx_wishlist_product_purchased_report (product_id, purchased_at);

-- Foreign key konversi wishlist dibuat hanya jika belum tersedia.
SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'wishlist_items'
      AND CONSTRAINT_NAME = 'fk_wishlist_purchased_order'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @fk_sql := IF(
    @fk_exists = 0,
    'ALTER TABLE wishlist_items ADD CONSTRAINT fk_wishlist_purchased_order FOREIGN KEY (purchased_order_id) REFERENCES orders(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE report_stmt FROM @fk_sql;
EXECUTE report_stmt;
DEALLOCATE PREPARE report_stmt;
