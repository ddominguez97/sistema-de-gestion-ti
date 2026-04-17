const express = require('express');
const router = express.Router();
const { loginGLPI, loginAD, resolveADConfig, getNivelUsuario, getPermisosUsuario } = require('../middleware/auth');
const { loadConfig } = require('../config/config');

// GET / — Login o Dashboard
router.get('/', async (req, res) => {
  if (req.session.nagsa_user) {
    const cfg = res.locals.cfg;
    const modulos = cfg.modulos || {};
    const nivelInfo = getNivelUsuario(cfg, req);
    const permisosUser = getPermisosUsuario(cfg, req);
    // Determinar si ve el icono de admin
    let showAdmin = false;
    if (nivelInfo.nivel === 1) showAdmin = true;
    else if (req.session.admin_ok) showAdmin = true;
    else if (nivelInfo.nivel === 2 && nivelInfo.config && nivelInfo.config.admin_panel) showAdmin = true;
    return res.render('dashboard', { modulos, showAdmin, nivelInfo, permisosUser });
  }
  res.render('login', { error: null });
});

// POST / — Login (AD primero, GLPI como fallback)
router.post('/', async (req, res) => {
  const { username: rawUsername, password } = req.body;
  const username = (rawUsername || '').trim().toLowerCase();
  const cfg = res.locals.cfg;
  const manualCfg = cfg.active_directory || {};

  let result = null;
  let error = null;

  // 1. Intentar AD primero si esta configurado
  let adConfig = null;
  try {
    adConfig = await resolveADConfig(manualCfg);
  } catch {}

  if (adConfig) {
    try {
      result = await loginAD(username, password, adConfig);
    } catch {}
  }

  // 2. Si AD fallo o no esta configurado, fallback a GLPI
  if (!result) {
    try {
      result = await loginGLPI(username, password);
    } catch {}
  }

  // 3. Si ambos fallaron
  if (!result) {
    error = 'Usuario o contrasena incorrectos.';
    return res.render('login', { error });
  }

  req.session.nagsa_user = result.user;
  req.session.nagsa_name = result.name;
  req.session.nagsa_firstname = result.firstname || result.name;
  req.session.nagsa_auth = result.auth;
  req.session.last_activity = Date.now();
  res.redirect('/');
});

// GET /logout
router.get('/logout', (req, res) => {
  req.session.destroy(() => res.redirect('/'));
});

module.exports = router;
