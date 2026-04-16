const express = require('express');
const router = express.Router();
const { requireLogin, checkModulo, getNivelUsuario } = require('../middleware/auth');

// GET /reportes — vista
router.get('/', requireLogin, (req, res) => {
  const cfg = res.locals.cfg;
  const blocked = checkModulo(cfg, 'reportes', req);
  if (blocked) return res.render('proximamente', { titulo: 'Reportes y Estadisticas' });
  // Determinar nivel y permisos
  const nivelInfo = getNivelUsuario(cfg, req);
  const esAdmin = nivelInfo.nivel <= 2;
  res.render('reportes', { esAdmin, nivelInfo });
});

// Proximamente route
router.get('/proximamente', requireLogin, (req, res) => {
  const nombres = { inversiones: 'Formato de Inversion', permisos: 'Permisos y Notificaciones' };
  const m = req.query.m || '';
  res.render('proximamente', { titulo: nombres[m] || 'Nuevo Modulo' });
});

module.exports = router;
