-- Local-dev seed data. Plain API keys are documented in README.md (local use only).

INSERT INTO vendors (id, name) VALUES
  (1, 'Aurora Audio'),
  (2, 'Brightwave Labs'),
  (3, 'Cobalt Sound');

INSERT INTO products (id, vendor_id, sku, name, category, price_cents, active) VALUES
  (1,  1, 'AUR-SYNTH-001', 'Nebula Synth',        'synth',       4999, 1),
  (2,  1, 'AUR-FX-001',    'Prism Reverb',        'effect',      2999, 1),
  (3,  1, 'AUR-FX-002',    'Echo Chamber',        'effect',      1999, 1),
  (4,  1, 'AUR-SMP-001',   'Ambient Textures',    'sample_pack', 1499, 1),
  (5,  2, 'BRW-SYNTH-001', 'Solstice Wavetable',  'synth',       6999, 1),
  (6,  2, 'BRW-FX-001',    'Tape Warmth',         'effect',      3499, 1),
  (7,  2, 'BRW-DAW-001',   'Loop Studio',         'daw',         9999, 1),
  (8,  2, 'BRW-SMP-001',   'Analog Drums Vol. 1', 'sample_pack', 1999, 1),  -- only 3 keys (out-of-stock tests)
  (9,  3, 'CBL-SYNTH-001', 'Iron FM',             'synth',       3999, 1),
  (10, 3, 'CBL-FX-001',    'Vinyl Crackle',       'effect',       999, 0),  -- inactive (validation tests)
  (11, 3, 'CBL-SMP-001',   'Lo-fi Keys',          'sample_pack', 1299, 1),
  (12, 3, 'CBL-OTH-001',   'MIDI Toolkit',        'other',       1999, 1);  -- 100 keys (load test)

-- Keys: 25 per product, except product 8 (3) and product 12 (100). Total 353.
INSERT INTO license_keys (product_id, key_value)
WITH RECURSIVE seq (n) AS (
  SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 100
)
SELECT p.id, CONCAT(p.sku, '-', LPAD(seq.n, 4, '0'))
FROM products p
JOIN seq ON seq.n <= CASE p.id WHEN 8 THEN 3 WHEN 12 THEN 100 ELSE 25 END;

-- api_key_hash = SHA-256 of the plain key (see README for the plain keys).
INSERT INTO resellers (id, name, api_key_hash, country) VALUES
  (1, 'Harmony Music Store',  '9b1582d7f1cf8bec3e19c3ec73aa3d409b78b17e241ddd92aec0f1e9e989517c', 'CA'),
  (2, 'Beatline Distribution','3824bd4c0ad2408d0208c9246cb2af049c5f03fba173f69e243f33e4d02d892d', 'US');
