<?php
// === CONFIGURAZIONE ===
$baseDir      = __DIR__;                                            // cartella principale
$configName   = ".Magic-Photo-Gallery";                             // nome della cartella di configurazione
$configDir    = $baseDir . DIRECTORY_SEPARATOR . $configName;       // path per tutti i file generati da questo script
$configFile   = $configDir . DIRECTORY_SEPARATOR . '.config.json';  // file di configurazione
$thumbsRoot   = $configDir . DIRECTORY_SEPARATOR . '.thumbs';       // path per le miniature
$prefThumb    = '.tbn_';                                            // prefisso per file thumbnail
$imgExtension = 'jpg,jpeg,JPG,JPEG,png,PNG,heic,HEIC';

// Nome del file script corrente (senza path). Evita hardcoding.
$self = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');

// Configurazione di default
$defaults = [
    'title'       => 'Galleria foto',
    'thumb_make'  => 320,        // lato lungo delle anteprime generate
    'thumb_view'  => 320,        // lato della miniatura mostrata in griglia
    'debugFlag'   => false,
    // Colori day/night
    'bg_light'      => '#f6f6f6',
    'fg_light'      => '#111111',
    'bg_dark'       => '#111111',
    'fg_dark'       => '#eeeeee',
    // Cartelle da escludere
    'exclude_dirs'  => [ $configName, 'log', 'logs' ],  // ulteriori cartelle da escludere
    // GIF di cartella
    'folder_gif_mode'          => 'transition', // 'transition' | 'cut'
    'folder_gif_fade_frames'   => 6,            // frame intermedi per dissolvenza tra due foto (usato in 'transition')
    'folder_gif_max_frames'    => 12,           // massimo numero di foto di partenza
    'folder_gif_delay_cs'      => 80,            // ritardo di base per frame (centisecondi)
    // Modalità autenticazione: 0=nessun login, 1=login solo per funzioni extra, 2=login per tutto
    'auth_mode'     => 0,
    'auth_password' => ''   // può essere testo in chiaro o password_hash() ($2y$...)
];
// Carica o crea .config.json
$cfg = $defaults;
$exclude = [];
if (is_file($configFile)) {
    $raw = @file_get_contents($configFile);
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $cfg = array_merge($cfg, array_intersect_key($json, $defaults));
        if (isset($json['exclude_dirs']) && is_array($json['exclude_dirs'])) {
            $exclude = $json['exclude_dirs'];
        }
    }
    // Integra il file esistente con eventuali nuove chiavi di default
    $needsSave = false;
    $jsonUpdated = is_array($json) ? $json : [];
    foreach ($defaults as $k => $v) {
        if (!array_key_exists($k, $jsonUpdated)) {
            $jsonUpdated[$k] = $v;
            $needsSave = true;
        }
    }
    if ($needsSave) {
        @file_put_contents($configFile, json_encode($jsonUpdated, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
} else {
    @file_put_contents($configFile, json_encode($defaults, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}
$cfg['title'] = (string)$cfg['title'];
$cfg['thumb_make'] = max(32, (int)$cfg['thumb_make']);
$cfg['thumb_view'] = max(48, (int)$cfg['thumb_view']);
// GIF cfg
$cfg['folder_gif_mode'] = in_array(($cfg['folder_gif_mode'] ?? 'transition'), ['transition','cut'], true) ? $cfg['folder_gif_mode'] : 'transition';
$cfg['folder_gif_fade_frames'] = max(0, (int)($cfg['folder_gif_fade_frames'] ?? 6));
$cfg['folder_gif_max_frames'] = max(1, (int)($cfg['folder_gif_max_frames'] ?? 12));
$cfg['folder_gif_delay_cs'] = max(1, (int)($cfg['folder_gif_delay_cs'] ?? 8));
// Backward-compatibilità: se esiste 'auth_enabled' nel file di config, mappa a auth_mode (true->2, false->0) solo se 'auth_mode' non è definito
if (!array_key_exists('auth_mode', $cfg) && array_key_exists('auth_enabled', $cfg)) {
    $cfg['auth_mode'] = !empty($cfg['auth_enabled']) ? 2 : 0;
}
// Sanitizza
foreach (['bg_light','fg_light','bg_dark','fg_dark'] as $k) {
    if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string)$cfg[$k])) {
        $cfg[$k] = $defaults[$k];
    }
}
// Esclusioni directory: unisci defaults + config utente, normalizza e de-duplica
$ex = array_merge($defaults['exclude_dirs'], is_array($exclude) ? $exclude : []);
$ex = array_values(array_unique(array_map(function($s){
    $s = trim((string)$s);
    $s = str_replace(['\\'], '/', $s);
    $s = trim($s, '/');
    return $s;
}, $ex)));
$cfg['exclude_dirs'] = $ex;
// Debug flag
$cfg['debugFlag'] = !empty($cfg['debugFlag']);
$debugFlag = $cfg['debugFlag'];
// Auth
$cfg['auth_mode'] = isset($cfg['auth_mode']) ? (int)$cfg['auth_mode'] : 0;
if ($cfg['auth_mode'] < 0 || $cfg['auth_mode'] > 2) { $cfg['auth_mode'] = 0; }
$cfg['auth_password'] = isset($cfg['auth_password']) ? (string)$cfg['auth_password'] : '';

// === SESSIONE E AUTENTICAZIONE SEMPLICE ===
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
if (empty($_SESSION['csrf'])) {
    try { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    catch (Throwable $e) { $_SESSION['csrf'] = bin2hex(openssl_random_pseudo_bytes(16)); }
}
$CSRF_TOKEN = $_SESSION['csrf'];
$SESSION_KEY = 'mpg_auth';
if (isset($_GET['logout'])) {
    unset($_SESSION[$SESSION_KEY]);
    header('Location: ' . $self); exit;
}
function auth_ok() {
    global $cfg, $SESSION_KEY;
    if (!isset($cfg['auth_mode'])) return true;
    if ((int)$cfg['auth_mode'] === 0) return true;
    return !empty($_SESSION[$SESSION_KEY]);
}
function try_login($pass) {
    global $cfg, $SESSION_KEY;
    $stored = (string)$cfg['auth_password'];
    if ($stored === '') return false;
    $ok = false;
    // supporta hash bcrypt o testo in chiaro
    if (preg_match('/^\$2[aby]\$/', $stored)) {
        $ok = password_verify($pass, $stored);
    } else {
        $ok = hash_equals($stored, $pass);
    }
    if ($ok) {
        $_SESSION[$SESSION_KEY] = 1;
        return true;
    }
    return false;
}

// Debug logger
function dbg_log($data) {
    global $configDir, $debugFlag;
    if (empty($debugFlag)) return;
    $line = '[' . date('c') . '] ' . json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL;
    @file_put_contents($configDir . '/.debug.log', $line, FILE_APPEND);
}

$__LAST_THUMB_ERR = null;
function img_dims($path) {
    $s = @getimagesize($path);
    return $s ? ['w'=>$s[0]??0,'h'=>$s[1]??0,'mime'=>$s['mime']??''] : null;
}
function rel($abs) {
    // Converte un path assoluto del filesystem in path URL relativo alla directory dello script
    $abs = str_replace('\\', '/', $abs);
    $baseFs = str_replace('\\', '/', realpath($GLOBALS['baseDir']));
    $baseUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (strpos($abs, $baseFs) === 0) {
        $suffix = ltrim(substr($abs, strlen($baseFs)), '/');
        // Se lo script è in root, non aggiungere doppio slash
        if ($baseUrl === '' || $baseUrl === '/') {
            return '/' . $suffix;
        }
        return $baseUrl . '/' . $suffix;
    }
    // Fallback: basename come ultima risorsa
    return '/' . basename($abs);
}

// URL relativo con percent-encoding dei segmenti (gestisce spazi e caratteri speciali)
function rel_url($abs) {
    $p = rel($abs);
    $parts = explode('/', $p);
    $out = [];
    foreach ($parts as $i => $seg) {
        if ($seg === '' && $i === 0) { $out[] = ''; continue; } // leading slash
        $out[] = rawurlencode($seg);
    }
    return implode('/', $out) ?: '/';
}

// Nome file thumbnail con prefisso nascosto, sempre .jpg
function thumb_name($origName) {
    // Thumbs sempre in JPEG: normalizza estensione a .jpg
    global $prefThumb;
    $base = preg_replace('/\.[^.]+$/', '', (string)$origName);
    return $prefThumb . $base . '.jpg';
}

$thumbWidth = $cfg['thumb_make'];        // lato lungo thumbnail generate

// === NAVIGAZIONE CARTELLE ===
$reqDir = isset($_GET['dir']) ? trim($_GET['dir']) : '';
// normalizza separatori
$reqDir = str_replace(['\\'], '/', $reqDir);
$reqDir = ltrim($reqDir, '/');
// blocca risalite
if (strpos($reqDir, '..') !== false) { $reqDir = ''; }
$currentAbs = realpath($baseDir . ($reqDir ? '/' . $reqDir : '')) ?: $baseDir;
// Enforce sotto-albero
if (strpos($currentAbs, realpath($baseDir)) !== 0) { $currentAbs = $baseDir; $reqDir = ''; }
$relDir = trim(str_replace(realpath($baseDir), '', $currentAbs), DIRECTORY_SEPARATOR);
$relDir = str_replace(DIRECTORY_SEPARATOR, '/', $relDir);
// blocca l'accesso a cartelle escluse
if ($relDir) {
    $segs = explode('/', $relDir);
    $acc = [];
    foreach ($segs as $seg) {
        if (is_excluded_dir($seg, implode('/', array_merge($acc, [$seg])))) {
            // se uno dei segmenti è escluso, torna alla root
            $currentAbs = $baseDir;
            $relDir = '';
            break;
        }
        $acc[] = $seg;
    }
}
$isRoot = ($relDir === '' || $relDir === false);

// Directory thumbs per cartella corrente, mirroring struttura
$thumbDirForView = $isRoot ? $thumbsRoot : ($thumbsRoot . '/' . $relDir);
if (!is_dir($thumbDirForView)) { @mkdir($thumbDirForView, 0755, true); }

// GIF di anteprima per cartelle: salviamo come .folder.gif dentro la cartella thumbs corrispondente
$folderGifName = $prefThumb . 'folder.gif';

// Progress file per GIF cartella
function folder_gif_progress_path($dirRel, $thumbsRoot, $prefThumb) {
    // Usa /tmp per progressi per evitare cache/lag su FS remoti
    $safe = $dirRel === '' ? '__root__' : str_replace(['\\','/'], '__', $dirRel);
    $dir  = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mpg_progress';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    return $dir . DIRECTORY_SEPARATOR . $prefThumb . $safe . '.json';
}

// helper: verifica se una cartella va esclusa
function is_excluded_dir($entryName, $relativePath = '') {
    global $cfg;
    // escludi sempre cartelle che iniziano con punto
    if ($entryName === '' || $entryName[0] === '.') return true;
    // confronta per nome semplice e per path relativo normalizzato
    $rel = str_replace(['\\'], '/', trim($relativePath, '/'));
    foreach ($cfg['exclude_dirs'] as $e) {
        if ($e === '') continue;
        if (strcasecmp($entryName, $e) === 0) return true;
        if ($rel !== '' && strcasecmp($rel, $e) === 0) return true;
    }
    return false;
}

// === ENDPOINT: genera hash per password ===
if (isset($_GET['mkhash'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ((int)$cfg['auth_mode'] === 0 || !auth_ok()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
    $plain = (string)($_GET['mkhash'] ?? '');
    if ($plain === '') { echo json_encode(['ok'=>false,'error'=>'empty']); exit; }
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    echo json_encode(['ok'=>true,'hash'=>$hash], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

/* === ENDPOINT: login via AJAX (auth_mode >= 1) === */
if (isset($_GET['do_login'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ((int)$cfg['auth_mode'] === 0) { http_response_code(404); echo json_encode(['ok'=>false]); exit; }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit; }
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $pass = (string)($payload['pass'] ?? '');
    if ($pass === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'empty']); exit; }
    if (try_login($pass)) { echo json_encode(['ok'=>true]); exit; }
    http_response_code(401); echo json_encode(['ok'=>false,'error'=>'bad_password']); exit;
}
// === LOGIN GATE ===
if ((int)$cfg['auth_mode'] === 2 && !auth_ok()) {
    // Se è stato inviato il form, prova il login
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pass'])) {
        if (try_login($_POST['pass'])) {
            header('Location: ' . $self); exit;
        }
        $err = 'Password errata';
    }
    ?><!doctype html>
    <html lang="it"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Accesso richiesto</title>
    <style>
    :root{--bg:#111;--fg:#eee;}
    body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;}
    form{background:#1b1b1b;padding:24px;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.35);min-width:260px}
    h1{font-size:18px;margin:0 0 12px 0}
    .row{display:flex;gap:8px}
    input[type=password]{flex:1;padding:10px 12px;border-radius:8px;border:1px solid #333;background:#0f0f0f;color:#fff}
    button{padding:10px 12px;border-radius:8px;border:1px solid #333;background:#222;color:#fff;cursor:pointer}
    .err{color:#ffb4b4;font-size:12px;margin-top:8px;height:14px}
    </style></head><body>
    <form method="post" autocomplete="current-password">
      <h1><?php echo htmlspecialchars($cfg['title'],ENT_QUOTES); ?></h1>
      <div class="row">
        <input name="pass" type="password" placeholder="Password" autofocus>
        <button type="submit">Entra</button>
      </div>
      <div class="err"><?php echo isset($err)?htmlspecialchars($err,ENT_QUOTES):''; ?></div>
    </form>
    </body></html><?php
    exit;
}

// Permetti richieste concorrenti mentre gira make_gif
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

// === PREPARAZIONE CARTELLE ===
if (!is_dir($configDir)) { @mkdir($configDir, 0755, true); }
if (!is_dir($thumbsRoot)) { @mkdir($thumbsRoot, 0755, true); }
// Proteggi i file sensibili nella cartella di configurazione
$htaccessPath = $configDir . '/.htaccess';
if (!file_exists($htaccessPath)) {
    $ht = <<<HTA
# Blocca accesso diretto a file sensibili
<Files ".config.json">
  Require all denied
</Files>
<Files ".debug.log">
  Require all denied
</Files>
# Niente listing
Options -Indexes

# Cache aggressiva per risorse statiche generate (thumbs e gif)
<IfModule mod_headers.c>
  <FilesMatch "\\.(?:jpe?g|png|gif)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
</IfModule>
HTA;
    @file_put_contents($htaccessPath, $ht);
}

/** Enforce sane defaults in the web root to avoid MultiViews negotiation issues */
$rootHtaccess = $baseDir . '/.htaccess';
$needLines = [];
$existing = is_file($rootHtaccess) ? @file_get_contents($rootHtaccess) : '';

if (stripos($existing, 'DirectoryIndex') === false || stripos($existing, 'index.php') === false) {
    $needLines[] = 'DirectoryIndex index.php index.html';
}

$hasMinusMulti = (stripos($existing, 'Options') !== false && stripos($existing, '-MultiViews') !== false);
if (!$hasMinusMulti) {
    $needLines[] = "<IfModule mod_negotiation.c>\n  Options -MultiViews\n</IfModule>";
}

if (!empty($needLines)) {
    $payload = rtrim($existing) . (strlen($existing) ? "\n\n" : '');
    $payload .= "# Added by Magic-Photo-Gallery to avoid content negotiation issues\n";
    $payload .= implode("\n", $needLines) . "\n";
    @file_put_contents($rootHtaccess, $payload);
}

// Flag estensione EXIF
$EXIF_AVAILABLE = extension_loaded('exif') || function_exists('exif_read_data');
function get_exif_orientation($path){
    if (!is_readable($path) || !function_exists('exif_read_data')) return 0;
    $exif = @exif_read_data($path, 'IFD0', true);
    if (!$exif) return 0;
    foreach ([
      $exif['IFD0']['Orientation'] ?? null,
      $exif['EXIF']['Orientation'] ?? null,
      $exif['Orientation'] ?? null
    ] as $o) {
      if (is_numeric($o)) { $o=(int)$o; if ($o>=1 && $o<=8) return $o; }
    }
    return 0;
}
if (!function_exists('imageflip')) {
    function imageflip(&$img,$mode){
        $w=imagesx($img); $h=imagesy($img); $d=imagecreatetruecolor($w,$h);
        if ($mode===IMG_FLIP_HORIZONTAL){ for($x=0;$x<$w;$x++) imagecopy($d,$img,$w-$x-1,0,$x,0,1,$h); }
        elseif($mode===IMG_FLIP_VERTICAL){ for($y=0;$y<$h;$y++) imagecopy($d,$img,0,$h-$y-1,0,$y,$w,1); }
        else { for($x=0;$x<$w;$x++) for($y=0;$y<$h;$y++) imagecopy($d,$img,$w-$x-1,$h-$y-1,$x,$y,1,1); }
        $img=$d; return true;
    }
}
// === FUNZIONE: genera thumbnail ===
function create_thumb($srcPath, $thumbPath, $thumbWidth, $force = false) {
    $GLOBALS['__LAST_THUMB_ERR'] = null;
    $ops = ['file'=>basename($srcPath),'lib'=>null,'exif'=>null,'actions'=>[]];
    // Assicurati che esista la cartella di destinazione
    $td = dirname($thumbPath);
    if (!is_dir($td)) { @mkdir($td, 0755, true); }

    // Se già esiste ed è più recente, non rigenerare
    if (!$force && file_exists($thumbPath) && filemtime($thumbPath) >= filemtime($srcPath)) {
        return true;
    }
    if ($force && file_exists($thumbPath)) {
        @unlink($thumbPath);
    }

    // Se file è HEIC/HEIF ma ImageMagick non supporta il formato, annota
    $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
    if (in_array($ext, ['heic','heif'])) {
        if (!class_exists('Imagick') || (empty(Imagick::queryFormats('HEIC')) && empty(Imagick::queryFormats('HEIF')))) {
            $GLOBALS['__LAST_THUMB_ERR'] = 'heic-unsupported: imagick senza delegate HEIC/HEIF';
            dbg_log(['thumb_error'=>$GLOBALS['__LAST_THUMB_ERR'], 'file'=>basename($srcPath)]);
        }
    }

    // Prova con Imagick se disponibile
    if (class_exists('Imagick')) {
        try {
            // Caricamento robusto per HEIC/HEIF: tenta immagine primaria e fallback [0]
            $img = new Imagick();
            $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
            if (in_array($ext,['heic','heif'])) {
                @$img->setOption('heic:primary','1');
                @$img->setOption('heic:ignore-auxiliary','true');
                try { $img->readImage($srcPath); $ops['actions'][]='read:primary'; }
                catch(Exception $e){ $img->clear(); $img=new Imagick(); $img->readImage($srcPath.'[0]'); $ops['actions'][]='read:primary'; }
            } else {
                $img->readImage($srcPath);
            }
            // Manually honor EXIF orientation before resizing
            $o = (function_exists('exif_read_data') ? get_exif_orientation($srcPath) : 0);
            $ops['exif'] = $o;
            if ($o >= 2) {
                $bg = new Imagick('xc:none');
                switch ($o) {
                    case 2: $img->flopImage();            $ops['actions'][] = 'flop'; break;     // mirror horizontal
                    case 3: $img->rotateImage($bg, 180);  $ops['actions'][] = 'rot180'; break;   // 180°
                    case 4: $img->flipImage();            $ops['actions'][] = 'flip'; break;     // mirror vertical
                    case 5: $img->transposeImage();       $ops['actions'][] = 'transpose'; break;// 90° CCW + mirror
                    case 6: $img->rotateImage($bg, 90);   $ops['actions'][] = 'rot90'; break;    // 90° CW
                    case 7: $img->transverseImage();      $ops['actions'][] = 'transverse'; break;// 90° CW + mirror
                    case 8: $img->rotateImage($bg, 270);  $ops['actions'][] = 'rot270'; break;   // 270° CW (90° CCW)
                }
            }
            $ops['lib'] = 'imagick';
            if (method_exists($img, 'autoOrientImage')) {
                $img->autoOrientImage();
                $ops['actions'][] = 'autoOrient';
            }
            // Assicura orientamento 'top-left' nel tag EXIF e rimuovi profili per evitare doppie rotazioni
            if (method_exists($img, 'setImageOrientation')) {
                $img->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
                $ops['actions'][] = 'setTopLeft';
            }
            // Calcola dimensioni DOPO l'auto-orientamento: lato lungo = $thumbWidth
            $w = $img->getImageWidth();
            $h = $img->getImageHeight();
            if ($w <= 0 || $h <= 0) { $img->clear(); return false; }
            $scale = $thumbWidth / max($w, $h);
            $newW = max(1, (int) round($w * $scale));
            $newH = max(1, (int) round($h * $scale));
            $ops['actions'][] = "resize:{$newW}x{$newH}";
            $img->resizeImage($newW, $newH, Imagick::FILTER_LANCZOS, 1, true);
            // Azzeriamo il tag di orientamento per evitare interpretazioni lato browser
            if (method_exists($img, 'setImageOrientation')) {
                $img->setImageOrientation(Imagick::ORIENTATION_UNDEFINED);
            }
            if (method_exists($img, 'stripImage')) {
                $img->stripImage();
            }
            // Forza formato di output a JPEG per compatibilità (HEIC/PNG in ingresso -> JPG in uscita)
            if (method_exists($img, 'setImageFormat')) {
                $img->setImageFormat('jpeg');
            }
            if (method_exists($img, 'setImageCompression')) {
                $img->setImageCompression(Imagick::COMPRESSION_JPEG);
            }
            if (method_exists($img, 'setImageCompressionQuality')) {
                $img->setImageCompressionQuality(85);
            }
            $img->writeImage($thumbPath);
            $img->clear();
            dbg_log(array_merge($ops, [
                'orig'=>img_dims($srcPath),
                'thumb'=>img_dims($thumbPath)
            ]));
            return true;
        } catch (Exception $e) {
            $GLOBALS['__LAST_THUMB_ERR'] = 'imagick-exception: ' . $e->getMessage();
            dbg_log(['thumb_error'=>$GLOBALS['__LAST_THUMB_ERR'], 'file'=>basename($srcPath)]);
            return false;
        }
    }

    // Altrimenti usa GD
    if (function_exists('imagecreatefromjpeg')) {
        // Prova JPEG poi PNG
        $src = @imagecreatefromjpeg($srcPath);
        if (!$src && function_exists('imagecreatefrompng')) {
            $src = @imagecreatefrompng($srcPath);
        }
        $ops['lib'] = 'gd';
        global $EXIF_AVAILABLE;
        // Auto-orienta in base all'EXIF (se presente)
        if ($EXIF_AVAILABLE) {
            $o = get_exif_orientation($srcPath);
            $ops['exif'] = $o;
            if ($o >= 2) {
                switch ($o) {
                    case 2:
                        imageflip($src, IMG_FLIP_HORIZONTAL);
                        $ops['actions'][] = 'flop';
                        break;
                    case 3:
                        $src = imagerotate($src, 180, 0);
                        $ops['actions'][] = 'rot180';
                        break;
                    case 4:
                        imageflip($src, IMG_FLIP_VERTICAL);
                        $ops['actions'][] = 'flip';
                        break;
                    case 5:
                        imageflip($src, IMG_FLIP_HORIZONTAL);
                        $src = imagerotate($src, -90, 0);
                        $ops['actions'][] = 'transpose';
                        break;
                    case 6:
                        $src = imagerotate($src, -90, 0);
                        $ops['actions'][] = 'rot90';
                        break;
                    case 7:
                        imageflip($src, IMG_FLIP_HORIZONTAL);
                        $src = imagerotate($src, 90, 0);
                        $ops['actions'][] = 'transverse';
                        break;
                    case 8:
                        $src = imagerotate($src, 90, 0);
                        $ops['actions'][] = 'rot270';
                        break;
                }
            }
        }
        if (!$src) {
            $GLOBALS['__LAST_THUMB_ERR'] = 'gd-open-failed';
            dbg_log(['thumb_error'=>$GLOBALS['__LAST_THUMB_ERR'], 'file'=>basename($srcPath)]);
            return false;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = $thumbWidth / max($w, $h); // lato lungo = $thumbWidth
        $newW = max(1, (int) round($w * $scale));
        $newH = max(1, (int) round($h * $scale));
        $ops['actions'][] = "resize:{$newW}x{$newH}";
        $thumb = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagejpeg($thumb, $thumbPath, 85);
        // Nota: EXIF non viene preservato in GD, quindi non c'è rischio di doppia rotazione.
        imagedestroy($src);
        imagedestroy($thumb);
        dbg_log(array_merge($ops, [
            'orig'=>img_dims($srcPath),
            'thumb'=>img_dims($thumbPath)
        ]));
        return true;
    }

    return false;
}

// === FUNZIONE: GIF animata di cartella (Imagick) ===
function create_folder_gif($srcDirAbs, $gifPath, $frameSize, $maxFrames = 12, $delayCs = 8, $progressPath = null, $mode = 'transition', $fadeFrames = 6) {
  global $imgExtension;
  $mode = $mode ?: 'transition';
  $fadeFrames = max(0, (int)$fadeFrames);
  // Raccogli immagini (solo primo livello della cartella)
    $files = glob($srcDirAbs . '/*.{'.$imgExtension.'}', GLOB_BRACE) ?: [];
    natsort($files);
    $files = array_values($files);
    if (empty($files)) return false;

    $total = min($maxFrames, count($files));
    if ($progressPath) {
        $td = dirname($progressPath);
        if (!is_dir($td)) @mkdir($td, 0755, true);
        $tmp = $progressPath . '.tmp';
        @file_put_contents($tmp, json_encode([
            'total' => $total,
            'done'  => 0,
            'started_at' => time()
        ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), LOCK_EX);
        @rename($tmp, $progressPath);
    }

    // Se non c'è Imagick, usa un PNG/JPEG statico del primo file convertito a GIF
    if (!class_exists('Imagick')) {
        if ($mode === 'transition') {
            $first = $files[0];
            // prepara thumb singola e rinominala .gif
            $tmpJpg = $gifPath . '.tmp.jpg';
            $td = dirname($gifPath);
            if (!is_dir($td)) @mkdir($td, 0755, true);
            if (!create_thumb($first, $tmpJpg, $frameSize, true)) return false;
            // copia come gif "finta"
            $ok = @copy($tmpJpg, $gifPath);
            if ($progressPath) {
                $tmp = $progressPath . '.tmp';
                @file_put_contents($tmp, json_encode([
                    'total' => 1,
                    'done'  => 1,
                    'done_at' => time()
                ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), LOCK_EX);
                @rename($tmp, $progressPath);
            }
            return $ok;
        } else {
            // cut mode: fallback identico (statico)
            $first = $files[0];
            $tmpJpg = $gifPath . '.tmp.jpg';
            $td = dirname($gifPath);
            if (!is_dir($td)) @mkdir($td, 0755, true);
            if (!create_thumb($first, $tmpJpg, $frameSize, true)) return false;
            $ok = @copy($tmpJpg, $gifPath);
            if ($progressPath) {
                $tmp = $progressPath . '.tmp';
                @file_put_contents($tmp, json_encode([
                    'total' => 1,
                    'done'  => 1,
                    'done_at' => time()
                ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), LOCK_EX);
                @rename($tmp, $progressPath);
            }
            return $ok;
        }
    }

    $td = dirname($gifPath);
    if (!is_dir($td)) @mkdir($td, 0755, true);
    $base = new Imagick();
    $base->setFormat('gif');
    $count = 0;
    foreach ($files as $f) {
        if ($count >= $maxFrames) break;
        try {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext,['heic','heif'])) {
                $frame = new Imagick();
                @$frame->setOption('heic:primary','1');
                try { $frame->readImage($f); }
                catch(Exception $e){ $frame->clear(); $frame=new Imagick(); $frame->readImage($f.'[0]'); }
            } else {
                $frame = new Imagick($f);
            }
            if (method_exists($frame,'autoOrientImage')) $frame->autoOrientImage();
            $w = $frame->getImageWidth(); $h = $frame->getImageHeight();
            $scale = $frameSize / max($w,$h);
            $newW = max(1,(int)round($w*$scale)); $newH = max(1,(int)round($h*$scale));
            $frame->resizeImage($newW, $newH, Imagick::FILTER_LANCZOS, 1, true);
            if (method_exists($frame,'stripImage')) $frame->stripImage();
            $frame->setImagePage(0,0,0,0);
            $frame->setImageDelay($delayCs);
            $base->addImage($frame);
            $base->setImageDispose(Imagick::DISPOSE_BACKGROUND);
            $count++;
            if ($progressPath) {
                $tmp = $progressPath . '.tmp';
                @file_put_contents($tmp, json_encode([
                    'total' => min($maxFrames, count($files)),
                    'done'  => $count
                ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), LOCK_EX);
                @rename($tmp, $progressPath);
            }
        } catch (Exception $e) { continue; }
    }
    if ($count === 0) { return false; }
    $base = $base->coalesceImages();
    if ($mode === 'transition' && $fadeFrames > 0) {
        // Genera dissolvenze tra i frame
        $morphed = $base->morphImages($fadeFrames);
        // Imposta un delay più breve per i frame intermedi
        foreach ($morphed as $i => $frm) { $frm->setImageDelay(max(1, (int)floor($delayCs / 2))); }
        $ok = $morphed->writeImages($gifPath, true);
        $morphed->clear();
    } else {
        // Taglio secco
        $ok = $base->writeImages($gifPath, true);
    }
    $base->clear();
    if ($progressPath) {
        $tmp = $progressPath . '.tmp';
        @file_put_contents($tmp, json_encode([
            'total' => $total,
            'done'  => $count,
            'done_at' => time()
        ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), LOCK_EX);
        @rename($tmp, $progressPath);
    }
    return $ok;
}

// === ENDPOINT: stato avanzamento GIF cartella ===
if (isset($_GET['gif_progress'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (!auth_ok()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
    $dirRel = trim(str_replace(['\\'],'/', ltrim($_GET['gif_progress'],'/')));
    if (strpos($dirRel,'..') !== false) { echo json_encode(['ok'=>false]); exit; }
    $ppath = folder_gif_progress_path($dirRel, $thumbsRoot, $prefThumb);
    if (!is_file($ppath)) { echo json_encode(['ok'=>true,'exists'=>false,'total'=>0,'done'=>0]); exit; }
    $data = @json_decode(@file_get_contents($ppath), true) ?: [];
    $total = isset($data['total']) ? (int)$data['total'] : 0;
    $done  = isset($data['done'])  ? (int)$data['done']  : 0;
    echo json_encode(['ok'=>true,'exists'=>true,'total'=>$total,'done'=>$done], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

// === ENDPOINT ASINCRONO: genera GIF anteprima cartella ===
if (isset($_GET['make_gif'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (!auth_ok()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
    @set_time_limit(0);
    $dirRel = trim(str_replace(['\\'],'/', ltrim($_GET['make_gif'],'/')));
    if (strpos($dirRel,'..') !== false) { echo json_encode(['ok'=>false]); exit; }
    $srcAbs = realpath($baseDir . ($dirRel ? '/' . $dirRel : '')) ?: $baseDir;
    if (strpos($srcAbs, realpath($baseDir)) !== 0) { echo json_encode(['ok'=>false]); exit; }
    // rifiuta se il target è escluso
    if ($dirRel !== '') {
        $last = basename($dirRel);
        if (is_excluded_dir($last, $dirRel)) { echo json_encode(['ok'=>false,'error'=>'excluded']); exit; }
    }
    $gifAbs = ($dirRel === '' ? ($thumbsRoot . '/' . $folderGifName) : ($thumbsRoot . '/' . $dirRel . '/' . $folderGifName));
    $progressPath = folder_gif_progress_path($dirRel, $thumbsRoot, $prefThumb);
    $ok = create_folder_gif(
        $srcAbs,
        $gifAbs,
        max(96, (int)$cfg['thumb_view']),
        (int)$cfg['folder_gif_max_frames'],
        (int)$cfg['folder_gif_delay_cs'],
        $progressPath,
        (string)$cfg['folder_gif_mode'],
        (int)$cfg['folder_gif_fade_frames']
    );
    echo json_encode([
        'ok' => (bool)$ok,
        'gif' => $ok ? rel_url($gifAbs) . '?v=' . time() : null
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

// === ENDPOINT DIAGNOSTICA: info su un file
if (isset($_GET['diag'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!auth_ok()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
    $name = basename($_GET['diag']);
    $srcPath = ($currentAbs . '/' . $name);
    $thumbPath = ($thumbDirForView . '/' . thumb_name($name));
    $out = [
        'file' => $name,
        'exists_src' => is_file($srcPath),
        'exists_thumb' => is_file($thumbPath),
        'exif_available' => (bool)$EXIF_AVAILABLE,
        'exif_orientation' => $EXIF_AVAILABLE ? get_exif_orientation($srcPath) : null,
        'imagick_available' => class_exists('Imagick'),
        'gd_available' => function_exists('imagecreatefromjpeg'),
        'src_dims' => img_dims($srcPath),
        'thumb_dims' => img_dims($thumbPath),
        'thumb_mtime' => is_file($thumbPath) ? filemtime($thumbPath) : null,
        'will_use' => (class_exists('Imagick') ? 'imagick' : (function_exists('imagecreatefromjpeg') ? 'gd' : 'none')),
    ];
    echo json_encode($out, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

// === ENDPOINT ASINCRONO: genera una singola thumbnail e ritorna JSON ===
if (isset($_GET['make_thumb'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!auth_ok()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
    @set_time_limit(0);
    $force = isset($_GET['force']) && $_GET['force'] === '1';

    $name = basename($_GET['make_thumb']); // sanitizza
    $srcPath = $currentAbs . '/' . $name;
    $thumbPath = $thumbDirForView . '/' . thumb_name($name);

    if (!is_dir($thumbDirForView)) { @mkdir($thumbDirForView, 0755, true); }

    $ok = is_file($srcPath) ? create_thumb($srcPath, $thumbPath, $thumbWidth, $force) : false;

    echo json_encode([
        'ok' => (bool)$ok,
        'name' => $name,
        'thumb' => $ok && file_exists($thumbPath) ? rel($thumbPath) : null,
        'mtime' => $ok && file_exists($thumbPath) ? filemtime($thumbPath) : null,
        'exif' => (bool)$EXIF_AVAILABLE,
        'error' => $ok ? null : ($GLOBALS['__LAST_THUMB_ERR'] ?? 'gen-failed')
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// === ENDPOINT: purge cache thumbs+gif per una cartella ===
if (isset($_GET['purge_cache'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    // Solo POST
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit; }

    // Se auth_mode >=1 richiede login e CSRF valido; se 0 non richiede auth
    if ((int)$cfg['auth_mode'] >= 1) {
        if (!auth_ok()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $csrf = (string)($payload['csrf'] ?? '');
        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
        }
    } else {
        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    }

    $targetRel = trim(str_replace(['\\'],'/', ltrim($_GET['dir'] ?? $relDir, '/')));
    if (strpos($targetRel,'..') !== false) { echo json_encode(['ok'=>false,'error'=>'bad_rel']); exit; }
    $recursive = !empty($payload['recursive']);
    $purged = ['thumbs'=>0,'gifs'=>0,'dirs'=>0];

    $purgeOne = function($rel) use ($thumbsRoot, $prefThumb, $folderGifName, &$purged) {
        $tDir = ($rel === '' ? $thumbsRoot : ($thumbsRoot . '/' . $rel));
        if (!is_dir($tDir)) return;
        if ($h=@opendir($tDir)) {
            while(false!==($f=readdir($h))){
                if ($f==='.'||$f==='..') continue;
                $p=$tDir.'/'.$f; if (is_dir($p)) continue;
                if ($f===$folderGifName){ @unlink($p); $purged['gifs']++; }
                if (strpos($f,$prefThumb)===0){ @unlink($p); $purged['thumbs']++; }
            }
            closedir($h);
        }
        $purged['dirs']++;
    };

    if ($recursive) {
        $start = ($targetRel === '' ? $thumbsRoot : ($thumbsRoot . '/' . $targetRel));
        $len = strlen($thumbsRoot) + 1;
        if (is_dir($start)) {
            $dirs = [$start];
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($start, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $path=>$info) if ($info->isDir()) $dirs[]=$path;
            $rels = array_unique(array_map(function($d)use($len){
                $d=str_replace('\\','/',$d); return ltrim(substr($d,$len),'/');
            },$dirs));
            foreach($rels as $r) $purgeOne($r);
        }
    } else {
        $purgeOne($targetRel);
    }
    echo json_encode(['ok'=>true,'purged'=>$purged], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

// === SCANSIONE: sottocartelle e file immagini nella cartella corrente ===
$subdirs = [];
$dIt = @scandir($currentAbs) ?: [];
foreach ($dIt as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $abs = $currentAbs . '/' . $entry;
    if (!is_dir($abs)) continue;
    $rel = ltrim(($relDir ? $relDir . '/' : '') . $entry, '/');
    if (is_excluded_dir($entry, $rel)) continue;
    // Conta elementi interni immediati
    $imgCount = 0;
    foreach (glob($abs . '/*.{'.$imgExtension.'}', GLOB_BRACE) ?: [] as $p) {
        if (basename($p)[0] !== '.') $imgCount++;
    }
    $dirCount = 0;
    $scanInner = @scandir($abs) ?: [];
    foreach ($scanInner as $e2) {
        if ($e2 === '.' || $e2 === '..') continue;
        if ($e2[0] === '.') continue;
        $abs2 = $abs . '/' . $e2;
        if (!is_dir($abs2)) continue;
        $rel2 = ltrim(($rel ? $rel . '/' : '') . $e2, '/');
        if (is_excluded_dir($e2, $rel2)) continue;
        $dirCount++;
    }
    // GIF path
    $gifAbs = ($rel === '' ? ($thumbsRoot . '/' . $folderGifName) : ($thumbsRoot . '/' . $rel . '/' . $folderGifName));
    // Placeholder SVG per "cartella annidata" quando non ci sono foto ma ci sono sottocartelle
    $placeholder = null;
    if (!file_exists($gifAbs) && $imgCount === 0 && $dirCount > 0) {
        $svg = '<?xml version="1.0" encoding="UTF-8"?>'
             . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 150" preserveAspectRatio="xMidYMid slice">'
             . '<defs>'
             . '  <linearGradient id="bg" x1="0" y1="0" x2="0" y2="1">'
             . '    <stop offset="0" stop-color="#eef1f5"/><stop offset="1" stop-color="#e3e6ea"/>'
             . '  </linearGradient>'
             . '  <linearGradient id="folder" x1="0" y1="0" x2="0" y2="1">'
             . '    <stop offset="0" stop-color="#ffd36b"/><stop offset="1" stop-color="#f0b548"/>'
             . '  </linearGradient>'
             . '  <filter id="soft" x="-10%" y="-10%" width="120%" height="120%"><feGaussianBlur in="SourceAlpha" stdDeviation="2"/><feOffset dy="1"/><feComponentTransfer><feFuncA type="linear" slope="0.35"/></feComponentTransfer><feMerge><feMergeNode/><feMergeNode in="SourceGraphic"/></feMerge></filter>'
             . '</defs>'
             . '<rect width="200" height="150" rx="16" fill="url(#bg)"/>'
             . '<g transform="translate(20,36)" filter="url(#soft)">'
             . '  <!-- linguetta -->'
             . '  <path d="M18 6h32c6 0 10 2 13 6l5 6h78a12 12 0 0 1 12 12v48a12 12 0 0 1-12 12H12A12 12 0 0 1 0 78V18A12 12 0 0 1 12 6z" fill="url(#folder)" stroke="#d39f43" stroke-width="1"/>'
             . '  <!-- documenti stilizzati -->'
             . '  <g opacity=".9" transform="translate(24,28)">'
             . '    <rect x="0" y="8" width="50" height="34" rx="6" fill="#fff" stroke="#d9d9d9"/>'
             . '    <rect x="10" y="0" width="60" height="38" rx="6" fill="#fff" stroke="#d9d9d9"/>'
             . '  </g>'
             . '  <!-- indice cartelle -->'
             . '  <g opacity=".8" transform="translate(110,24)">'
             . '    <rect x="0" y="0" width="48" height="30" rx="6" fill="#fff" stroke="#d9d9d9"/>'
             . '    <path d="M12 9h24M12 15h24M12 21h24" stroke="#a0a0a0" stroke-width="3" stroke-linecap="round"/>'
             . '  </g>'
             . '</g>'
             . '</svg>';
        $placeholder = 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
    $subdirs[] = [
        'name' => $entry,
        'rel'  => $rel,
        'gif'  => file_exists($gifAbs) ? rel_url($gifAbs) : null,
        'imgs' => $imgCount,
        'dirs' => $dirCount,
        'placeholder' => $placeholder
    ];
}
// immagini nella cartella corrente
$files = array_values(array_filter(glob($currentAbs . '/*.{'.$imgExtension.'}', GLOB_BRACE) ?: [], function($p){
    return basename($p)[0] !== '.';
}));
natsort($files);
$files = array_values($files);

// === PREPARA DATI ===
$items = [];
foreach ($files as $path) {
    $name = basename($path);
    $thumbPath = $thumbDirForView . '/' . thumb_name($name);
    $hasThumb = file_exists($thumbPath);
    $items[] = [
        'name'     => htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        'src'      => htmlspecialchars(rel($path), ENT_QUOTES),
        'thumb'    => htmlspecialchars(rel($thumbPath), ENT_QUOTES),
        'hasThumb' => $hasThumb ? 1 : 0
    ];
}

$pageTitle = $isRoot ? $cfg['title'] : basename($relDir);
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES); ?></title>
<style>
:root {
  --gap: 3px;
  --thumb-size: <?php echo (int)$cfg['thumb_view']; ?>px;
  --bg: <?php echo htmlspecialchars($cfg['bg_light'], ENT_QUOTES); ?>;
  --fg: <?php echo htmlspecialchars($cfg['fg_light'], ENT_QUOTES); ?>;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: <?php echo htmlspecialchars($cfg['bg_dark'], ENT_QUOTES); ?>;
    --fg: <?php echo htmlspecialchars($cfg['fg_dark'], ENT_QUOTES); ?>;
  }
}
body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: var(--bg); color: var(--fg); }
header { padding: 12px 16px; font-weight: 600; }
.hdr { display:flex; align-items:center; justify-content:space-between; gap:12px; position:relative; }
.hdr .title { position:absolute; left:50%; transform:translateX(-50%); text-align:center; font-weight:700; }
.crumbs a { color: inherit; text-decoration: none; }
.crumbs { font-weight:600; display:flex; align-items:center; gap:6px; }
.crumbs .sep { opacity:.6; }
.grid {
  display: grid;
  gap: var(--gap);
  padding: var(--gap);
  /*grid-template-columns: repeat(auto-fill, minmax(var(--thumb-size), 1fr));*/
  grid-template-columns: repeat(auto-fill, minmax(calc(var(--thumb-size) * 1), 1fr));
}
#grid {
  gap: 2px;
  padding: 2px;
}
.card {
  position: relative;
  border-radius: 5px;
  overflow: hidden;
  background: rgba(0,0,0,.2);
  margin: var(--gap);
  cursor:pointer;
  aspect-ratio: 1 / 1; /* celle quadrate e uniformi */
  content-visibility: auto; contain-intrinsic-size: var(--thumb-size) var(--thumb-size);
}
.progress {
  position:absolute; inset:6px 6px auto 6px; /* in alto, non sovrapposta al nome */
  height:8px; background:rgba(255,255,255,.25); border-radius:999px; overflow:hidden;
  display:none;
}
.progress > span {
  display:block; height:100%; width:0%;
  background:rgba(255,255,255,.9);
  transition:width .2s ease;
}
.pct {
  position:absolute; top:6px; right:10px;
  font-size:11px; color:#fff; text-shadow:0 1px 2px rgba(0,0,0,.85);
}
.topshade{
  position:absolute; left:0; right:0; top:0; height:34px;
  background:linear-gradient(to bottom, rgba(0,0,0,.6), rgba(0,0,0,0));
  display:none;
}
.card.loading .topshade{ display:block; }
.topshade .pct{ display:block; } /* forza visibilità dentro la banda */
.card.loading .progress { display:block; }
.card.loading .pct { display:block; }
.card.loading .name { opacity:.85; }
.badges{
  position:absolute; top:8px; left:8px; display:flex; gap:6px; z-index:2;
}
.badge{
  font-size:12px; line-height:1; padding:4px 6px; border-radius:999px;
  background:rgba(0,0,0,.55); color:#fff; text-shadow:0 1px 1px rgba(0,0,0,.5);
  backdrop-filter:saturate(120%) blur(2px);
}
.card img { display:block; width:100%; height:100%; object-fit:cover; transition:transform .2s ease; background: transparent; }
.card:hover img { transform: scale(1.03); }
.name{
  position:absolute; left:0; right:0; bottom:0;
  padding:6px 8px; font-size:12px; color:#fff;
  background:linear-gradient(to top, rgba(0,0,0,.65), rgba(0,0,0,0));
  text-shadow:0 1px 2px rgba(0,0,0,.85);
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}

/* Folder cards: darken background and subtly blur GIF to make text stand out */
#folders .card::after{
  content:"";
  position:absolute;
  inset:0;
  background:rgba(0,0,0,.35);
  z-index:1; /* sits above image, below text */
}

#folders .card img{
  filter: blur(1.5px) brightness(0.78);
  -webkit-filter: blur(1.5px) brightness(0.78);
}

@media (prefers-reduced-motion: reduce){
  #folders .card img{
    filter: brightness(0.78);
    -webkit-filter: brightness(0.78);
  }
}

/* Disable scale hover on folder GIFs */
#folders .card:hover img{ transform:none; }

/* Folder title: larger and centered, with stronger backdrop */
#folders .card .name{
  z-index:2;
  text-align:center;
  font-size:15px;
  font-weight:700;
  padding:8px 10px;
  background:linear-gradient(to top, rgba(0,0,0,.75), rgba(0,0,0,.25));
}
/* Lightbox */
.lightbox { position:fixed; inset:0; background:rgba(0,0,0,.92);
  display:none; align-items:center; justify-content:center; z-index:9999; }
.lightbox.open { display:flex; }
.spinner {
  position:absolute;
  top:50%; left:50%;
  width:48px; height:48px;
  margin:-24px 0 0 -24px;
  border:4px solid rgba(255,255,255,0.2);
  border-top-color:#fff;
  border-radius:50%;
  animation: spin 0.8s linear infinite;
  display:none;
  z-index:10000;
}
.lightbox.loading .spinner {
  display:block;
}
@keyframes spin {
  from { transform:rotate(0deg); }
  to { transform:rotate(360deg); }
}
.lb-img-wrap { max-width:95vw; max-height:90vh; }
.lb-img-wrap img { max-width:100%; max-height:90vh; display:block; margin:0 auto; }
.close,.nav { position:absolute; top:50%; transform:translateY(-50%);
  background:rgba(0,0,0,.5); border:1px solid rgba(255,255,255,.2);
  color:#fff; width:44px; height:44px; border-radius:50%;
  display:grid; place-items:center; cursor:pointer; user-select:none; }
.close { top:24px; right:24px; transform:none; }
.nav.prev { left:18px; }
.nav.next { right:18px; }
.counter { position:absolute; bottom:16px; left:50%; transform:translateX(-50%); font-size:14px; color:#bbb; }
button { all:unset; }
@media (hover:none){ .card:hover img{transform:none;} }
.collapsed { display:none !important; }
</style>
</head>
<body>
<?php
  // Config pubblica per il client: non includere segreti
  $publicCfg = $cfg;
  unset($publicCfg['auth_password']);   // rimuovi password/hash
?>
<script>
  window.__GALLERY_CFG__ = <?php echo json_encode($publicCfg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?>;
  window.__CUR_DIR__ = <?php echo json_encode($relDir, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?>;
  window.__SELF__ = <?php echo json_encode($self, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?>;
  window.__AUTH_MODE__ = <?php echo (int)$cfg['auth_mode']; ?>;
  window.__CSRF__ = <?php echo json_encode($CSRF_TOKEN, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?>;
</script>
<header>
  <div class="hdr">
    <nav class="crumbs" aria-label="Breadcrumb">
      <a href="<?php echo htmlspecialchars($self,ENT_QUOTES); ?>" title="Home">🏠 Home</a>
      <?php
        if (!$isRoot) {
          $acc = '';
          foreach (explode('/', $relDir) as $i => $seg) {
            $acc = ltrim($acc . '/' . $seg, '/');
            if (is_excluded_dir($seg, $acc)) break;
            echo '<span class="sep">›</span><a href="'.$self.'?dir='.rawurlencode($acc).'">'.htmlspecialchars($seg,ENT_QUOTES).'</a>';
          }
        }
      ?>
    </nav>
    <div class="title"><?php echo htmlspecialchars($cfg['title'],ENT_QUOTES); ?></div>
    <span style="display:flex; gap:12px; margin-left:auto; align-items:center;">
      <?php if ((int)$cfg['auth_mode'] === 0): ?>
        <a href="#" id="purgeBtn" style="color:inherit;" title="Elimina anteprime e GIF della cartella corrente">Pulisci cache</a>
      <?php elseif ((int)$cfg['auth_mode'] >= 1 && auth_ok()): ?>
        <a href="#" id="purgeBtn" style="color:inherit;" title="Elimina anteprime e GIF della cartella corrente">Pulisci cache</a>
        <a href="#" id="mkHash" style="color:inherit;">Crea hash</a>
        <a href="?logout=1" style="color:inherit;">Esci</a>
      <?php elseif ((int)$cfg['auth_mode'] >= 1 && !auth_ok()): ?>
        <a href="#" id="btnLogin" style="color:inherit;">Accedi</a>
      <?php endif; ?>
    </span>
  </div>
</header>

<?php if (!empty($subdirs)): ?>
<section style="padding:0 var(--gap);">
  <h3 id="foldersToggle" style="margin:6px 0 8px 0; font-size:14px; font-weight:600; cursor:pointer; user-select:none;">
    <span id="foldersCaret" aria-hidden="true">▾</span> Cartelle
  </h3>
  <div class="grid" id="folders">
    <?php foreach ($subdirs as $sd): ?>
      <a class="card" href="<?php echo htmlspecialchars($self,ENT_QUOTES); ?>?dir=<?php echo rawurlencode($sd['rel']); ?>" data-rel="<?php echo htmlspecialchars($sd['rel'],ENT_QUOTES); ?>" data-has-imgs="<?php echo ($sd['imgs']>0)?'1':'0'; ?>" style="display:block; text-decoration:none; color:inherit;">
        <img
          src="<?php echo $sd['gif'] ? htmlspecialchars($sd['gif'],ENT_QUOTES) : ($sd['placeholder'] ? $sd['placeholder'] : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='); ?>"
          alt="<?php echo htmlspecialchars($sd['name'],ENT_QUOTES); ?>"
          data-gif="<?php echo htmlspecialchars($sd['gif'] ?? '',ENT_QUOTES); ?>"
          loading="lazy" decoding="async">
        <div class="badges">
          <?php if ($sd['dirs'] > 0): ?><span class="badge" title="Cartelle">📁 <?php echo (int)$sd['dirs']; ?></span><?php endif; ?>
          <?php if ($sd['imgs'] > 0): ?><span class="badge" title="Foto">🖼 <?php echo (int)$sd['imgs']; ?></span><?php endif; ?>
        </div>
        <div class="name" title="<?php echo htmlspecialchars($sd['name'],ENT_QUOTES); ?>"><?php echo htmlspecialchars($sd['name'],ENT_QUOTES); ?></div>
        <div class="progress"><span></span></div>
        <div class="topshade"><div class="pct">0%</div></div>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if (empty($items) && empty($subdirs)): ?>
<p style="padding:16px">Nessuna immagine trovata.</p>
<?php elseif (!empty($items)): ?>
<h3 style="margin:6px 0 0 0; padding:0 var(--gap); font-size:14px; font-weight:600;">Foto<?php if (count($items)>0) echo ' ('.count($items).')'; ?></h3>
<main class="grid" id="grid">
<?php foreach ($items as $idx => $it): ?>
  <figure class="card" data-idx="<?php echo $idx; ?>">
    <img
      src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="
      data-thumb="<?php echo $it['thumb']; ?>"
      data-name="<?php echo $it['name']; ?>"
      data-has-thumb="<?php echo (int)$it['hasThumb']; ?>"
      alt="<?php echo $it['name']; ?>"
      loading="lazy"
      decoding="async"
    >
    <figcaption class="name" title="<?php echo $it['name']; ?>"><?php echo $it['name']; ?></figcaption>
  </figure>
<?php endforeach; ?>
</main>
<?php endif; ?>

<div class="lightbox" id="lightbox" aria-hidden="true">
  <div class="spinner" id="spinner" aria-hidden="true"></div>
  <button class="close" id="btnClose">✕</button>
  <button class="nav prev" id="btnPrev">❮</button>
  <div class="lb-img-wrap"><img id="lbImg" alt=""></div>
  <button class="nav next" id="btnNext">❯</button>
  <div class="counter" id="counter"></div>
</div>

<script>
(() => {
  const items = <?php echo json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  const grid = document.getElementById('grid');
  const lb = document.getElementById('lightbox');
  const spinner = document.getElementById('spinner');
  const lbImg = document.getElementById('lbImg');
  const counter = document.getElementById('counter');
  const btnClose = document.getElementById('btnClose');
  const btnPrev = document.getElementById('btnPrev');
  const btnNext = document.getElementById('btnNext');
  const folders = document.getElementById('folders');
  let idx = -1;

  // Purge cache corrente (ricorsivo dalla root)
  const purgeBtn = document.getElementById('purgeBtn');
  purgeBtn?.addEventListener('click', (e)=>{
    e.preventDefault();
    const isRoot = !window.__CUR_DIR__;
    const msg = isRoot
      ? 'Eliminare TUTTE le anteprime e GIF di TUTTE le cartelle?'
      : 'Eliminare le anteprime e la GIF della cartella corrente?';
    if (!confirm(msg)) return;

    const url = withDir(`${window.__SELF__}?purge_cache=1&t=${Date.now()}`);
    const body = { recursive: isRoot ? 1 : 0 };
    if ((window.__AUTH_MODE__ || 0) >= 1) { body.csrf = window.__CSRF__ || ''; }

    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      cache: 'no-store',
      body: JSON.stringify(body)
    })
    .then(r=>r.json())
    .then(d=>{
      if (d && d.ok) {
        alert(`Cache pulita.\nCartelle toccate: ${d.purged.dirs}\nThumbs: ${d.purged.thumbs}\nGIF: ${d.purged.gifs}`);
        location.reload();
      } else {
        alert('Errore nella pulizia cache');
      }
    }).catch(()=>alert('Errore nella pulizia cache'));
  });

  // Collassa/espandi “Cartelle”
  const toggle = document.getElementById('foldersToggle');
  const caret  = document.getElementById('foldersCaret');
  const keyColl = `mpg_fc_${window.__CUR_DIR__ || ''}`;
  function applyFoldState(){
    const st = localStorage.getItem(keyColl) || 'open';
    if (st==='closed'){ folders?.classList.add('collapsed'); if(caret) caret.textContent='▸'; }
    else { folders?.classList.remove('collapsed'); if(caret) caret.textContent='▾'; }
  }
  applyFoldState();
  toggle?.addEventListener('click', ()=>{
    folders?.classList.toggle('collapsed');
    const closed = folders && folders.classList.contains('collapsed');
    if (caret) caret.textContent = closed ? '▸' : '▾';
    localStorage.setItem(keyColl, closed ? 'closed' : 'open');
  });

  // Utility per endpoint con dir
  function withDir(url) {
    const d = window.__CUR_DIR__ || '';
    if (!d) return url;
    return url + (url.includes('?') ? '&' : '?') + 'dir=' + encodeURIComponent(d);
  }

  // Handler Login (auth_mode >= 1)
  const loginBtn = document.getElementById('btnLogin');
  loginBtn?.addEventListener('click', (e)=>{
    e.preventDefault();
    const pwd = prompt('Inserisci la password per le funzioni extra:');
    if (!pwd) return;
    fetch(`${window.__SELF__}?do_login=1`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ pass: pwd })
    })
    .then(r=>r.json())
    .then(d=>{
      if (d && d.ok) { location.reload(); }
      else { alert('Accesso negato'); }
    })
    .catch(()=>alert('Errore di autenticazione'));
  });

  // Handler Crea hash
  const mkHashLink = document.getElementById('mkHash');
  mkHashLink?.addEventListener('click', (e) => {
    e.preventDefault();
    const pwd = prompt('Inserisci la nuova password da trasformare in hash (non verrà salvata automaticamente):');
    if (!pwd) return;
    fetch(withDir(`${window.__SELF__}?mkhash=${encodeURIComponent(pwd)}`), { cache:'no-store' })
      .then(r=>r.json())
      .then(d=>{
        if (d && d.ok && d.hash) {
          navigator.clipboard?.writeText(d.hash).catch(()=>{});
          alert('Hash generato e copiato negli appunti:\n\n' + d.hash + '\n\nIncollalo in ".config.json" su "auth_password".');
        } else {
          alert('Errore nella generazione hash');
        }
      }).catch(()=>{ alert('Errore'); });
  });

  // Lightbox
  function openAt(i){
    idx = (i + items.length) % items.length;
    lb.classList.add('open','loading');
    spinner.style.display = 'block';
    const src = items[idx].src;
    lbImg.src = '';
    lbImg.alt = items[idx].name;
    counter.textContent = (idx+1) + ' / ' + items.length;
    document.body.style.overflow = 'hidden';
    const tmp = new Image();
    tmp.onload = () => {
      lbImg.src = src;
      lb.classList.remove('loading');
      spinner.style.display = 'none';
    };
    tmp.onerror = () => {
      lb.classList.remove('loading');
      spinner.style.display = 'none';
    };
    tmp.src = src;
  }
  function closeLb(){ lb.classList.remove('open'); document.body.style.overflow = ''; lbImg.src = ''; }
  function prev(){ openAt(idx-1); }
  function next(){ openAt(idx+1); }

  grid?.addEventListener('click', e => {
    const fig = e.target.closest('.card');
    if (!fig) return;
    if (e.altKey) {
      const img = fig.querySelector('img');
      fetch(withDir(`${window.__SELF__}?diag=${encodeURIComponent(img.dataset.name)}`), {cache:'no-store'})
        .then(r=>r.json()).then(d=>console.table(d));
      e.preventDefault(); return;
    }
    const i = parseInt(fig.dataset.idx, 10);
    if (!Number.isNaN(i)) openAt(i);
  });
  btnClose.addEventListener('click', closeLb);
  btnPrev.addEventListener('click', prev);
  btnNext.addEventListener('click', next);
  lb.addEventListener('click', e => { if (e.target === lb) closeLb(); });
  window.addEventListener('keydown', e => {
    if (!lb.classList.contains('open')) return;
    if (e.key === 'Escape') closeLb();
    else if (e.key === 'ArrowLeft') prev();
    else if (e.key === 'ArrowRight') next();
  });

  // === Generazione asincrona GIF per cartelle ===
  if (folders) {
    const cards = Array.from(folders.querySelectorAll('a.card'));
    const pollers = new Map(); // rel -> interval id

    function startPoll(rel, card) {
      if (pollers.has(rel)) return;
      const bar = card.querySelector('.progress > span');
      const pct = card.querySelector('.pct');
      card.classList.add('loading');
      // reset UI
      if (bar) bar.style.width = '1%';
      if (pct) pct.textContent = '0%';

      const iv = setInterval(() => {
        fetch(withDir(`${window.__SELF__}?gif_progress=${encodeURIComponent(rel)}&t=${Date.now()}`), { cache:'no-store' })
          .then(r=>r.ok ? r.json() : null)
          .then(d=>{
            if (!d || !d.ok) return;
            if (!d.exists) return; // progress file non ancora creato
            const total = d.total || 0, done = d.done || 0;
            if (total > 0) {
              const perc = Math.max(0, Math.min(100, Math.round((done / total) * 100)));
              if (bar) bar.style.width = (perc || 1) + '%';
              if (pct) pct.textContent = perc + '%';
              if (done >= total) {
                clearInterval(iv); pollers.delete(rel);
                // non rimuovere subito; lascia che il fetch make_gif imposti la GIF
              }
            }
          }).catch(()=>{});
      }, 350);
      pollers.set(rel, iv);
    }

    cards.forEach(card => {
      const img = card.querySelector('img');
      const rel = card.dataset.rel || '';
      const hasImgs = card.dataset.hasImgs === '1';
      if (img.dataset.gif && img.dataset.gif.length) return; // già pronto
      if (!hasImgs) return; // niente foto: non generare gif
      startPoll(rel, card);
      fetch(withDir(`${window.__SELF__}?make_gif=${encodeURIComponent(rel)}&t=${Date.now()}`), { cache:'no-store' })
        .then(r=>r.json())
        .then(d=>{
          if (d && d.ok && d.gif) {
            img.src = d.gif + '&cb=' + Date.now();
            card.classList.remove('loading');
          }
        })
        .catch(()=>{})
        .finally(()=>{
          const iv = pollers.get(rel);
          if (iv) { clearInterval(iv); pollers.delete(rel); }
          const bar = card.querySelector('.progress > span'); if (bar) bar.style.width = '100%';
          const pct = card.querySelector('.pct'); if (pct) pct.textContent = '100%';
          //setTimeout(()=>{ card.classList.remove('loading'); }, 300);
        });
    });
  }

  // === Generazione asincrona thumbnails ===
  const CONCURRENCY = 10;
  const imgs = Array.from(document.querySelectorAll('.card img'));
  const queue = imgs.filter(img => img.dataset.hasThumb === '0').map(img => ({
    name: img.dataset.name,
    img
  }));

  // Lazy-load per thumbs già esistenti
  const io = ('IntersectionObserver' in window) ? new IntersectionObserver((entries, obs) => {
    for (const en of entries) {
      if (!en.isIntersecting) continue;
      const im = en.target;
      if (im.dataset.hasThumb === '1' && im.dataset.thumb) {
        // carica solo quando visibile
        if (!im.dataset.loaded) {
          im.src = im.dataset.thumb;
          im.dataset.loaded = '1';
        }
        obs.unobserve(im);
      }
    }
  }, { rootMargin: '200px' }) : null;
  
  // attacca l'observer a tutte le immagini
  imgs.forEach(img => { io && io.observe(img); });

  let active = 0;
  function nextJob() {
    if (queue.length === 0) return;
    if (active >= CONCURRENCY) return;
    const job = queue.shift();
    active++;
    fetch(withDir(`${window.__SELF__}?make_thumb=${encodeURIComponent(job.name)}`), { cache: 'no-store' })
      .then(r => r.json())
      .then(data => {
        if (data && data.ok && data.thumb) {
          job.img.src = data.thumb + `?v=${Date.now()}`;
          job.img.dataset.hasThumb = '1';
          if (data && data.exif === false) { job.img.setAttribute('data-exif-missing','1'); }
        } else if (data && data.error) {
          console.warn('Thumb fail', job.name, data.error);
        }
      })
      .catch(() => {})
      .finally(() => {
        active--;
        nextJob();
      });

    // avvia altri job fino alla concorrenza
    while (active < CONCURRENCY && queue.length > 0) {
      nextJob();
    }
  }
  // avvio
  for (let i = 0; i < CONCURRENCY; i++) nextJob();
})();
</script>
</body>
</html>
