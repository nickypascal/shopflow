-- Pembaruan footer profesional ShopFlow
-- Jalankan satu kali pada database shopflow yang sudah ada.

UPDATE store_settings
SET setting_value = 'hello@shopflow.id'
WHERE setting_key = 'store_email'
  AND setting_value IN ('admin@shopflow.test', 'support@shopflow.test', '');

UPDATE store_settings
SET setting_value = '+62 812-3456-7890'
WHERE setting_key = 'store_phone'
  AND (setting_value IS NULL OR TRIM(setting_value) = '' OR setting_value = '0812-0000-0000');

INSERT INTO store_settings (setting_key, setting_value) VALUES
('footer_tagline', 'Belanja mudah, aman, dan terpercaya untuk kebutuhan Anda setiap hari.'),
('service_hours', 'Senin–Sabtu, 08.00–20.00 WIB')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);
