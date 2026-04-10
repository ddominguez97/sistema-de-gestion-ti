<?php
session_start();
$session_duration = 4 * 60 * 60;
if(isset($_SESSION['last_activity']) && (time()-$_SESSION['last_activity'])>$session_duration){
    session_unset(); session_destroy();
}
if(!isset($_SESSION['nagsa_user'])){ header('Location: ../../index.php'); exit; }
$_SESSION['last_activity'] = time();
require_once __DIR__ . '/../../config/config.php';
checkModulo('etiquetas');
$branding = getBranding();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Etiquetas — <?= htmlspecialchars($branding["nombre"]) ?></title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{display:flex;height:100vh;background:var(--bg-body);color:var(--text-main);font-family:Arial,sans-serif;overflow:hidden;}

/* Sidebar */
#sidebar{width:245px;min-width:245px;background:var(--bg-card);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow-y:auto;padding-bottom:20px;}
.sb-logo{padding:16px 14px 12px;border-bottom:2px solid var(--color-principal);text-align:center;}
.sb-logo .name{font-size:22px;font-weight:900;color:var(--color-principal);letter-spacing:2px;}
.sb-logo .sub{font-size:9px;color:var(--text-sub);letter-spacing:1px;text-transform:uppercase;}
.sb-section{padding:8px 12px 4px;font-size:9px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;color:var(--color-principal);margin-top:6px;}
.sb-item{display:flex;align-items:center;gap:8px;padding:5px 12px;cursor:pointer;font-size:12px;color:var(--text-main);transition:background 0.15s;}
.sb-item:hover{background:var(--bg-hover);}
.sb-item input{accent-color:var(--color-principal);width:14px;height:14px;flex-shrink:0;}
.sb-item .icon{font-size:14px;}
.sb-item .label{flex:1;}
.sb-item .badge{background:var(--bg-dark3);color:var(--text-sub);font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px;min-width:24px;text-align:center;}
.campo-item{display:flex;align-items:center;gap:8px;padding:4px 12px;cursor:pointer;font-size:11px;color:var(--text-main);}
.campo-item input{accent-color:var(--color-principal);}
.campo-item .lbl{flex:1;}
.campo-item .dot{width:6px;height:6px;border-radius:50%;background:var(--color-principal);}
.size-btn{margin:4px 10px;padding:8px;border-radius:6px;border:1.5px solid var(--border2);background:transparent;color:var(--text-sub);font-size:11px;cursor:pointer;text-align:left;transition:all 0.15s;width:calc(100% - 20px);}
.size-btn.active{border-color:var(--color-principal);background:rgba(0,0,0,0.12);color:var(--color-principal);font-weight:700;}
.counter-badge{margin:10px 10px 0;padding:8px 12px;background:var(--bg-input);border-radius:6px;font-size:11px;color:var(--text-muted);text-align:center;}
.print-btn{margin:8px 10px;padding:10px;border-radius:6px;border:none;background:var(--color-principal);color:#ffffff;font-size:12px;font-weight:700;cursor:pointer;width:calc(100% - 20px);}
.print-btn:hover{background:var(--color-hover);}

/* Main */
#main{flex:1;overflow-y:auto;display:flex;flex-direction:column;}
.page-header{display:flex;align-items:center;gap:12px;padding:14px 20px 10px;border-bottom:1px solid var(--border);flex-shrink:0;}
.page-header h1{font-size:18px;font-weight:800;color:var(--text-main);}
.total-badge{background:var(--color-principal);color:var(--text-main);font-size:12px;font-weight:700;padding:4px 14px;border-radius:20px;}
#labels-grid{display:flex;flex-wrap:wrap;gap:8px;padding:16px;align-content:flex-start;}

/* Etiqueta 58mm */
.label-58{width:58mm;height:39mm;border:1.5px solid #111;background:#fff;display:flex;flex-direction:column;font-family:'Arial Narrow',Arial,Helvetica,sans-serif;overflow:hidden;break-inside:avoid;cursor:pointer;position:relative;transition:box-shadow 0.15s;}
.label-58:hover{box-shadow:0 0 0 2px var(--color-principal) !important;}
.label-58.selected{box-shadow:0 0 0 3px var(--color-principal),0 0 12px rgba(0,0,0,0.3) !important;}
.label-58.selected::after{content:"\2713";position:absolute;top:3px;right:3px;background:var(--color-principal);color:#fff;font-size:7px;font-weight:bold;width:13px;height:13px;border-radius:50%;display:flex;align-items:center;justify-content:center;}
.label-58 .lbl-header{display:flex;justify-content:center;align-items:center;padding:2px 4px;border-bottom:1.5px solid #111;background:#111;color:#fff;flex-shrink:0;}
.label-58 .lbl-header .org{font-size:8px;font-weight:900;letter-spacing:0.8px;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:clip;}
.label-58 .lbl-body{display:flex;flex:1;overflow:hidden;}
.label-58 .lbl-info{flex:1;padding:2px 2px 2px 4px;display:flex;flex-direction:column;gap:1px;overflow:hidden;}
.label-58 .lbl-field{display:flex;flex-direction:column;}
.label-58 .lbl-field .fl{font-size:6px;font-weight:400;text-transform:uppercase;color:#000;letter-spacing:0.3px;}
.label-58 .lbl-field .fv{font-size:8px;font-weight:700;color:#000;white-space:nowrap;overflow:hidden;text-overflow:clip;max-width:32mm;display:block;}
.label-58 .lbl-qr{width:25mm;min-width:25mm;border-left:1px solid #ccc;display:flex;align-items:center;justify-content:center;padding:1px;background:#fff;}
.label-58 .lbl-qr img{width:23mm;height:23mm;object-fit:contain;}
.label-58 .lbl-footer{border-top:1px solid #ddd;padding:1.5px 4px;font-size:5px;font-weight:700;color:#000;text-align:center;background:#f0f0f0;white-space:nowrap;overflow:hidden;flex-shrink:0;}

/* Etiqueta 100mm */
.label-100{width:100mm;height:60mm;border:1.5px solid #111;background:#fff;display:flex;flex-direction:column;font-family:'Arial Narrow',Arial,Helvetica,sans-serif;overflow:hidden;break-inside:avoid;cursor:pointer;position:relative;}
.label-100:hover{box-shadow:0 0 0 2px var(--color-principal) !important;}
.label-100.selected{box-shadow:0 0 0 3px var(--color-principal),0 0 12px rgba(0,0,0,0.3) !important;}
.label-100.selected::after{content:"\2713";position:absolute;top:3px;right:3px;background:var(--color-principal);color:#fff;font-size:9px;font-weight:bold;width:15px;height:15px;border-radius:50%;display:flex;align-items:center;justify-content:center;}
.label-100 .lbl-header{display:flex;justify-content:center;align-items:center;padding:2px 4px;border-bottom:1.5px solid #111;background:#111;color:#fff;flex-shrink:0;}
.label-100 .lbl-header .org{font-size:9px;font-weight:900;letter-spacing:1px;text-transform:uppercase;}
.label-100 .lbl-body{display:flex;flex:1;overflow:hidden;}
.label-100 .lbl-info{flex:1;padding:3px 3px 3px 6px;display:flex;flex-direction:column;gap:2px;}
.label-100 .lbl-field .fl{font-size:6px;font-weight:400;text-transform:uppercase;color:#000;}
.label-100 .lbl-field .fv{font-size:8px;font-weight:700;color:#000;white-space:nowrap;overflow:hidden;text-overflow:clip;}
.label-100 .lbl-qr{width:28mm;min-width:28mm;border-left:1px solid #ccc;display:flex;align-items:center;justify-content:center;padding:3px;background:#fff;}
.label-100 .lbl-qr img{width:22mm;height:22mm;object-fit:contain;}
.label-100 .lbl-footer{border-top:1px solid #ddd;padding:2px 6px;font-size:6px;font-weight:700;color:#000;text-align:center;background:#f0f0f0;flex-shrink:0;}

/* Modal preview */
#preview-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.85);z-index:9999;justify-content:center;align-items:center;}
#preview-box{background:var(--bg-card);border-radius:10px;padding:20px;width:90vw;max-width:800px;}
#preview-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;color:var(--text-main);font-size:14px;font-weight:700;}
#preview-header button{background:transparent;border:none;color:var(--text-sub);font-size:22px;cursor:pointer;line-height:1;}
#preview-content{display:flex;justify-content:center;align-items:center;padding:30px;background:var(--bg-hover);border-radius:8px;min-height:280px;}
#preview-content .label-58{zoom:2;margin:0;}
#preview-content .label-100{zoom:1.5;margin:0;}
#preview-actions{display:flex;gap:10px;margin-top:16px;justify-content:center;}
#preview-actions button{padding:9px 20px;border-radius:6px;border:none;cursor:pointer;font-size:12px;font-weight:700;}

/* Buscador */
#sb-search-input{width:100%;padding:7px 30px 7px 10px;border-radius:6px;border:1.5px solid var(--border);background:var(--bg-hover);color:var(--text-main);font-size:12px;outline:none;box-sizing:border-box;font-family:Arial,sans-serif;}
#sb-search-input:focus{border-color:var(--color-principal);}

</style>
<?php brandingCSS('../../'); ?>
<style>
@media print{
  *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;}
  body{background:#fff !important;overflow:visible !important;}
  #sidebar,.page-header,#preview-overlay{display:none !important;}
  #main{display:block !important;width:100% !important;}
  #labels-grid{display:block !important;padding:0 !important;}
  .label-58,.label-100{display:inline-flex !important;vertical-align:top !important;margin:2mm !important;box-shadow:none !important;transform:none !important;background:#fff !important;break-inside:avoid !important;}
  .lbl-header{background:#111 !important;color:#fff !important;}
  .lbl-field .fl{color:#000 !important;}
  .lbl-field .fv{color:#000 !important;font-weight:700 !important;}
  .lbl-qr{background:#fff !important;}
  .lbl-footer{background:#f0f0f0 !important;color:#000 !important;}
  .label-58.selected::after,.label-100.selected::after{display:none !important;}
}
</style>
</head>
<body>

<div id="sidebar">
  <div class="sb-logo">
    <img src="<?= htmlspecialchars('../../'.$branding['logo']) ?>" alt="<?= htmlspecialchars($branding['nombre']) ?>" style="height:32px;object-fit:contain;margin-bottom:4px;">
    <div class="sub">Gestión de Activos TI</div>
  </div>

  <div id="sb-search">
    <div class="sb-section">Buscar etiqueta</div>
    <div style="padding:6px 10px 10px;">
      <div style="position:relative;">
        <input id="sb-search-input" type="text" placeholder="Buscar por nombre...">
        <span id="sb-search-clear" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);color:var(--text-muted);cursor:pointer;font-size:14px;display:none;">&#10005;</span>
      </div>
      <div id="sb-search-count" style="font-size:10px;color:var(--text-muted);margin-top:4px;min-height:14px;"></div>
    </div>
  </div>

  <div class="sb-section">Tipo de activo</div>
  <label class="sb-item"><input type="checkbox" id="chk_comp" checked><span class="icon">💻</span><span class="label">Computadoras</span><span class="badge" id="cnt_comp">0</span></label>
  <label class="sb-item"><input type="checkbox" id="chk_mon"><span class="icon">🖥️</span><span class="label">Monitores</span><span class="badge" id="cnt_mon">0</span></label>
  <label class="sb-item"><input type="checkbox" id="chk_imp"><span class="icon">🖨️</span><span class="label">Impresoras</span><span class="badge" id="cnt_imp">0</span></label>
  <label class="sb-item"><input type="checkbox" id="chk_per"><span class="icon">🕹️</span><span class="label">Periféricos</span><span class="badge" id="cnt_per">0</span></label>
  <label class="sb-item"><input type="checkbox" id="chk_red"><span class="icon">🌐</span><span class="label">Disp. de Red</span><span class="badge" id="cnt_red">0</span></label>
  <label class="sb-item"><input type="checkbox" id="chk_gab"><span class="icon">🗄</span><span class="label">Gabinetes</span><span class="badge" id="cnt_gab">0</span></label>
  <label class="sb-item"><input type="checkbox" id="chk_pas"><span class="icon">📡</span><span class="label">Disp. Pasivos</span><span class="badge" id="cnt_pas">0</span></label>
  <label class="sb-item"><input type="checkbox" id="chk_car"><span class="icon">🩸</span><span class="label">Cartuchos</span><span class="badge" id="cnt_car">0</span></label>
  <label class="sb-item"><input type="checkbox" id="chk_con"><span class="icon">📦</span><span class="label">Consumibles</span><span class="badge" id="cnt_con">0</span></label>
  <label class="sb-item"><input type="checkbox" id="chk_tel"><span class="icon">📱</span><span class="label">Teléfonos</span><span class="badge" id="cnt_tel">0</span></label>

  <div class="sb-section" style="margin-top:12px;">Campos a mostrar</div>
  <label class="campo-item"><input type="checkbox" id="f_nombre" checked><span class="lbl">Nombre</span><span class="dot"></span></label>
  <label class="campo-item"><input type="checkbox" id="f_fab" checked><span class="lbl">Fabricante</span><span class="dot"></span></label>
  <label class="campo-item"><input type="checkbox" id="f_mod" checked><span class="lbl">Modelo</span><span class="dot"></span></label>
  <label class="campo-item"><input type="checkbox" id="f_serie" checked><span class="lbl">S/N</span><span class="dot"></span></label>
  <label class="campo-item"><input type="checkbox" id="f_tipo"><span class="lbl">Tipo</span><span class="dot"></span></label>
  <label class="campo-item"><input type="checkbox" id="f_ip"><span class="lbl">IP</span><span class="dot"></span></label>
  <label class="campo-item"><input type="checkbox" id="f_user"><span class="lbl">Usuario</span><span class="dot"></span></label>
  <label class="campo-item"><input type="checkbox" id="f_qr" checked><span class="lbl">Código QR</span><span class="dot"></span></label>

  <div class="sb-section" style="margin-top:12px;">Tamaño de etiqueta</div>
  <button class="size-btn active" id="btn58" onclick="setSize(58)">🏷️ 58 × 39 mm</button>
  <button class="size-btn" id="btn100" onclick="setSize(100)">🏷️ 100 × 60 mm</button>

  <div class="sb-section" style="margin-top:12px;">Modo de impresión</div>
  <button class="size-btn active" id="btnLabel" onclick="setPrintMode('label')">&#127991; Impresora etiquetas</button>
  <button class="size-btn" id="btnA4" onclick="setPrintMode('a4')">&#128196; Hoja A4</button>

  <div id="label-counter" class="counter-badge">Cargando...</div>
  <button class="print-btn" onclick="doPrint()">🏷️ Imprimir etiquetas</button>
  <button class="print-btn" id="btn-zpl" onclick="doPrintZPL()" style="background:#555;margin-top:4px;">&#9889; Imprimir ZPL (Zebra)</button>
</div>

<div id="main">
  <div class="page-header">
    <a href="../../index.php" style="padding:6px 14px;border-radius:6px;background:var(--bg-hover);color:var(--text-sub);font-size:11px;font-weight:700;text-decoration:none;border:1px solid var(--border);flex-shrink:0;">← Menú</a>
    <h1>🏷️ Etiquetas de Activos TI — <?= htmlspecialchars($branding['nombre']) ?></h1>
    <span class="total-badge" id="top-badge">Cargando...</span>
    <div style="margin-left:auto;display:flex;align-items:center;gap:10px;">
      <span style="font-size:12px;color:var(--text-sub);">👤 <?=htmlspecialchars($_SESSION['nagsa_name']?:$_SESSION['nagsa_user'])?></span>
      <a href="../../index.php?logout=1" style="padding:6px 14px;border-radius:6px;background:var(--bg-dark3);color:var(--text-sub);font-size:11px;font-weight:700;text-decoration:none;">🚪 Salir</a>
    </div>
  </div>
  <div id="labels-grid"></div>
</div>

<div id="preview-overlay">
  <div id="preview-box">
    <div id="preview-header">
      <span>&#128065; Vista Previa de Etiqueta</span>
      <button onclick="closePreview()">&#10005;</button>
    </div>
    <div id="preview-content"></div>
    <div id="preview-actions">
      <button id="btn-selection" onclick="addToSelection()" style="background:var(--bg-dark3);color:var(--text-main);">&#9745; Agregar a selección</button>
      <button onclick="printPreview()" style="background:var(--color-principal);color:var(--text-main);">&#128424; Imprimir esta</button>
    </div>
  </div>
</div>

<script>
let ALL_DATA={}, currentSize=58, currentPrintMode='label', previewLabel=null;
const CATS={comp:'computadoras',mon:'monitores',imp:'impresoras',per:'perifericos',red:'redes',gab:'gabinetes',pas:'pasivos',car:'cartuchos',con:'consumibles',tel:'telefonos'};

function makeQR(url){return new Promise(resolve=>{const div=document.createElement('div');div.style.cssText='position:absolute;top:-9999px;left:-9999px;';document.body.appendChild(div);try{new QRCode(div,{text:url,width:128,height:128,colorDark:'#000000',colorLight:'#ffffff',correctLevel:QRCode.CorrectLevel.M});setTimeout(()=>{const img=div.querySelector('img');const canvas=div.querySelector('canvas');const src=img?.src||canvas?.toDataURL('image/png')||'';document.body.removeChild(div);resolve(src);},80);}catch(e){document.body.removeChild(div);resolve('');}});}

function mkLabel(item,size){
  const f={nombre:document.getElementById('f_nombre').checked,fab:document.getElementById('f_fab').checked,mod:document.getElementById('f_mod').checked,serie:document.getElementById('f_serie').checked,tipo:document.getElementById('f_tipo').checked,ip:document.getElementById('f_ip').checked,user:document.getElementById('f_user').checked,qr:document.getElementById('f_qr').checked};
  const esc=s=>String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const fields=[
    f.nombre?'<div class="lbl-field"><span class="fl">Nombre</span><span class="fv">'+esc(item.nombre)+'</span></div>':'',
    f.fab?'<div class="lbl-field"><span class="fl">Fabricante</span><span class="fv">'+esc(item.fabricante)+'</span></div>':'',
    f.mod?'<div class="lbl-field"><span class="fl">Modelo</span><span class="fv">'+esc(item.modelo||'---')+'</span></div>':'',
    f.serie?'<div class="lbl-field"><span class="fl">S/N</span><span class="fv">'+esc(item.serie||'---')+'</span></div>':'',
    f.tipo?'<div class="lbl-field"><span class="fl">Tipo</span><span class="fv">'+esc(item.tipo||'---')+'</span></div>':'',
    f.ip?'<div class="lbl-field"><span class="fl">IP</span><span class="fv">'+esc(item.ip||'---')+'</span></div>':'',
    f.user?'<div class="lbl-field"><span class="fl">Usuario</span><span class="fv">'+esc(item.usuario||'')+'</span></div>':'',
  ].filter(Boolean).join('');
  const qrHTML=f.qr?'<div class="lbl-qr"><img id="qr_'+item.id+'" src="" alt="QR"></div>':'';
  return '<div class="label-'+size+'" data-asset-id="'+item.id+'" data-categoria="'+esc(item.activo)+'" data-activo="'+esc(item.nombre)+'" data-qr-url="'+esc(item.qr_url)+'" onclick="toggleSelect(this)" ondblclick="openPreview(this)">'
    +'<div class="lbl-header"><span class="org">'+(window.NAGSA_NOMBRE||'NAGSA')+' &mdash; '+esc(item.nombre)+'</span></div>'
    +'<div class="lbl-body"><div class="lbl-info">'+fields+'</div>'+qrHTML+'</div>'
    +'<div class="lbl-footer">'+(window.NAGSA_NOMBRE||'NAGSA')+' &middot; Gestión de Activos TI &middot; '+(window.NAGSA_URL||'glpi.nagsa.com.ec')+'</div>'
    +'</div>';
}

async function generateQRs(labels){
  const arr=Array.from(labels);
  for(let i=0;i<arr.length;i+=15){
    await Promise.all(arr.slice(i,i+15).map(async label=>{
      const url=label.getAttribute('data-qr-url');
      const id=label.getAttribute('data-asset-id');
      const img=document.getElementById('qr_'+id);
      if(img&&url){const src=await makeQR(url);if(src)img.src=src;}
    }));
    await new Promise(r=>setTimeout(r,30));
  }
}

function updateCounters(n){document.getElementById('label-counter').textContent=n+' etiquetas generadas';document.getElementById('top-badge').textContent=n+' etiquetas';}

async function render(){
  const grid=document.getElementById('labels-grid');
  let items=[];
  for(const[k,cat]of Object.entries(CATS)){const chk=document.getElementById('chk_'+k);if(chk?.checked&&ALL_DATA[cat]?.length)items=items.concat(ALL_DATA[cat]);}
  grid.style.cssText='';
  grid.innerHTML=items.map(i=>mkLabel(i,currentSize)).join('');
  updateCounters(items.length);
  await generateQRs(grid.querySelectorAll('[data-qr-url]'));
}

function setSize(s){currentSize=s;document.getElementById('btn58').classList.toggle('active',s===58);document.getElementById('btn100').classList.toggle('active',s===100);render();}
function setPrintMode(m){currentPrintMode=m;document.getElementById('btnA4').classList.toggle('active',m==='a4');document.getElementById('btnLabel').classList.toggle('active',m==='label');}
function toggleSelect(el){el.classList.toggle('selected');}

function updateSelectionBtn(){
  const btn=document.getElementById('btn-selection');
  if(!btn||!previewLabel)return;
  btn.textContent=previewLabel.classList.contains('selected')?'☒ Quitar selección':'☑ Agregar a selección';
}
function addToSelection(){if(!previewLabel)return;previewLabel.classList.toggle('selected');updateSelectionBtn();}
function openPreview(el){
  event.stopPropagation();
  previewLabel=el;
  const c=el.cloneNode(true);c.style.transform='none';c.style.margin='0';c.onclick=null;c.ondblclick=null;
  const content=document.getElementById('preview-content');content.innerHTML='';content.appendChild(c);
  document.getElementById('preview-overlay').style.display='flex';
  updateSelectionBtn();
}
function closePreview(){document.getElementById('preview-overlay').style.display='none';}
function printPreview(){closePreview();if(previewLabel)doPrint(previewLabel);}

function initSearch(){
  const input=document.getElementById('sb-search-input');
  const clearBtn=document.getElementById('sb-search-clear');
  const countEl=document.getElementById('sb-search-count');
  const grid=document.getElementById('labels-grid');

  input.addEventListener('input',function(){
    const q=this.value.trim().toLowerCase();
    clearBtn.style.display=q?'block':'none';
    const all=document.querySelectorAll('.label-58,.label-100');
    all.forEach(l=>{l.style.display='';l.style.transform='';l.style.margin='';l.style.zoom='';});
    grid.style.cssText='display:flex;flex-wrap:wrap;gap:8px;padding:16px;align-content:flex-start;';

    if(!q){countEl.textContent='';updateCounters(all.length);return;}

    let visible=[];
    all.forEach(l=>{
      const name=(l.getAttribute('data-activo')||'').toLowerCase();
      const vals=Array.from(l.querySelectorAll('.fv')).map(v=>v.textContent.toLowerCase()).join(' ');
      const match=name.includes(q)||vals.includes(q);
      l.style.display=match?'':'none';
      if(match)visible.push(l);
    });

    const n=visible.length;
    if(n===1){
      grid.style.cssText='display:flex;justify-content:center;align-items:center;min-height:65vh;padding:16px;';
      visible[0].style.zoom='2.2';
      visible[0].style.margin='auto';
    } else if(n===2){
      grid.style.cssText='display:flex;flex-wrap:nowrap;justify-content:center;align-items:center;min-height:50vh;gap:40px;padding:40px 20px;';
      visible.forEach(l=>{l.style.zoom='1.6';l.style.margin='0';});
    } else if(n<=4){
      grid.style.cssText='display:flex;flex-wrap:wrap;justify-content:center;align-items:center;min-height:50vh;gap:30px;padding:30px 20px;';
      visible.forEach(l=>{l.style.zoom='1.2';l.style.margin='0';});
    } else if(n<=6){
      grid.style.cssText='display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:20px;padding:20px;';
      visible.forEach(l=>{l.style.zoom='1.0';l.style.margin='0';});
    }

    countEl.textContent=n+' resultado'+(n!==1?'s':'');
    updateCounters(n);
  });
  clearBtn.addEventListener('click',()=>{input.value='';input.dispatchEvent(new Event('input'));input.focus();});
}

function doPrintZPL(singleEl){
  const selected=document.querySelectorAll('.label-58.selected,.label-100.selected');
  const allLabels=document.querySelectorAll('.label-58,.label-100');
  closePreview();
  let toPrint=singleEl?[singleEl]:selected.length>0?Array.from(selected):Array.from(allLabels).filter(l=>window.getComputedStyle(l).display!=='none');
  if(!toPrint.length){alert('No hay etiquetas para imprimir');return;}

  const fields={
    nombre: document.getElementById('f_nombre').checked,
    fab:    document.getElementById('f_fab').checked,
    mod:    document.getElementById('f_mod').checked,
    serie:  document.getElementById('f_serie').checked,
    tipo:   document.getElementById('f_tipo').checked,
    ip:     document.getElementById('f_ip').checked,
    user:   document.getElementById('f_user').checked,
    qr:     document.getElementById('f_qr').checked,
  };
  const catMap={
    'Computadora':'computadoras','Monitor':'monitores','Impresora':'impresoras',
    'Periferico':'perifericos','Dispositivo de Red':'redes','Gabinete':'gabinetes',
    'Cartucho':'cartuchos','Consumible':'consumibles','Telefono':'telefonos'
  };
  const items=toPrint.map(el=>{
    const id=el.getAttribute('data-asset-id');
    const categoria=el.getAttribute('data-categoria');
    const catKey=catMap[categoria];
    if(catKey && ALL_DATA[catKey]){
      const found=ALL_DATA[catKey].find(i=>String(i.id)===String(id));
      if(found) return found;
    }
    for(const cat of Object.values(ALL_DATA)){
      if(!Array.isArray(cat)) continue;
      const found=cat.find(i=>String(i.id)===String(id));
      if(found) return found;
    }
    return null;
  }).filter(Boolean);

  if(!items.length){alert('No se encontraron datos de las etiquetas seleccionadas');return;}

  const btn=document.getElementById('btn-zpl');
  const orig=btn.textContent;
  btn.textContent='⏳ Enviando a impresora...';
  btn.disabled=true;

  fetch('etiquetas_print.php',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({items, size:currentSize, fields})
  })
  .then(r=>r.json())
  .then(data=>{
    if(data.ok) alert('✅ '+data.enviadas+' etiqueta(s) enviadas a la impresora Zebra.');
    else alert('❌ Error: '+(data.error||'Error desconocido'));
  })
  .catch(()=>alert('❌ No se pudo conectar con etiquetas_print.php'))
  .finally(()=>{btn.textContent=orig;btn.disabled=false;});
}

function doPrint(singleEl){
  const selected=document.querySelectorAll('.label-58.selected,.label-100.selected');
  const allLabels=document.querySelectorAll('.label-58,.label-100');
  closePreview();
  let toPrint=singleEl?[singleEl]:selected.length>0?Array.from(selected):Array.from(allLabels).filter(l=>window.getComputedStyle(l).display!=='none');
  if(!toPrint.length){alert('No hay etiquetas para imprimir');return;}



  const sheets=document.styleSheets;
  let labelCSS='';
  for(let s of sheets){try{for(let r of s.cssRules){if(r.selectorText&&(r.selectorText.includes('lbl')||r.selectorText.includes('label-')))labelCSS+=r.cssText+'\n';}}catch(e){}}

  let pageCSS='';
  if(currentPrintMode==='label'){
    const w=currentSize===58?'58mm':'100mm';
    const h=currentSize===58?'39mm':'60mm';
    pageCSS=`@page{margin:0;size:${w} ${h};}`;
  } else {
    pageCSS='@page{margin:5mm;size:A4 portrait;}';
  }

  const labelsHTML=toPrint.map(l=>{
    const c=l.cloneNode(true);c.classList.remove('selected');
    c.style.cssText=currentPrintMode==='label'?'display:flex;margin:0;box-shadow:none;transform:none;background:#fff;page-break-after:always;break-after:page;':'';
    c.onclick=null;c.ondblclick=null;return c.outerHTML;
  }).join('\n');

  const doc='<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
    +pageCSS
    +'*{box-sizing:border-box;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;}'
    +'html,body{background:#fff;margin:0;padding:0;font-family:"Arial Narrow",Arial,sans-serif;color:#000;}'
    +(currentPrintMode==='a4'?'body{padding:2mm;}':'')
    +labelCSS
    +'.label-58,.label-100{display:inline-flex!important;vertical-align:top!important;box-shadow:none!important;transform:none!important;background:#fff!important;'
    +(currentPrintMode==='a4'?'margin:1mm!important;':'margin:0!important;')
    +'break-inside:avoid!important;}'
    +'.lbl-header{background:#111!important;color:#fff!important;}'
    +'.lbl-body,.lbl-info{background:#fff!important;}'
    +'.lbl-field .fl{color:#000!important;font-weight:400!important;}'
    +'.lbl-field .fv{color:#000!important;font-weight:700!important;}'
    +'.lbl-qr{background:#fff!important;}'
    +'.lbl-footer{background:#f0f0f0!important;color:#000!important;font-weight:700!important;}'
    +'</style></head><body>'+labelsHTML+'</body></html>';

  let frame=document.getElementById('_pf');if(frame)frame.remove();
  frame=document.createElement('iframe');frame.id='_pf';
  frame.style.cssText='position:fixed;top:-9999px;left:-9999px;width:1px;height:1px;border:none;visibility:hidden;';
  document.body.appendChild(frame);
  frame.contentDocument.open();frame.contentDocument.write(doc);frame.contentDocument.close();

  const imgs=frame.contentDocument.querySelectorAll('img');
  let loaded=0,total=imgs.length;
  const go=()=>{
    frame.style.width=currentPrintMode==='label'?(currentSize===58?'58mm':'100mm'):'210mm';
    frame.style.height=currentPrintMode==='label'?(currentSize===58?'39mm':'60mm'):'297mm';
    frame.style.visibility='visible';
    setTimeout(()=>{frame.contentWindow.focus();frame.contentWindow.print();setTimeout(()=>frame.remove(),5000);},400);
  };
  if(!total){go();}
  else{imgs.forEach(img=>{if(img.complete){loaded++;if(loaded>=total)go();}else{img.onload=img.onerror=()=>{loaded++;if(loaded>=total)go();};}});setTimeout(()=>{if(loaded<total)go();},2500);}
}

document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key==='p'){e.preventDefault();doPrint();}},true);

async function init(){
  try{
    const res=await fetch('etiquetas_api.php');
    if(!res.ok)throw new Error('HTTP '+res.status);
    ALL_DATA=await res.json();
    if(ALL_DATA.error)throw new Error(ALL_DATA.error);
    const counts={comp:'computadoras',mon:'monitores',imp:'impresoras',per:'perifericos',red:'redes',gab:'gabinetes',pas:'pasivos',car:'cartuchos',con:'consumibles',tel:'telefonos'};
    for(const[k,cat]of Object.entries(counts))document.getElementById('cnt_'+k).textContent=(ALL_DATA[cat]||[]).length;
    document.querySelectorAll('#sidebar input[type="checkbox"][id^="chk_"]').forEach(c=>c.addEventListener('change',render));
    document.querySelectorAll('#sidebar input[type="checkbox"][id^="f_"]').forEach(c=>c.addEventListener('change',render));
    initSearch();
    await render();
  }catch(e){
    document.getElementById('labels-grid').innerHTML='<div style="color:var(--color-principal);padding:30px;font-size:14px;">&#9888; Error: '+e.message+'<br><br>Configura primero en <b>Sistema_admin.php</b></div>';
    updateCounters(0);
  }
}
init();
</script>
</body>
</html>