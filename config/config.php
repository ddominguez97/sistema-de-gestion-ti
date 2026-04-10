<?php
/**
 * config.php — Configuración central del Sistema NAGSA
 * Incluir desde cualquier módulo con:
 *   require_once __DIR__ . '/../../config/config.php';  (desde modules/xxx/)
 *   require_once __DIR__ . '/config/config.php';        (desde raíz)
 */

// ── Ruta raíz del sistema ────────────────────────────────────────────────────
define('ROOT_PATH',   dirname(__DIR__));
define('CONFIG_FILE', ROOT_PATH . '/sistemas_settings.json');
define('MODULES_PATH', ROOT_PATH . '/modules');

// ── Cargar configuración ─────────────────────────────────────────────────────
if (!file_exists(CONFIG_FILE)) {
    http_response_code(500);
    die(json_encode(['error' => 'No existe sistemas_settings.json. Configura primero desde Sistema_admin.php']));
}
$cfg = json_decode(file_get_contents(CONFIG_FILE), true);
if (!$cfg) {
    http_response_code(500);
    die(json_encode(['error' => 'sistemas_settings.json está corrupto.']));
}

// ── Conexión PDO ─────────────────────────────────────────────────────────────
function getDB() {
    global $cfg;
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host='.$cfg['db_host'].';port='.$cfg['db_port'].';dbname='.$cfg['db_name'].';charset='.($cfg['db_charset'] ?? 'utf8mb4');
    try {
        $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['error' => $e->getMessage()]));
    }
    return $pdo;
}

// ── Conexión PDO — SQL Server Express (BD local del sistema) ────────────────
function getLocalDB() {
    global $cfg;
    static $pdo = null;
    if ($pdo) return $pdo;

    $local = $cfg['local_db'] ?? [];
    $server  = $local['server']   ?? 'localhost\SQLEXPRESS';
    $dbname  = $local['database'] ?? 'SistemaNG';
    $user    = $local['user']     ?? '';
    $pass    = $local['password'] ?? '';

    try {
        if ($user) {
            // SQL Server Authentication
            $dsn = "sqlsrv:Server={$server};Database={$dbname}";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } else {
            // Windows Authentication
            $dsn = "sqlsrv:Server={$server};Database={$dbname}";
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['error' => 'Error SQL Express: ' . $e->getMessage()]));
    }
    return $pdo;
}

// ── URL base para módulos (relativa al navegador) ────────────────────────────
// Detecta automáticamente la URL base del sistema
define('BASE_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\'));

// ── Constantes de módulos ────────────────────────────────────────────────────
define('MODULE_ETIQUETAS_URL', BASE_URL . '/modules/etiquetas');
define('MODULE_ACTAS_URL',     BASE_URL . '/modules/actas');
define('MODULE_REPORTES_URL',  BASE_URL . '/modules/reportes');

// ── Módulos — 3 estados: activo | pruebas | deshabilitado ───────────────────
function getEstadoModulo($modulo) {
    global $cfg;
    return $cfg['modulos'][$modulo] ?? 'activo';
}

function checkModulo($modulo) {
    global $cfg;
    $estado = getEstadoModulo($modulo);
    if($estado === 'activo') return;

    $proto     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base      = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
    $dashboard = $proto . '://' . $_SERVER['HTTP_HOST'] . $base . '/index.php';

    // Pruebas: solo admin/desarrollador puede acceder
    if($estado === 'pruebas') {
        if(!empty($_SESSION['admin_ok'])) return;
        $icono   = '🧪';
        $titulo  = 'Módulo en pruebas';
        $mensaje = 'Este módulo está en desarrollo y solo es accesible para el equipo de TI.<br>Pronto estará disponible para todos.';
    } else {
        // Deshabilitado: nadie pasa
        $icono   = '🔧';
        $titulo  = 'Estamos trabajando en esto';
        $mensaje = 'Este módulo se encuentra en construcción.<br>Estamos trabajando para ofrecerte nuevas funcionalidades.<br><br>Gracias por tu paciencia.';
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $titulo . '</title>';
    brandingCSS('../../');
    echo '<style>*{box-sizing:border-box;margin:0;padding:0;}body{font-family:Arial,sans-serif;background:var(--bg-body);color:var(--text-main);min-height:100vh;display:flex;align-items:center;justify-content:center;}
    .maint{text-align:center;padding:40px;max-width:480px;}
    .icon{font-size:64px;margin-bottom:20px;}
    .title{font-size:22px;font-weight:800;color:var(--color-principal);margin-bottom:10px;}
    .msg{font-size:14px;color:var(--text-muted);margin-bottom:28px;line-height:1.6;}
    .btn{padding:10px 24px;border-radius:8px;background:var(--color-principal);color:#fff;text-decoration:none;font-size:13px;font-weight:700;}
    </style></head>';
    echo '<body><div class="maint">';
    echo '<div class="icon">' . $icono . '</div>';
    echo '<div class="title">' . $titulo . '</div>';
    echo '<div class="msg">' . $mensaje . '</div>';
    echo '<a href="' . $dashboard . '" class="btn">&larr; Volver al Dashboard</a>';
    echo '</div></body></html>';
    exit;
}

// ── Personalización ──────────────────────────────────────────────────────────
function getBranding() {
    global $cfg;
    $logo = $cfg['empresa_logo'] ?? 'nagsa_logo.png';
    // Agregar timestamp para evitar caché del navegador
    $logo_path = ROOT_PATH . '/' . $logo;
    $ts = file_exists($logo_path) ? filemtime($logo_path) : time();
    return [
        'nombre' => $cfg['empresa_nombre'] ?? 'NAGSA',
        'color'  => $cfg['empresa_color']  ?? '#E05816',
        'logo'   => $logo . '?v=' . $ts,
        'tema'   => $cfg['empresa_tema']   ?? 'oscuro',
    ];
}

// ── Inyectar CSS de personalización ──────────────────────────────────────────
function brandingCSS($logo_prefix = '') {
    $b = getBranding();
    $color = htmlspecialchars($b['color']);
    $logo  = htmlspecialchars($logo_prefix . $b['logo']);
    $tema  = $b['tema'];

    // Variables según tema
    if($tema === 'claro') {
        $bg_body    = '#f0f4f8';
        $bg_card    = '#ffffff';
        $bg_sidebar = '#ffffff';
        $bg_topbar  = '#ffffff';
        $bg_input   = '#f9f9f9';
        $bg_hover   = '#f0f0f0';
        $bg_dark2   = '#f0f0f0';
        $bg_dark3   = '#e8e8e8';
        $text_main  = '#1a1a1a';
        $text_sub   = '#555555';
        $text_muted = '#888888';
        $border     = '#dddddd';
        $border2    = '#cccccc';
    } else {
        $bg_body    = '#1a1a1a';
        $bg_card    = '#1e1e1e';
        $bg_sidebar = '#1e1e1e';
        $bg_topbar  = '#1e1e1e';
        $bg_input   = '#252525';
        $bg_hover   = '#2a2a2a';
        $bg_dark2   = '#2a2a2a';
        $bg_dark3   = '#333333';
        $text_main  = '#ffffff';
        $text_sub   = '#aaaaaa';
        $text_muted = '#666666';
        $border     = '#333333';
        $border2    = '#444444';
    }

    echo "<style>
:root {
    --color-principal: {$color};
    --color-hover: {$color}cc;
    --bg-body:    {$bg_body};
    --bg-card:    {$bg_card};
    --bg-sidebar: {$bg_sidebar};
    --bg-topbar:  {$bg_topbar};
    --bg-input:   {$bg_input};
    --bg-hover:   {$bg_hover};
    --bg-dark2:   {$bg_dark2};
    --bg-dark3:   {$bg_dark3};
    --text-main:  {$text_main};
    --text-sub:   {$text_sub};
    --text-muted: {$text_muted};
    --border:     {$border};
    --border2:    {$border2};
}
body { background: var(--bg-body) !important; color: var(--text-main) !important; }
</style>
";
    echo "<script>
window.NAGSA_COLOR  = '{$color}';
window.NAGSA_LOGO   = '{$logo}';
window.NAGSA_NOMBRE = '" . htmlspecialchars($b['nombre']) . "';
window.NAGSA_TEMA   = '{$tema}';
window.NAGSA_URL    = '" . htmlspecialchars(rtrim($cfg['base_url'] ?? '', '/')) . "';
</script>
";
}
?>

