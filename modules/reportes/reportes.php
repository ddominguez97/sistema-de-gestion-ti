<?php
session_start();
$session_duration = 4 * 60 * 60;
if(isset($_SESSION['last_activity']) && (time()-$_SESSION['last_activity'])>$session_duration){
    session_unset(); session_destroy();
}
// Modo desarrollo: si no hay sesión GLPI y es localhost, crear sesión temporal
if(!isset($_SESSION['nagsa_user']) && in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost:8080','localhost','127.0.0.1:8080','127.0.0.1'])){
    $_SESSION['nagsa_user'] = 'dev_local';
    $_SESSION['nagsa_name'] = 'Desarrollo Local';
    $_SESSION['admin_ok']   = true;
}
if(!isset($_SESSION['nagsa_user'])){ header('Location: ../../index.php'); exit; }
$_SESSION['last_activity'] = time();
require_once __DIR__ . '/../../config/config.php';
checkModulo('reportes');
$branding = getBranding();
$proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'];
$base     = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
$logo_abs = $proto . '://' . $host . $base . '/' . $branding['logo'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reportes &mdash; <?= htmlspecialchars($branding['nombre']) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,sans-serif;background:var(--bg-body);color:var(--text-main);min-height:100vh;}
.topbar{background:var(--bg-topbar);border-bottom:2px solid var(--color-principal);padding:12px 24px;display:flex;align-items:center;gap:16px;}
.topbar .sub{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-top:2px;}
.topbar .user{margin-left:auto;display:flex;align-items:center;gap:12px;font-size:12px;color:var(--text-sub);}
.topbar a.btn-back{padding:6px 14px;border-radius:6px;background:var(--bg-hover);color:var(--text-sub);font-size:11px;font-weight:700;text-decoration:none;border:1px solid var(--border);}
.topbar a.btn-logout{padding:6px 14px;border-radius:6px;background:var(--color-principal);color:#fff;font-size:11px;font-weight:700;text-decoration:none;}
.container{max-width:1200px;margin:24px auto;padding:0 20px;}

/* Tabs */
.tabs{display:flex;gap:0;margin-bottom:24px;border-bottom:2px solid var(--border);}
.tab{padding:12px 24px;font-size:13px;font-weight:700;color:var(--text-muted);cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all 0.15s;}
.tab:hover{color:var(--text-main);}
.tab.active{color:var(--color-principal);border-bottom-color:var(--color-principal);}
.tab-content{display:none;}
.tab-content.active{display:block;}

/* Cards */
.section-title{font-size:10px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:var(--color-principal);margin-bottom:12px;}
.card{background:var(--bg-card);border-radius:10px;padding:24px;border:1px solid var(--border);margin-bottom:20px;}

/* Stats grid */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:24px;}
.stat-card{background:var(--bg-card);border-radius:10px;padding:20px;border:1px solid var(--border);text-align:center;}
.stat-number{font-size:32px;font-weight:900;color:var(--color-principal);line-height:1;}
.stat-label{font-size:11px;color:var(--text-muted);margin-top:6px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;}

/* Filters */
.filters{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:20px;}
.filters select,.filters input{padding:8px 12px;border-radius:6px;border:1.5px solid var(--border2);background:var(--bg-input);color:var(--text-main);font-size:12px;outline:none;}
.filters select:focus,.filters input:focus{border-color:var(--color-principal);}
.filters .btn-filter{padding:8px 18px;border-radius:6px;border:none;background:var(--color-principal);color:#fff;font-size:12px;font-weight:700;cursor:pointer;}
.filters .btn-clear{padding:8px 18px;border-radius:6px;border:1px solid var(--border2);background:transparent;color:var(--text-sub);font-size:12px;font-weight:700;cursor:pointer;}

/* Table */
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table th{background:var(--bg-dark3);color:var(--text-sub);padding:10px 12px;text-align:left;font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;border-bottom:2px solid var(--border);position:sticky;top:0;}
.data-table td{padding:10px 12px;border-bottom:1px solid var(--border);color:var(--text-main);vertical-align:middle;}
.data-table tr:hover td{background:var(--bg-hover);}
.data-table .empty-row td{text-align:center;padding:40px;color:var(--text-muted);font-size:13px;}

/* Badges */
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;}
.badge-entrega{background:rgba(0,123,255,0.12);color:#0056b3;border:1px solid rgba(0,123,255,0.3);}
.badge-salida{background:rgba(255,152,0,0.12);color:#e65100;border:1px solid rgba(255,152,0,0.3);}
.badge-pendiente{background:rgba(255,193,7,0.15);color:#d39e00;border:1px solid rgba(255,193,7,0.4);}
.badge-aceptada{background:rgba(40,167,69,0.15);color:#1e7e34;border:1px solid rgba(40,167,69,0.4);}
.badge-rechazada{background:rgba(220,53,69,0.12);color:#a71d2a;border:1px solid rgba(220,53,69,0.4);}

/* Action buttons */
.btn-sm{padding:5px 12px;border-radius:5px;border:none;font-size:11px;font-weight:700;cursor:pointer;transition:all 0.15s;}
.btn-view{background:rgba(0,123,255,0.1);color:#0056b3;border:1px solid rgba(0,123,255,0.3);}
.btn-view:hover{background:rgba(0,123,255,0.2);}
.btn-accept{background:rgba(40,167,69,0.1);color:#1e7e34;border:1px solid rgba(40,167,69,0.3);}
.btn-accept:hover{background:rgba(40,167,69,0.2);}

/* Modal */
.modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:200;justify-content:center;align-items:flex-start;padding-top:40px;overflow-y:auto;}
.modal-overlay.active{display:flex;}
.modal-box{background:var(--bg-card);border-radius:12px;padding:28px;width:95%;max-width:800px;border:1px solid var(--border);margin-bottom:40px;}
.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;}
.modal-title{font-size:16px;font-weight:800;color:var(--text-main);}
.modal-close{background:none;border:none;color:var(--text-muted);font-size:24px;cursor:pointer;line-height:1;}

/* Acta preview inside modal */
.acta-doc{background:#fff;color:#000;padding:24px;border-radius:8px;font-family:Arial,sans-serif;margin-bottom:16px;}
.acta-doc .acta-header{border-bottom:2px solid #000;padding-bottom:10px;margin-bottom:14px;}
.acta-doc .acta-logo-row{display:flex;align-items:center;justify-content:space-between;}
.acta-doc .acta-logo-row img{height:50px;object-fit:contain;}
.acta-doc .acta-titulo{font-size:14px;font-weight:700;text-align:right;}
.acta-doc .acta-meta{display:flex;justify-content:space-between;margin-bottom:12px;font-size:11px;background:#f5f5f5;padding:5px 8px;border-radius:3px;}
.acta-doc .acta-section{margin-bottom:10px;}
.acta-doc .acta-section-title{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1px;background:#111;color:#fff;padding:3px 8px;margin-bottom:6px;}
.acta-doc .acta-equipos-table{width:100%;border-collapse:collapse;font-size:11px;}
.acta-doc .acta-equipos-table th{background:#333;color:#fff;padding:5px 8px;text-align:left;font-size:9px;font-weight:700;text-transform:uppercase;}
.acta-doc .acta-equipos-table td{padding:5px 8px;border-bottom:1px solid #ddd;font-size:11px;}
.acta-doc .acta-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;}
.acta-doc .acta-field{display:flex;flex-direction:column;padding:2px 0;}
.acta-doc .af-label{font-size:9px;font-weight:700;text-transform:uppercase;color:#666;}
.acta-doc .af-value{font-size:11px;font-weight:700;color:#000;border-bottom:1px solid #ddd;padding-bottom:2px;min-height:18px;}
.acta-doc .acta-obs{border:1px solid #ccc;padding:7px;min-height:40px;font-size:11px;margin-top:3px;}
.acta-doc .acta-firmas{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:24px;}
.acta-doc .acta-firma{text-align:center;}
.acta-doc .firma-linea{border-top:1px solid #000;margin-bottom:4px;margin-top:36px;}
.acta-doc .firma-nombre{font-size:11px;font-weight:700;}
.acta-doc .firma-cargo{font-size:10px;color:#555;}
.acta-doc .acta-footer{margin-top:14px;text-align:center;font-size:9px;color:#888;border-top:1px solid #ccc;padding-top:6px;}

/* Resguardo section */
.resguardo-section{background:var(--bg-card);border:2px dashed var(--color-principal);border-radius:10px;padding:20px;margin-top:16px;}
.resguardo-section h3{font-size:13px;font-weight:800;color:var(--color-principal);margin-bottom:14px;}
.resguardo-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.resguardo-grid .form-group{display:flex;flex-direction:column;gap:4px;}
.resguardo-grid .form-group.full{grid-column:1/-1;}
.resguardo-grid label{font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-sub);letter-spacing:0.5px;}
.resguardo-grid input,.resguardo-grid textarea{padding:9px 12px;border-radius:6px;border:1.5px solid var(--border);background:var(--bg-input);color:var(--text-main);font-size:13px;outline:none;font-family:Arial,sans-serif;}
.resguardo-grid input:focus,.resguardo-grid textarea:focus{border-color:var(--color-principal);}
.resguardo-grid textarea{resize:vertical;min-height:60px;}

/* Signature canvas */
.firma-canvas-wrap{border:2px solid var(--border2);border-radius:8px;overflow:hidden;background:#fff;position:relative;}
.firma-canvas-wrap canvas{display:block;cursor:crosshair;}
.firma-clear{position:absolute;top:6px;right:6px;padding:4px 10px;border-radius:4px;border:1px solid #ccc;background:#fff;color:#666;font-size:10px;cursor:pointer;z-index:5;}

.resguardo-actions{display:flex;gap:12px;margin-top:16px;}
.btn-aceptar{padding:12px 28px;border-radius:8px;border:none;background:#28a745;color:#fff;font-size:13px;font-weight:700;cursor:pointer;}
.btn-aceptar:hover{background:#218838;}
.btn-rechazar{padding:12px 28px;border-radius:8px;border:none;background:#dc3545;color:#fff;font-size:13px;font-weight:700;cursor:pointer;}
.btn-rechazar:hover{background:#c82333;}
.btn-imprimir{padding:12px 28px;border-radius:8px;border:none;background:var(--color-principal);color:#fff;font-size:13px;font-weight:700;cursor:pointer;}

/* Chart area */
.chart-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.chart-card{background:var(--bg-card);border-radius:10px;padding:20px;border:1px solid var(--border);}
.chart-card h3{font-size:12px;font-weight:800;color:var(--text-sub);text-transform:uppercase;letter-spacing:1px;margin-bottom:14px;}
.bar-chart{display:flex;flex-direction:column;gap:8px;}
.bar-row{display:flex;align-items:center;gap:10px;}
.bar-label{font-size:11px;color:var(--text-sub);min-width:80px;text-align:right;}
.bar-track{flex:1;height:24px;background:var(--bg-dark3);border-radius:4px;overflow:hidden;}
.bar-fill{height:100%;border-radius:4px;display:flex;align-items:center;padding-left:8px;font-size:10px;font-weight:700;color:#fff;transition:width 0.4s ease;}
.bar-count{font-size:11px;color:var(--text-muted);min-width:30px;}

@media(max-width:768px){
  .chart-grid{grid-template-columns:1fr;}
  .stats-grid{grid-template-columns:repeat(2,1fr);}
  .resguardo-grid{grid-template-columns:1fr;}
}
</style>
<?php brandingCSS('../../'); ?>
<script>window.NAGSA_LOGO_ABS='<?= htmlspecialchars($logo_abs) ?>';</script>
</head>
<body>
<div class="topbar">
  <div style="display:flex;align-items:center;gap:12px;">
    <img src="<?= htmlspecialchars('../../'.$branding['logo']) ?>" alt="<?= htmlspecialchars($branding['nombre']) ?>" style="height:32px;object-fit:contain;">
    <div class="sub">Reportes y Estad&iacute;sticas</div>
  </div>
  <a href="../../index.php" class="btn-back">&larr; Men&uacute;</a>
  <div class="user">
    <span>&#128100; <?=htmlspecialchars($_SESSION['nagsa_name']?:$_SESSION['nagsa_user'])?></span>
    <a href="../../index.php?logout=1" class="btn-logout">&#128682; Salir</a>
  </div>
</div>

<div class="container">
  <!-- Tabs -->
  <div class="tabs">
    <div class="tab active" onclick="switchTab('registro')">&#128203; Registro de Actas</div>
    <div class="tab" onclick="switchTab('resguardo')">&#9989; Aceptaci&oacute;n / Resguardo</div>
    <div class="tab" onclick="switchTab('estadisticas')">&#128202; Estad&iacute;sticas</div>
  </div>

  <!-- TAB: Registro de Actas -->
  <div class="tab-content active" id="tab-registro">
    <div class="card">
      <div class="section-title">Filtros</div>
      <div class="filters">
        <input type="text" id="f-buscar" placeholder="Buscar por n&uacute;mero, persona...">
        <select id="f-tipo"><option value="">Todos los tipos</option><option value="entrega">Entrega</option><option value="salida">Salida</option></select>
        <select id="f-estado"><option value="">Todos los estados</option><option value="pendiente">Pendiente</option><option value="aceptada">Aceptada</option><option value="rechazada">Rechazada</option></select>
        <input type="date" id="f-desde" title="Desde">
        <input type="date" id="f-hasta" title="Hasta">
        <button class="btn-filter" onclick="cargarActas()">&#128269; Filtrar</button>
        <button class="btn-clear" onclick="limpiarFiltros()">Limpiar</button>
      </div>
    </div>

    <div class="card" style="padding:0;overflow:hidden;">
      <div style="max-height:500px;overflow-y:auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th>N&deg; Acta</th>
              <th>Tipo</th>
              <th>Fecha</th>
              <th>Personas</th>
              <th>Equipos</th>
              <th>Estado</th>
              <th>Creado por</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody id="tabla-actas">
            <tr class="empty-row"><td colspan="8">&#128196; Cargando actas...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- TAB: Aceptación / Resguardo -->
  <div class="tab-content" id="tab-resguardo">
    <div class="card">
      <div class="section-title">Actas Pendientes de Aceptaci&oacute;n</div>
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">Selecciona un acta pendiente para revisarla y aceptar o rechazar el resguardo de los equipos.</p>
      <div style="max-height:400px;overflow-y:auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th>N&deg; Acta</th>
              <th>Tipo</th>
              <th>Fecha</th>
              <th>Personas</th>
              <th>Equipos</th>
              <th>Creado por</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="tabla-pendientes">
            <tr class="empty-row"><td colspan="7">Cargando...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- TAB: Estadísticas -->
  <div class="tab-content" id="tab-estadisticas">
    <div class="stats-grid" id="stats-cards"></div>
    <div class="chart-grid" id="stats-charts"></div>
  </div>
</div>

<!-- Modal: Ver detalle / Resguardo -->
<div class="modal-overlay" id="modal-detalle">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title" id="modal-titulo">Detalle del Acta</span>
      <button class="modal-close" onclick="cerrarModal()">&times;</button>
    </div>
    <div id="modal-body"></div>
  </div>
</div>

<script>
let actasData=[], currentActa=null;

// ── Tabs ────────────────────────────────────────────────────────────────────
function switchTab(tab){
  document.querySelectorAll('.tab').forEach((t,i)=>{
    const tabs=['registro','resguardo','estadisticas'];
    t.classList.toggle('active',tabs[i]===tab);
  });
  document.querySelectorAll('.tab-content').forEach(c=>c.classList.remove('active'));
  document.getElementById('tab-'+tab).classList.add('active');
  if(tab==='registro') cargarActas();
  if(tab==='resguardo') cargarPendientes();
  if(tab==='estadisticas') cargarEstadisticas();
}

// ── Cargar actas (registro) ─────────────────────────────────────────────────
async function cargarActas(){
  const params=new URLSearchParams();
  const buscar=document.getElementById('f-buscar').value.trim();
  const tipo=document.getElementById('f-tipo').value;
  const estado=document.getElementById('f-estado').value;
  const desde=document.getElementById('f-desde').value;
  const hasta=document.getElementById('f-hasta').value;
  if(buscar) params.set('buscar',buscar);
  if(tipo) params.set('tipo',tipo);
  if(estado) params.set('estado',estado);
  if(desde) params.set('desde',desde);
  if(hasta) params.set('hasta',hasta);
  params.set('action','listar');

  const tbody=document.getElementById('tabla-actas');
  tbody.innerHTML='<tr class="empty-row"><td colspan="8">Cargando...</td></tr>';

  try{
    const data=await fetch('reportes_api.php?'+params.toString()).then(r=>r.json());
    actasData=data;
    if(!data.length){
      tbody.innerHTML='<tr class="empty-row"><td colspan="8">&#128196; No se encontraron actas con los filtros aplicados.</td></tr>';
      return;
    }
    tbody.innerHTML=data.map(a=>{
      const personas=a.tipo==='entrega'
        ? `<small>${a.entregado_por||'---'} &rarr; ${a.recibido_por||'---'}</small>`
        : `<small>${a.autorizado_por||'---'} &rarr; ${a.retira_persona||'---'}</small>`;
      const fechaF=new Date(a.fecha+'T12:00:00').toLocaleDateString('es-EC',{day:'2-digit',month:'short',year:'numeric'});
      return `<tr>
        <td><strong>${a.numero}</strong></td>
        <td><span class="badge badge-${a.tipo}">${a.tipo==='entrega'?'Entrega':'Salida'}</span></td>
        <td>${fechaF}</td>
        <td>${personas}</td>
        <td style="text-align:center;font-weight:700;">${a.total_equipos}</td>
        <td><span class="badge badge-${a.estado}">${a.estado.charAt(0).toUpperCase()+a.estado.slice(1)}</span></td>
        <td><small>${a.created_by}</small></td>
        <td>
          <button class="btn-sm btn-view" onclick="verDetalle(${a.id})">&#128065; Ver</button>
          <button class="btn-sm btn-accept" onclick="verDetalle(${a.id},true)" ${a.estado!=='pendiente'?'disabled style="opacity:0.4;cursor:not-allowed"':''}>&#9989; Resguardo</button>
        </td>
      </tr>`;
    }).join('');
  }catch(e){
    tbody.innerHTML='<tr class="empty-row"><td colspan="8">Error al cargar actas: '+e.message+'</td></tr>';
  }
}

function limpiarFiltros(){
  ['f-buscar','f-tipo','f-estado','f-desde','f-hasta'].forEach(id=>{
    document.getElementById(id).value='';
  });
  cargarActas();
}

// ── Cargar pendientes (resguardo) ───────────────────────────────────────────
async function cargarPendientes(){
  const tbody=document.getElementById('tabla-pendientes');
  tbody.innerHTML='<tr class="empty-row"><td colspan="7">Cargando...</td></tr>';
  try{
    const data=await fetch('reportes_api.php?action=listar&estado=pendiente').then(r=>r.json());
    if(!data.length){
      tbody.innerHTML='<tr class="empty-row"><td colspan="7">&#9989; No hay actas pendientes de aceptaci&oacute;n.</td></tr>';
      return;
    }
    tbody.innerHTML=data.map(a=>{
      const personas=a.tipo==='entrega'
        ? `<small>${a.entregado_por||'---'} &rarr; ${a.recibido_por||'---'}</small>`
        : `<small>${a.autorizado_por||'---'} &rarr; ${a.retira_persona||'---'}</small>`;
      const fechaF=new Date(a.fecha+'T12:00:00').toLocaleDateString('es-EC',{day:'2-digit',month:'short',year:'numeric'});
      return `<tr>
        <td><strong>${a.numero}</strong></td>
        <td><span class="badge badge-${a.tipo}">${a.tipo==='entrega'?'Entrega':'Salida'}</span></td>
        <td>${fechaF}</td>
        <td>${personas}</td>
        <td style="text-align:center;font-weight:700;">${a.total_equipos}</td>
        <td><small>${a.created_by}</small></td>
        <td><button class="btn-sm btn-accept" onclick="verDetalle(${a.id},true)">&#128221; Revisar y Aceptar</button></td>
      </tr>`;
    }).join('');
  }catch(e){
    tbody.innerHTML='<tr class="empty-row"><td colspan="7">Error: '+e.message+'</td></tr>';
  }
}

// ── Ver detalle de acta ─────────────────────────────────────────────────────
async function verDetalle(id, modoResguardo=false){
  try{
    currentActa=await fetch('reportes_api.php?action=detalle&id='+id).then(r=>r.json());
    if(currentActa.error){alert(currentActa.error);return;}

    const a=currentActa;
    const fechaF=new Date(a.fecha+'T12:00:00').toLocaleDateString('es-EC',{day:'2-digit',month:'long',year:'numeric'});
    const tipoLabel=a.tipo==='entrega'?'ACTA DE ENTREGA DE EQUIPO':'ACTA DE SALIDA DE EQUIPO';
    const logo=window.NAGSA_LOGO_ABS||'';
    const nombre=window.NAGSA_NOMBRE||'';

    // Build equipment rows
    const equipos=a.equipos||[];
    const equiposRows=equipos.map((eq,i)=>`<tr>
      <td style="text-align:center;font-weight:700;">${i+1}</td>
      <td><strong>${eq.nombre||'---'}</strong></td>
      <td>${eq.tipo||'---'}</td>
      <td>${eq.fabricante||'---'}</td>
      <td>${eq.modelo||'---'}</td>
      <td>${eq.serie||'---'}</td>
    </tr>`).join('');

    // Build person data
    let datosHTML='';
    if(a.tipo==='entrega'){
      datosHTML=`<div class="acta-section"><div class="acta-section-title">Datos de Entrega</div>
        <div class="acta-grid">
          <div class="acta-field"><span class="af-label">Entregado por</span><span class="af-value">${a.entregado_por||'---'}</span></div>
          <div class="acta-field"><span class="af-label">Cargo</span><span class="af-value">${a.entregado_cargo||'---'}</span></div>
          <div class="acta-field"><span class="af-label">Recibido por</span><span class="af-value">${a.recibido_por||'---'}</span></div>
          <div class="acta-field"><span class="af-label">Cargo</span><span class="af-value">${a.recibido_cargo||'---'}</span></div>
        </div></div>`;
    } else {
      datosHTML=`<div class="acta-section"><div class="acta-section-title">Datos de Salida</div>
        <div class="acta-grid">
          <div class="acta-field"><span class="af-label">Autorizado por</span><span class="af-value">${a.autorizado_por||'---'}</span></div>
          <div class="acta-field"><span class="af-label">Cargo</span><span class="af-value">${a.autorizado_cargo||'---'}</span></div>
          <div class="acta-field"><span class="af-label">Motivo</span><span class="af-value">${a.motivo||'---'}</span></div>
          <div class="acta-field"><span class="af-label">Destino</span><span class="af-value">${a.destino||'---'}</span></div>
          <div class="acta-field"><span class="af-label">Persona que retira</span><span class="af-value">${a.retira_persona||'---'}</span></div>
          <div class="acta-field"><span class="af-label">Cargo</span><span class="af-value">${a.retira_cargo||'---'}</span></div>
        </div></div>`;
    }

    // Estado badge
    let estadoInfo='';
    if(a.estado!=='pendiente'){
      const fechaAc=a.aceptada_fecha?new Date(a.aceptada_fecha).toLocaleString('es-EC'):'---';
      estadoInfo=`<div style="margin-top:12px;padding:12px;border-radius:8px;background:${a.estado==='aceptada'?'rgba(40,167,69,0.08)':'rgba(220,53,69,0.08)'};border:1px solid ${a.estado==='aceptada'?'rgba(40,167,69,0.3)':'rgba(220,53,69,0.3)'};">
        <strong style="color:${a.estado==='aceptada'?'#1e7e34':'#a71d2a'};">${a.estado==='aceptada'?'&#9989; ACEPTADA':'&#10060; RECHAZADA'}</strong>
        <div style="font-size:11px;color:var(--text-sub);margin-top:4px;">
          <div>Por: <strong>${a.aceptada_por||'---'}</strong></div>
          <div>Fecha: ${fechaAc}</div>
          ${a.aceptada_observaciones?'<div>Obs: '+a.aceptada_observaciones+'</div>':''}
        </div>
      </div>`;
    }

    let html=`
      <div class="acta-doc">
        <div class="acta-header"><div class="acta-logo-row">
          <img src="${logo}" alt="${nombre}" onerror="this.style.display='none'" style="height:50px;object-fit:contain;">
          <div style="text-align:right;">
            <div class="acta-titulo">${tipoLabel}</div>
            <div style="font-size:10px;color:#555;margin-top:2px;"><b>N&deg; Acta:</b> ${a.numero}</div>
          </div>
        </div></div>
        <div class="acta-meta"><span><b>Fecha:</b> ${fechaF}</span><span><b>Lugar:</b> ${a.lugar||'---'}</span></div>
        ${datosHTML}
        <div class="acta-section"><div class="acta-section-title">Equipos (${equipos.length})</div>
          <table class="acta-equipos-table"><thead><tr><th>#</th><th>Nombre</th><th>Tipo</th><th>Fabricante</th><th>Modelo</th><th>S/N</th></tr></thead>
          <tbody>${equiposRows}</tbody></table></div>
        <div class="acta-section"><div class="acta-section-title">Observaciones</div>
          <div class="acta-obs">${a.observaciones||'Ninguna'}</div></div>
        <div class="acta-footer">${nombre} &middot; Gesti&oacute;n de Activos TI &middot; Generado el ${a.created_at?new Date(a.created_at).toLocaleString('es-EC'):'---'}</div>
      </div>
      ${estadoInfo}`;

    // Resguardo section
    if(modoResguardo && a.estado==='pendiente'){
      html+=`
        <div class="resguardo-section">
          <h3>&#128221; Aceptaci&oacute;n / Resguardo del Acta</h3>
          <div class="resguardo-grid">
            <div class="form-group">
              <label>Aceptado / Rechazado por</label>
              <input type="text" id="res-persona" value="<?=htmlspecialchars($_SESSION['nagsa_name']?:$_SESSION['nagsa_user'])?>">
            </div>
            <div class="form-group">
              <label>Observaciones</label>
              <input type="text" id="res-obs" placeholder="Observaciones opcionales...">
            </div>
            <div class="form-group full">
              <label>Firma</label>
              <div class="firma-canvas-wrap" style="width:100%;max-width:500px;">
                <canvas id="firma-canvas" width="500" height="150"></canvas>
                <button class="firma-clear" onclick="limpiarFirma()">Limpiar</button>
              </div>
            </div>
          </div>
          <div class="resguardo-actions">
            <button class="btn-aceptar" onclick="procesarResguardo('aceptada')">&#9989; Aceptar Resguardo</button>
            <button class="btn-rechazar" onclick="procesarResguardo('rechazada')">&#10060; Rechazar</button>
            <button class="btn-imprimir" onclick="imprimirDesdeModal()">&#128424; Imprimir / PDF</button>
          </div>
        </div>`;
    } else {
      html+=`<div style="margin-top:16px;display:flex;gap:12px;">
        <button class="btn-imprimir" onclick="imprimirDesdeModal()">&#128424; Imprimir / PDF</button>
      </div>`;
    }

    document.getElementById('modal-titulo').textContent=
      (modoResguardo&&a.estado==='pendiente'?'Resguardo: ':'Detalle: ')+a.numero;
    document.getElementById('modal-body').innerHTML=html;
    document.getElementById('modal-detalle').classList.add('active');

    // Initialize signature canvas if present
    if(modoResguardo && a.estado==='pendiente'){
      setTimeout(initFirma,100);
    }
  }catch(e){
    alert('Error al cargar detalle: '+e.message);
  }
}

function cerrarModal(){
  document.getElementById('modal-detalle').classList.remove('active');
  currentActa=null;
}

// ── Firma canvas ────────────────────────────────────────────────────────────
let firmaCtx=null, firmando=false;

function initFirma(){
  const canvas=document.getElementById('firma-canvas');
  if(!canvas) return;
  firmaCtx=canvas.getContext('2d');
  firmaCtx.strokeStyle='#000';
  firmaCtx.lineWidth=2;
  firmaCtx.lineCap='round';

  canvas.addEventListener('mousedown',e=>{firmando=true;firmaCtx.beginPath();firmaCtx.moveTo(e.offsetX,e.offsetY);});
  canvas.addEventListener('mousemove',e=>{if(!firmando)return;firmaCtx.lineTo(e.offsetX,e.offsetY);firmaCtx.stroke();});
  canvas.addEventListener('mouseup',()=>firmando=false);
  canvas.addEventListener('mouseleave',()=>firmando=false);

  // Touch support
  canvas.addEventListener('touchstart',e=>{e.preventDefault();const t=e.touches[0];const r=canvas.getBoundingClientRect();firmando=true;firmaCtx.beginPath();firmaCtx.moveTo(t.clientX-r.left,t.clientY-r.top);});
  canvas.addEventListener('touchmove',e=>{e.preventDefault();if(!firmando)return;const t=e.touches[0];const r=canvas.getBoundingClientRect();firmaCtx.lineTo(t.clientX-r.left,t.clientY-r.top);firmaCtx.stroke();});
  canvas.addEventListener('touchend',()=>firmando=false);
}

function limpiarFirma(){
  const canvas=document.getElementById('firma-canvas');
  if(canvas&&firmaCtx) firmaCtx.clearRect(0,0,canvas.width,canvas.height);
}

function getFirmaBase64(){
  const canvas=document.getElementById('firma-canvas');
  if(!canvas) return null;
  const blank=document.createElement('canvas');
  blank.width=canvas.width;blank.height=canvas.height;
  if(canvas.toDataURL()===blank.toDataURL()) return null;
  return canvas.toDataURL('image/png');
}

// ── Procesar resguardo ──────────────────────────────────────────────────────
async function procesarResguardo(estado){
  if(!currentActa) return;
  const persona=document.getElementById('res-persona').value.trim();
  if(!persona){alert('Indica quién acepta o rechaza el acta.');return;}

  const firma=getFirmaBase64();
  if(estado==='aceptada'&&!firma){
    if(!confirm('No se ha firmado el acta. ¿Desea continuar sin firma?')) return;
  }

  const confirmMsg=estado==='aceptada'
    ?'¿Confirmas la ACEPTACIÓN y resguardo de esta acta?'
    :'¿Confirmas el RECHAZO de esta acta?';
  if(!confirm(confirmMsg)) return;

  try{
    const resp=await fetch('reportes_api.php?action=resguardo',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        id: currentActa.id,
        estado: estado,
        aceptada_por: persona,
        observaciones: document.getElementById('res-obs').value.trim(),
        firma: firma
      })
    });
    const data=await resp.json();
    if(data.ok){
      alert(estado==='aceptada'?'Acta aceptada correctamente.':'Acta rechazada.');
      cerrarModal();
      cargarActas();
      cargarPendientes();
    } else {
      alert('Error: '+(data.error||'No se pudo procesar'));
    }
  }catch(e){
    alert('Error de conexión: '+e.message);
  }
}

// ── Imprimir desde modal ────────────────────────────────────────────────────
function imprimirDesdeModal(){
  const doc=document.querySelector('#modal-body .acta-doc');
  if(!doc) return;
  const win=window.open('','_blank','width=800,height=600');
  win.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Acta</title>
  <style>@page{margin:12mm 15mm;size:A4 portrait;}*{box-sizing:border-box;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}body{font-family:Arial,sans-serif;color:#000;background:#fff;margin:0;padding:10mm;}
  .acta-header{border-bottom:2px solid #000;padding-bottom:10px;margin-bottom:14px;}.acta-logo-row{display:flex;align-items:center;justify-content:space-between;}.acta-logo-row img{height:50px;object-fit:contain;}.acta-titulo{font-size:13px;font-weight:700;text-align:right;}.acta-meta{display:flex;justify-content:space-between;margin-bottom:12px;font-size:11px;background:#f5f5f5;padding:5px 8px;border-radius:3px;}.acta-section{margin-bottom:10px;}.acta-section-title{font-size:10px;font-weight:900;text-transform:uppercase;background:#111;color:#fff;padding:3px 8px;margin-bottom:6px;}.acta-equipos-table{width:100%;border-collapse:collapse;font-size:10px;}.acta-equipos-table th{background:#333;color:#fff;padding:4px 6px;text-align:left;font-size:9px;font-weight:700;text-transform:uppercase;}.acta-equipos-table td{padding:4px 6px;border-bottom:1px solid #ddd;}.acta-grid{display:grid;grid-template-columns:1fr 1fr;gap:5px;}.acta-field{display:flex;flex-direction:column;padding:2px 0;}.af-label{font-size:8px;font-weight:700;text-transform:uppercase;color:#666;}.af-value{font-size:11px;font-weight:700;color:#000;border-bottom:1px solid #ddd;padding-bottom:1px;min-height:16px;}.acta-obs{border:1px solid #ccc;padding:6px;min-height:40px;font-size:11px;margin-top:3px;}.acta-firmas{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:24px;}.acta-firma{text-align:center;}.firma-linea{border-top:1px solid #000;margin-bottom:4px;margin-top:36px;}.firma-nombre{font-size:11px;font-weight:700;}.firma-cargo{font-size:10px;color:#555;}.acta-footer{margin-top:14px;text-align:center;font-size:9px;color:#888;border-top:1px solid #ccc;padding-top:6px;}
  </style></head><body>${doc.innerHTML}</body></html>`);
  win.document.close();
  win.onload=()=>{win.focus();win.print();win.onafterprint=()=>win.close();};
}

// ── Estadísticas ────────────────────────────────────────────────────────────
async function cargarEstadisticas(){
  try{
    const stats=await fetch('reportes_api.php?action=estadisticas').then(r=>r.json());

    // Summary cards
    const general=stats.general||{total:0,equipos:0};
    const porEstado=stats.por_estado||[];
    const pendientes=porEstado.find(e=>e.estado==='pendiente')?.total||0;
    const aceptadas=porEstado.find(e=>e.estado==='aceptada')?.total||0;
    const rechazadas=porEstado.find(e=>e.estado==='rechazada')?.total||0;

    document.getElementById('stats-cards').innerHTML=`
      <div class="stat-card"><div class="stat-number">${general.total||0}</div><div class="stat-label">Total Actas</div></div>
      <div class="stat-card"><div class="stat-number">${general.equipos||0}</div><div class="stat-label">Total Equipos</div></div>
      <div class="stat-card"><div class="stat-number" style="color:#d39e00;">${pendientes}</div><div class="stat-label">Pendientes</div></div>
      <div class="stat-card"><div class="stat-number" style="color:#1e7e34;">${aceptadas}</div><div class="stat-label">Aceptadas</div></div>
      <div class="stat-card"><div class="stat-number" style="color:#a71d2a;">${rechazadas}</div><div class="stat-label">Rechazadas</div></div>
    `;

    // Charts
    const porTipo=stats.por_tipo||[];
    const maxTipo=Math.max(...porTipo.map(t=>t.total),1);
    const tipoChart=porTipo.map(t=>{
      const pct=Math.round((t.total/maxTipo)*100);
      const color=t.tipo==='entrega'?'#007bff':'#ff9800';
      return `<div class="bar-row">
        <div class="bar-label">${t.tipo==='entrega'?'Entrega':'Salida'}</div>
        <div class="bar-track"><div class="bar-fill" style="width:${pct}%;background:${color};">${t.total}</div></div>
      </div>`;
    }).join('');

    const porMes=stats.por_mes||[];
    const meses=[...new Set(porMes.map(m=>m.mes))].sort();
    const maxMes=Math.max(...porMes.map(m=>m.total),1);
    const mesChart=meses.map(mes=>{
      const items=porMes.filter(m=>m.mes===mes);
      const total=items.reduce((s,i)=>s+i.total,0);
      const pct=Math.round((total/maxMes)*100);
      const mesLabel=new Date(mes+'-01T12:00:00').toLocaleDateString('es-EC',{month:'short',year:'2-digit'});
      return `<div class="bar-row">
        <div class="bar-label">${mesLabel}</div>
        <div class="bar-track"><div class="bar-fill" style="width:${pct}%;background:var(--color-principal);">${total}</div></div>
      </div>`;
    }).join('');

    const topUsuarios=stats.top_usuarios||[];
    const maxUser=Math.max(...topUsuarios.map(u=>u.total),1);
    const userChart=topUsuarios.map(u=>{
      const pct=Math.round((u.total/maxUser)*100);
      return `<div class="bar-row">
        <div class="bar-label">${u.created_by}</div>
        <div class="bar-track"><div class="bar-fill" style="width:${pct}%;background:#6f42c1;">${u.total}</div></div>
      </div>`;
    }).join('');

    document.getElementById('stats-charts').innerHTML=`
      <div class="chart-card"><h3>Por Tipo</h3><div class="bar-chart">${tipoChart||'<p style="color:var(--text-muted);font-size:12px;">Sin datos</p>'}</div></div>
      <div class="chart-card"><h3>Por Mes (6 meses)</h3><div class="bar-chart">${mesChart||'<p style="color:var(--text-muted);font-size:12px;">Sin datos</p>'}</div></div>
      <div class="chart-card"><h3>Top Usuarios</h3><div class="bar-chart">${userChart||'<p style="color:var(--text-muted);font-size:12px;">Sin datos</p>'}</div></div>
      <div class="chart-card"><h3>Por Estado</h3><div class="bar-chart">
        ${porEstado.map(e=>{
          const colors={pendiente:'#ffc107',aceptada:'#28a745',rechazada:'#dc3545'};
          const pct=Math.round((e.total/Math.max(...porEstado.map(x=>x.total),1))*100);
          return `<div class="bar-row">
            <div class="bar-label">${e.estado.charAt(0).toUpperCase()+e.estado.slice(1)}</div>
            <div class="bar-track"><div class="bar-fill" style="width:${pct}%;background:${colors[e.estado]||'#666'};">${e.total}</div></div>
          </div>`;
        }).join('')||'<p style="color:var(--text-muted);font-size:12px;">Sin datos</p>'}
      </div></div>
    `;
  }catch(e){
    document.getElementById('stats-cards').innerHTML=`<div class="stat-card"><div class="stat-label">Error al cargar estad&iacute;sticas: ${e.message}</div></div>`;
  }
}

// Close modal on outside click
document.addEventListener('click',e=>{
  if(e.target.id==='modal-detalle') cerrarModal();
});

// Init
cargarActas();
</script>
</body>
</html>
