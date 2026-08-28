CREATE DATABASE IF NOT EXISTS drug CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE drug;

CREATE TABLE divisions (
  id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  slug VARCHAR(40) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE districts (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  division_id TINYINT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(60) NOT NULL UNIQUE,
  INDEX idx_district_division (division_id),
  CONSTRAINT fk_district_division FOREIGN KEY (division_id) REFERENCES divisions(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE police_stations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  district_id SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_station_district (district_id),
  UNIQUE KEY uq_station_district_name (district_id, name),
  CONSTRAINT fk_station_district FOREIGN KEY (district_id) REFERENCES districts(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE locations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  title VARCHAR(160) NOT NULL,
  type ENUM('use','sale','both') NOT NULL DEFAULT 'use',
  police_station_id INT UNSIGNED NULL,
  report_count INT UNSIGNED NOT NULL DEFAULT 1,
  use_count INT UNSIGNED NOT NULL DEFAULT 0,
  sale_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_location_lat_lng (latitude, longitude),
  INDEX idx_location_station (police_station_id),
  INDEX idx_location_type (type),
  INDEX idx_location_updated (updated_at),
  CONSTRAINT fk_location_station FOREIGN KEY (police_station_id) REFERENCES police_stations(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  location_id BIGINT UNSIGNED NOT NULL,
  report_type ENUM('use','sale') NOT NULL,
  title VARCHAR(160) NOT NULL,
  description VARCHAR(1000) NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  image_path VARCHAR(255) NULL,
  willing_to_contact TINYINT(1) NOT NULL DEFAULT 0,
  contact_info VARCHAR(190) NULL,
  ip_hash CHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reports_location (location_id),
  INDEX idx_reports_type_created (report_type, created_at),
  INDEX idx_reports_created (created_at),
  INDEX idx_reports_ip_created (ip_hash, created_at),
  CONSTRAINT fk_report_location FOREIGN KEY (location_id) REFERENCES locations(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT INTO divisions (name, slug) VALUES
('ঢাকা','dhaka'),('চট্টগ্রাম','chattogram'),('রাজশাহী','rajshahi'),
('খুলনা','khulna'),('বরিশাল','barishal'),('সিলেট','sylhet'),
('রংপুর','rangpur'),('ময়মনসিংহ','mymensingh');

INSERT INTO districts (division_id,name,slug)
SELECT id,'ঢাকা','dhaka' FROM divisions WHERE slug='dhaka';
INSERT INTO districts (division_id,name,slug)
SELECT id,'চট্টগ্রাম','chattogram' FROM divisions WHERE slug='chattogram';
INSERT INTO districts (division_id,name,slug)
SELECT id,'রাজশাহী','rajshahi' FROM divisions WHERE slug='rajshahi';
INSERT INTO districts (division_id,name,slug)
SELECT id,'খুলনা','khulna' FROM divisions WHERE slug='khulna';
INSERT INTO districts (division_id,name,slug)
SELECT id,'বরিশাল','barishal' FROM divisions WHERE slug='barishal';
INSERT INTO districts (division_id,name,slug)
SELECT id,'সিলেট','sylhet' FROM divisions WHERE slug='sylhet';
INSERT INTO districts (division_id,name,slug)
SELECT id,'রংপুর','rangpur' FROM divisions WHERE slug='rangpur';
INSERT INTO districts (division_id,name,slug)
SELECT id,'ময়মনসিংহ','mymensingh' FROM divisions WHERE slug='mymensingh';

INSERT INTO police_stations (district_id,name)
SELECT d.id, s.name FROM districts d JOIN (
  SELECT 'dhaka' slug,'রমনা থানা' name UNION ALL
  SELECT 'dhaka','ধানমন্ডি থানা' UNION ALL
  SELECT 'dhaka','লালবাগ থানা' UNION ALL
  SELECT 'dhaka','বাড্ডা থানা' UNION ALL
  SELECT 'dhaka','মতিঝিল থানা' UNION ALL
  SELECT 'dhaka','তেজগাঁও থানা' UNION ALL
  SELECT 'dhaka','কোতোয়ালি থানা' UNION ALL
  SELECT 'chattogram','পাহাড়তলী থানা' UNION ALL
  SELECT 'rajshahi','রাজপাড়া থানা' UNION ALL
  SELECT 'khulna','সোনাডাঙ্গা থানা'
) s ON s.slug=d.slug;
