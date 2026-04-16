const express = require('express');
const router = express.Router();
const mysql = require('mysql2/promise');
const { loadConfig, saveConfig, loadConfigFromDB } = require('../config/config');
const { query } = require('../config/database');
const { requireLogin, getNivelUsuario } = require('../middleware/auth');

async function getConn() {
  const cfg = loadConfig();
  return mysql.createConnection({
    host: cfg.db_host, port: parseInt(cfg.db_port) || 3306,
    database: cfg.db_name, user: cfg.db_user, password: cfg.db_pass,
    charset: 'utf8mb4',
  });
}

// Refrescar cache de permisos despues de cada cambio
async function refreshCache() {
  try { await loadConfigFromDB(); } catch (e) { console.error('Error refresh cache:', e.message); }
}

function puedeVerPermisos(cfg, req) {
  const info = getNivelUsuario(cfg, req);
  return info.nivel <= 3;
}

// GET /permisos - vista principal
router.get('/', requireLogin, (req, res) => {
  const cfg = loadConfig();
  if (!puedeVerPermisos(cfg, req)) return res.redirect('/');
  const nivelInfo = getNivelUsuario(cfg, req);
  const pc = cfg.permisos_config || { ti_usuarios: {}, grupos: {}, usuarios_nivel: {} };
  res.render('permisos', { nivelInfo, permisosConfig: pc });
});

// GET /permisos/api/mi-nivel
router.get('/api/mi-nivel', requireLogin, (req, res) => {
  const cfg = loadConfig();
  res.json(getNivelUsuario(cfg, req));
});

// GET /permisos/api/arbol
router.get('/api/arbol', requireLogin, (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  if (nivelInfo.nivel > 3) return res.status(403).json({ error: 'Sin acceso' });
  const pc = cfg.permisos_config || { ti_usuarios: {}, grupos: {}, usuarios_nivel: {} };
  if (nivelInfo.nivel <= 2) {
    res.json({ ti_usuarios: pc.ti_usuarios || {}, grupos: pc.grupos || {} });
  } else {
    const grupos = {};
    if (nivelInfo.grupo_id && pc.grupos[nivelInfo.grupo_id]) {
      grupos[nivelInfo.grupo_id] = pc.grupos[nivelInfo.grupo_id];
    }
    res.json({ ti_usuarios: {}, grupos });
  }
});

// GET /permisos/api/todos-usuarios
router.get('/api/todos-usuarios', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  if (nivelInfo.nivel > 2) return res.status(403).json([]);
  let conn;
  try {
    conn = await getConn();
    const [rows] = await conn.execute(
      `SELECT u.id, u.name, u.firstname, u.realname, u.authtype
       FROM glpi_users u WHERE u.is_deleted = 0 AND u.is_active = 1 AND u.name != ''
       ORDER BY u.realname, u.firstname`
    );
    const pc = cfg.permisos_config || {};
    const tiUsers = pc.ti_usuarios || {};
    const grupos = pc.grupos || {};
    res.json(rows.map(r => {
      const uname = (r.name || '').toLowerCase();
      const tipo = r.authtype === 1 ? 'GLPI' : r.authtype === 2 ? 'LDAP' : r.authtype === 3 ? 'AD' : 'Otro';
      let nivel = 4, grupoNombre = '---';
      if (tiUsers[uname]) { nivel = 2; grupoNombre = 'TI'; }
      for (const [gid, g] of Object.entries(grupos)) {
        if ((g.jefes || []).some(j => j.username.toLowerCase() === uname)) { nivel = 3; grupoNombre = g.nombre; }
        if ((g.miembros || []).some(m => m.username.toLowerCase() === uname)) { grupoNombre = g.nombre; }
      }
      let permsEfectivos = { actas: true, reportes: true };
      if (nivel === 2) permsEfectivos = { etiquetas: true, actas: true, reportes: true, inversiones: true, permisos: true };
      else {
        for (const [gid, g] of Object.entries(grupos)) {
          const esJefe = (g.jefes || []).some(j => j.username.toLowerCase() === uname);
          const esMiembro = (g.miembros || []).some(m => m.username.toLowerCase() === uname);
          if (esJefe || esMiembro) {
            permsEfectivos = { actas: !!(g.permisos || {}).actas, reportes: !!(g.permisos || {}).reportes };
            if (esJefe) permsEfectivos.permisos = true;
            break;
          }
        }
      }
      return { username: r.name, nombre: ((r.firstname || '') + ' ' + (r.realname || '')).trim() || r.name, tipo, nivel, grupo: grupoNombre, permisos: permsEfectivos };
    }));
  } catch (e) { res.status(500).json({ error: e.message }); }
  finally { if (conn) await conn.end(); }
});

// Obtener mapa de usuarios asignados
function getUsuariosAsignados(cfg) {
  const asignados = {};
  const pc = cfg.permisos_config || {};
  for (const [user, info] of Object.entries(pc.ti_usuarios || {})) asignados[user.toLowerCase()] = 'TI';
  for (const grupo of Object.values(pc.grupos || {})) {
    const gNombre = grupo.nombre || 'Sin nombre';
    for (const j of (grupo.jefes || [])) asignados[(j.username || '').toLowerCase()] = 'Jefe en ' + gNombre;
    if (grupo.jefe) asignados[grupo.jefe.toLowerCase()] = 'Jefe en ' + gNombre;
    for (const m of (grupo.miembros || [])) {
      const mUser = typeof m === 'string' ? m : m.username;
      asignados[mUser.toLowerCase()] = gNombre;
    }
  }
  return asignados;
}

// GET /permisos/api/buscar-usuarios
router.get('/api/buscar-usuarios', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  if (nivelInfo.nivel > 3) return res.status(403).json([]);
  const q = (req.query.q || '').trim();
  if (q.length < 2) return res.json([]);
  const asignados = getUsuariosAsignados(cfg);
  let conn;
  try {
    conn = await getConn();
    const [rows] = await conn.execute(
      `SELECT u.id, u.name, u.firstname, u.realname, u.authtype FROM glpi_users u
       WHERE u.is_deleted = 0 AND u.is_active = 1 AND u.name != ''
         AND (u.name LIKE ? OR u.firstname LIKE ? OR u.realname LIKE ?)
       ORDER BY u.realname, u.firstname LIMIT 50`,
      [`%${q}%`, `%${q}%`, `%${q}%`]
    );
    res.json(rows.slice(0, 25).map(r => {
      const uname = (r.name || '').toLowerCase();
      return {
        username: r.name,
        nombre: ((r.firstname || '') + ' ' + (r.realname || '')).trim() || r.name,
        tipo: r.authtype === 1 ? 'GLPI' : r.authtype === 2 ? 'LDAP' : r.authtype === 3 ? 'AD' : 'Otro',
        asignado: asignados[uname] || null,
      };
    }));
  } catch (e) { res.json([]); }
  finally { if (conn) await conn.end(); }
});

// POST /permisos/api/ti/nombre-area
router.post('/api/ti/nombre-area', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  if (nivelInfo.nivel !== 1) return res.status(403).json({ error: 'Solo superadmin' });
  const { nombre_area } = req.body;
  await query("UPDATE configuracion SET valor=@val, updated_at=GETDATE() WHERE clave='ti_nombre_area'", { val: (nombre_area || '').trim() });
  await refreshCache();
  res.json({ ok: true });
});

// POST /permisos/api/ti/guardar
router.post('/api/ti/guardar', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  if (nivelInfo.nivel > 2) return res.status(403).json({ error: 'Solo TI' });
  const { username, nombre, admin_panel, puede_delegar } = req.body;
  if (!username) return res.status(400).json({ error: 'Usuario requerido' });
  const user = username.toLowerCase();

  if (nivelInfo.nivel === 1) {
    const { recordset } = await query('SELECT id FROM permisos_ti WHERE username=@user', { user });
    if (recordset.length) {
      await query('UPDATE permisos_ti SET nombre=@nombre, admin_panel=@admin, puede_delegar=@delegar WHERE username=@user',
        { user, nombre: nombre || username, admin: admin_panel ? 1 : 0, delegar: puede_delegar ? 1 : 0 });
    } else {
      await query('INSERT INTO permisos_ti (username,nombre,admin_panel,puede_delegar) VALUES (@user,@nombre,@admin,@delegar)',
        { user, nombre: nombre || username, admin: admin_panel ? 1 : 0, delegar: puede_delegar ? 1 : 0 });
    }
  } else {
    const { recordset } = await query('SELECT id FROM permisos_ti WHERE username=@user', { user });
    if (recordset.length) return res.status(403).json({ error: 'Solo el superadmin puede editar privilegios' });
    await query('INSERT INTO permisos_ti (username,nombre,admin_panel,puede_delegar) VALUES (@user,@nombre,0,0)',
      { user, nombre: nombre || username });
  }
  await refreshCache();
  res.json({ ok: true });
});

// POST /permisos/api/ti/eliminar
router.post('/api/ti/eliminar', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  if (getNivelUsuario(cfg, req).nivel !== 1) return res.status(403).json({ error: 'Solo superadmin' });
  const { username } = req.body;
  if (!username) return res.status(400).json({ error: 'Usuario requerido' });
  await query('DELETE FROM permisos_ti WHERE username=@user', { user: username.toLowerCase() });
  await refreshCache();
  res.json({ ok: true });
});

// POST /permisos/api/grupo/crear
router.post('/api/grupo/crear', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  if (getNivelUsuario(cfg, req).nivel > 2) return res.status(403).json({ error: 'Solo TI' });
  const { nombre } = req.body;
  if (!nombre) return res.status(400).json({ error: 'Nombre requerido' });
  const result = await query('INSERT INTO permisos_grupos (nombre) OUTPUT INSERTED.id VALUES (@nombre)', { nombre });
  await refreshCache();
  res.json({ ok: true, id: result.recordset[0].id });
});

// POST /permisos/api/grupo/editar
router.post('/api/grupo/editar', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  if (getNivelUsuario(cfg, req).nivel > 2) return res.status(403).json({ error: 'Solo TI' });
  const { grupo_id, nombre } = req.body;
  if (!grupo_id) return res.status(400).json({ error: 'Grupo requerido' });
  if (nombre) await query('UPDATE permisos_grupos SET nombre=@nombre, updated_at=GETDATE() WHERE id=@gid', { gid: parseInt(grupo_id), nombre });
  await refreshCache();
  res.json({ ok: true });
});

// POST /permisos/api/grupo/eliminar
router.post('/api/grupo/eliminar', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  if (getNivelUsuario(cfg, req).nivel > 2) return res.status(403).json({ error: 'Solo TI' });
  const { grupo_id } = req.body;
  if (!grupo_id) return res.status(400).json({ error: 'Grupo requerido' });
  await query('DELETE FROM permisos_grupos WHERE id=@gid', { gid: parseInt(grupo_id) });
  await refreshCache();
  res.json({ ok: true });
});

// POST /permisos/api/grupo/agregar-jefe
router.post('/api/grupo/agregar-jefe', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  const { grupo_id, username, nombre } = req.body;
  if (nivelInfo.nivel === 3 && nivelInfo.grupo_id !== String(grupo_id)) return res.status(403).json({ error: 'Solo tu grupo' });
  if (nivelInfo.nivel > 3) return res.status(403).json({ error: 'Sin acceso' });
  if (!grupo_id || !username) return res.status(400).json({ error: 'Datos incompletos' });
  const { recordset } = await query('SELECT id FROM permisos_grupo_jefes WHERE grupo_id=@gid AND username=@user', { gid: parseInt(grupo_id), user: username.toLowerCase() });
  if (!recordset.length) {
    await query('INSERT INTO permisos_grupo_jefes (grupo_id,username,nombre) VALUES (@gid,@user,@nombre)', { gid: parseInt(grupo_id), user: username.toLowerCase(), nombre: nombre || username });
  }
  await refreshCache();
  res.json({ ok: true });
});

// POST /permisos/api/grupo/quitar-jefe
router.post('/api/grupo/quitar-jefe', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  const { grupo_id, username } = req.body;
  if (nivelInfo.nivel === 3 && nivelInfo.grupo_id !== String(grupo_id)) return res.status(403).json({ error: 'Solo tu grupo' });
  if (nivelInfo.nivel > 3) return res.status(403).json({ error: 'Sin acceso' });
  if (!grupo_id || !username) return res.status(400).json({ error: 'Datos incompletos' });
  await query('DELETE FROM permisos_grupo_jefes WHERE grupo_id=@gid AND username=@user', { gid: parseInt(grupo_id), user: username.toLowerCase() });
  await refreshCache();
  res.json({ ok: true });
});

// POST /permisos/api/grupo/miembro
router.post('/api/grupo/miembro', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  const { grupo_id, username, nombre } = req.body;
  if (!grupo_id || !username) return res.status(400).json({ error: 'Datos incompletos' });
  if (nivelInfo.nivel === 3 && nivelInfo.grupo_id !== String(grupo_id)) return res.status(403).json({ error: 'Solo tu grupo' });
  if (nivelInfo.nivel > 3) return res.status(403).json({ error: 'Sin acceso' });
  const { recordset } = await query('SELECT id FROM permisos_grupo_miembros WHERE grupo_id=@gid AND username=@user', { gid: parseInt(grupo_id), user: username.toLowerCase() });
  if (!recordset.length) {
    await query('INSERT INTO permisos_grupo_miembros (grupo_id,username,nombre) VALUES (@gid,@user,@nombre)', { gid: parseInt(grupo_id), user: username.toLowerCase(), nombre: nombre || username });
  }
  await refreshCache();
  res.json({ ok: true });
});

// POST /permisos/api/grupo/quitar-miembro
router.post('/api/grupo/quitar-miembro', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  const { grupo_id, username } = req.body;
  if (!grupo_id || !username) return res.status(400).json({ error: 'Datos incompletos' });
  if (nivelInfo.nivel === 3 && nivelInfo.grupo_id !== String(grupo_id)) return res.status(403).json({ error: 'Solo tu grupo' });
  if (nivelInfo.nivel > 3) return res.status(403).json({ error: 'Sin acceso' });
  await query('DELETE FROM permisos_grupo_miembros WHERE grupo_id=@gid AND username=@user', { gid: parseInt(grupo_id), user: username.toLowerCase() });
  await refreshCache();
  res.json({ ok: true });
});

// POST /permisos/api/grupo/permisos
router.post('/api/grupo/permisos', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  const { grupo_id, permisos } = req.body;
  if (!grupo_id) return res.status(400).json({ error: 'Grupo requerido' });
  if (nivelInfo.nivel === 3 && nivelInfo.grupo_id !== String(grupo_id)) return res.status(403).json({ error: 'Solo tu grupo' });
  if (nivelInfo.nivel > 3) return res.status(403).json({ error: 'Sin acceso' });

  const sets = [];
  const params = { gid: parseInt(grupo_id) };
  if (typeof permisos.actas !== 'undefined') { sets.push('perm_actas=@actas'); params.actas = permisos.actas ? 1 : 0; }
  if (typeof permisos.reportes !== 'undefined') { sets.push('perm_reportes=@reportes'); params.reportes = permisos.reportes ? 1 : 0; }
  if (nivelInfo.nivel <= 2 && typeof permisos.crear_entrega !== 'undefined') { sets.push('perm_crear_entrega=@entrega'); params.entrega = permisos.crear_entrega ? 1 : 0; }
  if (sets.length) await query('UPDATE permisos_grupos SET ' + sets.join(',') + ', updated_at=GETDATE() WHERE id=@gid', params);
  await refreshCache();
  res.json({ ok: true });
});

// GET /permisos/api/motivos
router.get('/api/motivos', requireLogin, async (req, res) => {
  const { recordset } = await query('SELECT nombre FROM motivos_salida WHERE activo=1 ORDER BY orden');
  res.json(recordset.map(r => r.nombre));
});

// POST /permisos/api/motivos/guardar
router.post('/api/motivos/guardar', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  if (getNivelUsuario(cfg, req).nivel > 2) return res.status(403).json({ error: 'Solo TI' });
  const { motivos } = req.body;
  if (!Array.isArray(motivos)) return res.status(400).json({ error: 'Formato invalido' });
  // Desactivar todos y reinsertar
  await query('DELETE FROM motivos_salida');
  const clean = motivos.filter(m => m && m.trim()).map(m => m.trim());
  for (let i = 0; i < clean.length; i++) {
    await query('INSERT INTO motivos_salida (nombre,orden) VALUES (@nombre,@orden)', { nombre: clean[i], orden: i });
  }
  await refreshCache();
  res.json({ ok: true });
});

module.exports = router;
