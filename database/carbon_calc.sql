-- =====================================================================
--  Carbon Emission Calculator  —  DBMS Mini Project
--  Import in phpMyAdmin (Import tab) or:  mysql -u root < carbon_calc.sql
--  Schema: 5 tables in 3NF + 1 view + 1 trigger + 1 stored procedure
--  Demo history is dated relative to CURDATE(), so the dashboard always has a
--  current month to show whenever this file is imported.
-- =====================================================================
DROP DATABASE IF EXISTS carbon_calc;
CREATE DATABASE carbon_calc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE carbon_calc;

-- 1. users --------------------------------------------------------------
CREATE TABLE users (
  user_id        INT AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(100) NOT NULL,
  email          VARCHAR(150) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('user','admin') NOT NULL DEFAULT 'user',
  monthly_budget_kg DECIMAL(8,2) NOT NULL DEFAULT 208.00 CHECK (monthly_budget_kg > 0),
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. category -----------------------------------------------------------
CREATE TABLE category (
  category_id    INT AUTO_INCREMENT PRIMARY KEY,
  category_name  VARCHAR(50) NOT NULL UNIQUE,
  unit           VARCHAR(20) NOT NULL
) ENGINE=InnoDB;

-- 3. emission_factor  (each factor stored ONCE — fixes Gap 1) -----------
CREATE TABLE emission_factor (
  factor_id        INT AUTO_INCREMENT PRIMARY KEY,
  category_id      INT NOT NULL,
  factor_name      VARCHAR(100) NOT NULL,
  unit             VARCHAR(20) NOT NULL,
  kg_co2_per_unit  DECIMAL(10,4) NOT NULL CHECK (kg_co2_per_unit >= 0),
  source           VARCHAR(150) NOT NULL,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_factor_category FOREIGN KEY (category_id)
      REFERENCES category(category_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  UNIQUE KEY uq_factor (category_id, factor_name)
) ENGINE=InnoDB;

-- 4. activity_log  (dated, never overwritten — fixes Gap 2;
--    stores the factor value used — fixes Gap 3) ------------------------
CREATE TABLE activity_log (
  log_id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id            INT NOT NULL,
  factor_id          INT NOT NULL,
  quantity           DECIMAL(10,2) NOT NULL CHECK (quantity > 0),
  log_date           DATE NOT NULL,
  factor_value_used  DECIMAL(10,4) NOT NULL DEFAULT 0,
  co2_kg             DECIMAL(12,3) NOT NULL DEFAULT 0,
  note               VARCHAR(255) NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_user   FOREIGN KEY (user_id)   REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_log_factor FOREIGN KEY (factor_id) REFERENCES emission_factor(factor_id) ON DELETE RESTRICT,
  INDEX idx_log_date (log_date),
  INDEX idx_user_date (user_id, log_date)
) ENGINE=InnoDB;

-- 5. report -------------------------------------------------------------
CREATE TABLE report (
  report_id     INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  period_start  DATE NOT NULL,
  period_end    DATE NOT NULL,
  total_co2_kg  DECIMAL(12,3) NOT NULL,
  entry_count   INT NOT NULL,
  generated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_report_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT chk_period CHECK (period_end >= period_start)
) ENGINE=InnoDB;

-- TRIGGER: the database itself computes CO2 and snapshots the factor ----
DELIMITER $$
CREATE TRIGGER trg_activity_compute
BEFORE INSERT ON activity_log
FOR EACH ROW
BEGIN
  DECLARE f DECIMAL(10,4);
  SELECT kg_co2_per_unit INTO f FROM emission_factor WHERE factor_id = NEW.factor_id;
  SET NEW.factor_value_used = f;
  SET NEW.co2_kg = ROUND(NEW.quantity * f, 3);
END$$

-- STORED PROCEDURE: build a period report in one call --------------------
CREATE PROCEDURE sp_generate_report(IN p_user INT, IN p_start DATE, IN p_end DATE)
BEGIN
  INSERT INTO report (user_id, period_start, period_end, total_co2_kg, entry_count)
  SELECT p_user, p_start, p_end, COALESCE(SUM(co2_kg),0), COUNT(*)
  FROM activity_log
  WHERE user_id = p_user AND log_date BETWEEN p_start AND p_end;
  SELECT * FROM report WHERE report_id = LAST_INSERT_ID();
END$$
DELIMITER ;

-- VIEW: monthly totals per user per category ----------------------------
CREATE VIEW v_monthly_summary AS
SELECT a.user_id,
       DATE_FORMAT(a.log_date, '%Y-%m') AS month,
       c.category_name,
       ROUND(SUM(a.co2_kg), 2)          AS total_co2_kg,
       COUNT(*)                         AS entries
FROM activity_log a
JOIN emission_factor f ON f.factor_id  = a.factor_id
JOIN category c        ON c.category_id = f.category_id
GROUP BY a.user_id, month, c.category_name;

-- ===================== SEED DATA =====================
INSERT INTO category (category_name, unit) VALUES
 ('Travel','km'), ('Electricity','kWh'), ('Cooking Fuel','kg'), ('Food','kg'), ('Waste','kg');

-- Reference values (approximate). Source is stored per row so any total can be traced.
INSERT INTO emission_factor (category_id, factor_name, unit, kg_co2_per_unit, source) VALUES
 (1,'Petrol car','km',0.1700,'DEFRA GHG conversion factors (avg. petrol car)'),
 (1,'Diesel car','km',0.1700,'DEFRA GHG conversion factors (avg. diesel car)'),
 (1,'Motorbike / scooter','km',0.1100,'DEFRA GHG conversion factors (avg. motorbike)'),
 (1,'City bus','km',0.1000,'DEFRA GHG conversion factors (local bus)'),
 (1,'Metro / rail','km',0.0350,'DEFRA GHG conversion factors (light rail)'),
 (1,'Domestic flight','km',0.2500,'DEFRA GHG conversion factors (domestic flight)'),
 (2,'Grid electricity (India)','kWh',0.7160,'CEA CO2 Baseline Database, India grid'),
 (3,'LPG','kg',2.9850,'IPCC 2006 Guidelines (63.1 kgCO2/GJ x 47.3 MJ/kg)'),
 (4,'Beef','kg',27.0000,'EWG Meat Eater''s Guide (2011), full life cycle'),
 (4,'Chicken','kg',6.9000,'EWG Meat Eater''s Guide (2011), full life cycle'),
 (4,'Rice','kg',2.7000,'EWG Meat Eater''s Guide (2011), full life cycle'),
 (4,'Milk','kg',1.9000,'EWG Meat Eater''s Guide (2011), full life cycle'),
 (4,'Vegetables','kg',0.5000,'Poore & Nemecek (2018), Science, other vegetables'),
 (5,'Mixed waste to landfill','kg',0.5800,'DEFRA GHG conversion factors (landfill)');

-- Demo accounts   admin@demo.com / admin123     yash@demo.com / demo123
-- monthly_budget_kg default 208 = 2.5 t CO2 per person per year (1.5 C lifestyle target for 2030) / 12
INSERT INTO users (name,email,password_hash,role) VALUES
 ('Admin','admin@demo.com','$2y$12$/.7I6DJ2ozqJnJHNVA.x/ex/AWb09nMZTzxMPyE3chJGRey5kBXfq','admin'),
 ('Yash B','yash@demo.com','$2y$12$C9X0FdRtlFuUGAGfhRuDouXsgPWngipRhSJzc0zZXJkGJL/MouzEO','user');

-- Demo history for Yash: ~6 months, dated relative to today (co2_kg is filled by the trigger).
-- The story in the data: car commute until ~3 months ago, then a switch to metro.
INSERT INTO activity_log (user_id,factor_id,quantity,log_date,note) VALUES
 (2,13,2.99,CURDATE() - INTERVAL 182 DAY,'Weekly vegetables'),
 (2,3,12,CURDATE() - INTERVAL 181 DAY,NULL),
 (2,1,42,CURDATE() - INTERVAL 179 DAY,'Office commute'),
 (2,7,35.21,CURDATE() - INTERVAL 178 DAY,'Weekly meter reading'),
 (2,1,38,CURDATE() - INTERVAL 176 DAY,'Office commute'),
 (2,12,5,CURDATE() - INTERVAL 176 DAY,NULL),
 (2,14,4.23,CURDATE() - INTERVAL 176 DAY,NULL),
 (2,13,2.64,CURDATE() - INTERVAL 173 DAY,'Weekly vegetables'),
 (2,11,5,CURDATE() - INTERVAL 173 DAY,NULL),
 (2,1,46,CURDATE() - INTERVAL 172 DAY,'Office commute'),
 (2,7,30.46,CURDATE() - INTERVAL 171 DAY,'Weekly meter reading'),
 (2,3,18,CURDATE() - INTERVAL 170 DAY,NULL),
 (2,1,38,CURDATE() - INTERVAL 169 DAY,'Office commute'),
 (2,10,1,CURDATE() - INTERVAL 169 DAY,NULL),
 (2,14,3.06,CURDATE() - INTERVAL 168 DAY,NULL),
 (2,12,5,CURDATE() - INTERVAL 166 DAY,NULL),
 (2,1,42,CURDATE() - INTERVAL 165 DAY,'Office commute'),
 (2,7,33.35,CURDATE() - INTERVAL 164 DAY,'Weekly meter reading'),
 (2,13,2.86,CURDATE() - INTERVAL 164 DAY,'Weekly vegetables'),
 (2,8,14.2,CURDATE() - INTERVAL 164 DAY,'LPG cylinder refill'),
 (2,1,46,CURDATE() - INTERVAL 162 DAY,'Office commute'),
 (2,14,3.64,CURDATE() - INTERVAL 160 DAY,NULL),
 (2,3,18,CURDATE() - INTERVAL 159 DAY,NULL),
 (2,1,38,CURDATE() - INTERVAL 158 DAY,'Office commute'),
 (2,11,5,CURDATE() - INTERVAL 158 DAY,NULL),
 (2,7,37.58,CURDATE() - INTERVAL 157 DAY,'Weekly meter reading'),
 (2,12,5,CURDATE() - INTERVAL 156 DAY,NULL),
 (2,1,46,CURDATE() - INTERVAL 155 DAY,'Office commute'),
 (2,13,3.44,CURDATE() - INTERVAL 155 DAY,'Weekly vegetables'),
 (2,10,1,CURDATE() - INTERVAL 155 DAY,NULL),
 (2,14,4.42,CURDATE() - INTERVAL 152 DAY,NULL),
 (2,1,46,CURDATE() - INTERVAL 151 DAY,'Office commute'),
 (2,7,34.68,CURDATE() - INTERVAL 150 DAY,'Weekly meter reading'),
 (2,1,38,CURDATE() - INTERVAL 148 DAY,'Office commute'),
 (2,3,12,CURDATE() - INTERVAL 148 DAY,NULL),
 (2,13,2.57,CURDATE() - INTERVAL 146 DAY,'Weekly vegetables'),
 (2,12,5,CURDATE() - INTERVAL 146 DAY,NULL),
 (2,1,38,CURDATE() - INTERVAL 144 DAY,'Office commute'),
 (2,14,3.43,CURDATE() - INTERVAL 144 DAY,NULL),
 (2,7,31.15,CURDATE() - INTERVAL 143 DAY,'Weekly meter reading'),
 (2,11,5,CURDATE() - INTERVAL 143 DAY,NULL),
 (2,1,38,CURDATE() - INTERVAL 141 DAY,'Office commute'),
 (2,10,1,CURDATE() - INTERVAL 141 DAY,NULL),
 (2,1,46,CURDATE() - INTERVAL 137 DAY,'Office commute'),
 (2,3,15,CURDATE() - INTERVAL 137 DAY,NULL),
 (2,13,3.34,CURDATE() - INTERVAL 137 DAY,'Weekly vegetables'),
 (2,7,35.46,CURDATE() - INTERVAL 136 DAY,'Weekly meter reading'),
 (2,12,5,CURDATE() - INTERVAL 136 DAY,NULL),
 (2,14,3.15,CURDATE() - INTERVAL 136 DAY,NULL),
 (2,1,46,CURDATE() - INTERVAL 134 DAY,'Office commute'),
 (2,1,46,CURDATE() - INTERVAL 130 DAY,'Office commute'),
 (2,6,1150,CURDATE() - INTERVAL 130 DAY,'Hyderabad → Delhi'),
 (2,7,31.5,CURDATE() - INTERVAL 129 DAY,'Weekly meter reading'),
 (2,13,2.65,CURDATE() - INTERVAL 128 DAY,'Weekly vegetables'),
 (2,11,5,CURDATE() - INTERVAL 128 DAY,NULL),
 (2,14,4.07,CURDATE() - INTERVAL 128 DAY,NULL),
 (2,1,46,CURDATE() - INTERVAL 127 DAY,'Office commute'),
 (2,10,1,CURDATE() - INTERVAL 127 DAY,NULL),
 (2,3,12,CURDATE() - INTERVAL 126 DAY,NULL),
 (2,12,5,CURDATE() - INTERVAL 126 DAY,NULL),
 (2,8,14.2,CURDATE() - INTERVAL 126 DAY,'LPG cylinder refill'),
 (2,1,46,CURDATE() - INTERVAL 123 DAY,'Office commute'),
 (2,7,31.65,CURDATE() - INTERVAL 122 DAY,'Weekly meter reading'),
 (2,1,46,CURDATE() - INTERVAL 120 DAY,'Office commute'),
 (2,14,3.8,CURDATE() - INTERVAL 120 DAY,NULL),
 (2,13,3.67,CURDATE() - INTERVAL 119 DAY,'Weekly vegetables'),
 (2,1,42,CURDATE() - INTERVAL 116 DAY,'Office commute'),
 (2,12,5,CURDATE() - INTERVAL 116 DAY,NULL),
 (2,7,34.68,CURDATE() - INTERVAL 115 DAY,'Weekly meter reading'),
 (2,3,15,CURDATE() - INTERVAL 115 DAY,NULL),
 (2,1,42,CURDATE() - INTERVAL 113 DAY,'Office commute'),
 (2,11,5,CURDATE() - INTERVAL 113 DAY,NULL),
 (2,10,1,CURDATE() - INTERVAL 113 DAY,NULL),
 (2,14,3.45,CURDATE() - INTERVAL 112 DAY,NULL),
 (2,13,3.69,CURDATE() - INTERVAL 110 DAY,'Weekly vegetables'),
 (2,1,46,CURDATE() - INTERVAL 109 DAY,'Office commute'),
 (2,7,36.24,CURDATE() - INTERVAL 108 DAY,'Weekly meter reading'),
 (2,1,38,CURDATE() - INTERVAL 106 DAY,'Office commute'),
 (2,12,5,CURDATE() - INTERVAL 106 DAY,NULL),
 (2,3,18,CURDATE() - INTERVAL 104 DAY,NULL),
 (2,14,3.45,CURDATE() - INTERVAL 104 DAY,NULL),
 (2,1,42,CURDATE() - INTERVAL 102 DAY,'Office commute'),
 (2,7,37.0,CURDATE() - INTERVAL 101 DAY,'Weekly meter reading'),
 (2,13,3.59,CURDATE() - INTERVAL 101 DAY,'Weekly vegetables'),
 (2,1,42,CURDATE() - INTERVAL 99 DAY,'Office commute'),
 (2,10,1,CURDATE() - INTERVAL 99 DAY,NULL),
 (2,11,5,CURDATE() - INTERVAL 98 DAY,NULL),
 (2,12,5,CURDATE() - INTERVAL 96 DAY,NULL),
 (2,14,3.91,CURDATE() - INTERVAL 96 DAY,NULL),
 (2,1,38,CURDATE() - INTERVAL 95 DAY,'Office commute'),
 (2,7,18.59,CURDATE() - INTERVAL 94 DAY,'Weekly meter reading'),
 (2,3,15,CURDATE() - INTERVAL 93 DAY,NULL),
 (2,5,42,CURDATE() - INTERVAL 92 DAY,'Office commute (metro)'),
 (2,13,3.64,CURDATE() - INTERVAL 92 DAY,'Weekly vegetables'),
 (2,12,5,CURDATE() - INTERVAL 90 DAY,NULL),
 (2,5,42,CURDATE() - INTERVAL 88 DAY,'Office commute (metro)'),
 (2,8,14.2,CURDATE() - INTERVAL 88 DAY,'LPG cylinder refill'),
 (2,14,4.4,CURDATE() - INTERVAL 88 DAY,NULL),
 (2,7,20.11,CURDATE() - INTERVAL 87 DAY,'Weekly meter reading'),
 (2,5,50,CURDATE() - INTERVAL 85 DAY,'Office commute (metro)'),
 (2,13,2.62,CURDATE() - INTERVAL 83 DAY,'Weekly vegetables'),
 (2,11,5,CURDATE() - INTERVAL 83 DAY,NULL),
 (2,3,18,CURDATE() - INTERVAL 82 DAY,NULL),
 (2,5,50,CURDATE() - INTERVAL 81 DAY,'Office commute (metro)'),
 (2,7,21.95,CURDATE() - INTERVAL 80 DAY,'Weekly meter reading'),
 (2,14,4.23,CURDATE() - INTERVAL 80 DAY,NULL),
 (2,5,46,CURDATE() - INTERVAL 78 DAY,'Office commute (metro)'),
 (2,12,5,CURDATE() - INTERVAL 78 DAY,NULL),
 (2,5,50,CURDATE() - INTERVAL 74 DAY,'Office commute (metro)'),
 (2,13,3.03,CURDATE() - INTERVAL 74 DAY,'Weekly vegetables'),
 (2,7,20.48,CURDATE() - INTERVAL 73 DAY,'Weekly meter reading'),
 (2,14,4.2,CURDATE() - INTERVAL 72 DAY,NULL),
 (2,5,42,CURDATE() - INTERVAL 71 DAY,'Office commute (metro)'),
 (2,3,12,CURDATE() - INTERVAL 71 DAY,NULL),
 (2,10,1,CURDATE() - INTERVAL 71 DAY,NULL),
 (2,11,5,CURDATE() - INTERVAL 68 DAY,NULL),
 (2,5,46,CURDATE() - INTERVAL 67 DAY,'Office commute (metro)'),
 (2,7,20.37,CURDATE() - INTERVAL 66 DAY,'Weekly meter reading'),
 (2,12,5,CURDATE() - INTERVAL 66 DAY,NULL),
 (2,13,3.5,CURDATE() - INTERVAL 65 DAY,'Weekly vegetables'),
 (2,5,42,CURDATE() - INTERVAL 64 DAY,'Office commute (metro)'),
 (2,14,4.1,CURDATE() - INTERVAL 64 DAY,NULL),
 (2,5,46,CURDATE() - INTERVAL 60 DAY,'Office commute (metro)'),
 (2,3,18,CURDATE() - INTERVAL 60 DAY,NULL),
 (2,7,20.89,CURDATE() - INTERVAL 59 DAY,'Weekly meter reading'),
 (2,5,50,CURDATE() - INTERVAL 57 DAY,'Office commute (metro)'),
 (2,13,3.73,CURDATE() - INTERVAL 56 DAY,'Weekly vegetables'),
 (2,14,3.43,CURDATE() - INTERVAL 56 DAY,NULL),
 (2,12,5,CURDATE() - INTERVAL 54 DAY,NULL),
 (2,5,46,CURDATE() - INTERVAL 53 DAY,'Office commute (metro)'),
 (2,11,5,CURDATE() - INTERVAL 53 DAY,NULL),
 (2,7,22.44,CURDATE() - INTERVAL 52 DAY,'Weekly meter reading'),
 (2,5,46,CURDATE() - INTERVAL 50 DAY,'Office commute (metro)'),
 (2,8,14.2,CURDATE() - INTERVAL 50 DAY,'LPG cylinder refill'),
 (2,3,12,CURDATE() - INTERVAL 49 DAY,NULL),
 (2,14,4.41,CURDATE() - INTERVAL 48 DAY,NULL),
 (2,13,3.03,CURDATE() - INTERVAL 47 DAY,'Weekly vegetables'),
 (2,5,50,CURDATE() - INTERVAL 46 DAY,'Office commute (metro)'),
 (2,7,18.59,CURDATE() - INTERVAL 45 DAY,'Weekly meter reading'),
 (2,5,42,CURDATE() - INTERVAL 43 DAY,'Office commute (metro)'),
 (2,10,1,CURDATE() - INTERVAL 43 DAY,NULL),
 (2,12,5,CURDATE() - INTERVAL 42 DAY,NULL),
 (2,14,3.33,CURDATE() - INTERVAL 40 DAY,NULL),
 (2,5,46,CURDATE() - INTERVAL 39 DAY,'Office commute (metro)'),
 (2,7,18.65,CURDATE() - INTERVAL 38 DAY,'Weekly meter reading'),
 (2,3,12,CURDATE() - INTERVAL 38 DAY,NULL),
 (2,13,3.1,CURDATE() - INTERVAL 38 DAY,'Weekly vegetables'),
 (2,11,5,CURDATE() - INTERVAL 38 DAY,NULL),
 (2,5,46,CURDATE() - INTERVAL 36 DAY,'Office commute (metro)'),
 (2,5,42,CURDATE() - INTERVAL 32 DAY,'Office commute (metro)'),
 (2,14,3.25,CURDATE() - INTERVAL 32 DAY,NULL),
 (2,7,20.01,CURDATE() - INTERVAL 31 DAY,'Weekly meter reading'),
 (2,12,5,CURDATE() - INTERVAL 30 DAY,NULL),
 (2,5,46,CURDATE() - INTERVAL 29 DAY,'Office commute (metro)'),
 (2,13,3.83,CURDATE() - INTERVAL 29 DAY,'Weekly vegetables'),
 (2,3,15,CURDATE() - INTERVAL 27 DAY,NULL),
 (2,5,50,CURDATE() - INTERVAL 25 DAY,'Office commute (metro)'),
 (2,7,19.39,CURDATE() - INTERVAL 24 DAY,'Weekly meter reading'),
 (2,14,3.62,CURDATE() - INTERVAL 24 DAY,NULL),
 (2,11,5,CURDATE() - INTERVAL 23 DAY,NULL),
 (2,5,46,CURDATE() - INTERVAL 22 DAY,'Office commute (metro)'),
 (2,13,3.52,CURDATE() - INTERVAL 20 DAY,'Weekly vegetables'),
 (2,4,60,CURDATE() - INTERVAL 20 DAY,'Weekend trip by bus'),
 (2,5,46,CURDATE() - INTERVAL 18 DAY,'Office commute (metro)'),
 (2,12,5,CURDATE() - INTERVAL 18 DAY,NULL),
 (2,7,22.79,CURDATE() - INTERVAL 17 DAY,'Weekly meter reading'),
 (2,3,12,CURDATE() - INTERVAL 16 DAY,NULL),
 (2,14,3.12,CURDATE() - INTERVAL 16 DAY,NULL),
 (2,5,42,CURDATE() - INTERVAL 15 DAY,'Office commute (metro)'),
 (2,10,1,CURDATE() - INTERVAL 15 DAY,NULL),
 (2,8,14.2,CURDATE() - INTERVAL 12 DAY,'LPG cylinder refill'),
 (2,5,42,CURDATE() - INTERVAL 11 DAY,'Office commute (metro)'),
 (2,13,3.49,CURDATE() - INTERVAL 11 DAY,'Weekly vegetables'),
 (2,7,18.06,CURDATE() - INTERVAL 10 DAY,'Weekly meter reading'),
 (2,5,50,CURDATE() - INTERVAL 8 DAY,'Office commute (metro)'),
 (2,11,5,CURDATE() - INTERVAL 8 DAY,NULL),
 (2,14,3.27,CURDATE() - INTERVAL 8 DAY,NULL),
 (2,12,5,CURDATE() - INTERVAL 6 DAY,NULL),
 (2,3,15,CURDATE() - INTERVAL 5 DAY,NULL),
 (2,5,42,CURDATE() - INTERVAL 4 DAY,'Office commute (metro)'),
 (2,7,18.73,CURDATE() - INTERVAL 3 DAY,'Weekly meter reading'),
 (2,13,3.3,CURDATE() - INTERVAL 2 DAY,'Weekly vegetables'),
 (2,5,50,CURDATE() - INTERVAL 1 DAY,'Office commute (metro)'),
 (2,14,3.85,CURDATE() - INTERVAL 0 DAY,NULL);
