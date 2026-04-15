const express = require('express');
const router = express.Router();
const mysql = require('mysql2/promise');
const { loadConfig, saveConfig } = require('../config/config');
const { requireLogin, getNivelUsuario } = require('../middleware/auth');

async function getConn() {
  const cfg = loadConfig();
  return mysql.createConnection({
    host: cfg.db_host, port: parseInt(cfg.db_port) || 3306,
    database: cfg.db_name, user: cfg.db_user, password: cfg.db_pass,
    charset: 'utf8mb4',
  });
}

// Solo N1, N2 y N3 (jefes) pueden ver el modulo
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

// GET /permisos/api/mi-nivel - info del usuario actual
router.get('/api/mi-nivel', requireLogin, (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  res.json(nivelInfo);
});

// GET /permisos/api/arbol - obtener arbol completo (N1/N2) o parcial (N3)
router.get('/api/arbol', requireLogin, (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  if (nivelInfo.nivel > 3) return res.status(403).json({ error: 'Sin acceso' });

  const pc = cfg.permisos_config || { ti_usuarios: {}, grupos: {}, usuarios_nivel: {} };

  if (nivelInfo.nivel <= 2) {
    // N1/N2: ven todo
    res.json({
      ti_usuarios: pc.ti_usuarios || {},
      grupos: pc.grupos || {},
    });
  } else {
    // N3: solo su grupo
    const grupos = {};
    if (nivelInfo.grupo_id && pc.grupos[nivelInfo.grupo_id]) {
      grupos[nivelInfo.grupo_id] = pc.grupos[nivelInfo.grupo_id];
    }
    res.json({ ti_usuarios: {}, grupos });
  }
});

// GET /permisos/api/todos-usuarios - tabla con todos los usuarios y sus permisos actuales
router.get('/api/todos-usuarios', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  if (nivelInfo.nivel > 2) return res.status(403).json([]);

  let conn;
  try {
    conn = await getConn();
    const [rows] = await conn.execute(
      `SELECT u.id, u.name, u.firstname, u.realname, u.authtype
       FROM glpi_users u
       WHERE u.is_deleted = 0 AND u.is_active = 1 AND u.name != ''
       ORDER BY u.realname, u.firstname`
    );
    const pc = cfg.permisos_config || {};
    const tiUsers = pc.ti_usuarios || {};
    const grupos = pc.grupos || {};

    res.json(rows.map(r => {
      const uname = (r.name || '').toLowerCase();
      const tipo = r.authtype === 1 ? 'GLPI' : r.authtype === 2 ? 'LDAP' : r.authtype === 3 ? 'AD' : 'Otro';
      // Determinar nivel y grupo
      let nivel = 4, grupoNombre = '---';
      if (tiUsers[uname]) { nivel = 2; grupoNombre = 'TI'; }
      for (const [gid, g] of Object.entries(grupos)) {
        if ((g.jefe || '').toLowerCase() === uname) { nivel = 3; grupoNombre = g.nombre; }
        const miembros = (g.miembros || []).map(m => (typeof m === 'string' ? m : m.username).toLowerCase());
        if (miembros.includes(uname)) { grupoNombre = g.nombre; }
      }
      // Permisos efectivos
      let permsEfectivos = { actas: true, reportes: true };
      if (nivel === 2) {
        permsEfectivos = { etiquetas: true, actas: true, reportes: true, inversiones: true, permisos: true };
      } else {
        for (const [gid, g] of Object.entries(grupos)) {
          const miembros = (g.miembros || []).map(m => (typeof m === 'string' ? m : m.username).toLowerCase());
          const esJefe = (g.jefe || '').toLowerCase() === uname;
          if (esJefe || miembros.includes(uname)) {
            permsEfectivos = { actas: !!(g.permisos || {}).actas, reportes: !!(g.permisos || {}).reportes };
            if (esJefe) permsEfectivos.permisos = true;
            break;
          }
        }
      }
      return {
        username: r.name,
        nombre: ((r.firstname || '') + ' ' + (r.realname || '')).trim() || r.name,
        tipo, nivel, grupo: grupoNombre,
        permisos: permsEfectivos,
      };
    }));
  } catch (e) { res.status(500).json({ error: e.message }); }
  finally { if (conn) await conn.end(); }
});

// Obtener mapa de usuarios asignados con su ubicacion
function getUsuariosAsignados(cfg) {
  const asignados = {};
  const pc = cfg.permisos_config || {};
  // TI (N2)
  for (const [user, info] of Object.entries(pc.ti_usuarios || {})) {
    asignados[user.toLowerCase()] = 'TI';
  }
  // Jefes (N3) y miembros (N4)
  for (const grupo of Object.values(pc.grupos || {})) {
    const gNombre = grupo.nombre || 'Sin nombre';
    for (const j of (grupo.jefes || [])) {
      asignados[(j.username || '').toLowerCase()] = 'Jefe en ' + gNombre;
    }
    if (grupo.jefe) asignados[grupo.jefe.toLowerCase()] = 'Jefe en ' + gNombre;
    for (const m of (grupo.miembros || [])) {
      const mUser = typeof m === 'string' ? m : m.username;
      asignados[mUser.toLowerCase()] = gNombre;
    }
  }
  return asignados;
}

// GET /permisos/api/buscar-usuarios - buscar usuarios en GLPI (filtra los ya asignados)
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
      `SELECT u.id, u.name, u.firstname, u.realname, u.authtype
       FROM glpi_users u
       WHERE u.is_deleted = 0 AND u.is_active = 1 AND u.name != ''
         AND (u.name LIKE ? OR u.firstname LIKE ? OR u.realname LIKE ?)
       ORDER BY u.realname, u.firstname
       LIMIT 50`,
      [`%${q}%`, `%${q}%`, `%${q}%`]
    );
    // Mostrar todos pero indicar si ya estan asignados
    const resultado = rows.slice(0, 25).map(r => {
      const uname = (r.name || '').toLowerCase();
      const asignadoEn = asignados[uname] || null;
      return {
        username: r.name,
        nombre: ((r.firstname || '') + ' ' + (r.realname || '')).trim() || r.name,
        tipo: r.authtype === 1 ? 'GLPI' : r.authtype === 2 ? 'LDAP' : r.authtype === 3 ? 'AD' : 'Otro',
        asignado: asignadoEn,
      };
    });
    res.json(resultado);
  } catch (e) { res.json([]); }
  finally { if (conn) await conn.end(); }
});

// POST /permisos/api/ti/nombre-area - guardar nombre del area TI (solo N1)
router.post('/api/ti/nombre-area', requireLogin, (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  if (nivelInfo.nivel !== 1) return res.status(403).json({ error: 'Solo superadmin' });

  const { nombre_area } = req.body;
  if (!cfg.permisos_config) cfg.permisos_config = { ti_usuarios: {}, grupos: {}, usuarios_nivel: {} };
  cfg.permisos_config.ti_nombre_area = (nombre_area || '').trim();
  saveConfig(cfg);
  res.json({ ok: true });
});

// POST /permisos/api/ti/guardar - agregar/editar usuario TI (solo N1)
router.post('/api/ti/guardar', requireLogin, (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  if (nivelInfo.nivel !== 1) return res.status(403).json({ error: 'Solo superadmin' });

  const { username, nombre, admin_panel, puede_delegar } = req.body;
  if (!username) return res.status(400).json({ error: 'Usuario requerido' });

  if (!cfg.permisos_config) cfg.permisos_config = { ti_usuarios: {}, grupos: {}, usuarios_nivel: {} };
  if (!cfg.permisos_config.ti_usuarios) cfg.permisos_config.ti_usuarios = {};

  cfg.permisos_config.ti_usuarios[username.toLowerCase()] = {
    nombre: nombre || username,
    admin_panel: !!admin_panel,
    puede_delegar: !!puede_delegar,
  };
  saveConfig(cfg);
  res.json({ ok: true });
});

// POST /permisos/api/ti/eliminar - quitar usuario TI (solo N1)
router.post('/api/ti/eliminar', requireLogin, (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  if (nivelInfo.nivel !== 1) return res.status(403).json({ error: 'Solo superadmin' });

  const { username } = req.body;
  if (!username) return res.status(400).json({ error: 'Usuario requerido' });

  if (cfg.permisos_config && cfg.permisos_config.ti_usuarios) {
    delete cfg.permisos_config.ti_usuarios[username.toLowerCase()];
    saveConfig(cfg);
  }
  res.json({ ok: true });
});

// POST /permisos/api/grupo/crear - crear grupo (solo N1/N2)
router.post('/api/grupo/crear', requireLogin, (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  if (nivelInfo.nivel > 2) return res.status(403).json({ error: 'Solo TI' });

  const { nombre } = req.body;
  if (!nombre) return res.status(400).json({ error: 'Nombre requerido' });

  if (!cfg.permisos_config) cfg.permisos_config = { ti_usuarios: {}, grupos: {}, usuarios_nivel: {} };
  if (!cfg.permisos_config.grupos) cfg.permisos_config.grupos = {};

  // Generar ID incremental
  const ids = Object.keys(cfg.permisos_config.grupos).map(Number).filter(n => !isNaN(n));
  const nuevoId = ids.length ? Math.max(...ids) + 1 : 1;

  cfg.permisos_config.grupos[nuevoId] = {
    nombre,
    jefes: [],
    miembros: [],
    permisos: { actas: true, reportes: true, crear_entrega: false },
  };
  saveConfig(cfg);
  res.json({ ok: true, id: nuevoId });
});

// POST /permisos/api/grupo/editar - editar grupo (N1/N2)
router.post('/api/grupo/editar', requireLogin, (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  if (nivelInfo.nivel > 2) return res.status(403).json({ error: 'Solo TI' });

  const { grupo_id, nombre } = req.body;
  if (!grupo_id) return res.status(400).json({ error: 'Grupo requerido' });

  const pc = cfg.permisos_config || {};
  if (!pc.grupos || !pc.grupos[grupo_id]) return res.status(404).json({ error: 'Grupo no existe' });

  if (nombre) pc.grupos[grupo_id].nombre = nombre;
  saveConfig(cfg);
  res.json({ ok: true });
});

// POST /permisos/api/grupo/eliminar - eliminar grupo (solo N1/N2)
router.post('/api/grupo/eliminar', requireLogin, (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  if (nivelInfo.nivel > 2) return res.status(403).json({ error: 'Solo TI' });

  const { grupo_id } = req.body;
  if (!grupo_id) return res.status(400).json({ error: 'Grupo requerido' });

  if (cfg.permisos_config && cfg.permisos_config.grupos) {
    delete cfg.permisos_config.grupos[grupo_id];
    saveConfig(cfg);
  }
  res.json({ ok: true });
});

// POST /permisos/api/grupo/agregar-jefe - agregar jefe a grupo (N1/N2 o N3 de su grupo)
router.post('/api/grupo/agregar-jefe', requireLogin, (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  const { grupo_id, username, nombre } = req.body;
  if (nivelInfo.nivel === 3 && nivelInfo.grupo_id !== String(grupo_id)) {
    return res.status(403).json({ error: 'Solo puedes gestionar tu grupo' });
  }
  if (nivelInfo.nivel > 3) return res.status(403).json({ error: 'Sin acceso' });
  if (!grupo_id || !username) return res.status(400).json({ error: 'Datos incompletos' });

  const pc = cfg.permisos_config || {};
  if (!pc.grupos || !pc.grupos[grupo_id]) return res.status(404).json({ error: 'Grupo no existe' });

  const grupo = pc.grupos[grupo_id];
  if (!grupo.jefes) grupo.jefes = [];
  // Migrar formato viejo (jefe string) a nuevo (jefes array)
  if (grupo.jefe && !grupo.jefes.length) {
    grupo.jefes.push({ username: grupo.jefe.toLowerCase(), nombre: grupo.jefe_nombre || grupo.jefe });
    delete grupo.jefe; delete grupo.jefe_nombre;
  }
  const yaExiste = grupo.jefes.some(j => j.username.toLowerCase() === username.toLowerCase());
  if (!yaExiste) {
    grupo.jefes.push({ username: username.toLowerCase(), nombre: nombre || username });
  }
  saveConfig(cfg);
  res.json({ ok: true });
});

// POST /permisos/api/grupo/quitar-jefe - quitar jefe de grupo (N1/N2 o N3 de su grupo)
router.post('/api/grupo/quitar-jefe', requireLogin, (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  const { grupo_id, username } = req.body;
  if (nivelInfo.nivel === 3 && nivelInfo.grupo_id !== String(grupo_id)) {
    return res.status(403).json({ error: 'Solo puedes gestionar tu grupo' });
  }
  if (nivelInfo.nivel > 3) return res.status(403).json({ error: 'Sin acceso' });
  if (!grupo_id || !username) return res.status(400).json({ error: 'Datos incompletos' });

  const pc = cfg.permisos_config || {};
  if (!pc.grupos || !pc.grupos[grupo_id]) return res.status(404).json({ error: 'Grupo no existe' });

  const grupo = pc.grupos[grupo_id];
  if (!grupo.jefes) grupo.jefes = [];
  grupo.jefes = grupo.jefes.filter(j => j.username.toLowerCase() !== username.toLowerCase());
  saveConfig(cfg);
  res.json({ ok: true });
});

// POST /permisos/api/grupo/miembro - agregar miembro a grupo (N1/N2/N3 de su grupo)
router.post('/api/grupo/miembro', requireLogin, (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  const { grupo_id, username, nombre } = req.body;
  if (!grupo_id || !username) return res.status(400).json({ error: 'Datos incompletos' });

  // N3 solo puede agregar a su propio grupo
  if (nivelInfo.nivel === 3 && nivelInfo.grupo_id !== grupo_id) {
    return res.status(403).json({ error: 'Solo puedes gestionar tu grupo' });
  }
  if (nivelInfo.nivel > 3) return res.status(403).json({ error: 'Sin acceso' });

  const pc = cfg.permisos_config || {};
  if (!pc.grupos || !pc.grupos[grupo_id]) return res.status(404).json({ error: 'Grupo no existe' });

  const grupo = pc.grupos[grupo_id];
  if (!grupo.miembros) grupo.miembros = [];

  // Guardar como objeto con username y nombre
  const yaExiste = grupo.miembros.some(m =>
    (typeof m === 'string' ? m : m.username).toLowerCase() === username.toLowerCase()
  );
  if (!yaExiste) {
    grupo.miembros.push({ username: username.toLowerCase(), nombre: nombre || username });
    saveConfig(cfg);
  }
  res.json({ ok: true });
});

// POST /permisos/api/grupo/quitar-miembro - quitar miembro (N1/N2/N3 de su grupo)
router.post('/api/grupo/quitar-miembro', requireLogin, (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  const { grupo_id, username } = req.body;
  if (!grupo_id || !username) return res.status(400).json({ error: 'Datos incompletos' });

  if (nivelInfo.nivel === 3 && nivelInfo.grupo_id !== grupo_id) {
    return res.status(403).json({ error: 'Solo puedes gestionar tu grupo' });
  }
  if (nivelInfo.nivel > 3) return res.status(403).json({ error: 'Sin acceso' });

  const pc = cfg.permisos_config || {};
  if (!pc.grupos || !pc.grupos[grupo_id]) return res.status(404).json({ error: 'Grupo no existe' });

  const grupo = pc.grupos[grupo_id];
  grupo.miembros = (grupo.miembros || []).filter(m =>
    (typeof m === 'string' ? m : m.username).toLowerCase() !== username.toLowerCase()
  );
  saveConfig(cfg);
  res.json({ ok: true });
});

// POST /permisos/api/grupo/permisos - cambiar permisos de un grupo (N1/N2/N3 solo actas y reportes de su grupo)
router.post('/api/grupo/permisos', requireLogin, (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  const { grupo_id, permisos } = req.body;
  if (!grupo_id) return res.status(400).json({ error: 'Grupo requerido' });

  if (nivelInfo.nivel === 3) {
    if (nivelInfo.grupo_id !== grupo_id) {
      return res.status(403).json({ error: 'Solo puedes gestionar tu grupo' });
    }
    // N3 solo puede cambiar actas y reportes
    const pc = cfg.permisos_config || {};
    if (pc.grupos && pc.grupos[grupo_id]) {
      if (!pc.grupos[grupo_id].permisos) pc.grupos[grupo_id].permisos = {};
      if (typeof permisos.actas !== 'undefined') pc.grupos[grupo_id].permisos.actas = !!permisos.actas;
      if (typeof permisos.reportes !== 'undefined') pc.grupos[grupo_id].permisos.reportes = !!permisos.reportes;
      saveConfig(cfg);
    }
    return res.json({ ok: true });
  }

  if (nivelInfo.nivel > 3) return res.status(403).json({ error: 'Sin acceso' });

  // N1/N2: pueden cambiar todos los permisos incluyendo crear_entrega
  const pc = cfg.permisos_config || {};
  if (!pc.grupos || !pc.grupos[grupo_id]) return res.status(404).json({ error: 'Grupo no existe' });
  pc.grupos[grupo_id].permisos = {
    actas: !!permisos.actas,
    reportes: !!permisos.reportes,
    crear_entrega: !!permisos.crear_entrega,
  };
  saveConfig(cfg);
  res.json({ ok: true });
});

module.exports = router;
