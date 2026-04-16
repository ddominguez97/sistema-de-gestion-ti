const sql = require('mssql');
const fs = require('fs');
const path = require('path');

const CONFIG_FILE = path.join(__dirname, '..', '..', 'sistemas_settings.json');

let pool = null;

function getLocalDbConfig() {
  const raw = fs.readFileSync(CONFIG_FILE, 'utf8');
  const cfg = JSON.parse(raw);
  const db = cfg.local_db || {};
  const serverParts = (db.server || 'localhost\\SQLEXPRESS').split('\\');
  return {
    server: serverParts[0] || 'localhost',
    port: parseInt(db.port) || 1433,
    database: db.database || 'SistemaNG',
    user: db.user || '',
    password: db.password || '',
    options: {
      encrypt: false,
      trustServerCertificate: true,
      instanceName: serverParts[1] || 'SQLEXPRESS',
    },
    pool: {
      max: 10,
      min: 0,
      idleTimeoutMillis: 30000,
    },
  };
}

async function getPool() {
  if (pool) return pool;
  const config = getLocalDbConfig();
  pool = await sql.connect(config);
  console.log('Conectado a SQL Server:', config.server + '\\' + config.options.instanceName);
  return pool;
}

async function query(text, params) {
  const p = await getPool();
  const req = p.request();
  if (params) {
    for (const [key, val] of Object.entries(params)) {
      req.input(key, val);
    }
  }
  return req.query(text);
}

async function closePool() {
  if (pool) {
    await pool.close();
    pool = null;
  }
}

module.exports = { getPool, query, closePool, sql };
