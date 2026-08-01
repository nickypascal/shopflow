USE shopflow;

ALTER TABLE orders
    ADD COLUMN payment_category VARCHAR(40) NOT NULL DEFAULT 'transfer_bank' AFTER shipping_cost,
    ADD COLUMN payment_method VARCHAR(80) NOT NULL DEFAULT 'bca' AFTER payment_category,
    ADD COLUMN payment_detail VARCHAR(100) NULL AFTER payment_method;
