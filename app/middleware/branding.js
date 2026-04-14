const { loadConfig, getBranding, brandingVars } = require('../config/config');

function brandingMiddleware(req, res, next) {
  try {
    const cfg = loadConfig();
    const branding = getBranding(cfg);
    const vars = brandingVars(branding);
    res.locals.cfg = cfg;
    res.locals.branding = branding;
    res.locals.themeVars = vars;
    res.locals.glpiUrl = (cfg.base_url || '').replace(/\/$/, '');
  } catch (e) {
    res.locals.cfg = {};
    res.locals.branding = { nombre: 'Sistema', color: '#E05816', logo: '', tema: 'oscuro' };
    res.locals.themeVars = {
      bg_body: '#1a1a1a', bg_card: '#1e1e1e', bg_sidebar: '#1e1e1e',
      bg_topbar: '#1e1e1e', bg_input: '#252525', bg_hover: '#2a2a2a',
      bg_dark2: '#2a2a2a', bg_dark3: '#333333',
      text_main: '#ffffff', text_sub: '#aaaaaa', text_muted: '#666666',
      border: '#333333', border2: '#444444',
    };
    res.locals.glpiUrl = '';
  }
  next();
}

module.exports = brandingMiddleware;
