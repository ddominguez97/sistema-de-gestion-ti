-- ============================================================
--  SistemaNG -- Script de migracion completo
--  Generado: 2026-05-19
--  Uso: ejecutar en el nuevo host contra una BD vacia
--       CREATE DATABASE SistemaNG; USE SistemaNG;
-- ============================================================

USE SistemaNG;
GO

-- ------------------------------------------------------------
-- 0. Eliminar tablas existentes (orden inverso por FK)
-- ------------------------------------------------------------
IF OBJECT_ID('acta_recordatorios','U') IS NOT NULL DROP TABLE acta_recordatorios;
IF OBJECT_ID('acta_equipos','U')       IS NOT NULL DROP TABLE acta_equipos;
IF OBJECT_ID('actas','U')              IS NOT NULL DROP TABLE actas;
IF OBJECT_ID('equipos_asignados','U')  IS NOT NULL DROP TABLE equipos_asignados;
IF OBJECT_ID('inversiones_items','U')  IS NOT NULL DROP TABLE inversiones_items;
IF OBJECT_ID('inversiones','U')        IS NOT NULL DROP TABLE inversiones;
IF OBJECT_ID('inversiones_config','U') IS NOT NULL DROP TABLE inversiones_config;
IF OBJECT_ID('permisos_grupo_jefes','U')   IS NOT NULL DROP TABLE permisos_grupo_jefes;
IF OBJECT_ID('permisos_grupo_miembros','U') IS NOT NULL DROP TABLE permisos_grupo_miembros;
IF OBJECT_ID('permisos_grupos','U')    IS NOT NULL DROP TABLE permisos_grupos;
IF OBJECT_ID('permisos_ti','U')        IS NOT NULL DROP TABLE permisos_ti;
IF OBJECT_ID('motivos_salida','U')     IS NOT NULL DROP TABLE motivos_salida;
IF OBJECT_ID('configuracion_ad','U')   IS NOT NULL DROP TABLE configuracion_ad;
IF OBJECT_ID('configuracion_modulos','U') IS NOT NULL DROP TABLE configuracion_modulos;
IF OBJECT_ID('configuracion_categorias','U') IS NOT NULL DROP TABLE configuracion_categorias;
IF OBJECT_ID('configuracion','U')      IS NOT NULL DROP TABLE configuracion;
GO

-- ============================================================
-- 1. TABLAS DE CONFIGURACION
-- ============================================================

CREATE TABLE configuracion (
    id          INT IDENTITY(1,1) PRIMARY KEY,
    clave       NVARCHAR(100) NOT NULL,
    valor       NVARCHAR(MAX),
    tipo        NVARCHAR(20),
    descripcion NVARCHAR(255),
    updated_at  DATETIME DEFAULT GETDATE(),
    CONSTRAINT UQ_configuracion_clave UNIQUE (clave)
);

CREATE TABLE configuracion_categorias (
    id        INT IDENTITY(1,1) PRIMARY KEY,
    categoria NVARCHAR(50) NOT NULL,
    visible   BIT,
    CONSTRAINT UQ_configuracion_categorias_categoria UNIQUE (categoria)
);

CREATE TABLE configuracion_modulos (
    id     INT IDENTITY(1,1) PRIMARY KEY,
    modulo NVARCHAR(50) NOT NULL,
    estado NVARCHAR(20),
    CONSTRAINT UQ_configuracion_modulos_modulo UNIQUE (modulo)
);

CREATE TABLE configuracion_ad (
    id             INT IDENTITY(1,1) PRIMARY KEY,
    modo           NVARCHAR(20)  DEFAULT 'automatica',
    habilitado     BIT           DEFAULT 1,
    nombre         NVARCHAR(100),
    servidor       NVARCHAR(255),
    puerto         INT           DEFAULT 389,
    dominio        NVARCHAR(255),
    base_dn        NVARCHAR(500),
    sufijo_usuario NVARCHAR(100),
    bind_dn        NVARCHAR(500) NULL,
    bind_password  NVARCHAR(500) NULL
);

CREATE TABLE motivos_salida (
    id     INT IDENTITY(1,1) PRIMARY KEY,
    nombre NVARCHAR(200) NOT NULL,
    activo BIT           DEFAULT 1,
    orden  INT           DEFAULT 0,
    tipo   NVARCHAR(20)
);

GO

-- ============================================================
-- 2. TABLAS DE PERMISOS
-- ============================================================

CREATE TABLE permisos_ti (
    id             INT IDENTITY(1,1) PRIMARY KEY,
    username       NVARCHAR(100) NOT NULL,
    nombre         NVARCHAR(200),
    admin_panel    BIT           DEFAULT 0,
    puede_delegar  BIT           DEFAULT 0,
    created_at     DATETIME      DEFAULT GETDATE(),
    CONSTRAINT UQ_permisos_ti_username UNIQUE (username)
);

CREATE TABLE permisos_grupos (
    id                        INT IDENTITY(1,1) PRIMARY KEY,
    nombre                    NVARCHAR(200) NOT NULL,
    empresa                   NVARCHAR(200) NULL,
    perm_actas                BIT DEFAULT 0,
    perm_reportes             BIT DEFAULT 0,
    perm_crear_entrega        BIT DEFAULT 0,
    puede_aprobar_cotizacion  BIT NOT NULL DEFAULT 0,
    puede_aprobar_pago        BIT NOT NULL DEFAULT 0,
    puede_marcar_pagado       BIT NOT NULL DEFAULT 0,
    ver_actas                 BIT NULL,
    ver_etiquetas             BIT NULL,
    ver_inversiones           BIT NULL,
    ver_reportes              BIT NULL,
    ver_permisos              BIT NULL,
    created_at                DATETIME DEFAULT GETDATE(),
    updated_at                DATETIME DEFAULT GETDATE()
);

CREATE TABLE permisos_grupo_jefes (
    id         INT IDENTITY(1,1) PRIMARY KEY,
    grupo_id   INT           NOT NULL,
    username   NVARCHAR(100) NOT NULL,
    nombre     NVARCHAR(200),
    created_at DATETIME DEFAULT GETDATE(),
    CONSTRAINT FK_grupo_jefes_grupo FOREIGN KEY (grupo_id) REFERENCES permisos_grupos(id),
    CONSTRAINT UQ_grupo_jefes UNIQUE (grupo_id, username)
);
CREATE INDEX IX_permisos_grupo_jefes_username ON permisos_grupo_jefes(username);

CREATE TABLE permisos_grupo_miembros (
    id         INT IDENTITY(1,1) PRIMARY KEY,
    grupo_id   INT           NOT NULL,
    username   NVARCHAR(100) NOT NULL,
    nombre     NVARCHAR(200),
    empresa    NVARCHAR(200) NULL,
    created_at DATETIME DEFAULT GETDATE(),
    CONSTRAINT FK_grupo_miembros_grupo FOREIGN KEY (grupo_id) REFERENCES permisos_grupos(id),
    CONSTRAINT UQ_grupo_miembros UNIQUE (grupo_id, username)
);
CREATE INDEX IX_permisos_grupo_miembros_username ON permisos_grupo_miembros(username);

GO

-- ============================================================
-- 3. TABLAS DE ACTAS
-- ============================================================

CREATE TABLE actas (
    id                     INT IDENTITY(1,1) PRIMARY KEY,
    numero                 NVARCHAR(20)  NOT NULL,
    tipo                   NVARCHAR(20)  NOT NULL,
    fecha                  DATE          NOT NULL,
    lugar                  NVARCHAR(200),
    destino                NVARCHAR(200),
    entregado_por          NVARCHAR(200),
    entregado_cargo        NVARCHAR(200),
    entregado_username     NVARCHAR(100),
    recibido_por           NVARCHAR(200),
    recibido_cargo         NVARCHAR(200),
    recibido_username      NVARCHAR(100),
    autorizado_por         NVARCHAR(200),
    autorizado_cargo       NVARCHAR(200),
    motivo                 NVARCHAR(200),
    retira_persona         NVARCHAR(200),
    retira_cargo           NVARCHAR(200),
    retira_username        NVARCHAR(100),
    observaciones          NVARCHAR(MAX),
    total_equipos          INT,
    estado                 NVARCHAR(30),
    aceptada_por           NVARCHAR(200),
    aceptada_fecha         DATETIME,
    aceptada_observaciones NVARCHAR(MAX),
    firma_digital          NVARCHAR(MAX),
    created_by             NVARCHAR(100),
    created_at             DATETIME DEFAULT GETDATE(),
    updated_at             DATETIME DEFAULT GETDATE(),
    CONSTRAINT UQ_actas_numero UNIQUE (numero)
);
CREATE INDEX IX_actas_tipo           ON actas(tipo);
CREATE INDEX IX_actas_estado         ON actas(estado);
CREATE INDEX IX_actas_fecha          ON actas(fecha);
CREATE INDEX IX_actas_created_by     ON actas(created_by);
CREATE INDEX IX_actas_recibido_username ON actas(recibido_username);
CREATE INDEX IX_actas_retira_username   ON actas(retira_username);

CREATE TABLE acta_equipos (
    id         INT IDENTITY(1,1) PRIMARY KEY,
    acta_id    INT           NOT NULL,
    nombre     NVARCHAR(200) NOT NULL,
    tipo       NVARCHAR(100),
    fabricante NVARCHAR(200),
    modelo     NVARCHAR(200),
    serie      NVARCHAR(200),
    estado     NVARCHAR(100),
    stock      INT,
    CONSTRAINT FK_acta_equipos_acta FOREIGN KEY (acta_id) REFERENCES actas(id)
);
CREATE INDEX IX_acta_equipos_acta_id ON acta_equipos(acta_id);

CREATE TABLE acta_recordatorios (
    id          INT IDENTITY(1,1) PRIMARY KEY,
    acta_id     INT           NOT NULL,
    fecha       DATETIME,
    enviado_por NVARCHAR(100),
    CONSTRAINT FK_acta_recordatorios_acta FOREIGN KEY (acta_id) REFERENCES actas(id)
);

GO

-- ============================================================
-- 4. TABLA EQUIPOS ASIGNADOS
-- ============================================================

CREATE TABLE equipos_asignados (
    id               INT IDENTITY(1,1) PRIMARY KEY,
    glpi_item_id     INT           NULL,
    glpi_tabla       NVARCHAR(100) NULL,
    nombre           NVARCHAR(255) NOT NULL,
    tipo             NVARCHAR(100) NULL,
    fabricante       NVARCHAR(100) NULL,
    modelo           NVARCHAR(100) NULL,
    serie            NVARCHAR(100) NULL,
    usuario_nombre   NVARCHAR(255) NULL,
    usuario_username NVARCHAR(100) NULL,
    acta_numero      NVARCHAR(50)  NULL,
    acta_id          INT           NULL,
    estado           NVARCHAR(50)  NOT NULL DEFAULT 'activo',
    fecha_entrega    DATE          NULL,
    created_at       DATETIME DEFAULT GETDATE(),
    updated_at       DATETIME DEFAULT GETDATE()
);

GO

-- ============================================================
-- 5. TABLAS DE INVERSIONES
-- ============================================================

CREATE TABLE inversiones_config (
    id      INT IDENTITY(1,1) PRIMARY KEY,
    empresa NVARCHAR(100) NOT NULL,
    flujo   CHAR(1)       NOT NULL,
    activo  BIT           NOT NULL DEFAULT 1,
    CONSTRAINT UQ_inversiones_config_empresa UNIQUE (empresa)
);

CREATE TABLE inversiones (
    id                       INT IDENTITY(1,1) PRIMARY KEY,
    empresa                  NVARCHAR(100) NOT NULL,
    solicitado_por           NVARCHAR(100) NOT NULL,
    solicitado_nombre        NVARCHAR(200) NOT NULL,
    descripcion              NVARCHAR(500) NOT NULL,
    justificacion            NVARCHAR(500),
    estado                   NVARCHAR(50)  NOT NULL,
    flujo                    CHAR(1)       NOT NULL,
    cotizacion_monto         DECIMAL(18,2),
    cotizacion_proveedor     NVARCHAR(200),
    cotizacion_detalle       NVARCHAR(500),
    cotizado_por             NVARCHAR(100),
    cotizado_at              DATETIME,
    aprobado_cotizacion_por  NVARCHAR(100),
    aprobado_cotizacion_at   DATETIME,
    aprobado_pago_por        NVARCHAR(100),
    aprobado_pago_at         DATETIME,
    marcado_pagado_por       NVARCHAR(100),
    marcado_pagado_at        DATETIME,
    rechazado_por            NVARCHAR(100),
    rechazado_at             DATETIME,
    motivo_rechazo           NVARCHAR(300),
    observaciones            NVARCHAR(500),
    cotizacion_archivo       NVARCHAR(500),
    factura_archivo          NVARCHAR(500),
    factura_subida_por       NVARCHAR(100),
    factura_subida_at        DATETIME,
    recordatorio_ti_at       DATETIME,
    recordatorio_ti_por      NVARCHAR(100),
    entregado_desde_stock    BIT,
    glpi_consumible_id       INT,
    glpi_consumible_nombre   NVARCHAR(255),
    entregado_por            NVARCHAR(100),
    entregado_at             DATETIME,
    solicitud_adjunto        NVARCHAR(MAX),
    created_at               DATETIME NOT NULL DEFAULT GETDATE()
);

CREATE TABLE inversiones_items (
    id           INT IDENTITY(1,1) PRIMARY KEY,
    inversion_id INT           NOT NULL,
    descripcion  NVARCHAR(500) NOT NULL,
    cantidad     INT,
    CONSTRAINT FK_inversiones_items_inv FOREIGN KEY (inversion_id) REFERENCES inversiones(id)
);

GO

CREATE TABLE catalogo_articulos (
    id         INT IDENTITY(1,1) PRIMARY KEY,
    nombre     NVARCHAR(200) NOT NULL,
    categoria  NVARCHAR(100) NOT NULL,
    activo     BIT NOT NULL DEFAULT 1,
    orden      INT NOT NULL DEFAULT 0,
    created_at DATETIME2 DEFAULT GETDATE()
);

GO

-- ============================================================
-- 6. DATOS DE CONFIGURACION
-- ============================================================

SET IDENTITY_INSERT configuracion ON;
INSERT INTO configuracion (id, clave, valor, tipo, descripcion) VALUES
(1,  'admin_pass',      'GLPIM853@UYT',             'texto',  'Contrasena del panel admin'),
(2,  'session_secret',  'sistema-ng-secret-2024',   'texto',  'Secret para sesiones Express'),
(3,  'db_host',         '192.168.104.193',           'texto',  'Host de la BD GLPI (MySQL)'),
(4,  'db_port',         '3306',                     'texto',  'Puerto de la BD GLPI'),
(5,  'db_name',         'glpidb',                   'texto',  'Nombre de la BD GLPI'),
(6,  'db_user',         'glpiuser',                 'texto',  'Usuario de la BD GLPI'),
(7,  'db_pass',         'glpi_db_pass',             'texto',  'Contrasena de la BD GLPI'),
(8,  'entity_id',       '0',                        'numero', 'Entidad GLPI'),
(9,  'base_url',        'https://glpi.nagsa.com.ec','texto',  'URL base de GLPI'),
(10, 'zebra_ip',        '192.168.106.87',           'texto',  'IP de impresora Zebra'),
(11, 'zebra_nombre',    'D5N231202429',             'texto',  'Nombre de impresora Zebra'),
(12, 'zebra_port',      '9100',                     'texto',  'Puerto de impresora Zebra'),
(13, 'empresa_nombre',  'GRUPO NAGSA',              'texto',  'Nombre de la empresa'),
(14, 'empresa_color',   '#e05816',                  'texto',  'Color principal'),
(15, 'empresa_logo',    'logo_empresa.png',         'texto',  'Archivo del logo'),
(16, 'empresa_tema',    'claro',                    'texto',  'Tema: claro/oscuro'),
(17, 'ti_nombre_area',  'NAGSA IT',                 'texto',  'Nombre del area TI para actas');
SET IDENTITY_INSERT configuracion OFF;
GO

SET IDENTITY_INSERT configuracion_categorias ON;
INSERT INTO configuracion_categorias (id, categoria, visible) VALUES
(1, 'computadoras', 1),(2, 'monitores',   1),(3, 'impresoras',  1),
(4, 'perifericos',  1),(5, 'redes',        1),(6, 'gabinetes',   1),
(7, 'pasivos',      1),(8, 'cartuchos',    1),(9, 'consumibles', 1),
(10,'telefonos',    1);
SET IDENTITY_INSERT configuracion_categorias OFF;
GO

SET IDENTITY_INSERT configuracion_modulos ON;
INSERT INTO configuracion_modulos (id, modulo, estado) VALUES
(1, 'etiquetas',         'activo'),
(2, 'actas',             'activo'),
(3, 'reportes',          'activo'),
(4, 'inversiones',       'activo'),
(5, 'permisos',          'activo'),
(8, 'equipos_asignados', 'activo');
SET IDENTITY_INSERT configuracion_modulos OFF;
GO

-- NOTA: bind_password en texto plano — cambiar si es necesario
SET IDENTITY_INSERT configuracion_ad ON;
INSERT INTO configuracion_ad (id, modo, habilitado, nombre, servidor, puerto, dominio, base_dn, sufijo_usuario, bind_dn, bind_password) VALUES
(1, 'automatica', 1, 'Grupo NAGSA', '192.168.104.232', 389,
 'Grupo NAGSA', 'DC=DURAN,DC=GRUPONAGSA,DC=EC', '',
 'CN=Administrador,CN=Users,DC=DURAN,DC=GRUPONAGSA,DC=EC',
 'M853@UYT');
SET IDENTITY_INSERT configuracion_ad OFF;
GO

SET IDENTITY_INSERT motivos_salida ON;
INSERT INTO motivos_salida (id, nombre, activo, orden, tipo) VALUES
(1, 'Equipo dañado',       1, 0, 'externo'),
(2, 'Teletrabajo',         1, 1, 'interno'),
(3, 'Prestamo',            1, 2, 'interno'),
(4, 'Cambio de ubicacion', 1, 3, 'interno');
SET IDENTITY_INSERT motivos_salida OFF;
GO

-- ============================================================
-- 7. DATOS DE PERMISOS
-- ============================================================

SET IDENTITY_INSERT permisos_ti ON;
INSERT INTO permisos_ti (id, username, nombre, admin_panel, puede_delegar) VALUES
(1, 'christian.lopez',  'Christian Lopez',  1, 1),
(2, 'jonathan.perez',   'Jonathan Perez',   1, 1),
(3, 'joaquin.cabrera',  'Joaquin Cabrera',  0, 1),
(4, 'darwin.dominguez', 'Darwin Dominguez', 1, 1);
SET IDENTITY_INSERT permisos_ti OFF;
GO

SET IDENTITY_INSERT permisos_grupos ON;
INSERT INTO permisos_grupos (id, nombre, empresa, perm_actas, perm_reportes, perm_crear_entrega, puede_aprobar_cotizacion, puede_aprobar_pago, puede_marcar_pagado, ver_actas, ver_etiquetas, ver_inversiones, ver_reportes, ver_permisos) VALUES
(1,  'Manufactura', 'Duracolor',   1, 1, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL),
(2,  'Pintura',     'Duracolor',   1, 1, 0, 0, 0, 0, NULL, NULL, NULL, NULL, NULL),
(4,  'Gerencia',    NULL,          1, 1, 0, 1, 1, 0,    1, NULL,    1,    1, NULL),
(5,  'Contadora',   NULL,          1, 1, 0, 0, 0, 1,    1, NULL,    1, NULL, NULL),
(6,  'Oficina',     'Vetriko',     1, 1, 0, 0, 0, 0,    0,    0,    0,    0,    0),
(7,  'Oficina',     'WindowWorld', 1, 1, 0, 0, 0, 0,    0,    0,    0,    0,    0),
(8,  'Oficina',     'Proalum',     1, 1, 0, 0, 0, 0,    0,    0,    0,    0,    0),
(9,  'Oficina',     'Duralum',     1, 1, 0, 0, 0, 0,    0,    0,    0,    0,    0),
(10, 'Oficina',     'Nagsa',       1, 1, 0, 0, 0, 0,    0,    0,    0,    0,    0),
(11, 'Planta',      'Vetriko',     1, 1, 0, 0, 0, 0,    0,    0,    0,    0,    0);
SET IDENTITY_INSERT permisos_grupos OFF;
GO

SET IDENTITY_INSERT permisos_grupo_jefes ON;
INSERT INTO permisos_grupo_jefes (id, grupo_id, username, nombre) VALUES
(1, 1, 'exar.lamota',  'Exar Lamota'),
(2, 1, 'luis.lascano', 'Luis Lascano'),
(3, 1, 'erick.tumbaco','Erick Tumbaco'),
(4, 2, 'luis.haro',    'Luis Carlos Haro');
SET IDENTITY_INSERT permisos_grupo_jefes OFF;
GO

SET IDENTITY_INSERT permisos_grupo_miembros ON;
INSERT INTO permisos_grupo_miembros (id, grupo_id, username, nombre, empresa) VALUES
(1,  1, 'dc.mecanizado',   'dc.mecanizado',         NULL),
(2,  4, 'gonzalo.kozhaya', 'Gonzalo Kozhaya',        NULL),
(3,  4, 'andrea.kozhaya',  'Andrea Kozhaya',         NULL),
(4,  4, 'andres.kozhaya',  'Andres Kozhaya',         NULL),
(5,  4, 'ignacio.kozhaya', 'Ignacio Kozhaya',        NULL),
(7,  5, 'cinthya.cheve',   'Cinthya Cheve',          'WindowWorld'),
(8,  5, 'maythe.pincay',   'Maythe Pincay',          'WindowWorld'),
(9,  5, 'margarita.vargas','Margarita Vargas',        'Vetriko'),
(10, 5, 'jamilet.zambrano','Jamilet Zambrano',        'Vetriko'),
(11, 5, 'roxanna.farfan',  'Roxana Farfan',           'Proalum'),
(12, 5, 'jonathan.flores', 'Jonathan Flores',         'Proalum'),
(14, 5, 'betsy.yagual',    'Betsy Yagual',            'Duralum'),
(15, 5, 'andrea.parrales', 'Andrea Parrales',         'Duracolor'),
(16, 5, 'raisa.benitez',   'Raisa Benitez',           'Duracolor'),
(17, 5, 'adriana.giler',   'Adriana Giler',           'Duracolor'),
(18, 5, 'veronica.santos', 'Veronica Santos',         'Nagsa'),
(19, 5, 'johanna.munoz',   N'Johanna Muñoz DURALUM', 'Duralum');
SET IDENTITY_INSERT permisos_grupo_miembros OFF;
GO

-- ============================================================
-- 8. DATOS OPERATIVOS (actas, inversiones, equipos_asignados)
--    Exportar con SSMS: clic derecho BD > Tasks > Generate Scripts
--    o usar bcp / SQL Server Import-Export Wizard
-- ============================================================
-- PENDIENTE: migrar manualmente los datos de:
--   - actas
--   - acta_equipos
--   - acta_recordatorios
--   - equipos_asignados
--   - inversiones
--   - inversiones_items
-- ============================================================

PRINT 'Migracion SistemaNG completada correctamente.';
GO
