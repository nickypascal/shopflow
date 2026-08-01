USE shopflow;

ALTER TABLE orders
    ADD COLUMN cancellation_reason VARCHAR(255) NULL AFTER shipping_address,
    ADD COLUMN cancelled_at DATETIME NULL AFTER cancellation_reason;
