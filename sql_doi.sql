-- =========================================================
-- Fitur Aktivasi DOI (deposit Crossref dengan review).
-- Jalankan sekali: mysql -u USER -p NAMADB < sql_doi.sql
-- =========================================================

-- Nama jurnal "terkunci" di Crossref (full_title yang sudah terdaftar).
-- Dipakai validator: full_title di XML harus sama dengan ini.
ALTER TABLE `jurnals`
  ADD COLUMN `crossref_title`   VARCHAR(255) NULL AFTER `scopus_url`,
  ADD COLUMN `doi_sample`       VARCHAR(255) NULL AFTER `crossref_title`,
  ADD COLUMN `doi_sample_valid` TINYINT(1) DEFAULT 0 AFTER `doi_sample`;

-- Satu request = satu unggahan XML (umumnya 1 terbitan).
CREATE TABLE IF NOT EXISTS `doi_request` (
  `id`             INT(11) NOT NULL AUTO_INCREMENT,
  `jurnal_id`      INT(11) NOT NULL,
  `terbitan_id`    INT(11) NULL,
  `terbitan_label` VARCHAR(150) NULL,
  `jenis`          ENUM('terkini','susulan') DEFAULT 'terkini',
  `status`         ENUM('uploaded','reviewed','revisi','fixed','deposited','done','failed') DEFAULT 'uploaded',
  `xml_original`   LONGTEXT NULL,
  `xml_fixed`      LONGTEXT NULL,
  `full_title_xml` VARCHAR(255) NULL,
  `issn_xml`       VARCHAR(20) NULL,
  `name_mismatch`  TINYINT(1) DEFAULT 0,
  `n_articles`     INT(11) DEFAULT 0,
  `n_active`       INT(11) DEFAULT 0,
  `admin_note`     VARCHAR(255) NULL,
  `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jurnal` (`jurnal_id`),
  KEY `idx_terbitan` (`terbitan_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Satu baris per DOI artikel (diekstrak dari XML).
CREATE TABLE IF NOT EXISTS `doi_article` (
  `id`              INT(11) NOT NULL AUTO_INCREMENT,
  `request_id`      INT(11) NOT NULL,
  `jurnal_id`       INT(11) NOT NULL,
  `terbitan_id`     INT(11) NULL,
  `judul`           VARCHAR(500) NULL,
  `doi`             VARCHAR(255) NOT NULL,
  `crossref_active` TINYINT(1) DEFAULT 0,
  `checked_at`      DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`),
  KEY `idx_jurnal` (`jurnal_id`),
  KEY `idx_doi` (`doi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
