<?php
session_start();
if(!isset($_SESSION['nagsa_user'])){ header('Location: ../index.php'); exit; }
require_once __DIR__ . '/../config/config.php';
$branding = getBranding();
$modulo   = $_GET['m'] ?? '';

// Nombres de módulos para el título
$nombres = [
    'inversiones' => 'Formato de Inversión',
    'permisos'    => 'Permisos y Notificaciones',
];
$titulo_modulo = $nombres[$modulo] ?? 'Nuevo Módulo';

$proto     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$dashboard = $proto . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/index.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($titulo_modulo) ?> &mdash; <?= htmlspecialchars($branding['nombre']) ?></title>
<?php brandingCSS('../'); ?>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,sans-serif;background:var(--bg-body);color:var(--text-main);min-height:100vh;display:flex;align-items:center;justify-content:center;}
.card{text-align:center;padding:48px 40px;max-width:500px;background:var(--bg-card);border-radius:14px;border:1px solid var(--border);box-shadow:0 4px 20px rgba(0,0,0,0.08);}
.icon{font-size:64px;margin-bottom:20px;}
.title{font-size:22px;font-weight:800;color:var(--color-principal);margin-bottom:8px;}
.modulo{font-size:13px;color:var(--text-sub);font-weight:600;margin-bottom:20px;}
.msg{font-size:14px;color:var(--text-muted);margin-bottom:28px;line-height:1.7;}
.btn{display:inline-block;padding:11px 26px;border-radius:8px;background:var(--color-principal);color:#fff;text-decoration:none;font-size:13px;font-weight:700;transition:background 0.15s;}
.btn:hover{background:var(--color-hover);}
</style>
</head>
<body>
<div class="card">
  <div class="icon">&#128736;</div>
  <div class="title">Estamos trabajando en esto</div>
  <div class="modulo"><?= htmlspecialchars($titulo_modulo) ?></div>
  <div class="msg">
    Este módulo se encuentra en construcción.<br>
    Estamos trabajando para ofrecerte nuevas funcionalidades.<br><br>
    Gracias por tu paciencia.
  </div>
  <a href="<?= htmlspecialchars($dashboard) ?>" class="btn">&larr; Volver al Dashboard</a>
</div>
</body>
</html>
