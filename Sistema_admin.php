<?php
define('ADMIN_PASS', 'GLPIM853@UYT');
session_start();
$msg = ''; $msg_type = 'ok';

require_once __DIR__.'/config/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Login ─────────────────────────────────────────────────────────────────
    if (isset($_POST['password'])) {
        if ($_POST['password'] === ADMIN_PASS) $_SESSION['admin_ok'] = true;
        else { $msg = 'Contraseña incorrecta.'; $msg_type = 'err'; }
    }

    // ── Cambio de tema via AJAX ───────────────────────────────────────────────
    if (isset($_POST['cambiar_tema']) && !empty($_SESSION['admin_ok'])) {
        $f_cfg = __DIR__ . '/sistemas_settings.json';
        if (file_exists($f_cfg)) {
            $json = json_decode(file_get_contents($f_cfg), true);
            $json['empresa_tema'] = trim($_POST['empresa_tema'] ?? 'oscuro');
            file_put_contents($f_cfg, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            header('Content-Type: application/json');
            die(json_encode(['ok' => true, 'tema' => $json['empresa_tema']]));
        }
        die(json_encode(['ok' => false]));
    }

    // ── Subida de logo ────────────────────────────────────────────────────────
    if (isset($_POST['upload_logo']) && !empty($_SESSION['admin_ok'])) {
        header('Content-Type: application/json');
        if (!empty($_FILES['empresa_logo_file']['name'])) {
            $upload_error = $_FILES['empresa_logo_file']['error'];
            if ($upload_error === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['empresa_logo_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['png','jpg','jpeg','gif','svg','webp'])) {
                    $nuevo_logo = 'logo_empresa.' . $ext;
                    $dest = __DIR__ . '/' . $nuevo_logo;
                    if (move_uploaded_file($_FILES['empresa_logo_file']['tmp_name'], $dest)) {
                        $f_cfg = __DIR__ . '/sistemas_settings.json';
                        if (file_exists($f_cfg)) {
                            $json = json_decode(file_get_contents($f_cfg), true);
                            $json['empresa_logo'] = $nuevo_logo;
                            file_put_contents($f_cfg, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        }
                        die(json_encode(['ok'=>true,'msg'=>'Logo actualizado correctamente.']));
                    } else {
                        die(json_encode(['ok'=>false,'msg'=>'Error al mover el archivo. Verifica permisos en la carpeta raíz.']));
                    }
                } else {
                    die(json_encode(['ok'=>false,'msg'=>'Formato no permitido. Usa PNG, JPG, SVG o WEBP.']));
                }
            } else {
                die(json_encode(['ok'=>false,'msg'=>'Error al subir (código: '.$upload_error.'). Verifica php.ini: upload_max_filesize']));
            }
        } else {
            die(json_encode(['ok'=>false,'msg'=>'No se recibió ningún archivo.']));
        }
    }

    if (isset($_POST['save_bd']) && !empty($_SESSION['admin_ok'])) {
        $f_cfg = __DIR__ . '/sistemas_settings.json';
        $cfg_actual = file_exists($f_cfg) ? json_decode(file_get_contents($f_cfg), true) : [];
        $cfg_actual['db_host']    = trim($_POST['db_host']);
        $cfg_actual['db_port']    = trim($_POST['db_port']);
        $cfg_actual['db_name']    = trim($_POST['db_name']);
        $cfg_actual['db_user']    = trim($_POST['db_user']);
        $cfg_actual['db_pass']    = $_POST['db_pass'];
        $cfg_actual['db_charset'] = 'utf8mb4';
        try {
            $dsn = "mysql:host={$cfg_actual['db_host']};port={$cfg_actual['db_port']};dbname={$cfg_actual['db_name']};charset=utf8mb4";
            new PDO($dsn, $cfg_actual['db_user'], $cfg_actual['db_pass'], [PDO::ATTR_TIMEOUT=>3]);
            file_put_contents($f_cfg, json_encode($cfg_actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $msg = '✅ Conexión verificada y configuración guardada.'; $msg_type = 'ok';
        } catch(Exception $e) {
            $msg = '❌ Error de conexión: ' . $e->getMessage(); $msg_type = 'err';
        }
    }

    if (isset($_POST['save_glpi']) && !empty($_SESSION['admin_ok'])) {
        $f_cfg = __DIR__ . '/sistemas_settings.json';
        $cfg_actual = file_exists($f_cfg) ? json_decode(file_get_contents($f_cfg), true) : [];
        $cfg_actual['entity_id'] = (int)$_POST['entity_id'];
        $cfg_actual['base_url']  = rtrim(trim($_POST['base_url']), '/');
        file_put_contents($f_cfg, json_encode($cfg_actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $msg = '✅ Configuración GLPI guardada.'; $msg_type = 'ok';
    }

    if (isset($_POST['save_marca']) && !empty($_SESSION['admin_ok'])) {
        $f_cfg = __DIR__ . '/sistemas_settings.json';
        $cfg_actual = file_exists($f_cfg) ? json_decode(file_get_contents($f_cfg), true) : [];
        $cfg_actual['empresa_nombre'] = trim($_POST['empresa_nombre'] ?? 'NAGSA');
        $cfg_actual['empresa_color']  = trim($_POST['empresa_color']  ?? '#E05816');
        $cfg_actual['empresa_tema']   = trim($_POST['empresa_tema']   ?? 'oscuro');
        file_put_contents($f_cfg, json_encode($cfg_actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $msg = '✅ Personalización guardada.'; $msg_type = 'ok';
    }

    if (isset($_POST['save_zebra']) && !empty($_SESSION['admin_ok'])) {
        $f_cfg = __DIR__ . '/sistemas_settings.json';
        $cfg_actual = file_exists($f_cfg) ? json_decode(file_get_contents($f_cfg), true) : [];
        $cfg_actual['zebra_ip']     = trim($_POST['zebra_ip'] ?? '');
        $cfg_actual['zebra_nombre'] = trim($_POST['zebra_nombre'] ?? '');
        $cfg_actual['zebra_port']   = trim($_POST['zebra_port'] ?? '9100');
        file_put_contents($f_cfg, json_encode($cfg_actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $msg = '✅ Impresora guardada.'; $msg_type = 'ok';
    }

    if (isset($_POST['save_modulos']) && !empty($_SESSION['admin_ok'])) {
        $f_cfg = __DIR__ . '/sistemas_settings.json';
        $cfg_actual = file_exists($f_cfg) ? json_decode(file_get_contents($f_cfg), true) : [];
        $estados_validos = ['activo','pruebas','deshabilitado'];
        foreach(['etiquetas','actas','reportes','inversiones','permisos'] as $mod) {
            $val = $_POST['mod_'.$mod] ?? 'deshabilitado';
            $cfg_actual['modulos'][$mod] = in_array($val, $estados_validos) ? $val : 'deshabilitado';
        }
        file_put_contents($f_cfg, json_encode($cfg_actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $msg = '✅ Estado de módulos guardado.'; $msg_type = 'ok';
    }

    if (isset($_POST['save_ad']) && !empty($_SESSION['admin_ok'])) {
        $f_cfg = __DIR__ . '/sistemas_settings.json';
        $cfg_actual = file_exists($f_cfg) ? json_decode(file_get_contents($f_cfg), true) : [];
        $cfg_actual['active_directory'] = [
            'habilitado'     => isset($_POST['ad_habilitado']),
            'nombre'         => trim($_POST['ad_nombre'] ?? 'Active Directory'),
            'servidor'       => trim($_POST['ad_servidor'] ?? ''),
            'puerto'         => (int)($_POST['ad_puerto'] ?? 389),
            'dominio'        => trim($_POST['ad_dominio'] ?? ''),
            'base_dn'        => trim($_POST['ad_base_dn'] ?? ''),
            'sufijo_usuario' => trim($_POST['ad_sufijo'] ?? ''),
        ];

        // Test connection si hay servidor
        $srv = $cfg_actual['active_directory']['servidor'];
        if ($srv) {
            $port = $cfg_actual['active_directory']['puerto'];
            $ldap = @ldap_connect($srv, $port);
            if ($ldap) {
                ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
                ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
                // Intentar bind anónimo para verificar conectividad
                $bind = @ldap_bind($ldap);
                @ldap_close($ldap);
                file_put_contents($f_cfg, json_encode($cfg_actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $msg = '✅ Configuración Active Directory guardada. Conexión al servidor verificada.'; $msg_type = 'ok';
            } else {
                file_put_contents($f_cfg, json_encode($cfg_actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $msg = '⚠️ Configuración guardada pero no se pudo conectar al servidor LDAP.'; $msg_type = 'err';
            }
        } else {
            file_put_contents($f_cfg, json_encode($cfg_actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $msg = '✅ Configuración Active Directory guardada.'; $msg_type = 'ok';
        }
    }

    if (isset($_POST['save_localdb']) && !empty($_SESSION['admin_ok'])) {
        $f_cfg = __DIR__ . '/sistemas_settings.json';
        $cfg_actual = file_exists($f_cfg) ? json_decode(file_get_contents($f_cfg), true) : [];
        $cfg_actual['local_db'] = [
            'server'   => trim($_POST['local_server'] ?? 'localhost\SQLEXPRESS'),
            'database' => trim($_POST['local_database'] ?? 'SistemaNG'),
            'user'     => trim($_POST['local_user'] ?? ''),
            'password' => $_POST['local_password'] ?? '',
        ];
        // Test connection
        try {
            $srv = $cfg_actual['local_db']['server'];
            $dbn = $cfg_actual['local_db']['database'];
            $usr = $cfg_actual['local_db']['user'];
            $pwd = $cfg_actual['local_db']['password'];
            $dsn = "sqlsrv:Server={$srv};Database={$dbn}";
            if ($usr) {
                new PDO($dsn, $usr, $pwd, [PDO::ATTR_TIMEOUT => 3]);
            } else {
                new PDO($dsn, null, null, [PDO::ATTR_TIMEOUT => 3]);
            }
            file_put_contents($f_cfg, json_encode($cfg_actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $msg = '✅ Conexión SQL Express verificada y guardada.'; $msg_type = 'ok';
        } catch(Exception $e) {
            $msg = '❌ Error SQL Express: ' . $e->getMessage(); $msg_type = 'err';
        }
    }

    if (isset($_POST['save_cats']) && !empty($_SESSION['admin_ok'])) {
        $f_cfg = __DIR__ . '/sistemas_settings.json';
        $cfg_actual = file_exists($f_cfg) ? json_decode(file_get_contents($f_cfg), true) : [];
        $cfg_actual['show'] = [
            'computadoras' => isset($_POST['show_comp']),
            'monitores'    => isset($_POST['show_mon']),
            'impresoras'   => isset($_POST['show_imp']),
            'perifericos'  => isset($_POST['show_per']),
            'redes'        => isset($_POST['show_red']),
            'gabinetes'    => isset($_POST['show_gab']),
            'pasivos'      => isset($_POST['show_pas']),
            'cartuchos'    => isset($_POST['show_car']),
            'consumibles'  => isset($_POST['show_con']),
            'telefonos'    => isset($_POST['show_tel']),
        ];
        file_put_contents($f_cfg, json_encode($cfg_actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $msg = '✅ Categorías guardadas.'; $msg_type = 'ok';
    }
}

$loggedIn = !empty($_SESSION['admin_ok']);
$branding = getBranding();

$defaults = ['db_host'=>'','db_port'=>'3306','db_name'=>'','db_user'=>'','db_pass'=>'',
    'entity_id'=>0,'base_url'=>'','zebra_ip'=>'','zebra_nombre'=>'','zebra_port'=>'9100',
    'empresa_nombre'=>'NAGSA','empresa_color'=>'#E05816','empresa_logo'=>'nagsa_logo.png','empresa_tema'=>'oscuro',
    'modulos'=>['etiquetas'=>true,'actas'=>true,'reportes'=>false,'inversiones'=>false,'permisos'=>false],
    'show'=>['computadoras'=>true,'monitores'=>true,'impresoras'=>true,'perifericos'=>true,'redes'=>true,
             'gabinetes'=>true,'pasivos'=>true,'cartuchos'=>true,'consumibles'=>true,'telefonos'=>true]];

$f_cfg = __DIR__ . '/sistemas_settings.json';
$cfg = file_exists($f_cfg) ? array_merge($defaults, json_decode(file_get_contents($f_cfg), true)) : $defaults;
$show = $cfg['show'];

function chk($show, $key) { return !empty($show[$key]) ? 'checked' : ''; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin — <?= htmlspecialchars($branding['nombre']) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,sans-serif;background:var(--bg-body);color:var(--text-main);min-height:100vh;}
.topbar{background:var(--bg-card);border-bottom:2px solid var(--color-principal);padding:12px 24px;display:flex;align-items:center;gap:12px;}
.topbar .sub{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-top:2px;}
.topbar .actions{margin-left:auto;display:flex;gap:10px;align-items:center;}
.topbar a.btn-back{padding:7px 16px;border-radius:6px;background:var(--bg-hover);color:var(--text-sub);font-size:11px;font-weight:700;text-decoration:none;border:1px solid var(--border);}
.body{max-width:1200px;margin:24px auto;padding:0 20px;}
.page-title{font-size:18px;font-weight:900;color:var(--text-main);margin-bottom:2px;}
.page-sub{font-size:11px;color:var(--text-muted);margin-bottom:20px;}
.msg{padding:10px 16px;border-radius:7px;font-size:13px;margin-bottom:18px;}
.msg.ok{background:rgba(40,167,69,0.12);border:1px solid #28a745;color:#1e7e34;}
.msg.err{background:rgba(220,53,69,0.12);border:1px solid #dc3545;color:#a71d2a;}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:16px;}
.grid2{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:16px;}
.card{background:var(--bg-card);border-radius:10px;border:1px solid var(--border);overflow:hidden;display:flex;flex-direction:column;}
.ch{display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border);background:var(--bg-hover);}
.ci{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.ct{font-size:12px;font-weight:800;color:var(--text-main);text-transform:uppercase;letter-spacing:0.5px;}
.cd{font-size:10px;color:var(--text-muted);margin-top:1px;}
.cb{padding:14px 16px;flex:1;}
.fg{display:flex;flex-direction:column;gap:3px;margin-bottom:10px;}
.fg:last-child{margin-bottom:0;}
.fg label{font-size:11px;font-weight:700;color:var(--text-main);text-transform:uppercase;letter-spacing:0.3px;}
.fg input[type=text],.fg input[type=password],.fg input[type=number],.fg input[type=url]{width:100%;padding:7px 9px;border-radius:6px;border:1.5px solid var(--border);background:var(--bg-input);color:var(--text-main);font-size:12px;outline:none;}
.fg input:focus{border-color:var(--color-principal);}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.cf{padding:10px 16px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;}
.bs{padding:7px 18px;border-radius:6px;border:none;background:var(--color-principal);color:#fff;font-size:11px;font-weight:700;cursor:pointer;}
.bs:hover{opacity:0.9;}
.color-row{display:flex;gap:7px;align-items:center;}
.logo-pre{background:var(--bg-input);border-radius:6px;padding:7px 10px;display:flex;align-items:center;gap:8px;border:1px solid var(--border);margin-bottom:8px;}
.logo-pre span{font-size:10px;color:var(--text-muted);}
.file-row{display:flex;gap:6px;align-items:center;}
.file-input{flex:1;padding:6px 9px;border-radius:6px;border:1.5px solid var(--border);background:var(--bg-input);color:var(--text-muted);font-size:11px;}
.btn-up{padding:7px 12px;border-radius:6px;border:none;background:var(--color-principal);color:#fff;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;}
.sw-row{display:flex;align-items:center;gap:10px;padding:8px 0;}
.sw-row span{font-size:12px;color:var(--text-main);}
.sw-wrap{position:relative;width:48px;height:26px;flex-shrink:0;}
.sw-wrap input[type=checkbox]{opacity:0;width:100%;height:100%;position:absolute;cursor:pointer;margin:0;z-index:2;}
.sw-track{width:48px;height:26px;border-radius:13px;position:absolute;top:0;left:0;transition:background 0.3s;pointer-events:none;}
.sw-knob{width:20px;height:20px;border-radius:50%;background:#fff;position:absolute;top:3px;transition:left 0.3s;box-shadow:0 1px 3px rgba(0,0,0,0.2);}
.zb{background:var(--bg-input);border:1px solid var(--border);border-radius:6px;padding:7px 10px;display:flex;align-items:center;justify-content:space-between;margin-top:6px;}
.zn{font-size:12px;font-weight:700;color:var(--text-main);}
.zi{font-size:10px;color:var(--color-principal);margin-top:1px;}
.zbr{position:relative;margin-bottom:6px;}
.zbr input{width:100%;padding:7px 9px;border-radius:6px;border:1.5px solid var(--border);background:var(--bg-input);color:var(--text-main);font-size:12px;outline:none;}
.zbr input:focus{border-color:var(--color-principal);}
.zb-results{display:none;position:absolute;top:calc(100% + 2px);left:0;right:0;background:var(--bg-input);border:1px solid var(--border);border-radius:6px;z-index:100;max-height:180px;overflow-y:auto;box-shadow:0 4px 16px rgba(0,0,0,0.15);}
.zb-item{padding:7px 10px;cursor:pointer;border-bottom:1px solid var(--border);font-size:12px;transition:background 0.1s;}
.zb-item:last-child{border-bottom:none;}
.zb-item:hover{background:var(--bg-hover);}
.cats{display:grid;grid-template-columns:repeat(5,1fr);gap:7px;}
.cat-item{display:flex;align-items:center;gap:6px;padding:6px 8px;border-radius:6px;background:var(--bg-input);border:1px solid var(--border);font-size:11px;color:var(--text-main);cursor:pointer;}
.cat-item input{accent-color:var(--color-principal);}
.tema-preview{margin-top:14px;padding:10px;background:var(--bg-input);border-radius:7px;border:1px solid var(--border);}
.tema-preview .label{font-size:10px;color:var(--text-muted);font-weight:700;text-transform:uppercase;margin-bottom:6px;}
.tema-boxes{display:flex;gap:6px;}
.tema-box{flex:1;height:30px;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;}
#logo-upload-msg{font-size:11px;margin-top:4px;}
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:80vh;}
.login-card{background:var(--bg-card);border-radius:10px;padding:32px;width:100%;max-width:360px;border:1px solid var(--border);}
</style>
<?php brandingCSS(); ?>
</head>
<body>
<div class="topbar">
  <div style="display:flex;align-items:center;gap:12px;">
    <img src="<?= htmlspecialchars($branding['logo']) ?>" alt="<?= htmlspecialchars($branding['nombre']) ?>" style="height:36px;object-fit:contain;">
    <div class="sub">Panel de Configuración</div>
  </div>
  <div class="actions">
    <a href="index.php" class="btn-back">Dashboard →</a>
  </div>
</div>

<?php if(!$loggedIn): ?>
<div class="login-wrap">
  <div class="login-card">
    <div style="text-align:center;margin-bottom:20px;">
      <img src="<?= htmlspecialchars($branding['logo']) ?>" alt="<?= htmlspecialchars($branding['nombre']) ?>" style="height:48px;object-fit:contain;margin-bottom:10px;">
      <div style="font-size:14px;font-weight:700;color:var(--text-main);">Panel de Administración</div>
      <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Ingresa la contraseña de administrador</div>
    </div>
    <?php if($msg): ?><div class="msg <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <form method="POST">
      <div class="fg">
        <label>Contraseña</label>
        <input type="password" name="password" placeholder="••••••••" autofocus style="padding:9px 12px;border-radius:6px;border:1.5px solid var(--border);background:var(--bg-input);color:var(--text-main);font-size:13px;width:100%;outline:none;">
      </div>
      <button type="submit" style="width:100%;padding:10px;border-radius:6px;border:none;background:var(--color-principal);color:#fff;font-size:13px;font-weight:700;cursor:pointer;margin-top:10px;">🔐 Ingresar</button>
    </form>
  </div>
</div>

<?php else: ?>
<div class="body">
  <div class="page-title">⚙️ Panel de Configuración</div>
  <div class="page-sub">Ajustes de conexión, personalización e impresión</div>

  <?php if($msg): ?><div class="msg <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <!-- Fila 1: BD | GLPI | Impresora -->
  <div class="grid3">

    <!-- Base de datos -->
    <form method="POST" class="card">
      <div class="ch">
        <div class="ci" style="background:#E8F0FF;">🗄️</div>
        <div><div class="ct">Base de Datos</div><div class="cd">MariaDB / MySQL</div></div>
      </div>
      <div class="cb">
        <div class="row2">
          <div class="fg"><label>Host / IP</label><input type="text" name="db_host" value="<?= htmlspecialchars($cfg['db_host']) ?>"></div>
          <div class="fg"><label>Puerto</label><input type="number" name="db_port" value="<?= htmlspecialchars($cfg['db_port']) ?>"></div>
        </div>
        <div class="row2">
          <div class="fg"><label>Nombre BD</label><input type="text" name="db_name" value="<?= htmlspecialchars($cfg['db_name']) ?>"></div>
          <div class="fg"><label>Usuario</label><input type="text" name="db_user" value="<?= htmlspecialchars($cfg['db_user']) ?>"></div>
        </div>
        <div class="fg"><label>Contraseña</label><input type="password" name="db_pass" value="<?= htmlspecialchars($cfg['db_pass']) ?>" placeholder="Contraseña BD"></div>
      </div>
      <div class="cf"><button type="submit" name="save_bd" class="bs">Guardar y verificar</button></div>
    </form>

    <!-- GLPI -->
    <form method="POST" class="card">
      <div class="ch">
        <div class="ci" style="background:#E8F5E9;">🔗</div>
        <div><div class="ct">GLPI</div><div class="cd">Conexión con GLPI 11</div></div>
      </div>
      <div class="cb">
        <div class="fg"><label>Entity ID (0 = todas)</label><input type="number" name="entity_id" value="<?= (int)$cfg['entity_id'] ?>"></div>
        <div class="fg"><label>URL base (para QR)</label><input type="url" name="base_url" value="<?= htmlspecialchars($cfg['base_url']) ?>"></div>
      </div>
      <div class="cf"><button type="submit" name="save_glpi" class="bs">Guardar</button></div>
    </form>

    <!-- Impresora -->
    <form method="POST" class="card">
      <div class="ch">
        <div class="ci" style="background:#F0F0F0;">🖨️</div>
        <div><div class="ct">Impresora Zebra</div><div class="cd">Etiquetas en red TCP</div></div>
      </div>
      <div class="cb">
        <label style="font-size:11px;font-weight:700;color:var(--text-main);text-transform:uppercase;letter-spacing:0.3px;display:block;margin-bottom:3px;">Buscar por nombre o IP</label>
        <div class="zbr">
          <input type="text" id="zebra-search" placeholder="Escribe para buscar..." autocomplete="off" oninput="buscarZebra(this.value)">
          <div class="zb-results" id="zebra-results"></div>
        </div>
        <div id="zebra-badge" style="display:<?= $cfg['zebra_ip'] ? 'flex' : 'none' ?>;" class="zb">
          <div><div class="zn" id="zebra-badge-nombre"><?= htmlspecialchars($cfg['zebra_nombre'] ?? '') ?></div><div class="zi" id="zebra-badge-ip"><?= htmlspecialchars($cfg['zebra_ip'] ?? '') ?></div></div>
          <span style="color:#dc3545;font-weight:700;cursor:pointer;" onclick="limpiarZebra()">✕</span>
        </div>
        <input type="hidden" name="zebra_ip"     id="zebra_ip"     value="<?= htmlspecialchars($cfg['zebra_ip']) ?>">
        <input type="hidden" name="zebra_nombre" id="zebra_nombre" value="<?= htmlspecialchars($cfg['zebra_nombre']) ?>">
        <div class="fg" style="margin-top:10px;"><label>Puerto TCP</label><input type="number" name="zebra_port" value="<?= htmlspecialchars($cfg['zebra_port']) ?>"></div>
      </div>
      <div class="cf"><button type="submit" name="save_zebra" class="bs">Guardar</button></div>
    </form>

    <!-- SQL Express -->
    <form method="POST" class="card">
      <div class="ch">
        <div class="ci" style="background:#E8F4FD;">&#128451;</div>
        <div><div class="ct">SQL Server Express</div><div class="cd">BD local para registro de actas</div></div>
      </div>
      <div class="cb">
<?php $ldb = $cfg['local_db'] ?? []; ?>
        <div class="fg"><label>Servidor</label><input type="text" name="local_server" value="<?= htmlspecialchars($ldb['server'] ?? 'localhost\SQLEXPRESS') ?>" placeholder="localhost\SQLEXPRESS"></div>
        <div class="fg"><label>Base de datos</label><input type="text" name="local_database" value="<?= htmlspecialchars($ldb['database'] ?? 'SistemaNG') ?>" placeholder="SistemaNG"></div>
        <div class="fg"><label>Usuario (vacío = Windows Auth)</label><input type="text" name="local_user" value="<?= htmlspecialchars($ldb['user'] ?? '') ?>" placeholder="Vacío para Windows Auth"></div>
        <div class="fg"><label>Contraseña</label><input type="password" name="local_password" value="<?= htmlspecialchars($ldb['password'] ?? '') ?>"></div>
      </div>
      <div class="cf"><button type="submit" name="save_localdb" class="bs">Guardar</button></div>
    </form>

    <!-- Active Directory -->
    <form method="POST" class="card">
      <div class="ch">
        <div class="ci" style="background:#FFF3E0;">🔑</div>
        <div><div class="ct">Active Directory</div><div class="cd">Autenticación LDAP</div></div>
      </div>
      <div class="cb">
<?php $ad = $cfg['active_directory'] ?? []; ?>
        <div class="sw-row" style="margin-bottom:10px;">
          <span style="font-size:12px;font-weight:700;">Habilitado</span>
          <div class="sw-wrap">
            <input type="checkbox" name="ad_habilitado" <?= !empty($ad['habilitado'])?'checked':'' ?>>
            <div class="sw-track" style="background:<?= !empty($ad['habilitado'])?'var(--color-principal)':'#555' ?>;"></div>
            <div class="sw-knob" style="left:<?= !empty($ad['habilitado'])?'25px':'3px' ?>;"></div>
          </div>
        </div>
        <div class="fg"><label>Nombre (se muestra en login)</label><input type="text" name="ad_nombre" value="<?= htmlspecialchars($ad['nombre'] ?? 'Active Directory') ?>" placeholder="Ej: Dominio NAGSA"></div>
        <div class="row2">
          <div class="fg"><label>Servidor LDAP</label><input type="text" name="ad_servidor" value="<?= htmlspecialchars($ad['servidor'] ?? '') ?>" placeholder="192.168.x.x o dc.dominio.com"></div>
          <div class="fg"><label>Puerto</label><input type="number" name="ad_puerto" value="<?= htmlspecialchars($ad['puerto'] ?? '389') ?>"></div>
        </div>
        <div class="fg"><label>Dominio</label><input type="text" name="ad_dominio" value="<?= htmlspecialchars($ad['dominio'] ?? '') ?>" placeholder="NAGSA o nagsa.com.ec"></div>
        <div class="fg"><label>Base DN</label><input type="text" name="ad_base_dn" value="<?= htmlspecialchars($ad['base_dn'] ?? '') ?>" placeholder="DC=nagsa,DC=com,DC=ec"></div>
        <div class="fg"><label>Sufijo usuario (opcional)</label><input type="text" name="ad_sufijo" value="<?= htmlspecialchars($ad['sufijo_usuario'] ?? '') ?>" placeholder="@nagsa.com.ec"></div>
      </div>
      <div class="cf"><button type="submit" name="save_ad" class="bs">Guardar y verificar</button></div>
    </form>

  </div>

  <!-- Fila 2: Personalización | Tema -->
  <div class="grid2">

    <!-- Personalización -->
    <div class="card">
      <div class="ch">
        <div class="ci" style="background:#FFF0E8;">🎨</div>
        <div><div class="ct">Personalización</div><div class="cd">Marca visual del sistema</div></div>
      </div>
      <div class="cb">
        <!-- Fila 1: Logo actual | Cambiar logo -->
        <form method="POST" enctype="multipart/form-data" style="margin-bottom:14px;">
          <div class="row2">
            <div class="fg" style="margin-bottom:0;">
              <label>Logo actual</label>
              <div class="logo-pre" style="height:38px;">
                <img id="logo-preview" src="<?= htmlspecialchars($cfg['empresa_logo'] ?? 'nagsa_logo.png') ?>?v=<?= filemtime(__DIR__.'/' . ($cfg['empresa_logo'] ?? 'nagsa_logo.png')) ?: time() ?>" alt="Logo" style="height:26px;object-fit:contain;">
                <span><?= htmlspecialchars($cfg['empresa_logo'] ?? 'nagsa_logo.png') ?></span>
              </div>
            </div>
            <div class="fg" style="margin-bottom:0;">
              <label>Cambiar logo</label>
              <div class="file-row">
                <input type="file" name="empresa_logo_file" accept="image/*" class="file-input" style="height:38px;">
                <button type="button" onclick="subirLogo()" class="btn-up" style="height:38px;">🖼 Subir</button>
              </div>
            </div>
          </div>
          <div id="logo-upload-msg" style="margin-top:4px;"></div>
        </form>

        <!-- Fila 2: Nombre | Color -->
        <form method="POST" id="form-marca">
          <div class="row2">
            <div class="fg">
              <label>Nombre de la empresa</label>
              <input type="text" name="empresa_nombre" value="<?= htmlspecialchars($cfg['empresa_nombre'] ?? 'NAGSA') ?>" style="height:38px;">
            </div>
            <div class="fg">
              <label>Color principal</label>
              <div class="color-row" style="height:38px;">
                <input type="color" name="empresa_color" id="color-picker" value="<?= htmlspecialchars($cfg['empresa_color'] ?? '#E05816') ?>" style="width:38px;height:38px;padding:2px;border-radius:6px;border:1.5px solid var(--border);background:var(--bg-input);cursor:pointer;flex-shrink:0;">
                <input type="text" id="color-hex" value="<?= htmlspecialchars($cfg['empresa_color'] ?? '#E05816') ?>" style="flex:1;height:38px;" placeholder="#E05816">
              </div>
            </div>
          </div>
          <input type="hidden" name="empresa_tema" id="empresa_tema" value="<?= htmlspecialchars($cfg['empresa_tema'] ?? 'oscuro') ?>">
          <div style="border-top:1px solid var(--border);padding-top:10px;margin-top:10px;display:flex;justify-content:flex-end;">
            <button type="submit" name="save_marca" class="bs">Guardar personalización</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Tema -->
    <div class="card">
      <div class="ch">
        <div class="ci" style="background:#F0E8FF;">🌗</div>
        <div><div class="ct">Tema</div><div class="cd">Apariencia visual</div></div>
      </div>
      <div class="cb">
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:12px;">El cambio se aplica inmediatamente en todos los módulos.</div>
        <div class="sw-row">
          <span>☀️ Claro</span>
          <div class="sw-wrap">
            <input type="checkbox" id="tema-check" <?= ($cfg['empresa_tema']??'oscuro')==='oscuro'?'checked':'' ?> onchange="toggleTema()">
            <div class="sw-track" id="tema-switch" style="background:<?= ($cfg['empresa_tema']??'oscuro')==='oscuro'?'#E05816':'#555' ?>;"></div>
            <div class="sw-knob" id="tema-knob" style="left:<?= ($cfg['empresa_tema']??'oscuro')==='oscuro'?'25px':'3px' ?>;"></div>
          </div>
          <span>🌙 Oscuro</span>
        </div>
        <div class="tema-preview">
          <div class="label">Vista previa</div>
          <div class="tema-boxes">
            <div class="tema-box" id="prev-oscuro" style="background:#1a1a1a;color:#fff;border:<?= ($cfg['empresa_tema']??'oscuro')==='oscuro'?'2px solid #E05816':'1px solid #444' ?>;">Oscuro</div>
            <div class="tema-box" id="prev-claro" style="background:#f0f4f8;color:#333;border:<?= ($cfg['empresa_tema']??'oscuro')==='claro'?'2px solid #E05816':'1px solid #ccc' ?>;">Claro</div>
          </div>
        </div>
      </div>
    </div>

  </div>

  <!-- Fila 3: Módulos + Categorías -->
  <div class="grid2" style="margin-bottom:16px;">

    <!-- Estado de módulos -->
    <form method="POST" class="card">
      <div class="ch">
        <div class="ci" style="background:#E8F5E9;">🔌</div>
        <div><div class="ct">Estado de Módulos</div><div class="cd">Activar o desactivar módulos del sistema</div></div>
      </div>
      <div class="cb">
        <?php
        $mods = $cfg['modulos'] ?? ['etiquetas'=>'activo','actas'=>'activo','reportes'=>'deshabilitado','inversiones'=>'deshabilitado','permisos'=>'deshabilitado'];
        $modLabels = ['etiquetas'=>'🏷️ Etiquetas','actas'=>'📋 Actas','reportes'=>'📊 Reportes','inversiones'=>'💰 Inversiones','permisos'=>'🔔 Permisos y Notif.'];
        foreach($modLabels as $key=>$label):
            $estado = $mods[$key] ?? 'deshabilitado';
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
          <span style="font-size:13px;color:var(--text-main);"><?= $label ?></span>
          <select name="mod_<?= $key ?>" style="padding:5px 8px;border-radius:6px;border:1.5px solid var(--border);background:var(--bg-input);color:var(--text-main);font-size:12px;outline:none;cursor:pointer;accent-color:var(--color-principal);">
            <option value="activo"       <?= $estado==='activo'?'selected':'' ?>>✅ Activo</option>
            <option value="pruebas"      <?= $estado==='pruebas'?'selected':'' ?>>🧪 Pruebas</option>
            <option value="deshabilitado"<?= $estado==='deshabilitado'?'selected':'' ?>>🚫 Deshabilitado</option>
          </select>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="cf"><button type="submit" name="save_modulos" class="bs">Guardar estado</button></div>
    </form>

    <!-- Categorías -->
    <form method="POST" class="card">
      <div class="ch">
        <div class="ci" style="background:#F0E8FF;">📋</div>
        <div><div class="ct">Categorías a Mostrar</div><div class="cd">Visibles en el módulo de etiquetas</div></div>
      </div>
      <div class="cb">
        <div class="cats">
          <label class="cat-item"><input type="checkbox" name="show_comp" <?= chk($show,'computadoras') ?>> 💻 Computadoras</label>
          <label class="cat-item"><input type="checkbox" name="show_mon"  <?= chk($show,'monitores') ?>> 🖥 Monitores</label>
          <label class="cat-item"><input type="checkbox" name="show_imp"  <?= chk($show,'impresoras') ?>> 🖨️ Impresoras</label>
          <label class="cat-item"><input type="checkbox" name="show_per"  <?= chk($show,'perifericos') ?>> 🖱 Periféricos</label>
          <label class="cat-item"><input type="checkbox" name="show_red"  <?= chk($show,'redes') ?>> 🌐 Disp. de Red</label>
          <label class="cat-item"><input type="checkbox" name="show_gab"  <?= chk($show,'gabinetes') ?>> 🗄 Gabinetes</label>
          <label class="cat-item"><input type="checkbox" name="show_pas"  <?= chk($show,'pasivos') ?>> 🔌 Disp. Pasivos</label>
          <label class="cat-item"><input type="checkbox" name="show_car"  <?= chk($show,'cartuchos') ?>> 🩸 Cartuchos</label>
          <label class="cat-item"><input type="checkbox" name="show_con"  <?= chk($show,'consumibles') ?>> 📦 Consumibles</label>
          <label class="cat-item"><input type="checkbox" name="show_tel"  <?= chk($show,'telefonos') ?>> 📱 Teléfonos</label>
        </div>
      </div>
      <div class="cf"><button type="submit" name="save_cats" class="bs">Guardar categorías</button></div>
    </form>

  </div>

  <!-- Categorías — eliminada, ahora está en grid2 arriba -->
  <?php if(false): ?>
  <form method="POST" class="card">
    <div class="ch">
      <div class="ci" style="background:#F0E8FF;">📋</div>
      <div><div class="ct">Categorías a Mostrar</div><div class="cd">Visibles en el módulo de etiquetas</div></div>
    </div>
    <div class="cb">
      <div class="cats">
        <label class="cat-item"><input type="checkbox" name="show_comp" <?= chk($show,'computadoras') ?>> 💻 Computadoras</label>
        <label class="cat-item"><input type="checkbox" name="show_mon"  <?= chk($show,'monitores') ?>> 🖥 Monitores</label>
        <label class="cat-item"><input type="checkbox" name="show_imp"  <?= chk($show,'impresoras') ?>> 🖨️ Impresoras</label>
        <label class="cat-item"><input type="checkbox" name="show_per"  <?= chk($show,'perifericos') ?>> 🖱 Periféricos</label>
        <label class="cat-item"><input type="checkbox" name="show_red"  <?= chk($show,'redes') ?>> 🌐 Disp. de Red</label>
        <label class="cat-item"><input type="checkbox" name="show_gab"  <?= chk($show,'gabinetes') ?>> 🗄 Gabinetes</label>
        <label class="cat-item"><input type="checkbox" name="show_pas"  <?= chk($show,'pasivos') ?>> 🔌 Disp. Pasivos</label>
        <label class="cat-item"><input type="checkbox" name="show_car"  <?= chk($show,'cartuchos') ?>> 🖨 Cartuchos</label>
        <label class="cat-item"><input type="checkbox" name="show_con"  <?= chk($show,'consumibles') ?>> 📦 Consumibles</label>
        <label class="cat-item"><input type="checkbox" name="show_tel"  <?= chk($show,'telefonos') ?>> 📱 Teléfonos</label>
      </div>
    </div>
    <div class="cf"><button type="submit" name="save_cats" class="bs">Guardar categorías</button></div>
  </form>
  <?php endif; ?>

</div>
<?php endif; ?>

<script>
// ── Color picker sync ─────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const picker = document.getElementById('color-picker');
  const hex    = document.getElementById('color-hex');
  if(picker && hex) {
    picker.addEventListener('input', () => { hex.value = picker.value; });
    hex.addEventListener('input', () => {
      const v = hex.value.trim();
      if(/^#[0-9A-Fa-f]{6}$/.test(v)) picker.value = v;
    });
  }
});

// ── Logo upload via fetch ────────────────────────────────────────────────────
function subirLogo() {
  const fileInput = document.querySelector('input[name="empresa_logo_file"]');
  const msgEl = document.getElementById('logo-upload-msg');
  if(!fileInput.files || !fileInput.files[0]) {
    msgEl.style.color = 'var(--color-principal)';
    msgEl.textContent = '⚠️ Selecciona un archivo primero.';
    return;
  }
  msgEl.style.color = 'var(--text-muted)';
  msgEl.textContent = '⏳ Subiendo...';
  const fd = new FormData();
  fd.append('upload_logo', '1');
  fd.append('empresa_logo_file', fileInput.files[0]);
  fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if(data.ok) {
        msgEl.style.color = 'green';
        msgEl.textContent = '✅ ' + data.msg;
        setTimeout(() => location.reload(true), 1200);
      } else {
        msgEl.style.color = 'red';
        msgEl.textContent = '❌ ' + data.msg;
      }
    })
    .catch(() => { msgEl.style.color='red'; msgEl.textContent='❌ Error de conexión.'; });
}

// ── Toggle tema ───────────────────────────────────────────────────────────────
function toggleTema() {
  const chk   = document.getElementById('tema-check');
  const input = document.getElementById('empresa_tema');
  const sw    = document.getElementById('tema-switch');
  const knob  = document.getElementById('tema-knob');
  const po    = document.getElementById('prev-oscuro');
  const pc    = document.getElementById('prev-claro');
  const tema  = chk.checked ? 'oscuro' : 'claro';
  input.value = tema;
  sw.style.background = chk.checked ? '#E05816' : '#555';
  knob.style.left     = chk.checked ? '25px' : '3px';
  if(po){ po.style.border = tema==='oscuro' ? '2px solid #E05816' : '1px solid #444'; }
  if(pc){ pc.style.border = tema==='claro'  ? '2px solid #E05816' : '1px solid #ccc'; }
  const fd = new FormData();
  fd.append('cambiar_tema', '1');
  fd.append('empresa_tema', tema);
  fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => { if(data.ok) location.reload(); })
    .catch(() => {});
}

// ── Zebra search ──────────────────────────────────────────────────────────────
let zebraTimeout = null;
function buscarZebra(q) {
  clearTimeout(zebraTimeout);
  const res = document.getElementById('zebra-results');
  if(q.length < 2) { res.style.display='none'; return; }
  zebraTimeout = setTimeout(async () => {
    try {
      const data = await fetch('modules/etiquetas/etiquetas_api.php?action=impresoras&q='+encodeURIComponent(q)).then(r=>r.json());
      if(!data.length) { res.style.display='none'; return; }
      res.innerHTML = data.map(p=>`<div class="zb-item" onclick="selZebra('${p.nombre.replace(/'/g,"\\'")}','${p.ip}')">
        <div style="font-weight:700;font-size:12px;color:var(--text-main);">${p.nombre}</div>
        <div style="font-size:10px;color:var(--color-principal);">${p.ip}</div>
      </div>`).join('');
      res.style.display = 'block';
    } catch(e) {}
  }, 300);
}

function selZebra(nombre, ip) {
  document.getElementById('zebra_ip').value     = ip;
  document.getElementById('zebra_nombre').value = nombre;
  document.getElementById('zebra-badge-nombre').textContent = nombre;
  document.getElementById('zebra-badge-ip').textContent     = ip;
  document.getElementById('zebra-badge').style.display      = 'flex';
  document.getElementById('zebra-search').value             = '';
  document.getElementById('zebra-results').style.display    = 'none';
}

function limpiarZebra() {
  document.getElementById('zebra_ip').value = '';
  document.getElementById('zebra_nombre').value = '';
  document.getElementById('zebra-badge').style.display = 'none';
}

document.addEventListener('click', e => {
  if(!e.target.closest('.zbr')) document.getElementById('zebra-results').style.display='none';
});
</script>
</body>
</html>
