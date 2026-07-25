-- Instalasi tabel FORM INTERVENSI RISIKO JATUH PASIEN RAWAT JALAN
-- Jalankan satu kali pada database SIMRS Khanza.
-- Tabel rawat inap tetap menggunakan: intervensi_risiko_jatuh

CREATE TABLE IF NOT EXISTS `intervensi_risiko_jatuh_rajal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `no_form` varchar(40) NOT NULL,
  `no_rawat` varchar(17) NOT NULL,
  `tanggal_form` datetime NOT NULL,
  `identitas_json` longtext NOT NULL,
  `skrining_json` longtext NOT NULL,
  `intervensi_json` longtext NOT NULL,
  `evaluasi_json` longtext NULL,
  `edukasi_json` longtext NULL,
  `penerima_nama` varchar(120) DEFAULT NULL,
  `hubungan_penerima` varchar(80) DEFAULT NULL,
  `petugas_npk` varchar(30) NOT NULL,
  `petugas_nama` varchar(120) NOT NULL,
  `petugas_jabatan` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_no_form` (`no_form`),
  KEY `idx_no_rawat` (`no_rawat`),
  KEY `idx_tanggal_form` (`tanggal_form`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `intervensi_risiko_jatuh_rajal_ibfk_1`
    FOREIGN KEY (`no_rawat`) REFERENCES `reg_periksa` (`no_rawat`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

SHOW COLUMNS FROM `intervensi_risiko_jatuh_rajal`;
