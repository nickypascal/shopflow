USE shopflow;

-- Jalankan file ini satu kali setelah fitur variasi dan berat produk terpasang.

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
    CONSTRAINT fk_shipping_services_courier
        FOREIGN KEY (courier_id) REFERENCES shipping_couriers(id) ON DELETE CASCADE,
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
    CONSTRAINT fk_shipping_rates_service
        FOREIGN KEY (shipping_service_id) REFERENCES shipping_services(id) ON DELETE CASCADE,
    INDEX idx_shipping_rates_destination (province, city, district),
    INDEX idx_shipping_rates_active (shipping_service_id, is_active)
) ENGINE=InnoDB;

ALTER TABLE orders
    ADD COLUMN subtotal_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER address_id,
    ADD COLUMN cod_fee DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER shipping_cost,
    ADD COLUMN shipping_courier_id BIGINT UNSIGNED NULL AFTER total_weight_grams,
    ADD COLUMN shipping_service_id BIGINT UNSIGNED NULL AFTER shipping_courier_id,
    ADD COLUMN shipping_courier_code VARCHAR(30) NULL AFTER shipping_service_id,
    ADD COLUMN shipping_courier_name VARCHAR(100) NULL AFTER shipping_courier_code,
    ADD COLUMN shipping_service_code VARCHAR(50) NULL AFTER shipping_courier_name,
    ADD COLUMN shipping_service_name VARCHAR(100) NULL AFTER shipping_service_code,
    ADD COLUMN charged_weight_kg INT UNSIGNED NOT NULL DEFAULT 1 AFTER shipping_service_name,
    ADD COLUMN shipping_estimation VARCHAR(100) NULL AFTER charged_weight_kg,
    ADD COLUMN tracking_number VARCHAR(100) NULL AFTER shipping_estimation,
    ADD COLUMN delivered_at DATETIME NULL AFTER shipped_at,
    ADD INDEX idx_orders_shipping_courier (shipping_courier_id),
    ADD INDEX idx_orders_shipping_service (shipping_service_id),
    ADD INDEX idx_orders_tracking_number (tracking_number),
    ADD CONSTRAINT fk_orders_shipping_courier
        FOREIGN KEY (shipping_courier_id) REFERENCES shipping_couriers(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_orders_shipping_service
        FOREIGN KEY (shipping_service_id) REFERENCES shipping_services(id) ON DELETE SET NULL;

CREATE TABLE shipment_histories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    location VARCHAR(150) NULL,
    changed_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_shipment_histories_order
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_shipment_histories_user
        FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_shipment_histories_order (order_id, created_at),
    INDEX idx_shipment_histories_status (status)
) ENGINE=InnoDB;

-- Mempertahankan subtotal pesanan lama.
UPDATE orders
SET subtotal_amount = GREATEST(total_amount - shipping_cost, 0)
WHERE subtotal_amount = 0;

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

-- Tarif contoh penelitian untuk alamat demo Rembang, Jawa Tengah.
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

INSERT INTO store_settings (setting_key, setting_value) VALUES
('store_province', 'Jawa Tengah'),
('store_city', 'Rembang'),
('store_district', 'Rembang'),
('store_postal_code', '59217'),
('store_full_address', 'Jl. Pemuda No. 10, Rembang'),
('shipping_rounding_grams', '1000');
