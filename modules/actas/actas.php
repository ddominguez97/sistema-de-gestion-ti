<?php
session_start();
$session_duration = 4 * 60 * 60;
if(isset($_SESSION['last_activity']) && (time()-$_SESSION['last_activity'])>$session_duration){
    session_unset(); session_destroy();
}
if(!isset($_SESSION['nagsa_user'])){ header('Location: ../../index.php'); exit; }
$_SESSION['last_activity'] = time();
require_once __DIR__ . '/../../config/config.php';
checkModulo('actas');
$branding = getBranding();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Actas &mdash; <?= htmlspecialchars($branding['nombre']) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,sans-serif;background:var(--bg-body);color:var(--text-main);min-height:100vh;}
.topbar{background:var(--bg-topbar);border-bottom:2px solid var(--color-principal);padding:12px 24px;display:flex;align-items:center;gap:16px;}
.topbar .sub{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-top:2px;}
.topbar .user{margin-left:auto;display:flex;align-items:center;gap:12px;font-size:12px;color:var(--text-sub);}
.topbar a.btn-back{padding:6px 14px;border-radius:6px;background:var(--bg-hover);color:var(--text-sub);font-size:11px;font-weight:700;text-decoration:none;border:1px solid var(--border);}
.topbar a.btn-logout{padding:6px 14px;border-radius:6px;background:var(--color-principal);color:#fff;font-size:11px;font-weight:700;text-decoration:none;}
.container{max-width:960px;margin:30px auto;padding:0 20px;}
.section-title{font-size:10px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:var(--color-principal);margin-bottom:12px;}
.card{background:var(--bg-card);border-radius:10px;padding:24px;border:1px solid var(--border);margin-bottom:20px;}
.tipo-btns{display:flex;gap:12px;}
.tipo-btn{flex:1;padding:14px;border-radius:8px;border:1.5px solid var(--border2);background:transparent;color:var(--text-sub);font-size:13px;font-weight:700;cursor:pointer;transition:all 0.15s;text-align:center;}
.tipo-btn.active{border-color:var(--color-principal);background:rgba(0,0,0,0.08);color:var(--color-principal);}
.search-wrap{position:relative;}
.search-wrap input{width:100%;padding:10px 36px;border-radius:7px;border:1.5px solid var(--border2);background:var(--bg-input);color:var(--text-main);font-size:13px;outline:none;transition:border-color 0.15s;}
.search-wrap input:focus{border-color:var(--color-principal);}
.search-icon{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:14px;color:var(--text-muted);pointer-events:none;}
.search-results{position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--bg-card);border:1px solid var(--border2);border-radius:8px;z-index:100;max-height:240px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,0.2);}
.search-result-item{padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border);font-size:12px;color:var(--text-main);}
.search-result-item:last-child{border-bottom:none;}
.search-result-item:hover{background:var(--bg-hover);}
.r-name{font-weight:700;font-size:13px;}
.r-tipo{color:var(--color-principal);font-size:10px;font-weight:700;margin-left:6px;}
.r-sub{color:var(--text-muted);font-size:11px;margin-top:2px;}
.no-results{color:var(--text-muted);cursor:default!important;}
.equipo-row{display:flex;gap:10px;align-items:flex-start;}
.equipo-row .search-wrap{flex:1;}
.btn-manual{padding:10px 16px;border-radius:7px;border:1.5px dashed var(--border2);background:transparent;color:var(--text-muted);font-size:12px;cursor:pointer;white-space:nowrap;flex-shrink:0;transition:all 0.15s;}
.btn-manual:hover{border-color:var(--color-principal);color:var(--color-principal);}
.equipos-table{width:100%;border-collapse:collapse;margin-top:16px;font-size:12px;}
.equipos-table th{background:var(--bg-dark3);color:var(--text-sub);padding:8px 10px;text-align:left;font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;border-bottom:2px solid var(--border);}
.equipos-table td{padding:8px 10px;border-bottom:1px solid var(--border);color:var(--text-main);vertical-align:middle;}
.equipos-table tr:hover td{background:var(--bg-hover);}
.eq-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:rgba(0,0,0,0.08);color:var(--text-sub);}
.btn-remove{background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:16px;padding:2px 6px;border-radius:4px;}
.btn-remove:hover{color:#dc3545;}
.empty-equipos{text-align:center;padding:24px;color:var(--text-muted);font-size:13px;}
.modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:200;justify-content:center;align-items:center;}
.modal-overlay.active{display:flex;}
.modal-box{background:var(--bg-card);border-radius:12px;padding:24px;width:90%;max-width:560px;border:1px solid var(--border);}
.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;}
.modal-title{font-size:14px;font-weight:800;color:var(--text-main);}
.modal-close{background:none;border:none;color:var(--text-muted);font-size:22px;cursor:pointer;line-height:1;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;}
.form-group{display:flex;flex-direction:column;gap:4px;}
.form-group.full{grid-column:1/-1;}
.form-group label{font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-sub);letter-spacing:0.5px;}
.form-group input,.form-group select,.form-group textarea{padding:9px 12px;border-radius:6px;border:1.5px solid var(--border);background:var(--bg-input);color:var(--text-main);font-size:13px;outline:none;font-family:Arial,sans-serif;transition:border-color 0.15s;}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--color-principal);}
.form-group textarea{resize:vertical;min-height:70px;}
.form-group select option{background:var(--bg-input);}
.user-wrap{position:relative;}
.user-wrap input{width:100%;padding:9px 12px;border-radius:6px;border:1.5px solid var(--border);background:var(--bg-input);color:var(--text-main);font-size:13px;outline:none;transition:border-color 0.15s;}
.user-wrap input:focus{border-color:var(--color-principal);}
.user-results{position:absolute;top:calc(100% + 2px);left:0;right:0;background:var(--bg-card);border:1px solid var(--border2);border-radius:6px;z-index:100;max-height:200px;overflow-y:auto;box-shadow:0 4px 16px rgba(0,0,0,0.2);display:none;}
.user-item{padding:8px 12px;cursor:pointer;border-bottom:1px solid var(--border);font-size:12px;color:var(--text-main);}
.user-item:last-child{border-bottom:none;}
.user-item:hover{background:var(--bg-hover);}
.u-nombre{font-weight:700;}
.u-sub{color:var(--text-muted);font-size:11px;}
.btn-print{width:100%;padding:14px;border-radius:8px;border:none;background:var(--color-principal);color:#fff;font-size:14px;font-weight:700;cursor:pointer;margin-top:8px;}
.btn-add-equipo{padding:10px 18px;border-radius:7px;border:none;background:var(--color-principal);color:#fff;font-size:12px;font-weight:700;cursor:pointer;}
.acta-preview-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;}
.acta-doc-screen{background:#fff;color:#000;padding:20px;border-radius:6px;font-family:Arial,sans-serif;}
.acta-doc-screen .acta-header{border-bottom:2px solid #000;padding-bottom:12px;margin-bottom:16px;}
.acta-doc-screen .acta-logo-row{display:flex;align-items:center;justify-content:space-between;}
.acta-doc-screen .acta-logo-row img{height:50px;object-fit:contain;}
.acta-doc-screen .acta-titulo{font-size:14px;font-weight:700;text-align:right;}
.acta-doc-screen .acta-meta{display:flex;justify-content:space-between;margin-bottom:14px;font-size:11px;background:#f5f5f5;padding:5px 8px;border-radius:3px;}
.acta-doc-screen .acta-section{margin-bottom:12px;}
.acta-doc-screen .acta-section-title{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1px;background:#111;color:#fff;padding:3px 8px;margin-bottom:7px;}
.acta-doc-screen .acta-equipos-table{width:100%;border-collapse:collapse;font-size:11px;}
.acta-doc-screen .acta-equipos-table th{background:#333;color:#fff;padding:5px 8px;text-align:left;font-size:9px;font-weight:700;text-transform:uppercase;}
.acta-doc-screen .acta-equipos-table td{padding:5px 8px;border-bottom:1px solid #ddd;font-size:11px;}
.acta-doc-screen .acta-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;}
.acta-doc-screen .acta-field{display:flex;flex-direction:column;padding:2px 0;}
.acta-doc-screen .af-label{font-size:9px;font-weight:700;text-transform:uppercase;color:#666;}
.acta-doc-screen .af-value{font-size:11px;font-weight:700;color:#000;border-bottom:1px solid #ddd;padding-bottom:2px;min-height:18px;}
.acta-doc-screen .acta-obs{border:1px solid #ccc;padding:7px;min-height:50px;font-size:11px;margin-top:3px;}
.acta-doc-screen .acta-firmas{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:28px;}
.acta-doc-screen .acta-firma{text-align:center;}
.acta-doc-screen .firma-linea{border-top:1px solid #000;margin-bottom:4px;margin-top:40px;}
.acta-doc-screen .firma-nombre{font-size:11px;font-weight:700;}
.acta-doc-screen .firma-cargo{font-size:10px;color:#555;}
.acta-doc-screen .acta-footer{margin-top:16px;text-align:center;font-size:9px;color:#888;border-top:1px solid #ccc;padding-top:7px;}
</style>
<?php
brandingCSS('../../');
// URL absoluta del logo usando branding ya cargado
$proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'];
$base     = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
$logo_file = $branding['logo']; // ya viene de getBranding() con timestamp
// Quitar el ?v=xxx para la ruta del archivo, conservarlo para la URL
$logo_clean = strtok($logo_file, '?');
$logo_abs   = $proto . '://' . $host . $base . '/' . $logo_file;
?>
<script>window.NAGSA_LOGO_ABS = '<?= htmlspecialchars($logo_abs) ?>';</script>
</head>
<body>
<div class="topbar">
  <div style="display:flex;align-items:center;gap:12px;">
    <img src="<?= htmlspecialchars('../../'.$branding['logo']) ?>" alt="<?= htmlspecialchars($branding['nombre']) ?>" style="height:32px;object-fit:contain;">
    <div class="sub">Sistema de Actas</div>
  </div>
  <a href="../../index.php" class="btn-back">&larr; Men&uacute;</a>
  <div class="user">
    <span>&#128100; <?=htmlspecialchars($_SESSION['nagsa_name']?:$_SESSION['nagsa_user'])?></span>
    <a href="../../index.php?logout=1" class="btn-logout">&#128682; Salir</a>
  </div>
</div>

<div class="container">

  <div class="card">
    <div class="section-title">Tipo de Acta</div>
    <div class="tipo-btns">
      <button class="tipo-btn active" id="btn-entrega" onclick="setTipo('entrega')">&#128203; Acta de Entrega</button>
      <button class="tipo-btn" id="btn-salida" onclick="setTipo('salida')">&#128228; Acta de Salida</button>
    </div>
  </div>

  <div class="card">
    <div class="section-title">Equipos</div>
    <div class="equipo-row">
      <div class="search-wrap">
        <span class="search-icon">&#128269;</span>
        <input type="text" id="equipo-search" placeholder="Buscar equipo por nombre en GLPI..." oninput="buscarEquipo(this.value)" autocomplete="off">
        <div class="search-results" id="search-results"></div>
      </div>
      <button class="btn-manual" onclick="abrirModalManual()">&#9998; Ingresar manual</button>
    </div>
    <div id="equipos-container">
      <div class="empty-equipos" id="empty-msg">&#128230; No hay equipos agregados. Busca un equipo o agr&eacute;galo manualmente.</div>
      <table class="equipos-table" id="equipos-table" style="display:none;">
        <thead><tr><th>#</th><th>Nombre</th><th>Tipo</th><th>Fabricante / Modelo</th><th>S/N</th><th></th></tr></thead>
        <tbody id="equipos-tbody"></tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="section-title">Datos del Acta</div>
    <div class="form-grid" style="margin-top:0;">
      <div class="form-group"><label>Fecha</label><input type="date" id="acta-fecha"></div>
      <div class="form-group"><label>Lugar</label><select id="acta-lugar"><option>Guayaquil</option></select></div>
    </div>

    <!-- Campos solo entrega -->
    <div id="campos-entrega">
      <div class="form-grid" style="margin-top:12px;">
        <div class="form-group"><label>Entregado por</label><div class="user-wrap"><input type="text" id="search-entrega" placeholder="Buscar usuario de GLPI..." oninput="buscarUsuario(this,'entrega')" autocomplete="off"><div class="user-results" id="results-entrega"></div></div></div>
        <div class="form-group"><label>Cargo / Departamento</label><input type="text" id="acta-entrega-cargo" placeholder="Cargo o departamento"></div>
        <div class="form-group"><label>Recibido por</label><div class="user-wrap"><input type="text" id="search-recibe" placeholder="Buscar usuario de GLPI..." oninput="buscarUsuario(this,'recibe')" autocomplete="off"><div class="user-results" id="results-recibe"></div></div></div>
        <div class="form-group"><label>Cargo / Departamento</label><input type="text" id="acta-recibe-cargo" placeholder="Cargo o departamento"></div>
      </div>
    </div>

    <!-- Campos solo salida -->
    <div id="campos-salida" style="display:none;">
      <div class="form-grid" style="margin-top:12px;">
        <div class="form-group"><label>Autorizado por</label><div class="user-wrap"><input type="text" id="search-autoriza" placeholder="Buscar usuario de GLPI..." oninput="buscarUsuario(this,'autoriza')" autocomplete="off"><div class="user-results" id="results-autoriza"></div></div></div>
        <div class="form-group"><label>Cargo / Departamento</label><input type="text" id="acta-autoriza-cargo" placeholder="Cargo o departamento"></div>
        <div class="form-group"><label>Motivo de salida</label><select id="acta-motivo"><option value="">-- Seleccionar motivo --</option></select></div>
        <div class="form-group"><label>Destino / Empresa</label><div class="user-wrap"><input type="text" id="search-destino" placeholder="Buscar proveedor o escribir destino..." oninput="buscarProveedor(this.value)" autocomplete="off"><div class="user-results" id="results-destino"></div></div></div>
        <div class="form-group"><label>Persona que retira</label><div class="user-wrap"><input type="text" id="search-retira" placeholder="Buscar en GLPI o escribir nombre..." oninput="buscarUsuario(this,'retira')" autocomplete="off"><div class="user-results" id="results-retira"></div></div></div>
        <div class="form-group"><label>Cargo</label><input type="text" id="acta-retira-cargo" placeholder="Cargo de quien retira"></div>
      </div>
    </div>

    <div class="form-grid" style="margin-top:12px;">
      <div class="form-group full"><label>Observaciones</label><textarea id="acta-obs" placeholder="Condiciones del equipo, accesorios incluidos, placa del vehículo si aplica..."></textarea></div>
    </div>
  </div>

  <button class="btn-print" onclick="generarActa()">&#128065; Vista previa e imprimir</button>

  <div class="card" id="acta-preview" style="display:none;margin-top:20px;">
    <div class="acta-preview-header">
      <span class="section-title" style="margin:0;">Vista Previa</span>
      <div style="display:flex;gap:10px;">
        <button onclick="imprimirActa()" style="padding:8px 20px;border-radius:6px;border:none;background:var(--color-principal);color:#fff;font-weight:700;cursor:pointer;">&#128424; Imprimir / PDF</button>
        <button onclick="document.getElementById('acta-preview').style.display='none'" style="padding:8px 20px;border-radius:6px;border:none;background:var(--bg-dark3);color:var(--text-sub);font-weight:700;cursor:pointer;">&#10005; Cerrar</button>
      </div>
    </div>
    <div class="acta-doc-screen" id="acta-contenido"></div>
  </div>

</div>

<div class="modal-overlay" id="modal-manual">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title">&#9998; Agregar Equipo Manual</span>
      <button class="modal-close" onclick="cerrarModal()">&#10005;</button>
    </div>
    <div class="form-grid" style="margin-top:0;">
      <div class="form-group"><label>Nombre del equipo</label><input type="text" id="m-nombre" placeholder="Nombre"></div>
      <div class="form-group"><label>Tipo</label><input type="text" id="m-tipo" placeholder="Computadora, Monitor..."></div>
      <div class="form-group"><label>Fabricante</label><input type="text" id="m-fabricante" placeholder="Fabricante"></div>
      <div class="form-group"><label>Modelo</label><input type="text" id="m-modelo" placeholder="Modelo"></div>
      <div class="form-group"><label>S/N</label><input type="text" id="m-serie" placeholder="N&uacute;mero de serie"></div>
      <div class="form-group"><label>Estado</label><select id="m-estado"><option value="">-- Seleccionar --</option></select></div>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
      <button onclick="cerrarModal()" style="padding:10px 20px;border-radius:6px;border:1px solid var(--border);background:transparent;color:var(--text-sub);cursor:pointer;">Cancelar</button>
      <button class="btn-add-equipo" onclick="agregarManual()">+ Agregar equipo</button>
    </div>
  </div>
</div>

<script>
let tipoActa='entrega', equipos=[], searchTimeout=null, userTimeout={entrega:null,recibe:null,autoriza:null,retira:null};

document.getElementById('acta-fecha').value=new Date().toISOString().split('T')[0];

async function cargarEstados(){
  try{
    const data=await fetch('actas_api.php?action=estados').then(r=>r.json());
    const sel=document.getElementById('m-estado');
    data.forEach(e=>{const o=document.createElement('option');o.value=e.id;o.textContent=e.name;sel.appendChild(o);});
  }catch(e){}
}
async function cargarUbicaciones(){
  try{
    const data=await fetch('actas_api.php?action=ubicaciones').then(r=>r.json());
    const selL=document.getElementById('acta-lugar');
    selL.innerHTML='';
    data.forEach(u=>{const o=document.createElement('option');o.value=u.completename;o.textContent=u.completename;if(u.name==='Duran'||u.completename==='Duran')o.selected=true;selL.appendChild(o);});
  }catch(e){}
}
cargarEstados();cargarUbicaciones();cargarMotivos();

async function cargarMotivos(){
  try{
    const data=await fetch('actas_api.php?action=estados').then(r=>r.json());
    const sel=document.getElementById('acta-motivo');
    data.forEach(e=>{const o=document.createElement('option');o.value=e.name;o.textContent=e.name;sel.appendChild(o);});
  }catch(e){}
}

async function buscarProveedor(q){
  const res=document.getElementById('results-destino');
  if(q.length<2){res.style.display='none';return;}
  try{
    const data=await fetch('actas_api.php?action=proveedores&q='+encodeURIComponent(q)).then(r=>r.json());
    if(!data.length){res.style.display='none';return;}
    res.innerHTML=data.map(p=>`<div class="user-item" onclick="seleccionarProveedor('${p.nombre.replace(/'/g,"\'")}')" ><div class="u-nombre">${p.nombre}</div></div>`).join('');
    res.style.display='block';
  }catch(e){}
}

function seleccionarProveedor(nombre){
  document.getElementById('search-destino').value=nombre;
  document.getElementById('results-destino').style.display='none';
}

function setTipo(t){
  tipoActa=t;
  document.getElementById('btn-entrega').classList.toggle('active',t==='entrega');
  document.getElementById('btn-salida').classList.toggle('active',t==='salida');
  document.getElementById('campos-entrega').style.display=t==='entrega'?'block':'none';
  document.getElementById('campos-salida').style.display=t==='salida'?'block':'none';
}

function renderEquipos(){
  const tbody=document.getElementById('equipos-tbody');
  const table=document.getElementById('equipos-table');
  const empty=document.getElementById('empty-msg');
  if(!equipos.length){table.style.display='none';empty.style.display='block';return;}
  empty.style.display='none';table.style.display='table';
  tbody.innerHTML=equipos.map((eq,i)=>{
    const esConsumible=eq.tabla==='glpi_consumableitems'||eq.tabla==='glpi_cartridgeitems';
    const snCell=(esConsumible||!eq.serie||eq.serie==='---')
      ? `<input type="text" value="${eq.serie==='---'?'':eq.serie||''}" placeholder="S/N opcional" onchange="equipos[${i}].serie=this.value||'---'" style="width:90px;padding:3px 6px;border-radius:4px;border:1px solid var(--border2);background:var(--bg-input);color:var(--text-main);font-size:11px;">`
      : (eq.serie||'---');
    return `<tr>
      <td><span class="eq-badge">${i+1}</span></td>
      <td><strong>${eq.nombre}</strong></td>
      <td><span class="eq-badge">${eq.tipo||'---'}</span></td>
      <td>${eq.fabricante||'---'}<br><small style="color:var(--text-muted)">${eq.modelo||'---'}</small></td>
      <td>${snCell}</td>
      <td><button class="btn-remove" onclick="quitarEquipo(${i})">&#10005;</button></td>
    </tr>`;
  }).join('');
}
function quitarEquipo(i){equipos.splice(i,1);renderEquipos();}

function buscarEquipo(q){
  clearTimeout(searchTimeout);
  const res=document.getElementById('search-results');
  if(q.length<2){res.style.display='none';return;}
  searchTimeout=setTimeout(async()=>{
    res.innerHTML='<div class="search-result-item no-results">Buscando...</div>';
    res.style.display='block';
    try{
      const data=await fetch('actas_api.php?action=buscar&q='+encodeURIComponent(q)).then(r=>r.json());
      if(!data.length){res.innerHTML='<div class="search-result-item no-results">No se encontraron equipos</div>';return;}
      res.innerHTML=data.map(i=>`<div class="search-result-item${i.stock !== undefined && i.stock === 0 ? ' no-results' : ''}" ${i.stock !== undefined && i.stock === 0 ? '' : "onclick='agregarDesdeGLPI("+JSON.stringify(i).replace(/'/g,"&#39;")+")'"}>
        <div class="r-name">${i.nombre}<span class="r-tipo">[${i.tipo}]</span>${i.stock !== undefined ? (i.stock > 0 ? '<span style="color:#28a745;font-size:10px;font-weight:700;margin-left:6px;">Stock: '+i.stock+'</span>' : '<span style="color:#dc3545;font-size:10px;font-weight:700;margin-left:6px;">Sin stock</span>') : ''}</div>
        <div class="r-sub">S/N: ${i.serie} | ${i.fabricante} ${i.modelo}${i.usuario && i.usuario!='---' ? ' | '+i.usuario : ''}</div>
      </div>`).join('');
    }catch(e){res.innerHTML='<div class="search-result-item no-results">Error al buscar</div>';}
  },300);
}

function agregarDesdeGLPI(item){
  equipos.push({nombre:item.nombre,tipo:item.tipo,fabricante:item.fabricante,modelo:item.modelo,serie:item.serie,estado:item.estado,usuario:item.usuario});
  document.getElementById('equipo-search').value='';
  document.getElementById('search-results').style.display='none';
  renderEquipos();
}

function abrirModalManual(){
  ['m-nombre','m-tipo','m-fabricante','m-modelo','m-serie'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('m-estado').value='';
  document.getElementById('modal-manual').classList.add('active');
}
function cerrarModal(){document.getElementById('modal-manual').classList.remove('active');}
function agregarManual(){
  const nombre=document.getElementById('m-nombre').value.trim();
  if(!nombre){alert('El nombre del equipo es obligatorio.');return;}
  const selE=document.getElementById('m-estado');
  equipos.push({nombre,tipo:document.getElementById('m-tipo').value.trim(),fabricante:document.getElementById('m-fabricante').value.trim(),modelo:document.getElementById('m-modelo').value.trim(),serie:document.getElementById('m-serie').value.trim(),estado:selE.options[selE.selectedIndex]?.text||''});
  cerrarModal();renderEquipos();
}

function buscarUsuario(input,campo){
  clearTimeout(userTimeout[campo]);
  const q=input.value.trim();
  const res=document.getElementById('results-'+campo);
  if(q.length<2){res.style.display='none';return;}
  userTimeout[campo]=setTimeout(async()=>{
    try{
      const data=await fetch('actas_api.php?action=usuarios&q='+encodeURIComponent(q)).then(r=>r.json());
      if(!data.length){res.style.display='none';return;}
      res.innerHTML=data.map(u=>`<div class="user-item" onclick="seleccionarUsuario('${campo}','${u.nombre.replace(/'/g,"\\'").replace(/"/g,"&quot;")}')">${u.nombre}<div class="u-sub">${u.name}</div></div>`).join('');
      res.style.display='block';
    }catch(e){}
  },300);
}
function seleccionarUsuario(campo,nombre){
  document.getElementById('search-'+campo).value=nombre;
  document.getElementById('results-'+campo).style.display='none';
}

document.addEventListener('click',e=>{
  if(!e.target.closest('.search-wrap')) document.getElementById('search-results').style.display='none';
  if(!e.target.closest('.user-wrap')) document.querySelectorAll('.user-results').forEach(r=>r.style.display='none');
  if(!e.target.closest('.modal-box')&&!e.target.closest('.btn-manual')) document.getElementById('modal-manual').classList.remove('active');
});

function generarActa(){
  if(!equipos.length){alert('Agrega al menos un equipo al acta.');return;}
  const fecha=document.getElementById('acta-fecha').value;
  if(!fecha){alert('Completa la fecha del acta.');return;}
  const fechaF=new Date(fecha+'T12:00:00').toLocaleDateString('es-EC',{day:'2-digit',month:'long',year:'numeric'});
  const tipoLabel=tipoActa==='entrega'?'ACTA DE ENTREGA DE EQUIPO':'ACTA DE SALIDA DE EQUIPO';
  const num='ACT-'+Date.now().toString().slice(-6);
  const selL=document.getElementById('acta-lugar');
  const lugarNombre=selL.options[selL.selectedIndex]?.text||'Guayaquil';
  const logo=window.NAGSA_LOGO_ABS||window.NAGSA_LOGO||'../../nagsa_logo.png';
  const nombre=window.NAGSA_NOMBRE||'NAGSA';
  const obsText=document.getElementById('acta-obs').value||'Ninguna';
  const totalEquipos=equipos.length;
  const equiposRows=equipos.map((eq,i)=>`<tr><td style="text-align:center;font-weight:700;">${i+1}</td><td><strong>${eq.nombre}</strong></td><td>${eq.tipo||'---'}</td><td>${eq.fabricante||'---'}</td><td>${eq.modelo||'---'}</td><td>${eq.serie||'---'}</td></tr>`).join('');

  let datosHTML='', declaracionHTML='', firmasHTML='';

  if(tipoActa==='entrega'){
    const entregaPor=document.getElementById('search-entrega').value.trim();
    const recibe=document.getElementById('search-recibe').value.trim();
    if(!entregaPor||!recibe){alert('Completa: entregado por y recibido por.');return;}
    const entregaCargo=document.getElementById('acta-entrega-cargo').value||'---';
    const recibeCargo=document.getElementById('acta-recibe-cargo').value||'---';
    datosHTML=`<div class="acta-section"><div class="acta-section-title">Datos de Entrega</div>
      <div class="acta-grid">
        <div class="acta-field"><span class="af-label">Entregado por</span><span class="af-value">${entregaPor}</span></div>
        <div class="acta-field"><span class="af-label">Cargo / Departamento</span><span class="af-value">${entregaCargo}</span></div>
        <div class="acta-field"><span class="af-label">Recibido por</span><span class="af-value">${recibe}</span></div>
        <div class="acta-field"><span class="af-label">Cargo / Departamento</span><span class="af-value">${recibeCargo}</span></div>
      </div></div>`;
    declaracionHTML=`<div style="border:1px solid #ccc;padding:8px 12px;margin-bottom:10px;font-size:10px;color:#333;line-height:1.6;background:#fafafa;">
      En la ciudad de <b>${lugarNombre}</b>, el d&iacute;a <b>${fechaF}</b>, yo <b>${recibe}</b>, con cargo <b>${recibeCargo}</b>, declaro haber recibido a entera satisfacci&oacute;n de <b>${entregaPor}</b>, los bienes inform&aacute;ticos detallados a continuaci&oacute;n, comprometi&eacute;ndome a su correcto uso, custodia y conservaci&oacute;n, y a responder por cualquier da&ntilde;o, p&eacute;rdida o extrav&iacute;o de los mismos.
    </div>`;
    firmasHTML=`<div class="acta-firmas">
      <div class="acta-firma"><div class="firma-linea"></div><div class="firma-nombre">${entregaPor}</div><div class="firma-cargo">${entregaCargo}</div><div class="firma-cargo">Entrega</div></div>
      <div class="acta-firma"><div class="firma-linea"></div><div class="firma-nombre">${recibe}</div><div class="firma-cargo">${recibeCargo}</div><div class="firma-cargo">Recibe</div></div>
    </div>`;
  } else {
    const autoriza=document.getElementById('search-autoriza').value.trim();
    const motivo=document.getElementById('acta-motivo').value||'---';
    const destino=document.getElementById('search-destino').value.trim()||'---';
    const retira=document.getElementById('search-retira').value.trim()||'---';
    if(!autoriza){alert('Completa: autorizado por.');return;}
    const autorizaCargo=document.getElementById('acta-autoriza-cargo').value||'---';
    const retiraCargo=document.getElementById('acta-retira-cargo').value||'---';
    datosHTML=`<div class="acta-section"><div class="acta-section-title">Datos de Salida</div>
      <div class="acta-grid">
        <div class="acta-field"><span class="af-label">Autorizado por</span><span class="af-value">${autoriza}</span></div>
        <div class="acta-field"><span class="af-label">Cargo / Departamento</span><span class="af-value">${autorizaCargo}</span></div>
        <div class="acta-field"><span class="af-label">Motivo de salida</span><span class="af-value">${motivo}</span></div>
        <div class="acta-field"><span class="af-label">Destino / Empresa</span><span class="af-value">${destino}</span></div>
        <div class="acta-field"><span class="af-label">Persona que retira</span><span class="af-value">${retira}</span></div>
        <div class="acta-field"><span class="af-label">Cargo / Empresa</span><span class="af-value">${retiraCargo}</span></div>
      </div></div>`;
    declaracionHTML=`<div style="border:1px solid #ccc;padding:8px 12px;margin-bottom:10px;font-size:10px;color:#333;line-height:1.6;background:#fafafa;">
      En la ciudad de <b>${lugarNombre}</b>, el d&iacute;a <b>${fechaF}</b>, el suscrito <b>${autoriza}</b>, en calidad de <b>${autorizaCargo}</b>, autoriza la salida de los bienes inform&aacute;ticos detallados a continuaci&oacute;n, por motivo de <b>${motivo}</b>, con destino a <b>${destino}</b>. El retiro de los equipos estar&aacute; a cargo de <b>${retira}</b>, quien se responsabiliza del traslado y entrega a su destino.
    </div>`;
    firmasHTML=`<div class="acta-firmas">
      <div class="acta-firma"><div class="firma-linea"></div><div class="firma-nombre">${autoriza}</div><div class="firma-cargo">${autorizaCargo}</div><div class="firma-cargo">Autoriza</div></div>
      <div class="acta-firma"><div class="firma-linea"></div><div class="firma-nombre">${retira}</div><div class="firma-cargo">${retiraCargo}</div><div class="firma-cargo">Retira</div></div>
    </div>`;
  }

  document.getElementById('acta-contenido').innerHTML=`
  <div class="acta-header"><div class="acta-logo-row">
    <img src="${logo}" alt="${nombre}" onerror="this.style.display='none'" style="height:50px;object-fit:contain;">
    <div style="text-align:right;">
      <div class="acta-titulo">${tipoLabel}</div>
      <div style="font-size:10px;color:#555;margin-top:2px;"><b>N&deg; Acta:</b> ${num}</div>
    </div>
  </div></div>
  <div class="acta-meta"><span><b>Fecha:</b> ${fechaF}</span><span><b>Lugar:</b> ${lugarNombre}</span></div>
  ${datosHTML}
  ${declaracionHTML}
  <div class="acta-section"><div class="acta-section-title">Equipos (${totalEquipos})</div>
    <table class="acta-equipos-table"><thead><tr><th>#</th><th>Nombre</th><th>Tipo</th><th>Fabricante</th><th>Modelo</th><th>S/N</th></tr></thead>
    <tbody>${equiposRows}</tbody></table></div>
  <div class="acta-section"><div class="acta-section-title">Observaciones</div>
    <div class="acta-obs">${obsText}</div></div>
  ${firmasHTML}
  <div class="acta-footer">${nombre} &middot; Gesti&oacute;n de Activos TI &middot; ${window.NAGSA_URL||'glpi.nagsa.com.ec'} &middot; Generado el ${new Date().toLocaleString('es-EC')}</div>`;
  document.getElementById('acta-preview').style.display='block';
  document.getElementById('acta-preview').scrollIntoView({behavior:'smooth'});

  // Guardar acta en SQL Express
  const datosGuardar={
    numero: num,
    tipo: tipoActa,
    fecha: fecha,
    lugar: lugarNombre,
    equipos: equipos,
    observaciones: obsText
  };
  if(tipoActa==='entrega'){
    datosGuardar.entregado_por=document.getElementById('search-entrega').value.trim();
    datosGuardar.entregado_cargo=document.getElementById('acta-entrega-cargo').value||'---';
    datosGuardar.recibido_por=document.getElementById('search-recibe').value.trim();
    datosGuardar.recibido_cargo=document.getElementById('acta-recibe-cargo').value||'---';
  } else {
    datosGuardar.autorizado_por=document.getElementById('search-autoriza').value.trim();
    datosGuardar.autorizado_cargo=document.getElementById('acta-autoriza-cargo').value||'---';
    datosGuardar.motivo=document.getElementById('acta-motivo').value||'---';
    datosGuardar.destino=document.getElementById('search-destino').value.trim()||'---';
    datosGuardar.retira_persona=document.getElementById('search-retira').value.trim()||'---';
    datosGuardar.retira_cargo=document.getElementById('acta-retira-cargo').value||'---';
  }
  guardarActa(datosGuardar);
}

async function guardarActa(datosActa){
  try{
    const resp=await fetch('../reportes/reportes_api.php?action=guardar',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(datosActa)
    });
    const data=await resp.json();
    if(data.ok){
      console.log('Acta guardada:',data.numero);
      return data;
    } else {
      console.warn('Error al guardar acta:',data.error);
      return null;
    }
  }catch(e){
    console.warn('No se pudo guardar el acta:',e);
    return null;
  }
}

function imprimirActa(){
  const contenido=document.getElementById('acta-contenido').innerHTML;
  if(!contenido.trim()){alert('Primero genera el acta.');return;}
  const win=window.open('','_blank','width=800,height=600');
  win.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Acta</title>
  <style>@page{margin:12mm 15mm;size:A4 portrait;}*{box-sizing:border-box;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}body{font-family:Arial,sans-serif;color:#000;background:#fff;margin:0;padding:10mm;}.acta-header{border-bottom:2px solid #000;padding-bottom:10px;margin-bottom:14px;}.acta-logo-row{display:flex;align-items:center;justify-content:space-between;}.acta-logo-row img{height:50px;object-fit:contain;}.acta-titulo{font-size:13px;font-weight:700;text-align:right;}.acta-meta{display:flex;justify-content:space-between;margin-bottom:12px;font-size:11px;background:#f5f5f5;padding:5px 8px;border-radius:3px;}.acta-section{margin-bottom:10px;}.acta-section-title{font-size:10px;font-weight:900;text-transform:uppercase;background:#111;color:#fff;padding:3px 8px;margin-bottom:6px;}.acta-equipos-table{width:100%;border-collapse:collapse;font-size:10px;}.acta-equipos-table th{background:#333;color:#fff;padding:4px 6px;text-align:left;font-size:9px;font-weight:700;text-transform:uppercase;}.acta-equipos-table td{padding:4px 6px;border-bottom:1px solid #ddd;}.acta-grid{display:grid;grid-template-columns:1fr 1fr;gap:5px;}.acta-field{display:flex;flex-direction:column;padding:2px 0;}.af-label{font-size:8px;font-weight:700;text-transform:uppercase;color:#666;}.af-value{font-size:11px;font-weight:700;color:#000;border-bottom:1px solid #ddd;padding-bottom:1px;min-height:16px;}.acta-obs{border:1px solid #ccc;padding:6px;min-height:40px;font-size:11px;margin-top:3px;}.acta-firmas{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:24px;}.acta-firma{text-align:center;}.firma-linea{border-top:1px solid #000;margin-bottom:4px;margin-top:36px;}.firma-nombre{font-size:11px;font-weight:700;}.firma-cargo{font-size:10px;color:#555;}.acta-footer{margin-top:14px;text-align:center;font-size:9px;color:#888;border-top:1px solid #ccc;padding-top:6px;}</style>
  </head><body>${contenido}</body></html>`);
  win.document.close();
  win.onload=()=>{win.focus();win.print();win.onafterprint=()=>win.close();};
}
</script>
</body>
</html>