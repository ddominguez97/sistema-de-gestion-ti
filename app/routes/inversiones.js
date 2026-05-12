const express = require('express');
const router = express.Router();
const { query } = require('../config/database');
const { requireLogin, getNivelUsuario, getPermisosUsuario } = require('../middleware/auth');
const { loadConfig } = require('../config/config');

// Determina el rol del usuario en el módulo inversiones
function getRolInversiones(cfg, req) {
  const user = (req.session.nagsa_user || '').toLowerCase();
  const auth = req.session.nagsa_auth;
  const pc = cfg.permisos_config || {};

  // N1/N2 (TI): cotizan y compran
  if (auth === 'glpi') return { rol: 'ti', puede_cotizar: true, puede_aprobar_cotizacion: false, puede_aprobar_pago: false, puede_marcar_pagado: false };
  if (pc.ti_usuarios && pc.ti_usuarios[user]) return { rol: 'ti', puede_cotizar: true, puede_aprobar_cotizacion: false, puede_aprobar_pago: false, puede_marcar_pagado: false };

  // Revisar switches del grupo
  for (const g of Object.values(pc.grupos || {})) {
    const enGrupo = [...(g.jefes || []), ...(g.miembros || [])].some(u => u.username.toLowerCase() === user);
    if (!enGrupo) continue;
    const inv = g.inversiones || {};
    if (inv.puede_aprobar_cotizacion || inv.puede_aprobar_pago || inv.puede_marcar_pagado) {
      return {
        rol: inv.puede_marcar_pagado ? 'contadora' : 'gerente',
        puede_cotizar: false,
        puede_aprobar_cotizacion: !!inv.puede_aprobar_cotizacion,
        puede_aprobar_pago: !!inv.puede_aprobar_pago,
        puede_marcar_pagado: !!inv.puede_marcar_pagado,
      };
    }
  }

  // Usuario normal: solo puede solicitar
  return { rol: 'solicitante', puede_cotizar: false, puede_aprobar_cotizacion: false, puede_aprobar_pago: false, puede_marcar_pagado: false };
}

// GET /inversiones
router.get('/', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  const empresa = req.session.nagsa_empresa || '';

  let solicitudes = [];
  try {
    if (rolInfo.rol === 'ti' || rolInfo.rol === 'gerente' || rolInfo.rol === 'contadora') {
      // TI, gerentes y contadora ven todas
      const { recordset } = await query(`
        SELECT id, empresa, solicitado_por, solicitado_nombre, descripcion, estado, flujo,
               cotizacion_monto, cotizacion_proveedor, created_at
        FROM inversiones ORDER BY created_at DESC
      `);
      solicitudes = recordset;
    } else {
      // Solicitante: solo las suyas
      const { recordset } = await query(`
        SELECT id, empresa, solicitado_por, solicitado_nombre, descripcion, estado, flujo,
               cotizacion_monto, cotizacion_proveedor, created_at
        FROM inversiones WHERE solicitado_por=@user ORDER BY created_at DESC
      `, { user: req.session.nagsa_user });
      solicitudes = recordset;
    }
  } catch (e) { console.error('Error cargando inversiones:', e.message); }

  res.render('inversiones/index', {
    solicitudes,
    rolInfo,
    empresa,
    nivelInfo: getNivelUsuario(cfg, req),
  });
});

// GET /inversiones/nueva
router.get('/nueva', requireLogin, (req, res) => {
  const cfg = loadConfig();
  res.render('inversiones/nueva', {
    nivelInfo: getNivelUsuario(cfg, req),
    empresa: req.session.nagsa_empresa || '',
  });
});

// POST /inversiones/nueva
router.post('/nueva', requireLogin, async (req, res) => {
  const { descripcion, justificacion, observaciones } = req.body;
  if (!descripcion) return res.status(400).json({ error: 'La descripción es requerida' });

  const empresa = req.session.nagsa_empresa || 'Sin empresa';
  const user = req.session.nagsa_user;
  const nombre = req.session.nagsa_name;

  // Determinar flujo según empresa
  let flujo = 'B';
  try {
    const { recordset } = await query('SELECT flujo FROM inversiones_config WHERE empresa=@emp', { emp: empresa });
    if (recordset.length) flujo = recordset[0].flujo;
  } catch {}

  try {
    await query(`
      INSERT INTO inversiones (empresa, solicitado_por, solicitado_nombre, descripcion, justificacion, observaciones, flujo)
      VALUES (@empresa, @user, @nombre, @desc, @just, @obs, @flujo)
    `, { empresa, user, nombre, desc: descripcion, just: justificacion || '', obs: observaciones || '', flujo });
    res.json({ ok: true });
  } catch (e) {
    res.status(500).json({ error: e.message });
  }
});

// POST /inversiones/:id/cotizar
router.post('/:id/cotizar', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  if (!rolInfo.puede_cotizar) return res.status(403).json({ error: 'Sin permiso' });

  const { monto, proveedor, detalle } = req.body;
  if (!monto || !proveedor) return res.status(400).json({ error: 'Monto y proveedor requeridos' });

  const { recordset } = await query('SELECT id, flujo, estado FROM inversiones WHERE id=@id', { id: parseInt(req.params.id) });
  if (!recordset.length) return res.status(404).json({ error: 'No encontrada' });
  const inv = recordset[0];
  if (inv.estado !== 'pendiente_cotizacion') return res.status(400).json({ error: 'Estado incorrecto' });

  // Flujo A: pasa a pendiente_aprobacion_cotizacion; Flujo B: directo a pendiente_aprobacion
  const nuevoEstado = inv.flujo === 'A' ? 'pendiente_aprobacion_cotizacion' : 'pendiente_aprobacion';

  await query(`
    UPDATE inversiones SET
      cotizacion_monto=@monto, cotizacion_proveedor=@prov, cotizacion_detalle=@det,
      cotizado_por=@user, cotizado_at=GETDATE(), estado=@estado
    WHERE id=@id
  `, { monto: parseFloat(monto), prov: proveedor, det: detalle || '', user: req.session.nagsa_user, estado: nuevoEstado, id: parseInt(req.params.id) });

  res.json({ ok: true });
});

// POST /inversiones/:id/aprobar-cotizacion (solo Flujo A, gerente)
router.post('/:id/aprobar-cotizacion', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  if (!rolInfo.puede_aprobar_cotizacion) return res.status(403).json({ error: 'Sin permiso' });

  const { recordset } = await query('SELECT id, estado FROM inversiones WHERE id=@id', { id: parseInt(req.params.id) });
  if (!recordset.length) return res.status(404).json({ error: 'No encontrada' });
  if (recordset[0].estado !== 'pendiente_aprobacion_cotizacion') return res.status(400).json({ error: 'Estado incorrecto' });

  await query(`
    UPDATE inversiones SET estado='en_compra', aprobado_cotizacion_por=@user, aprobado_cotizacion_at=GETDATE() WHERE id=@id
  `, { user: req.session.nagsa_user, id: parseInt(req.params.id) });

  res.json({ ok: true });
});

// POST /inversiones/:id/aprobar (gerente aprueba — Flujo B o Flujo A post-compra)
router.post('/:id/aprobar', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  if (!rolInfo.puede_aprobar_pago) return res.status(403).json({ error: 'Sin permiso' });

  const { recordset } = await query('SELECT id, estado FROM inversiones WHERE id=@id', { id: parseInt(req.params.id) });
  if (!recordset.length) return res.status(404).json({ error: 'No encontrada' });
  if (!['pendiente_aprobacion', 'pendiente_aprobacion_pago'].includes(recordset[0].estado)) return res.status(400).json({ error: 'Estado incorrecto' });

  await query(`
    UPDATE inversiones SET estado='pendiente_pago', aprobado_pago_por=@user, aprobado_pago_at=GETDATE() WHERE id=@id
  `, { user: req.session.nagsa_user, id: parseInt(req.params.id) });

  res.json({ ok: true });
});

// POST /inversiones/:id/marcar-comprado (TI confirma compra en Flujo A)
router.post('/:id/marcar-comprado', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  if (!rolInfo.puede_cotizar) return res.status(403).json({ error: 'Sin permiso' });

  const { recordset } = await query('SELECT id, estado FROM inversiones WHERE id=@id', { id: parseInt(req.params.id) });
  if (!recordset.length) return res.status(404).json({ error: 'No encontrada' });
  if (recordset[0].estado !== 'en_compra') return res.status(400).json({ error: 'Estado incorrecto' });

  await query(`UPDATE inversiones SET estado='pendiente_aprobacion_pago' WHERE id=@id`, { id: parseInt(req.params.id) });
  res.json({ ok: true });
});

// POST /inversiones/:id/marcar-pagado (contadora)
router.post('/:id/marcar-pagado', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  if (!rolInfo.puede_marcar_pagado) return res.status(403).json({ error: 'Sin permiso' });

  const { recordset } = await query('SELECT id, estado FROM inversiones WHERE id=@id', { id: parseInt(req.params.id) });
  if (!recordset.length) return res.status(404).json({ error: 'No encontrada' });
  if (recordset[0].estado !== 'pendiente_pago') return res.status(400).json({ error: 'Estado incorrecto' });

  await query(`
    UPDATE inversiones SET estado='pagado', marcado_pagado_por=@user, marcado_pagado_at=GETDATE() WHERE id=@id
  `, { user: req.session.nagsa_user, id: parseInt(req.params.id) });

  res.json({ ok: true });
});

// POST /inversiones/:id/rechazar
router.post('/:id/rechazar', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const rolInfo = getRolInversiones(cfg, req);
  if (!['ti', 'gerente'].includes(rolInfo.rol)) return res.status(403).json({ error: 'Sin permiso' });

  const { motivo } = req.body;
  await query(`
    UPDATE inversiones SET estado='rechazado', rechazado_por=@user, rechazado_at=GETDATE(), motivo_rechazo=@motivo WHERE id=@id
  `, { user: req.session.nagsa_user, motivo: motivo || '', id: parseInt(req.params.id) });

  res.json({ ok: true });
});

// GET /inversiones/:id (detalle)
router.get('/:id', requireLogin, async (req, res) => {
  const cfg = loadConfig();
  const { recordset } = await query('SELECT * FROM inversiones WHERE id=@id', { id: parseInt(req.params.id) });
  if (!recordset.length) return res.redirect('/inversiones');

  res.render('inversiones/detalle', {
    inv: recordset[0],
    rolInfo: getRolInversiones(cfg, req),
    nivelInfo: getNivelUsuario(cfg, req),
  });
});

module.exports = router;
