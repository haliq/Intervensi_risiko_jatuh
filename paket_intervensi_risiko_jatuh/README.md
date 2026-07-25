# Modul Intervensi Pencegahan Risiko Jatuh

Paket ini dibuat untuk SIMKES Khanza / webapps PHP berbasis database yang memiliki tabel standar:
`reg_periksa`, `pasien`, `dokter`, `poliklinik`, `kamar_inap`, `kamar`, `bangsal`,
`diagnosa_pasien`, `penyakit`, dan `pegawai`.

## Isi paket

- `index.php` — satu halaman modul: form, pencarian pasien, simpan, daftar, edit, hapus, dan cetak.
- `install_intervensi_risiko_jatuh.sql` — hanya membuat **satu tabel baru**, yaitu `intervensi_risiko_jatuh`.
- `assets/logo_cmc.png` dan `assets/logo_paripurna.png` — aset kop cetak dari contoh yang diberikan.

## Instalasi

1. Salin folder `paket_intervensi_risiko_jatuh` menjadi:
   ```
   webapps/intervensi_risiko_jatuh/
   ```

2. Jalankan `install_intervensi_risiko_jatuh.sql` pada database SIMKES, misalnya `sik_cmc`.

3. Akses:
   ```
   https://alamat-simrs/webapps/intervensi_risiko_jatuh/
   ```

4. Tambahkan menu aplikasi yang menuju:
   ```
   webapps/intervensi_risiko_jatuh/index.php
   ```

> `index.php` otomatis mencoba membaca `../../conf/conf.php`, sesuai struktur umum folder
> `webapps/<nama_modul>/index.php`. Bila struktur server berbeda, sesuaikan `$conf_candidates`
> pada bagian paling atas file.

## Perilaku yang diterapkan

- Input **nomor rawat**, lalu klik **Cari Data Pasien**.
- Identitas diambil dari data registrasi pasien, baik `Ralan` maupun `Ranap`.
- Untuk rawat inap, ruang diambil dari `kamar_inap → kamar → bangsal`.
- Diagnosa dirangkum dari `diagnosa_pasien → penyakit`.
- Tindakan A, B, dan C memiliki pilihan **Ya/Tidak**.
- Saat pilihan Ya/Tidak diubah, **tanggal/jam** dan **paraf elektronik** otomatis terisi.
- Paraf elektronik bukan tanda tangan manual: nilainya mengikuti **Perawat Pelaksana**.
- Verifikasi Perawat Pelaksana dan Kepala Ruangan diambil dari tabel `pegawai`.
- Cetak menggunakan default kertas **Legal portrait** (`@page size: legal`).
- Barcode pada bagian verifikasi menggunakan **Code 39** dan menyimpan NIK petugas.
- Seluruh formulir disimpan pada satu tabel `intervensi_risiko_jatuh`; detail dinamis disimpan sebagai JSON agar tidak perlu tabel anak.

## Catatan penting

- Pengamanan akses/login tetap mengikuti mekanisme login webapps yang telah digunakan di server RS.
- Bila nama kolom tabel `pegawai` di server berbeda dari `nik` dan `nama`, sesuaikan kueri:
  ```sql
  SELECT nik, nama FROM pegawai ORDER BY nama ASC
  ```
- Bila tampilan aset logo belum sesuai struktur server, biarkan folder `assets` tetap berada
  satu folder dengan `index.php`.
