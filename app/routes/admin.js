const express = require('express');
const router = express.Router();
const multer = require('multer');
const path = require('path');
const fs = require('fs');
const { loadConfig, saveConfig, ROOT_PATH } = require('../config/config');
const { getGLPILdapConfig } = require('../middleware/auth');

const ADMIN_PASS = 'GLPIM853@UYT';

// Logo upload config
const storage = multer.diskStorage({
  destination: (req, file, cb) => cb(null, ROOT_PATH),
  filename: (req, file, cb) => {
    const ext = path.extname(file.originalname).toLowerCase();
    cb(null, 'logo_empresa' + ext);
  }
});
const upload = multer({
  storage,
  fileFilter: (req, file, cb) => {
    const allowed = ['.png','.jpg','.jpeg','.gif','.svg','.webp'];
    cb(null, allowed.includes(path.extname(file.originalname).toLowerCase()));
  }
});

// GET /admin
router.get('/', async (req, res) => {
  const cfg = loadConfig();
  const show = cfg.show || {};
  const ad = cfg.active_directory || {};
  const msg = req.session.admin_msg || null;
  const msgType = req.session.admin_msg_type || 'ok';
  delete req.session.admin_msg;
  delete req.session.admin_msg_type;
  // Detectar LDAP de GLPI para mostrar info en la tarjeta AD
  let glpiLdap = null;
  if (req.session.admin_ok) {
    try { glpiLdap = await getGLPILdapConfig(); } catch {}
  }
  res.render('admin', {
    loggedIn: !!req.session.admin_ok,
    msg, msgType, cfg, show, ad, glpiLdap,
  });
});

// POST /admin — login
router.post('/login', (req, res) => {
  if (req.body.password === ADMIN_PASS) {
    req.session.admin_ok = true;
  } else {
    req.session.admin_msg = 'Contrasena incorrecta.';
    req.session.admin_msg_type = 'err';
  }
  res.redirect('/admin');
});

// POST /admin/save-bd
router.post('/save-bd', (req, res) => {
  if (!req.session.admin_ok) return res.redirect('/admin');
  const cfg = loadConfig();
  cfg.db_host = (req.body.db_host || '').trim();
  cfg.db_port = (req.body.db_port || '3306').trim();
  cfg.db_name = (req.body.db_name || '').trim();
  cfg.db_user = (req.body.db_user || '').trim();
  cfg.db_pass = req.body.db_pass || '';
  cfg.db_charset = 'utf8mb4';
  saveConfig(cfg);
  req.session.admin_msg = 'Configuracion de BD guardada.';
  res.redirect('/admin');
});

// POST /admin/save-glpi
router.post('/save-glpi', (req, res) => {
  if (!req.session.admin_ok) return res.redirect('/admin');
  const cfg = loadConfig();
  cfg.entity_id = parseInt(req.body.entity_id) || 0;
  cfg.base_url = (req.body.base_url || '').trim().replace(/\/$/, '');
  saveConfig(cfg);
  req.session.admin_msg = 'Configuracion GLPI guardada.';
  res.redirect('/admin');
});

// POST /admin/save-zebra
router.post('/save-zebra', (req, res) => {
  if (!req.session.admin_ok) return res.redirect('/admin');
  const cfg = loadConfig();
  cfg.zebra_ip = (req.body.zebra_ip || '').trim();
  cfg.zebra_nombre = (req.body.zebra_nombre || '').trim();
  cfg.zebra_port = (req.body.zebra_port || '9100').trim();
  saveConfig(cfg);
  req.session.admin_msg = 'Impresora guardada.';
  res.redirect('/admin');
});

// POST /admin/save-marca
router.post('/save-marca', (req, res) => {
  if (!req.session.admin_ok) return res.redirect('/admin');
  const cfg = loadConfig();
  cfg.empresa_nombre = (req.body.empresa_nombre || 'NAGSA').trim();
  cfg.empresa_color = (req.body.empresa_color || '#E05816').trim();
  cfg.empresa_tema = (req.body.empresa_tema || 'oscuro').trim();
  saveConfig(cfg);
  req.session.admin_msg = 'Personalizacion guardada.';
  res.redirect('/admin');
});

// POST /admin/save-modulos
router.post('/save-modulos', (req, res) => {
  if (!req.session.admin_ok) return res.redirect('/admin');
  const cfg = loadConfig();
  const valid = ['activo','pruebas','deshabilitado'];
  cfg.modulos = cfg.modulos || {};
  for (const mod of ['etiquetas','actas','reportes','inversiones','permisos']) {
    const val = req.body['mod_' + mod] || 'deshabilitado';
    cfg.modulos[mod] = valid.includes(val) ? val : 'deshabilitado';
  }
  saveConfig(cfg);
  req.session.admin_msg = 'Estado de modulos guardado.';
  res.redirect('/admin');
});

// POST /admin/save-cats
router.post('/save-cats', (req, res) => {
  if (!req.session.admin_ok) return res.redirect('/admin');
  const cfg = loadConfig();
  cfg.show = {
    computadoras: !!req.body.show_comp,
    monitores: !!req.body.show_mon,
    impresoras: !!req.body.show_imp,
    perifericos: !!req.body.show_per,
    redes: !!req.body.show_red,
    gabinetes: !!req.body.show_gab,
    pasivos: !!req.body.show_pas,
    cartuchos: !!req.body.show_car,
    consumibles: !!req.body.show_con,
    telefonos: !!req.body.show_tel,
  };
  saveConfig(cfg);
  req.session.admin_msg = 'Categorias guardadas.';
  res.redirect('/admin');
});

// POST /admin/save-ad
router.post('/save-ad', (req, res) => {
  if (!req.session.admin_ok) return res.redirect('/admin');
  const cfg = loadConfig();
  const modo = req.body.ad_modo || 'automatica';
  cfg.active_directory = {
    modo,
    habilitado: modo === 'manual' ? !!(req.body.ad_servidor || '').trim() : true,
    nombre: (req.body.ad_nombre || 'Active Directory').trim(),
    servidor: (req.body.ad_servidor || '').trim(),
    puerto: parseInt(req.body.ad_puerto) || 389,
    dominio: (req.body.ad_dominio || '').trim(),
    base_dn: (req.body.ad_base_dn || '').trim(),
    sufijo_usuario: (req.body.ad_sufijo || '').trim(),
  };
  saveConfig(cfg);
  req.session.admin_msg = modo === 'automatica'
    ? 'Active Directory en modo automatico (GLPI).'
    : 'Configuracion Active Directory manual guardada.';
  res.redirect('/admin');
});

// POST /admin/save-localdb
router.post('/save-localdb', (req, res) => {
  if (!req.session.admin_ok) return res.redirect('/admin');
  const cfg = loadConfig();
  cfg.local_db = {
    server: (req.body.local_server || 'localhost\\SQLEXPRESS').trim(),
    database: (req.body.local_database || 'SistemaNG').trim(),
    user: (req.body.local_user || '').trim(),
    password: req.body.local_password || '',
  };
  saveConfig(cfg);
  req.session.admin_msg = 'Configuracion SQL Express guardada.';
  res.redirect('/admin');
});

// POST /admin/upload-logo (AJAX)
router.post('/upload-logo', upload.single('empresa_logo_file'), (req, res) => {
  if (!req.session.admin_ok) return res.json({ ok: false, msg: 'No autorizado' });
  if (!req.file) return res.json({ ok: false, msg: 'No se recibio ningun archivo.' });
  const cfg = loadConfig();
  cfg.empresa_logo = req.file.filename;
  saveConfig(cfg);
  res.json({ ok: true, msg: 'Logo actualizado correctamente.' });
});

// POST /admin/cambiar-tema (AJAX) - cualquier usuario logueado
router.post('/cambiar-tema', (req, res) => {
  if (!req.session.nagsa_user && !req.session.admin_ok) return res.json({ ok: false });
  const cfg = loadConfig();
  cfg.empresa_tema = (req.body.empresa_tema || 'oscuro').trim();
  saveConfig(cfg);
  res.json({ ok: true, tema: cfg.empresa_tema });
});

// GET /admin/debug-user — ver campos de usuario en GLPI (solo admin)
router.get('/debug-user', async (req, res) => {
  if (!req.session.admin_ok) return res.status(403).json({ error: 'No autorizado' });
  const mysql = require('mysql2/promise');
  const cfg = loadConfig();
  let conn;
  try {
    conn = await mysql.createConnection({
      host: cfg.db_host, port: parseInt(cfg.db_port) || 3306,
      database: cfg.db_name, user: cfg.db_user, password: cfg.db_pass,
      charset: 'utf8mb4',
    });
    const q = (req.query.q || '').trim();
    let query, params;
    if (q) {
      query = 'SELECT id, name, firstname, realname, authtype, auths_id FROM glpi_users WHERE (name LIKE ? OR firstname LIKE ? OR realname LIKE ?) AND is_deleted=0 AND is_active=1 LIMIT 10';
      params = ['%'+q+'%', '%'+q+'%', '%'+q+'%'];
    } else {
      query = 'SELECT id, name, firstname, realname, authtype, auths_id FROM glpi_users WHERE is_deleted=0 AND is_active=1 LIMIT 20';
      params = [];
    }
    const [rows] = await conn.execute(query, params);
    res.json({
      nota: 'authtype: 1=local, 2=LDAP, 3=mail, 4=other',
      campos: 'name=login, firstname=nombre, realname=apellido',
      usuarios: rows,
    });
  } catch (e) {
    res.status(500).json({ error: e.message });
  } finally {
    if (conn) await conn.end();
  }
});

// GET /admin/debug-ldap — ver config LDAP en GLPI (solo admin, temporal)
router.get('/debug-ldap', async (req, res) => {
  if (!req.session.admin_ok) return res.status(403).json({ error: 'No autorizado' });
  try {
    const glpiLdap = await getGLPILdapConfig();
    res.json({ detectado: !!glpiLdap, config: glpiLdap });
  } catch (e) {
    res.status(500).json({ error: e.message });
  }
});

module.exports = router;
