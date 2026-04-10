<?php
session_start();
$session_duration = 4 * 60 * 60;
if(isset($_SESSION['last_activity']) && (time()-$_SESSION['last_activity'])>$session_duration){
    session_unset(); session_destroy(); session_start();
}
if(isset($_SESSION['nagsa_user'])) $_SESSION['last_activity']=time();
if(isset($_GET['logout'])){ session_unset(); session_destroy(); header('Location: '.$_SERVER['PHP_SELF']); exit; }

require_once __DIR__.'/config/config.php';
$branding = getBranding();
$modulos = $cfg['modulos'] ?? ['etiquetas'=>'activo','actas'=>'activo','reportes'=>'deshabilitado','inversiones'=>'deshabilitado','permisos'=>'deshabilitado'];
function estadoMod($m) { global $modulos; return $modulos[$m] ?? 'deshabilitado'; }
function badgeMod($m) {
    $e = estadoMod($m);
    if($e==='activo')        return ['badge-active','Activo'];
    if($e==='pruebas')       return ['badge-beta','En Pruebas'];
    return ['badge-disabled','Deshabilitado'];
}
function cardMod($m) { return estadoMod($m)==='deshabilitado' ? 'disabled' : ''; }
$login_error='';
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['username'])){
    $cfg_file = __DIR__.'/sistemas_settings.json'; // en raíz
    if(!file_exists($cfg_file)){
        $login_error='No hay configuración de BD. Configure primero en Sistema_admin.php';
    } else {
        $cfg=json_decode(file_get_contents($cfg_file),true);
        $dsn='mysql:host='.$cfg['db_host'].';port='.$cfg['db_port'].';dbname='.$cfg['db_name'].';charset=utf8mb4';
        try{
            $pdo=new PDO($dsn,$cfg['db_user'],$cfg['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $stmt=$pdo->prepare("SELECT id,name,password,firstname,realname FROM glpi_users WHERE name=:u AND is_deleted=0 AND is_active=1 LIMIT 1");
            $stmt->execute([':u'=>trim($_POST['username'])]);
            $user=$stmt->fetch(PDO::FETCH_ASSOC);
            if($user && password_verify($_POST['password'],$user['password'])){
                $_SESSION['nagsa_user']=$user['name'];
                $_SESSION['nagsa_name']=trim($user['firstname'].' '.$user['realname']);
                $_SESSION['last_activity']=time();
                header('Location: '.$_SERVER['PHP_SELF']); exit;
            } else { $login_error='Usuario o contraseña incorrectos.'; }
        } catch(PDOException $e){ $login_error='Error de conexión a la BD.'; }
    }
}

if(!isset($_SESSION['nagsa_user'])):
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($branding["nombre"]) ?> System — Login</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,sans-serif;background:var(--bg-body);color:var(--text-main);min-height:100vh;display:flex;align-items:center;justify-content:center;}
.login-card{background:var(--bg-card);border-radius:12px;padding:40px 36px;width:100%;max-width:420px;border:1px solid var(--border);box-shadow:0 8px 32px rgba(0,0,0,0.4);}
.login-logo{text-align:center;margin-bottom:28px;}
.login-logo .name{font-size:32px;font-weight:900;color:var(--color-principal);letter-spacing:4px;}
.login-logo .sub{font-size:11px;color:var(--text-muted);letter-spacing:1.5px;text-transform:uppercase;margin-top:6px;}
.login-title{font-size:13px;color:var(--text-muted);text-align:center;margin-bottom:28px;}
label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);margin-bottom:5px;}
input{width:100%;padding:11px 14px;border-radius:7px;border:1.5px solid var(--border);background:var(--bg-input);color:var(--text-main);font-size:13px;outline:none;margin-bottom:16px;transition:border-color 0.15s;}
input:focus{border-color:var(--color-principal);}
.btn{width:100%;padding:13px;border-radius:7px;border:none;background:var(--color-principal);color:var(--text-main);font-size:14px;font-weight:700;cursor:pointer;transition:background 0.15s;margin-top:4px;}
.btn:hover{background:var(--color-hover);}
.error{background:rgba(220,53,69,0.12);border:1px solid rgba(220,53,69,0.4);color:#e57373;padding:11px 14px;border-radius:7px;font-size:13px;margin-bottom:18px;}
</style>
<?php brandingCSS(); ?>
</head>
<body>
<div class="login-card">
  <div class="login-logo">
    <img src="<?= htmlspecialchars($branding['logo']) ?>" alt="<?= htmlspecialchars($branding['nombre']) ?>" style="height:60px;object-fit:contain;margin-bottom:8px;">
    <div class="sub">Sistema de Gestión de Activos TI</div>
  </div>
  <div class="login-title">Ingresa con tu usuario de GLPI</div>
  <?php if($login_error): ?>
  <div class="error">⚠️ <?=htmlspecialchars($login_error)?></div>
  <?php endif; ?>
  <form method="POST">
    <label>Usuario</label>
    <input type="text" name="username" placeholder="Usuario GLPI" autofocus required>
    <label>Contraseña</label>
    <input type="password" name="password" placeholder="Contraseña" required>
    <button class="btn" type="submit">🔐 Ingresar</button>
  </form>
</div>
</body>
</html>
<?php exit; endif; ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($branding["nombre"]) ?> System</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,sans-serif;background:var(--bg-body);color:var(--text-main);min-height:100vh;}
.topbar{background:var(--bg-card);border-bottom:2px solid var(--color-principal);padding:14px 28px;display:flex;align-items:center;gap:16px;}
.topbar .logo{font-size:22px;font-weight:900;color:var(--color-principal);letter-spacing:3px;}
.topbar .sub{font-size:10px;color:var(--text-sub);text-transform:uppercase;letter-spacing:1.5px;margin-top:2px;font-weight:600;}
.topbar .user{margin-left:auto;display:flex;align-items:center;gap:12px;}
.topbar .user span{font-size:12px;color:var(--text-sub);font-weight:600;}
.topbar .user a{padding:7px 16px;border-radius:6px;background:var(--color-principal);color:#fff;font-size:11px;font-weight:700;text-decoration:none;border:none;transition:all 0.15s;}
.topbar .user a:hover{background:var(--color-hover);color:#fff;}
.main{max-width:1100px;margin:0 auto;padding:40px 24px;}
.welcome{margin-bottom:36px;}
.welcome h1{font-size:22px;font-weight:800;color:var(--text-main);margin-bottom:6px;}
.welcome p{font-size:13px;color:var(--text-sub);}
.modules-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px;}
.module-card{background:var(--bg-card);border-radius:12px;border:1.5px solid var(--border);box-shadow:0 2px 8px rgba(0,0,0,0.06);padding:24px;cursor:pointer;text-decoration:none;display:flex;flex-direction:column;gap:14px;transition:all 0.2s;position:relative;overflow:hidden;}
.module-card:hover{border-color:var(--color-principal);transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,0.15);}
.module-card.disabled{cursor:not-allowed;opacity:0.45;}
.module-card.disabled:hover{border-color:var(--border);transform:none;box-shadow:none;}
.module-card .card-icon{font-size:32px;line-height:1;}
.module-card .card-title{font-size:15px;font-weight:800;color:var(--text-main);}
.module-card .card-desc{font-size:12px;color:var(--text-muted);line-height:1.5;}
.module-card .card-badge{position:absolute;top:14px;right:14px;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;}
.badge-active{background:rgba(40,167,69,0.15);color:#1e7e34;border:1px solid rgba(40,167,69,0.4);font-weight:800;}
.badge-beta{background:rgba(255,193,7,0.2);color:#d39e00;border:1px solid rgba(255,193,7,0.5);font-weight:800;}
.badge-disabled{background:rgba(108,117,125,0.15);color:#6c757d;border:1px solid rgba(108,117,125,0.4);font-weight:800;}
.module-card .card-footer{font-size:10px;color:var(--text-muted);margin-top:auto;padding-top:10px;border-top:1px solid var(--border);}
.section-label{font-size:10px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:var(--color-principal);margin:32px 0 14px;}
.section-label:first-child{margin-top:0;}
</style>
<?php brandingCSS(); ?>
</head>
<body>
<div class="topbar">
  <div style="display:flex;align-items:center;gap:12px;">
    <img src="<?= htmlspecialchars($branding['logo']) ?>" alt="<?= htmlspecialchars($branding['nombre']) ?>" style="height:36px;object-fit:contain;">
    <div class="sub">Sistema de Gestión de Activos TI</div>
  </div>
  <div class="user">
    <span>👤 <?=htmlspecialchars($_SESSION['nagsa_name']?:$_SESSION['nagsa_user'])?></span>
    <a href="Sistema_admin.php" title="Configuración">⚙️</a>
    <a href="?logout=1">🚪 Salir</a>
  </div>
</div>
<div class="main">
  <div class="welcome">
    <h1>Bienvenido, <?=htmlspecialchars(explode(' ',$_SESSION['nagsa_name']?:$_SESSION['nagsa_user'])[0])?> 👋</h1>
    <p>Selecciona el módulo con el que deseas trabajar.</p>
  </div>
<?php
// Definición de todos los módulos
$todosModulos = [
  'etiquetas'  => ['url'=>'modules/etiquetas/etiquetas_inventario.php','icono'=>'🏷️','titulo'=>'Etiquetas de Activos','desc'=>'Genera e imprime etiquetas con código QR para todos los activos registrados en GLPI.','footer'=>'Computadoras · Monitores · Impresoras · Periféricos · más','link'=>true],
  'actas'      => ['url'=>'modules/actas/actas.php','icono'=>'📋','titulo'=>'Actas de Equipos','desc'=>'Genera actas de entrega y salida de equipos con datos desde GLPI o ingreso manual.','footer'=>'Entrega · Salida · Impresión / PDF','link'=>true],
  'reportes'   => ['url'=>'modules/reportes/reportes.php','icono'=>'📊','titulo'=>'Reportes y Estadísticas','desc'=>'Registro de actas de entrega y salida, aceptación/resguardo, estadísticas por período.','footer'=>'Registro actas · Aceptación / Resguardo · Estadísticas','link'=>true],
  'inversiones'=> ['url'=>'modules/proximamente.php?m=inversiones','icono'=>'💰','titulo'=>'Formato de Inversión','desc'=>'Solicitudes de compra, inversión por empresa y seguimiento de adquisiciones.','footer'=>'Compras · Inversión · Por entidad','link'=>true],
  'permisos'   => ['url'=>'modules/proximamente.php?m=permisos','icono'=>'🔔','titulo'=>'Permisos y Notificaciones','desc'=>'Permisos por perfil de GLPI para cada módulo, notificaciones por correo y alertas.','footer'=>'Permisos · Notificaciones · Perfiles GLPI','link'=>true],
];

// Agrupar por estado
$grActivo = $grPruebas = $grDeshabilitado = [];
foreach($todosModulos as $key=>$mod) {
  $estado = estadoMod($key);
  if($estado==='activo')           $grActivo[$key]       = $mod;
  elseif($estado==='pruebas')      $grPruebas[$key]      = $mod;
  else                             $grDeshabilitado[$key] = $mod;
}

function renderCard($key, $mod, $estado) {
  $badges = ['activo'=>['badge-active','Activo'],'pruebas'=>['badge-beta','En Pruebas'],'deshabilitado'=>['badge-disabled','Deshabilitado']];
  $bc = $badges[$estado][0] ?? 'badge-disabled';
  $bl = $badges[$estado][1] ?? 'Deshabilitado';
  $disabled = $estado==='deshabilitado' ? 'disabled' : '';
  $isLink = $mod['link'];
  $url = $mod['url'];
  if($isLink) echo '<a href="'.$url.'" class="module-card '.$disabled.'">';
  else        echo '<div class="module-card '.$disabled.'">';
  echo '<span class="card-badge '.$bc.'">'.$bl.'</span>';
  echo '<div class="card-icon">'.$mod['icono'].'</div>';
  echo '<div class="card-title">'.$mod['titulo'].'</div>';
  echo '<div class="card-desc">'.$mod['desc'].'</div>';
  echo '<div class="card-footer">'.$mod['footer'].'</div>';
  if($isLink) echo '</a>';
  else        echo '</div>';
}
?>

<?php if(!empty($grActivo)): ?>
  <div class="section-label">Módulos disponibles</div>
  <div class="modules-grid">
    <?php foreach($grActivo as $key=>$mod) renderCard($key,$mod,'activo'); ?>
  </div>
<?php endif; ?>

<?php if(!empty($grPruebas)): ?>
  <div class="section-label">En desarrollo</div>
  <div class="modules-grid">
    <?php foreach($grPruebas as $key=>$mod) renderCard($key,$mod,'pruebas'); ?>
  </div>
<?php endif; ?>

<?php if(!empty($grDeshabilitado)): ?>
  <div class="section-label">Deshabilitados</div>
  <div class="modules-grid">
    <?php foreach($grDeshabilitado as $key=>$mod) renderCard($key,$mod,'deshabilitado'); ?>
  </div>
<?php endif; ?>
</div>
</body>
</html>
