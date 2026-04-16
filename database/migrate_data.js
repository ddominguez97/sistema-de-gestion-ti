/**
 * Migra datos de sistemas_settings.json y data/actas.json a SQL Server
 * Ejecutar: node database/migrate_data.js
 */
const path = require('path');
const fs = require('fs');
const sql = require('../app/node_modules/mssql');

const CONFIG_FILE = path.join(__dirname, '..', 'sistemas_settings.json');
const ACTAS_FILE = path.join(__dirname, '..', 'data', 'actas.json');

async function main() {
  // Leer archivos JSON
  const cfg = JSON.parse(fs.readFileSync(CONFIG_FILE, 'utf8'));
  const actasData = fs.existsSync(ACTAS_FILE) ? JSON.parse(fs.readFileSync(ACTAS_FILE, 'utf8')) : { actas: [] };

  // Conectar a SQL Server
  const db = cfg.local_db || {};
  const serverParts = (db.server || '').split('\\');
  const pool = await sql.connect({
    server: serverParts[0], port: 1433,
    database: db.database || 'SistemaNG',
    user: db.user, password: db.password,
    options: { encrypt: false, trustServerCertificate: true, instanceName: serverParts[1] || 'SQLEXPRESS' },
  });
  console.log('Conectado a SQL Server');

  // ========================
  // 1. CONFIGURACION
  // ========================
  console.log('\n--- Migrando configuracion ---');
  const configMap = {
    admin_pass: cfg.admin_pass,
    session_secret: cfg.session_secret,
    db_host: cfg.db_host,
    db_port: cfg.db_port,
    db_name: cfg.db_name,
    db_user: cfg.db_user,
    db_pass: cfg.db_pass,
    entity_id: String(cfg.entity_id || 0),
    base_url: cfg.base_url,
    zebra_ip: cfg.zebra_ip,
    zebra_nombre: cfg.zebra_nombre,
    zebra_port: cfg.zebra_port,
    empresa_nombre: cfg.empresa_nombre,
    empresa_color: cfg.empresa_color,
    empresa_logo: cfg.empresa_logo,
    empresa_tema: cfg.empresa_tema,
    ti_nombre_area: (cfg.permisos_config || {}).ti_nombre_area || '',
  };
  for (const [clave, valor] of Object.entries(configMap)) {
    await pool.request()
      .input('clave', clave).input('valor', valor || '')
      .query('UPDATE configuracion SET valor=@valor, updated_at=GETDATE() WHERE clave=@clave');
  }
  console.log('Configuracion: ' + Object.keys(configMap).length + ' claves actualizadas');

  // Categorias
  const show = cfg.show || {};
  for (const [cat, visible] of Object.entries(show)) {
    await pool.request()
      .input('cat', cat).input('vis', visible ? 1 : 0)
      .query('UPDATE configuracion_categorias SET visible=@vis WHERE categoria=@cat');
  }
  console.log('Categorias: ' + Object.keys(show).length + ' actualizadas');

  // Modulos
  const mods = cfg.modulos || {};
  for (const [mod, estado] of Object.entries(mods)) {
    await pool.request()
      .input('mod', mod).input('estado', estado)
      .query('UPDATE configuracion_modulos SET estado=@estado WHERE modulo=@mod');
  }
  console.log('Modulos: ' + Object.keys(mods).length + ' actualizados');

  // AD
  const ad = cfg.active_directory || {};
  await pool.request()
    .input('modo', ad.modo || 'automatica')
    .input('hab', ad.habilitado ? 1 : 0)
    .input('nombre', ad.nombre || 'Active Directory')
    .input('servidor', ad.servidor || '')
    .input('puerto', ad.puerto || 389)
    .input('dominio', ad.dominio || '')
    .input('base_dn', ad.base_dn || '')
    .input('sufijo', ad.sufijo_usuario || '')
    .query('UPDATE configuracion_ad SET modo=@modo, habilitado=@hab, nombre=@nombre, servidor=@servidor, puerto=@puerto, dominio=@dominio, base_dn=@base_dn, sufijo_usuario=@sufijo WHERE id=1');
  console.log('Active Directory: actualizado');

  // ========================
  // 2. PERMISOS
  // ========================
  console.log('\n--- Migrando permisos ---');
  const pc = cfg.permisos_config || {};

  // TI usuarios
  const tiUsers = pc.ti_usuarios || {};
  for (const [user, info] of Object.entries(tiUsers)) {
    await pool.request()
      .input('user', user).input('nombre', info.nombre || user)
      .input('admin', info.admin_panel ? 1 : 0)
      .input('delegar', info.puede_delegar ? 1 : 0)
      .query('IF NOT EXISTS (SELECT 1 FROM permisos_ti WHERE username=@user) INSERT INTO permisos_ti (username,nombre,admin_panel,puede_delegar) VALUES (@user,@nombre,@admin,@delegar)');
  }
  console.log('TI usuarios: ' + Object.keys(tiUsers).length + ' migrados');

  // Grupos
  const grupos = pc.grupos || {};
  for (const [gid, grupo] of Object.entries(grupos)) {
    const perms = grupo.permisos || {};
    const result = await pool.request()
      .input('nombre', grupo.nombre)
      .input('actas', perms.actas ? 1 : 0)
      .input('reportes', perms.reportes ? 1 : 0)
      .input('entrega', perms.crear_entrega ? 1 : 0)
      .query('INSERT INTO permisos_grupos (nombre,perm_actas,perm_reportes,perm_crear_entrega) OUTPUT INSERTED.id VALUES (@nombre,@actas,@reportes,@entrega)');
    const nuevoId = result.recordset[0].id;

    // Jefes
    const jefes = grupo.jefes || [];
    // Compatibilidad formato viejo
    if (!jefes.length && grupo.jefe) {
      jefes.push({ username: grupo.jefe, nombre: grupo.jefe_nombre || grupo.jefe });
    }
    for (const j of jefes) {
      await pool.request()
        .input('gid', nuevoId).input('user', j.username).input('nombre', j.nombre || j.username)
        .query('INSERT INTO permisos_grupo_jefes (grupo_id,username,nombre) VALUES (@gid,@user,@nombre)');
    }

    // Miembros
    for (const m of (grupo.miembros || [])) {
      const mUser = typeof m === 'string' ? m : m.username;
      const mNombre = typeof m === 'string' ? m : (m.nombre || m.username);
      await pool.request()
        .input('gid', nuevoId).input('user', mUser).input('nombre', mNombre)
        .query('INSERT INTO permisos_grupo_miembros (grupo_id,username,nombre) VALUES (@gid,@user,@nombre)');
    }

    console.log('Grupo "' + grupo.nombre + '": ' + jefes.length + ' jefes, ' + (grupo.miembros || []).length + ' miembros');
  }

  // Motivos de salida
  const motivos = pc.motivos_salida || [];
  for (let i = 0; i < motivos.length; i++) {
    await pool.request()
      .input('nombre', motivos[i]).input('orden', i)
      .query('INSERT INTO motivos_salida (nombre,orden) VALUES (@nombre,@orden)');
  }
  console.log('Motivos de salida: ' + motivos.length + ' migrados');

  // ========================
  // 3. ACTAS
  // ========================
  console.log('\n--- Migrando actas ---');
  const actas = actasData.actas || [];
  for (const a of actas) {
    const result = await pool.request()
      .input('numero', a.numero)
      .input('tipo', a.tipo)
      .input('fecha', a.fecha)
      .input('lugar', a.lugar)
      .input('destino', a.destino)
      .input('entregado_por', a.entregado_por)
      .input('entregado_cargo', a.entregado_cargo)
      .input('entregado_username', a.entregado_username)
      .input('recibido_por', a.recibido_por)
      .input('recibido_cargo', a.recibido_cargo)
      .input('recibido_username', a.recibido_username)
      .input('autorizado_por', a.autorizado_por)
      .input('autorizado_cargo', a.autorizado_cargo)
      .input('motivo', a.motivo)
      .input('retira_persona', a.retira_persona)
      .input('retira_cargo', a.retira_cargo)
      .input('retira_username', a.retira_username)
      .input('observaciones', a.observaciones)
      .input('total_equipos', a.total_equipos || 0)
      .input('estado', a.estado || 'pendiente')
      .input('aceptada_por', a.aceptada_por)
      .input('aceptada_fecha', a.aceptada_fecha ? new Date(a.aceptada_fecha) : null)
      .input('aceptada_obs', a.aceptada_observaciones)
      .input('firma', a.firma_digital)
      .input('created_by', a.created_by)
      .input('created_at', a.created_at ? new Date(a.created_at) : new Date())
      .input('updated_at', a.updated_at ? new Date(a.updated_at) : new Date())
      .query(`INSERT INTO actas (numero,tipo,fecha,lugar,destino,entregado_por,entregado_cargo,entregado_username,
              recibido_por,recibido_cargo,recibido_username,autorizado_por,autorizado_cargo,motivo,
              retira_persona,retira_cargo,retira_username,observaciones,total_equipos,estado,
              aceptada_por,aceptada_fecha,aceptada_observaciones,firma_digital,created_by,created_at,updated_at)
              OUTPUT INSERTED.id
              VALUES (@numero,@tipo,@fecha,@lugar,@destino,@entregado_por,@entregado_cargo,@entregado_username,
              @recibido_por,@recibido_cargo,@recibido_username,@autorizado_por,@autorizado_cargo,@motivo,
              @retira_persona,@retira_cargo,@retira_username,@observaciones,@total_equipos,@estado,
              @aceptada_por,@aceptada_fecha,@aceptada_obs,@firma,@created_by,@created_at,@updated_at)`);
    const actaId = result.recordset[0].id;

    // Equipos
    for (const eq of (a.equipos || [])) {
      await pool.request()
        .input('aid', actaId)
        .input('nombre', eq.nombre || '')
        .input('tipo', eq.tipo || '')
        .input('fab', eq.fabricante || '')
        .input('modelo', eq.modelo || '')
        .input('serie', eq.serie || '')
        .input('estado', eq.estado || '')
        .input('stock', eq.stock || 0)
        .query('INSERT INTO acta_equipos (acta_id,nombre,tipo,fabricante,modelo,serie,estado,stock) VALUES (@aid,@nombre,@tipo,@fab,@modelo,@serie,@estado,@stock)');
    }

    // Recordatorios
    for (const r of (a.recordatorios || [])) {
      await pool.request()
        .input('aid', actaId)
        .input('fecha', r.fecha ? new Date(r.fecha) : new Date())
        .input('enviado', r.enviado_por)
        .query('INSERT INTO acta_recordatorios (acta_id,fecha,enviado_por) VALUES (@aid,@fecha,@enviado)');
    }
  }
  console.log('Actas: ' + actas.length + ' migradas con equipos y recordatorios');

  // ========================
  console.log('\n=== Migracion completada ===');
  await pool.close();
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
