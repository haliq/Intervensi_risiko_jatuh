<?php
/**
 * Modul Intervensi Pencegahan Risiko Jatuh
 * SIMKES Khanza / webapps mandiri
 * Simpan file ini di: webapps/intervensi_risiko_jatuh/index.php
 */
@date_default_timezone_set('Asia/Jakarta');

$conf_candidates = array(
    __DIR__ . '/../../conf/conf.php',    // jika modul ada di webapps/intervensi_risiko_jatuh
    __DIR__ . '/../conf/conf.php',
    __DIR__ . '/conf/conf.php'
);
$conf_loaded = false;
foreach ($conf_candidates as $conf) {
    if (file_exists($conf)) {
        require_once $conf;
        $conf_loaded = true;
        break;
    }
}
if (!$conf_loaded) {
    http_response_code(500);
    exit('conf/conf.php tidak ditemukan. Sesuaikan $conf_candidates pada index.php.');
}

function rj_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function rj_sql($value) {
    global $koneksi;
    $value = (string)$value;
    if (isset($koneksi) && $koneksi instanceof mysqli) {
        return mysqli_real_escape_string($koneksi, $value);
    }
    return addslashes($value);
}
function rj_query_one($sql) {
    $result = bukaquery($sql);
    if ($result && ($row = mysqli_fetch_assoc($result))) {
        return $row;
    }
    return null;
}
function rj_now_local() {
    return date('Y-m-d\TH:i');
}
function rj_display_datetime($value) {
    if (empty($value)) return '-';
    $ts = strtotime(str_replace('T', ' ', $value));
    return $ts ? date('d-m-Y H:i', $ts) : $value;
}
function rj_decode($json, $fallback = array()) {
    $data = json_decode((string)$json, true);
    return is_array($data) ? $data : $fallback;
}
function rj_url($params = array()) {
    $base = basename($_SERVER['PHP_SELF']);
    return $base . '?' . http_build_query($params);
}
function rj_generate_nomor_form() {
    return 'RJ' . date('YmdHis') . rand(10, 99);
}
function rj_code39_svg($text, $height = 42) {
    $patterns = array(
        '0'=>'nnnwwnwnn','1'=>'wnnwnnnnw','2'=>'nnwwnnnnw','3'=>'wnwwnnnnn',
        '4'=>'nnnwwnnnw','5'=>'wnnwwnnnn','6'=>'nnwwwnnnn','7'=>'nnnwnnwnw',
        '8'=>'wnnwnnwnn','9'=>'nnwwnnwnn','A'=>'wnnnnwnnw','B'=>'nnwnnwnnw',
        'C'=>'wnwnnwnnn','D'=>'nnnnwwnnw','E'=>'wnnnwwnnn','F'=>'nnwnwwnnn',
        'G'=>'nnnnnwwnw','H'=>'wnnnnwwnn','I'=>'nnwnnwwnn','J'=>'nnnnwwwnn',
        'K'=>'wnnnnnnww','L'=>'nnwnnnnww','M'=>'wnwnnnnwn','N'=>'nnnnwnnww',
        'O'=>'wnnnwnnwn','P'=>'nnwnwnnwn','Q'=>'nnnnnnwww','R'=>'wnnnnnwwn',
        'S'=>'nnwnnnwwn','T'=>'nnnnwnwwn','U'=>'wwnnnnnnw','V'=>'nwwnnnnnw',
        'W'=>'wwwnnnnnn','X'=>'nwnnwnnnw','Y'=>'wwnnwnnnn','Z'=>'nwwnwnnnn',
        '-'=>'nwnnnnwnw','.'=>'wwnnnnwnn',' '=>'nwwnnnwnn','$'=>'nwnwnwnnn',
        '/'=>'nwnwnnnwn','+'=>'nwnnnwnwn','%'=>'nnnwnwnwn','*'=>'nwnnwnwnn'
    );
    $text = strtoupper(preg_replace('/[^A-Z0-9\-\.\ \$\/\+\%]/', '', (string)$text));
    if ($text === '') return '';
    $code = '*' . $text . '*';
    $unit = 1.35;
    $wide = 3;
    $quiet = 10;
    $width = $quiet * 2;
    foreach (str_split($code) as $char) {
        $pattern = $patterns[$char];
        foreach (str_split($pattern) as $part) $width += ($part === 'w' ? $wide : 1) * $unit;
        $width += $unit;
    }
    $x = $quiet;
    $svg = '<svg class="barcode-svg" xmlns="http://www.w3.org/2000/svg" width="'.ceil($width).'" height="'.($height + 14).'" viewBox="0 0 '.ceil($width).' '.($height + 14).'" role="img" aria-label="Barcode '.$text.'">';
    foreach (str_split($code) as $char) {
        $pattern = $patterns[$char];
        $parts = str_split($pattern);
        foreach ($parts as $index => $part) {
            $barWidth = ($part === 'w' ? $wide : 1) * $unit;
            if ($index % 2 === 0) {
                $svg .= '<rect x="'.round($x,2).'" y="0" width="'.round($barWidth,2).'" height="'.$height.'" fill="#000"/>';
            }
            $x += $barWidth;
        }
        $x += $unit;
    }
    $svg .= '<text x="'.ceil($width/2).'" y="'.($height+11).'" text-anchor="middle" font-family="Arial, sans-serif" font-size="10">'.$text.'</text></svg>';
    return $svg;
}

$mode = isset($_REQUEST['mode']) ? $_REQUEST['mode'] : 'form';

/* =========================
   AJAX: Pencarian data pasien
   ========================= */
if ($mode === 'ajax_pasien') {
    header('Content-Type: application/json; charset=utf-8');
    $no_rawat = isset($_POST['no_rawat']) ? trim($_POST['no_rawat']) : '';
    if ($no_rawat === '') {
        echo json_encode(array('status'=>'gagal', 'pesan'=>'Nomor rawat wajib diisi.'));
        exit;
    }
    $rawat = rj_sql($no_rawat);
    $sql = "SELECT 
                rp.no_rawat, rp.no_rkm_medis, rp.status_lanjut, rp.tgl_registrasi,
                p.nm_pasien,
                d.nm_dokter,
                CASE 
                    WHEN rp.status_lanjut='Ranap' THEN COALESCE((
                        SELECT CONCAT(ki.kd_kamar, ' - ', b.nm_bangsal)
                        FROM kamar_inap ki
                        INNER JOIN kamar k ON ki.kd_kamar=k.kd_kamar
                        INNER JOIN bangsal b ON k.kd_bangsal=b.kd_bangsal
                        WHERE ki.no_rawat=rp.no_rawat
                        ORDER BY ki.tgl_masuk DESC, ki.jam_masuk DESC
                        LIMIT 1
                    ), 'Rawat Inap')
                    ELSE COALESCE(pl.nm_poli, 'Rawat Jalan')
                END AS ruang_rawat,
                CASE
                    WHEN rp.status_lanjut='Ranap' THEN COALESCE((
                        SELECT CONCAT(ki.tgl_masuk, ' ', ki.jam_masuk)
                        FROM kamar_inap ki
                        WHERE ki.no_rawat=rp.no_rawat
                        ORDER BY ki.tgl_masuk ASC, ki.jam_masuk ASC
                        LIMIT 1
                    ), CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg))
                    ELSE CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg)
                END AS tgl_masuk,
                COALESCE((
                    SELECT GROUP_CONCAT(CONCAT(dp.kd_penyakit, ' - ', py.nm_penyakit) ORDER BY dp.prioritas SEPARATOR '; ')
                    FROM diagnosa_pasien dp
                    LEFT JOIN penyakit py ON dp.kd_penyakit=py.kd_penyakit
                    WHERE dp.no_rawat=rp.no_rawat
                ), '-') AS diagnosa
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis=p.no_rkm_medis
            LEFT JOIN dokter d ON rp.kd_dokter=d.kd_dokter
            LEFT JOIN poliklinik pl ON rp.kd_poli=pl.kd_poli
            WHERE rp.no_rawat='$rawat'
            LIMIT 1";
    $data = rj_query_one($sql);
    if (!$data) {
        echo json_encode(array('status'=>'gagal', 'pesan'=>'Data pasien dengan nomor rawat tersebut tidak ditemukan.'));
        exit;
    }
    echo json_encode(array('status'=>'sukses', 'data'=>$data));
    exit;
}

/* =========================
   Hapus data
   ========================= */
if ($mode === 'hapus' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id > 0) {
        bukaquery("DELETE FROM intervensi_risiko_jatuh WHERE id='$id'");
    }
    header('Location: '.rj_url(array('mode'=>'daftar', 'info'=>'hapus')));
    exit;
}

/* =========================
   Simpan / ubah data
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'simpan') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $no_rawat = trim(isset($_POST['no_rawat']) ? $_POST['no_rawat'] : '');
    $identitas = rj_decode(isset($_POST['identitas_json']) ? $_POST['identitas_json'] : '');
    $skrining = rj_decode(isset($_POST['skrining_json']) ? $_POST['skrining_json'] : '');
    $intervensi = rj_decode(isset($_POST['intervensi_json']) ? $_POST['intervensi_json'] : '');
    $edukasi = rj_decode(isset($_POST['edukasi_json']) ? $_POST['edukasi_json'] : '');
    $evaluasi = rj_decode(isset($_POST['evaluasi_json']) ? $_POST['evaluasi_json'] : '');
    $kejadian = rj_decode(isset($_POST['kejadian_json']) ? $_POST['kejadian_json'] : '');
    $perawat_nik = trim(isset($_POST['perawat_nik']) ? $_POST['perawat_nik'] : '');
    $perawat_nama = trim(isset($_POST['perawat_nama']) ? $_POST['perawat_nama'] : '');
    $karu_nik = trim(isset($_POST['karu_nik']) ? $_POST['karu_nik'] : '');
    $karu_nama = trim(isset($_POST['karu_nama']) ? $_POST['karu_nama'] : '');

    if ($no_rawat === '' || empty($identitas['nm_pasien']) || $perawat_nik === '' || $karu_nik === '') {
        header('Location: '.rj_url(array('mode'=>'form', 'info'=>'wajib')));
        exit;
    }

    $no_form = isset($_POST['no_form']) && trim($_POST['no_form']) !== '' ? trim($_POST['no_form']) : rj_generate_nomor_form();
    $tanggal_form = !empty($skrining['tanggal']) ? str_replace('T',' ', $skrining['tanggal']) : date('Y-m-d H:i:s');

    $fields = array(
        'no_form' => $no_form,
        'no_rawat' => $no_rawat,
        'tanggal_form' => $tanggal_form,
        'identitas_json' => json_encode($identitas, JSON_UNESCAPED_UNICODE),
        'skrining_json' => json_encode($skrining, JSON_UNESCAPED_UNICODE),
        'intervensi_json' => json_encode($intervensi, JSON_UNESCAPED_UNICODE),
        'edukasi_json' => json_encode($edukasi, JSON_UNESCAPED_UNICODE),
        'evaluasi_json' => json_encode($evaluasi, JSON_UNESCAPED_UNICODE),
        'kejadian_json' => json_encode($kejadian, JSON_UNESCAPED_UNICODE),
        'perawat_nik' => $perawat_nik,
        'perawat_nama' => $perawat_nama,
        'karu_nik' => $karu_nik,
        'karu_nama' => $karu_nama
    );

    if ($id > 0) {
        $sets = array();
        foreach ($fields as $name => $value) $sets[] = "`$name`='".rj_sql($value)."'";
        $sets[] = "updated_at=NOW()";
        bukaquery("UPDATE intervensi_risiko_jatuh SET ".implode(',', $sets)." WHERE id='$id'");
        $saved_id = $id;
    } else {
        $columns = array();
        $values = array();
        foreach ($fields as $name => $value) {
            $columns[] = "`$name`";
            $values[] = "'".rj_sql($value)."'";
        }
        $columns[] = '`created_at`';
        $columns[] = '`updated_at`';
        $values[] = 'NOW()';
        $values[] = 'NOW()';
        bukaquery("INSERT INTO intervensi_risiko_jatuh (".implode(',', $columns).") VALUES (".implode(',', $values).")");
        $lookup = rj_query_one("SELECT id FROM intervensi_risiko_jatuh WHERE no_form='".rj_sql($no_form)."' ORDER BY id DESC LIMIT 1");
        $saved_id = $lookup ? (int)$lookup['id'] : 0;
    }
    header('Location: '.rj_url(array('mode'=>'daftar', 'info'=>'simpan', 'cetak'=>$saved_id)));
    exit;
}

/* =========================
   Data cetak
   ========================= */
if ($mode === 'cetak' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $record = rj_query_one("SELECT * FROM intervensi_risiko_jatuh WHERE id='$id' LIMIT 1");
    if (!$record) exit('Data tidak ditemukan.');

    $identity = rj_decode($record['identitas_json']);
    $screening = rj_decode($record['skrining_json']);
    $interventions = rj_decode($record['intervensi_json']);
    $education = rj_decode($record['edukasi_json']);
    $evaluations = rj_decode($record['evaluasi_json']);
    $incident = rj_decode($record['kejadian_json']);
    $category_labels = array('umum'=>'A. Intervensi Umum (Semua Pasien)', 'sedang'=>'B. Intervensi Risiko Sedang', 'tinggi'=>'C. Intervensi Risiko Tinggi');
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Cetak Intervensi Risiko Jatuh - <?=rj_h($record['no_form'])?></title>
<style>
@page { size: legal portrait; margin: 8mm 8mm 10mm 8mm; }
* { box-sizing:border-box; }
body { margin:0; color:#000; font:10pt Arial, Helvetica, sans-serif; }
.no-print { text-align:center; margin:10px; }
.btn { border:0; background:#1f5f99; color:#fff; border-radius:4px; padding:8px 14px; cursor:pointer; }
.sheet { width:100%; }
.kop { display:grid; grid-template-columns:95px 1fr 90px; align-items:center; gap:8px; border-bottom:3px double #000; padding:0 4px 8px; }
.kop img { max-width:90px; max-height:74px; object-fit:contain; display:block; margin:auto; }
.kop-title { text-align:center; line-height:1.25; }
.kop-title h1 { margin:0; font-size:19pt; letter-spacing:.2px; }
.kop-title div { font-size:10.6pt; }
.doc-title { text-align:center; font-size:16pt; line-height:1.15; font-weight:700; margin:12px 0 10px; }
.doc-meta { display:flex; justify-content:space-between; font-size:9pt; margin:2px 0 7px; }
.section-title { background:#e9e9e9; border:1px solid #000; padding:4px 6px; font-weight:700; margin-top:7px; }
table { width:100%; border-collapse:collapse; margin:0; }
th, td { border:1px solid #000; padding:4px 5px; vertical-align:top; }
th { text-align:center; background:#f5f5f5; }
.identity td:nth-child(1), .identity td:nth-child(3) { width:17%; font-weight:700; background:#fafafa; }
.identity td:nth-child(2), .identity td:nth-child(4) { width:33%; }
.small { font-size:8.4pt; }
.center { text-align:center; vertical-align:middle; }
.yes { font-weight:700; }
.signature-grid { display:grid; grid-template-columns:1fr 1fr; gap:36px; margin-top:11px; text-align:center; }
.signature-box { min-height:86px; padding-top:3px; }
.signature-box .barcode-svg { display:block; max-width:100%; height:52px; margin:6px auto 0; }
.note { font-size:8pt; margin-top:8px; border-top:1px solid #000; padding-top:4px; }
.empty { min-height:26px; }
@media print { .no-print { display:none; } body { font-size:9.5pt; } }
</style>
</head>
<body>
<div class="no-print"><button class="btn" onclick="window.print()">Cetak Dokumen</button></div>
<main class="sheet">
    <header class="kop">
        <img src="assets/logo_cmc.png" alt="Logo RS CMC">
        <div class="kop-title">
            <h1>RS. CANDIMAS MEDICAL CENTER</h1>
            <div>Jl. Lintas Sumatera No.21, Klp. Tujuh, Kec. Kotabumi Sel., Kabupaten Lampung Utara, Lampung 34511</div>
            <div>Telp/HP: 0821-8154-9355 &nbsp; Email: rumahsakitcmc@gmail.com</div>
        </div>
        <img src="assets/logo_paripurna.png" alt="Logo Akreditasi">
    </header>

    <div class="doc-title">FORMULIR TINDAKAN DAN/ATAU INTERVENSI<br>PENCEGAHAN RISIKO JATUH PASIEN</div>
    <div class="doc-meta">
        <span>No. Form: <b><?=rj_h($record['no_form'])?></b></span>
        <span>Tanggal: <b><?=rj_display_datetime($record['tanggal_form'])?></b></span>
    </div>

    <div class="section-title">I. IDENTITAS PASIEN</div>
    <table class="identity">
        <tr><td>Nama Pasien</td><td><?=rj_h(isset($identity['nm_pasien'])?$identity['nm_pasien']:'-')?></td><td>No. RM</td><td><?=rj_h(isset($identity['no_rkm_medis'])?$identity['no_rkm_medis']:'-')?></td></tr>
        <tr><td>No. Rawat</td><td><?=rj_h($record['no_rawat'])?></td><td>Jenis Pelayanan</td><td><?=rj_h(isset($identity['status_lanjut'])?$identity['status_lanjut']:'-')?></td></tr>
        <tr><td>Ruang/Poli</td><td><?=rj_h(isset($identity['ruang_rawat'])?$identity['ruang_rawat']:'-')?></td><td>Tanggal Masuk/Daftar</td><td><?=rj_display_datetime(isset($identity['tgl_masuk'])?$identity['tgl_masuk']:'')?></td></tr>
        <tr><td>Diagnosa</td><td><?=rj_h(isset($identity['diagnosa'])?$identity['diagnosa']:'-')?></td><td>DPJP</td><td><?=rj_h(isset($identity['nm_dokter'])?$identity['nm_dokter']:'-')?></td></tr>
    </table>

    <div class="section-title">II. HASIL SKRINING RISIKO JATUH</div>
    <table>
      <tr><th>Jenis Asesmen</th><th>Skor</th><th>Risiko</th><th>Tanggal/Jam Asesmen</th><th>Perawat</th></tr>
      <tr>
        <td><?=rj_h(isset($screening['jenis'])?$screening['jenis']:'-')?></td>
        <td class="center"><?=rj_h(isset($screening['skor'])?$screening['skor']:'-')?></td>
        <td class="center"><?=rj_h(isset($screening['risiko'])?$screening['risiko']:'-')?></td>
        <td class="center"><?=rj_display_datetime(isset($screening['tanggal'])?$screening['tanggal']:'')?></td>
        <td><?=rj_h(isset($screening['perawat'])?$screening['perawat']:$record['perawat_nama'])?></td>
      </tr>
    </table>

    <div class="section-title">III. INTERVENSI PENCEGAHAN RISIKO JATUH</div>
    <?php foreach ($category_labels as $key=>$label): $rows = isset($interventions[$key]) && is_array($interventions[$key]) ? $interventions[$key] : array(); ?>
      <div style="font-weight:700; margin:5px 0 2px;"><?=rj_h($label)?></div>
      <table class="small">
        <tr><th style="width:5%">No</th><th>Intervensi</th><th style="width:12%">Dilaksanakan</th><th style="width:17%">Tanggal/Jam</th><th style="width:18%">Paraf Petugas</th></tr>
        <?php if (!$rows): ?><tr><td colspan="5" class="center">-</td></tr><?php endif; ?>
        <?php foreach ($rows as $i=>$row): ?>
        <tr>
          <td class="center"><?=rj_h(isset($row['no'])?$row['no']:($i+1))?></td>
          <td><?=rj_h(isset($row['teks'])?$row['teks']:'')?></td>
          <td class="center <?=isset($row['status']) && $row['status']==='Ya'?'yes':''?>"><?=rj_h(isset($row['status'])?$row['status']:'-')?></td>
          <td class="center"><?=rj_display_datetime(isset($row['tanggal'])?$row['tanggal']:'')?></td>
          <td class="center"><?=rj_h(isset($row['paraf']) && $row['paraf']!=='' ? $row['paraf'] : '-')?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endforeach; ?>

    <div class="section-title">IV. EDUKASI PASIEN DAN KELUARGA</div>
    <table class="small">
      <tr><th style="width:7%">No</th><th>Materi Edukasi</th><th style="width:16%">Ya</th><th style="width:16%">Tidak</th></tr>
      <?php if (!$education): ?><tr><td colspan="4" class="center">-</td></tr><?php endif; ?>
      <?php foreach ($education as $i=>$row): ?>
      <tr><td class="center"><?=($i+1)?></td><td><?=rj_h(isset($row['materi'])?$row['materi']:'')?></td><td class="center"><?=isset($row['jawaban']) && $row['jawaban']==='Ya'?'✓':''?></td><td class="center"><?=isset($row['jawaban']) && $row['jawaban']==='Tidak'?'✓':''?></td></tr>
      <?php endforeach; ?>
    </table>

    <div class="section-title">V. EVALUASI</div>
    <table class="small">
      <tr><th style="width:17%">Tanggal/Jam</th><th>Hasil Evaluasi</th><th style="width:14%">Risiko Saat Ini</th><th>Tindak Lanjut</th><th style="width:17%">Petugas</th></tr>
      <?php if (!$evaluations): ?><tr><td colspan="5" class="center">Belum ada evaluasi</td></tr><?php endif; ?>
      <?php foreach ($evaluations as $row): ?>
      <tr><td class="center"><?=rj_display_datetime(isset($row['tanggal'])?$row['tanggal']:'')?></td><td><?=rj_h(isset($row['hasil'])?$row['hasil']:'')?></td><td class="center"><?=rj_h(isset($row['risiko'])?$row['risiko']:'')?></td><td><?=rj_h(isset($row['tindak_lanjut'])?$row['tindak_lanjut']:'')?></td><td class="center"><?=rj_h(isset($row['petugas'])?$row['petugas']:'')?></td></tr>
      <?php endforeach; ?>
    </table>

    <div class="section-title">VI. BILA TERJADI KEJADIAN JATUH</div>
    <?php if (isset($incident['terjadi']) && $incident['terjadi']==='Ya'): ?>
    <table class="small">
      <tr><th style="width:17%">Tanggal/Jam</th><th>Kronologi</th><th>Cedera</th><th>Tindakan</th><th>Dilaporkan Ke</th></tr>
      <tr><td class="center"><?=rj_display_datetime(isset($incident['tanggal'])?$incident['tanggal']:'')?></td><td><?=rj_h(isset($incident['kronologi'])?$incident['kronologi']:'')?></td><td><?=rj_h(isset($incident['cedera'])?$incident['cedera']:'')?></td><td><?=rj_h(isset($incident['tindakan'])?$incident['tindakan']:'')?></td><td><?=rj_h(isset($incident['dilaporkan_ke'])?$incident['dilaporkan_ke']:'')?></td></tr>
    </table>
    <?php else: ?><div class="empty" style="border:1px solid #000; padding:5px;">Tidak ada kejadian jatuh yang dilaporkan pada formulir ini.</div><?php endif; ?>

    <div class="section-title">VII. VERIFIKASI</div>
    <div class="signature-grid">
      <div class="signature-box">
          <div>Perawat Pelaksana</div>
          <?=rj_code39_svg($record['perawat_nik'])?>
          <div><b><?=rj_h($record['perawat_nama'])?></b></div>
          <div>NIK: <?=rj_h($record['perawat_nik'])?></div>
      </div>
      <div class="signature-box">
          <div>Kepala Ruangan</div>
          <?=rj_code39_svg($record['karu_nik'])?>
          <div><b><?=rj_h($record['karu_nama'])?></b></div>
          <div>NIK: <?=rj_h($record['karu_nik'])?></div>
      </div>
    </div>

    <div class="note">
      <b>Petunjuk pengisian:</b> Intervensi disesuaikan dengan kategori risiko pasien. Pilihan Ya/Tidak memunculkan tanggal/jam otomatis dan paraf petugas secara elektronik. Barcode verifikasi menggantikan TTD manual dan merepresentasikan petugas yang dipilih pada sistem.
    </div>
</main>
<script>window.addEventListener('load', function(){ window.print(); });</script>
</body>
</html><?php
    exit;
}

/* =========================
   Ambil record edit / petugas
   ========================= */
$edit = array();
if ($mode === 'edit' && isset($_GET['id'])) {
    $edit = rj_query_one("SELECT * FROM intervensi_risiko_jatuh WHERE id='".(int)$_GET['id']."' LIMIT 1");
    if (!$edit) {
        header('Location: '.rj_url(array('mode'=>'daftar')));
        exit;
    }
}
$pegawai = array();
$result_petugas = bukaquery("SELECT nik, nama FROM pegawai ORDER BY nama ASC");
if ($result_petugas) {
    while ($p = mysqli_fetch_assoc($result_petugas)) $pegawai[] = $p;
}

/* =========================
   Daftar data
   ========================= */
if ($mode === 'daftar') {
    $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
    $filter = '';
    if ($keyword !== '') {
        $like = rj_sql($keyword);
        $filter = " WHERE (irj.no_form LIKE '%$like%' OR irj.no_rawat LIKE '%$like%' OR p.nm_pasien LIKE '%$like%')";
    }
    $sql = "SELECT irj.id, irj.no_form, irj.no_rawat, irj.tanggal_form, irj.perawat_nama, irj.created_at,
                   p.no_rkm_medis, p.nm_pasien, rp.status_lanjut
            FROM intervensi_risiko_jatuh irj
            LEFT JOIN reg_periksa rp ON irj.no_rawat=rp.no_rawat
            LEFT JOIN pasien p ON rp.no_rkm_medis=p.no_rkm_medis
            $filter
            ORDER BY irj.created_at DESC, irj.id DESC";
    $result = bukaquery($sql);
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><title>Daftar Intervensi Risiko Jatuh</title>
<style>
body{margin:0;background:#f3f6f9;font:14px Arial,sans-serif;color:#17212b}.wrap{max-width:1380px;margin:auto;padding:22px}.top{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px}.brand{font-size:20px;font-weight:700}.nav,.actions{display:flex;gap:7px;flex-wrap:wrap}.btn{display:inline-block;border:0;border-radius:5px;padding:9px 12px;color:white;background:#1769aa;text-decoration:none;cursor:pointer}.btn.gray{background:#5e6974}.btn.green{background:#177d45}.btn.red{background:#b83232}.card{background:white;border-radius:8px;box-shadow:0 1px 4px #ced6de;padding:16px}.notice{padding:10px;border-radius:5px;background:#d9f7e5;border:1px solid #92d9ae;margin-bottom:11px}.search{display:flex;gap:7px;margin-bottom:12px}.search input{padding:9px;border:1px solid #b8c2cd;border-radius:5px;min-width:280px}table{border-collapse:collapse;width:100%;font-size:13px}th,td{padding:10px 8px;border-bottom:1px solid #e1e6eb;text-align:left;vertical-align:top}th{background:#eef3f7;white-space:nowrap}.muted{color:#647180}@media(max-width:760px){.top{align-items:flex-start;flex-direction:column}.search input{min-width:0;flex:1}.table-scroll{overflow:auto}}
</style>
</head>
<body><main class="wrap">
  <div class="top"><div><div class="brand">Daftar Intervensi Pencegahan Risiko Jatuh</div><div class="muted">Rawat jalan dan rawat inap</div></div><div class="nav"><a class="btn green" href="<?=rj_h(rj_url(array('mode'=>'form')))?>">+ Form Baru</a></div></div>
  <div class="card">
  <?php if (isset($_GET['info']) && $_GET['info']==='simpan'): ?><div class="notice">Data intervensi risiko jatuh berhasil disimpan.</div><?php endif; ?>
  <?php if (isset($_GET['info']) && $_GET['info']==='hapus'): ?><div class="notice">Data intervensi risiko jatuh berhasil dihapus.</div><?php endif; ?>
  <form class="search" method="get"><input type="hidden" name="mode" value="daftar"><input type="text" name="keyword" value="<?=rj_h($keyword)?>" placeholder="Cari nomor form, nomor rawat, atau nama pasien"><button class="btn" type="submit">Cari</button><a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'daftar')))?>">Reset</a></form>
  <div class="table-scroll"><table>
    <tr><th>No.</th><th>No. Form</th><th>Tanggal</th><th>Pasien</th><th>No. RM / No. Rawat</th><th>Pelayanan</th><th>Perawat</th><th>Aksi</th></tr>
    <?php $no=1; if ($result && mysqli_num_rows($result)>0): while ($row=mysqli_fetch_assoc($result)): ?>
    <tr>
      <td><?=($no++)?></td><td><b><?=rj_h($row['no_form'])?></b></td><td><?=rj_display_datetime($row['tanggal_form'])?></td>
      <td><?=rj_h($row['nm_pasien'] ? $row['nm_pasien'] : '-')?></td><td><?=rj_h($row['no_rkm_medis'] ? $row['no_rkm_medis'] : '-')?><br><span class="muted"><?=rj_h($row['no_rawat'])?></span></td>
      <td><?=rj_h($row['status_lanjut'] ? $row['status_lanjut'] : '-')?></td><td><?=rj_h($row['perawat_nama'])?></td>
      <td class="actions">
        <a class="btn" href="<?=rj_h(rj_url(array('mode'=>'cetak','id'=>$row['id'])))?>" target="_blank">Cetak</a>
        <a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'edit','id'=>$row['id'])))?>">Edit</a>
        <a class="btn red" href="<?=rj_h(rj_url(array('mode'=>'hapus','id'=>$row['id'])))?>" onclick="return confirm('Hapus formulir <?=rj_h($row['no_form'])?>?')">Hapus</a>
      </td>
    </tr>
    <?php endwhile; else: ?><tr><td colspan="8" style="text-align:center;padding:25px">Belum ada data.</td></tr><?php endif; ?>
  </table></div></div></main></body></html><?php
    exit;
}

/* =========================
   Form input / edit
   ========================= */
$edit_payload = array();
if ($edit) {
    $edit_payload = array(
        'id'=>(int)$edit['id'],
        'no_form'=>$edit['no_form'],
        'no_rawat'=>$edit['no_rawat'],
        'identity'=>rj_decode($edit['identitas_json']),
        'screening'=>rj_decode($edit['skrining_json']),
        'interventions'=>rj_decode($edit['intervensi_json']),
        'education'=>rj_decode($edit['edukasi_json']),
        'evaluations'=>rj_decode($edit['evaluasi_json']),
        'incident'=>rj_decode($edit['kejadian_json']),
        'perawat_nik'=>$edit['perawat_nik'],
        'karu_nik'=>$edit['karu_nik']
    );
}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title><?= $edit ? 'Edit' : 'Form' ?> Intervensi Risiko Jatuh</title>
<style>
:root{--blue:#1368a8;--dark:#123149;--green:#168044;--red:#b92e2e;--border:#cbd5df;--soft:#eef4f8}*{box-sizing:border-box}body{margin:0;background:#f1f5f8;font:14px Arial,Helvetica,sans-serif;color:#17212b}.wrap{max-width:1420px;margin:auto;padding:20px}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:10px}.brand{font-size:21px;font-weight:700;color:var(--dark)}.sub{color:#647180;margin-top:3px}.nav{display:flex;gap:7px}.btn{border:0;border-radius:5px;padding:9px 13px;background:var(--blue);color:#fff;text-decoration:none;cursor:pointer;font-size:14px}.btn.gray{background:#64717d}.btn.green{background:var(--green)}.btn.red{background:var(--red)}.btn.small{padding:6px 9px;font-size:12px}.card{background:#fff;border-radius:8px;box-shadow:0 1px 4px #cfd8e0;margin-bottom:14px;overflow:hidden}.card-title{padding:10px 14px;background:var(--dark);color:#fff;font-weight:700;font-size:15px}.card-body{padding:14px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px}.grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}.field label{display:block;font-weight:700;margin-bottom:4px}.field input,.field select,.field textarea{width:100%;border:1px solid var(--border);border-radius:4px;padding:8px;background:#fff;font:inherit}.field input[readonly],.readonly{background:#f1f4f7}.field textarea{min-height:68px;resize:vertical}.full{grid-column:1/-1}.search-row{display:flex;gap:8px;align-items:end}.search-row .field{flex:1}.notice{padding:10px;border:1px solid #e2c46c;background:#fff7d5;color:#6a5415;border-radius:5px;margin-bottom:10px}.status{padding:9px;border-radius:5px;margin-top:10px;display:none}.status.ok{display:block;background:#daf5e4;color:#155f36;border:1px solid #97d6ad}.status.err{display:block;background:#fde1e1;color:#8c2525;border:1px solid #f3b2b2}.section-note{font-size:12px;color:#56636f;margin:0 0 10px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:13px}th,td{border:1px solid var(--border);padding:7px;vertical-align:middle}th{background:var(--soft);text-align:center}td input,td select{padding:6px!important;font-size:12px!important}.intervention-table th:nth-child(1){width:42px}.intervention-table th:nth-child(3){width:120px}.intervention-table th:nth-child(4){width:160px}.intervention-table th:nth-child(5){width:175px}.category{font-weight:700;background:#dce9f2;padding:8px 10px;border:1px solid var(--border);border-bottom:0;margin-top:12px}.status-yes{background:#e8f8ee}.status-no{background:#fff3f3}.tag{display:inline-block;background:#eaf1f6;color:#44545f;border-radius:3px;padding:4px 6px;font-size:11px}.check-grid{display:grid;grid-template-columns:1fr 110px;gap:10px;align-items:center}.footer-actions{display:flex;justify-content:flex-end;gap:8px;padding:14px;position:sticky;bottom:0;background:#fff;border-top:1px solid var(--border)}.required{color:#b12323}@media(max-width:900px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.top{align-items:flex-start;flex-direction:column}}@media(max-width:560px){.wrap{padding:10px}.grid,.grid.two{grid-template-columns:1fr}.search-row{align-items:stretch;flex-direction:column}.footer-actions{position:static}.intervention-table{min-width:800px}}
</style>
</head>
<body>
<main class="wrap">
  <div class="top">
    <div><div class="brand"><?= $edit ? 'Edit' : 'Form' ?> Intervensi Pencegahan Risiko Jatuh</div><div class="sub">Pencatatan untuk pasien rawat jalan dan rawat inap</div></div>
    <div class="nav"><a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'daftar')))?>">Daftar Tindakan</a><a class="btn green" href="<?=rj_h(rj_url(array('mode'=>'form')))?>">Form Baru</a></div>
  </div>
  <?php if (isset($_GET['info']) && $_GET['info']==='wajib'): ?><div class="notice">Nomor rawat, identitas pasien, Perawat Pelaksana, dan Kepala Ruangan wajib dilengkapi.</div><?php endif; ?>
  <form id="form-rj" method="post" action="<?=rj_h(rj_url())?>">
    <input type="hidden" name="aksi" value="simpan">
    <input type="hidden" name="id" id="id" value="<?=rj_h($edit ? $edit['id'] : '')?>">
    <input type="hidden" name="no_form" id="no_form" value="<?=rj_h($edit ? $edit['no_form'] : '')?>">
    <input type="hidden" name="no_rawat" id="no_rawat">
    <input type="hidden" name="identitas_json" id="identitas_json">
    <input type="hidden" name="skrining_json" id="skrining_json">
    <input type="hidden" name="intervensi_json" id="intervensi_json">
    <input type="hidden" name="edukasi_json" id="edukasi_json">
    <input type="hidden" name="evaluasi_json" id="evaluasi_json">
    <input type="hidden" name="kejadian_json" id="kejadian_json">
    <input type="hidden" name="perawat_nik" id="perawat_nik">
    <input type="hidden" name="perawat_nama" id="perawat_nama">
    <input type="hidden" name="karu_nik" id="karu_nik">
    <input type="hidden" name="karu_nama" id="karu_nama">

    <section class="card">
      <div class="card-title">I. IDENTITAS PASIEN</div>
      <div class="card-body">
        <div class="search-row">
          <div class="field"><label>Nomor Rawat <span class="required">*</span></label><input id="cari_no_rawat" autocomplete="off" placeholder="Contoh: 2026/07/02/000001"></div>
          <button type="button" class="btn" id="btn-cari">Cari Data Pasien</button>
        </div>
        <div id="pesan-cari" class="status"></div>
        <div class="grid" style="margin-top:12px">
          <div class="field"><label>Nama Pasien</label><input id="nm_pasien" readonly></div>
          <div class="field"><label>No. RM</label><input id="no_rkm_medis" readonly></div>
          <div class="field"><label>Jenis Pelayanan</label><input id="status_lanjut" readonly></div>
          <div class="field"><label>Tanggal Masuk/Daftar</label><input id="tgl_masuk" readonly></div>
          <div class="field"><label>Ruang Rawat / Poli</label><input id="ruang_rawat" readonly></div>
          <div class="field"><label>Dokter Penanggung Jawab</label><input id="nm_dokter" readonly></div>
          <div class="field full"><label>Diagnosa</label><input id="diagnosa" readonly></div>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-title">II. HASIL SKRINING RISIKO JATUH</div>
      <div class="card-body"><div class="grid">
        <div class="field"><label>Jenis Asesmen <span class="required">*</span></label><select id="skrining_jenis"><option value="">-- Pilih --</option><option>Morse Fall Scale</option><option>Humpty Dumpty</option><option>Edmonson</option></select></div>
        <div class="field"><label>Skor</label><input id="skrining_skor" type="number" min="0" placeholder="Masukkan skor"></div>
        <div class="field"><label>Risiko <span class="required">*</span></label><select id="skrining_risiko"><option value="">-- Pilih --</option><option>Rendah</option><option>Sedang</option><option>Tinggi</option></select></div>
        <div class="field"><label>Tanggal/Jam Asesmen</label><input id="skrining_tanggal" type="datetime-local" value="<?=rj_h(rj_now_local())?>"></div>
        <div class="field full"><label>Perawat Asesmen</label><input id="skrining_perawat" readonly placeholder="Mengikuti Perawat Pelaksana pada bagian Verifikasi"></div>
      </div></div>
    </section>

    <section class="card">
      <div class="card-title">III. INTERVENSI PENCEGAHAN RISIKO JATUH</div>
      <div class="card-body">
        <p class="section-note">Pilih <b>Ya</b> atau <b>Tidak</b> pada setiap intervensi. Sistem mencatat tanggal/jam otomatis dan mengisi paraf elektronik dari Perawat Pelaksana yang dipilih pada bagian Verifikasi.</p>
        <div id="intervention-container"></div>
      </div>
    </section>

    <section class="card">
      <div class="card-title">IV. EDUKASI PASIEN DAN KELUARGA</div>
      <div class="card-body"><div class="table-wrap"><table id="education-table">
        <thead><tr><th style="width:50px">No</th><th>Materi Edukasi</th><th style="width:160px">Diberikan</th></tr></thead>
        <tbody></tbody>
      </table></div></div>
    </section>

    <section class="card">
      <div class="card-title">V. EVALUASI</div>
      <div class="card-body">
        <p class="section-note">Tambahkan evaluasi setelah reasesmen, perubahan kondisi pasien, pergantian shift, atau setelah kejadian jatuh.</p>
        <div class="table-wrap"><table id="evaluation-table">
          <thead><tr><th style="width:155px">Tanggal/Jam</th><th>Hasil Evaluasi</th><th style="width:135px">Risiko Saat Ini</th><th>Tindak Lanjut</th><th style="width:160px">Petugas</th><th style="width:55px">Aksi</th></tr></thead>
          <tbody></tbody>
        </table></div>
        <button type="button" class="btn small green" id="btn-add-evaluation" style="margin-top:10px">+ Tambah Evaluasi</button>
      </div>
    </section>

    <section class="card">
      <div class="card-title">VI. BILA TERJADI KEJADIAN JATUH</div>
      <div class="card-body">
        <div class="grid two">
          <div class="field"><label>Terjadi Kejadian Jatuh?</label><select id="kejadian_terjadi"><option value="Tidak">Tidak</option><option value="Ya">Ya</option></select></div>
          <div class="field"><label>Tanggal/Jam Kejadian</label><input id="kejadian_tanggal" type="datetime-local" disabled></div>
          <div class="field full"><label>Kronologi</label><textarea id="kejadian_kronologi" disabled></textarea></div>
          <div class="field"><label>Cedera yang Terjadi</label><textarea id="kejadian_cedera" disabled></textarea></div>
          <div class="field"><label>Tindakan yang Dilakukan</label><textarea id="kejadian_tindakan" disabled></textarea></div>
          <div class="field full"><label>Dilaporkan Kepada</label><input id="kejadian_dilaporkan" disabled></div>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-title">VII. VERIFIKASI</div>
      <div class="card-body">
        <p class="section-note">Pilih data petugas dari tabel <code>pegawai</code>. Saat cetak, identitas petugas ditampilkan sebagai barcode Code 39 dan menggantikan TTD manual.</p>
        <div class="grid two">
          <div class="field"><label>Perawat Pelaksana <span class="required">*</span></label>
            <select id="select_perawat"><option value="">-- Pilih Perawat Pelaksana --</option><?php foreach($pegawai as $p): ?><option value="<?=rj_h($p['nik'])?>" data-nama="<?=rj_h($p['nama'])?>"><?=rj_h($p['nik'].' - '.$p['nama'])?></option><?php endforeach; ?></select>
          </div>
          <div class="field"><label>Kepala Ruangan <span class="required">*</span></label>
            <select id="select_karu"><option value="">-- Pilih Kepala Ruangan --</option><?php foreach($pegawai as $p): ?><option value="<?=rj_h($p['nik'])?>" data-nama="<?=rj_h($p['nama'])?>"><?=rj_h($p['nik'].' - '.$p['nama'])?></option><?php endforeach; ?></select>
          </div>
          <div class="field"><label>Preview Paraf Elektronik Perawat</label><input id="preview_paraf_perawat" class="readonly" readonly></div>
          <div class="field"><label>Preview Barcode</label><input class="readonly" readonly value="Barcode akan terbentuk pada dokumen cetak."></div>
        </div>
      </div>
      <div class="footer-actions"><a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'daftar')))?>">Batal</a><button class="btn green" type="submit">Simpan Formulir</button></div>
    </section>
  </form>
</main>
<script>
(function(){
'use strict';
var ajaxUrl = <?=json_encode(rj_url(array('mode'=>'ajax_pasien')))?>;
var existing = <?=json_encode($edit_payload, JSON_UNESCAPED_UNICODE)?> || {};
var categories = {
  umum: {
    title: 'A. Intervensi Umum (Semua Pasien)',
    rows: [
      'Memberikan edukasi kepada pasien dan keluarga mengenai pencegahan jatuh',
      'Tempat tidur pada posisi terendah',
      'Rem tempat tidur dikunci',
      'Bel pasien berada dalam jangkauan',
      'Barang pribadi dalam jangkauan pasien',
      'Lingkungan bebas dari benda yang dapat menyebabkan tersandung',
      'Pencahayaan ruangan cukup',
      'Pasien menggunakan alas kaki anti slip'
    ]
  },
  sedang: {
    title: 'B. Intervensi Risiko Sedang',
    rows: [
      'Pasang gelang risiko jatuh warna kuning',
      'Pasang tanda risiko jatuh pada tempat tidur/pintu kamar',
      'Orientasi lingkungan kepada pasien',
      'Anjurkan meminta bantuan sebelum mobilisasi',
      'Observasi kondisi pasien setiap shift'
    ]
  },
  tinggi: {
    title: 'C. Intervensi Risiko Tinggi',
    rows: [
      'Seluruh intervensi risiko sedang dilakukan',
      'Naikkan pagar tempat tidur sesuai indikasi',
      'Pendampingan saat mobilisasi atau ke kamar mandi',
      'Tempatkan pasien dekat nurse station bila memungkinkan',
      'Monitoring setiap 1–2 jam',
      'Evaluasi obat yang meningkatkan risiko jatuh bersama DPJP/farmasi',
      'Gunakan alat bantu jalan sesuai kebutuhan',
      'Kolaborasi dengan fisioterapis bila diperlukan',
      'Pastikan infus, kateter, atau selang tidak menghambat mobilisasi',
      'Reasesmen risiko jatuh setiap perubahan kondisi pasien'
    ]
  }
};
var educationRows = [
  'Risiko jatuh pasien',
  'Cara meminta bantuan petugas',
  'Penggunaan bel pasien',
  'Penggunaan alas kaki yang aman',
  'Larangan berjalan sendiri bila kondisi tidak memungkinkan'
];
function el(id){ return document.getElementById(id); }
function escapeHtml(value) {
  return String(value == null ? '' : value).replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]; });
}
function nowLocal() {
  var d = new Date();
  var z = n => String(n).padStart(2, '0');
  return d.getFullYear()+'-'+z(d.getMonth()+1)+'-'+z(d.getDate())+'T'+z(d.getHours())+':'+z(d.getMinutes());
}
function setSelect(id, value) { var node=el(id); if(node && value != null) node.value=value; }
function selectedName(select) {
  if (!select || select.selectedIndex < 0) return '';
  return select.options[select.selectedIndex].getAttribute('data-nama') || '';
}
function getPerawatName() { return selectedName(el('select_perawat')); }
function getPerawatCode() {
  var nik = el('select_perawat').value || '';
  var nama = getPerawatName();
  if (!nik) return '';
  var initial = nama.split(/\s+/).filter(Boolean).map(function(s){return s.charAt(0).toUpperCase();}).join('').slice(0,4);
  return nik + (initial ? ' / '+initial : '');
}
function updatePerawatPreview() {
  var nama = getPerawatName(), code = getPerawatCode();
  el('skrining_perawat').value = nama ? (nama + ' (' + (el('select_perawat').value || '') + ')') : '';
  el('preview_paraf_perawat').value = code;
  document.querySelectorAll('.input-paraf').forEach(function(input){
    if (input.dataset.locked !== '1') input.value = code;
  });
}
function renderInterventions(data) {
  var html = '';
  Object.keys(categories).forEach(function(key){
    var c = categories[key], saved = (data && data[key]) || [];
    html += '<div class="category" data-category="'+key+'">'+escapeHtml(c.title)+'</div>';
    html += '<div class="table-wrap"><table class="intervention-table" data-category="'+key+'"><thead><tr><th>No</th><th>Intervensi</th><th>Dilaksanakan</th><th>Tanggal/Jam</th><th>Paraf Elektronik</th></tr></thead><tbody>';
    c.rows.forEach(function(text,index){
      var row = saved[index] || {};
      var status = row.status || '';
      html += '<tr class="'+(status==='Ya'?'status-yes':status==='Tidak'?'status-no':'')+'">';
      html += '<td style="text-align:center">'+(index+1)+'</td><td>'+escapeHtml(text)+'</td>';
      html += '<td><select class="input-status"><option value="">-- Pilih --</option><option value="Ya" '+(status==='Ya'?'selected':'')+'>Ya</option><option value="Tidak" '+(status==='Tidak'?'selected':'')+'>Tidak</option></select></td>';
      html += '<td><input type="datetime-local" class="input-tanggal" readonly value="'+escapeHtml(row.tanggal || '')+'"></td>';
      html += '<td><input type="text" class="input-paraf readonly" readonly value="'+escapeHtml(row.paraf || '')+'"></td></tr>';
    });
    html += '</tbody></table></div>';
  });
  el('intervention-container').innerHTML = html;
  document.querySelectorAll('.input-status').forEach(function(select){
    select.addEventListener('change', function(){
      var row = select.closest('tr'), time = row.querySelector('.input-tanggal'), paraf=row.querySelector('.input-paraf');
      if (select.value) {
        time.value=nowLocal();
        paraf.value=getPerawatCode();
      } else { time.value=''; paraf.value=''; }
      row.classList.remove('status-yes','status-no');
      if(select.value==='Ya') row.classList.add('status-yes');
      if(select.value==='Tidak') row.classList.add('status-no');
    });
  });
}
function renderEducation(data) {
  var html = '';
  educationRows.forEach(function(materi,index) {
    var row = (data && data[index]) || {};
    var v = row.jawaban || '';
    html += '<tr><td style="text-align:center">'+(index+1)+'</td><td>'+escapeHtml(materi)+'</td><td><select class="edukasi-jawaban"><option value="">-- Pilih --</option><option value="Ya" '+(v==='Ya'?'selected':'')+'>Ya</option><option value="Tidak" '+(v==='Tidak'?'selected':'')+'>Tidak</option></select></td></tr>';
  });
  el('education-table').querySelector('tbody').innerHTML = html;
}
function evaluationRow(row) {
  row=row || {};
  return '<tr>'+
    '<td><input type="datetime-local" class="eval-tanggal" value="'+escapeHtml(row.tanggal||nowLocal())+'"></td>'+
    '<td><input class="eval-hasil" value="'+escapeHtml(row.hasil||'')+'" placeholder="Contoh: Tidak ada kejadian jatuh"></td>'+
    '<td><select class="eval-risiko"><option value="">-- Pilih --</option><option '+(row.risiko==='Rendah'?'selected':'')+'>Rendah</option><option '+(row.risiko==='Sedang'?'selected':'')+'>Sedang</option><option '+(row.risiko==='Tinggi'?'selected':'')+'>Tinggi</option></select></td>'+
    '<td><input class="eval-tindak" value="'+escapeHtml(row.tindak_lanjut||'')+'" placeholder="Tindak lanjut"></td>'+
    '<td><input class="eval-petugas readonly" readonly value="'+escapeHtml(row.petugas || getPerawatCode())+'"></td>'+
    '<td style="text-align:center"><button type="button" class="btn small red btn-delete-eval">Hapus</button></td></tr>';
}
function bindDeleteEvaluation() {
  document.querySelectorAll('.btn-delete-eval').forEach(function(btn){ btn.onclick=function(){ btn.closest('tr').remove(); }; });
}
function renderEvaluations(data) {
  var tbody=el('evaluation-table').querySelector('tbody');
  tbody.innerHTML='';
  (data || []).forEach(function(row){ tbody.insertAdjacentHTML('beforeend', evaluationRow(row)); });
  bindDeleteEvaluation();
}
function setIncidentEnabled() {
  var enabled=el('kejadian_terjadi').value==='Ya';
  ['kejadian_tanggal','kejadian_kronologi','kejadian_cedera','kejadian_tindakan','kejadian_dilaporkan'].forEach(function(id){ el(id).disabled=!enabled; });
  if(enabled && !el('kejadian_tanggal').value) el('kejadian_tanggal').value=nowLocal();
}
function collectIdentity() {
  return { no_rawat:el('no_rawat').value, no_rkm_medis:el('no_rkm_medis').value, nm_pasien:el('nm_pasien').value, status_lanjut:el('status_lanjut').value, tgl_masuk:el('tgl_masuk').value, ruang_rawat:el('ruang_rawat').value, nm_dokter:el('nm_dokter').value, diagnosa:el('diagnosa').value };
}
function collectInterventions() {
  var output={};
  document.querySelectorAll('.intervention-table').forEach(function(table){
    var key=table.dataset.category;
    output[key]=[];
    table.querySelectorAll('tbody tr').forEach(function(row, index) {
      output[key].push({no:index+1, teks:categories[key].rows[index], status:row.querySelector('.input-status').value, tanggal:row.querySelector('.input-tanggal').value, paraf:row.querySelector('.input-paraf').value});
    });
  });
  return output;
}
function collectEducation() {
  return Array.from(document.querySelectorAll('.edukasi-jawaban')).map(function(select,index){return {materi:educationRows[index], jawaban:select.value};});
}
function collectEvaluation() {
  return Array.from(el('evaluation-table').querySelectorAll('tbody tr')).map(function(row){ return {
    tanggal:row.querySelector('.eval-tanggal').value,
    hasil:row.querySelector('.eval-hasil').value,
    risiko:row.querySelector('.eval-risiko').value,
    tindak_lanjut:row.querySelector('.eval-tindak').value,
    petugas:row.querySelector('.eval-petugas').value
  };});
}
function loadIdentity(identity) {
  identity=identity || {};
  ['no_rawat','no_rkm_medis','nm_pasien','status_lanjut','tgl_masuk','ruang_rawat','nm_dokter','diagnosa'].forEach(function(k){
    var target=el(k); if(target) target.value=identity[k] || '';
  });
  el('cari_no_rawat').value=identity.no_rawat || '';
}
function loadExisting() {
  if (!existing || !existing.id) {
    renderInterventions();
    renderEducation();
    renderEvaluations();
    return;
  }
  loadIdentity(existing.identity);
  var s=existing.screening || {};
  setSelect('skrining_jenis',s.jenis); el('skrining_skor').value=s.skor||''; setSelect('skrining_risiko',s.risiko); el('skrining_tanggal').value=s.tanggal||'';
  setSelect('select_perawat',existing.perawat_nik); setSelect('select_karu',existing.karu_nik); updatePerawatPreview();
  renderInterventions(existing.interventions||{});
  renderEducation(existing.education||[]);
  renderEvaluations(existing.evaluations||[]);
  var k=existing.incident||{}; setSelect('kejadian_terjadi',k.terjadi||'Tidak'); el('kejadian_tanggal').value=k.tanggal||''; el('kejadian_kronologi').value=k.kronologi||''; el('kejadian_cedera').value=k.cedera||''; el('kejadian_tindakan').value=k.tindakan||''; el('kejadian_dilaporkan').value=k.dilaporkan_ke||''; setIncidentEnabled();
}
function statusMessage(type,msg){var p=el('pesan-cari');p.className='status '+type;p.textContent=msg;}
el('btn-cari').addEventListener('click', function(){
  var rawat=el('cari_no_rawat').value.trim();
  if(!rawat){statusMessage('err','Masukkan nomor rawat terlebih dahulu.');return;}
  var button=this;button.disabled=true;button.textContent='Mencari...';
  fetch(ajaxUrl, {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'no_rawat='+encodeURIComponent(rawat)})
  .then(function(r){return r.json();}).then(function(res){
    if(res.status==='sukses'){loadIdentity(res.data);statusMessage('ok','Data pasien ditemukan. Periksa identitas sebelum mengisi formulir.');}
    else statusMessage('err',res.pesan||'Data pasien tidak ditemukan.');
  }).catch(function(){statusMessage('err','Gagal terhubung ke server saat mencari data pasien.');})
  .finally(function(){button.disabled=false;button.textContent='Cari Data Pasien';});
});
el('cari_no_rawat').addEventListener('keydown', function(e){if(e.key==='Enter'){e.preventDefault();el('btn-cari').click();}});
el('select_perawat').addEventListener('change',function(){updatePerawatPreview();});
el('kejadian_terjadi').addEventListener('change',setIncidentEnabled);
el('btn-add-evaluation').addEventListener('click',function(){ el('evaluation-table').querySelector('tbody').insertAdjacentHTML('beforeend',evaluationRow()); bindDeleteEvaluation(); });
el('form-rj').addEventListener('submit',function(e){
  var identity=collectIdentity(), perawat=el('select_perawat'), karu=el('select_karu');
  if(!identity.no_rawat || !identity.nm_pasien){e.preventDefault();alert('Cari dan pastikan data pasien terlebih dahulu.');return;}
  if(!el('skrining_risiko').value){e.preventDefault();alert('Pilih risiko jatuh pasien.');return;}
  if(!perawat.value || !karu.value){e.preventDefault();alert('Pilih Perawat Pelaksana dan Kepala Ruangan pada bagian Verifikasi.');return;}
  el('identitas_json').value=JSON.stringify(identity);
  el('skrining_json').value=JSON.stringify({jenis:el('skrining_jenis').value,skor:el('skrining_skor').value,risiko:el('skrining_risiko').value,tanggal:el('skrining_tanggal').value,perawat:el('skrining_perawat').value});
  el('intervensi_json').value=JSON.stringify(collectInterventions());
  el('edukasi_json').value=JSON.stringify(collectEducation());
  el('evaluasi_json').value=JSON.stringify(collectEvaluation());
  el('kejadian_json').value=JSON.stringify({terjadi:el('kejadian_terjadi').value,tanggal:el('kejadian_tanggal').value,kronologi:el('kejadian_kronologi').value,cedera:el('kejadian_cedera').value,tindakan:el('kejadian_tindakan').value,dilaporkan_ke:el('kejadian_dilaporkan').value});
  el('perawat_nik').value=perawat.value;el('perawat_nama').value=selectedName(perawat);el('karu_nik').value=karu.value;el('karu_nama').value=selectedName(karu);
});
loadExisting();
})();
</script>
</body>
</html>
