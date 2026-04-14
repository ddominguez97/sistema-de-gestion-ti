const express = require('express');
const router = express.Router();
const { requireLogin, checkModulo } = require('../middleware/auth');

// GET /reportes — vista
router.get('/', (req, res) => {
  // Dev mode: auto-session for localhost
  const host = req.headers.host || '';
  if (!req.session.nagsa_user && (host.startsWith('localhost') || host.startsWith('127.0.0.1'))) {
    req.session.nagsa_user = 'dev_local';
    req.session.nagsa_name = 'Desarrollo Local';
    req.session.nagsa_firstname = 'Desarrollo';
    req.session.admin_ok = true;
  }
  if (!req.session.nagsa_user) return res.redirect('/');
  const cfg = res.locals.cfg;
  const blocked = checkModulo(cfg, 'reportes', req);
  if (blocked) return res.render('proximamente', { titulo: 'Reportes y Estadisticas' });
  // Determinar si es admin (ve todo) o usuario normal (ve solo las suyas)
  const esAdmin = req.session.nagsa_auth === 'glpi' || req.session.admin_ok;
  res.render('reportes', { esAdmin });
});

// Proximamente route
router.get('/proximamente', requireLogin, (req, res) => {
  const nombres = { inversiones: 'Formato de Inversion', permisos: 'Permisos y Notificaciones' };
  const m = req.query.m || '';
  res.render('proximamente', { titulo: nombres[m] || 'Nuevo Modulo' });
});

module.exports = router;
