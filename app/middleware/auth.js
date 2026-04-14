const mysql = require('mysql2/promise');
const { loadConfig } = require('../config/config');

function requireLogin(req, res, next) {
  if (req.session && req.session.nagsa_user) {
    req.session.last_activity = Date.now();
    return next();
  }
  return res.redirect('/');
}

function requireAdmin(req, res, next) {
  if (req.session && req.session.admin_ok) {
    return next();
  }
  return res.redirect('/admin');
}

async function loginGLPI(username, password) {
  const cfg = loadConfig();
  const conn = await mysql.createConnection({
    host: cfg.db_host,
    port: parseInt(cfg.db_port) || 3306,
    database: cfg.db_name,
    user: cfg.db_user,
    password: cfg.db_pass,
    charset: 'utf8mb4',
    connectTimeout: 5000,
  });
  try {
    const [rows] = await conn.execute(
      'SELECT id, name, password, firstname, realname FROM glpi_users WHERE name = ? AND is_deleted = 0 AND is_active = 1 LIMIT 1',
      [username]
    );
    if (rows.length === 0) return null;
    const user = rows[0];
    // PHP password_hash uses bcrypt - we need to verify
    const crypto = require('crypto');
    // GLPI uses PHP's password_hash (bcrypt) - use a pure comparison
    // We'll use a simple approach: try direct bcrypt verification
    const bcryptMatch = await verifyBcrypt(password, user.password);
    if (!bcryptMatch) return null;
    return {
      user: user.name,
      name: (user.firstname + ' ' + user.realname).trim(),
      firstname: user.firstname || user.name,
      auth: 'glpi',
    };
  } finally {
    await conn.end();
  }
}

async function verifyBcrypt(password, hash) {
  // PHP password_hash generates $2y$ hashes, Node bcrypt uses $2b$
  // Replace $2y$ with $2b$ for compatibility
  const normalizedHash = hash.replace(/^\$2y\$/, '$2b$');
  try {
    const bcrypt = require('bcryptjs');
    return await bcrypt.compare(password, normalizedHash);
  } catch {
    return false;
  }
}

// Obtener configuracion LDAP/AD directamente desde la BD de GLPI
async function getGLPILdapConfig() {
  const cfg = loadConfig();
  const conn = await mysql.createConnection({
    host: cfg.db_host,
    port: parseInt(cfg.db_port) || 3306,
    database: cfg.db_name,
    user: cfg.db_user,
    password: cfg.db_pass,
    charset: 'utf8mb4',
    connectTimeout: 5000,
  });
  try {
    const [rows] = await conn.execute(
      `SELECT id, name, host, port, basedn, rootdn, rootdn_passwd,
              login_field, realname_field, firstname_field, email1_field,
              use_tls, is_active
       FROM glpi_authldaps
       WHERE is_active = 1 AND host != ''
       ORDER BY id ASC LIMIT 1`
    );
    if (rows.length === 0) return null;
    const r = rows[0];
    return {
      nombre: r.name || 'Active Directory (GLPI)',
      servidor: r.host,
      puerto: r.port || 389,
      base_dn: r.basedn || '',
      bind_dn: r.rootdn || '',
      bind_password: r.rootdn_passwd || '',
      login_field: r.login_field || 'sAMAccountName',
      realname_field: r.realname_field || 'displayName',
      firstname_field: r.firstname_field || 'givenName',
      use_tls: r.use_tls || 0,
      source: 'glpi',
    };
  } finally {
    await conn.end();
  }
}

// Determinar config AD: primero GLPI, luego manual como fallback
async function resolveADConfig(manualCfg) {
  const modo = (manualCfg && manualCfg.modo) || 'automatica';

  if (modo === 'automatica') {
    // Leer LDAP desde la BD de GLPI
    try {
      const glpiLdap = await getGLPILdapConfig();
      if (glpiLdap) return glpiLdap;
    } catch {}
    return null;
  }

  // Modo manual: usar config del JSON solo si tiene servidor
  if (manualCfg && manualCfg.servidor) {
    return { ...manualCfg, source: 'manual' };
  }
  return null;
}

async function loginAD(username, password, adCfg) {
  const ldap = require('ldapjs');
  return new Promise((resolve) => {
    const server = adCfg.servidor;
    const port = parseInt(adCfg.puerto) || 389;
    const protocol = adCfg.use_tls ? 'ldaps' : 'ldap';

    // Si viene de GLPI, usar bind_dn + login_field para autenticacion
    // Si es manual, usar dominio/sufijo como antes
    let ldapUser;
    if (adCfg.source === 'glpi') {
      // AD tipico: usuario@dominio o DOMINIO\usuario
      // Extraer dominio del base_dn (dc=empresa,dc=com -> empresa.com)
      const baseDn = adCfg.base_dn || '';
      const domainParts = baseDn.match(/dc=([^,]+)/gi);
      if (domainParts) {
        const domain = domainParts.map(d => d.replace(/dc=/i, '')).join('.');
        ldapUser = username + '@' + domain;
      } else {
        ldapUser = username;
      }
    } else {
      // Config manual: usar sufijo o dominio
      const domain = adCfg.dominio || '';
      const sufijo = adCfg.sufijo_usuario || '';
      if (sufijo) ldapUser = username + sufijo;
      else if (domain) ldapUser = domain + '\\' + username;
      else ldapUser = username;
    }

    const client = ldap.createClient({
      url: `${protocol}://${server}:${port}`,
      connectTimeout: 5000,
    });

    client.on('error', () => resolve(null));

    client.bind(ldapUser, password, (err) => {
      if (err) {
        client.destroy();
        return resolve(null);
      }

      let displayName = username;
      let firstName = '';
      const baseDn = adCfg.base_dn || '';
      const loginField = adCfg.login_field || 'sAMAccountName';
      const realnameField = adCfg.realname_field || 'displayName';
      const firstnameField = adCfg.firstname_field || 'givenName';

      if (baseDn) {
        client.search(baseDn, {
          filter: `(${loginField}=${username})`,
          attributes: [realnameField, firstnameField, 'sn', 'displayName', 'givenName'],
          scope: 'sub',
        }, (searchErr, searchRes) => {
          if (searchErr) {
            client.destroy();
            return resolve({ user: username, name: displayName, firstname: firstName || username, auth: 'ad' });
          }
          searchRes.on('searchEntry', (entry) => {
            const attrs = entry.pojo ? entry.pojo.attributes : [];
            for (const a of attrs) {
              if (a.type === realnameField && a.values[0]) displayName = a.values[0];
              else if (a.type === 'displayName' && a.values[0] && displayName === username) displayName = a.values[0];
              if (a.type === firstnameField && a.values[0]) firstName = a.values[0];
              else if (a.type === 'givenName' && a.values[0] && !firstName) firstName = a.values[0];
            }
          });
          searchRes.on('end', () => {
            client.destroy();
            resolve({ user: username, name: displayName, firstname: firstName || displayName, auth: 'ad' });
          });
        });
      } else {
        client.destroy();
        resolve({ user: username, name: displayName, firstname: firstName || username, auth: 'ad' });
      }
    });
  });
}

function checkModulo(cfg, modulo, req) {
  const estado = (cfg.modulos && cfg.modulos[modulo]) || 'activo';
  if (estado === 'activo') return null;
  if (estado === 'pruebas' && req.session) {
    // Admin del panel
    if (req.session.admin_ok) return null;
    // Usuario autenticado via GLPI (superadmin/TI)
    if (req.session.nagsa_auth === 'glpi') return null;
  }
  return estado;
}

module.exports = { requireLogin, requireAdmin, loginGLPI, loginAD, checkModulo, getGLPILdapConfig, resolveADConfig };
