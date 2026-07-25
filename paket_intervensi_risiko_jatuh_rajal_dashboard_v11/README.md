# Modul Intervensi Risiko Jatuh V11

Paket ini memperbarui modul `intervensi_risiko_jatuh` agar mempunyai tab dan alur terpisah untuk:

1. Input Intervensi Rawat Inap — tetap memakai tabel lama `intervensi_risiko_jatuh`.
2. Input Intervensi Rawat Jalan — memakai tabel baru `intervensi_risiko_jatuh_rajal`.
3. Dashboard Risiko Jatuh Tinggi — menggabungkan hasil skrining dari tabel asesmen lanjutan, lalu mencocokkan apakah sudah dibuat intervensi rawat jalan atau rawat inap.

## Sumber dashboard risiko tinggi

Dashboard mengambil `hasil_skrining` yang mengandung kata **tinggi** dari tabel:

- `penilaian_lanjutan_resiko_jatuh_anak`
- `penilaian_lanjutan_resiko_jatuh_dewasa`
- `penilaian_lanjutan_resiko_jatuh_geriatri`
- `penilaian_lanjutan_resiko_jatuh_lansia`
- `penilaian_lanjutan_resiko_jatuh_psikiatri`

Kemudian dicocokkan dengan:

- `intervensi_risiko_jatuh` untuk pasien Ranap.
- `intervensi_risiko_jatuh_rajal` untuk pasien Ralan/Rajal.

## Cara pasang

1. Backup file lama:

```text
webapps/intervensi_risiko_jatuh/index.php
```

2. Salin `index.php` dari paket ini ke:

```text
webapps/intervensi_risiko_jatuh/index.php
```

3. Jalankan SQL satu kali:

```text
install_intervensi_risiko_jatuh_rajal.sql
```

4. Pastikan folder `assets` lama tetap ada bila sebelumnya sudah memakai logo dan QR Code:

```text
webapps/intervensi_risiko_jatuh/assets/logo_cmc.png
webapps/intervensi_risiko_jatuh/assets/logo_paripurna.png
webapps/intervensi_risiko_jatuh/assets/qrcode-browser.js
```

5. Refresh browser paksa dengan `Ctrl+F5` atau `Cmd+Shift+R`.

## Menu yang tersedia

- `?mode=form` : Input intervensi rawat inap.
- `?mode=form_rajal` : Input intervensi rawat jalan.
- `?mode=daftar` : Daftar intervensi rawat inap per hari.
- `?mode=daftar_rajal` : Daftar intervensi rawat jalan per hari.
- `?mode=risiko_tinggi` : Dashboard risiko jatuh tinggi bulanan dan cakupan intervensi.

## Catatan

Form rawat jalan otomatis menolak nomor rawat Ranap supaya data tidak salah masuk. Bila pasien berasal dari rawat inap, gunakan tab **Input Intervensi Rawat Inap**.
