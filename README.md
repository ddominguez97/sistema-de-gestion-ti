# Sistema NG - Gestion de Activos TI

Sistema web para la gestion de activos de tecnologia, integrado con GLPI.

## Modulos

| Modulo | Estado | Descripcion |
|---|---|---|
| Etiquetas de Activos | Activo | Genera e imprime etiquetas con codigo QR desde GLPI |
| Actas de Equipos | Activo | Actas de entrega y salida con datos de GLPI o ingreso manual |
| Reportes y Estadisticas | Pruebas | Registro de actas, aceptacion/resguardo, estadisticas por periodo |
| Formato de Inversion | Deshabilitado | Solicitudes de compra e inversion por empresa |
| Permisos y Notificaciones | Deshabilitado | Permisos por perfil GLPI, notificaciones y alertas |

## Estados de modulos

- **activo** - Produccion, accesible para todos los usuarios
- **pruebas** - En desarrollo, solo accesible para admin/dev
- **deshabilitado** - No desarrollado aun, muestra pantalla de "proximamente"

## Requisitos

- PHP 8.2+ con extensiones: pdo_mysql, pdo_sqlsrv
- MySQL/MariaDB (BD GLPI para autenticacion y datos de activos)
- SQL Server Express (BD local para actas) - en desarrollo se usa archivo JSON

## Servidor de desarrollo

```bash
php -S localhost:8080 -t .
```

Acceder a http://localhost:8080

En modo desarrollo (localhost) se crea sesion temporal automaticamente para pruebas sin necesidad de BD GLPI.

## Estructura

```
Sistema_NG/
  index.php                  # Dashboard + login
  Sistema_admin.php          # Panel de configuracion
  sistemas_settings.json     # Configuracion general (BD, branding, modulos)
  config/
    config.php               # Configuracion central, conexiones BD, branding
    schema_sqlexpress.sql    # Schema SQL Server para produccion
  data/
    actas.json               # Almacenamiento local desarrollo (reemplaza SQL Server)
  modules/
    etiquetas/               # Modulo de etiquetas QR
    actas/                   # Modulo de actas entrega/salida
    reportes/                # Modulo de reportes y estadisticas
    proximamente.php         # Pantalla para modulos deshabilitados
```

## Configuracion

Toda la configuracion se gestiona desde `sistemas_settings.json`:
- Conexion a BD GLPI (MySQL)
- Conexion a BD local (SQL Server Express)
- Branding: nombre empresa, color, logo, tema (claro/oscuro)
- Estado de modulos: activo / pruebas / deshabilitado
