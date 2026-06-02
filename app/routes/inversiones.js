const express = require('express');
const router = express.Router();
const multer = require('multer');
const path = require('path');
const fs = require('fs');
const mysql = require('mysql2/promise');
const { query } = require('../config/database');
const { requireLogin, getNivelUsuario } = require('../middleware/auth');
const { loadConfig, ROOT_PATH } = require('../config/config');

async function glpiConn() {
  const cfg = loadConfig();
  return mysql.createConnection({
    host: cfg.db_host, port: parseInt(cfg.db_port) || 3306,
    database: cfg.db_name, user: cfg.db_user, password: cfg.db_pass,
    charset: 'utf8mb4', connectTimeout: 5000,
  });
}

// Setup multer
const uploadDir = path.join(ROOT_PATH, 'data', 'uploads', 'inversiones');
if (!fs.existsSync(uploadDir)) fs.mkdirSync(uploadDir, { recursive: true });

const storage = multer.diskStorage({
  destination: (req, file, cb) => cb(null, uploadDir),
  filename: (req, file, cb) => {
    const ext = path.extname(file.originalname).toLowerCase();
    cb(null, `inv-${Date.now()}-${Math.random().toString(36).substr(2, 6)}${ext}`);
  },
});
const upload = multer({
  storage,
  limits: { fileSize: 10 * 1024 * 1024 },
  fileFilter: (req, file, cb) => {
    const allowed = ['.pdf', '.jpg', '.jpeg', '.png', '.webp'];
    cb(null, allowed.includes(path.extname(file.originalname).toLowerCase()));
  },
});

const NOMBRES_SISTEMA = ['Gerencia', 'Contadora'];

function getRolInversiones(cfg, req) {
  const user = (req.session.nagsa_user || '').toLowerCase();
  const auth = req.session.nagsa_auth;
  const pc = cfg.permisos_config || {};

  // TI (GLPI auth o usuario TI configurado)
  if (auth === 'glpi') return { rol: 'ti', puede_cotizar: true, puede_aprobar_cotizacion: false, puede_aprobar_pago: false, puede_marcar_pagado: false };
  if (pc.ti_usuarios && pc.ti_usuarios[user]) return { rol: 'ti', puede_cotizar: true, puede_aprobar_cotizacion: false, puede_aprobar_pago: false, puede_marcar_pagado: false };

  // Grupos con switches de inversiones (Gerencia / Contadora)
  for (const g of Object.values(pc.grupos || {})) {
    const enGrupo = [...(g.jefes || []), ...(g.miembros || [])].some(u => u.username.toLowerCase() === user);
    if (!enGrupo) continue;
    const inv = g.inversiones || {};
    if (inv.puede_aprobar_cotizacion || inv.puede_aprobar_pago || inv.puede_marcar_pagado) {
      let empresa = null;
      if (inv.puede_marcar_pagado) {
        const mb = (g.miembros || []).find(m => m.username.toLowerCase() === user);
        if (mb) empresa = mb.empresa;
      }
      return {
        rol: inv.puede_marcar_pagado ? 'contadora' : 'gerente',
        puede_cotizar: false,
        puede_aprobar_cotizacion: !!inv.puede_aprobar_cotizacion,
        puede_aprobar_pago: !!inv.puede_aprobar_pago,
        puede_marcar_pagado: !!inv.puede_marcar_pagado,
        empresa,
      };
    }
  }

  // Jefe de grupo de área
  for (const [gid, g] of Object.entries(pc.grupos || {})) {
    if (NOMBRES_SISTEMA.includes(g.nombre)) continue;
    const esJefe = (g.jefes || []).some(u => u.username.toLowerCase() === user);
    if (esJefe) {
      const miembros = (g.miembros || []).map(m => m.username.toLowerCase());
      return { rol: 'jefe', puede_cotizar: false, puede_aprobar_cotizacion: false, puede_aprobar_pago: false, puede_marcar_pagado: false, grupo_id: gid, miembros };
    }
  }

  return { rol: 'solicitante', puede_cotizar: false, puede_aprobar_cotizacion: false, puede_aprobar_pago: false, puede_marcar_pagado: false };
}

const COLS = 'id, empresa, solicitado_por, solicitado_nombre, descripcion, estado, flujo, cotizacion_monto, cotizacion_proveedor, cotizacion_archivo, factura_archivo, recordatorio_ti_at, created_at';

// GET /inversiones
router.get('/', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  const user = (req.session.nagsa_user || '').toLowerCase();
  let data = {};

  try {
    if (rolInfo.rol === 'ti' || rolInfo.rol === 'gerente') {
      const { recordset } = await query(`SELECT ${COLS} FROM inversiones ORDER BY empresa, created_at DESC`);
      data.solicitudes = recordset;
      data.propias = recordset.filter(s => s.solicitado_por.toLowerCase() === user);
    } else if (rolInfo.rol === 'contadora') {
      const empresa = rolInfo.empresa;
      if (empresa) {
        const { recordset } = await query(
          `SELECT ${COLS} FROM inversiones WHERE empresa=@emp ORDER BY created_at DESC`,
          { emp: empresa }
        );
        data.todas = recordset;
      } else {
        data.todas = [];
      }
      const { recordset: propias } = await query(
        `SELECT ${COLS} FROM inversiones WHERE LOWER(solicitado_por)=@user ORDER BY created_at DESC`,
        { user }
      );
      data.propias = propias;
    } else if (rolInfo.rol === 'jefe') {
      const { recordset: todas } = await query(`SELECT ${COLS} FROM inversiones ORDER BY created_at DESC`);
      data.propias = todas.filter(s => s.solicitado_por.toLowerCase() === user);
      const miembros = rolInfo.miembros || [];
      data.equipo = todas.filter(s => miembros.includes(s.solicitado_por.toLowerCase()) && s.solicitado_por.toLowerCase() !== user);
    } else {
      const { recordset } = await query(
        `SELECT ${COLS} FROM inversiones WHERE LOWER(solicitado_por)=@user ORDER BY created_at DESC`,
        { user }
      );
      data.propias = recordset;
    }
  } catch (e) { console.error('Error inversiones index:', e.message); }

  res.render('inversiones/index', { data, rolInfo, nivelInfo: getNivelUsuario(cfg, req) });
});

// GET /inversiones/nueva
router.get('/nueva', requireLogin, (req, res) => {
  const cfg = loadConfig();
  res.render('inversiones/nueva', { nivelInfo: getNivelUsuario(cfg, req), empresa: req.session.nagsa_empresa || '' });
});

// POST /inversiones/nueva
router.post('/nueva', requireLogin, upload.array('solicitud_adjuntos', 5), async (req, res) => {
  let items;
  try {
    items = JSON.parse(req.body.items || '[]');
  } catch { items = []; }
  if (!items.length) {
    (req.files || []).forEach(f => fs.unlinkSync(f.path));
    return res.status(400).json({ error: 'Al menos un ítem requerido' });
  }

  const empresa = req.session.nagsa_empresa || 'Sin empresa';
  const user = req.session.nagsa_user;
  const nombre = req.session.nagsa_name;
  const archivos = (req.files || []).map(f => f.filename);
  const adjunto = archivos.length ? JSON.stringify(archivos) : null;

  const resumen = items.length === 1
    ? items[0].descripcion
    : `${items[0].descripcion} (y ${items.length - 1} más)`;

  let flujo = 'A';
  try {
    const { recordset } = await query('SELECT flujo FROM inversiones_config WHERE empresa=@emp', { emp: empresa });
    if (recordset.length) flujo = recordset[0].flujo;
  } catch {}

  try {
    const result = await query(`
      INSERT INTO inversiones (empresa, solicitado_por, solicitado_nombre, descripcion, justificacion, solicitud_adjunto, flujo, estado)
      OUTPUT INSERTED.id
      VALUES (@empresa, @user, @nombre, @desc, @just, @adjunto, @flujo, 'pendiente_cotizacion')
    `, { empresa, user, nombre, desc: resumen, just: req.body.justificacion || '', adjunto, flujo });

    const invId = result.recordset[0].id;
    for (const item of items) {
      if (!item.descripcion) continue;
      await query(
        'INSERT INTO inversiones_items (inversion_id, descripcion, cantidad) VALUES (@id, @desc, @cant)',
        { id: invId, desc: item.descripcion, cant: parseInt(item.cantidad) || 1 }
      );
    }
    res.json({ ok: true });
  } catch (e) {
    (req.files || []).forEach(f => fs.unlinkSync(f.path));
    res.status(500).json({ error: e.message });
  }
});

// POST /inversiones/:id/cotizar — TI cotiza (Flujo A), sube doc de cotización
router.post('/:id/cotizar', requireLogin, upload.single('cotizacion_archivo'), async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  if (!rolInfo.puede_cotizar) {
    if (req.file) fs.unlinkSync(req.file.path);
    return res.status(403).json({ error: 'Sin permiso' });
  }
  const { monto, proveedor, detalle } = req.body;
  if (!monto || !proveedor) {
    if (req.file) fs.unlinkSync(req.file.path);
    return res.status(400).json({ error: 'Monto y proveedor requeridos' });
  }
  const { recordset } = await query('SELECT id, estado FROM inversiones WHERE id=@id', { id: parseInt(req.params.id) });
  if (!recordset.length || recordset[0].estado !== 'pendiente_cotizacion') {
    if (req.file) fs.unlinkSync(req.file.path);
    return res.status(400).json({ error: 'Estado incorrecto' });
  }
  const archivo = req.file ? req.file.filename : null;
  await query(`
    UPDATE inversiones SET
      cotizacion_monto=@monto, cotizacion_proveedor=@prov, cotizacion_detalle=@det,
      cotizacion_archivo=@archivo, cotizado_por=@user, cotizado_at=GETDATE(),
      estado='pendiente_aprobacion_cotizacion'
    WHERE id=@id
  `, { monto: parseFloat(monto), prov: proveedor, det: detalle || '', archivo, user: req.session.nagsa_user, id: parseInt(req.params.id) });
  res.json({ ok: true });
});

// POST /inversiones/:id/compra-directa — TI compra sin cotización (Flujo B), sube factura
router.post('/:id/compra-directa', requireLogin, upload.single('factura_archivo'), async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  if (!rolInfo.puede_cotizar) {
    if (req.file) fs.unlinkSync(req.file.path);
    return res.status(403).json({ error: 'Sin permiso' });
  }
  const { recordset } = await query('SELECT id, estado FROM inversiones WHERE id=@id', { id: parseInt(req.params.id) });
  if (!recordset.length || recordset[0].estado !== 'pendiente_cotizacion') {
    if (req.file) fs.unlinkSync(req.file.path);
    return res.status(400).json({ error: 'Estado incorrecto' });
  }
  const archivo = req.file ? req.file.filename : null;
  await query(`
    UPDATE inversiones SET
      factura_archivo=@archivo, factura_subida_por=@user, factura_subida_at=GETDATE(),
      flujo='B', estado='pendiente_aprobacion_pago'
    WHERE id=@id
  `, { archivo, user: req.session.nagsa_user, id: parseInt(req.params.id) });
  res.json({ ok: true });
});

// POST /inversiones/:id/aprobar-cotizacion — Gerencia aprueba cotización (Flujo A)
router.post('/:id/aprobar-cotizacion', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  if (!rolInfo.puede_aprobar_cotizacion) return res.status(403).json({ error: 'Sin permiso' });
  const { recordset } = await query('SELECT id, estado FROM inversiones WHERE id=@id', { id: parseInt(req.params.id) });
  if (!recordset.length || recordset[0].estado !== 'pendiente_aprobacion_cotizacion') return res.status(400).json({ error: 'Estado incorrecto' });
  await query(`UPDATE inversiones SET estado='en_compra', aprobado_cotizacion_por=@user, aprobado_cotizacion_at=GETDATE() WHERE id=@id`,
    { user: req.session.nagsa_user, id: parseInt(req.params.id) });
  res.json({ ok: true });
});

// POST /inversiones/:id/marcar-comprado — TI confirma compra + sube factura (Flujo A)
router.post('/:id/marcar-comprado', requireLogin, upload.single('factura_archivo'), async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  if (!rolInfo.puede_cotizar) {
    if (req.file) fs.unlinkSync(req.file.path);
    return res.status(403).json({ error: 'Sin permiso' });
  }
  const { recordset } = await query('SELECT id, estado FROM inversiones WHERE id=@id', { id: parseInt(req.params.id) });
  if (!recordset.length || recordset[0].estado !== 'en_compra') {
    if (req.file) fs.unlinkSync(req.file.path);
    return res.status(400).json({ error: 'Estado incorrecto' });
  }
  const archivo = req.file ? req.file.filename : null;
  await query(`
    UPDATE inversiones SET
      factura_archivo=@archivo, factura_subida_por=@user, factura_subida_at=GETDATE(),
      estado='pendiente_aprobacion_pago'
    WHERE id=@id
  `, { archivo, user: req.session.nagsa_user, id: parseInt(req.params.id) });
  res.json({ ok: true });
});

// POST /inversiones/:id/aprobar — Gerencia aprueba pago
router.post('/:id/aprobar', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  if (!rolInfo.puede_aprobar_pago) return res.status(403).json({ error: 'Sin permiso' });
  const { recordset } = await query('SELECT id, estado FROM inversiones WHERE id=@id', { id: parseInt(req.params.id) });
  if (!recordset.length) return res.status(404).json({ error: 'No encontrada' });
  const estadosValidos = ['pendiente_aprobacion_pago', 'pendiente_aprobacion'];
  if (!estadosValidos.includes(recordset[0].estado)) return res.status(400).json({ error: 'Estado incorrecto' });
  await query(`UPDATE inversiones SET estado='pendiente_pago', aprobado_pago_por=@user, aprobado_pago_at=GETDATE() WHERE id=@id`,
    { user: req.session.nagsa_user, id: parseInt(req.params.id) });
  res.json({ ok: true });
});

// POST /inversiones/:id/marcar-pagado — Contadora
router.post('/:id/marcar-pagado', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  if (!rolInfo.puede_marcar_pagado) return res.status(403).json({ error: 'Sin permiso' });
  const { recordset } = await query('SELECT id, estado, empresa FROM inversiones WHERE id=@id', { id: parseInt(req.params.id) });
  if (!recordset.length || recordset[0].estado !== 'pendiente_pago') return res.status(400).json({ error: 'Estado incorrecto' });
  if (rolInfo.empresa && recordset[0].empresa !== rolInfo.empresa) return res.status(403).json({ error: 'No es tu empresa' });
  await query(`UPDATE inversiones SET estado='pagado', marcado_pagado_por=@user, marcado_pagado_at=GETDATE() WHERE id=@id`,
    { user: req.session.nagsa_user, id: parseInt(req.params.id) });
  res.json({ ok: true });
});

// POST /inversiones/:id/rechazar
router.post('/:id/rechazar', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  if (!['ti', 'gerente'].includes(rolInfo.rol)) return res.status(403).json({ error: 'Sin permiso' });
  const { motivo } = req.body;
  await query(`UPDATE inversiones SET estado='rechazado', rechazado_por=@user, rechazado_at=GETDATE(), motivo_rechazo=@motivo WHERE id=@id`,
    { user: req.session.nagsa_user, motivo: motivo || '', id: parseInt(req.params.id) });
  res.json({ ok: true });
});

// GET /inversiones/glpi-consumibles?q= — Buscar consumibles en GLPI (solo TI)
router.get('/glpi-consumibles', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  if (!rolInfo.puede_cotizar) return res.status(403).json({ error: 'Sin permiso' });

  const q = (req.query.q || '').trim();
  if (q.length < 2) return res.json([]);

  let conn;
  try {
    conn = await glpiConn();
    const [rows] = await conn.execute(`
      SELECT ci.id, ci.name, ci.ref,
        l.name AS ubicacion,
        g.name AS grupo,
        COUNT(c.id) AS total,
        SUM(CASE WHEN c.id IS NOT NULL AND c.date_out IS NULL THEN 1 ELSE 0 END) AS disponibles
      FROM glpi_consumableitems ci
      LEFT JOIN glpi_consumables c ON c.consumableitems_id = ci.id
      LEFT JOIN glpi_locations l ON l.id = ci.locations_id
      LEFT JOIN glpi_groups_items gi ON gi.itemtype = 'ConsumableItem' AND gi.items_id = ci.id AND gi.type = 1
      LEFT JOIN glpi_groups g ON g.id = gi.groups_id
      WHERE ci.is_deleted = 0 AND ci.name LIKE ?
      GROUP BY ci.id, ci.name, ci.ref, l.name, g.name
      HAVING SUM(CASE WHEN c.id IS NOT NULL AND c.date_out IS NULL THEN 1 ELSE 0 END) > 0
      ORDER BY ci.name
      LIMIT 20
    `, [`%${q}%`]);
    res.json(rows);
  } catch (e) {
    res.status(500).json({ error: e.message });
  } finally {
    if (conn) await conn.end();
  }
});

// POST /inversiones/:id/entregar-stock — TI entrega desde stock y asigna en GLPI
router.post('/:id/entregar-stock', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  if (!rolInfo.puede_cotizar) return res.status(403).json({ error: 'Sin permiso' });

  const { consumable_id, consumable_nombre } = req.body;
  if (!consumable_id) return res.status(400).json({ error: 'Consumible requerido' });

  const { recordset } = await query('SELECT id, estado, solicitado_por FROM inversiones WHERE id=@id', { id: parseInt(req.params.id) });
  if (!recordset.length || recordset[0].estado !== 'pendiente_cotizacion') {
    return res.status(400).json({ error: 'Estado incorrecto' });
  }

  const solicitante = recordset[0].solicitado_por;
  let conn;
  try {
    conn = await glpiConn();

    // Buscar ID del usuario en GLPI por username
    const [userRows] = await conn.execute(
      'SELECT id FROM glpi_users WHERE name = ? AND is_deleted = 0 AND is_active = 1 LIMIT 1',
      [solicitante]
    );
    if (!userRows.length) {
      await conn.end();
      return res.status(400).json({ error: `Usuario "${solicitante}" no encontrado en GLPI` });
    }
    const glpiUserId = userRows[0].id;

    // Tomar una unidad disponible del consumible
    const [unitRows] = await conn.execute(
      'SELECT id FROM glpi_consumables WHERE consumableitems_id = ? AND date_out IS NULL LIMIT 1',
      [parseInt(consumable_id)]
    );
    if (!unitRows.length) {
      await conn.end();
      return res.status(400).json({ error: 'Sin stock disponible en GLPI' });
    }
    const unitId = unitRows[0].id;

    // Asignar al usuario en GLPI
    await conn.execute(
      'UPDATE glpi_consumables SET date_out = NOW(), itemtype = "User", items_id = ? WHERE id = ?',
      [glpiUserId, unitId]
    );
    await conn.end();

    // Cerrar la solicitud como entregada
    await query(`
      UPDATE inversiones SET
        estado = 'entregado',
        entregado_desde_stock = 1,
        glpi_consumible_id = @cid,
        glpi_consumible_nombre = @cnombre,
        entregado_por = @user,
        entregado_at = GETDATE()
      WHERE id = @id
    `, {
      cid: parseInt(consumable_id),
      cnombre: consumable_nombre || '',
      user: req.session.nagsa_user,
      id: parseInt(req.params.id),
    });

    res.json({ ok: true });
  } catch (e) {
    if (conn) try { await conn.end(); } catch {}
    res.status(500).json({ error: e.message });
  }
});

// POST /inversiones/:id/notificar-ti — Jefe envía recordatorio a TI
router.post('/:id/notificar-ti', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  if (rolInfo.rol !== 'jefe') return res.status(403).json({ error: 'Solo jefes' });
  await query(`UPDATE inversiones SET recordatorio_ti_at=GETDATE(), recordatorio_ti_por=@user WHERE id=@id`,
    { user: req.session.nagsa_user, id: parseInt(req.params.id) });
  res.json({ ok: true });
});

// GET /inversiones/:id
router.get('/:id', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const { recordset } = await query('SELECT * FROM inversiones WHERE id=@id', { id: parseInt(req.params.id) });
  if (!recordset.length) return res.redirect('/inversiones');
  let items = [];
  try {
    const { recordset: rs } = await query('SELECT descripcion, cantidad FROM inversiones_items WHERE inversion_id=@id ORDER BY id', { id: parseInt(req.params.id) });
    items = rs;
  } catch {}
  res.render('inversiones/detalle', {
    inv: recordset[0],
    items,
    rolInfo: getRolInversiones(cfg, req),
    nivelInfo: getNivelUsuario(cfg, req),
  });
});

// ── CATÁLOGO DE ARTÍCULOS ──────────────────────────────────────────────────

router.get('/api/catalogo', requireLogin, async (req, res) => {
  try {
    const { recordset } = await query('SELECT id, nombre, categoria, activo, orden FROM catalogo_articulos ORDER BY categoria, orden, nombre');
    res.json(recordset);
  } catch(e) { res.status(500).json({ error: e.message }); }
});

router.post('/api/catalogo', requireLogin, async (req, res) => {
  const nivelInfo = getNivelUsuario(res.locals.cfg, req);
  if (nivelInfo.nivel > 2) return res.status(403).json({ error: 'Sin permiso' });
  const { nombre, categoria } = req.body;
  if (!nombre || !categoria) return res.status(400).json({ error: 'Nombre y categoría requeridos' });
  try {
    await query('INSERT INTO catalogo_articulos (nombre, categoria) VALUES (@n, @c)', { n: nombre.trim(), c: categoria.trim() });
    res.json({ ok: true });
  } catch(e) { res.status(500).json({ error: e.message }); }
});

router.put('/api/catalogo/:id', requireLogin, async (req, res) => {
  const nivelInfo = getNivelUsuario(res.locals.cfg, req);
  if (nivelInfo.nivel > 2) return res.status(403).json({ error: 'Sin permiso' });
  const { nombre, categoria } = req.body;
  if (!nombre || !categoria) return res.status(400).json({ error: 'Nombre y categoría requeridos' });
  try {
    await query('UPDATE catalogo_articulos SET nombre=@n, categoria=@c WHERE id=@id', { n: nombre.trim(), c: categoria.trim(), id: parseInt(req.params.id) });
    res.json({ ok: true });
  } catch(e) { res.status(500).json({ error: e.message }); }
});

router.post('/api/catalogo/:id/toggle', requireLogin, async (req, res) => {
  const nivelInfo = getNivelUsuario(res.locals.cfg, req);
  if (nivelInfo.nivel > 2) return res.status(403).json({ error: 'Sin permiso' });
  try {
    await query('UPDATE catalogo_articulos SET activo = CASE WHEN activo=1 THEN 0 ELSE 1 END WHERE id=@id', { id: parseInt(req.params.id) });
    res.json({ ok: true });
  } catch(e) { res.status(500).json({ error: e.message }); }
});

module.exports = router;
