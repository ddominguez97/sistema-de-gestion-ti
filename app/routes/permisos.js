const express = require('express');
const router = express.Router();
const mysql = require('mysql2/promise');
const { loadConfig, saveConfig, loadConfigFromDB } = require('../config/config');
const { query } = require('../config/database');
const { requireLogin, getNivelUsuario, searchADUsers, resolveADConfig } = require('../middleware/auth');

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

// GET /permisos/api/glpi-entidades — empresas desde grupos config (fuente de verdad)
router.get('/api/glpi-entidades', requireLogin, (req, res) => {
  const cfg = loadConfig();
  if (getNivelUsuario(cfg, req).nivel > 2) return res.status(403).json([]);
  const pc = cfg.permisos_config || {};
  const empresas = [...new Set(
    Object.values(pc.grupos || {}).map(g => (g.empresa || '').trim()).filter(Boolean)
  )].sort().map(nombre => ({ nombre }));
  res.json(empresas);
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

  // Buscar en GLPI
  let glpiResultados = [];
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
    glpiResultados = rows.map(r => ({
      username: r.name,
      nombre: ((r.firstname || '') + ' ' + (r.realname || '')).trim() || r.name,
      tipo: r.authtype === 1 ? 'GLPI' : r.authtype === 2 ? 'LDAP' : r.authtype === 3 ? 'AD' : 'Otro',
      asignado: asignados[(r.name || '').toLowerCase()] || null,
    }));
  } catch (e) { console.error('Error busqueda GLPI:', e.message); }
  finally { if (conn) try { await conn.end(); } catch {} }

  // Buscar en AD directo (usuarios que aun no han entrado al sistema)
  let adResultados = [];
  try {
    const adCfg = await resolveADConfig(cfg.active_directory || {});
    if (adCfg && adCfg.servidor) {
      const adUsers = await searchADUsers(q, adCfg);
      const glpiUsernames = new Set(glpiResultados.map(r => r.username.toLowerCase()));
      adResultados = adUsers
        .filter(u => !glpiUsernames.has(u.username.toLowerCase()))
        .map(u => ({ ...u, asignado: asignados[u.username.toLowerCase()] || null }));
    }
  } catch (e) { console.error('Error busqueda AD:', e.message); }

  res.json([...glpiResultados, ...adResultados].slice(0, 25));
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
  const { nombre, empresa } = req.body;
  if (!nombre) return res.status(400).json({ error: 'Nombre requerido' });
  const result = await query('INSERT INTO permisos_grupos (nombre, empresa) OUTPUT INSERTED.id VALUES (@nombre, @empresa)', { nombre, empresa: empresa || null });
  await refreshCache();
  res.json({ ok: true, id: result.recordset[0].id });
});

// POST /permisos/api/grupo/editar
router.post('/api/grupo/editar', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  if (getNivelUsuario(cfg, req).nivel > 2) return res.status(403).json({ error: 'Solo TI' });
  const { grupo_id, nombre, empresa } = req.body;
  if (!grupo_id) return res.status(400).json({ error: 'Grupo requerido' });
  const sets = [], params = { gid: parseInt(grupo_id) };
  if (nombre) { sets.push('nombre=@nombre'); params.nombre = nombre; }
  if (typeof empresa !== 'undefined') { sets.push('empresa=@empresa'); params.empresa = empresa || null; }
  if (sets.length) await query('UPDATE permisos_grupos SET ' + sets.join(',') + ', updated_at=GETDATE() WHERE id=@gid', params);
  await refreshCache();
  res.json({ ok: true });
});

// POST /permisos/api/grupo/eliminar
router.post('/api/grupo/eliminar', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  if (getNivelUsuario(cfg, req).nivel > 2) return res.status(403).json({ error: 'Solo TI' });
  const { grupo_id } = req.body;
  if (!grupo_id) return res.status(400).json({ error: 'Grupo requerido' });
  const { recordset: check } = await query('SELECT nombre FROM permisos_grupos WHERE id=@gid', { gid: parseInt(grupo_id) });
  if (check.length && ['Gerencia', 'Contadora'].includes(check[0].nombre)) {
    return res.status(403).json({ error: 'Este grupo es del sistema y no puede eliminarse.' });
  }
  await query('DELETE FROM permisos_grupos WHERE id=@gid', { gid: parseInt(grupo_id) });
  await refreshCache();
  res.json({ ok: true });
});

// POST /permisos/api/grupo/agregar-jefe
router.post('/api/grupo/agregar-jefe', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  if (getNivelUsuario(cfg, req).nivel > 2) return res.status(403).json({ error: 'Solo TI' });
  const { grupo_id, username, nombre } = req.body;
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
  if (getNivelUsuario(cfg, req).nivel > 2) return res.status(403).json({ error: 'Solo TI' });
  const { grupo_id, username } = req.body;
  if (!grupo_id || !username) return res.status(400).json({ error: 'Datos incompletos' });
  await query('DELETE FROM permisos_grupo_jefes WHERE grupo_id=@gid AND username=@user', { gid: parseInt(grupo_id), user: username.toLowerCase() });
  await refreshCache();
  res.json({ ok: true });
});

// POST /permisos/api/grupo/miembro
router.post('/api/grupo/miembro', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  if (getNivelUsuario(cfg, req).nivel > 2) return res.status(403).json({ error: 'Solo TI' });
  const { grupo_id, username, nombre, empresa } = req.body;
  if (!grupo_id || !username) return res.status(400).json({ error: 'Datos incompletos' });
  const { recordset } = await query('SELECT id FROM permisos_grupo_miembros WHERE grupo_id=@gid AND username=@user', { gid: parseInt(grupo_id), user: username.toLowerCase() });
  if (!recordset.length) {
    await query('INSERT INTO permisos_grupo_miembros (grupo_id,username,nombre,empresa) VALUES (@gid,@user,@nombre,@empresa)',
      { gid: parseInt(grupo_id), user: username.toLowerCase(), nombre: nombre || username, empresa: empresa || null });
  } else if (empresa) {
    await query('UPDATE permisos_grupo_miembros SET empresa=@empresa WHERE grupo_id=@gid AND username=@user',
      { gid: parseInt(grupo_id), user: username.toLowerCase(), empresa });
  }
  await refreshCache();
  res.json({ ok: true });
});

// POST /permisos/api/grupo/quitar-miembro
router.post('/api/grupo/quitar-miembro', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  if (getNivelUsuario(cfg, req).nivel > 2) return res.status(403).json({ error: 'Solo TI' });
  const { grupo_id, username } = req.body;
  if (!grupo_id || !username) return res.status(400).json({ error: 'Datos incompletos' });
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
  if (nivelInfo.nivel <= 2 && typeof permisos.puede_aprobar_cotizacion !== 'undefined') { sets.push('puede_aprobar_cotizacion=@aprobCot'); params.aprobCot = permisos.puede_aprobar_cotizacion ? 1 : 0; }
  if (nivelInfo.nivel <= 2 && typeof permisos.puede_aprobar_pago !== 'undefined') { sets.push('puede_aprobar_pago=@aprobPago'); params.aprobPago = permisos.puede_aprobar_pago ? 1 : 0; }
  if (nivelInfo.nivel <= 2 && typeof permisos.puede_marcar_pagado !== 'undefined') { sets.push('puede_marcar_pagado=@marcPagado'); params.marcPagado = permisos.puede_marcar_pagado ? 1 : 0; }
  if (nivelInfo.nivel <= 2 && typeof permisos.ver_actas !== 'undefined') { sets.push('ver_actas=@verActas'); params.verActas = permisos.ver_actas ? 1 : 0; }
  if (nivelInfo.nivel <= 2 && typeof permisos.ver_etiquetas !== 'undefined') { sets.push('ver_etiquetas=@verEtiq'); params.verEtiq = permisos.ver_etiquetas ? 1 : 0; }
  if (nivelInfo.nivel <= 2 && typeof permisos.ver_inversiones !== 'undefined') { sets.push('ver_inversiones=@verInv'); params.verInv = permisos.ver_inversiones ? 1 : 0; }
  if (nivelInfo.nivel <= 2 && typeof permisos.ver_reportes !== 'undefined') { sets.push('ver_reportes=@verRep'); params.verRep = permisos.ver_reportes ? 1 : 0; }
  if (nivelInfo.nivel <= 2 && typeof permisos.ver_permisos !== 'undefined') { sets.push('ver_permisos=@verPerm'); params.verPerm = permisos.ver_permisos ? 1 : 0; }
  if (sets.length) await query('UPDATE permisos_grupos SET ' + sets.join(',') + ', updated_at=GETDATE() WHERE id=@gid', params);
  await refreshCache();
  res.json({ ok: true });
});

// GET /permisos/api/motivos
// GET /permisos/api/sin-grupo — usuarios de AD/GLPI no asignados a ningún grupo, agrupados por empresa
router.get('/api/sin-grupo', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  if (nivelInfo.nivel > 2) return res.status(403).json({ error: 'Sin permiso' });

  const asignados = getUsuariosAsignados(cfg);
  const pc = cfg.permisos_config || {};
  // Empresas conocidas: las configuradas en algún grupo
  const knownEmpresas = new Set(
    Object.values(pc.grupos || {})
      .map(g => (g.empresa || '').trim().toLowerCase())
      .filter(Boolean)
  );

  let conn;
  try {
    conn = await getConn();
    const [rows] = await conn.execute(
      `SELECT u.name, u.firstname, u.realname
       FROM glpi_users u
       WHERE u.is_deleted=0 AND u.is_active=1 AND u.name != ''
         AND u.authtype IN (2,3)
       ORDER BY u.realname, u.firstname`
    );
    const sinGrupo = rows
      .map(r => ({ username: r.name, nombre: ((r.firstname||'')+' '+(r.realname||'')).trim() || r.name }))
      .filter(u => !asignados[u.username.toLowerCase()]);

    if (!sinGrupo.length) return res.json({ empresas: [], sin_empresa: [] });

    // Obtener OU top-level desde AD (distinguishedName)
    const ouMap = {}; // username → top-level OU
    try {
      const adCfg = await resolveADConfig(cfg.active_directory || {});
      if (adCfg && adCfg.bind_password) {
        const ldap = require('ldapjs');
        const loginField = adCfg.login_field || 'sAMAccountName';
        const userSet = new Set(sinGrupo.map(u => u.username.toLowerCase()));
        await new Promise(resolve => {
          const protocol = adCfg.use_tls ? 'ldaps' : 'ldap';
          const client = ldap.createClient({ url: `${protocol}://${adCfg.servidor}:${parseInt(adCfg.puerto)||389}`, connectTimeout: 6000 });
          client.on('error', () => resolve());
          client.bind(adCfg.bind_dn, adCfg.bind_password, err => {
            if (err) { client.destroy(); return resolve(); }
            client.search(adCfg.base_dn || '', {
              filter: `(&(objectClass=user)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))`,
              attributes: [loginField, 'distinguishedName'], scope: 'sub', sizeLimit: 2000,
            }, (searchErr, searchRes) => {
              if (searchErr) { client.destroy(); return resolve(); }
              searchRes.on('searchEntry', entry => {
                const attrs = (entry.pojo && entry.pojo.attributes) ? entry.pojo.attributes : [];
                let username = '', dn = entry.dn?.toString() || '';
                for (const a of attrs) {
                  if (a.type.toLowerCase() === loginField.toLowerCase()) username = (a.values && a.values[0] ? a.values[0] : '').toLowerCase();
                  if (a.type === 'distinguishedName' && a.values && a.values[0]) dn = a.values[0];
                }
                if (!username || !userSet.has(username)) return;
                const ous = (dn.match(/OU=([^,]+)/gi) || []).map(o => o.replace(/ou=/i, ''));
                // ous[last] = top-level (empresa o OU raíz), ous[0] = más específico
                ouMap[username] = ous.length ? ous[ous.length - 1] : '';
              });
              searchRes.on('end', () => { client.destroy(); resolve(); });
              searchRes.on('error', () => { client.destroy(); resolve(); });
            });
          });
        });
      }
    } catch {}

    // Separar: empresas conocidas vs OUs desconocidos
    const empMap = {};    // empresa conocida → [users]
    const ouSinEmp = {};  // OU desconocido → [users]

    for (const u of sinGrupo) {
      const topOU = ouMap[u.username.toLowerCase()] || '';
      if (topOU && knownEmpresas.has(topOU.toLowerCase())) {
        if (!empMap[topOU]) empMap[topOU] = [];
        empMap[topOU].push(u);
      } else {
        const ouLabel = topOU || 'Sin OU';
        if (!ouSinEmp[ouLabel]) ouSinEmp[ouLabel] = [];
        ouSinEmp[ouLabel].push(u);
      }
    }

    const empresas = Object.entries(empMap)
      .sort(([a],[b]) => a.localeCompare(b))
      .map(([nombre, usuarios]) => ({ nombre, usuarios: usuarios.sort((a,b) => a.nombre.localeCompare(b.nombre)) }));

    const sin_empresa = Object.entries(ouSinEmp)
      .sort(([a],[b]) => a.localeCompare(b))
      .map(([ou, usuarios]) => ({ ou, usuarios: usuarios.sort((a,b) => a.nombre.localeCompare(b.nombre)) }));

    res.json({ empresas, sin_empresa });
  } catch (e) {
    res.status(500).json({ error: e.message });
  } finally { if (conn) try { await conn.end(); } catch {} }
});

router.get('/api/motivos', requireLogin, async (req, res) => {
  const { recordset } = await query('SELECT nombre, tipo FROM motivos_salida WHERE activo=1 ORDER BY orden');
  res.json(recordset);
});

// POST /permisos/api/motivos/guardar
router.post('/api/motivos/guardar', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  if (getNivelUsuario(cfg, req).nivel > 2) return res.status(403).json({ error: 'Solo TI' });
  const { motivos } = req.body;
  if (!Array.isArray(motivos)) return res.status(400).json({ error: 'Formato invalido' });
  // Desactivar todos y reinsertar
  await query('DELETE FROM motivos_salida');
  const clean = motivos.filter(m => m && (m.nombre || m).toString().trim());
  for (let i = 0; i < clean.length; i++) {
    const item = typeof clean[i] === 'string' ? { nombre: clean[i], tipo: 'externo' } : clean[i];
    await query('INSERT INTO motivos_salida (nombre,tipo,orden) VALUES (@nombre,@tipo,@orden)',
      { nombre: item.nombre.trim(), tipo: item.tipo || 'externo', orden: i });
  }
  await refreshCache();
  res.json({ ok: true });
});

module.exports = router;
