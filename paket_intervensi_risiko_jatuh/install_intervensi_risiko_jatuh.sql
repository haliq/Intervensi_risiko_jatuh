-- ============================================================================
-- MODUL INTERVENSI PENCEGAHAN RISIKO JATUH
-- Hanya membuat SATU tabel baru.
-- Jalankan pada database SIMKES Khanza, misal: sik_cmc
-- ============================================================================

CREATE TABLE IF NOT EXISTS `intervensi_risiko_jatuh` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `no_form` VARCHAR(30) NOT NULL,
  `no_rawat` VARCHAR(20) NOT NULL,
  `tanggal_form` DATETIME NOT NULL,
  `identitas_json` LONGTEXT NOT NULL,
  `skrining_json` LONGTEXT NOT NULL,
  `intervensi_json` LONGTEXT NOT NULL,
  `edukasi_json` LONGTEXT NOT NULL,
  `evaluasi_json` LONGTEXT NOT NULL,
  `kejadian_json` LONGTEXT NOT NULL,
  `perawat_nik` VARCHAR(30) NOT NULL,
  `perawat_nama` VARCHAR(150) NOT NULL,
  `karu_nik` VARCHAR(30) NOT NULL,
  `karu_nama` VARCHAR(150) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_intervensi_risiko_jatuh_no_form` (`no_form`),
  KEY `idx_intervensi_risiko_jatuh_no_rawat` (`no_rawat`),
  KEY `idx_intervensi_risiko_jatuh_tanggal` (`tanggal_form`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verifikasi setelah instalasi:
-- SHOW COLUMNS FROM intervensi_risiko_jatuh;
