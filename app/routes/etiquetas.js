const express = require('express');
const router = express.Router();
const mysql = require('mysql2/promise');
const { requireLogin, checkModulo, getNivelUsuario } = require('../middleware/auth');
const { loadConfig } = require('../config/config');

// Helpers
async function getConn() {
  const cfg = loadConfig();
  return mysql.createConnection({
    host: cfg.db_host, port: parseInt(cfg.db_port) || 3306,
    database: cfg.db_name, user: cfg.db_user, password: cfg.db_pass,
    charset: 'utf8mb4',
  });
}

async function lookup(conn, table, id) {
  if (!id) return '---';
  const [r] = await conn.execute(`SELECT name FROM ${table} WHERE id = ? LIMIT 1`, [id]);
  return r[0] ? r[0].name : '---';
}
async function getFab(conn, id) { if (!id) return ''; const [r] = await conn.execute('SELECT name FROM glpi_manufacturers WHERE id=? LIMIT 1',[id]); return r[0]?r[0].name:''; }
async function getUsuario(conn, id) { if (!id) return ''; const [r] = await conn.execute("SELECT CONCAT(firstname,' ',realname) n FROM glpi_users WHERE id=? LIMIT 1",[id]); return r[0]?r[0].n.trim():''; }
async function getEstado(conn, id) { if (!id) return ''; const [r] = await conn.execute('SELECT name FROM glpi_states WHERE id=? LIMIT 1',[id]); return r[0]?r[0].name:''; }
async function getIP(conn, type, id) {
  const [r] = await conn.execute(
    "SELECT ip.name FROM glpi_networkports np JOIN glpi_networknames nn ON nn.items_id=np.id AND nn.itemtype='NetworkPort' JOIN glpi_ipaddresses ip ON ip.items_id=nn.id AND ip.itemtype='NetworkName' WHERE np.itemtype=? AND np.items_id=? AND np.is_deleted=0 LIMIT 1",
    [type, id]
  );
  return r[0] ? r[0].name : '---';
}
function qrUrl(base, type, id) {
  const map = { Computer:'computer.form.php', Monitor:'monitor.form.php', Printer:'printer.form.php', Peripheral:'peripheral.form.php', NetworkEquipment:'networkequipment.form.php', Enclosure:'enclosure.form.php', PassiveDCEquipment:'passivedcequipment.form.php', CartridgeItem:'cartridgeitem.form.php', ConsumableItem:'consumableitem.form.php', Phone:'phone.form.php' };
  return base ? base + '/front/' + (map[type] || 'computer.form.php') + '?id=' + id : type + '?id=' + id;
}

// GET /etiquetas — vista
// Middleware: solo N1/N2 pueden acceder a etiquetas
function requireTI(req, res, next) {
  const cfg = loadConfig();
  const nivelInfo = getNivelUsuario(cfg, req);
  if (nivelInfo.nivel > 2) return res.redirect('/');
  next();
}

router.get('/', requireLogin, requireTI, (req, res) => {
  const cfg = res.locals.cfg;
  const blocked = checkModulo(cfg, 'etiquetas', req);
  if (blocked) return res.render('proximamente', { titulo: 'Etiquetas de Activos' });
  res.render('etiquetas');
});

// GET /etiquetas/api — datos de activos
router.get('/api', requireLogin, requireTI, async (req, res) => {
  const cfg = loadConfig();
  const BASE = cfg.base_url || '';
  const ENT = parseInt(cfg.entity_id) || 0;
  const show = cfg.show || {};
  const ef = ENT > 0 ? ` AND entities_id=${ENT}` : '';
  let conn;
  try {
    conn = await getConn();
    const out = {};

    if (show.computadoras) {
      const [rows] = await conn.execute(`SELECT id,name,serial,manufacturers_id,computermodels_id,computertypes_id,users_id,states_id FROM glpi_computers WHERE is_deleted=0 AND is_template=0 ${ef} ORDER BY name`);
      out.computadoras = [];
      for (const r of rows) out.computadoras.push({ id:String(r.id), nombre:r.name, fabricante:await getFab(conn,r.manufacturers_id), modelo:await lookup(conn,'glpi_computermodels',r.computermodels_id), serie:r.serial||'---', ip:await getIP(conn,'Computer',r.id), tipo:await lookup(conn,'glpi_computertypes',r.computertypes_id), usuario:await getUsuario(conn,r.users_id), estado:await getEstado(conn,r.states_id), qr_url:qrUrl(BASE,'Computer',r.id), activo:'Computadora' });
    }
    if (show.monitores) {
      const [rows] = await conn.execute(`SELECT id,name,serial,manufacturers_id,monitormodels_id,monitortypes_id,users_id,states_id FROM glpi_monitors WHERE is_deleted=0 AND is_template=0 ${ef} ORDER BY name`);
      out.monitores = [];
      for (const r of rows) out.monitores.push({ id:String(r.id), nombre:r.name, fabricante:await getFab(conn,r.manufacturers_id), modelo:await lookup(conn,'glpi_monitormodels',r.monitormodels_id), serie:r.serial||'---', ip:'---', tipo:await lookup(conn,'glpi_monitortypes',r.monitortypes_id), usuario:await getUsuario(conn,r.users_id), estado:await getEstado(conn,r.states_id), qr_url:qrUrl(BASE,'Monitor',r.id), activo:'Monitor' });
    }
    if (show.impresoras) {
      const [rows] = await conn.execute(`SELECT id,name,serial,manufacturers_id,printermodels_id,printertypes_id,users_id,states_id FROM glpi_printers WHERE is_deleted=0 AND is_template=0 ${ef} ORDER BY name`);
      out.impresoras = [];
      for (const r of rows) out.impresoras.push({ id:String(r.id), nombre:r.name, fabricante:await getFab(conn,r.manufacturers_id), modelo:await lookup(conn,'glpi_printermodels',r.printermodels_id), serie:r.serial||'---', ip:await getIP(conn,'Printer',r.id), tipo:await lookup(conn,'glpi_printertypes',r.printertypes_id), usuario:await getUsuario(conn,r.users_id), estado:await getEstado(conn,r.states_id), qr_url:qrUrl(BASE,'Printer',r.id), activo:'Impresora' });
    }
    if (show.perifericos) {
      const [rows] = await conn.execute(`SELECT id,name,serial,manufacturers_id,peripheralmodels_id,peripheraltypes_id,users_id,states_id FROM glpi_peripherals WHERE is_deleted=0 AND is_template=0 ${ef} ORDER BY name`);
      out.perifericos = [];
      for (const r of rows) out.perifericos.push({ id:String(r.id), nombre:r.name, fabricante:await getFab(conn,r.manufacturers_id), modelo:await lookup(conn,'glpi_peripheralmodels',r.peripheralmodels_id), serie:r.serial||'---', ip:'---', tipo:await lookup(conn,'glpi_peripheraltypes',r.peripheraltypes_id), usuario:await getUsuario(conn,r.users_id), estado:await getEstado(conn,r.states_id), qr_url:qrUrl(BASE,'Peripheral',r.id), activo:'Periferico' });
    }
    if (show.redes) {
      const [rows] = await conn.execute(`SELECT id,name,serial,manufacturers_id,networkequipmentmodels_id,networkequipmenttypes_id,users_id,states_id FROM glpi_networkequipments WHERE is_deleted=0 AND is_template=0 ${ef} ORDER BY name`);
      out.redes = [];
      for (const r of rows) out.redes.push({ id:String(r.id), nombre:r.name, fabricante:await getFab(conn,r.manufacturers_id), modelo:await lookup(conn,'glpi_networkequipmentmodels',r.networkequipmentmodels_id), serie:r.serial||'---', ip:await getIP(conn,'NetworkEquipment',r.id), tipo:await lookup(conn,'glpi_networkequipmenttypes',r.networkequipmenttypes_id), usuario:await getUsuario(conn,r.users_id), estado:await getEstado(conn,r.states_id), qr_url:qrUrl(BASE,'NetworkEquipment',r.id), activo:'Dispositivo de Red' });
    }
    if (show.gabinetes) {
      const [rows] = await conn.execute(`SELECT id,name,serial,manufacturers_id,enclosuremodels_id,states_id FROM glpi_enclosures WHERE is_deleted=0 AND is_template=0 ${ef} ORDER BY name`);
      out.gabinetes = [];
      for (const r of rows) out.gabinetes.push({ id:String(r.id), nombre:r.name, fabricante:await getFab(conn,r.manufacturers_id), modelo:await lookup(conn,'glpi_enclosuremodels',r.enclosuremodels_id), serie:r.serial||'---', ip:'---', tipo:'Gabinete', usuario:'', estado:await getEstado(conn,r.states_id), qr_url:qrUrl(BASE,'Enclosure',r.id), activo:'Gabinete' });
    }
    if (show.telefonos) {
      const [rows] = await conn.execute(`SELECT id,name,serial,manufacturers_id,phonemodels_id,phonetypes_id,users_id,states_id FROM glpi_phones WHERE is_deleted=0 AND is_template=0 ${ef} ORDER BY name`);
      out.telefonos = [];
      for (const r of rows) out.telefonos.push({ id:String(r.id), nombre:r.name, fabricante:await getFab(conn,r.manufacturers_id), modelo:await lookup(conn,'glpi_phonemodels',r.phonemodels_id), serie:r.serial||'---', ip:await getIP(conn,'Phone',r.id), tipo:await lookup(conn,'glpi_phonetypes',r.phonetypes_id), usuario:await getUsuario(conn,r.users_id), estado:await getEstado(conn,r.states_id), qr_url:qrUrl(BASE,'Phone',r.id), activo:'Telefono' });
    }
    if (show.cartuchos) {
      const [rows] = await conn.execute(`SELECT id,name,manufacturers_id,cartridgeitemtypes_id FROM glpi_cartridgeitems WHERE is_deleted=0 ${ef} ORDER BY name`);
      out.cartuchos = [];
      for (const r of rows) out.cartuchos.push({ id:String(r.id), nombre:r.name, fabricante:await getFab(conn,r.manufacturers_id), modelo:'---', serie:'---', ip:'---', tipo:await lookup(conn,'glpi_cartridgeitemtypes',r.cartridgeitemtypes_id), usuario:'', estado:'', qr_url:qrUrl(BASE,'CartridgeItem',r.id), activo:'Cartucho' });
    }
    if (show.consumibles) {
      const [rows] = await conn.execute(`SELECT id,name,manufacturers_id,consumableitemtypes_id FROM glpi_consumableitems WHERE is_deleted=0 ${ef} ORDER BY name`);
      out.consumibles = [];
      for (const r of rows) out.consumibles.push({ id:String(r.id), nombre:r.name, fabricante:await getFab(conn,r.manufacturers_id), modelo:'---', serie:'---', ip:'---', tipo:await lookup(conn,'glpi_consumableitemtypes',r.consumableitemtypes_id), usuario:'', estado:'', qr_url:qrUrl(BASE,'ConsumableItem',r.id), activo:'Consumible' });
    }

    res.json(out);
  } catch (e) {
    res.status(500).json({ error: e.message });
  } finally {
    if (conn) await conn.end();
  }
});

// GET /etiquetas/api/impresoras — buscar impresoras Zebra (para admin)
router.get('/api/impresoras', requireLogin, requireTI, async (req, res) => {
  const q = (req.query.q || '').trim();
  if (q.length < 1) return res.json([]);
  let conn;
  try {
    conn = await getConn();
    const [rows] = await conn.execute(
      `SELECT p.id, p.name, p.serial, m.name AS modelo, ip.name AS ip
       FROM glpi_printers p
       LEFT JOIN glpi_printermodels m ON m.id = p.printermodels_id
       LEFT JOIN glpi_networkports np ON np.items_id = p.id AND np.itemtype = 'Printer' AND np.is_deleted = 0
       LEFT JOIN glpi_networknames nn ON nn.items_id = np.id AND nn.itemtype = 'NetworkPort'
       LEFT JOIN glpi_ipaddresses ip ON ip.items_id = nn.id AND ip.itemtype = 'NetworkName'
       WHERE p.is_deleted = 0 AND p.is_template = 0 AND (p.name LIKE ? OR ip.name LIKE ?)
       GROUP BY p.id ORDER BY p.name LIMIT 10`,
      ['%'+q+'%', '%'+q+'%']
    );
    res.json(rows.map(r => ({ id:r.id, nombre:r.name, ip:r.ip||'---', modelo:r.modelo||'---', serie:r.serial||'---' })));
  } catch (e) {
    res.status(500).json({ error: e.message });
  } finally {
    if (conn) await conn.end();
  }
});

module.exports = router;
