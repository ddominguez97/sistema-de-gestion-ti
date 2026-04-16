-- =============================================
-- Sistema NG - Base de datos SQL Server Express
-- =============================================

-- Crear base de datos
IF NOT EXISTS (SELECT name FROM sys.databases WHERE name = 'SistemaNG')
BEGIN
    CREATE DATABASE SistemaNG;
END
GO

USE SistemaNG;
GO

-- =============================================
-- CONFIGURACION DEL SISTEMA
-- =============================================

CREATE TABLE configuracion (
    id INT IDENTITY(1,1) PRIMARY KEY,
    clave NVARCHAR(100) NOT NULL UNIQUE,
    valor NVARCHAR(MAX),
    tipo NVARCHAR(20) DEFAULT 'texto', -- texto, numero, booleano, json
    descripcion NVARCHAR(255),
    updated_at DATETIME DEFAULT GETDATE()
);
GO

-- Insertar configuracion inicial
INSERT INTO configuracion (clave, valor, tipo, descripcion) VALUES
('admin_pass', '', 'texto', 'Contrasena del panel admin'),
('session_secret', '', 'texto', 'Secret para sesiones Express'),
('db_host', '', 'texto', 'Host de la BD GLPI (MySQL)'),
('db_port', '3306', 'texto', 'Puerto de la BD GLPI'),
('db_name', '', 'texto', 'Nombre de la BD GLPI'),
('db_user', '', 'texto', 'Usuario de la BD GLPI'),
('db_pass', '', 'texto', 'Contrasena de la BD GLPI'),
('entity_id', '0', 'numero', 'Entidad GLPI'),
('base_url', '', 'texto', 'URL base de GLPI'),
('zebra_ip', '', 'texto', 'IP de impresora Zebra'),
('zebra_nombre', '', 'texto', 'Nombre de impresora Zebra'),
('zebra_port', '9100', 'texto', 'Puerto de impresora Zebra'),
('empresa_nombre', 'NAGSA', 'texto', 'Nombre de la empresa'),
('empresa_color', '#e05816', 'texto', 'Color principal'),
('empresa_logo', 'logo_empresa.png', 'texto', 'Archivo del logo'),
('empresa_tema', 'oscuro', 'texto', 'Tema: claro/oscuro'),
('ti_nombre_area', '', 'texto', 'Nombre del area TI para actas');
GO

-- Categorias visibles en etiquetas
CREATE TABLE configuracion_categorias (
    id INT IDENTITY(1,1) PRIMARY KEY,
    categoria NVARCHAR(50) NOT NULL UNIQUE,
    visible BIT DEFAULT 1
);
GO

INSERT INTO configuracion_categorias (categoria, visible) VALUES
('computadoras', 1), ('monitores', 1), ('impresoras', 1),
('perifericos', 1), ('redes', 1), ('gabinetes', 1),
('pasivos', 1), ('cartuchos', 1), ('consumibles', 1), ('telefonos', 1);
GO

-- Estado de modulos
CREATE TABLE configuracion_modulos (
    id INT IDENTITY(1,1) PRIMARY KEY,
    modulo NVARCHAR(50) NOT NULL UNIQUE,
    estado NVARCHAR(20) DEFAULT 'deshabilitado' -- activo, pruebas, deshabilitado
);
GO

INSERT INTO configuracion_modulos (modulo, estado) VALUES
('etiquetas', 'activo'), ('actas', 'activo'), ('reportes', 'activo'),
('inversiones', 'deshabilitado'), ('permisos', 'pruebas');
GO

-- Active Directory config
CREATE TABLE configuracion_ad (
    id INT IDENTITY(1,1) PRIMARY KEY,
    modo NVARCHAR(20) DEFAULT 'automatica',
    habilitado BIT DEFAULT 1,
    nombre NVARCHAR(100) DEFAULT 'Active Directory',
    servidor NVARCHAR(255) DEFAULT '',
    puerto INT DEFAULT 389,
    dominio NVARCHAR(255) DEFAULT '',
    base_dn NVARCHAR(500) DEFAULT '',
    sufijo_usuario NVARCHAR(100) DEFAULT ''
);
GO

INSERT INTO configuracion_ad (modo, habilitado) VALUES ('automatica', 1);
GO

-- =============================================
-- PERMISOS Y NIVELES
-- =============================================

-- Usuarios TI (Nivel 2)
CREATE TABLE permisos_ti (
    id INT IDENTITY(1,1) PRIMARY KEY,
    username NVARCHAR(100) NOT NULL UNIQUE,
    nombre NVARCHAR(200),
    admin_panel BIT DEFAULT 0,
    puede_delegar BIT DEFAULT 0,
    created_at DATETIME DEFAULT GETDATE()
);
GO

-- Grupos / Areas
CREATE TABLE permisos_grupos (
    id INT IDENTITY(1,1) PRIMARY KEY,
    nombre NVARCHAR(200) NOT NULL,
    perm_actas BIT DEFAULT 1,
    perm_reportes BIT DEFAULT 1,
    perm_crear_entrega BIT DEFAULT 0,
    created_at DATETIME DEFAULT GETDATE(),
    updated_at DATETIME DEFAULT GETDATE()
);
GO

-- Jefes de grupo (Nivel 3) - multiples por grupo
CREATE TABLE permisos_grupo_jefes (
    id INT IDENTITY(1,1) PRIMARY KEY,
    grupo_id INT NOT NULL REFERENCES permisos_grupos(id) ON DELETE CASCADE,
    username NVARCHAR(100) NOT NULL,
    nombre NVARCHAR(200),
    created_at DATETIME DEFAULT GETDATE(),
    UNIQUE(grupo_id, username)
);
GO

-- Miembros de grupo (Nivel 4)
CREATE TABLE permisos_grupo_miembros (
    id INT IDENTITY(1,1) PRIMARY KEY,
    grupo_id INT NOT NULL REFERENCES permisos_grupos(id) ON DELETE CASCADE,
    username NVARCHAR(100) NOT NULL,
    nombre NVARCHAR(200),
    created_at DATETIME DEFAULT GETDATE(),
    UNIQUE(grupo_id, username)
);
GO

-- Motivos de salida configurables
CREATE TABLE motivos_salida (
    id INT IDENTITY(1,1) PRIMARY KEY,
    nombre NVARCHAR(200) NOT NULL,
    activo BIT DEFAULT 1,
    orden INT DEFAULT 0
);
GO

-- =============================================
-- ACTAS
-- =============================================

CREATE TABLE actas (
    id INT IDENTITY(1,1) PRIMARY KEY,
    numero NVARCHAR(20) NOT NULL UNIQUE,
    tipo NVARCHAR(20) NOT NULL, -- entrega, salida
    fecha DATE NOT NULL,
    lugar NVARCHAR(200),
    destino NVARCHAR(200),

    -- Personas involucradas
    entregado_por NVARCHAR(200),
    entregado_cargo NVARCHAR(200),
    entregado_username NVARCHAR(100),
    recibido_por NVARCHAR(200),
    recibido_cargo NVARCHAR(200),
    recibido_username NVARCHAR(100),
    autorizado_por NVARCHAR(200),
    autorizado_cargo NVARCHAR(200),

    -- Salida especifico
    motivo NVARCHAR(200),
    retira_persona NVARCHAR(200),
    retira_cargo NVARCHAR(200),
    retira_username NVARCHAR(100),

    -- General
    observaciones NVARCHAR(MAX),
    total_equipos INT DEFAULT 0,

    -- Estado: pendiente, pendiente_autorizacion, aceptada, autorizada, rechazada
    estado NVARCHAR(30) DEFAULT 'pendiente',
    aceptada_por NVARCHAR(200),
    aceptada_fecha DATETIME,
    aceptada_observaciones NVARCHAR(MAX),
    firma_digital NVARCHAR(MAX), -- base64 de la firma

    -- Auditoria
    created_by NVARCHAR(100),
    created_at DATETIME DEFAULT GETDATE(),
    updated_at DATETIME DEFAULT GETDATE()
);
GO

-- Equipos por acta
CREATE TABLE acta_equipos (
    id INT IDENTITY(1,1) PRIMARY KEY,
    acta_id INT NOT NULL REFERENCES actas(id) ON DELETE CASCADE,
    nombre NVARCHAR(200) NOT NULL,
    tipo NVARCHAR(100),
    fabricante NVARCHAR(200),
    modelo NVARCHAR(200),
    serie NVARCHAR(200),
    estado NVARCHAR(100),
    stock INT DEFAULT 0
);
GO

-- Recordatorios enviados
CREATE TABLE acta_recordatorios (
    id INT IDENTITY(1,1) PRIMARY KEY,
    acta_id INT NOT NULL REFERENCES actas(id) ON DELETE CASCADE,
    fecha DATETIME DEFAULT GETDATE(),
    enviado_por NVARCHAR(100)
);
GO

-- =============================================
-- INDICES
-- =============================================

CREATE INDEX IX_actas_tipo ON actas(tipo);
CREATE INDEX IX_actas_estado ON actas(estado);
CREATE INDEX IX_actas_fecha ON actas(fecha);
CREATE INDEX IX_actas_created_by ON actas(created_by);
CREATE INDEX IX_actas_recibido_username ON actas(recibido_username);
CREATE INDEX IX_actas_retira_username ON actas(retira_username);
CREATE INDEX IX_acta_equipos_acta_id ON acta_equipos(acta_id);
CREATE INDEX IX_permisos_grupo_jefes_username ON permisos_grupo_jefes(username);
CREATE INDEX IX_permisos_grupo_miembros_username ON permisos_grupo_miembros(username);
GO

-- =============================================
-- VISTA: Resumen de actas con equipos
-- =============================================

CREATE VIEW vw_actas_resumen AS
SELECT
    a.id, a.numero, a.tipo, a.fecha, a.lugar, a.destino,
    a.entregado_por, a.recibido_por, a.autorizado_por,
    a.retira_persona, a.motivo, a.total_equipos,
    a.estado, a.created_by, a.created_at, a.updated_at
FROM actas a;
GO

PRINT 'Base de datos SistemaNG creada exitosamente.';
GO
