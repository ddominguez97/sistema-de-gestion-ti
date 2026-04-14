const express = require('express');
const router = express.Router();
const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');
const { requireLogin, checkModulo } = require('../middleware/auth');
const { loadConfig, DATA_DIR } = require('../config/config');

const DATA_FILE = path.join(DATA_DIR, 'actas.json');

function loadData() {
  if (!fs.existsSync(DATA_FILE)) return { next_id: 1, actas: [] };
  const raw = fs.readFileSync(DATA_FILE, 'utf8');
  const data = JSON.parse(raw);
  return (data && data.actas) ? data : { next_id: 1, actas: [] };
}
function saveData(data) {
  fs.writeFileSync(DATA_FILE, JSON.stringify(data, null, 4), 'utf8');
}

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
  res.render('actas');
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
        results.push({
          id:r.id, nombre:r.name, serie:r.serial||'---', fabricante:fab,
          modelo:'', usuario, estado, estado_id:r.states_id,
          ubicacion, ubicacion_id:r.locations_id, tipo:t.tipo, tabla:t.tabla,
        });
      }
    }

    // Consumibles y Cartuchos con stock
    const tiposSimples = [
      { tabla:'glpi_consumableitems', tipo:'Consumible', type_col:'consumableitemtypes_id', type_table:'glpi_consumableitemtypes', stock_table:'glpi_consumables', stock_fk:'consumableitems_id', itemtype:'ConsumableItem' },
      { tabla:'glpi_cartridgeitems', tipo:'Cartucho', type_col:'cartridgeitemtypes_id', type_table:'glpi_cartridgeitemtypes', stock_table:'glpi_cartridges', stock_fk:'cartridgeitems_id', itemtype:'CartridgeItem' },
    ];
    for (const t of tiposSimples) {
      const [rows] = await conn.execute(
        `SELECT c.id, c.name, c.manufacturers_id, c.${t.type_col},
                g.name AS grupo_nombre,
                (SELECT COUNT(*) FROM ${t.stock_table} s WHERE s.${t.stock_fk}=c.id AND s.date_out IS NULL) AS stock_disponible
         FROM ${t.tabla} c
         LEFT JOIN glpi_groups_items gi ON gi.items_id=c.id AND gi.itemtype=? AND gi.type=1
         LEFT JOIN glpi_groups g ON g.id=gi.groups_id
         WHERE c.name LIKE ? AND c.is_deleted=0 LIMIT 10`,
        [t.itemtype, '%'+q+'%']
      );
      for (const r of rows) {
        const fab = r.manufacturers_id ? ((await conn.execute('SELECT name FROM glpi_manufacturers WHERE id=? LIMIT 1',[r.manufacturers_id]))[0][0]?.name || '') : '';
        const tipoNombre = r[t.type_col] ? ((await conn.execute(`SELECT name FROM ${t.type_table} WHERE id=? LIMIT 1`,[r[t.type_col]]))[0][0]?.name || '---') : '---';
        results.push({
          id:r.id, nombre:r.name, serie:'---', fabricante:fab,
          modelo:tipoNombre, usuario:r.grupo_nombre ? 'Grupo: '+r.grupo_nombre : '---',
          estado:'---', estado_id:'', ubicacion:'---', ubicacion_id:'',
          tipo:t.tipo, tabla:t.tabla,
          stock: parseInt(r.stock_disponible) || 0,
        });
      }
    }

    res.json(results);
  } catch (e) {
    res.status(500).json({ error: e.message });
  } finally {
    if (conn) await conn.end();
  }
});

// GET /actas/api/usuarios — buscar usuarios GLPI
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
  } catch (e) {
    res.status(500).json([]);
  } finally {
    if (conn) await conn.end();
  }
});

// GET /actas/api/estados
router.get('/api/estados', requireLogin, async (req, res) => {
  let conn;
  try {
    conn = await getConn();
    const [rows] = await conn.execute('SELECT id, name FROM glpi_states ORDER BY name');
    res.json(rows);
  } catch (e) { res.json([]); }
  finally { if (conn) await conn.end(); }
});

// GET /actas/api/ubicaciones
router.get('/api/ubicaciones', requireLogin, async (req, res) => {
  let conn;
  try {
    conn = await getConn();
    const [rows] = await conn.execute('SELECT id, name, completename FROM glpi_locations ORDER BY completename');
    res.json(rows);
  } catch (e) { res.json([]); }
  finally { if (conn) await conn.end(); }
});

// POST /actas/api/guardar
router.post('/api/guardar', requireLogin, (req, res) => {
  const input = req.body;
  if (!input) return res.status(400).json({ error: 'Datos invalidos' });
  const data = loadData();
  const id = data.next_id;
  const numero = input.numero || ('ACT-' + String(id).padStart(3, '0'));
  const now = new Date().toISOString().replace('T',' ').slice(0,19);
  const acta = {
    id, numero, tipo: input.tipo || 'entrega',
    fecha: input.fecha || new Date().toISOString().slice(0,10),
    lugar: input.lugar || null,
    destino: input.destino || 'oficina',
    estado_equipo: input.estado_equipo || null,
    entregado_por: input.entregado_por || null,
    entregado_cargo: input.entregado_cargo || null,
    recibido_por: input.recibido_por || null,
    recibido_cargo: input.recibido_cargo || null,
    autorizado_por: input.autorizado_por || null,
    autorizado_cargo: input.autorizado_cargo || null,
    motivo: input.motivo || null,
    destino: input.destino || null,
    retira_persona: input.retira_persona || null,
    retira_cargo: input.retira_cargo || null,
    observaciones: input.observaciones || null,
    equipos: input.equipos || [],
    total_equipos: (input.equipos || []).length,
    estado: 'pendiente',
    aceptada_por: null, aceptada_fecha: null,
    aceptada_observaciones: null, firma_digital: null,
    created_by: req.session.nagsa_user,
    created_at: now, updated_at: now,
  };
  data.actas.push(acta);
  data.next_id = id + 1;
  saveData(data);
  res.json({ ok: true, id, numero });
});

// GET /actas/api/listar
router.get('/api/listar', requireLogin, (req, res) => {
  const data = loadData();
  let actas = data.actas;
  if (req.query.tipo) actas = actas.filter(a => a.tipo === req.query.tipo);
  if (req.query.estado) actas = actas.filter(a => a.estado === req.query.estado);
  if (req.query.desde) actas = actas.filter(a => a.fecha >= req.query.desde);
  if (req.query.hasta) actas = actas.filter(a => a.fecha <= req.query.hasta);
  if (req.query.buscar) {
    const q = req.query.buscar.toLowerCase();
    actas = actas.filter(a =>
      (a.numero||'').toLowerCase().includes(q) ||
      (a.entregado_por||'').toLowerCase().includes(q) ||
      (a.recibido_por||'').toLowerCase().includes(q) ||
      (a.autorizado_por||'').toLowerCase().includes(q) ||
      (a.created_by||'').toLowerCase().includes(q)
    );
  }
  actas.sort((a, b) => b.created_at.localeCompare(a.created_at));
  const result = actas.map(a => ({
    id:a.id, numero:a.numero, tipo:a.tipo, fecha:a.fecha, lugar:a.lugar, destino:a.destino||'oficina',
    entregado_por:a.entregado_por, recibido_por:a.recibido_por,
    autorizado_por:a.autorizado_por, retira_persona:a.retira_persona,
    total_equipos:a.total_equipos, estado:a.estado,
    created_by:a.created_by, created_at:a.created_at,
  }));
  res.json(result);
});

// GET /actas/api/detalle
router.get('/api/detalle', requireLogin, (req, res) => {
  const id = parseInt(req.query.id);
  if (!id) return res.status(400).json({ error: 'ID requerido' });
  const data = loadData();
  const acta = data.actas.find(a => a.id === id);
  if (!acta) return res.status(404).json({ error: 'Acta no encontrada' });
  res.json(acta);
});

// POST /actas/api/resguardo
router.post('/api/resguardo', requireLogin, (req, res) => {
  const input = req.body;
  if (!input || !input.id || !input.estado) return res.status(400).json({ error: 'Datos incompletos' });
  if (!['aceptada','rechazada'].includes(input.estado)) return res.status(400).json({ error: 'Estado no valido' });
  const data = loadData();
  const now = new Date().toISOString().replace('T',' ').slice(0,19);
  let found = false;
  for (const acta of data.actas) {
    if (acta.id === parseInt(input.id) && acta.estado === 'pendiente') {
      acta.estado = input.estado;
      acta.aceptada_por = input.aceptada_por || req.session.nagsa_user;
      acta.aceptada_fecha = now;
      acta.aceptada_observaciones = input.observaciones || null;
      acta.firma_digital = input.firma || null;
      acta.updated_at = now;
      found = true;
      break;
    }
  }
  if (!found) return res.status(409).json({ error: 'El acta ya fue procesada o no existe' });
  saveData(data);
  res.json({ ok: true });
});

// GET /actas/api/estadisticas
router.get('/api/estadisticas', requireLogin, (req, res) => {
  const data = loadData();
  const actas = data.actas;
  const porTipo = {}, porEstado = {};
  for (const a of actas) {
    porTipo[a.tipo] = (porTipo[a.tipo]||0) + 1;
    porEstado[a.estado] = (porEstado[a.estado]||0) + 1;
  }
  const limite = new Date(); limite.setMonth(limite.getMonth()-6);
  const limStr = limite.toISOString().slice(0,7);
  const porMes = {};
  for (const a of actas) {
    const mes = a.fecha.slice(0,7);
    if (mes < limStr) continue;
    const key = mes + '|' + a.tipo;
    porMes[key] = (porMes[key]||0) + 1;
  }
  const totalEquipos = actas.reduce((s,a) => s + (a.total_equipos||0), 0);
  const porUsuario = {};
  for (const a of actas) porUsuario[a.created_by] = (porUsuario[a.created_by]||0) + 1;
  const topUsuarios = Object.entries(porUsuario).sort((a,b)=>b[1]-a[1]).slice(0,5).map(([u,t])=>({created_by:u,total:t}));
  res.json({
    por_tipo: Object.entries(porTipo).map(([tipo,total])=>({tipo,total})),
    por_estado: Object.entries(porEstado).map(([estado,total])=>({estado,total})),
    por_mes: Object.entries(porMes).sort().map(([k,total])=>{ const [mes,tipo]=k.split('|'); return {mes,tipo,total}; }),
    general: { total:actas.length, equipos:totalEquipos },
    top_usuarios: topUsuarios,
  });
});

module.exports = router;
