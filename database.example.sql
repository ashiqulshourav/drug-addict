CREATE DATABASE IF NOT EXISTS drug
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE drug;

SET FOREIGN_KEY_CHECKS = 0;


/* =========================================================
   DIVISIONS
   ========================================================= */

DROP TABLE IF EXISTS reports;
DROP TABLE IF EXISTS locations;
DROP TABLE IF EXISTS police_stations;
DROP TABLE IF EXISTS upazilas;
DROP TABLE IF EXISTS districts;
DROP TABLE IF EXISTS divisions;


CREATE TABLE divisions (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(80) NOT NULL UNIQUE,

    slug VARCHAR(40) NOT NULL UNIQUE

) ENGINE=InnoDB;


/* =========================================================
   DISTRICTS
   ========================================================= */

CREATE TABLE districts (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    division_id TINYINT UNSIGNED NOT NULL,

    name VARCHAR(80) NOT NULL,

    slug VARCHAR(60) NOT NULL UNIQUE,

    latitude DECIMAL(10,7) NULL,

    longitude DECIMAL(10,7) NULL,

    INDEX idx_district_division (division_id),

    INDEX idx_district_lat_lng (
        latitude,
        longitude
    ),

    CONSTRAINT fk_district_division

        FOREIGN KEY (division_id)

        REFERENCES divisions(id)

        ON UPDATE CASCADE

        ON DELETE RESTRICT

) ENGINE=InnoDB;


/* =========================================================
   UPAZILAS
   ========================================================= */

CREATE TABLE upazilas (

    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    district_id SMALLINT UNSIGNED NOT NULL,

    name VARCHAR(120) NOT NULL,

    bn_name VARCHAR(120) NULL,

    slug VARCHAR(120) NOT NULL,

    latitude DECIMAL(10,7) NULL,

    longitude DECIMAL(10,7) NULL,

    created_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_upazila_district (
        district_id
    ),

    INDEX idx_upazila_lat_lng (
        latitude,
        longitude
    ),

    UNIQUE KEY uq_upazila_district_slug (
        district_id,
        slug
    ),

    CONSTRAINT fk_upazila_district

        FOREIGN KEY (
            district_id
        )

        REFERENCES districts(id)

        ON UPDATE CASCADE

        ON DELETE RESTRICT

) ENGINE=InnoDB;


/* =========================================================
   POLICE STATIONS
   ========================================================= */

CREATE TABLE police_stations (

    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    district_id SMALLINT UNSIGNED NOT NULL,

    name VARCHAR(120) NOT NULL,

    latitude DECIMAL(10,7) NULL,

    longitude DECIMAL(10,7) NULL,

    created_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_station_district (
        district_id
    ),

    INDEX idx_station_lat_lng (
        latitude,
        longitude
    ),

    UNIQUE KEY uq_station_district_name (
        district_id,
        name
    ),

    CONSTRAINT fk_station_district

        FOREIGN KEY (
            district_id
        )

        REFERENCES districts(id)

        ON UPDATE CASCADE

        ON DELETE RESTRICT

) ENGINE=InnoDB;


/* =========================================================
   LOCATIONS
   ========================================================= */

CREATE TABLE locations (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    latitude DECIMAL(10,7) NOT NULL,

    longitude DECIMAL(10,7) NOT NULL,

    title VARCHAR(160) NOT NULL,

    type ENUM(
        'use',
        'sale',
        'both'
    ) NOT NULL DEFAULT 'use',

    police_station_id INT UNSIGNED NULL,

    upazila_id INT UNSIGNED NULL,

    report_count INT UNSIGNED NOT NULL DEFAULT 1,

    use_count INT UNSIGNED NOT NULL DEFAULT 0,

    sale_count INT UNSIGNED NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_location_lat_lng (
        latitude,
        longitude
    ),

    INDEX idx_location_station (
        police_station_id
    ),

    INDEX idx_location_upazila (
        upazila_id
    ),

    INDEX idx_location_type (
        type
    ),

    INDEX idx_location_updated (
        updated_at
    ),

    CONSTRAINT fk_location_station

        FOREIGN KEY (
            police_station_id
        )

        REFERENCES police_stations(id)

        ON UPDATE CASCADE

        ON DELETE SET NULL,

    CONSTRAINT fk_location_upazila

        FOREIGN KEY (
            upazila_id
        )

        REFERENCES upazilas(id)

        ON UPDATE CASCADE

        ON DELETE SET NULL

) ENGINE=InnoDB;


/* =========================================================
   REPORTS
   ========================================================= */

CREATE TABLE reports (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    location_id BIGINT UNSIGNED NOT NULL,

    report_type ENUM(
        'use',
        'sale'
    ) NOT NULL,

    title VARCHAR(160) NOT NULL,

    description VARCHAR(1000) NULL,

    latitude DECIMAL(10,7) NOT NULL,

    longitude DECIMAL(10,7) NOT NULL,

    image_path VARCHAR(255) NULL,

    willing_to_contact TINYINT(1) NOT NULL DEFAULT 0,

    contact_info VARCHAR(190) NULL,

    ip_hash CHAR(64) NULL,

    user_agent VARCHAR(255) NULL,

    created_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_reports_location (
        location_id
    ),

    INDEX idx_reports_type_created (
        report_type,
        created_at
    ),

    INDEX idx_reports_created (
        created_at
    ),

    INDEX idx_reports_ip_created (
        ip_hash,
        created_at
    ),

    CONSTRAINT fk_report_location

        FOREIGN KEY (
            location_id
        )

        REFERENCES locations(id)

        ON UPDATE CASCADE

        ON DELETE RESTRICT

) ENGINE=InnoDB;


/* =========================================================
   DIVISIONS
   ========================================================= */

INSERT INTO divisions
(name, slug)
VALUES

('ঢাকা', 'dhaka'),

('চট্টগ্রাম', 'chattogram'),

('রাজশাহী', 'rajshahi'),

('খুলনা', 'khulna'),

('বরিশাল', 'barishal'),

('সিলেট', 'sylhet'),

('রংপুর', 'rangpur'),

('ময়মনসিংহ', 'mymensingh');


/* =========================================================
   NOTE
   =========================================================

   District / Upazila / Police Station data
   should be imported from your JSON dataset.

   Do NOT use the old 10-station INSERT.

   Your db_geocode.json already contains structured
   district/division/upazila information.

   After importing those datasets, verify:

   SELECT COUNT(*) FROM divisions;
   SELECT COUNT(*) FROM districts;
   SELECT COUNT(*) FROM upazilas;
   SELECT COUNT(*) FROM police_stations;

   ========================================================= */


SET FOREIGN_KEY_CHECKS = 1;