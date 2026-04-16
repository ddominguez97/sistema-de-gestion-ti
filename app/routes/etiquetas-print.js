const express = require('express');
const router = express.Router();
const net = require('net');
const { loadConfig, getBranding } = require('../config/config');
const { requireLogin, getNivelUsuario } = require('../middleware/auth');

function buildZPL(item, size, fields, empresaNombre, glpiUrl) {
  const esc = (s) => {
    return String(s || '---').replace(/[\\^~{}|]/g, c => '\\' + c).substring(0, 35);
  };

  const fieldMap = {
    nombre: { label: 'NOMBRE', key: 'nombre' },
    fab:    { label: 'FABRICANTE', key: 'fabricante' },
    mod:    { label: 'MODELO', key: 'modelo' },
    serie:  { label: 'S/N', key: 'serie' },
    tipo:   { label: 'TIPO', key: 'tipo' },
    ip:     { label: 'IP', key: 'ip' },
    user:   { label: 'USUARIO', key: 'usuario' },
  };

  const active = Object.entries(fieldMap).filter(([k]) => fields[k]);
  const nfields = active.length;
  const hasQr = fields.qr && item.qr_url;

  let w, h, headerH, bodyStart, footerY, bodyH;
  let qrMag, qrSize, qrArea, sepX, qrX, qrY;
  let infoX, fieldH, infoYStart;
  let fontLh, fontLw, fontVh, fontVw, maxChars;
  let hfh, hfw, hty, maxHeader, ffh, ffw;

  if (size === 58) {
    w = 463; h = 312; headerH = 36; bodyStart = 38; footerY = 285;
    bodyH = footerY - bodyStart;
    qrMag = 5; qrSize = 180; qrArea = hasQr ? 200 : 0;
    sepX = w - qrArea; qrX = sepX + 10;
    qrY = bodyStart + Math.floor((bodyH - qrSize) / 2);
    infoX = 4; fieldH = 41; infoYStart = bodyStart + 4;
    fontLh = 13; fontLw = 8; fontVh = 24; fontVw = 15; maxChars = 40;
    hfh = 26; hfw = 17; hty = 5; maxHeader = 47;
    ffh = 12; ffw = 7;
  } else {
    w = 800; h = 480; headerH = 42; bodyStart = 46; footerY = 452;
    bodyH = footerY - bodyStart;
    qrMag = 5; qrSize = 180; qrArea = hasQr ? 220 : 0;
    sepX = w - qrArea; qrX = sepX + 12;
    qrY = bodyStart + Math.floor((bodyH - qrSize) / 2);
    infoX = 8; fieldH = Math.min(90, Math.floor(bodyH / Math.max(1, nfields)));
    infoYStart = bodyStart + 6;
    fontLh = 15; fontLw = 9; fontVh = 30; fontVw = 18; maxChars = 28;
    hfh = 30; hfw = 18; hty = 6; maxHeader = 46;
    ffh = 14; ffw = 9;
  }

  let zpl = '^XA\n';
  zpl += `^PW${w}\n`;
  zpl += `^LL${h}\n`;
  zpl += '^CI28\n';
  zpl += '^LT16\n';

  // Header negro con texto blanco centrado
  zpl += `^FO0,0^GB${w},${headerH},${headerH}^FS\n`;
  let headerTxt = (empresaNombre + '  -  ' + (item.nombre || '').toUpperCase()).substring(0, maxHeader);
  zpl += `^CF0,${hfh},${hfw}\n`;
  zpl += `^FO0,${hty}^FR^FB${w},1,0,C,0^FD${headerTxt}^FS\n`;
  zpl += '^CF0,30,20\n';

  // Separador vertical
  if (hasQr) {
    const sepH = footerY - bodyStart;
    zpl += `^FO${sepX},${bodyStart}^GB1,${sepH},1^FS\n`;
  }

  // Campos info
  let y = infoYStart;
  for (const [fkey, fdef] of active) {
    const raw = String(item[fdef.key] || '');
    const val = esc((raw || '---').substring(0, maxChars));
    zpl += `^FO${infoX},${y}^A0N,${fontLh},${fontLw}^FD${fdef.label}^FS\n`;
    zpl += `^FO${infoX},${y + fontLh + 2}^A0N,${fontVh},${fontVw}^FD${val}^FS\n`;
    y += fieldH;
  }

  // QR
  if (hasQr) {
    zpl += `^FO${qrX},${qrY}^BQN,2,${qrMag}^FDMA,${item.qr_url}^FS\n`;
  }

  // Footer centrado
  zpl += `^FO0,${footerY}^GB${w},1,1^FS\n`;
  const footerUrl = (glpiUrl || '').replace(/^https?:\/\//, '').replace(/\/$/, '') || 'sistema.local';
  zpl += `^FO0,${footerY + 4}^A0N,${ffh},${ffw}^FB${w},1,0,C,0^FD${empresaNombre}  *  Gestion de Activos TI  *  ${footerUrl}^FS\n`;

  zpl += '^XZ\n';
  return zpl;
}

// POST /etiquetas/print — enviar ZPL a la impresora Zebra
router.post('/', requireLogin, (req, res, next) => {
  const cfg = loadConfig();
  if (getNivelUsuario(cfg, req).nivel > 2) return res.status(403).json({ error: 'Sin acceso' });
  next();
}, (req, res) => {
  const cfg = loadConfig();
  const branding = getBranding(cfg);
  const empresaNombre = (branding.nombre || 'NAGSA').toUpperCase();
  const zebraIp = cfg.zebra_ip || '';
  const zebraPort = parseInt(cfg.zebra_port) || 9100;

  if (!zebraIp) return res.status(400).json({ error: 'IP de impresora no configurada. Configura desde el panel admin.' });

  const { items, size, fields } = req.body;
  if (!items || !items.length) return res.status(400).json({ error: 'No hay etiquetas para imprimir' });

  // Generar ZPL
  let zplTotal = '';
  for (const item of items) {
    zplTotal += buildZPL(item, parseInt(size) || 58, fields || {}, empresaNombre, cfg.base_url);
  }

  // Enviar por socket TCP
  const client = new net.Socket();
  client.setTimeout(5000);

  client.connect(zebraPort, zebraIp, () => {
    client.write(zplTotal, () => {
      client.destroy();
      res.json({ ok: true, enviadas: items.length });
    });
  });

  client.on('error', (err) => {
    client.destroy();
    res.status(500).json({ error: `No se pudo conectar a la impresora (${zebraIp}:${zebraPort}) - ${err.message}` });
  });

  client.on('timeout', () => {
    client.destroy();
    res.status(500).json({ error: `Timeout al conectar con la impresora (${zebraIp}:${zebraPort})` });
  });
});

// GET /etiquetas/print/debug — ver ZPL generado (solo para testing)
router.get('/debug', requireLogin, (req, res) => {
  const cfg = loadConfig();
  const branding = getBranding(cfg);
  const empresaNombre = (branding.nombre || 'NAGSA').toUpperCase();
  const size = parseInt(req.query.size) || 58;

  const testItem = {
    id: '999', nombre: 'EQC-WW-0000105', fabricante: 'LENOVO',
    modelo: 'ThinkPad E14 Gen 4', serie: 'PF4C3GV9', tipo: 'Laptop',
    ip: '192.168.1.100', usuario: 'Juan Perez',
    qr_url: 'https://glpi.nagsa.com.ec/front/computer.form.php?id=999',
  };
  const testFields = { nombre: true, fab: true, mod: true, serie: true, qr: true };
  const zpl = buildZPL(testItem, size, testFields, empresaNombre, cfg.base_url);

  res.setHeader('Content-Type', 'text/plain; charset=utf-8');
  res.send(`=== ZPL GENERADO (size=${size}mm) ===\n\n${zpl}\n=== FIN ZPL ===\n`);
});

module.exports = router;
