USE shopflow;

-- Tambahkan peran pengguna bila belum ada.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role') = 0,
    "ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'customer' AFTER password",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Tambahkan informasi penolakan pesanan bila belum ada.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'rejection_reason') = 0,
    "ALTER TABLE orders ADD COLUMN rejection_reason VARCHAR(255) NULL AFTER cancelled_at",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'rejected_at') = 0,
    "ALTER TABLE orders ADD COLUMN rejected_at DATETIME NULL AFTER rejection_reason",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Pesanan COD lama yang masih memakai status pembayaran diubah menjadi menunggu konfirmasi.
UPDATE orders
SET status = 'Menunggu Konfirmasi'
WHERE payment_method IN ('cod', 'cod_check')
  AND status = 'Menunggu Pembayaran';

CREATE TABLE IF NOT EXISTS order_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(50) NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_status_history_order
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_order_status_history_order (order_id),
    INDEX idx_order_status_history_status (status)
) ENGINE=InnoDB;

-- Catat status terakhir untuk pesanan lama yang belum memiliki riwayat.
INSERT INTO order_status_history (order_id, status, note, created_at)
SELECT o.id, o.status, 'Status awal sebelum fitur pelacakan COD ditambahkan.', o.created_at
FROM orders o
WHERE NOT EXISTS (
    SELECT 1 FROM order_status_history h WHERE h.order_id = o.id
);

-- Akun admin demo. Password: admin123
INSERT INTO users (name, email, password, role)
VALUES (
    'Admin ShopFlow',
    'admin@shopflow.test',
    '$2y$12$9vIvGoxNJ7IercjSD4FItuMC22rfTcwfBQvE/tKlzHoHfNm13BUTu',
    'admin'
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    role = 'admin';
