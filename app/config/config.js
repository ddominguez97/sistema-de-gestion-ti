const fs = require('fs');
const path = require('path');
const { query } = require('./database');

const CONFIG_FILE = path.join(__dirname, '..', '..', 'sistemas_settings.json');
const ROOT_PATH = path.join(__dirname, '..', '..');
const DATA_DIR = path.join(ROOT_PATH, 'data');

// Cache en memoria - se llena de SQL al arrancar
let configCache = null;

// Cargar config desde SQL Server y armar el objeto compatible
async function loadConfigFromDB() {
  const cfg = {};

  // Configuracion general (key-value)
  const { recordset: rows } = await query('SELECT clave, valor FROM configuracion');
  for (const r of rows) cfg[r.clave] = r.valor;

  // local_db se mantiene del JSON (para conectarse a SQL necesitamos leerlo primero)
  try {
    const raw = JSON.parse(fs.readFileSync(CONFIG_FILE, 'utf8'));
    cfg.local_db = raw.local_db;
  } catch {}

  // Categorias
  const { recordset: cats } = await query('SELECT categoria, visible FROM configuracion_categorias');
  cfg.show = {};
  for (const c of cats) cfg.show[c.categoria] = !!c.visible;

  // Modulos
  const { recordset: mods } = await query('SELECT modulo, estado FROM configuracion_modulos');
  cfg.modulos = {};
  for (const m of mods) cfg.modulos[m.modulo] = m.estado;

  // AD
  const { recordset: adRows } = await query('SELECT * FROM configuracion_ad WHERE id=1');
  if (adRows.length) {
    const ad = adRows[0];
    cfg.active_directory = {
      modo: ad.modo, habilitado: !!ad.habilitado, nombre: ad.nombre,
      servidor: ad.servidor, puerto: ad.puerto, dominio: ad.dominio,
      base_dn: ad.base_dn, sufijo_usuario: ad.sufijo_usuario,
    };
  }

  // Permisos config (compatible con estructura actual)
  cfg.permisos_config = await loadPermisosFromDB();

  configCache = cfg;
  return cfg;
}

async function loadPermisosFromDB() {
  const pc = { ti_usuarios: {}, grupos: {}, usuarios_nivel: {} };

  // TI nombre area
  const { recordset: tiArea } = await query("SELECT valor FROM configuracion WHERE clave='ti_nombre_area'");
  pc.ti_nombre_area = tiArea.length ? tiArea[0].valor : '';

  // TI usuarios
  const { recordset: tiRows } = await query('SELECT username, nombre, admin_panel, puede_delegar FROM permisos_ti');
  for (const r of tiRows) {
    pc.ti_usuarios[r.username] = { nombre: r.nombre, admin_panel: !!r.admin_panel, puede_delegar: !!r.puede_delegar };
  }

  // Grupos
  const { recordset: grupoRows } = await query('SELECT id, nombre, perm_actas, perm_reportes, perm_crear_entrega FROM permisos_grupos');
  for (const g of grupoRows) {
    const gid = String(g.id);
    // Jefes
    const { recordset: jefes } = await query('SELECT username, nombre FROM permisos_grupo_jefes WHERE grupo_id=@gid', { gid: g.id });
    // Miembros
    const { recordset: miembros } = await query('SELECT username, nombre FROM permisos_grupo_miembros WHERE grupo_id=@gid', { gid: g.id });

    pc.grupos[gid] = {
      nombre: g.nombre,
      jefes: jefes.map(j => ({ username: j.username, nombre: j.nombre })),
      miembros: miembros.map(m => ({ username: m.username, nombre: m.nombre })),
      permisos: { actas: !!g.perm_actas, reportes: !!g.perm_reportes, crear_entrega: !!g.perm_crear_entrega },
    };
  }

  // Motivos
  const { recordset: motivosRows } = await query('SELECT nombre FROM motivos_salida WHERE activo=1 ORDER BY orden');
  pc.motivos_salida = motivosRows.map(m => m.nombre);

  return pc;
}

// loadConfig sincrono - devuelve cache o fallback a JSON
function loadConfig() {
  if (configCache) return configCache;
  // Fallback a JSON si el cache no esta listo (primera carga)
  if (fs.existsSync(CONFIG_FILE)) {
    return JSON.parse(fs.readFileSync(CONFIG_FILE, 'utf8'));
  }
  throw new Error('Config no disponible. Espera a que el sistema se conecte a SQL Server.');
}

// saveConfig - escribe a SQL y actualiza cache
async function saveConfigToDB(clave, valor) {
  await query('UPDATE configuracion SET valor=@valor, updated_at=GETDATE() WHERE clave=@clave', { clave, valor: String(valor) });
  if (configCache) configCache[clave] = valor;
}

// saveConfig compatible - recibe objeto completo y sincroniza a SQL
function saveConfig(cfg) {
  // Actualizar cache inmediatamente
  configCache = cfg;
  // Escribir a SQL en background
  syncConfigToDB(cfg).catch(e => console.error('Error sync config:', e.message));
  // Backup a JSON
  try { fs.writeFileSync(CONFIG_FILE, JSON.stringify(cfg, null, 4)); } catch {}
}

async function syncConfigToDB(cfg) {
  const simpleKeys = [
    'admin_pass', 'session_secret', 'db_host', 'db_port', 'db_name', 'db_user', 'db_pass',
    'base_url', 'zebra_ip', 'zebra_nombre', 'zebra_port',
    'empresa_nombre', 'empresa_color', 'empresa_logo', 'empresa_tema',
  ];
  for (const k of simpleKeys) {
    if (cfg[k] !== undefined) {
      await query('UPDATE configuracion SET valor=@valor, updated_at=GETDATE() WHERE clave=@clave', { clave: k, valor: String(cfg[k] || '') });
    }
  }
  // Entity ID
  if (cfg.entity_id !== undefined) {
    await query('UPDATE configuracion SET valor=@valor, updated_at=GETDATE() WHERE clave=@clave', { clave: 'entity_id', valor: String(cfg.entity_id) });
  }
  // Categorias
  if (cfg.show) {
    for (const [cat, vis] of Object.entries(cfg.show)) {
      await query('UPDATE configuracion_categorias SET visible=@vis WHERE categoria=@cat', { cat, vis: vis ? 1 : 0 });
    }
  }
  // Modulos
  if (cfg.modulos) {
    for (const [mod, estado] of Object.entries(cfg.modulos)) {
      await query('UPDATE configuracion_modulos SET estado=@estado WHERE modulo=@mod', { mod, estado });
    }
  }
  // AD
  if (cfg.active_directory) {
    const ad = cfg.active_directory;
    await query(`UPDATE configuracion_ad SET modo=@modo, habilitado=@hab, nombre=@nombre, servidor=@servidor,
      puerto=@puerto, dominio=@dominio, base_dn=@base_dn, sufijo_usuario=@sufijo WHERE id=1`, {
      modo: ad.modo || 'automatica', hab: ad.habilitado ? 1 : 0, nombre: ad.nombre || '',
      servidor: ad.servidor || '', puerto: ad.puerto || 389, dominio: ad.dominio || '',
      base_dn: ad.base_dn || '', sufijo: ad.sufijo_usuario || '',
    });
  }
  // ti_nombre_area
  if (cfg.permisos_config && cfg.permisos_config.ti_nombre_area !== undefined) {
    await query("UPDATE configuracion SET valor=@valor, updated_at=GETDATE() WHERE clave='ti_nombre_area'",
      { valor: cfg.permisos_config.ti_nombre_area || '' });
  }
}

// Inicializar cache desde SQL al arrancar
async function initConfig() {
  try {
    await loadConfigFromDB();
    console.log('Config cargada desde SQL Server.');
  } catch (e) {
    console.warn('No se pudo cargar config de SQL, usando JSON:', e.message);
  }
}

function getBranding(cfg) {
  const logo = cfg.empresa_logo || 'logo_empresa.png';
  const logoPath = path.join(ROOT_PATH, logo);
  let ts = Date.now();
  try { ts = fs.statSync(logoPath).mtimeMs; } catch {}
  return {
    nombre: cfg.empresa_nombre || 'NAGSA',
    color: cfg.empresa_color || '#E05816',
    logo: '/public/' + logo + '?v=' + Math.floor(ts),
    tema: cfg.empresa_tema || 'oscuro',
  };
}

function brandingVars(branding) {
  const tema = branding.tema;
  if (tema === 'claro') {
    return {
      bg_body: '#f0f4f8', bg_card: '#ffffff', bg_sidebar: '#ffffff',
      bg_topbar: '#ffffff', bg_input: '#f9f9f9', bg_hover: '#f0f0f0',
      bg_dark2: '#f0f0f0', bg_dark3: '#e8e8e8',
      text_main: '#1a1a1a', text_sub: '#555555', text_muted: '#888888',
      border: '#dddddd', border2: '#cccccc',
    };
  }
  return {
    bg_body: '#1a1a1a', bg_card: '#1e1e1e', bg_sidebar: '#1e1e1e',
    bg_topbar: '#1e1e1e', bg_input: '#252525', bg_hover: '#2a2a2a',
    bg_dark2: '#2a2a2a', bg_dark3: '#333333',
    text_main: '#ffffff', text_sub: '#aaaaaa', text_muted: '#666666',
    border: '#333333', border2: '#444444',
  };
}

function getEstadoModulo(cfg, modulo) {
  return (cfg.modulos && cfg.modulos[modulo]) || 'activo';
}

module.exports = {
  CONFIG_FILE, ROOT_PATH, DATA_DIR,
  loadConfig, saveConfig, saveConfigToDB, initConfig, loadConfigFromDB, loadPermisosFromDB,
  getBranding, brandingVars, getEstadoModulo,
};
