# Sistema NG — Estado del Proyecto al 02/06/2026
> Documento generado antes de formatear la máquina de desarrollo.
> Cubre: stack, instalación, estado de módulos, pendientes y decisiones de diseño.

---

## 1. Quién y qué

**Desarrollador:** Darwin Dominguez (`ddominguez97`), área de TI — Grupo NAGSA (Ecuador).  
**Email:** soporte3@nagsa.com.ec  
**Proyecto:** Sistema NG — aplicación web de gestión de activos TI, integrada con GLPI.  
**Repo local:** `C:\Users\Darwin.Dominguez\OneDrive - Corporativo\Proyectos\Sistema_NG`  
**Branch activa:** `desarrollo` | Branch principal: `main`

---

## 2. Stack técnico

| Componente | Detalle |
|---|---|
| Runtime | Node.js (sin framework extra) |
| Framework | Express 4 |
| Templates | EJS |
| Puerto | 8080 |
| BD local | SQL Server Express: `192.168.106.38\SQLEXPRESS`, BD `SistemaNG`, user `sistemang_app`, pass `SisNG2026$Prod` |
| BD GLPI | MySQL: `192.168.104.193:3306`, BD `glpidb`, user `glpiuser`, pass `glpi_db_pass` |
| Active Directory | `192.168.104.232:389`, dominio `DURAN.GRUPONAGSA.EC`, base DN `DC=DURAN,DC=GRUPONAGSA,DC=EC` |
| Impresora Zebra | `192.168.106.87:9100` (nombre: `D5N231202429`) |
| GLPI web | `https://glpi.nagsa.com.ec` |

### Dependencias npm (en `app/package.json`)
```json
{
  "bcryptjs": "^2.4.3",
  "ejs": "^3.1.10",
  "express": "^4.21.0",
  "express-session": "^1.18.0",
  "ldapjs": "^3.0.7",
  "mssql": "^12.3.0",
  "multer": "^1.4.5-lts.1",
  "mysql2": "^3.11.0"
}
```

---

## 3. Instalación desde cero

```bash
# 1. Tener Node.js instalado (versión 20+ recomendada)
# 2. Restaurar el repo desde git o copia de seguridad
cd "C:\Users\Darwin.Dominguez\OneDrive - Corporativo\Proyectos\Sistema_NG\app"
npm install

# 3. Verificar que sistemas_settings.json existe en la raíz del proyecto (fuera de /app)
# El archivo ya tiene toda la configuración de conexiones

# 4. SQL Server Express: crear BD y ejecutar script de migración
# Ejecutar database/migrate_SistemaNG.sql contra una BD vacía

# 5. Arrancar
npm start          # producción
npm run dev        # desarrollo (--watch para hot reload)
```

### Credenciales del settings
El archivo `sistemas_settings.json` (raíz del proyecto, NO dentro de `/app`) contiene:
- `admin_pass`: `GLPIM853@UYT`
- `local_db.password`: `SisNG2026$Prod`
- `active_directory.bind_dn`: `CN=Administrador,CN=Users,DC=DURAN,DC=GRUPONAGSA,DC=EC`
- `active_directory.bind_password`: `M853@UYT`

---

## 4. Estructura de archivos relevantes

```
Sistema_NG/
├── sistemas_settings.json          ← config principal (conexiones, branding, permisos_config)
├── database/
│   └── migrate_SistemaNG.sql       ← script completo para recrear BD SQL Server
├── app/
│   ├── server.js                   ← entrada, rutas, sesión
│   ├── package.json
│   ├── config/
│   │   └── config.js               ← carga config de SQL + cache en memoria
│   ├── middleware/
│   │   ├── auth.js                 ← autenticación AD + niveles N1/N2/N3/N4
│   │   └── branding.js             ← inyecta color/logo/tema en todas las vistas
│   ├── routes/
│   │   ├── index.js                ← login, dashboard, campanita
│   │   ├── actas.js                ← módulo actas (entrega + salida)
│   │   ├── admin.js                ← panel admin
│   │   ├── inversiones.js          ← módulo solicitudes de compra
│   │   ├── permisos.js             ← módulo permisos
│   │   └── equipos_asignados.js    ← módulo equipos
│   ├── views/
│   │   ├── dashboard.ejs
│   │   ├── actas.ejs
│   │   ├── admin.ejs
│   │   ├── permisos.ejs
│   │   ├── equipos.ejs
│   │   ├── equipos_asignados.ejs
│   │   ├── reportes.ejs
│   │   ├── etiquetas.ejs
│   │   └── inversiones/
│   │       ├── index.ejs
│   │       ├── detalle.ejs
│   │       └── nueva.ejs
│   └── public/                     ← CSS, JS, imágenes
└── data/
    ├── actas.json                  ← fallback JSON (backup de SQL Server)
    └── uploads/                    ← logos subidos
```

---

## 5. Módulos y estados actuales

Todos los módulos se controlan desde el Admin Panel (`/admin`) y se guardan en la tabla `configuracion_modulos` de SQL Server.

| Key | Nombre visible | Estado actual | Acceso |
|---|---|---|---|
| `etiquetas` | Etiquetas de Activos | activo | N1/N2 solo |
| `actas` | Actas de Equipos | activo | N1-N4 (vistas por nivel) |
| `reportes` | Seguimiento de Actas | activo | N1-N4 (vistas por nivel) |
| `equipos_asignados` | Equipos | activo | N1/N2 ven todo, N3/N4 ven sus equipos |
| `inversiones` | Solicitudes de Compra | activo | N1-N4 + Gerencia + Contadoras |
| `permisos` | Permisos del Sistema | activo | N1/N2 solo |

**IMPORTANTE:** Si se agrega un módulo nuevo, actualizar en DOS lugares:
1. `views/admin.ejs` → objeto `modLabels`
2. `routes/admin.js` → array en `POST /admin/save-modulos`

---

## 6. Sistema de permisos y niveles

### Niveles de usuario
| Nivel | Rol | Origen |
|---|---|---|
| N1 | Superadmin | Perfil GLPI = 4 (super-admin) |
| N2 | TI | Está en `permisos_config.ti_usuarios` (sistemas_settings.json) |
| N3 | Jefe de área | Está en `permisos_grupo_jefes` (SQL Server) |
| N4 | Usuario normal | Cualquier usuario AD/GLPI no clasificado arriba |
| Gerente | Gerentes dueños | Grupo con `puede_aprobar_cotizacion=true` o `puede_aprobar_pago=true` |
| Contadora | Contadoras | Grupo con `puede_marcar_pagado=true` |

### TI usuarios (en sistemas_settings.json)
- `christian.lopez` (admin_panel: true)
- `jonathan.perez` (admin_panel: true)
- `joaquin.cabrera` (admin_panel: false)
- `darwin.dominguez` (admin_panel: true)

### Grupos configurados (en sistemas_settings.json y SQL Server)
| ID | Nombre | Empresa | Flags inversiones |
|---|---|---|---|
| 1 | Manufactura | Duracolor | ninguno |
| 2 | Pintura | Duracolor | ninguno |
| 4 | Gerencia | null (AD lookup) | puede_aprobar_cotizacion + puede_aprobar_pago |
| 5 | Contadora | null (por miembro) | puede_marcar_pagado |
| 6 | Oficina | Vetriko | ninguno |
| 7 | Oficina | WindowWorld | ninguno |
| 8 | Oficina | Proalum | ninguno |
| 9 | Oficina | Duralum | ninguno |
| 10 | Oficina | Nagsa | ninguno |
| 11 | Planta | Vetriko | ninguno |

**Grupo Gerencia** (`empresa: null`): empresa se resuelve via AD lookup del OU de cada miembro.  
**Grupo Contadora** (`empresa: null`): empresa se toma de `mb.empresa` individual de cada miembro.

---

## 7. Active Directory — notas críticas

### Configuración actual (modo: automático)
- El servidor, base_dn y login_field se leen de `glpi_authldaps` (MySQL GLPI).
- `bind_dn` y `bind_password` se leen de `configuracion_ad` (SQL Server) como **override** de los valores GLPI (que vienen cifrados con sodium).
- **NUNCA usar modo manual** sin configurar también `dominio` y `sufijo_usuario`.

### ldapjs v3.0.7 — cambio que rompe código legacy
```javascript
// ❌ NO funciona en v3
const attrs = entry.object || {};

// ✅ Correcto en v3
const attrs = (entry.pojo && entry.pojo.attributes) ? entry.pojo.attributes : [];
for (const a of attrs) {
  // a.type = nombre del atributo (ej: 'sAMAccountName')
  // a.values = array de valores (ej: ['jperez'])
}
```
`entry.dn` en v3 es un objeto DN — usar `.toString()` y regex con flag `i` para OUs.

### Endpoint de diagnóstico
`GET /admin/test-ldap` — prueba bind + search. Accesible con sesión N1/N2.

---

## 8. Base de datos SQL Server — tablas principales

El script completo está en `database/migrate_SistemaNG.sql`.

| Tabla | Descripción |
|---|---|
| `configuracion` | Pares clave/valor generales |
| `configuracion_modulos` | Estado de cada módulo (activo/pruebas/deshabilitado) |
| `configuracion_ad` | Config AD con bind_dn y bind_password |
| `configuracion_categorias` | Visibilidad de categorías GLPI |
| `motivos_salida` | Motivos configurables con tipo (externo/interno) |
| `permisos_grupos` | Grupos con empresa, switches de inversiones |
| `permisos_grupo_jefes` | Jefes por grupo |
| `permisos_grupo_miembros` | Miembros con campo empresa individual |
| `permisos_ti` | Usuarios TI con flags admin_panel |
| `actas` | Actas de entrega/salida |
| `acta_equipos` | Equipos por acta |
| `acta_recordatorios` | Recordatorios de actas pendientes |
| `equipos_asignados` | Equipos con S/N cruzados con actas |
| `inversiones` | Solicitudes de compra por empresa/flujo |
| `inversiones_items` | Ítems por solicitud |
| `inversiones_config` | Config de flujo por empresa (A o B) |

### Schema crítico `equipos_asignados`
```sql
CREATE TABLE equipos_asignados (
  id INT IDENTITY(1,1) PRIMARY KEY,
  glpi_item_id INT NULL, glpi_tabla NVARCHAR(100) NULL,
  nombre NVARCHAR(255) NOT NULL, tipo NVARCHAR(100) NULL,
  fabricante NVARCHAR(100) NULL, modelo NVARCHAR(100) NULL,
  serie NVARCHAR(100) NULL,
  usuario_nombre NVARCHAR(255) NULL, usuario_username NVARCHAR(100) NULL,
  acta_numero NVARCHAR(50) NULL, acta_id INT NULL,
  estado NVARCHAR(50) NOT NULL DEFAULT 'activo',
  fecha_entrega DATE NULL,
  created_at DATETIME DEFAULT GETDATE(), updated_at DATETIME DEFAULT GETDATE()
)
```

---

## 9. Módulo Actas — diseño implementado

### Vistas por nivel
- **N1/N2**: formulario completo (crear entrega + crear salida + autorizar solicitudes)
- **N3**: solicitar salida (formulario simplificado). Si tiene `crear_entrega`: botón adicional
- **N4**: solo solicitar salida

### Flujo de solicitud de salida
1. N3/N4 solicita → estado `pendiente_autorizacion`
2. TI ve en Reportes → Pendientes de Salida
3. Si motivo **externo** → modal pide proveedor/destino
4. Si motivo **interno** → solo confirmación, destino = solicitante
5. Estado final: `autorizada`

### Motivos de salida
Configurables desde Permisos → Configuración (N1/N2).  
Tabla `motivos_salida` con columna `tipo` = `externo` | `interno`.  
Actuales: "Equipo dañado" (externo), "Teletrabajo", "Prestamo", "Cambio de ubicacion" (internos).

### Reportes por nivel
| Nivel | Tabs disponibles |
|---|---|
| N1/N2 | Pendientes Entrega \| Pendientes Salida \| Historial \| Estadísticas |
| N3 | Mis Pendientes (suyas + grupo) \| Historial |
| N4 | Mis Pendientes (solo suyas) \| Historial |

---

## 10. Módulo Equipos — diseño implementado

- **Ruta:** `/equipos` — archivo `routes/equipos_asignados.js`, vista `views/equipos.ejs`
- **Tabs:** Asignados | Por Asignar (badge naranja) | Mis Equipos
- **Fuente:** GLPI MySQL (`glpi_computers`, `glpi_monitors`, `glpi_printers`, `glpi_phones`)
- **Cruce:** `equipos_asignados` (SQL Server) por S/N para saber si tienen acta

### Jerarquía (espejo exacto de Permisos)
- TI → empresas → grupos → contadoras por empresa
- Grupos con `empresa: null` (Gerencia): AD lookup del OU de cada miembro
- Contadoras: usan `mb.empresa` individual
- **Sin grupo**: usuarios GLPI sin grupo → sección "Sin grupo" (borde naranja) dentro de su empresa

### Tab Por Asignar
- Equipos con `date_creation >= fechaCorte` (configurable en SQL Server, default 2026-05-18) sin S/N en `equipos_asignados` activos
- API: `GET /equipos/api/por-asignar` → `{ equipos, fecha_corte }`

---

## 11. Módulo Inversiones — diseño implementado

### Dos flujos según empresa
**Flujo A** (Window World, Vetriko, Duracolor):
```
Solicitud → TI cotiza → Gerente aprueba cotización → TI compra → llega factura → Gerente aprueba pago → Contadora marca pagado
```

**Flujo B** (Proalum, Duralum, NAGSA):
```
Solicitud → TI cotiza/compra → Gerente aprueba → Contadora marca pagado
```

### Roles en Inversiones
| Rol | Configuración | Nota |
|---|---|---|
| Gerente | Grupo con `puede_aprobar_cotizacion=true` o `puede_aprobar_pago=true` | Actualmente los Kozhaya (Gerencia grupo ID=4) |
| Contadora | Grupo con `puede_marcar_pagado=true` | Actualmente Contadora (grupo ID=5) |
| Cualquier usuario | Solo crear solicitudes | Sin auto-aprobación |

- Gerentes tienen visibilidad total entre empresas
- Un gerente puede cuestionar/rechazar compra aprobada por otro gerente
- Contadoras NO pueden aprobar cotizaciones ni pagos

### Switches por grupo (en `permisos_grupos`)
```
puede_aprobar_cotizacion  BIT
puede_aprobar_pago        BIT
puede_marcar_pagado       BIT
```
Tener cualquier switch ON activa acceso al módulo Inversiones automáticamente.

---

## 12. Módulo Permisos — diseño implementado

### Tabs
1. **Jerarquía** — árbol TI + grupos por empresa + sección "Sin grupo" por empresa
2. **Permisos de Módulos** — tabla de usuarios con accesos por módulo
3. **Configuración** — motivos de salida (N1/N2 solo)
4. **Notificaciones** — estado de notificaciones (N1/N2 solo)

### Sección "Sin grupo" en Jerarquía
- Aparece dentro de cada empresa, ANTES de los grupos, como card colapsable con borde naranja
- Muestra usuarios AD/GLPI sin grupo ni TI
- **Asignación rápida**: select de grupo (todos los grupos, optgroup por empresa) + select Miembro/Jefe + botón Asignar
- Cross-empresa soportado (empleado DURACOLOR puede quedar en grupo WINDOW WORLD)
- API: `GET /permisos/api/sin-grupo` → `{ empresas, sin_empresa }`

### Admin Panel
- Toggle `admin_panel` en el perfil TI del usuario
- N2 con `admin_panel=true` entra sin contraseña

### Empresa header en Jerarquía
- Badge 36x36px con siglas (NG, WW, DC, DUR, PR, VTK)
- Función `empresaSigla(nombre)` en `permisos.ejs`
- Startup: `fixEmpresaNames()` en `config.js` normaliza "windowworld" → "Window World"

---

## 13. UX / Preferencias de UI

- **Sin popups del navegador**: nunca usar `alert()`, `confirm()`, `prompt()`
- **Toast global** para todo feedback
- **Modales custom** para confirmaciones
- **Notificaciones (campanita)**: fondo neutro en ítems, hover con opacidad sutil, badge en pastel del color principal
- **Colores**: reservar naranja/colores fuertes solo para badges pastel, no fondos sólidos

---

## 14. TAREAS PENDIENTES

### P1 — Campanita: badge "equipos sin acta"
**Qué:** Agregar al conteo de la campanita en el dashboard: "X equipos pendientes de acta" (del tab Por Asignar de Equipos).  
**Por qué:** TI necesita saber rápidamente si hay equipos nuevos sin acta en el GLPI.  
**Cómo:** El endpoint `GET /equipos/api/por-asignar` ya existe y devuelve `{ equipos, fecha_corte }`. Llamarlo desde el dashboard para mostrar el badge en la campanita.

---

### P2 — GLPI sync al autorizar acta de salida
**Qué:** Cuando TI autoriza una solicitud de salida, actualizar GLPI automáticamente:
1. Cambiar estado del equipo (`states_id`) al que TI seleccione (dañado, en reparación, dado de baja)
2. Opcionalmente desasignar del usuario (`users_id = 0`)
3. UPDATE directo en la tabla del equipo (`glpi_computers`, etc.)

**Por qué:** Actualmente Sistema NG maneja la trazabilidad pero GLPI sigue mostrando el equipo como activo. Doble trabajo manual.  
**Cómo:** Agregar al modal de autorizar: select de estados GLPI + checkbox desasignar. Al confirmar, ejecutar UPDATE en la tabla correspondiente. La conexión MySQL a GLPI ya existe (`getConn()` en el código).

---

### P3 — Inversiones → Actas (entrega de stock)
**Qué:** Después de marcar un ítem como entregado desde stock en Inversiones, redirigir a Actas con datos pre-llenados (usuario, equipo/ítem, contexto de la inversión).  
**Por qué:** Cuando el equipo comprado llega y se entrega a un usuario, debería quedar registrado automáticamente como acta de entrega.

---

### P4 — Inversiones → Actas (compra pagada)
**Qué:** Cuando una compra se marca como pagada, ofrecer redirigir a Actas para crear acta de entrega formal.  
**Por qué:** Flujo natural: se aprueba el pago → contadora lo paga → se crea acta de entrega del equipo.

---

### P5 — Portal de proveedores (Fase 2 — NO implementar aún)
**Qué:** Portal externo para cotizaciones en línea.  
**Diseño acordado:**
- TI activa "cotizar" en una solicitud → sistema envía correo a proveedores con link único
- Cada proveedor entra a `/cotizar/:token` (sin login), ve los ítems y llena oferta
- Mínimo 3 proveedores por solicitud
- Gerente ve comparativo: tabla artículos vs proveedores
- Gerente selecciona por artículo (puede mezclar proveedores)
- Sistema agrupa → órdenes de compra separadas por proveedor

**Infraestructura requerida (aún no configurada):**
- SMTP para envío de correos
- nginx como proxy inverso: `portalproveedores.nagsa.com.ec → 192.168.106.38:8080/cotizar/*`
- DNS público para `portalproveedores.nagsa.com.ec`

---

## 15. Decisiones de diseño importantes (no cambiar sin consultar)

1. **JSON como fallback**: `data/actas.json` y otros JSON se mantienen como backup si SQL Server no está disponible. No eliminarlos.
2. **Config cargada desde SQL al arrancar**: `config.js` carga todo al inicio con cache en memoria. Si cambias algo en SQL hay que reiniciar el proceso.
3. **Permisos en JSON + SQL**: `sistemas_settings.json` es la fuente primaria para TI usuarios y grupos. SQL Server tiene tablas espejo. Hay sincronización al arrancar (`fixEmpresaNames()` y migración de startup).
4. **ldapjs v3**: Siempre usar `entry.pojo.attributes`. NUNCA `entry.object`.
5. **bind_password en sistemas_settings.json**: La contraseña de AD está en texto plano en este archivo. Es override del valor cifrado de GLPI. Es normal.
6. **Gerentes NO están en AD**: El login de gerentes (familia Kozhaya) es vía AD porque sí tienen usuario de dominio. El diseño futuro podría requerir usuarios locales SQL para gerentes externos.
7. **Empresa de grupo Gerencia = null**: Se resuelve en runtime via AD lookup del OU de cada miembro. No se puede hardcodear.
8. **Admin panel acceso**: El admin panel (`/admin`) tiene doble control: password para N1 externo, y toggle `admin_panel` para N2.

---

## 16. Git — rama y estado al formatear

```
Branch: desarrollo
Archivos modificados (no commiteados):
  app/config/config.js
  app/middleware/auth.js
  app/routes/actas.js
  app/routes/admin.js
  app/routes/index.js
  app/routes/inversiones.js
  app/routes/permisos.js
  app/server.js
  app/views/ (varios)
  data/actas.json
  sistemas_settings.json

Archivos nuevos sin trackear:
  app/routes/equipos_asignados.js
  app/views/equipos.ejs
  app/views/equipos_asignados.ejs
  data/uploads/
  database/migrate_SistemaNG.sql
```

**IMPORTANTE:** Antes de formatear, asegurarse de que todo esté commiteado y pusheado a GitHub (`git push origin desarrollo`).

---

## 17. Checklist post-formateo

- [ ] Instalar Git
- [ ] Instalar Node.js (v20+)
- [ ] Instalar SQL Server Management Studio (SSMS) para administrar SQL Server Express
- [ ] Clonar/restaurar el repo
- [ ] Copiar `sistemas_settings.json` (si no está en el repo por seguridad)
- [ ] `cd app && npm install`
- [ ] Verificar conectividad a `192.168.106.38\SQLEXPRESS`
- [ ] Verificar conectividad a `192.168.104.193:3306` (GLPI MySQL)
- [ ] Verificar conectividad a `192.168.104.232:389` (AD)
- [ ] Ejecutar `npm start` y probar login
- [ ] Ir a `/admin/test-ldap` para verificar que el AD bind funciona
- [ ] Instalar Claude Code (`npm install -g @anthropic-ai/claude-code`) y autenticar
