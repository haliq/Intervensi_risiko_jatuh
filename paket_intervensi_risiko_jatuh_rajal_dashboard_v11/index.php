<?php
/**
 * Modul Intervensi Pencegahan Risiko Jatuh - V11 Rawat Jalan + Rawat Inap + Dashboard
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

/*
 * Koneksi Khanza dapat tersedia sebagai $koneksi, $konektor, atau hanya
 * melalui fungsi bukakoneksi(). Jangan hanya mengandalkan global $koneksi.
 */
function rj_db_connection(&$error = '') {
    static $conn = null;
    $error = '';
    if ($conn instanceof mysqli) return $conn;

    global $koneksi, $konektor;
    if (isset($koneksi) && $koneksi instanceof mysqli) {
        $conn = $koneksi;
        return $conn;
    }
    if (isset($konektor) && $konektor instanceof mysqli) {
        $conn = $konektor;
        return $conn;
    }
    if (function_exists('bukakoneksi')) {
        $candidate = @bukakoneksi();
        if ($candidate instanceof mysqli) {
            $conn = $candidate;
            return $conn;
        }
    }
    $error = 'Koneksi MySQL tidak tersedia dari conf/conf.php.';
    return null;
}
function rj_sql($value) {
    $error = '';
    $conn = rj_db_connection($error);
    return $conn ? mysqli_real_escape_string($conn, (string)$value) : addslashes((string)$value);
}
function rj_db_query($sql, &$error = '') {
    $error = '';
    $conn = rj_db_connection($error);
    if (!$conn) return false;
    $result = @mysqli_query($conn, $sql);
    if ($result === false) $error = mysqli_error($conn);
    return $result;
}
function rj_query_one($sql) {
    $error = '';
    $result = rj_db_query($sql, $error);
    if ($result instanceof mysqli_result) {
        $row = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
        return $row ? $row : null;
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
function rj_qr_payload($npk, $nama, $peran, $no_form) {
    return "RS CANDIMAS MEDICAL CENTER\nFORM: ".$no_form."\nPETUGAS: ".$nama."\nJABATAN: ".$peran."\nNPK: ".$npk;
}
function rj_table_columns($table) {
    $columns = array();
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return $columns;
    $error = '';
    $conn = rj_db_connection($error);
    if (!$conn) return $columns;
    $result = @mysqli_query($conn, "SHOW COLUMNS FROM `" . $table . "`");
    if ($result instanceof mysqli_result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $columns[strtolower($row['Field'])] = $row['Field'];
        }
        mysqli_free_result($result);
    }
    return $columns;
}
function rj_column_exists($table, $column) {
    $columns = rj_table_columns($table);
    return isset($columns[strtolower($column)]);
}
function rj_pick_column($columns, $candidates, $fallback = '') {
    foreach ($candidates as $candidate) {
        $key = strtolower($candidate);
        if (isset($columns[$key])) return $columns[$key];
    }
    return $fallback;
}
function rj_form_error_redirect($message, $id = 0) {
    $params = array('mode' => 'form', 'info' => 'db', 'dbmsg' => $message);
    if ($id > 0) {
        $params = array('mode' => 'edit', 'id' => $id, 'info' => 'db', 'dbmsg' => $message);
    }
    header('Location: ' . rj_url($params));
    exit;
}

$mode = isset($_REQUEST['mode']) ? $_REQUEST['mode'] : 'form';

/* Struktur dibaca dari koneksi Khanza yang aktif. Ini mencegah fallback salah ke
   perawat_nik/karu_nik ketika tabel sudah memakai perawat_npk/karu_npk. */
$rj_pegawai_columns = rj_table_columns('pegawai');
$rj_form_columns = rj_table_columns('intervensi_risiko_jatuh');
$pegawai_kode_column = rj_pick_column($rj_pegawai_columns, array('npk', 'nik', 'nip'), 'nik');
$rj_perawat_storage_column = rj_pick_column($rj_form_columns, array('perawat_npk', 'perawat_nik'), 'perawat_npk');
$rj_karu_storage_column = rj_pick_column($rj_form_columns, array('karu_npk', 'karu_nik'), 'karu_npk');

/* =========================
   Sumber asesmen risiko jatuh lanjutan dari Khanza
   ========================= */
function rj_risiko_sources() {
    $configs = array(
        array('table'=>'penilaian_lanjutan_resiko_jatuh_anak',      'label'=>'Anak / Humpty Dumpty', 'score'=>array('penilaian_humptydumpty_totalnilai')),
        array('table'=>'penilaian_lanjutan_resiko_jatuh_dewasa',    'label'=>'Dewasa / Morse Fall Scale', 'score'=>array('penilaian_jatuhmorse_totalnilai')),
        array('table'=>'penilaian_lanjutan_resiko_jatuh_geriatri',  'label'=>'Geriatri', 'score'=>array('penilaian_jatuh_totalnilai')),
        array('table'=>'penilaian_lanjutan_resiko_jatuh_lansia',    'label'=>'Lansia', 'score'=>array('penilaian_jatuhmorse_totalnilai')),
        array('table'=>'penilaian_lanjutan_resiko_jatuh_psikiatri', 'label'=>'Psikiatri / Edmonson', 'score'=>array('penilaian_jatuhedmonson_totalnilai'))
    );
    $sources = array();
    foreach ($configs as $cfg) {
        $columns = rj_table_columns($cfg['table']);
        if (!$columns) continue;
        if (!isset($columns['no_rawat']) || !isset($columns['tanggal']) || !isset($columns['hasil_skrining'])) continue;
        $score_col = rj_pick_column($columns, $cfg['score'], '');
        $sources[] = array(
            'table'=>$cfg['table'],
            'label'=>$cfg['label'],
            'score'=>$score_col,
            'has_nip'=>isset($columns['nip']),
            'has_saran'=>isset($columns['saran'])
        );
    }
    return $sources;
}
function rj_risiko_union_sql($start_date, $end_date, $only_tinggi = true) {
    $sources = rj_risiko_sources();
    if (!$sources) return '';
    $parts = array();
    $start = rj_sql($start_date . ' 00:00:00');
    $end = rj_sql($end_date . ' 00:00:00');
    foreach ($sources as $src) {
        $table = $src['table'];
        $label = rj_sql($src['label']);
        $score = $src['score'] !== '' ? "CAST(t.`".$src['score']."` AS CHAR)" : "''";
        $nip = $src['has_nip'] ? "t.`nip`" : "''";
        $saran = $src['has_saran'] ? "COALESCE(t.`saran`,'')" : "''";
        $where = "t.`tanggal` >= '".$start."' AND t.`tanggal` < '".$end."'";
        if ($only_tinggi) {
            $where .= " AND LOWER(COALESCE(t.`hasil_skrining`,'')) LIKE '%tinggi%'";
        }
        $parts[] = "SELECT 
              '".$label."' AS jenis_asesmen,
              '".$table."' AS sumber_tabel,
              t.`no_rawat` AS no_rawat,
              t.`tanggal` AS tanggal_skrining,
              COALESCE(t.`hasil_skrining`,'') AS hasil_skrining,
              ".$score." AS skor,
              ".$saran." AS saran,
              ".$nip." AS nip
            FROM `".$table."` t
            WHERE ".$where;
    }
    return implode("\nUNION ALL\n", $parts);
}
function rj_month_name_id($month_no) {
    $names = array(1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember');
    $m = (int)$month_no;
    return isset($names[$m]) ? $names[$m] : $month_no;
}
function rj_risiko_period_from_month($bulan) {
    if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) $bulan = date('Y-m');
    $start = $bulan . '-01';
    $end = date('Y-m-d', strtotime($start . ' +1 month'));
    return array($start, $end);
}
function rj_is_ralan($status_lanjut) {
    $v = strtolower(trim((string)$status_lanjut));
    return in_array($v, array('ralan','rajal','rawat jalan'), true);
}
function rj_generate_nomor_form_rajal() {
    return 'IRJ-RJ' . date('YmdHis') . rand(10, 99);
}
function rj_rajal_error_redirect($message, $id = 0) {
    $params = array('mode' => 'form_rajal', 'info' => 'db', 'dbmsg' => $message);
    if ($id > 0) {
        $params = array('mode' => 'edit_rajal', 'id' => $id, 'info' => 'db', 'dbmsg' => $message);
    }
    header('Location: ' . rj_url($params));
    exit;
}
function rj_rajal_items() {
    return array(
        'Pemasangan stiker Risiko Jatuh pada lengan kanan pasien.',
        'Edukasi kepada pasien dan keluarga mengenai pencegahan risiko jatuh selama berada di area rawat jalan.',
        'Pasien dianjurkan selalu didampingi keluarga/pengantar selama berada di rumah sakit.',
        'Pasien ditempatkan pada tempat duduk di area prioritas yang mudah dipantau petugas.',
        'Pasien diprioritaskan dalam antrean pelayanan sesuai kondisi klinis.',
        'Kursi roda disediakan apabila diperlukan.',
        'Alat bantu jalan digunakan sesuai kebutuhan pasien.',
        'Petugas membantu pasien saat berjalan atau berpindah tempat.',
        'Memastikan lingkungan aman (lantai kering, tidak licin, bebas hambatan, pencahayaan cukup).',
        'Memastikan pasien menggunakan alas kaki yang aman/tidak licin.',
        'Pasien dipantau selama menunggu pelayanan.',
        'Seluruh intervensi dan edukasi didokumentasikan di rekam medis/SIMRS.'
    );
}
function rj_rajal_eval_labels() {
    return array(
        'Pasien pulang dalam kondisi aman.',
        'Tidak terjadi insiden jatuh.',
        'Terjadi hampir jatuh (Near Miss).',
        'Terjadi pasien jatuh.'
    );
}
function rj_dashboard_intervensi_route($status_lanjut, $has_intervensi, $id_intervensi, $no_rawat, $print = false) {
    $is_ralan = rj_is_ralan($status_lanjut);
    if ($has_intervensi && $id_intervensi > 0) {
        return rj_url(array('mode' => $is_ralan ? ($print ? 'cetak_rajal' : 'edit_rajal') : ($print ? 'cetak' : 'edit'), 'id' => $id_intervensi));
    }
    return rj_url(array('mode' => $is_ralan ? 'form_rajal' : 'form', 'no_rawat' => $no_rawat));
}


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
        $delete_error = '';
        rj_db_query("DELETE FROM intervensi_risiko_jatuh WHERE id='$id'", $delete_error);
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
    $perawat_npk = trim(isset($_POST['perawat_npk']) ? $_POST['perawat_npk'] : '');
    $perawat_nama = trim(isset($_POST['perawat_nama']) ? $_POST['perawat_nama'] : '');
    $karu_npk = trim(isset($_POST['karu_npk']) ? $_POST['karu_npk'] : '');
    $karu_nama = trim(isset($_POST['karu_nama']) ? $_POST['karu_nama'] : '');

    if ($no_rawat === '' || empty($identitas['nm_pasien']) || $perawat_npk === '' || $karu_npk === '') {
        header('Location: '.rj_url(array('mode'=>'form', 'info'=>'wajib')));
        exit;
    }

    /* Pastikan tabel dan kolom yang dipakai benar sebelum menyusun INSERT. */
    $rj_form_columns = rj_table_columns('intervensi_risiko_jatuh');
    if (!$rj_form_columns) {
        rj_form_error_redirect('Tabel intervensi_risiko_jatuh tidak ditemukan atau tidak dapat dibaca. Jalankan SQL instalasi tabel.', $id);
    }
    $rj_perawat_storage_column = rj_pick_column($rj_form_columns, array('perawat_npk', 'perawat_nik'));
    $rj_karu_storage_column = rj_pick_column($rj_form_columns, array('karu_npk', 'karu_nik'));
    if ($rj_perawat_storage_column === '' || $rj_karu_storage_column === '') {
        rj_form_error_redirect('Kolom verifikasi perawat tidak sesuai. Tabel harus memiliki perawat_npk dan karu_npk, atau kolom versi lama perawat_nik dan karu_nik.', $id);
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
        $rj_perawat_storage_column => $perawat_npk,
        'perawat_nama' => $perawat_nama,
        $rj_karu_storage_column => $karu_npk,
        'karu_nama' => $karu_nama
    );

    foreach ($fields as $field_name => $unused) {
        if (!isset($rj_form_columns[strtolower($field_name)])) {
            rj_form_error_redirect('Struktur tabel belum sesuai: kolom `'.$field_name.'` tidak ditemukan. Jalankan SQL instalasi atau migrasi modul.', $id);
        }
    }

    $sql_error = '';
    if ($id > 0) {
        $sets = array();
        foreach ($fields as $name => $value) $sets[] = "`$name`='".rj_sql($value)."'";
        if (isset($rj_form_columns['updated_at'])) $sets[] = "`".$rj_form_columns['updated_at']."`=NOW()";
        $ok = rj_db_query("UPDATE intervensi_risiko_jatuh SET ".implode(',', $sets)." WHERE id='$id'", $sql_error);
        if (!$ok) rj_form_error_redirect('Data tidak dapat diperbarui. Detail MySQL: '.$sql_error, $id);
        $saved_id = $id;
    } else {
        $columns = array();
        $values = array();
        foreach ($fields as $name => $value) {
            $columns[] = "`$name`";
            $values[] = "'".rj_sql($value)."'";
        }
        if (isset($rj_form_columns['created_at'])) {
            $columns[] = "`".$rj_form_columns['created_at']."`";
            $values[] = 'NOW()';
        }
        if (isset($rj_form_columns['updated_at'])) {
            $columns[] = "`".$rj_form_columns['updated_at']."`";
            $values[] = 'NOW()';
        }
        $ok = rj_db_query("INSERT INTO intervensi_risiko_jatuh (".implode(',', $columns).") VALUES (".implode(',', $values).")", $sql_error);
        if (!$ok) rj_form_error_redirect('Data tidak dapat disimpan. Detail MySQL: '.$sql_error, 0);
        $conn_error = '';
        $conn = rj_db_connection($conn_error);
        $saved_id = $conn ? (int)mysqli_insert_id($conn) : 0;
        if ($saved_id <= 0) {
            $lookup = rj_query_one("SELECT id FROM intervensi_risiko_jatuh WHERE no_form='".rj_sql($no_form)."' ORDER BY id DESC LIMIT 1");
            $saved_id = $lookup ? (int)$lookup['id'] : 0;
        }
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

    // Kompatibel dengan data paket lama sebelum nama kolom NIK dimigrasikan ke NPK.
    $record_perawat_npk = isset($record['perawat_npk']) ? $record['perawat_npk'] : (isset($record['perawat_nik']) ? $record['perawat_nik'] : '');
    $record_karu_npk = isset($record['karu_npk']) ? $record['karu_npk'] : (isset($record['karu_nik']) ? $record['karu_nik'] : '');

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
.signature-box { min-height:122px; padding-top:3px; }
.signature-box .qr-code { display:block; width:74px; height:74px; margin:6px auto 4px; }
.signature-box .qr-code svg { display:block; width:74px; height:74px; }
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
          <div class="qr-code" data-qr-text="<?=rj_h(rj_qr_payload($record_perawat_npk, $record['perawat_nama'], 'Perawat Pelaksana', $record['no_form']))?>" aria-label="QR Code Perawat Pelaksana"></div>
          <div><b><?=rj_h($record['perawat_nama'])?></b></div>
          <div>NPK: <?=rj_h($record_perawat_npk)?></div>
      </div>
      <div class="signature-box">
          <div>Kepala Ruangan</div>
          <div class="qr-code" data-qr-text="<?=rj_h(rj_qr_payload($record_karu_npk, $record['karu_nama'], 'Kepala Ruangan', $record['no_form']))?>" aria-label="QR Code Kepala Ruangan"></div>
          <div><b><?=rj_h($record['karu_nama'])?></b></div>
          <div>NPK: <?=rj_h($record_karu_npk)?></div>
      </div>
    </div>

    <div class="note">
      <b>Petunjuk pengisian:</b> Intervensi disesuaikan dengan kategori risiko pasien. Pilihan Ya/Tidak memunculkan tanggal/jam otomatis dan paraf petugas secara elektronik. QR Code verifikasi menggantikan TTD manual dan memuat NPK, nama, jabatan, serta nomor formulir.
    </div>
</main>
<script src="assets/qrcode-browser.js"></script>
<script>
(function(){
  function renderQr(target) {
    var text = target.getAttribute('data-qr-text') || '';
    if (!text || !window.RJQRCode || !window.RJQRErrorCorrectLevel) return;
    try {
      var qr = new window.RJQRCode(0, window.RJQRErrorCorrectLevel.M);
      qr.addData(text);
      qr.make();
      var moduleCount = qr.getModuleCount(), quiet = 4, total = moduleCount + (quiet * 2);
      var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '+total+' '+total+'" role="img" aria-label="QR Code" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/>';
      for (var r = 0; r < moduleCount; r++) {
        for (var c = 0; c < moduleCount; c++) {
          if (qr.isDark(r, c)) svg += '<rect x="'+(c + quiet)+'" y="'+(r + quiet)+'" width="1" height="1" fill="#000"/>';
        }
      }
      target.innerHTML = svg + '</svg>';
    } catch (err) {
      target.textContent = 'QR Code tidak dapat dibuat';
    }
  }
  window.addEventListener('load', function(){
    document.querySelectorAll('.qr-code').forEach(renderQr);
    window.setTimeout(function(){ window.print(); }, 120);
  });
})();
</script>
</body>
</html><?php
    exit;
}



/* =========================
   Rawat Jalan: simpan / hapus / cetak
   ========================= */
if ($mode === 'hapus_rajal' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id > 0) {
        $delete_error = '';
        rj_db_query("DELETE FROM intervensi_risiko_jatuh_rajal WHERE id='$id'", $delete_error);
    }
    header('Location: '.rj_url(array('mode'=>'daftar_rajal', 'info'=>'hapus')));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi']) && $_POST['aksi'] === 'simpan_rajal') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $no_rawat = trim(isset($_POST['no_rawat']) ? $_POST['no_rawat'] : '');
    $identitas = rj_decode(isset($_POST['identitas_json']) ? $_POST['identitas_json'] : '');
    $skrining = rj_decode(isset($_POST['skrining_json']) ? $_POST['skrining_json'] : '');
    $intervensi = rj_decode(isset($_POST['intervensi_json']) ? $_POST['intervensi_json'] : '');
    $evaluasi = rj_decode(isset($_POST['evaluasi_json']) ? $_POST['evaluasi_json'] : '');
    $edukasi = rj_decode(isset($_POST['edukasi_json']) ? $_POST['edukasi_json'] : '');
    $petugas_npk = trim(isset($_POST['petugas_npk']) ? $_POST['petugas_npk'] : '');
    $petugas_nama = trim(isset($_POST['petugas_nama']) ? $_POST['petugas_nama'] : '');
    $petugas_jabatan = trim(isset($_POST['petugas_jabatan']) ? $_POST['petugas_jabatan'] : 'Petugas Pelaksana');
    $penerima_nama = trim(isset($_POST['penerima_nama']) ? $_POST['penerima_nama'] : '');
    $hubungan_penerima = trim(isset($_POST['hubungan_penerima']) ? $_POST['hubungan_penerima'] : '');

    if ($no_rawat === '' || empty($identitas['nm_pasien']) || empty($skrining['risiko']) || $petugas_npk === '') {
        $back = $id > 0 ? array('mode'=>'edit_rajal','id'=>$id,'info'=>'wajib') : array('mode'=>'form_rajal','info'=>'wajib');
        header('Location: '.rj_url($back));
        exit;
    }

    $cols = rj_table_columns('intervensi_risiko_jatuh_rajal');
    if (!$cols) rj_rajal_error_redirect('Tabel intervensi_risiko_jatuh_rajal belum tersedia. Jalankan SQL install_intervensi_risiko_jatuh_rajal.sql.', $id);

    $no_form = isset($_POST['no_form']) && trim($_POST['no_form']) !== '' ? trim($_POST['no_form']) : rj_generate_nomor_form_rajal();
    $tanggal_form = !empty($skrining['tanggal']) ? str_replace('T',' ', $skrining['tanggal']) : date('Y-m-d H:i:s');

    $fields = array(
        'no_form' => $no_form,
        'no_rawat' => $no_rawat,
        'tanggal_form' => $tanggal_form,
        'identitas_json' => json_encode($identitas, JSON_UNESCAPED_UNICODE),
        'skrining_json' => json_encode($skrining, JSON_UNESCAPED_UNICODE),
        'intervensi_json' => json_encode($intervensi, JSON_UNESCAPED_UNICODE),
        'evaluasi_json' => json_encode($evaluasi, JSON_UNESCAPED_UNICODE),
        'edukasi_json' => json_encode($edukasi, JSON_UNESCAPED_UNICODE),
        'penerima_nama' => $penerima_nama,
        'hubungan_penerima' => $hubungan_penerima,
        'petugas_npk' => $petugas_npk,
        'petugas_nama' => $petugas_nama,
        'petugas_jabatan' => $petugas_jabatan
    );
    foreach ($fields as $field_name => $unused) {
        if (!isset($cols[strtolower($field_name)])) rj_rajal_error_redirect('Struktur tabel rawat jalan belum sesuai: kolom `'.$field_name.'` tidak ditemukan.', $id);
    }

    $sql_error = '';
    if ($id > 0) {
        $sets = array();
        foreach ($fields as $name => $value) $sets[] = "`$name`='".rj_sql($value)."'";
        if (isset($cols['updated_at'])) $sets[] = "`".$cols['updated_at']."`=NOW()";
        $ok = rj_db_query("UPDATE intervensi_risiko_jatuh_rajal SET ".implode(',', $sets)." WHERE id='$id'", $sql_error);
        if (!$ok) rj_rajal_error_redirect('Data rawat jalan tidak dapat diperbarui. Detail MySQL: '.$sql_error, $id);
        $saved_id = $id;
    } else {
        $columns = array();
        $values = array();
        foreach ($fields as $name => $value) {
            $columns[] = "`$name`";
            $values[] = "'".rj_sql($value)."'";
        }
        if (isset($cols['created_at'])) { $columns[] = "`".$cols['created_at']."`"; $values[] = 'NOW()'; }
        if (isset($cols['updated_at'])) { $columns[] = "`".$cols['updated_at']."`"; $values[] = 'NOW()'; }
        $ok = rj_db_query("INSERT INTO intervensi_risiko_jatuh_rajal (".implode(',', $columns).") VALUES (".implode(',', $values).")", $sql_error);
        if (!$ok) rj_rajal_error_redirect('Data rawat jalan tidak dapat disimpan. Detail MySQL: '.$sql_error, 0);
        $conn_error = '';
        $conn = rj_db_connection($conn_error);
        $saved_id = $conn ? (int)mysqli_insert_id($conn) : 0;
        if ($saved_id <= 0) {
            $lookup = rj_query_one("SELECT id FROM intervensi_risiko_jatuh_rajal WHERE no_form='".rj_sql($no_form)."' ORDER BY id DESC LIMIT 1");
            $saved_id = $lookup ? (int)$lookup['id'] : 0;
        }
    }
    header('Location: '.rj_url(array('mode'=>'daftar_rajal', 'info'=>'simpan', 'cetak'=>$saved_id)));
    exit;
}

if ($mode === 'cetak_rajal' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $record = rj_query_one("SELECT * FROM intervensi_risiko_jatuh_rajal WHERE id='$id' LIMIT 1");
    if (!$record) exit('Data rawat jalan tidak ditemukan.');
    $identity = rj_decode($record['identitas_json']);
    $screening = rj_decode($record['skrining_json']);
    $interventions = rj_decode($record['intervensi_json']);
    $evaluasi = rj_decode($record['evaluasi_json']);
    $edukasi = rj_decode($record['edukasi_json']);
    $items = rj_rajal_items();
    $eval_labels = rj_rajal_eval_labels();
    ?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><title>Cetak Intervensi Risiko Jatuh Rawat Jalan - <?=rj_h($record['no_form'])?></title>
<style>
@page{size:legal portrait;margin:9mm 9mm 10mm 9mm}*{box-sizing:border-box}body{margin:0;color:#000;font:10pt Arial,Helvetica,sans-serif}.no-print{text-align:center;margin:10px}.btn{border:0;background:#1f5f99;color:#fff;border-radius:4px;padding:8px 14px;cursor:pointer}.kop{display:grid;grid-template-columns:95px 1fr 90px;align-items:center;gap:8px;border-bottom:3px double #000;padding:0 4px 8px}.kop img{max-width:90px;max-height:74px;object-fit:contain;display:block;margin:auto}.kop-title{text-align:center;line-height:1.25}.kop-title h1{margin:0;font-size:19pt;letter-spacing:.2px}.kop-title div{font-size:10.6pt}.doc-title{text-align:center;font-size:15.5pt;line-height:1.15;font-weight:700;margin:11px 0 9px}.doc-meta{display:flex;justify-content:space-between;font-size:9pt;margin:2px 0 7px}.section-title{background:#e9e9e9;border:1px solid #000;padding:4px 6px;font-weight:700;margin-top:7px}table{width:100%;border-collapse:collapse;margin:0}th,td{border:1px solid #000;padding:4px 5px;vertical-align:top}th{text-align:center;background:#f5f5f5}.identity td:nth-child(1),.identity td:nth-child(3){width:17%;font-weight:700;background:#fafafa}.identity td:nth-child(2),.identity td:nth-child(4){width:33%}.center{text-align:center;vertical-align:middle}.small{font-size:8.7pt}.signature-grid{display:grid;grid-template-columns:1fr 1fr;gap:34px;margin-top:12px;text-align:center}.signature-box{min-height:116px;padding-top:3px}.qr-code{display:block;width:74px;height:74px;margin:6px auto 4px}.qr-code svg{display:block;width:74px;height:74px}.lines{min-height:40px;border:1px solid #000;padding:5px}.note{font-size:8pt;margin-top:8px;border-top:1px solid #000;padding-top:4px}@media print{.no-print{display:none}body{font-size:9.5pt}}
</style></head><body><div class="no-print"><button class="btn" onclick="window.print()">Cetak Dokumen</button></div><main>
<header class="kop"><img src="assets/logo_cmc.png" alt="Logo RS CMC"><div class="kop-title"><h1>RS. CANDIMAS MEDICAL CENTER</h1><div>Jl. Lintas Sumatera No.21, Klp. Tujuh, Kec. Kotabumi Sel., Kabupaten Lampung Utara, Lampung 34511</div><div>Telp/HP: 0821-8154-9355 &nbsp; Email: rumahsakitcmc@gmail.com</div></div><img src="assets/logo_paripurna.png" alt="Logo Akreditasi"></header>
<div class="doc-title">FORM INTERVENSI RISIKO JATUH<br>PASIEN RAWAT JALAN</div>
<div class="doc-meta"><span>No. Form: <b><?=rj_h($record['no_form'])?></b></span><span>Tanggal/Jam: <b><?=rj_display_datetime($record['tanggal_form'])?> WIB</b></span></div>
<div class="section-title">I. IDENTITAS PASIEN</div>
<table class="identity"><tr><td>Nama Pasien</td><td><?=rj_h(isset($identity['nm_pasien'])?$identity['nm_pasien']:'-')?></td><td>No. Rekam Medis</td><td><?=rj_h(isset($identity['no_rkm_medis'])?$identity['no_rkm_medis']:'-')?></td></tr><tr><td>No. Rawat</td><td><?=rj_h($record['no_rawat'])?></td><td>Poli</td><td><?=rj_h(isset($identity['ruang_rawat'])?$identity['ruang_rawat']:'-')?></td></tr><tr><td>DPJP</td><td><?=rj_h(isset($identity['nm_dokter'])?$identity['nm_dokter']:'-')?></td><td>Tanggal Daftar</td><td><?=rj_display_datetime(isset($identity['tgl_masuk'])?$identity['tgl_masuk']:'')?></td></tr></table>
<div class="section-title">II. HASIL SKRINING RISIKO JATUH</div>
<table><tr><th>Risiko Rendah</th><th>Risiko Tinggi</th><th>Skor</th><th>Jenis Asesmen</th></tr><tr><td class="center"><?=isset($screening['risiko']) && $screening['risiko']==='Rendah'?'✓':''?></td><td class="center"><?=isset($screening['risiko']) && $screening['risiko']==='Tinggi'?'✓':''?></td><td class="center"><?=rj_h(isset($screening['skor'])?$screening['skor']:'-')?></td><td><?=rj_h(isset($screening['jenis'])?$screening['jenis']:'-')?></td></tr></table>
<div class="section-title">III. INTERVENSI PENCEGAHAN RISIKO JATUH</div>
<table class="small"><tr><th style="width:5%">No</th><th>Intervensi</th><th style="width:8%">Ya</th><th style="width:8%">Tidak</th><th style="width:24%">Keterangan</th></tr>
<?php foreach ($items as $i=>$label): $row=isset($interventions[$i])?$interventions[$i]:array(); $jaw=isset($row['jawaban'])?$row['jawaban']:''; ?>
<tr><td class="center"><?=($i+1)?></td><td><?=rj_h($label)?></td><td class="center"><?=$jaw==='Ya'?'✓':''?></td><td class="center"><?=$jaw==='Tidak'?'✓':''?></td><td><?=rj_h(isset($row['keterangan'])?$row['keterangan']:'')?></td></tr>
<?php endforeach; ?>
</table>
<div class="section-title">IV. EVALUASI</div>
<table class="small"><tr><th>Evaluasi</th><th style="width:10%">Ya</th></tr><?php foreach ($eval_labels as $label): ?><tr><td><?=rj_h($label)?></td><td class="center"><?=in_array($label, $evaluasi['pilihan'] ?? array(), true)?'✓':''?></td></tr><?php endforeach; ?></table>
<div style="margin-top:5px"><b>Bila terjadi insiden, uraian singkat:</b><div class="lines"><?=nl2br(rj_h(isset($evaluasi['uraian'])?$evaluasi['uraian']:''))?></div></div>
<div style="margin-top:5px"><b>Tindak lanjut:</b><div class="lines"><?=nl2br(rj_h(isset($evaluasi['tindak_lanjut'])?$evaluasi['tindak_lanjut']:''))?></div></div>
<div class="section-title">V. EDUKASI DITERIMA OLEH</div>
<table><tr><td style="width:22%;font-weight:bold">Nama Pasien/Keluarga</td><td><?=rj_h($record['penerima_nama'])?></td><td style="width:15%;font-weight:bold">Hubungan</td><td><?=rj_h($record['hubungan_penerima'])?></td></tr><tr><td style="font-weight:bold">Tanda Tangan</td><td colspan="3" style="height:42px"></td></tr></table>
<div class="section-title">VI. PETUGAS PELAKSANA</div>
<div class="signature-grid"><div class="signature-box"><div>Petugas Pelaksana</div><div class="qr-code" data-qr-text="<?=rj_h(rj_qr_payload($record['petugas_npk'], $record['petugas_nama'], $record['petugas_jabatan'], $record['no_form']))?>"></div><div><b><?=rj_h($record['petugas_nama'])?></b></div><div>NPK: <?=rj_h($record['petugas_npk'])?></div><div>Jabatan: <?=rj_h($record['petugas_jabatan'])?></div></div><div class="signature-box"><div>Tanggal/Jam</div><br><br><b><?=rj_display_datetime($record['tanggal_form'])?> WIB</b></div></div>
<div class="note">QR Code verifikasi petugas memuat NPK, nama, jabatan, dan nomor formulir. Form rawat jalan ini digunakan untuk pasien dengan hasil skrining risiko jatuh rendah/tinggi di area rawat jalan.</div>
</main><script src="assets/qrcode-browser.js"></script><script>(function(){function renderQr(t){var text=t.getAttribute('data-qr-text')||'';if(!text||!window.RJQRCode||!window.RJQRErrorCorrectLevel)return;try{var qr=new window.RJQRCode(0,window.RJQRErrorCorrectLevel.M);qr.addData(text);qr.make();var mc=qr.getModuleCount(),q=4,total=mc+(q*2),svg='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '+total+' '+total+'" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/>';for(var r=0;r<mc;r++){for(var c=0;c<mc;c++){if(qr.isDark(r,c))svg+='<rect x="'+(c+q)+'" y="'+(r+q)+'" width="1" height="1" fill="#000"/>';}}t.innerHTML=svg+'</svg>';}catch(e){t.textContent='QR Code tidak dapat dibuat';}}window.addEventListener('load',function(){document.querySelectorAll('.qr-code').forEach(renderQr);setTimeout(function(){window.print();},120);});})();</script></body></html><?php
    exit;
}

/* =========================
   Dashboard / daftar risiko jatuh tinggi dari hasil skrining Khanza
   ========================= */
if ($mode === 'risiko_tinggi') {
    $bulan = isset($_GET['bulan']) && preg_match('/^\d{4}-\d{2}$/', $_GET['bulan']) ? $_GET['bulan'] : date('Y-m');
    $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
    list($start_month, $end_month) = rj_risiko_period_from_month($bulan);
    $tahun = substr($bulan, 0, 4);
    $year_start = $tahun . '-01-01';
    $year_end = ((int)$tahun + 1) . '-01-01';

    $sources = rj_risiko_sources();
    $dashboard_error = '';
    $rows = array();
    $monthly_rows = array();

    if (!$sources) {
        $dashboard_error = 'Tabel penilaian lanjutan risiko jatuh belum ditemukan atau strukturnya belum sesuai.';
    } else {
        $union = rj_risiko_union_sql($start_month, $end_month, true);
        $where_keyword = '';
        if ($keyword !== '') {
            $like = rj_sql($keyword);
            $where_keyword = " AND (x.no_rawat LIKE '%$like%' OR rp.no_rkm_medis LIKE '%$like%' OR p.nm_pasien LIKE '%$like%' OR x.hasil_skrining LIKE '%$like%' OR x.jenis_asesmen LIKE '%$like%')";
        }
        $ruang_expr = "CASE 
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
        END";
        $sql_risiko = "SELECT 
                x.jenis_asesmen, x.sumber_tabel, x.no_rawat, x.tanggal_skrining, x.hasil_skrining, x.skor, x.saran,
                rp.no_rkm_medis, rp.status_lanjut, p.nm_pasien, d.nm_dokter, ".$ruang_expr." AS ruang_rawat,
                CASE WHEN rp.status_lanjut IN ('Ralan','Rajal','Rawat Jalan') THEN COALESCE(irj_rajal.total_intervensi,0) ELSE COALESCE(irj_ranap.total_intervensi,0) END AS total_intervensi,
                CASE WHEN rp.status_lanjut IN ('Ralan','Rajal','Rawat Jalan') THEN irj_rajal.id_intervensi ELSE irj_ranap.id_intervensi END AS id_intervensi,
                CASE WHEN rp.status_lanjut IN ('Ralan','Rajal','Rawat Jalan') THEN irj_rajal.no_form_intervensi ELSE irj_ranap.no_form_intervensi END AS no_form_intervensi,
                CASE WHEN rp.status_lanjut IN ('Ralan','Rajal','Rawat Jalan') THEN irj_rajal.tanggal_intervensi ELSE irj_ranap.tanggal_intervensi END AS tanggal_intervensi,
                CASE WHEN rp.status_lanjut IN ('Ralan','Rajal','Rawat Jalan') THEN 'Rawat Jalan' ELSE 'Rawat Inap' END AS jenis_form_intervensi
            FROM (".$union.") x
            INNER JOIN reg_periksa rp ON x.no_rawat=rp.no_rawat
            INNER JOIN pasien p ON rp.no_rkm_medis=p.no_rkm_medis
            LEFT JOIN dokter d ON rp.kd_dokter=d.kd_dokter
            LEFT JOIN poliklinik pl ON rp.kd_poli=pl.kd_poli
            LEFT JOIN (
                SELECT no_rawat, COUNT(*) AS total_intervensi, MAX(id) AS id_intervensi,
                       MAX(no_form) AS no_form_intervensi, MAX(tanggal_form) AS tanggal_intervensi
                FROM intervensi_risiko_jatuh
                GROUP BY no_rawat
            ) irj_ranap ON irj_ranap.no_rawat=x.no_rawat
            LEFT JOIN (
                SELECT no_rawat, COUNT(*) AS total_intervensi, MAX(id) AS id_intervensi,
                       MAX(no_form) AS no_form_intervensi, MAX(tanggal_form) AS tanggal_intervensi
                FROM intervensi_risiko_jatuh_rajal
                GROUP BY no_rawat
            ) irj_rajal ON irj_rajal.no_rawat=x.no_rawat
            WHERE 1=1 ".$where_keyword."
            ORDER BY x.tanggal_skrining DESC, p.nm_pasien ASC";
        $error_query = '';
        $result_risiko = rj_db_query($sql_risiko, $error_query);
        if ($error_query !== '') $dashboard_error = $error_query;
        if ($result_risiko instanceof mysqli_result) {
            while ($row = mysqli_fetch_assoc($result_risiko)) $rows[] = $row;
            mysqli_free_result($result_risiko);
        }

        $union_year = rj_risiko_union_sql($year_start, $year_end, true);
        if ($union_year !== '') {
            $sql_monthly = "SELECT DATE_FORMAT(x.tanggal_skrining,'%Y-%m') AS bulan,
                                   COUNT(*) AS total_tinggi,
                                   SUM(CASE WHEN ((rp.status_lanjut IN ('Ralan','Rajal','Rawat Jalan') AND irj_rajal.no_rawat IS NOT NULL) OR (rp.status_lanjut NOT IN ('Ralan','Rajal','Rawat Jalan') AND irj_ranap.no_rawat IS NOT NULL)) THEN 1 ELSE 0 END) AS sudah_intervensi
                            FROM (".$union_year.") x
                            INNER JOIN reg_periksa rp ON x.no_rawat=rp.no_rawat
                            LEFT JOIN (SELECT DISTINCT no_rawat FROM intervensi_risiko_jatuh) irj_ranap ON irj_ranap.no_rawat=x.no_rawat
                            LEFT JOIN (SELECT DISTINCT no_rawat FROM intervensi_risiko_jatuh_rajal) irj_rajal ON irj_rajal.no_rawat=x.no_rawat
                            GROUP BY DATE_FORMAT(x.tanggal_skrining,'%Y-%m')
                            ORDER BY bulan ASC";
            $err_month = '';
            $res_month = rj_db_query($sql_monthly, $err_month);
            if ($err_month !== '' && $dashboard_error === '') $dashboard_error = $err_month;
            if ($res_month instanceof mysqli_result) {
                while ($m = mysqli_fetch_assoc($res_month)) $monthly_rows[$m['bulan']] = $m;
                mysqli_free_result($res_month);
            }
        }
    }

    $total_tinggi = count($rows);
    $sudah_intervensi = 0;
    $belum_intervensi = 0;
    $by_jenis = array();
    $by_layanan = array();
    foreach ($rows as $r) {
        if ((int)$r['total_intervensi'] > 0) $sudah_intervensi++; else $belum_intervensi++;
        $jenis = $r['jenis_asesmen'] ? $r['jenis_asesmen'] : '-';
        $layanan = $r['status_lanjut'] ? $r['status_lanjut'] : '-';
        $by_jenis[$jenis] = isset($by_jenis[$jenis]) ? $by_jenis[$jenis] + 1 : 1;
        $by_layanan[$layanan] = isset($by_layanan[$layanan]) ? $by_layanan[$layanan] + 1 : 1;
    }
    $cakupan = $total_tinggi > 0 ? round(($sudah_intervensi / $total_tinggi) * 100, 1) : 0;
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><title>Dashboard Risiko Jatuh Tinggi</title>
<style>
body{margin:0;background:#f3f6f9;font:14px Arial,sans-serif;color:#17212b}.wrap{max-width:1480px;margin:auto;padding:22px}.top{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px}.brand{font-size:21px;font-weight:700}.muted{color:#647180}.nav,.actions{display:flex;gap:7px;flex-wrap:wrap}.btn{display:inline-block;border:0;border-radius:5px;padding:9px 12px;color:white;background:#1769aa;text-decoration:none;cursor:pointer}.btn.gray{background:#5e6974}.btn.green{background:#177d45}.btn.red{background:#b83232}.btn.orange{background:#d97706}.btn.orange{background:#d97706}.card{background:white;border-radius:8px;box-shadow:0 1px 4px #ced6de;padding:16px;margin-bottom:14px}.notice.err{padding:10px;border-radius:5px;background:#fdecec;border:1px solid #ef9a9a;color:#8b2424;margin-bottom:11px}.search{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.search .field{display:flex;flex-direction:column;gap:4px}.search label{font-size:12px;color:#52616d;font-weight:700}.search input{padding:9px;border:1px solid #b8c2cd;border-radius:5px;min-width:230px}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}.metric{background:white;border-radius:8px;box-shadow:0 1px 4px #ced6de;padding:15px;border-left:6px solid #1769aa}.metric.redline{border-left-color:#b83232}.metric.greenline{border-left-color:#177d45}.metric.orangeline{border-left-color:#d97706}.metric .label{color:#60717f;font-size:12px;font-weight:700}.metric .value{font-size:28px;font-weight:800;margin-top:5px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.badge{display:inline-block;background:#eef5fb;border:1px solid #cfe1ef;border-radius:5px;padding:6px 8px;color:#344d60;margin:3px 4px 3px 0}table{border-collapse:collapse;width:100%;font-size:13px}th,td{padding:10px 8px;border-bottom:1px solid #e1e6eb;text-align:left;vertical-align:top}th{background:#eef3f7;white-space:nowrap}.table-scroll{overflow:auto}.status-done{color:#166534;font-weight:700}.status-pending{color:#991b1b;font-weight:700}.bar{height:9px;border-radius:20px;background:#e5edf4;overflow:hidden;min-width:120px}.bar span{display:block;height:100%;background:#1769aa}@media(max-width:980px){.cards,.grid{grid-template-columns:1fr 1fr}}@media(max-width:680px){.top{align-items:flex-start;flex-direction:column}.cards,.grid{grid-template-columns:1fr}.search{align-items:stretch;flex-direction:column}.search input{min-width:0;width:100%}.actions .btn{padding:7px 9px}}
</style>
</head>
<body><main class="wrap">
  <div class="top">
    <div><div class="brand">Dashboard Risiko Jatuh Tinggi</div><div class="muted">Mengambil hasil_skrining risiko tinggi dari tabel asesmen lanjutan, lalu dicocokkan dengan Form Intervensi Risiko Jatuh.</div></div>
    <div class="nav">
      <a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'daftar')))?>">Daftar Inap</a>
      <a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'daftar_rajal')))?>">Daftar Jalan</a>
      <a class="btn green" href="<?=rj_h(rj_url(array('mode'=>'form')))?>">+ Intervensi Inap</a>
      <a class="btn green" href="<?=rj_h(rj_url(array('mode'=>'form_rajal')))?>">+ Intervensi Jalan</a>
    </div>
  </div>

  <?php if ($dashboard_error !== ''): ?><div class="notice err"><b>Data belum dapat dimuat.</b><br><?=rj_h($dashboard_error)?></div><?php endif; ?>

  <div class="card">
    <form class="search" method="get">
      <input type="hidden" name="mode" value="risiko_tinggi">
      <div class="field"><label>Bulan Skrining</label><input type="month" name="bulan" value="<?=rj_h($bulan)?>"></div>
      <div class="field"><label>Pencarian</label><input type="text" name="keyword" value="<?=rj_h($keyword)?>" placeholder="Cari no rawat, No. RM, nama, jenis asesmen"></div>
      <button class="btn" type="submit">Tampilkan</button>
      <a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'risiko_tinggi')))?>">Bulan Ini</a>
    </form>
    <div style="margin-top:10px">
      <span class="badge">Periode: <b><?=rj_month_name_id((int)substr($bulan,5,2))?> <?=rj_h(substr($bulan,0,4))?></b></span>
      <?php if ($keyword !== ''): ?><span class="badge">Kata kunci: <b><?=rj_h($keyword)?></b></span><?php endif; ?>
      <span class="badge">Sumber aktif: <b><?=count($sources)?></b> tabel asesmen</span>
    </div>
  </div>

  <div class="cards">
    <div class="metric redline"><div class="label">Total Risiko Tinggi</div><div class="value"><?=number_format($total_tinggi,0,',','.')?></div></div>
    <div class="metric greenline"><div class="label">Sudah Ada Intervensi</div><div class="value"><?=number_format($sudah_intervensi,0,',','.')?></div></div>
    <div class="metric orangeline"><div class="label">Belum Ada Intervensi</div><div class="value"><?=number_format($belum_intervensi,0,',','.')?></div></div>
    <div class="metric"><div class="label">Cakupan Intervensi</div><div class="value"><?=$cakupan?>%</div></div>
  </div>

  <div class="grid">
    <div class="card">
      <h3 style="margin:0 0 10px">Rekap Per Bulan Tahun <?=rj_h($tahun)?></h3>
      <div class="table-scroll"><table>
        <tr><th>Bulan</th><th>Total Risiko Tinggi</th><th>Sudah Intervensi</th><th>Cakupan</th></tr>
        <?php for ($m=1; $m<=12; $m++): $key=$tahun.'-'.str_pad($m,2,'0',STR_PAD_LEFT); $mr=isset($monthly_rows[$key])?$monthly_rows[$key]:array('total_tinggi'=>0,'sudah_intervensi'=>0); $mt=(int)$mr['total_tinggi']; $ms=(int)$mr['sudah_intervensi']; $mp=$mt>0?round(($ms/$mt)*100,1):0; ?>
        <tr>
          <td><?=rj_month_name_id($m)?></td>
          <td><?=number_format($mt,0,',','.')?></td>
          <td><?=number_format($ms,0,',','.')?></td>
          <td><div class="bar"><span style="width:<?=$mp?>%"></span></div><?=$mp?>%</td>
        </tr>
        <?php endfor; ?>
      </table></div>
    </div>
    <div class="card">
      <h3 style="margin:0 0 10px">Komposisi Bulan Terpilih</h3>
      <div style="margin-bottom:10px"><b>Berdasarkan Jenis Asesmen</b><br>
        <?php if (!$by_jenis): ?><span class="muted">Belum ada data.</span><?php endif; ?>
        <?php foreach ($by_jenis as $label=>$count): ?><span class="badge"><?=rj_h($label)?>: <b><?=number_format($count,0,',','.')?></b></span><?php endforeach; ?>
      </div>
      <div><b>Berdasarkan Pelayanan</b><br>
        <?php if (!$by_layanan): ?><span class="muted">Belum ada data.</span><?php endif; ?>
        <?php foreach ($by_layanan as $label=>$count): ?><span class="badge"><?=rj_h($label)?>: <b><?=number_format($count,0,',','.')?></b></span><?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px">Daftar Pasien Hasil Skrining Risiko Tinggi</h3>
    <div class="table-scroll"><table>
      <tr><th>No.</th><th>Tanggal Skrining</th><th>Pasien</th><th>No. RM / No. Rawat</th><th>Pelayanan</th><th>Ruang/Poli</th><th>Jenis Asesmen</th><th>Skor</th><th>Hasil Skrining</th><th>Status Intervensi</th><th>Aksi</th></tr>
      <?php if ($rows): $no=1; foreach ($rows as $row): ?>
      <tr>
        <td><?=($no++)?></td>
        <td><?=rj_display_datetime($row['tanggal_skrining'])?></td>
        <td><b><?=rj_h($row['nm_pasien'])?></b><br><span class="muted"><?=rj_h($row['nm_dokter'] ? $row['nm_dokter'] : '-')?></span></td>
        <td><?=rj_h($row['no_rkm_medis'])?><br><span class="muted"><?=rj_h($row['no_rawat'])?></span></td>
        <td><?=rj_h($row['status_lanjut'])?></td>
        <td><?=rj_h($row['ruang_rawat'])?></td>
        <td><?=rj_h($row['jenis_asesmen'])?></td>
        <td><?=rj_h($row['skor'] !== '' ? $row['skor'] : '-')?></td>
        <td><b><?=rj_h($row['hasil_skrining'])?></b><?php if ($row['saran']): ?><br><span class="muted"><?=rj_h($row['saran'])?></span><?php endif; ?></td>
        <td>
          <?php if ((int)$row['total_intervensi'] > 0): ?>
            <span class="status-done">Sudah</span><br><span class="muted"><?=rj_h($row['no_form_intervensi'])?></span><br><span class="muted"><?=rj_display_datetime($row['tanggal_intervensi'])?></span>
          <?php else: ?>
            <span class="status-pending">Belum</span>
          <?php endif; ?>
        </td>
        <td class="actions">
          <?php if ((int)$row['total_intervensi'] > 0 && (int)$row['id_intervensi'] > 0): ?>
            <a class="btn" target="_blank" href="<?=rj_h(rj_dashboard_intervensi_route($row['status_lanjut'], true, (int)$row['id_intervensi'], $row['no_rawat'], true))?>">Cetak</a>
            <a class="btn gray" href="<?=rj_h(rj_dashboard_intervensi_route($row['status_lanjut'], true, (int)$row['id_intervensi'], $row['no_rawat'], false))?>">Edit</a>
          <?php else: ?>
            <a class="btn orange" href="<?=rj_h(rj_dashboard_intervensi_route($row['status_lanjut'], false, 0, $row['no_rawat'], false))?>">Buat Intervensi</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; else: ?>
      <tr><td colspan="11" style="text-align:center;padding:25px">Belum ada pasien dengan hasil_skrining risiko tinggi pada periode ini.</td></tr>
      <?php endif; ?>
    </table></div>
  </div>
</main></body></html><?php
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
$pegawai_error = '';
$result_petugas = rj_db_query("SELECT `".$pegawai_kode_column."` AS npk, nama FROM pegawai WHERE `".$pegawai_kode_column."` IS NOT NULL AND `".$pegawai_kode_column."` <> '' ORDER BY nama ASC", $pegawai_error);
if ($result_petugas) {
    while ($p = mysqli_fetch_assoc($result_petugas)) $pegawai[] = $p;
}


/* =========================
   Rawat Jalan: Daftar dan Form
   ========================= */
if ($mode === 'daftar_rajal') {
    $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
    $tanggal_input = isset($_GET['tanggal']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');
    $tanggal_expr = rj_column_exists('intervensi_risiko_jatuh_rajal', 'created_at') ? "DATE(COALESCE(irj.created_at, irj.tanggal_form))" : "DATE(irj.tanggal_form)";
    $tanggal_select = rj_column_exists('intervensi_risiko_jatuh_rajal', 'created_at') ? "COALESCE(irj.created_at, irj.tanggal_form) AS tanggal_input" : "irj.tanggal_form AS tanggal_input";
    $where = array($tanggal_expr . "='" . rj_sql($tanggal_input) . "'");
    if ($keyword !== '') {
        $like = rj_sql($keyword);
        $where[] = "(irj.no_form LIKE '%$like%' OR irj.no_rawat LIKE '%$like%' OR p.no_rkm_medis LIKE '%$like%' OR p.nm_pasien LIKE '%$like%' OR pl.nm_poli LIKE '%$like%')";
    }
    $filter = ' WHERE ' . implode(' AND ', $where);
    $sql = "SELECT irj.id, irj.no_form, irj.no_rawat, irj.tanggal_form, irj.petugas_nama, irj.penerima_nama, $tanggal_select,
                   p.no_rkm_medis, p.nm_pasien, rp.status_lanjut, pl.nm_poli
            FROM intervensi_risiko_jatuh_rajal irj
            LEFT JOIN reg_periksa rp ON irj.no_rawat=rp.no_rawat
            LEFT JOIN pasien p ON rp.no_rkm_medis=p.no_rkm_medis
            LEFT JOIN poliklinik pl ON rp.kd_poli=pl.kd_poli
            $filter
            ORDER BY tanggal_input DESC, irj.id DESC";
    $list_error = '';
    $result = rj_db_query($sql, $list_error);
    ?><!doctype html><html lang="id"><head><meta charset="utf-8"><title>Daftar Intervensi Risiko Jatuh Rawat Jalan</title>
<style>body{margin:0;background:#f3f6f9;font:14px Arial,sans-serif;color:#17212b}.wrap{max-width:1380px;margin:auto;padding:22px}.top{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px}.brand{font-size:20px;font-weight:700}.nav,.actions{display:flex;gap:7px;flex-wrap:wrap}.btn{display:inline-block;border:0;border-radius:5px;padding:9px 12px;color:white;background:#1769aa;text-decoration:none;cursor:pointer}.btn.gray{background:#5e6974}.btn.green{background:#177d45}.btn.red{background:#b83232}.btn.orange{background:#d97706}.card{background:white;border-radius:8px;box-shadow:0 1px 4px #ced6de;padding:16px}.notice{padding:10px;border-radius:5px;background:#d9f7e5;border:1px solid #92d9ae;margin-bottom:11px}.notice.err{background:#fdecec;border-color:#ef9a9a;color:#8b2424}.search{display:flex;gap:7px;margin-bottom:12px;align-items:end;flex-wrap:wrap}.search .field{display:flex;flex-direction:column;gap:4px}.search label{font-size:12px;color:#52616d;font-weight:700}.search input{padding:9px;border:1px solid #b8c2cd;border-radius:5px;min-width:230px}.summary{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}.badge{display:inline-block;background:#eef5fb;border:1px solid #cfe1ef;border-radius:5px;padding:7px 9px;color:#344d60}table{border-collapse:collapse;width:100%;font-size:13px}th,td{padding:10px 8px;border-bottom:1px solid #e1e6eb;text-align:left;vertical-align:top}th{background:#eef3f7;white-space:nowrap}.muted{color:#647180}@media(max-width:760px){.top{align-items:flex-start;flex-direction:column}.search{align-items:stretch;flex-direction:column}.search input{min-width:0;width:100%}.table-scroll{overflow:auto}.actions .btn{padding:7px 9px}}</style>
</head><body><main class="wrap"><div class="top"><div><div class="brand">Daftar Intervensi Risiko Jatuh Rawat Jalan</div><div class="muted">Ditampilkan berdasarkan tanggal input per hari</div></div><div class="nav"><a class="btn orange" href="<?=rj_h(rj_url(array('mode'=>'risiko_tinggi')))?>">Dashboard Risiko Jatuh</a><a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'daftar')))?>">Daftar Rawat Inap</a><a class="btn green" href="<?=rj_h(rj_url(array('mode'=>'form_rajal')))?>">+ Form Rawat Jalan</a></div></div><div class="card">
<?php if (isset($_GET['info']) && $_GET['info']==='simpan'): ?><div class="notice">Data intervensi risiko jatuh rawat jalan berhasil disimpan.</div><?php endif; ?>
<?php if (isset($_GET['info']) && $_GET['info']==='hapus'): ?><div class="notice">Data intervensi risiko jatuh rawat jalan berhasil dihapus.</div><?php endif; ?>
<?php if ($list_error !== ''): ?><div class="notice err"><b>Data belum dapat dimuat.</b><br><?=rj_h($list_error)?></div><?php endif; ?>
<form class="search" method="get"><input type="hidden" name="mode" value="daftar_rajal"><div class="field"><label>Tanggal Input</label><input type="date" name="tanggal" value="<?=rj_h($tanggal_input)?>"></div><div class="field"><label>Pencarian</label><input type="text" name="keyword" value="<?=rj_h($keyword)?>" placeholder="Cari no form, no rawat, No. RM, nama pasien"></div><button class="btn" type="submit">Tampilkan</button><a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'daftar_rajal')))?>">Hari Ini</a></form>
<div class="summary"><span class="badge">Tanggal: <b><?=date('d-m-Y', strtotime($tanggal_input))?></b></span><?php if ($keyword !== ''): ?><span class="badge">Kata kunci: <b><?=rj_h($keyword)?></b></span><?php endif; ?></div>
<div class="table-scroll"><table><tr><th>No.</th><th>No. Form</th><th>Tanggal Input</th><th>Tanggal Asesmen</th><th>Pasien</th><th>No. RM / No. Rawat</th><th>Poli</th><th>Petugas</th><th>Aksi</th></tr>
<?php $no=1; if ($result && mysqli_num_rows($result)>0): while ($row=mysqli_fetch_assoc($result)): ?>
<tr><td><?=($no++)?></td><td><b><?=rj_h($row['no_form'])?></b></td><td><?=rj_display_datetime($row['tanggal_input'])?></td><td><?=rj_display_datetime($row['tanggal_form'])?></td><td><?=rj_h($row['nm_pasien'] ? $row['nm_pasien'] : '-')?></td><td><?=rj_h($row['no_rkm_medis'] ? $row['no_rkm_medis'] : '-')?><br><span class="muted"><?=rj_h($row['no_rawat'])?></span></td><td><?=rj_h($row['nm_poli'] ? $row['nm_poli'] : 'Rawat Jalan')?></td><td><?=rj_h($row['petugas_nama'])?></td><td class="actions"><a class="btn" href="<?=rj_h(rj_url(array('mode'=>'cetak_rajal','id'=>$row['id'])))?>" target="_blank">Cetak</a><a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'edit_rajal','id'=>$row['id'])))?>">Edit</a><a class="btn red" href="<?=rj_h(rj_url(array('mode'=>'hapus_rajal','id'=>$row['id'], 'tanggal'=>$tanggal_input, 'keyword'=>$keyword)))?>" onclick="return confirm('Hapus formulir <?=rj_h($row['no_form'])?>?')">Hapus</a></td></tr>
<?php endwhile; else: ?><tr><td colspan="9" style="text-align:center;padding:25px">Belum ada input intervensi risiko jatuh rawat jalan pada tanggal ini.</td></tr><?php endif; ?>
</table></div></div></main></body></html><?php
    exit;
}

if ($mode === 'form_rajal' || $mode === 'edit_rajal') {
    $edit_rajal = array();
    if ($mode === 'edit_rajal' && isset($_GET['id'])) {
        $edit_rajal = rj_query_one("SELECT * FROM intervensi_risiko_jatuh_rajal WHERE id='".(int)$_GET['id']."' LIMIT 1");
        if (!$edit_rajal) { header('Location: '.rj_url(array('mode'=>'daftar_rajal'))); exit; }
    }
    $payload = array();
    if ($edit_rajal) {
        $payload = array(
            'id'=>(int)$edit_rajal['id'], 'no_form'=>$edit_rajal['no_form'], 'no_rawat'=>$edit_rajal['no_rawat'],
            'identity'=>rj_decode($edit_rajal['identitas_json']), 'screening'=>rj_decode($edit_rajal['skrining_json']),
            'interventions'=>rj_decode($edit_rajal['intervensi_json']), 'evaluasi'=>rj_decode($edit_rajal['evaluasi_json']),
            'edukasi'=>rj_decode($edit_rajal['edukasi_json']), 'petugas_npk'=>$edit_rajal['petugas_npk'],
            'petugas_nama'=>$edit_rajal['petugas_nama'], 'petugas_jabatan'=>$edit_rajal['petugas_jabatan'],
            'penerima_nama'=>$edit_rajal['penerima_nama'], 'hubungan_penerima'=>$edit_rajal['hubungan_penerima']
        );
    }
    $items = rj_rajal_items();
    $eval_labels = rj_rajal_eval_labels();
    $pre_rawat = isset($_GET['no_rawat']) ? trim($_GET['no_rawat']) : '';
    ?><!doctype html><html lang="id"><head><meta charset="utf-8"><title><?= $edit_rajal ? 'Edit' : 'Form' ?> Intervensi Risiko Jatuh Rawat Jalan</title>
<style>:root{--blue:#1368a8;--dark:#123149;--green:#168044;--red:#b92e2e;--border:#cbd5df;--soft:#eef4f8}*{box-sizing:border-box}body{margin:0;background:#f1f5f8;font:14px Arial,Helvetica,sans-serif;color:#17212b}.wrap{max-width:1420px;margin:auto;padding:20px}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:10px}.brand{font-size:21px;font-weight:700;color:var(--dark)}.sub{color:#647180;margin-top:3px}.nav{display:flex;gap:7px;flex-wrap:wrap}.btn{border:0;border-radius:5px;padding:9px 13px;background:var(--blue);color:#fff;text-decoration:none;cursor:pointer;font-size:14px}.btn.gray{background:#64717d}.btn.green{background:var(--green)}.btn.red{background:var(--red)}.btn.orange{background:#d97706}.btn.small{padding:6px 9px;font-size:12px}.card{background:#fff;border-radius:8px;box-shadow:0 1px 4px #cfd8e0;margin-bottom:14px;overflow:hidden}.card-title{padding:10px 14px;background:var(--dark);color:#fff;font-weight:700;font-size:15px}.card-body{padding:14px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px}.grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}.field label{display:block;font-weight:700;margin-bottom:4px}.field input,.field select,.field textarea{width:100%;border:1px solid var(--border);border-radius:4px;padding:8px;background:#fff;font:inherit}.field input[readonly],.readonly{background:#f1f4f7}.field textarea{min-height:68px;resize:vertical}.full{grid-column:1/-1}.search-row{display:flex;gap:8px;align-items:end}.search-row .field{flex:1}.notice{padding:10px;border:1px solid #e2c46c;background:#fff7d5;color:#6a5415;border-radius:5px;margin-bottom:10px}.status{padding:9px;border-radius:5px;margin-top:10px;display:none}.status.ok{display:block;background:#daf5e4;color:#155f36;border:1px solid #97d6ad}.status.err{display:block;background:#fde1e1;color:#8c2525;border:1px solid #f3b2b2}.section-note{font-size:12px;color:#56636f;margin:0 0 10px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:13px}th,td{border:1px solid var(--border);padding:7px;vertical-align:middle}th{background:var(--soft);text-align:center}td input,td select{padding:6px!important;font-size:12px!important}.footer-actions{display:flex;justify-content:flex-end;gap:8px;padding:14px;position:sticky;bottom:0;background:#fff;border-top:1px solid var(--border)}.required{color:#b12323}.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}.tabs a{padding:9px 12px;border-radius:6px;background:#e8eef4;text-decoration:none;color:#25313b}.tabs a.active{background:#1368a8;color:white}.status-yes{background:#e8f8ee}.status-no{background:#fff3f3}@media(max-width:900px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.top{align-items:flex-start;flex-direction:column}}@media(max-width:560px){.wrap{padding:10px}.grid,.grid.two{grid-template-columns:1fr}.search-row{align-items:stretch;flex-direction:column}.footer-actions{position:static}.intervention-table{min-width:900px}}</style>
</head><body><main class="wrap"><div class="top"><div><div class="brand"><?= $edit_rajal ? 'Edit' : 'Form' ?> Intervensi Risiko Jatuh Pasien Rawat Jalan</div><div class="sub">Form khusus pasien rawat jalan, terpisah dari intervensi rawat inap</div></div><div class="nav"><a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'daftar_rajal')))?>">Daftar Rawat Jalan</a><a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'daftar')))?>">Daftar Rawat Inap</a><a class="btn orange" href="<?=rj_h(rj_url(array('mode'=>'risiko_tinggi')))?>">Dashboard Risiko</a></div></div>
<div class="tabs"><a class="active" href="<?=rj_h(rj_url(array('mode'=>'form_rajal')))?>">Input Intervensi Rawat Jalan</a><a href="<?=rj_h(rj_url(array('mode'=>'form')))?>">Input Intervensi Rawat Inap</a></div>
<?php if (isset($_GET['info']) && $_GET['info']==='wajib'): ?><div class="notice">Nomor rawat, identitas pasien, risiko jatuh, dan petugas pelaksana wajib dilengkapi.</div><?php endif; ?>
<?php if (isset($_GET['info']) && $_GET['info']==='db'): ?><div class="notice" style="border-color:#ef9a9a;background:#fff0f0;color:#8b2424"><b>Form belum tersimpan.</b><br><?=rj_h(isset($_GET['dbmsg']) ? $_GET['dbmsg'] : 'Periksa struktur tabel dan koneksi database.')?></div><?php endif; ?>
<form id="form-rajal" method="post" action="<?=rj_h(rj_url())?>"><input type="hidden" name="aksi" value="simpan_rajal"><input type="hidden" name="id" id="id" value="<?=rj_h($edit_rajal ? $edit_rajal['id'] : '')?>"><input type="hidden" name="no_form" id="no_form" value="<?=rj_h($edit_rajal ? $edit_rajal['no_form'] : '')?>"><input type="hidden" name="no_rawat" id="no_rawat"><input type="hidden" name="identitas_json" id="identitas_json"><input type="hidden" name="skrining_json" id="skrining_json"><input type="hidden" name="intervensi_json" id="intervensi_json"><input type="hidden" name="evaluasi_json" id="evaluasi_json"><input type="hidden" name="edukasi_json" id="edukasi_json"><input type="hidden" name="petugas_npk" id="petugas_npk"><input type="hidden" name="petugas_nama" id="petugas_nama">
<section class="card"><div class="card-title">I. IDENTITAS PASIEN</div><div class="card-body"><div class="search-row"><div class="field"><label>Nomor Rawat <span class="required">*</span></label><input id="cari_no_rawat" autocomplete="off" placeholder="Contoh: 2026/07/22/000001"></div><button type="button" class="btn" id="btn-cari">Cari Data Pasien</button></div><div id="pesan-cari" class="status"></div><div class="grid" style="margin-top:12px"><div class="field"><label>Nama Pasien</label><input id="nm_pasien" readonly></div><div class="field"><label>No. Rekam Medis</label><input id="no_rkm_medis" readonly></div><div class="field"><label>Jenis Pelayanan</label><input id="status_lanjut" readonly></div><div class="field"><label>Tanggal/Jam</label><input id="tgl_masuk" readonly></div><div class="field"><label>Poli</label><input id="ruang_rawat" readonly></div><div class="field"><label>DPJP</label><input id="nm_dokter" readonly></div><div class="field full"><label>Diagnosa</label><input id="diagnosa" readonly></div></div></div></section>
<section class="card"><div class="card-title">II. HASIL SKRINING RISIKO JATUH</div><div class="card-body"><div class="grid"><div class="field"><label>Jenis Asesmen</label><input id="skrining_jenis" placeholder="Contoh: Morse Fall Scale / Humpty Dumpty"></div><div class="field"><label>Skor</label><input id="skrining_skor" type="number" min="0"></div><div class="field"><label>Hasil Skrining <span class="required">*</span></label><select id="skrining_risiko"><option value="">-- Pilih --</option><option>Rendah</option><option>Tinggi</option></select></div><div class="field"><label>Tanggal/Jam</label><input id="skrining_tanggal" type="datetime-local" value="<?=rj_h(rj_now_local())?>"></div></div></div></section>
<section class="card"><div class="card-title">III. INTERVENSI PENCEGAHAN RISIKO JATUH</div><div class="card-body"><p class="section-note">Pilih Ya/Tidak dan isi keterangan bila diperlukan.</p><div class="table-wrap"><table class="intervention-table"><thead><tr><th style="width:45px">No</th><th>Intervensi</th><th style="width:105px">Ya/Tidak</th><th style="width:260px">Keterangan</th></tr></thead><tbody id="intervensi-body"></tbody></table></div></div></section>
<section class="card"><div class="card-title">IV. EVALUASI</div><div class="card-body"><div class="table-wrap"><table><thead><tr><th>Evaluasi</th><th style="width:95px">Ya</th></tr></thead><tbody id="evaluasi-body"></tbody></table></div><div class="grid two" style="margin-top:12px"><div class="field"><label>Bila terjadi insiden, uraian singkat</label><textarea id="evaluasi_uraian"></textarea></div><div class="field"><label>Tindak lanjut</label><textarea id="evaluasi_tindak_lanjut"></textarea></div></div></div></section>
<section class="card"><div class="card-title">V. EDUKASI DITERIMA OLEH</div><div class="card-body"><div class="grid two"><div class="field"><label>Nama Pasien/Keluarga</label><input name="penerima_nama" id="penerima_nama"></div><div class="field"><label>Hubungan</label><input name="hubungan_penerima" id="hubungan_penerima" placeholder="Pasien / Suami / Istri / Anak / Keluarga"></div></div></div></section>
<section class="card"><div class="card-title">VI. PETUGAS PELAKSANA</div><div class="card-body"><div class="grid two"><div class="field"><label>Nama Petugas <span class="required">*</span></label><select id="select_petugas"><option value="">-- Pilih Petugas --</option><?php foreach($pegawai as $p): ?><option value="<?=rj_h($p['npk'])?>" data-nama="<?=rj_h($p['nama'])?>"><?=rj_h($p['npk'].' - '.$p['nama'])?></option><?php endforeach; ?></select></div><div class="field"><label>Jabatan</label><input name="petugas_jabatan" id="petugas_jabatan" value="Petugas Pelaksana Rawat Jalan"></div><div class="field"><label>Preview QR Code</label><input class="readonly" readonly value="QR Code akan terbentuk pada dokumen cetak."></div><div class="field"><label>NPK Petugas</label><input id="preview_npk" readonly></div></div></div><div class="footer-actions"><a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'daftar_rajal')))?>">Batal</a><button class="btn green" type="submit">Simpan Formulir</button></div></section></form></main>
<script>(function(){'use strict';var ajaxUrl=<?=json_encode(rj_url(array('mode'=>'ajax_pasien')))?>;var existing=<?=json_encode($payload, JSON_UNESCAPED_UNICODE)?>||{};var preRawat=<?=json_encode($pre_rawat)?>;var items=<?=json_encode($items, JSON_UNESCAPED_UNICODE)?>;var evalLabels=<?=json_encode($eval_labels, JSON_UNESCAPED_UNICODE)?>;function el(id){return document.getElementById(id);}function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];});}function statusMessage(type,msg){var p=el('pesan-cari');p.className='status '+type;p.textContent=msg;}function selectedName(select){if(!select||select.selectedIndex<0)return '';return select.options[select.selectedIndex].getAttribute('data-nama')||'';}function loadIdentity(identity){identity=identity||{};['no_rawat','no_rkm_medis','nm_pasien','status_lanjut','tgl_masuk','ruang_rawat','nm_dokter','diagnosa'].forEach(function(k){var t=el(k);if(t)t.value=identity[k]||'';});el('cari_no_rawat').value=identity.no_rawat||'';if((identity.status_lanjut||'').toLowerCase()==='ranap'){statusMessage('err','Nomor rawat ini terdaftar sebagai Ranap. Gunakan tab Input Intervensi Rawat Inap.');}else if(identity.no_rawat){statusMessage('ok','Data pasien rawat jalan ditemukan.');}}function collectIdentity(){return{no_rawat:el('no_rawat').value,no_rkm_medis:el('no_rkm_medis').value,nm_pasien:el('nm_pasien').value,status_lanjut:el('status_lanjut').value,tgl_masuk:el('tgl_masuk').value,ruang_rawat:el('ruang_rawat').value,nm_dokter:el('nm_dokter').value,diagnosa:el('diagnosa').value};}function renderIntervensi(saved){saved=saved||[];var html='';items.forEach(function(label,i){var r=saved[i]||{};var j=r.jawaban||'';html+='<tr class="'+(j==='Ya'?'status-yes':j==='Tidak'?'status-no':'')+'"><td style="text-align:center">'+(i+1)+'</td><td>'+esc(label)+'</td><td><select class="jawaban"><option value="">-- Pilih --</option><option '+(j==='Ya'?'selected':'')+'>Ya</option><option '+(j==='Tidak'?'selected':'')+'>Tidak</option></select></td><td><input class="ket" value="'+esc(r.keterangan||'')+'"></td></tr>';});el('intervensi-body').innerHTML=html;document.querySelectorAll('.jawaban').forEach(function(s){s.addEventListener('change',function(){var tr=s.closest('tr');tr.classList.remove('status-yes','status-no');if(s.value==='Ya')tr.classList.add('status-yes');if(s.value==='Tidak')tr.classList.add('status-no');});});}function renderEvaluasi(saved){saved=saved||{};var pilihan=saved.pilihan||[];var html='';evalLabels.forEach(function(label){html+='<tr><td>'+esc(label)+'</td><td style="text-align:center"><input type="checkbox" class="eval-check" value="'+esc(label)+'" '+(pilihan.indexOf(label)>=0?'checked':'')+'></td></tr>';});el('evaluasi-body').innerHTML=html;el('evaluasi_uraian').value=saved.uraian||'';el('evaluasi_tindak_lanjut').value=saved.tindak_lanjut||'';}function collectIntervensi(){return Array.from(document.querySelectorAll('#intervensi-body tr')).map(function(tr,i){return{no:i+1,intervensi:items[i],jawaban:tr.querySelector('.jawaban').value,keterangan:tr.querySelector('.ket').value};});}function collectEvaluasi(){return{pilihan:Array.from(document.querySelectorAll('.eval-check:checked')).map(function(c){return c.value;}),uraian:el('evaluasi_uraian').value,tindak_lanjut:el('evaluasi_tindak_lanjut').value};}function pilihPetugas(){var s=el('select_petugas');el('preview_npk').value=s.value||'';}el('btn-cari').addEventListener('click',function(){var rawat=el('cari_no_rawat').value.trim();if(!rawat){statusMessage('err','Masukkan nomor rawat terlebih dahulu.');return;}var b=this;b.disabled=true;b.textContent='Mencari...';fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'no_rawat='+encodeURIComponent(rawat)}).then(function(r){return r.json();}).then(function(res){if(res.status==='sukses')loadIdentity(res.data);else statusMessage('err',res.pesan||'Data pasien tidak ditemukan.');}).catch(function(){statusMessage('err','Gagal terhubung ke server saat mencari data pasien.');}).finally(function(){b.disabled=false;b.textContent='Cari Data Pasien';});});el('cari_no_rawat').addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();el('btn-cari').click();}});el('select_petugas').addEventListener('change',pilihPetugas);el('form-rajal').addEventListener('submit',function(e){var ident=collectIdentity(),pet=el('select_petugas');if(!ident.no_rawat||!ident.nm_pasien){e.preventDefault();alert('Cari dan pastikan data pasien terlebih dahulu.');return;}if((ident.status_lanjut||'').toLowerCase()==='ranap'){e.preventDefault();alert('Pasien ini Ranap. Gunakan Input Intervensi Rawat Inap.');return;}if(!el('skrining_risiko').value){e.preventDefault();alert('Pilih hasil skrining risiko jatuh.');return;}if(!pet.value){e.preventDefault();alert('Pilih Petugas Pelaksana.');return;}el('identitas_json').value=JSON.stringify(ident);el('skrining_json').value=JSON.stringify({jenis:el('skrining_jenis').value,skor:el('skrining_skor').value,risiko:el('skrining_risiko').value,tanggal:el('skrining_tanggal').value});el('intervensi_json').value=JSON.stringify(collectIntervensi());el('evaluasi_json').value=JSON.stringify(collectEvaluasi());el('edukasi_json').value=JSON.stringify({penerima_nama:el('penerima_nama').value,hubungan_penerima:el('hubungan_penerima').value});el('petugas_npk').value=pet.value;el('petugas_nama').value=selectedName(pet);});renderIntervensi(existing.interventions||[]);renderEvaluasi(existing.evaluasi||{});if(existing.id){loadIdentity(existing.identity);el('skrining_jenis').value=(existing.screening||{}).jenis||'';el('skrining_skor').value=(existing.screening||{}).skor||'';el('skrining_risiko').value=(existing.screening||{}).risiko||'';el('skrining_tanggal').value=(existing.screening||{}).tanggal||'';el('penerima_nama').value=existing.penerima_nama||'';el('hubungan_penerima').value=existing.hubungan_penerima||'';el('petugas_jabatan').value=existing.petugas_jabatan||'Petugas Pelaksana Rawat Jalan';el('select_petugas').value=existing.petugas_npk||'';pilihPetugas();}else if(preRawat){el('cari_no_rawat').value=preRawat;setTimeout(function(){el('btn-cari').click();},100);}})();</script>
</body></html><?php
    exit;
}

/* =========================
   Daftar data - default per hari
   ========================= */
if ($mode === 'daftar') {
    $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
    $tanggal_input = isset($_GET['tanggal']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');

    // Utamakan created_at sebagai tanggal input. Bila tabel lama belum punya created_at, gunakan tanggal_form.
    $tanggal_expr = rj_column_exists('intervensi_risiko_jatuh', 'created_at') ? "DATE(COALESCE(irj.created_at, irj.tanggal_form))" : "DATE(irj.tanggal_form)";
    $tanggal_select = rj_column_exists('intervensi_risiko_jatuh', 'created_at') ? "COALESCE(irj.created_at, irj.tanggal_form) AS tanggal_input" : "irj.tanggal_form AS tanggal_input";

    $where = array();
    $where[] = $tanggal_expr . "='" . rj_sql($tanggal_input) . "'";
    if ($keyword !== '') {
        $like = rj_sql($keyword);
        $where[] = "(irj.no_form LIKE '%$like%' OR irj.no_rawat LIKE '%$like%' OR p.no_rkm_medis LIKE '%$like%' OR p.nm_pasien LIKE '%$like%' OR rp.status_lanjut LIKE '%$like%')";
    }
    $filter = ' WHERE ' . implode(' AND ', $where);

    $sql = "SELECT irj.id, irj.no_form, irj.no_rawat, irj.tanggal_form, irj.perawat_nama, $tanggal_select,
                   p.no_rkm_medis, p.nm_pasien, rp.status_lanjut
            FROM intervensi_risiko_jatuh irj
            LEFT JOIN reg_periksa rp ON irj.no_rawat=rp.no_rawat
            LEFT JOIN pasien p ON rp.no_rkm_medis=p.no_rkm_medis
            $filter
            ORDER BY tanggal_input DESC, irj.id DESC";
    $list_error = '';
    $result = rj_db_query($sql, $list_error);
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><title>Daftar Intervensi Risiko Jatuh</title>
<style>
body{margin:0;background:#f3f6f9;font:14px Arial,sans-serif;color:#17212b}.wrap{max-width:1380px;margin:auto;padding:22px}.top{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px}.brand{font-size:20px;font-weight:700}.nav,.actions{display:flex;gap:7px;flex-wrap:wrap}.btn{display:inline-block;border:0;border-radius:5px;padding:9px 12px;color:white;background:#1769aa;text-decoration:none;cursor:pointer}.btn.gray{background:#5e6974}.btn.green{background:#177d45}.btn.red{background:#b83232}.btn.orange{background:#d97706}.card{background:white;border-radius:8px;box-shadow:0 1px 4px #ced6de;padding:16px}.notice{padding:10px;border-radius:5px;background:#d9f7e5;border:1px solid #92d9ae;margin-bottom:11px}.notice.err{background:#fdecec;border-color:#ef9a9a;color:#8b2424}.search{display:flex;gap:7px;margin-bottom:12px;align-items:end;flex-wrap:wrap}.search .field{display:flex;flex-direction:column;gap:4px}.search label{font-size:12px;color:#52616d;font-weight:700}.search input{padding:9px;border:1px solid #b8c2cd;border-radius:5px;min-width:230px}.summary{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}.badge{display:inline-block;background:#eef5fb;border:1px solid #cfe1ef;border-radius:5px;padding:7px 9px;color:#344d60}table{border-collapse:collapse;width:100%;font-size:13px}th,td{padding:10px 8px;border-bottom:1px solid #e1e6eb;text-align:left;vertical-align:top}th{background:#eef3f7;white-space:nowrap}.muted{color:#647180}@media(max-width:760px){.top{align-items:flex-start;flex-direction:column}.search{align-items:stretch;flex-direction:column}.search input{min-width:0;width:100%}.table-scroll{overflow:auto}.actions .btn{padding:7px 9px}}
</style>
</head>
<body><main class="wrap">
  <div class="top"><div><div class="brand">Daftar Intervensi Pencegahan Risiko Jatuh</div><div class="muted">Ditampilkan berdasarkan tanggal input per hari</div></div><div class="nav"><a class="btn orange" href="<?=rj_h(rj_url(array('mode'=>'risiko_tinggi')))?>">Daftar Risiko Jatuh</a><a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'daftar_rajal')))?>">Daftar Rawat Jalan</a><a class="btn green" href="<?=rj_h(rj_url(array('mode'=>'form')))?>">+ Intervensi Inap</a><a class="btn green" href="<?=rj_h(rj_url(array('mode'=>'form_rajal')))?>">+ Intervensi Jalan</a></div></div>
  <div class="card">
  <?php if (isset($_GET['info']) && $_GET['info']==='simpan'): ?><div class="notice">Data intervensi risiko jatuh berhasil disimpan.</div><?php endif; ?>
  <?php if (isset($_GET['info']) && $_GET['info']==='hapus'): ?><div class="notice">Data intervensi risiko jatuh berhasil dihapus.</div><?php endif; ?>
  <?php if ($list_error !== ''): ?><div class="notice err"><b>Data belum dapat dimuat.</b><br><?=rj_h($list_error)?></div><?php endif; ?>

  <form class="search" method="get">
    <input type="hidden" name="mode" value="daftar">
    <div class="field"><label>Tanggal Input</label><input type="date" name="tanggal" value="<?=rj_h($tanggal_input)?>"></div>
    <div class="field"><label>Pencarian</label><input type="text" name="keyword" value="<?=rj_h($keyword)?>" placeholder="Cari no form, no rawat, No. RM, nama pasien"></div>
    <button class="btn" type="submit">Tampilkan</button>
    <a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'daftar')))?>">Hari Ini</a>
  </form>

  <div class="summary">
    <span class="badge">Tanggal: <b><?=date('d-m-Y', strtotime($tanggal_input))?></b></span>
    <?php if ($keyword !== ''): ?><span class="badge">Kata kunci: <b><?=rj_h($keyword)?></b></span><?php endif; ?>
  </div>

  <div class="table-scroll"><table>
    <tr><th>No.</th><th>No. Form</th><th>Tanggal Input</th><th>Tanggal Asesmen</th><th>Pasien</th><th>No. RM / No. Rawat</th><th>Pelayanan</th><th>Perawat</th><th>Aksi</th></tr>
    <?php $no=1; if ($result && mysqli_num_rows($result)>0): while ($row=mysqli_fetch_assoc($result)): ?>
    <tr>
      <td><?=($no++)?></td><td><b><?=rj_h($row['no_form'])?></b></td><td><?=rj_display_datetime($row['tanggal_input'])?></td><td><?=rj_display_datetime($row['tanggal_form'])?></td>
      <td><?=rj_h($row['nm_pasien'] ? $row['nm_pasien'] : '-')?></td><td><?=rj_h($row['no_rkm_medis'] ? $row['no_rkm_medis'] : '-')?><br><span class="muted"><?=rj_h($row['no_rawat'])?></span></td>
      <td><?=rj_h($row['status_lanjut'] ? $row['status_lanjut'] : '-')?></td><td><?=rj_h($row['perawat_nama'])?></td>
      <td class="actions">
        <a class="btn" href="<?=rj_h(rj_url(array('mode'=>'cetak','id'=>$row['id'])))?>" target="_blank">Cetak</a>
        <a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'edit','id'=>$row['id'])))?>">Edit</a>
        <a class="btn red" href="<?=rj_h(rj_url(array('mode'=>'hapus','id'=>$row['id'], 'tanggal'=>$tanggal_input, 'keyword'=>$keyword)))?>" onclick="return confirm('Hapus formulir <?=rj_h($row['no_form'])?>?')">Hapus</a>
      </td>
    </tr>
    <?php endwhile; else: ?><tr><td colspan="9" style="text-align:center;padding:25px">Belum ada input intervensi risiko jatuh pada tanggal ini.</td></tr><?php endif; ?>
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
        'perawat_npk'=>isset($edit['perawat_npk']) ? $edit['perawat_npk'] : (isset($edit['perawat_nik']) ? $edit['perawat_nik'] : ''),
        'karu_npk'=>isset($edit['karu_npk']) ? $edit['karu_npk'] : (isset($edit['karu_nik']) ? $edit['karu_nik'] : '')
    );
}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title><?= $edit ? 'Edit' : 'Form' ?> Intervensi Risiko Jatuh</title>
<style>
:root{--blue:#1368a8;--dark:#123149;--green:#168044;--red:#b92e2e;--border:#cbd5df;--soft:#eef4f8}*{box-sizing:border-box}body{margin:0;background:#f1f5f8;font:14px Arial,Helvetica,sans-serif;color:#17212b}.wrap{max-width:1420px;margin:auto;padding:20px}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:10px}.brand{font-size:21px;font-weight:700;color:var(--dark)}.sub{color:#647180;margin-top:3px}.nav{display:flex;gap:7px}.btn{border:0;border-radius:5px;padding:9px 13px;background:var(--blue);color:#fff;text-decoration:none;cursor:pointer;font-size:14px}.btn.gray{background:#64717d}.btn.green{background:var(--green)}.btn.red{background:var(--red)}.btn.orange{background:#d97706}.btn.small{padding:6px 9px;font-size:12px}.card{background:#fff;border-radius:8px;box-shadow:0 1px 4px #cfd8e0;margin-bottom:14px;overflow:hidden}.card-title{padding:10px 14px;background:var(--dark);color:#fff;font-weight:700;font-size:15px}.card-body{padding:14px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px}.grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}.field label{display:block;font-weight:700;margin-bottom:4px}.field input,.field select,.field textarea{width:100%;border:1px solid var(--border);border-radius:4px;padding:8px;background:#fff;font:inherit}.field input[readonly],.readonly{background:#f1f4f7}.field textarea{min-height:68px;resize:vertical}.full{grid-column:1/-1}.search-row{display:flex;gap:8px;align-items:end}.search-row .field{flex:1}.notice{padding:10px;border:1px solid #e2c46c;background:#fff7d5;color:#6a5415;border-radius:5px;margin-bottom:10px}.status{padding:9px;border-radius:5px;margin-top:10px;display:none}.status.ok{display:block;background:#daf5e4;color:#155f36;border:1px solid #97d6ad}.status.err{display:block;background:#fde1e1;color:#8c2525;border:1px solid #f3b2b2}.section-note{font-size:12px;color:#56636f;margin:0 0 10px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:13px}th,td{border:1px solid var(--border);padding:7px;vertical-align:middle}th{background:var(--soft);text-align:center}td input,td select{padding:6px!important;font-size:12px!important}.intervention-table th:nth-child(1){width:42px}.intervention-table th:nth-child(3){width:120px}.intervention-table th:nth-child(4){width:160px}.intervention-table th:nth-child(5){width:175px}.category{font-weight:700;background:#dce9f2;padding:8px 10px;border:1px solid var(--border);border-bottom:0;margin-top:12px}.status-yes{background:#e8f8ee}.status-no{background:#fff3f3}.tag{display:inline-block;background:#eaf1f6;color:#44545f;border-radius:3px;padding:4px 6px;font-size:11px}.check-grid{display:grid;grid-template-columns:1fr 110px;gap:10px;align-items:center}.footer-actions{display:flex;justify-content:flex-end;gap:8px;padding:14px;position:sticky;bottom:0;background:#fff;border-top:1px solid var(--border)}.required{color:#b12323}@media(max-width:900px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.top{align-items:flex-start;flex-direction:column}}@media(max-width:560px){.wrap{padding:10px}.grid,.grid.two{grid-template-columns:1fr}.search-row{align-items:stretch;flex-direction:column}.footer-actions{position:static}.intervention-table{min-width:800px}}
</style>
</head>
<body>
<main class="wrap">
  <div class="top">
    <div><div class="brand"><?= $edit ? 'Edit' : 'Form' ?> Intervensi Pencegahan Risiko Jatuh</div><div class="sub">Pencatatan untuk pasien rawat jalan dan rawat inap</div></div>
    <div class="nav"><a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'daftar')))?>">Daftar Rawat Inap</a><a class="btn gray" href="<?=rj_h(rj_url(array('mode'=>'daftar_rajal')))?>">Daftar Rawat Jalan</a><a class="btn orange" href="<?=rj_h(rj_url(array('mode'=>'risiko_tinggi')))?>">Dashboard Risiko</a><a class="btn green" href="<?=rj_h(rj_url(array('mode'=>'form')))?>">Form Baru</a></div>
  </div>
  <div class="tabs" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px"><a style="padding:9px 12px;border-radius:6px;background:#1368a8;color:white;text-decoration:none" href="<?=rj_h(rj_url(array('mode'=>'form')))?>">Input Intervensi Rawat Inap</a><a style="padding:9px 12px;border-radius:6px;background:#e8eef4;color:#25313b;text-decoration:none" href="<?=rj_h(rj_url(array('mode'=>'form_rajal')))?>">Input Intervensi Rawat Jalan</a></div>
  <?php if (isset($_GET['info']) && $_GET['info']==='wajib'): ?><div class="notice">Nomor rawat, identitas pasien, Perawat Pelaksana, dan Kepala Ruangan wajib dilengkapi.</div><?php endif; ?>
  <?php if (isset($_GET['info']) && $_GET['info']==='db'): ?><div class="notice" style="border-color:#ef9a9a;background:#fff0f0;color:#8b2424"><b>Form belum tersimpan.</b><br><?=rj_h(isset($_GET['dbmsg']) ? $_GET['dbmsg'] : 'Periksa struktur tabel dan koneksi database.')?></div><?php endif; ?>
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
    <input type="hidden" name="perawat_npk" id="perawat_npk">
    <input type="hidden" name="perawat_nama" id="perawat_nama">
    <input type="hidden" name="karu_npk" id="karu_npk">
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
        <p class="section-note">Pilih data petugas dari tabel <code>pegawai</code>. Saat cetak, identitas petugas ditampilkan sebagai QR Code dan menggantikan TTD manual. QR Code memuat NPK, nama, jabatan, dan nomor formulir.</p>
        <div class="grid two">
          <div class="field"><label>Perawat Pelaksana <span class="required">*</span></label>
            <select id="select_perawat"><option value="">-- Pilih Perawat Pelaksana --</option><?php foreach($pegawai as $p): ?><option value="<?=rj_h($p['npk'])?>" data-nama="<?=rj_h($p['nama'])?>"><?=rj_h($p['npk'].' - '.$p['nama'])?></option><?php endforeach; ?></select>
          </div>
          <div class="field"><label>Kepala Ruangan <span class="required">*</span></label>
            <select id="select_karu"><option value="">-- Pilih Kepala Ruangan --</option><?php foreach($pegawai as $p): ?><option value="<?=rj_h($p['npk'])?>" data-nama="<?=rj_h($p['nama'])?>"><?=rj_h($p['npk'].' - '.$p['nama'])?></option><?php endforeach; ?></select>
          </div>
          <div class="field"><label>Preview Paraf Elektronik Perawat</label><input id="preview_paraf_perawat" class="readonly" readonly></div>
          <div class="field"><label>Preview QR Code</label><input class="readonly" readonly value="QR Code akan terbentuk pada dokumen cetak."></div>
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
var prefillNoRawat = <?=json_encode(isset($_GET['no_rawat']) ? $_GET['no_rawat'] : '')?>;
var existing = <?=json_encode($edit_payload, JSON_UNESCAPED_UNICODE)?> || {};
var preRawat = <?=json_encode(isset($_GET['no_rawat']) ? trim($_GET['no_rawat']) : '')?>;
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
  var npk = el('select_perawat').value || '';
  var nama = getPerawatName();
  if (!npk) return '';
  var initial = nama.split(/\s+/).filter(Boolean).map(function(s){return s.charAt(0).toUpperCase();}).join('').slice(0,4);
  return npk + (initial ? ' / '+initial : '');
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
  setSelect('select_perawat',existing.perawat_npk); setSelect('select_karu',existing.karu_npk); updatePerawatPreview();
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
  el('perawat_npk').value=perawat.value;el('perawat_nama').value=selectedName(perawat);el('karu_npk').value=karu.value;el('karu_nama').value=selectedName(karu);
});
loadExisting();
if (!existing.id && prefillNoRawat) {
  el('cari_no_rawat').value = prefillNoRawat;
  window.setTimeout(function(){ el('btn-cari').click(); }, 200);
}
})();
</script>
</body>
</html>
