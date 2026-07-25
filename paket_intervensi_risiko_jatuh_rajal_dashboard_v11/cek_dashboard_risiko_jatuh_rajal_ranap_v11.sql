-- Cek sumber dashboard risiko jatuh tinggi dan status intervensi rawat jalan/rawat inap.
-- Ganti periode bila diperlukan.
SET @awal  := DATE_FORMAT(CURDATE(), '%Y-%m-01');
SET @akhir := DATE_ADD(@awal, INTERVAL 1 MONTH);

SELECT 'intervensi_risiko_jatuh' AS tabel, COUNT(*) AS jumlah_data FROM intervensi_risiko_jatuh
UNION ALL
SELECT 'intervensi_risiko_jatuh_rajal' AS tabel, COUNT(*) AS jumlah_data FROM intervensi_risiko_jatuh_rajal;

SELECT sumber, COUNT(*) AS total_risiko_tinggi
FROM (
  SELECT 'anak' AS sumber, no_rawat, tanggal, hasil_skrining FROM penilaian_lanjutan_resiko_jatuh_anak
  WHERE tanggal >= @awal AND tanggal < @akhir AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
  UNION ALL
  SELECT 'dewasa' AS sumber, no_rawat, tanggal, hasil_skrining FROM penilaian_lanjutan_resiko_jatuh_dewasa
  WHERE tanggal >= @awal AND tanggal < @akhir AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
  UNION ALL
  SELECT 'geriatri' AS sumber, no_rawat, tanggal, hasil_skrining FROM penilaian_lanjutan_resiko_jatuh_geriatri
  WHERE tanggal >= @awal AND tanggal < @akhir AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
  UNION ALL
  SELECT 'lansia' AS sumber, no_rawat, tanggal, hasil_skrining FROM penilaian_lanjutan_resiko_jatuh_lansia
  WHERE tanggal >= @awal AND tanggal < @akhir AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
  UNION ALL
  SELECT 'psikiatri' AS sumber, no_rawat, tanggal, hasil_skrining FROM penilaian_lanjutan_resiko_jatuh_psikiatri
  WHERE tanggal >= @awal AND tanggal < @akhir AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
) x
GROUP BY sumber;

SELECT rp.status_lanjut,
       COUNT(*) AS total_risiko_tinggi,
       SUM(CASE WHEN rp.status_lanjut IN ('Ralan','Rajal','Rawat Jalan') AND irj_rajal.no_rawat IS NOT NULL THEN 1
                WHEN rp.status_lanjut NOT IN ('Ralan','Rajal','Rawat Jalan') AND irj_ranap.no_rawat IS NOT NULL THEN 1
                ELSE 0 END) AS sudah_intervensi,
       SUM(CASE WHEN rp.status_lanjut IN ('Ralan','Rajal','Rawat Jalan') AND irj_rajal.no_rawat IS NULL THEN 1
                WHEN rp.status_lanjut NOT IN ('Ralan','Rajal','Rawat Jalan') AND irj_ranap.no_rawat IS NULL THEN 1
                ELSE 0 END) AS belum_intervensi
FROM (
  SELECT no_rawat, tanggal, hasil_skrining FROM penilaian_lanjutan_resiko_jatuh_anak
  WHERE tanggal >= @awal AND tanggal < @akhir AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
  UNION ALL
  SELECT no_rawat, tanggal, hasil_skrining FROM penilaian_lanjutan_resiko_jatuh_dewasa
  WHERE tanggal >= @awal AND tanggal < @akhir AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
  UNION ALL
  SELECT no_rawat, tanggal, hasil_skrining FROM penilaian_lanjutan_resiko_jatuh_geriatri
  WHERE tanggal >= @awal AND tanggal < @akhir AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
  UNION ALL
  SELECT no_rawat, tanggal, hasil_skrining FROM penilaian_lanjutan_resiko_jatuh_lansia
  WHERE tanggal >= @awal AND tanggal < @akhir AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
  UNION ALL
  SELECT no_rawat, tanggal, hasil_skrining FROM penilaian_lanjutan_resiko_jatuh_psikiatri
  WHERE tanggal >= @awal AND tanggal < @akhir AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
) x
INNER JOIN reg_periksa rp ON rp.no_rawat=x.no_rawat
LEFT JOIN (SELECT DISTINCT no_rawat FROM intervensi_risiko_jatuh) irj_ranap ON irj_ranap.no_rawat=x.no_rawat
LEFT JOIN (SELECT DISTINCT no_rawat FROM intervensi_risiko_jatuh_rajal) irj_rajal ON irj_rajal.no_rawat=x.no_rawat
GROUP BY rp.status_lanjut;
