USE shopflow;

-- Lengkapi tabel notifikasi lama tanpa menghapus data yang sudah ada.
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS notification_key VARCHAR(191) NULL AFTER url,
    ADD COLUMN IF NOT EXISTS related_type VARCHAR(50) NULL AFTER notification_key,
    ADD COLUMN IF NOT EXISTS related_id BIGINT UNSIGNED NULL AFTER related_type;

CREATE INDEX IF NOT EXISTS idx_notifications_user_read_created
    ON notifications (user_id, is_read, created_at);
CREATE INDEX IF NOT EXISTS idx_notifications_related
    ON notifications (related_type, related_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_notifications_user_key
    ON notifications (user_id, notification_key);

CREATE TABLE IF NOT EXISTS notification_preferences (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    order_updates TINYINT(1) NOT NULL DEFAULT 1,
    shipping_updates TINYINT(1) NOT NULL DEFAULT 1,
    promotion_updates TINYINT(1) NOT NULL DEFAULT 1,
    wishlist_price_updates TINYINT(1) NOT NULL DEFAULT 1,
    wishlist_stock_updates TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_preferences_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE promotions
    ADD COLUMN IF NOT EXISTS notification_sent_at DATETIME NULL AFTER is_active;

-- Buat preferensi default untuk semua pelanggan yang sudah ada.
INSERT INTO notification_preferences (user_id)
SELECT u.id
FROM users u
LEFT JOIN notification_preferences np ON np.user_id = u.id
WHERE u.role = 'customer' AND np.user_id IS NULL;
