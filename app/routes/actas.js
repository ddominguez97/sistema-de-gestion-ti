const express = require('express');
const router = express.Router();
const mysql = require('mysql2/promise');
const { requireLogin, checkModulo, getNivelUsuario, getPermisosUsuario } = require('../middleware/auth');
const { loadConfig, DATA_DIR } = require('../config/config');
const { query, sql } = require('../config/database');

async function getConn() {
  const cfg = loadConfig();
  return mysql.createConnection({
    host: cfg.db_host, port: parseInt(cfg.db_port) || 3306,
    database: cfg.db_name, user: cfg.db_user, password: cfg.db_pass,
    charset: 'utf8mb4',
  });
}

// GET /actas — vista
router.get('/', requireLogin, (req, res) => {
  const cfg = res.locals.cfg;
  const blocked = checkModulo(cfg, 'actas', req);
  if (blocked) return res.render('proximamente', { titulo: 'Actas de Equipos' });
  const nivelInfo = getNivelUsuario(cfg, req);
  const pc = cfg.permisos_config || {};
  let puedeCrearEntrega = nivelInfo.nivel <= 2;
  if (nivelInfo.nivel === 3 && nivelInfo.grupo_id) {
    const grupo = (pc.grupos || {})[nivelInfo.grupo_id];
    if (grupo && grupo.permisos && grupo.permisos.crear_entrega) puedeCrearEntrega = true;
  }
  res.render('actas', { nivelInfo, puedeCrearEntrega });
});

// GET /actas/api/buscar — buscar equipo en GLPI
router.get('/api/buscar', requireLogin, async (req, res) => {
  const q = (req.query.q || '').trim();
  if (q.length < 2) return res.json([]);
  let conn;
  try {
    conn = await getConn();
    const results = [];
    const tipos = [
      { tabla:'glpi_computers', tipo:'Computadora', hasUser:true },
      { tabla:'glpi_monitors', tipo:'Monitor', hasUser:true },
      { tabla:'glpi_printers', tipo:'Impresora', hasUser:true },
      { tabla:'glpi_peripherals', tipo:'Periferico', hasUser:true },
      { tabla:'glpi_networkequipments', tipo:'Disp. de Red', hasUser:true },
      { tabla:'glpi_phones', tipo:'Telefono', hasUser:true },
      { tabla:'glpi_enclosures', tipo:'Gabinete', hasUser:false },
    ];
    for (const t of tipos) {
      const [rows] = await conn.execute(
        `SELECT id,name,serial,manufacturers_id,users_id,states_id,locations_id FROM ${t.tabla} WHERE name LIKE ? AND is_deleted=0 AND is_template=0 LIMIT 10`,
        ['%'+q+'%']
      );
      for (const r of rows) {
        const fab = r.manufacturers_id ? ((await conn.execute('SELECT name FROM glpi_manufacturers WHERE id=? LIMIT 1',[r.manufacturers_id]))[0][0]?.name || '') : '';
        const estado = r.states_id ? ((await conn.execute('SELECT name FROM glpi_states WHERE id=? LIMIT 1',[r.states_id]))[0][0]?.name || '') : '';
        const ubicacion = r.locations_id ? ((await conn.execute('SELECT name FROM glpi_locations WHERE id=? LIMIT 1',[r.locations_id]))[0][0]?.name || '') : '';
        const usuario = (t.hasUser && r.users_id) ? ((await conn.execute("SELECT CONCAT(firstname,' ',realname) n FROM glpi_users WHERE id=? LIMIT 1",[r.users_id]))[0][0]?.n?.trim() || '') : '---';
        results.push({ id:r.id, nombre:r.name, serie:r.serial||'---', fabricante:fab, modelo:'', usuario, estado, estado_id:r.states_id, ubicacion, ubicacion_id:r.locations_id, tipo:t.tipo, tabla:t.tabla });
      }
    }
    const tiposSimples = [
      { tabla:'glpi_consumableitems', tipo:'Consumible', type_col:'consumableitemtypes_id', type_table:'glpi_consumableitemtypes', stock_table:'glpi_consumables', stock_fk:'consumableitems_id', itemtype:'ConsumableItem' },
      { tabla:'glpi_cartridgeitems', tipo:'Cartucho', type_col:'cartridgeitemtypes_id', type_table:'glpi_cartridgeitemtypes', stock_table:'glpi_cartridges', stock_fk:'cartridgeitems_id', itemtype:'CartridgeItem' },
    ];
    for (const t of tiposSimples) {
      const [rows] = await conn.execute(
        `SELECT c.id, c.name, c.manufacturers_id, c.${t.type_col},
                g.name AS grupo_nombre,
                (SELECT COUNT(*) FROM ${t.stock_table} s WHERE s.${t.stock_fk}=c.id AND s.date_out IS NULL) AS stock_disponible
         FROM ${t.tabla} c LEFT JOIN glpi_groups_items gi ON gi.items_id=c.id AND gi.itemtype=? AND gi.type=1
         LEFT JOIN glpi_groups g ON g.id=gi.groups_id WHERE c.name LIKE ? AND c.is_deleted=0 LIMIT 10`,
        [t.itemtype, '%'+q+'%']
      );
      for (const r of rows) {
        const fab = r.manufacturers_id ? ((await conn.execute('SELECT name FROM glpi_manufacturers WHERE id=? LIMIT 1',[r.manufacturers_id]))[0][0]?.name || '') : '';
        const tipoNombre = r[t.type_col] ? ((await conn.execute(`SELECT name FROM ${t.type_table} WHERE id=? LIMIT 1`,[r[t.type_col]]))[0][0]?.name || '---') : '---';
        results.push({ id:r.id, nombre:r.name, serie:'---', fabricante:fab, modelo:tipoNombre, usuario:r.grupo_nombre ? 'Grupo: '+r.grupo_nombre : '---', estado:'---', tipo:t.tipo, tabla:t.tabla, stock: parseInt(r.stock_disponible) || 0 });
      }
    }
    res.json(results);
  } catch (e) { res.status(500).json({ error: e.message }); }
  finally { if (conn) await conn.end(); }
});

// GET /actas/api/usuarios
router.get('/api/usuarios', requireLogin, async (req, res) => {
  const q = (req.query.q || '').trim();
  if (q.length < 2) return res.json([]);
  let conn;
  try {
    conn = await getConn();
    const [rows] = await conn.execute(
      "SELECT id, name, CONCAT(firstname,' ',realname) AS fullname FROM glpi_users WHERE (name LIKE ? OR firstname LIKE ? OR realname LIKE ?) AND is_deleted=0 AND is_active=1 LIMIT 10",
      ['%'+q+'%','%'+q+'%','%'+q+'%']
    );
    res.json(rows.map(r => ({ id:r.id, name:r.name, fullname:r.fullname?.trim() || r.name })));
  } catch (e) { res.status(500).json([]); }
  finally { if (conn) await conn.end(); }
});

// GET /actas/api/estados
router.get('/api/estados', requireLogin, async (req, res) => {
  let conn;
  try { conn = await getConn(); const [rows] = await conn.execute('SELECT id, name FROM glpi_states ORDER BY name'); res.json(rows); }
  catch (e) { res.json([]); } finally { if (conn) await conn.end(); }
});

// GET /actas/api/ubicaciones
router.get('/api/ubicaciones', requireLogin, async (req, res) => {
  let conn;
  try { conn = await getConn(); const [rows] = await conn.execute('SELECT id, name, completename FROM glpi_locations ORDER BY completename'); res.json(rows); }
  catch (e) { res.json([]); } finally { if (conn) await conn.end(); }
});

// POST /actas/api/guardar
router.post('/api/guardar', requireLogin, async (req, res) => {
  const input = req.body;
  if (!input) return res.status(400).json({ error: 'Datos invalidos' });
  const now = new Date();
  const result = await query(
    `INSERT INTO actas (numero,tipo,fecha,lugar,destino,entregado_por,entregado_cargo,entregado_username,
     recibido_por,recibido_cargo,recibido_username,autorizado_por,autorizado_cargo,motivo,
     retira_persona,retira_cargo,retira_username,observaciones,total_equipos,estado,created_by,created_at,updated_at)
     OUTPUT INSERTED.id, INSERTED.numero
     VALUES (@numero,@tipo,@fecha,@lugar,@destino,@ep,@ec,@eu,@rp,@rc,@ru,@ap,@ac,@motivo,
     @ret,@retc,@retu,@obs,@total,'pendiente',@cb,@ca,@ua)`, {
      numero: input.numero || ('ACT-' + Date.now().toString().slice(-6)),
      tipo: input.tipo || 'entrega', fecha: input.fecha || now.toISOString().slice(0,10),
      lugar: input.lugar || null, destino: input.destino || null,
      ep: input.entregado_por || null, ec: input.entregado_cargo || null, eu: input.entregado_username || null,
      rp: input.recibido_por || null, rc: input.recibido_cargo || null, ru: input.recibido_username || null,
      ap: input.autorizado_por || null, ac: input.autorizado_cargo || null, motivo: input.motivo || null,
      ret: input.retira_persona || null, retc: input.retira_cargo || null, retu: input.retira_username || null,
      obs: input.observaciones || null, total: (input.equipos || []).length,
      cb: req.session.nagsa_user, ca: now, ua: now,
    });
  const actaId = result.recordset[0].id;
  const numero = result.recordset[0].numero;
  for (const eq of (input.equipos || [])) {
    await query('INSERT INTO acta_equipos (acta_id,nombre,tipo,fabricante,modelo,serie,estado,stock) VALUES (@aid,@n,@t,@f,@m,@s,@e,@st)',
      { aid: actaId, n: eq.nombre||'', t: eq.tipo||'', f: eq.fabricante||'', m: eq.modelo||'', s: eq.serie||'', e: eq.estado||'', st: eq.stock||0 });
  }
  res.json({ ok: true, id: actaId, numero });
});

// GET /actas/api/listar
router.get('/api/listar', requireLogin, async (req, res) => {
  let where = 'WHERE 1=1';
  const params = {};
  if (req.query.tipo) { where += ' AND tipo=@tipo'; params.tipo = req.query.tipo; }
  if (req.query.estado) { where += ' AND estado=@estado'; params.estado = req.query.estado; }
  if (req.query.desde) { where += ' AND fecha>=@desde'; params.desde = req.query.desde; }
  if (req.query.hasta) { where += ' AND fecha<=@hasta'; params.hasta = req.query.hasta; }
  if (req.query.buscar) {
    where += ' AND (numero LIKE @q OR entregado_por LIKE @q OR recibido_por LIKE @q OR autorizado_por LIKE @q OR created_by LIKE @q)';
    params.q = '%' + req.query.buscar + '%';
  }
  const { recordset } = await query(`SELECT id,numero,tipo,fecha,lugar,destino,entregado_por,recibido_por,autorizado_por,retira_persona,total_equipos,estado,created_by,created_at FROM actas ${where} ORDER BY created_at DESC`, params);
  res.json(recordset);
});

// GET /actas/api/detalle
router.get('/api/detalle', requireLogin, async (req, res) => {
  const id = parseInt(req.query.id);
  if (!id) return res.status(400).json({ error: 'ID requerido' });
  const { recordset } = await query('SELECT * FROM actas WHERE id=@id', { id });
  if (!recordset.length) return res.status(404).json({ error: 'Acta no encontrada' });
  const acta = recordset[0];
  const { recordset: equipos } = await query('SELECT nombre,tipo,fabricante,modelo,serie,estado,stock FROM acta_equipos WHERE acta_id=@id', { id });
  acta.equipos = equipos;
  res.json(acta);
});

// POST /actas/api/resguardo
router.post('/api/resguardo', requireLogin, async (req, res) => {
  const input = req.body;
  if (!input || !input.id || !input.estado) return res.status(400).json({ error: 'Datos incompletos' });
  if (!['aceptada','rechazada'].includes(input.estado)) return res.status(400).json({ error: 'Estado no valido' });
  const now = new Date();
  const result = await query(
    `UPDATE actas SET estado=@estado, aceptada_por=@por, aceptada_fecha=@fecha, aceptada_observaciones=@obs, firma_digital=@firma, updated_at=@ua
     WHERE id=@id AND estado='pendiente'`,
    { id: parseInt(input.id), estado: input.estado, por: input.aceptada_por || req.session.nagsa_user, fecha: now, obs: input.observaciones || null, firma: input.firma || null, ua: now });
  if (result.rowsAffected[0] === 0) return res.status(409).json({ error: 'El acta ya fue procesada o no existe' });
  res.json({ ok: true });
});

// GET /actas/api/mis-actas
router.get('/api/mis-actas', requireLogin, async (req, res) => {
  const user = (req.session.nagsa_user || '').toLowerCase();
  const { recordset } = await query(
    `SELECT id,numero,tipo,fecha,lugar,destino,entregado_por,recibido_por,autorizado_por,retira_persona,total_equipos,estado,created_by,created_at
     FROM actas WHERE LOWER(recibido_username)=@user OR LOWER(retira_username)=@user OR LOWER(entregado_username)=@user ORDER BY created_at DESC`, { user });
  res.json(recordset);
});

// GET /actas/api/pendientes
router.get('/api/pendientes', requireLogin, async (req, res) => {
  const user = (req.session.nagsa_user || '').toLowerCase();
  const { recordset } = await query(
    `SELECT id,numero,tipo,fecha,total_equipos FROM actas WHERE estado='pendiente' AND (LOWER(recibido_username)=@user OR LOWER(retira_username)=@user)`, { user });
  res.json({ count: recordset.length, actas: recordset });
});

// POST /actas/api/recordatorio
router.post('/api/recordatorio', requireLogin, async (req, res) => {
  const id = parseInt(req.body.id);
  if (!id) return res.status(400).json({ error: 'ID requerido' });
  const { recordset } = await query('SELECT id FROM actas WHERE id=@id AND (estado=\'pendiente\' OR estado=\'pendiente_autorizacion\')', { id });
  if (!recordset.length) return res.status(404).json({ error: 'Acta no encontrada o ya procesada' });
  await query('INSERT INTO acta_recordatorios (acta_id,enviado_por) VALUES (@id,@por)', { id, por: req.session.nagsa_user });
  res.json({ ok: true });
});

// GET /actas/api/estadisticas
router.get('/api/estadisticas', requireLogin, async (req, res) => {
  const { recordset: porTipo } = await query('SELECT tipo, COUNT(*) as total FROM actas GROUP BY tipo');
  const { recordset: porEstado } = await query('SELECT estado, COUNT(*) as total FROM actas GROUP BY estado');
  const { recordset: general } = await query('SELECT COUNT(*) as total, SUM(total_equipos) as equipos FROM actas');
  const { recordset: porMes } = await query(
    `SELECT FORMAT(fecha,'yyyy-MM') as mes, tipo, COUNT(*) as total FROM actas WHERE fecha >= DATEADD(MONTH,-6,GETDATE()) GROUP BY FORMAT(fecha,'yyyy-MM'), tipo ORDER BY mes`);
  const { recordset: topUsuarios } = await query('SELECT TOP 5 created_by, COUNT(*) as total FROM actas GROUP BY created_by ORDER BY total DESC');
  res.json({
    por_tipo: porTipo, por_estado: porEstado,
    por_mes: porMes, general: general[0] || { total: 0, equipos: 0 },
    top_usuarios: topUsuarios,
  });
});

// GET /actas/api/mis-pendientes
router.get('/api/mis-pendientes', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  const user = (req.session.nagsa_user || '').toLowerCase();
  const usernames = [user];
  if (nivelInfo.nivel === 3 && nivelInfo.grupo_id) {
    const pc = cfg.permisos_config || {};
    const grupo = (pc.grupos || {})[nivelInfo.grupo_id];
    if (grupo) for (const m of (grupo.miembros || [])) usernames.push((typeof m === 'string' ? m : m.username).toLowerCase());
  }
  // Construir IN clause
  const inParams = {};
  const inParts = usernames.map((u, i) => { inParams['u' + i] = u; return '@u' + i; });
  const inClause = inParts.join(',');
  const { recordset } = await query(
    `SELECT id,numero,tipo,fecha,recibido_por,retira_persona,total_equipos,estado,created_by,created_at
     FROM actas WHERE estado IN ('pendiente','pendiente_autorizacion')
     AND (LOWER(recibido_username) IN (${inClause}) OR LOWER(retira_username) IN (${inClause}) OR LOWER(created_by) IN (${inClause}))
     ORDER BY created_at DESC`, inParams);
  res.json(recordset);
});

// GET /actas/api/proveedores
router.get('/api/proveedores', requireLogin, async (req, res) => {
  let conn;
  try { conn = await getConn(); const [rows] = await conn.execute('SELECT id, name FROM glpi_suppliers WHERE is_deleted=0 ORDER BY name'); res.json(rows); }
  catch (e) { res.json([]); } finally { if (conn) await conn.end(); }
});

// GET /actas/api/mis-equipos
router.get('/api/mis-equipos', requireLogin, async (req, res) => {
  const username = (req.session.nagsa_user || '').toLowerCase();
  let conn;
  try {
    conn = await getConn();
    const [userRows] = await conn.execute('SELECT id FROM glpi_users WHERE LOWER(name)=? AND is_deleted=0 LIMIT 1', [username]);
    if (!userRows.length) return res.json([]);
    const userId = userRows[0].id;
    const results = [];
    const tipos = [
      { tabla:'glpi_computers', tipo:'Computadora' },
      { tabla:'glpi_monitors', tipo:'Monitor' },
      { tabla:'glpi_printers', tipo:'Impresora' },
      { tabla:'glpi_networkequipments', tipo:'Disp. de Red' },
    ];
    for (const t of tipos) {
      const [rows] = await conn.execute(`SELECT a.id, a.name, a.serial, a.manufacturers_id, a.states_id FROM ${t.tabla} a WHERE a.users_id=? AND a.is_deleted=0 AND a.is_template=0`, [userId]);
      for (const r of rows) {
        const fab = r.manufacturers_id ? ((await conn.execute('SELECT name FROM glpi_manufacturers WHERE id=? LIMIT 1',[r.manufacturers_id]))[0][0]?.name || '') : '';
        const estado = r.states_id ? ((await conn.execute('SELECT name FROM glpi_states WHERE id=? LIMIT 1',[r.states_id]))[0][0]?.name || '') : '';
        results.push({ id: r.id, nombre: r.name, serie: r.serial || '---', fabricante: fab, tipo: t.tipo, estado, tabla: t.tabla });
      }
    }
    const [consumibles] = await conn.execute(
      `SELECT c.id, ci.name, ci.manufacturers_id FROM glpi_consumables c JOIN glpi_consumableitems ci ON ci.id = c.consumableitems_id WHERE c.items_id = ? AND c.itemtype = 'User' AND c.date_out IS NOT NULL`, [userId]);
    for (const r of consumibles) {
      const fab = r.manufacturers_id ? ((await conn.execute('SELECT name FROM glpi_manufacturers WHERE id=? LIMIT 1',[r.manufacturers_id]))[0][0]?.name || '') : '';
      results.push({ id: r.id, nombre: r.name, serie: '---', fabricante: fab, tipo: 'Consumible', estado: 'Asignado', tabla: 'glpi_consumables' });
    }
    res.json(results);
  } catch (e) { res.status(500).json({ error: e.message }); }
  finally { if (conn) await conn.end(); }
});

// POST /actas/api/solicitar-salida
router.post('/api/solicitar-salida', requireLogin, async (req, res) => {
  const input = req.body;
  if (!input || !input.equipos || !input.equipos.length) return res.status(400).json({ error: 'Debe seleccionar al menos un equipo' });
  const now = new Date();
  const numero = 'SOL-' + Date.now().toString().slice(-6);
  const result = await query(
    `INSERT INTO actas (numero,tipo,fecha,motivo,retira_persona,retira_cargo,retira_username,observaciones,total_equipos,estado,created_by,created_at,updated_at)
     OUTPUT INSERTED.id, INSERTED.numero
     VALUES (@numero,'salida',@fecha,@motivo,@ret,@retc,@retu,@obs,@total,'pendiente_autorizacion',@cb,@ca,@ua)`, {
      numero, fecha: now.toISOString().slice(0,10), motivo: input.motivo || null,
      ret: req.session.nagsa_name || req.session.nagsa_user, retc: input.cargo || null,
      retu: req.session.nagsa_user, obs: input.observaciones || null, total: input.equipos.length,
      cb: req.session.nagsa_user, ca: now, ua: now,
    });
  const actaId = result.recordset[0].id;
  for (const eq of input.equipos) {
    await query('INSERT INTO acta_equipos (acta_id,nombre,tipo,fabricante,modelo,serie,estado) VALUES (@aid,@n,@t,@f,@m,@s,@e)',
      { aid: actaId, n: eq.nombre||'', t: eq.tipo||'', f: eq.fabricante||'', m: eq.modelo||'', s: eq.serie||'', e: eq.estado||'' });
  }
  res.json({ ok: true, id: actaId, numero: result.recordset[0].numero });
});

// GET /actas/api/solicitudes-pendientes
router.get('/api/solicitudes-pendientes', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  if (getNivelUsuario(cfg, req).nivel > 2) return res.status(403).json({ count: 0, actas: [] });
  const { recordset } = await query(
    `SELECT id,numero,tipo,fecha,retira_persona,retira_username,motivo,destino,total_equipos,created_by,created_at
     FROM actas WHERE estado='pendiente_autorizacion' OR (tipo='salida' AND estado='pendiente') ORDER BY created_at DESC`);
  res.json({ count: recordset.length, actas: recordset });
});

// POST /actas/api/autorizar-solicitud
router.post('/api/autorizar-solicitud', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  if (getNivelUsuario(cfg, req).nivel > 2) return res.status(403).json({ error: 'Solo TI puede autorizar' });
  const { id, accion, observaciones, destino } = req.body;
  if (!id || !['autorizada','rechazada'].includes(accion)) return res.status(400).json({ error: 'Datos incompletos' });
  const now = new Date();
  const pc = cfg.permisos_config || {};
  const nombreArea = pc.ti_nombre_area || 'TI';
  const result = await query(
    `UPDATE actas SET estado=@estado, autorizado_por=@ap, autorizado_cargo=@ac, aceptada_por=@por, aceptada_fecha=@fecha,
     aceptada_observaciones=@obs, destino=COALESCE(@destino,destino), updated_at=@ua
     WHERE id=@id AND (estado='pendiente_autorizacion' OR (tipo='salida' AND estado='pendiente'))`,
    { id: parseInt(id), estado: accion, ap: nombreArea, ac: req.session.nagsa_name || req.session.nagsa_user,
      por: req.session.nagsa_name || req.session.nagsa_user, fecha: now, obs: observaciones || null, destino: destino || null, ua: now });
  if (result.rowsAffected[0] === 0) return res.status(409).json({ error: 'Solicitud ya procesada o no existe' });
  res.json({ ok: true });
});

module.exports = router;
