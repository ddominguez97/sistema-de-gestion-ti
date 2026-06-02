const express = require('express');
const router = express.Router();
const mysql = require('mysql2/promise');
const { requireLogin, getNivelUsuario, resolveADConfig } = require('../middleware/auth');
const { loadConfig } = require('../config/config');
const { query } = require('../config/database');

async function getConn() {
  const cfg = loadConfig();
  return mysql.createConnection({
    host: cfg.db_host, port: parseInt(cfg.db_port) || 3306,
    database: cfg.db_name, user: cfg.db_user, password: cfg.db_pass,
    charset: 'utf8mb4',
  });
}

const TABLAS_GLPI = [
  { tabla: 'glpi_computers',   categoria: 'Computadora', tipo_tabla: 'glpi_computertypes',   tipo_fk: 'computertypes_id' },
  { tabla: 'glpi_monitors',    categoria: 'Monitor',     tipo_tabla: 'glpi_monitortypes',    tipo_fk: 'monitortypes_id' },
  { tabla: 'glpi_printers',    categoria: 'Impresora',   tipo_tabla: 'glpi_printertypes',    tipo_fk: 'printertypes_id' },
  { tabla: 'glpi_phones',      categoria: 'Telefono',    tipo_tabla: 'glpi_phonetypes',      tipo_fk: 'phonetypes_id' },
];

async function getEmpresasMap(usernames) {
  const empresaMap = {};
  if (!usernames.length) return empresaMap;

  try {
    const cfg = loadConfig();
    const adCfg = await resolveADConfig(cfg.active_directory || {});
    if (!adCfg || !adCfg.bind_password) return empresaMap;
    const ldap = require('ldapjs');
    await new Promise((resolve) => {
      const protocol = adCfg.use_tls ? 'ldaps' : 'ldap';
      const client = ldap.createClient({ url: `${protocol}://${adCfg.servidor}:${parseInt(adCfg.puerto)||389}`, connectTimeout: 6000 });
      client.on('error', () => resolve());
      client.bind(adCfg.bind_dn, adCfg.bind_password, (err) => {
        if (err) { client.destroy(); return resolve(); }
        const loginField = adCfg.login_field || 'sAMAccountName';
        client.search(adCfg.base_dn || '', {
          filter: `(&(objectClass=user)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))`,
          attributes: [loginField, 'distinguishedName'],
          scope: 'sub', sizeLimit: 2000,
        }, (searchErr, searchRes) => {
          if (searchErr) { client.destroy(); return resolve(); }
          const usernamesSet = new Set(usernames.map(u => u.toLowerCase()));
          searchRes.on('searchEntry', (entry) => {
            const attrs = (entry.pojo && entry.pojo.attributes) ? entry.pojo.attributes : [];
            let username = '';
            let dn = entry.dn?.toString() || entry.objectName || '';
            for (const a of attrs) {
              if (a.type.toLowerCase() === loginField.toLowerCase())
                username = (a.values && a.values[0] ? a.values[0] : '').toLowerCase();
              if (a.type === 'distinguishedName' && a.values && a.values[0]) dn = a.values[0];
            }
            if (!username || !usernamesSet.has(username)) return;
            const ous = (dn.match(/OU=([^,]+)/gi) || []).map(o => o.replace(/ou=/i, ''));
            const empresa = ous.length ? ous[ous.length - 1] : (username === 'administrador' ? 'NAGSA' : null);
            const departamento = ous.length > 1 ? ous[ous.length - 2] : null;
            if (empresa) empresaMap[username] = { empresa, departamento };
          });
          searchRes.on('end', () => { client.destroy(); resolve(); });
          searchRes.on('error', () => { client.destroy(); resolve(); });
        });
      });
    });
  } catch (e) { /* AD failed */ }

  return empresaMap;
}

router.get('/', requireLogin, (req, res) => {
  const nivelInfo = getNivelUsuario(res.locals.cfg, req);
  res.render('equipos', { nivelInfo });
});

router.get('/api/mis-equipos', requireLogin, async (req, res) => {
  const username = (req.session.nagsa_user || '').toLowerCase();
  let glpi = null;
  try {
    glpi = await getConn();
    const [users] = await glpi.execute(
      'SELECT id FROM glpi_users WHERE LOWER(name)=? AND is_active=1 LIMIT 1',
      [username]
    );
    const equipos = [];
    if (users.length) {
      const usersId = users[0].id;
      for (const { tabla, categoria, tipo_tabla, tipo_fk } of TABLAS_GLPI) {
        const [rows] = await glpi.execute(
          `SELECT t.name, t.serial,
                  s.name AS estado_glpi,
                  mf.name AS fabricante,
                  tp.name AS tipo_real
           FROM ${tabla} t
           LEFT JOIN glpi_states s ON s.id = t.states_id
           LEFT JOIN glpi_manufacturers mf ON mf.id = t.manufacturers_id
           LEFT JOIN ${tipo_tabla} tp ON tp.id = t.${tipo_fk}
           WHERE t.is_deleted=0 AND t.is_template=0 AND t.users_id=?`,
          [usersId]
        );
        for (const r of rows) {
          equipos.push({
            nombre: r.name || '',
            serie: (r.serial || '').trim() || '---',
            categoria,
            tipo: r.tipo_real || null,
            fabricante: r.fabricante || '',
            estado_glpi: r.estado_glpi || '---',
          });
        }
      }
    }
    res.json({ equipos });
  } catch(e) {
    res.status(500).json({ error: e.message });
  } finally { if (glpi) await glpi.end(); }
});

router.get('/api/listar', requireLogin, async (req, res) => {
  const nivelInfo = getNivelUsuario(res.locals.cfg, req);
  if (nivelInfo.nivel > 2) return res.status(403).json({});

  let glpi = null;
  try {
    // 1. Actas existentes en SQL Server
    const { recordset: actas } = await query(
      "SELECT serie, acta_numero, acta_id, fecha_entrega FROM equipos_asignados WHERE serie IS NOT NULL AND serie <> '---'"
    );
    const actasBySN = {};
    for (const a of actas) { if (a.serie) actasBySN[a.serie.trim().toLowerCase()] = a; }

    // 2. Consultar GLPI
    glpi = await getConn();
    const personasMap = {};
    const areasMap = {};
    const sinAsig = [];

    for (const { tabla, categoria, tipo_tabla, tipo_fk } of TABLAS_GLPI) {
      const [rows] = await glpi.execute(
        `SELECT t.id, t.name, t.serial, t.users_id, t.locations_id,
                s.name AS estado_glpi,
                mf.name AS fabricante,
                tp.name AS tipo_real,
                TRIM(CONCAT(COALESCE(u.firstname,''),' ',COALESCE(u.realname,''))) AS usuario_nombre,
                u.name AS usuario_username,
                l.completename AS ubicacion
         FROM ${tabla} t
         LEFT JOIN glpi_states s ON s.id = t.states_id
         LEFT JOIN glpi_manufacturers mf ON mf.id = t.manufacturers_id
         LEFT JOIN ${tipo_tabla} tp ON tp.id = t.${tipo_fk}
         LEFT JOIN glpi_users u ON u.id = t.users_id
         LEFT JOIN glpi_locations l ON l.id = t.locations_id
         WHERE t.is_deleted=0 AND t.is_template=0`
      );
      for (const r of rows) {
        const sn = (r.serial || '').trim();
        const acta = sn ? (actasBySN[sn.toLowerCase()] || null) : null;
        const usersId = Number(r.users_id) || 0;
        const locId = Number(r.locations_id) || 0;
        const eq = {
          glpi_id: r.id, glpi_tabla: tabla,
          nombre: r.name || '', serie: sn || '---',
          categoria, tipo: r.tipo_real || null,
          fabricante: r.fabricante || '',
          estado_glpi: r.estado_glpi || '---',
          acta_numero: acta?.acta_numero || null,
          acta_id: acta?.acta_id || null,
          acta_fecha: acta?.fecha_entrega || null,
        };
        if (usersId > 0) {
          const key = (r.usuario_username || '').toLowerCase() || ('uid_' + usersId);
          if (!personasMap[key]) personasMap[key] = { username: r.usuario_username || '', nombre: r.usuario_nombre?.trim() || r.usuario_username || '---', equipos: [] };
          personasMap[key].equipos.push(eq);
        } else if (locId > 0) {
          const key = r.ubicacion || ('loc_' + locId);
          if (!areasMap[key]) areasMap[key] = { nombre: r.ubicacion || key, equipos: [] };
          areasMap[key].equipos.push(eq);
        } else {
          sinAsig.push(eq);
        }
      }
    }

    // 3. Consumibles desde equipos_asignados
    const { recordset: cons } = await query(
      "SELECT nombre,tipo,fabricante,serie,usuario_username,acta_numero,acta_id,fecha_entrega FROM equipos_asignados WHERE glpi_tabla='glpi_consumableitems' OR glpi_tabla IS NULL"
    );
    for (const c of cons) {
      const key = (c.usuario_username || '').toLowerCase();
      if (key && personasMap[key]) {
        personasMap[key].equipos.push({ nombre: c.nombre, serie: c.serie||'---', categoria: c.tipo||'Consumible', tipo: null, fabricante: c.fabricante||'', estado_glpi: 'Consumible', acta_numero: c.acta_numero, acta_id: c.acta_id, acta_fecha: c.fecha_entrega });
      }
    }

    // 4. Grupos desde permisos config (misma jerarquía que Permisos)
    const pc = (res.locals.cfg || loadConfig()).permisos_config || {};
    const gruposCfg = pc.grupos || {};
    const tiNombreArea = pc.ti_nombre_area || 'TI/Sistemas';

    // username → { grupo_id, grupo_nombre, empresa }
    const userToGroup = {};
    const needsEmpresa = new Set(); // miembros de grupos sin empresa configurada

    // TI users → empresa 'TI'
    for (const uname of Object.keys(pc.ti_usuarios || {})) {
      userToGroup[uname.toLowerCase()] = { grupo_id: 'ti', grupo_nombre: tiNombreArea, empresa: 'TI' };
    }

    // Grupos: miembros y jefes
    for (const [gid, g] of Object.entries(gruposCfg)) {
      const inv = g.inversiones || {};
      const esContadora = !!inv.puede_marcar_pagado;
      for (const m of (g.miembros || [])) {
        const uname = (m.username || '').toLowerCase();
        // Contadoras usan mb.empresa (por empresa), resto usa g.empresa del grupo
        const empresa = esContadora ? (m.empresa || g.empresa || null) : (g.empresa || null);
        userToGroup[uname] = { grupo_id: gid, grupo_nombre: g.nombre, empresa };
        if (!empresa) needsEmpresa.add(uname);
      }
      for (const m of (g.jefes || [])) {
        const uname = (m.username || '').toLowerCase();
        userToGroup[uname] = { grupo_id: gid, grupo_nombre: g.nombre, empresa: g.empresa || null };
        if (!g.empresa) needsEmpresa.add(uname);
      }
    }

    // 5. AD: usuarios sin grupo + miembros de grupos sin empresa configurada
    const sinGrupoUsernames = Object.keys(personasMap).filter(u => !userToGroup[u]);
    const adQueryUsernames = [...new Set([...sinGrupoUsernames, ...needsEmpresa])];
    const adMap = adQueryUsernames.length ? await getEmpresasMap(adQueryUsernames) : {};

    // Rellenar empresa desde AD para grupos sin empresa (ej: Gerencia sin empresa asignada)
    for (const uname of needsEmpresa) {
      if (userToGroup[uname] && !userToGroup[uname].empresa) {
        const adInfo = adMap[uname];
        userToGroup[uname].empresa = adInfo ? adInfo.empresa : 'Sin empresa';
      }
    }

    // 6. Construir estructura empresa → { sin_grupo, grupos }
    const empMap = {};
    function empEntry(nombre) {
      const k = (nombre || 'Sin empresa').toUpperCase();
      if (!empMap[k]) empMap[k] = { nombre: nombre || 'Sin empresa', sin_grupo: [], grupos: {} };
      return empMap[k];
    }
    for (const [username, persona] of Object.entries(personasMap)) {
      if (userToGroup[username]) {
        const { grupo_id, grupo_nombre, empresa } = userToGroup[username];
        const emp = empEntry(empresa);
        if (!emp.grupos[grupo_id]) emp.grupos[grupo_id] = { id: grupo_id, nombre: grupo_nombre, personas: [] };
        emp.grupos[grupo_id].personas.push(persona);
      } else {
        const adInfo = adMap[username];
        empEntry(adInfo ? adInfo.empresa : 'Sin empresa').sin_grupo.push(persona);
      }
    }
    const empresasArr = Object.values(empMap).map(emp => ({
      nombre: emp.nombre,
      sin_grupo: emp.sin_grupo.sort((a,b) => a.nombre.localeCompare(b.nombre)),
      grupos: Object.values(emp.grupos).map(g => ({ ...g, personas: g.personas.sort((a,b) => a.nombre.localeCompare(b.nombre)) })).sort((a,b) => a.nombre.localeCompare(b.nombre))
    })).sort((a,b) => a.nombre.localeCompare(b.nombre));
    const areasArr = Object.values(areasMap).sort((a,b) => a.nombre.localeCompare(b.nombre));

    res.json({ empresas: empresasArr, areas: areasArr, sin_asignacion: sinAsig });
  } catch (e) {
    console.error('equipos-asignados error:', e.message);
    res.status(500).json({ error: e.message });
  } finally { if (glpi) await glpi.end(); }
});

router.post('/api/devolver', requireLogin, async (req, res) => {
  const nivelInfo = getNivelUsuario(res.locals.cfg, req);
  if (nivelInfo.nivel > 2) return res.status(403).json({ error: 'Sin permiso' });
  const { serie } = req.body;
  if (!serie) return res.status(400).json({ error: 'S/N requerido' });
  const now = new Date();
  await query("UPDATE equipos_asignados SET estado='devuelto',fecha_devolucion=@fd,updated_at=@ua WHERE serie=@s AND estado='activo'", { s: serie, fd: now, ua: now });
  res.json({ ok: true });
});

// Buscar usuarios en GLPI
router.get('/api/buscar-usuario', requireLogin, async (req, res) => {
  const q = (req.query.q || '').trim();
  if (q.length < 2) return res.json([]);
  let glpi = null;
  try {
    glpi = await getConn();
    const like = `%${q}%`;
    const [rows] = await glpi.execute(
      `SELECT id, name, TRIM(CONCAT(COALESCE(firstname,''),' ',COALESCE(realname,''))) AS nombre_completo
       FROM glpi_users
       WHERE is_active=1 AND (name LIKE ? OR firstname LIKE ? OR realname LIKE ?)
       ORDER BY realname, firstname LIMIT 10`,
      [like, like, like]
    );
    res.json(rows.map(r => ({ id: r.id, username: r.name, nombre: r.nombre_completo.trim() || r.name })));
  } catch(e) {
    res.status(500).json({ error: e.message });
  } finally { if (glpi) await glpi.end(); }
});

// Asignar usuario a equipo en GLPI
router.post('/api/asignar', requireLogin, async (req, res) => {
  const nivelInfo = getNivelUsuario(res.locals.cfg, req);
  if (nivelInfo.nivel > 2) return res.status(403).json({ error: 'Sin permiso' });
  const { glpi_tabla, glpi_id, users_id } = req.body;
  const TABLAS_OK = ['glpi_computers', 'glpi_monitors', 'glpi_printers', 'glpi_phones'];
  if (!TABLAS_OK.includes(glpi_tabla) || !glpi_id || !users_id)
    return res.status(400).json({ error: 'Datos incompletos' });
  let glpi = null;
  try {
    glpi = await getConn();
    await glpi.execute(`UPDATE ${glpi_tabla} SET users_id=?, locations_id=0 WHERE id=?`, [users_id, glpi_id]);
    res.json({ ok: true });
  } catch(e) {
    res.status(500).json({ error: e.message });
  } finally { if (glpi) await glpi.end(); }
});

// GET /api/por-asignar — equipos nuevos en GLPI sin acta
router.get('/api/por-asignar', requireLogin, async (req, res) => {
  const nivelInfo = getNivelUsuario(res.locals.cfg, req);
  if (nivelInfo.nivel > 2) return res.status(403).json({ error: 'Sin permiso' });

  // Fecha de corte: config SQL Server clave equipos_corte_fecha, default 2026-05-18
  let fechaCorte = '2026-05-18';
  try {
    const { recordset } = await query("SELECT valor FROM configuracion WHERE clave='equipos_corte_fecha'");
    if (recordset.length && recordset[0].valor) fechaCorte = recordset[0].valor;
  } catch {}

  // S/N que ya tienen acta activa
  const activos = new Set();
  try {
    const { recordset } = await query("SELECT serie FROM equipos_asignados WHERE estado='activo' AND serie IS NOT NULL AND serie <> '---'");
    for (const r of recordset) activos.add((r.serie || '').trim().toLowerCase());
  } catch {}

  let glpi = null;
  const equipos = [];
  try {
    glpi = await getConn();
    for (const { tabla, categoria, tipo_tabla, tipo_fk } of TABLAS_GLPI) {
      const [rows] = await glpi.execute(
        `SELECT t.id, t.name, t.serial, t.date_creation, t.users_id,
                s.name AS estado_glpi, mf.name AS fabricante,
                tp.name AS tipo_real,
                TRIM(CONCAT(COALESCE(u.firstname,''),' ',COALESCE(u.realname,''))) AS asignado_nombre,
                u.name AS asignado_user
         FROM ${tabla} t
         LEFT JOIN glpi_states s ON s.id=t.states_id
         LEFT JOIN glpi_manufacturers mf ON mf.id=t.manufacturers_id
         LEFT JOIN ${tipo_tabla} tp ON tp.id=t.${tipo_fk}
         LEFT JOIN glpi_users u ON u.id=t.users_id
         WHERE t.is_deleted=0 AND t.is_template=0
           AND t.date_creation >= ?
         ORDER BY t.date_creation DESC`,
        [fechaCorte]
      );
      for (const r of rows) {
        const sn = (r.serial || '').trim();
        if (sn && activos.has(sn.toLowerCase())) continue; // ya tiene acta
        equipos.push({
          glpi_id: r.id,
          glpi_tabla: tabla,
          nombre: r.name || '',
          serie: sn || '---',
          categoria,
          tipo: r.tipo_real || null,
          fabricante: r.fabricante || '',
          estado_glpi: r.estado_glpi || '---',
          fecha_glpi: r.date_creation,
          asignado_nombre: r.users_id ? (r.asignado_nombre.trim() || r.asignado_user || '?') : null,
          asignado_user: r.asignado_user || null,
        });
      }
    }
    res.json({ equipos, fecha_corte: fechaCorte });
  } catch (e) {
    res.status(500).json({ error: e.message });
  } finally { if (glpi) await glpi.end(); }
});

module.exports = router;
