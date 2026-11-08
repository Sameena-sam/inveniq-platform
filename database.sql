-- ============================================================
-- InvenIQ — Complete Database Schema + Seed Data
-- Import via phpMyAdmin: Import → choose this file → Go
-- ============================================================

CREATE DATABASE IF NOT EXISTS inveniq
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE inveniq;

-- ============================================================
-- TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS locations (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  location_code VARCHAR(20)  NOT NULL UNIQUE,
  name          VARCHAR(100) NOT NULL,
  type          ENUM('warehouse','distribution_center','retail_store') DEFAULT 'distribution_center',
  region        VARCHAR(50),
  status        ENUM('active','inactive') DEFAULT 'active',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS suppliers (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  supplier_id         VARCHAR(20)  NOT NULL UNIQUE,
  name                VARCHAR(150) NOT NULL,
  lead_time_days      INT          DEFAULT 7,
  payment_terms_days  INT          DEFAULT 30,
  contact_email       VARCHAR(100),
  contact_phone       VARCHAR(30),
  status              ENUM('active','inactive','on_hold') DEFAULT 'active',
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  sku                 VARCHAR(30)  NOT NULL UNIQUE,
  description         VARCHAR(200) NOT NULL,
  product_group       VARCHAR(100),
  unit_cost           DECIMAL(10,2) DEFAULT 0,
  unit_price          DECIMAL(10,2) DEFAULT 0,
  shelf_life_days     INT           DEFAULT 0,
  min_cover_days      INT           DEFAULT 14,
  max_cover_days      INT           DEFAULT 60,
  safety_stock_days   INT           DEFAULT 7,
  reorder_cover_days  INT           DEFAULT 30,
  ordering_cost       DECIMAL(10,2) DEFAULT 50,
  holding_cost_pct    DECIMAL(5,2)  DEFAULT 20,
  status              ENUM('active','discontinued','seasonal') DEFAULT 'active',
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS stock_balances (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  sku              VARCHAR(30)  NOT NULL,
  location_code    VARCHAR(20)  NOT NULL,
  quantity_on_hand DECIMAL(12,2) DEFAULT 0,
  batch_number     VARCHAR(50),
  expiry_date      DATE NULL,
  last_updated     DATE,
  FOREIGN KEY (sku)           REFERENCES products(sku)   ON UPDATE CASCADE,
  FOREIGN KEY (location_code) REFERENCES locations(location_code) ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS stock_movements (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  sku           VARCHAR(30)  NOT NULL,
  location_code VARCHAR(20)  NOT NULL,
  movement_date DATE         NOT NULL,
  movement_type ENUM('sale','purchase_receipt','transfer_in','transfer_out',
                     'adjustment_in','adjustment_out','return','write_off') NOT NULL,
  quantity      DECIMAL(12,2) NOT NULL,
  reference     VARCHAR(50),
  batch_number  VARCHAR(50),
  expiry_date   DATE NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sku) REFERENCES products(sku) ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS purchase_orders (
  id                     INT AUTO_INCREMENT PRIMARY KEY,
  po_number              VARCHAR(30)   NOT NULL UNIQUE,
  supplier_id            VARCHAR(20)   NOT NULL,
  sku                    VARCHAR(30)   NOT NULL,
  ordered_quantity       DECIMAL(12,2) NOT NULL,
  order_date             DATE          NOT NULL,
  expected_delivery_date DATE,
  unit_cost              DECIMAL(10,2),
  status                 ENUM('open','partially_received','fully_received','cancelled') DEFAULT 'open',
  location_code          VARCHAR(20),
  FOREIGN KEY (supplier_id)   REFERENCES suppliers(supplier_id) ON UPDATE CASCADE,
  FOREIGN KEY (sku)           REFERENCES products(sku)          ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS goods_received (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  grn_number        VARCHAR(30)   NOT NULL UNIQUE,
  po_number         VARCHAR(30)   NOT NULL,
  supplier_id       VARCHAR(20),
  sku               VARCHAR(30)   NOT NULL,
  received_quantity DECIMAL(12,2) NOT NULL,
  receipt_date      DATE          NOT NULL,
  batch_number      VARCHAR(50),
  expiry_date       DATE NULL,
  location_code     VARCHAR(20),
  FOREIGN KEY (po_number) REFERENCES purchase_orders(po_number) ON UPDATE CASCADE,
  FOREIGN KEY (sku)       REFERENCES products(sku)              ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS accounts_payable (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  invoice_number VARCHAR(30)    NOT NULL UNIQUE,
  supplier_id    VARCHAR(20)    NOT NULL,
  po_number      VARCHAR(30),
  amount         DECIMAL(12,2)  NOT NULL,
  invoice_date   DATE           NOT NULL,
  due_date       DATE           NOT NULL,
  payment_date   DATE NULL,
  status         ENUM('unpaid','paid','partially_paid','overdue') DEFAULT 'unpaid',
  FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON UPDATE CASCADE
);

-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO locations (location_code, name, type, region) VALUES
('KL-HUB','Kuala Lumpur Hub',       'distribution_center','Central'),
('JB-DC', 'Johor Bahru DC',         'distribution_center','Southern'),
('PG-HUB','Penang Hub',             'warehouse',          'Northern'),
('SB-DC', 'Shah Alam DC',           'distribution_center','Central');

INSERT INTO suppliers (supplier_id, name, lead_time_days, payment_terms_days, contact_email, contact_phone) VALUES
('SUP-001','Nestle Malaysia Sdn Bhd',   5, 30,'orders@nestle.com.my',   '+603-7628-6000'),
('SUP-002','Reckitt Benckiser (M)',      7, 45,'supply@rb.com.my',       '+603-5628-8000'),
('SUP-003','F&N Beverages Trading',      4, 30,'trade@fn.com.my',        '+603-5628-3000'),
('SUP-004','Gardenia Bakeries (KL)',     2, 14,'orders@gardenia.com.my', '+603-5510-1000'),
('SUP-005','GSK Consumer Healthcare',   8, 45,'supply@gsk.com.my',      '+603-2281-1000'),
('SUP-006','Dutch Lady Milk Industries',5, 30,'orders@dutchlady.com.my','+603-7966-0000'),
('SUP-007','Mamee-Double Decker',       4, 30,'trade@mamee.com',        '+606-652-1111'),
('SUP-008','Procter & Gamble Malaysia', 7, 45,'supply@pg.com.my',       '+603-2282-3000');

INSERT INTO products (sku,description,product_group,unit_cost,unit_price,shelf_life_days,min_cover_days,max_cover_days,safety_stock_days,reorder_cover_days,ordering_cost,holding_cost_pct) VALUES
('SKU-4412','Gardenia Bread Classic 400g',     'Bakery',                     3.60,  4.90,    7,  5, 14,  3, 10,  85.00,28),
('SKU-1089','Nestle Milo 3in1 30 Sachets',     'Beverages - Hot Drinks',    12.40, 16.90,  365, 14, 60,  7, 30, 120.00,22),
('SKU-1042','Panadol Extra 500mg 20 Tablets',  'Healthcare - OTC',           8.20, 12.00, 1095, 21, 90, 10, 45,  95.00,18),
('SKU-7821','Dutch Lady Full Cream Milk 1L',   'Dairy',                      5.80,  7.90,   30,  7, 21,  4, 14,  90.00,26),
('SKU-6630','Maggi Mee Goreng 5 Pack',         'Instant Noodles',            4.20,  6.50,  270, 10, 45,  5, 21,  80.00,20),
('SKU-2018','Milo 1kg Tin',                    'Beverages - Hot Drinks',    18.50, 24.90,  548, 14, 60,  7, 30, 130.00,20),
('SKU-3388','Yakult Original 5 Pack',          'Dairy - Cultured',           5.20,  7.00,   40,  7, 21,  4, 14,  75.00,25),
('SKU-3301','Dettol Antiseptic Liquid 750ml',  'Healthcare - Personal Care',11.80, 16.50, 1825, 21, 90, 10, 45, 100.00,18),
('SKU-2233','F&N 100Plus 325ml 24 Pack',       'Beverages - Carbonated',    28.80, 38.00,  365, 14, 45,  7, 21, 110.00,19),
('SKU-5501','Sunlight Dish Liquid 900ml',      'Household - Cleaning',       6.40,  9.20,  730, 21, 60,  7, 30,  85.00,20),
('SKU-7710','Rejoice Shampoo 340ml',           'Personal Care - Hair',       9.90, 14.50, 1095, 21, 75, 10, 45,  95.00,19),
('SKU-3320','Darlie Toothpaste Twin Pack 225g','Personal Care - Oral',       7.60, 11.00, 1095, 21, 75, 10, 45,  90.00,19),
('SKU-5510','Anlene Gold 3X Milk Powder 400g', 'Dairy - Nutritional',       22.00, 30.90,  548, 14, 60,  7, 30, 115.00,21),
('SKU-9002','Tropicana Twister Orange 1L',     'Beverages - Juice',          4.80,  6.90,   90, 10, 35,  5, 21,  80.00,23),
('SKU-8841','Mamee Monster Snack 25g 6 Pack',  'Snacks',                     2.40,  3.80,  180, 14, 45,  7, 21,  70.00,22),
('SKU-4490','Sunquick Orange Concentrate 840ml','Beverages - Concentrate',   9.20, 13.50,  730, 21, 60,  7, 30,  90.00,20),
('SKU-6610','Maggi Tomato Ketchup 325g',       'Condiments',                 4.50,  6.80,  540, 21, 75, 10, 45,  80.00,20),
('SKU-1130','Scott Toilet Tissue 10 Roll Pack','Household - Paper',         11.20, 15.90,    0, 21, 90, 10, 45, 100.00,16),
('SKU-2290','Nescafe Classic 200g Jar',        'Beverages - Hot Drinks',    16.80, 23.50,  730, 14, 60,  7, 30, 120.00,21),
('SKU-3450','Mamy Poko Pants M 46 Pack',       'Baby Care',                 38.50, 52.90,    0, 14, 60,  7, 30, 140.00,17);

INSERT INTO stock_balances (sku,location_code,quantity_on_hand,batch_number,expiry_date,last_updated) VALUES
('SKU-4412','SB-DC',  120,  'B2026-001','2026-06-18',CURDATE()),
('SKU-1089','KL-HUB', 210,  'B2026-002','2027-03-01',CURDATE()),
('SKU-1042','KL-HUB', 180,  'B2026-003','2027-07-15',CURDATE()),
('SKU-1042','JB-DC',   80,  'B2026-003','2027-07-15',CURDATE()),
('SKU-7821','PG-HUB', 175,  'B2026-004','2026-06-22',CURDATE()),
('SKU-6630','JB-DC',  300,  'B2026-005','2027-01-01',CURDATE()),
('SKU-2018','JB-DC',  360,  'B2026-006','2027-08-01',CURDATE()),
('SKU-3388','KL-HUB',1080,  'B2026-007','2026-06-28',CURDATE()),
('SKU-3301','PG-HUB', 880,  'B2026-008','2030-01-01',CURDATE()),
('SKU-2233','JB-DC', 2700,  'B2026-009','2027-06-01',CURDATE()),
('SKU-5501','PG-HUB',1340,  'B2026-010',NULL,        CURDATE()),
('SKU-7710','KL-HUB',1480,  'B2026-011',NULL,        CURDATE()),
('SKU-3320','SB-DC', 1760,  'B2026-012',NULL,        CURDATE()),
('SKU-5510','KL-HUB', 162,  'B2026-013','2026-08-14',CURDATE()),
('SKU-9002','SB-DC',  528,  'B2026-014','2026-07-04',CURDATE()),
('SKU-8841','KL-HUB',1440,  'B2026-015','2026-08-30',CURDATE()),
('SKU-4490','JB-DC',  744,  'B2026-016','2027-12-01',CURDATE()),
('SKU-6610','KL-HUB', 820,  'B2026-017','2027-10-01',CURDATE()),
('SKU-1130','SB-DC',  940,  'B2026-018',NULL,        CURDATE()),
('SKU-2290','KL-HUB', 630,  'B2026-019','2027-06-01',CURDATE()),
('SKU-3450','JB-DC',  410,  'B2026-020',NULL,        CURDATE());

-- 90 days of sales movements for velocity calculations
INSERT INTO stock_movements (sku,location_code,movement_date,movement_type,quantity,reference) VALUES
('SKU-4412','SB-DC', DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',3600,'SO-3001'),
('SKU-4412','SB-DC', DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',3600,'SO-3002'),
('SKU-4412','SB-DC', DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',3720,'SO-3003'),
('SKU-1089','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',2100,'SO-3010'),
('SKU-1089','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',2100,'SO-3011'),
('SKU-1089','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',2170,'SO-3012'),
('SKU-1089','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 24 DAY),'purchase_receipt',800,'PO-24908'),
('SKU-1042','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',1350,'SO-3020'),
('SKU-1042','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',1250,'SO-3021'),
('SKU-1042','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',1480,'SO-3022'),
('SKU-1042','JB-DC', DATE_SUB(CURDATE(),INTERVAL 60 DAY),'sale',400, 'SO-3023'),
('SKU-7821','PG-HUB',DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',1050,'SO-3030'),
('SKU-7821','PG-HUB',DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',1050,'SO-3031'),
('SKU-7821','PG-HUB',DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',1050,'SO-3032'),
('SKU-6630','JB-DC', DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',1500,'SO-3040'),
('SKU-6630','JB-DC', DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',1500,'SO-3041'),
('SKU-6630','JB-DC', DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',1500,'SO-3042'),
('SKU-2018','JB-DC', DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',1350,'SO-3050'),
('SKU-2018','JB-DC', DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',1350,'SO-3051'),
('SKU-2018','JB-DC', DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',1350,'SO-3052'),
('SKU-3388','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',2700,'SO-3060'),
('SKU-3388','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',2700,'SO-3061'),
('SKU-3388','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',2700,'SO-3062'),
('SKU-3301','PG-HUB',DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',1200,'SO-3070'),
('SKU-3301','PG-HUB',DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',1200,'SO-3071'),
('SKU-3301','PG-HUB',DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',1200,'SO-3072'),
('SKU-2233','JB-DC', DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',1800,'SO-3080'),
('SKU-2233','JB-DC', DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',1800,'SO-3081'),
('SKU-2233','JB-DC', DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',1800,'SO-3082'),
('SKU-2233','JB-DC', DATE_SUB(CURDATE(),INTERVAL 18 DAY),'purchase_receipt',850,'PO-24915'),
('SKU-5501','PG-HUB',DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',600,'SO-3090'),
('SKU-5501','PG-HUB',DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',600,'SO-3091'),
('SKU-5501','PG-HUB',DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',600,'SO-3092'),
('SKU-7710','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',600,'SO-3100'),
('SKU-7710','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',600,'SO-3101'),
('SKU-7710','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',600,'SO-3102'),
('SKU-7710','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 22 DAY),'purchase_receipt',520,'PO-24899'),
('SKU-3320','SB-DC', DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',600,'SO-3110'),
('SKU-3320','SB-DC', DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',600,'SO-3111'),
('SKU-3320','SB-DC', DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',600,'SO-3112'),
('SKU-5510','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',200,'SO-3120'),
('SKU-5510','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',600,'SO-3121'),
('SKU-5510','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',400,'SO-3122'),
('SKU-9002','SB-DC', DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',1440,'SO-3130'),
('SKU-9002','SB-DC', DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',1440,'SO-3131'),
('SKU-9002','SB-DC', DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',1440,'SO-3132'),
('SKU-8841','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',2400,'SO-3140'),
('SKU-8841','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',2400,'SO-3141'),
('SKU-8841','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',2400,'SO-3142'),
('SKU-4490','JB-DC', DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',720,'SO-3150'),
('SKU-4490','JB-DC', DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',720,'SO-3151'),
('SKU-4490','JB-DC', DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',720,'SO-3152'),
('SKU-6610','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',540,'SO-3160'),
('SKU-6610','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',540,'SO-3161'),
('SKU-6610','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',540,'SO-3162'),
('SKU-1130','SB-DC', DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',660,'SO-3170'),
('SKU-1130','SB-DC', DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',660,'SO-3171'),
('SKU-1130','SB-DC', DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',660,'SO-3172'),
('SKU-2290','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',840,'SO-3180'),
('SKU-2290','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',840,'SO-3181'),
('SKU-2290','KL-HUB',DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',840,'SO-3182'),
('SKU-3450','JB-DC', DATE_SUB(CURDATE(),INTERVAL 75 DAY),'sale',480,'SO-3190'),
('SKU-3450','JB-DC', DATE_SUB(CURDATE(),INTERVAL 45 DAY),'sale',480,'SO-3191'),
('SKU-3450','JB-DC', DATE_SUB(CURDATE(),INTERVAL 15 DAY),'sale',480,'SO-3192');

INSERT INTO purchase_orders (po_number,supplier_id,sku,ordered_quantity,order_date,expected_delivery_date,unit_cost,status,location_code) VALUES
('PO-24888','SUP-004','SKU-4412',700, DATE_SUB(CURDATE(),INTERVAL 46 DAY),DATE_SUB(CURDATE(),INTERVAL 44 DAY),3.60, 'open',               'SB-DC'),
('PO-24908','SUP-001','SKU-1089',800, DATE_SUB(CURDATE(),INTERVAL 27 DAY),DATE_SUB(CURDATE(),INTERVAL 22 DAY),12.40,'fully_received',     'KL-HUB'),
('PO-24891','SUP-005','SKU-1042',500, DATE_SUB(CURDATE(),INTERVAL 20 DAY),DATE_SUB(CURDATE(),INTERVAL 12 DAY),8.20, 'partially_received', 'KL-HUB'),
('PO-24902','SUP-006','SKU-7821',600, DATE_SUB(CURDATE(),INTERVAL 16 DAY),DATE_SUB(CURDATE(),INTERVAL 11 DAY),5.80, 'partially_received', 'PG-HUB'),
('PO-24911','SUP-007','SKU-6630',800, DATE_SUB(CURDATE(),INTERVAL 11 DAY),DATE_SUB(CURDATE(),INTERVAL 7  DAY),4.20, 'partially_received', 'JB-DC'),
('PO-24903','SUP-001','SKU-2018',600, DATE_SUB(CURDATE(),INTERVAL 7  DAY),DATE_SUB(CURDATE(),INTERVAL 2  DAY),18.50,'open',               'JB-DC'),
('PO-24915','SUP-003','SKU-2233',800, DATE_SUB(CURDATE(),INTERVAL 19 DAY),DATE_SUB(CURDATE(),INTERVAL 15 DAY),28.80,'fully_received',     'JB-DC'),
('PO-24899','SUP-008','SKU-7710',500, DATE_SUB(CURDATE(),INTERVAL 29 DAY),DATE_SUB(CURDATE(),INTERVAL 22 DAY),9.90, 'fully_received',     'KL-HUB'),
('PO-24921','SUP-005','SKU-5510',200, DATE_SUB(CURDATE(),INTERVAL 40 DAY),DATE_SUB(CURDATE(),INTERVAL 32 DAY),22.00,'open',               'KL-HUB'),
('PO-24877','SUP-002','SKU-3301',400, DATE_SUB(CURDATE(),INTERVAL 34 DAY),DATE_SUB(CURDATE(),INTERVAL 27 DAY),11.80,'partially_received', 'PG-HUB');

INSERT INTO goods_received (grn_number,po_number,supplier_id,sku,received_quantity,receipt_date,batch_number,expiry_date,location_code) VALUES
('GRN-5001','PO-24908','SUP-001','SKU-1089',800, DATE_SUB(CURDATE(),INTERVAL 22 DAY),'B2026-089','2027-03-01','KL-HUB'),
('GRN-5002','PO-24891','SUP-005','SKU-1042',420, DATE_SUB(CURDATE(),INTERVAL 10 DAY),'B2026-042','2027-07-15','KL-HUB'),
('GRN-5003','PO-24902','SUP-006','SKU-7821',540, DATE_SUB(CURDATE(),INTERVAL 9  DAY),'B2026-078','2026-06-22','PG-HUB'),
('GRN-5004','PO-24911','SUP-007','SKU-6630',750, DATE_SUB(CURDATE(),INTERVAL 5  DAY),'B2026-066','2027-01-01','JB-DC'),
('GRN-5005','PO-24915','SUP-003','SKU-2233',850, DATE_SUB(CURDATE(),INTERVAL 15 DAY),'B2026-022','2027-06-01','JB-DC'),
('GRN-5006','PO-24899','SUP-008','SKU-7710',520, DATE_SUB(CURDATE(),INTERVAL 20 DAY),'B2026-077',NULL,        'KL-HUB'),
('GRN-5007','PO-24877','SUP-002','SKU-3301',380, DATE_SUB(CURDATE(),INTERVAL 25 DAY),'B2026-033','2030-01-01','PG-HUB');

INSERT INTO accounts_payable (invoice_number,supplier_id,po_number,amount,invoice_date,due_date,payment_date,status) VALUES
('INV-8801','SUP-001','PO-24908', 9920.00,DATE_SUB(CURDATE(),INTERVAL 22 DAY),DATE_ADD(DATE_SUB(CURDATE(),INTERVAL 22 DAY),INTERVAL 30 DAY),NULL,'unpaid'),
('INV-8802','SUP-005','PO-24891', 3444.00,DATE_SUB(CURDATE(),INTERVAL 10 DAY),DATE_ADD(DATE_SUB(CURDATE(),INTERVAL 10 DAY),INTERVAL 45 DAY),NULL,'unpaid'),
('INV-8803','SUP-006','PO-24902', 3132.00,DATE_SUB(CURDATE(),INTERVAL 9  DAY),DATE_ADD(DATE_SUB(CURDATE(),INTERVAL 9  DAY),INTERVAL 30 DAY),NULL,'unpaid'),
('INV-8804','SUP-007','PO-24911', 3150.00,DATE_SUB(CURDATE(),INTERVAL 5  DAY),DATE_ADD(DATE_SUB(CURDATE(),INTERVAL 5  DAY),INTERVAL 30 DAY),NULL,'unpaid'),
('INV-8805','SUP-003','PO-24915',24480.00,DATE_SUB(CURDATE(),INTERVAL 15 DAY),DATE_ADD(DATE_SUB(CURDATE(),INTERVAL 15 DAY),INTERVAL 30 DAY),NULL,'unpaid'),
('INV-8806','SUP-008','PO-24899', 5148.00,DATE_SUB(CURDATE(),INTERVAL 20 DAY),DATE_ADD(DATE_SUB(CURDATE(),INTERVAL 20 DAY),INTERVAL 45 DAY),NULL,'unpaid'),
('INV-8807','SUP-002','PO-24877', 4484.00,DATE_SUB(CURDATE(),INTERVAL 25 DAY),DATE_SUB(CURDATE(),INTERVAL 5  DAY),NULL,'overdue'),
('INV-8808','SUP-005','PO-24921',    0.00,DATE_SUB(CURDATE(),INTERVAL 40 DAY),DATE_SUB(CURDATE(),INTERVAL 10 DAY),NULL,'overdue'),
('INV-8809','SUP-004','PO-24888',    0.00,DATE_SUB(CURDATE(),INTERVAL 46 DAY),DATE_SUB(CURDATE(),INTERVAL 32 DAY),NULL,'overdue'),
('INV-8810','SUP-001',NULL,        12400.00,DATE_SUB(CURDATE(),INTERVAL 65 DAY),DATE_SUB(CURDATE(),INTERVAL 35 DAY),DATE_SUB(CURDATE(),INTERVAL 36 DAY),'paid'),
('INV-8811','SUP-003',NULL,         6700.00,DATE_SUB(CURDATE(),INTERVAL 62 DAY),DATE_SUB(CURDATE(),INTERVAL 32 DAY),DATE_SUB(CURDATE(),INTERVAL 33 DAY),'paid'),
('INV-8812','SUP-002',NULL,         8200.00,DATE_SUB(CURDATE(),INTERVAL 85 DAY),DATE_SUB(CURDATE(),INTERVAL 55 DAY),NULL,'overdue'),
('INV-8813','SUP-005',NULL,        15600.00,DATE_SUB(CURDATE(),INTERVAL 98 DAY),DATE_SUB(CURDATE(),INTERVAL 68 DAY),NULL,'overdue'),
('INV-8814','SUP-008',NULL,        11200.00,DATE_SUB(CURDATE(),INTERVAL 80 DAY),DATE_SUB(CURDATE(),INTERVAL 50 DAY),NULL,'overdue');

-- ============================================================
-- USERS TABLE & SEED ACCOUNTS (added for role-based auth)
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(100) NOT NULL,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('super_admin','inventory_manager','procurement_officer','finance_officer','viewer')
                NOT NULL DEFAULT 'viewer',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  last_login    DATETIME NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Demo accounts (passwords shown in brackets)
-- Hashed with PHP password_hash($pass, PASSWORD_DEFAULT)
INSERT INTO users (full_name, email, password_hash, role) VALUES
('Super Admin',          'admin@inveniq.com',        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin'),
('Inventory Manager',    'inventory@inveniq.com',    '$2y$10$TKh8H1.PfJZ1bFgQiCZPUOYl3rZVsHDV6TITOwB0/y.g0nCXa3n/6', 'inventory_manager'),
('Procurement Officer',  'procurement@inveniq.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'procurement_officer'),
('Finance Officer',      'finance@inveniq.com',      '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'finance_officer'),
('Viewer',               'viewer@inveniq.com',       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'viewer');
