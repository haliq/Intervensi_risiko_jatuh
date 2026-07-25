-- Cek sumber data Dashboard Risiko Jatuh Tinggi V10
-- Ganti periode sesuai kebutuhan.
SET @tgl_awal := '2026-07-01 00:00:00';
SET @tgl_akhir := '2026-08-01 00:00:00';

-- 1. Jumlah risiko tinggi per tabel asesmen
SELECT 'Anak / Humpty Dumpty' AS sumber, COUNT(*) AS total_tinggi
FROM penilaian_lanjutan_resiko_jatuh_anak
WHERE tanggal >= @tgl_awal AND tanggal < @tgl_akhir
  AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
UNION ALL
SELECT 'Dewasa / Morse Fall Scale' AS sumber, COUNT(*) AS total_tinggi
FROM penilaian_lanjutan_resiko_jatuh_dewasa
WHERE tanggal >= @tgl_awal AND tanggal < @tgl_akhir
  AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
UNION ALL
SELECT 'Geriatri' AS sumber, COUNT(*) AS total_tinggi
FROM penilaian_lanjutan_resiko_jatuh_geriatri
WHERE tanggal >= @tgl_awal AND tanggal < @tgl_akhir
  AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
UNION ALL
SELECT 'Lansia' AS sumber, COUNT(*) AS total_tinggi
FROM penilaian_lanjutan_resiko_jatuh_lansia
WHERE tanggal >= @tgl_awal AND tanggal < @tgl_akhir
  AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
UNION ALL
SELECT 'Psikiatri / Edmonson' AS sumber, COUNT(*) AS total_tinggi
FROM penilaian_lanjutan_resiko_jatuh_psikiatri
WHERE tanggal >= @tgl_awal AND tanggal < @tgl_akhir
  AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%';

-- 2. Daftar pasien risiko tinggi dan status intervensinya
SELECT x.sumber, x.tanggal, x.no_rawat, rp.no_rkm_medis, p.nm_pasien,
       rp.status_lanjut, x.skor, x.hasil_skrining,
       IF(irj.no_rawat IS NULL, 'Belum ada intervensi', 'Sudah ada intervensi') AS status_intervensi
FROM (
    SELECT 'Anak / Humpty Dumpty' AS sumber, tanggal, no_rawat, penilaian_humptydumpty_totalnilai AS skor, hasil_skrining
    FROM penilaian_lanjutan_resiko_jatuh_anak
    WHERE tanggal >= @tgl_awal AND tanggal < @tgl_akhir AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
    UNION ALL
    SELECT 'Dewasa / Morse Fall Scale' AS sumber, tanggal, no_rawat, penilaian_jatuhmorse_totalnilai AS skor, hasil_skrining
    FROM penilaian_lanjutan_resiko_jatuh_dewasa
    WHERE tanggal >= @tgl_awal AND tanggal < @tgl_akhir AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
    UNION ALL
    SELECT 'Geriatri' AS sumber, tanggal, no_rawat, penilaian_jatuh_totalnilai AS skor, hasil_skrining
    FROM penilaian_lanjutan_resiko_jatuh_geriatri
    WHERE tanggal >= @tgl_awal AND tanggal < @tgl_akhir AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
    UNION ALL
    SELECT 'Lansia' AS sumber, tanggal, no_rawat, penilaian_jatuhmorse_totalnilai AS skor, hasil_skrining
    FROM penilaian_lanjutan_resiko_jatuh_lansia
    WHERE tanggal >= @tgl_awal AND tanggal < @tgl_akhir AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
    UNION ALL
    SELECT 'Psikiatri / Edmonson' AS sumber, tanggal, no_rawat, penilaian_jatuhedmonson_totalnilai AS skor, hasil_skrining
    FROM penilaian_lanjutan_resiko_jatuh_psikiatri
    WHERE tanggal >= @tgl_awal AND tanggal < @tgl_akhir AND LOWER(COALESCE(hasil_skrining,'')) LIKE '%tinggi%'
) x
INNER JOIN reg_periksa rp ON x.no_rawat=rp.no_rawat
INNER JOIN pasien p ON rp.no_rkm_medis=p.no_rkm_medis
LEFT JOIN (SELECT DISTINCT no_rawat FROM intervensi_risiko_jatuh) irj ON irj.no_rawat=x.no_rawat
ORDER BY x.tanggal DESC, p.nm_pasien ASC;
