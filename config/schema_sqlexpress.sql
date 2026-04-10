-- ============================================================
-- Sistema NG — Schema para SQL Server Express
-- Base de datos local para registro de actas
-- ============================================================

-- Crear base de datos (ejecutar como sa o usuario con permisos)
IF NOT EXISTS (SELECT name FROM sys.databases WHERE name = 'SistemaNG')
BEGIN
    CREATE DATABASE SistemaNG;
END
GO

USE SistemaNG;
GO

-- ============================================================
-- Tabla principal de actas
-- ============================================================
IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='actas' AND xtype='U')
BEGIN
    CREATE TABLE actas (
        id              INT IDENTITY(1,1) PRIMARY KEY,
        numero          VARCHAR(20)   NOT NULL UNIQUE,
        tipo            VARCHAR(10)   NOT NULL,          -- 'entrega' | 'salida'
        fecha           DATE          NOT NULL,
        lugar           NVARCHAR(255) NULL,

        -- Campos de entrega
        entregado_por       NVARCHAR(255) NULL,
        entregado_cargo     NVARCHAR(255) NULL,
        recibido_por        NVARCHAR(255) NULL,
        recibido_cargo      NVARCHAR(255) NULL,

        -- Campos de salida
        autorizado_por      NVARCHAR(255) NULL,
        autorizado_cargo    NVARCHAR(255) NULL,
        motivo              NVARCHAR(255) NULL,
        destino             NVARCHAR(255) NULL,
        retira_persona      NVARCHAR(255) NULL,
        retira_cargo        NVARCHAR(255) NULL,

        -- Datos comunes
        observaciones       NVARCHAR(MAX) NULL,
        equipos             NVARCHAR(MAX) NULL,          -- JSON array de equipos
        total_equipos       INT           DEFAULT 0,

        -- Estado de aceptacion / resguardo
        estado              VARCHAR(20)   DEFAULT 'pendiente',  -- pendiente | aceptada | rechazada
        aceptada_por        NVARCHAR(255) NULL,
        aceptada_fecha      DATETIME      NULL,
        aceptada_observaciones NVARCHAR(MAX) NULL,
        firma_digital       NVARCHAR(MAX) NULL,          -- base64 de firma canvas

        -- Metadata
        created_by          NVARCHAR(100) NOT NULL,
        created_at          DATETIME      DEFAULT GETDATE(),
        updated_at          DATETIME      DEFAULT GETDATE()
    );
END
GO

-- Indices para consultas frecuentes
IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_actas_tipo')
    CREATE INDEX IX_actas_tipo ON actas(tipo);

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_actas_estado')
    CREATE INDEX IX_actas_estado ON actas(estado);

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_actas_fecha')
    CREATE INDEX IX_actas_fecha ON actas(fecha DESC);

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IX_actas_created_by')
    CREATE INDEX IX_actas_created_by ON actas(created_by);
GO

-- ============================================================
-- Vista para estadisticas rapidas
-- ============================================================
IF EXISTS (SELECT * FROM sys.views WHERE name = 'vw_actas_resumen')
    DROP VIEW vw_actas_resumen;
GO

CREATE VIEW vw_actas_resumen AS
SELECT
    tipo,
    estado,
    YEAR(fecha)  AS anio,
    MONTH(fecha) AS mes,
    COUNT(*)     AS total,
    SUM(total_equipos) AS total_equipos
FROM actas
GROUP BY tipo, estado, YEAR(fecha), MONTH(fecha);
GO
