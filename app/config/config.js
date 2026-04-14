const fs = require('fs');
const path = require('path');

const CONFIG_FILE = path.join(__dirname, '..', '..', 'sistemas_settings.json');
const ROOT_PATH = path.join(__dirname, '..', '..');
const DATA_DIR = path.join(ROOT_PATH, 'data');

function loadConfig() {
  if (!fs.existsSync(CONFIG_FILE)) {
    throw new Error('No existe sistemas_settings.json. Configura primero desde el panel admin.');
  }
  return JSON.parse(fs.readFileSync(CONFIG_FILE, 'utf8'));
}

function saveConfig(cfg) {
  fs.writeFileSync(CONFIG_FILE, JSON.stringify(cfg, null, 4));
}

function getBranding(cfg) {
  const logo = cfg.empresa_logo || 'logo_empresa.png';
  const logoPath = path.join(ROOT_PATH, logo);
  let ts = Date.now();
  try { ts = fs.statSync(logoPath).mtimeMs; } catch {}
  return {
    nombre: cfg.empresa_nombre || 'NAGSA',
    color: cfg.empresa_color || '#E05816',
    logo: '/public/' + logo + '?v=' + Math.floor(ts),
    tema: cfg.empresa_tema || 'oscuro',
  };
}

function brandingVars(branding) {
  const tema = branding.tema;
  let vars;
  if (tema === 'claro') {
    vars = {
      bg_body: '#f0f4f8', bg_card: '#ffffff', bg_sidebar: '#ffffff',
      bg_topbar: '#ffffff', bg_input: '#f9f9f9', bg_hover: '#f0f0f0',
      bg_dark2: '#f0f0f0', bg_dark3: '#e8e8e8',
      text_main: '#1a1a1a', text_sub: '#555555', text_muted: '#888888',
      border: '#dddddd', border2: '#cccccc',
    };
  } else {
    vars = {
      bg_body: '#1a1a1a', bg_card: '#1e1e1e', bg_sidebar: '#1e1e1e',
      bg_topbar: '#1e1e1e', bg_input: '#252525', bg_hover: '#2a2a2a',
      bg_dark2: '#2a2a2a', bg_dark3: '#333333',
      text_main: '#ffffff', text_sub: '#aaaaaa', text_muted: '#666666',
      border: '#333333', border2: '#444444',
    };
  }
  return vars;
}

function getEstadoModulo(cfg, modulo) {
  return (cfg.modulos && cfg.modulos[modulo]) || 'activo';
}

module.exports = {
  CONFIG_FILE, ROOT_PATH, DATA_DIR,
  loadConfig, saveConfig, getBranding, brandingVars, getEstadoModulo,
};
