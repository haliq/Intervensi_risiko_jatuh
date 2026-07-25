# Modul Intervensi Risiko Jatuh - V10 Dashboard Risiko Tinggi

Pembaruan ini untuk file:
`webapps/intervensi_risiko_jatuh/index.php`

## Fitur baru

1. Pada halaman **Daftar Intervensi Pencegahan Risiko Jatuh** ditambahkan tombol **Daftar Risiko Jatuh**.
2. Halaman **Dashboard Risiko Jatuh Tinggi** mengambil pasien dari tabel:
   - `penilaian_lanjutan_resiko_jatuh_anak`
   - `penilaian_lanjutan_resiko_jatuh_dewasa`
   - `penilaian_lanjutan_resiko_jatuh_geriatri`
   - `penilaian_lanjutan_resiko_jatuh_lansia`
   - `penilaian_lanjutan_resiko_jatuh_psikiatri`
3. Data yang tampil adalah pasien dengan `hasil_skrining` mengandung kata **tinggi**.
4. Dashboard menampilkan:
   - total risiko tinggi bulan terpilih,
   - jumlah pasien yang sudah ada Form Intervensi Risiko Jatuh,
   - jumlah pasien yang belum ada Form Intervensi Risiko Jatuh,
   - cakupan intervensi dalam persen,
   - rekap total risiko tinggi per bulan,
   - komposisi berdasarkan jenis asesmen,
   - komposisi berdasarkan pelayanan Rajal/Ranap.
5. Daftar pasien menampilkan nomor rawat, No. RM, nama pasien, hasil skrining risiko tinggi, skor, ruang/poli, dan status intervensi.
6. Bila belum ada intervensi, tersedia tombol **Buat Intervensi** yang otomatis membawa nomor rawat ke form.
7. Bila sudah ada intervensi, tersedia tombol **Cetak** dan **Edit**.

## Cara pasang

1. Backup file lama:
   `webapps/intervensi_risiko_jatuh/index.php`
2. Ganti dengan file `index.php` dari paket ini.
3. Refresh browser paksa:
   - Mac: `Cmd + Shift + R`
   - Windows: `Ctrl + F5`
4. Buka halaman daftar intervensi, lalu klik tombol **Daftar Risiko Jatuh**.

## Catatan

- Tidak perlu membuat tabel baru.
- Tidak perlu migrasi SQL.
- Pastikan tabel penilaian lanjutan risiko jatuh sudah ada di database Khanza.
- Query memakai `hasil_skrining LIKE '%tinggi%'`, sehingga tetap terbaca walaupun isi kolom berupa "Risiko Tinggi", "Tinggi", atau kalimat sejenis.
