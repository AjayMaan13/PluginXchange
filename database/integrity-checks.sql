-- Integrity checks. Every query must return ZERO rows on a healthy database.
-- Run with `make integrity` (bin/integrity.php runs each query and fails if any returns rows).
-- Keep one statement per query, end each with a semicolon, and no semicolons inside comments.

-- 1. Sold keys that point at no order (license_keys.order_id has no FK on purpose, see schema.sql)
SELECT 'sold_key_without_order' AS check_name, k.id AS id, k.key_value AS detail
FROM license_keys k
LEFT JOIN orders o ON o.id = k.order_id
WHERE k.status = 'sold' AND o.id IS NULL;

-- 2. Fulfilled orders whose number of sold keys differs from the quantity
SELECT 'fulfilled_order_key_count_mismatch' AS check_name, o.id AS id,
       CONCAT('quantity=', o.quantity, ' sold_keys=', COUNT(k.id)) AS detail
FROM orders o
LEFT JOIN license_keys k ON k.order_id = o.id AND k.status = 'sold'
WHERE o.status = 'fulfilled'
GROUP BY o.id, o.quantity
HAVING COUNT(k.id) <> o.quantity;

-- 3. Orders whose total is not quantity times the unit price
SELECT 'order_total_mismatch' AS check_name, o.id AS id,
       CONCAT('total=', o.total_cents, ' expected=', o.quantity * o.unit_price_cents) AS detail
FROM orders o
WHERE o.total_cents <> o.quantity * o.unit_price_cents;

-- 4. Keys still marked sold although their order was cancelled (they should be revoked) or failed
SELECT 'sold_key_on_unfulfilled_order' AS check_name, k.id AS id,
       CONCAT('order=', o.id, ' status=', o.status) AS detail
FROM license_keys k
JOIN orders o ON o.id = k.order_id
WHERE k.status = 'sold' AND o.status <> 'fulfilled';

-- 5. Available keys that still reference an order
SELECT 'available_key_with_order' AS check_name, k.id AS id, CONCAT('order=', k.order_id) AS detail
FROM license_keys k
WHERE k.status = 'available' AND k.order_id IS NOT NULL;

-- 6. Keys attached to an order for a different product
SELECT 'key_product_mismatch' AS check_name, k.id AS id,
       CONCAT('key_product=', k.product_id, ' order_product=', o.product_id) AS detail
FROM license_keys k
JOIN orders o ON o.id = k.order_id
WHERE k.product_id <> o.product_id;
