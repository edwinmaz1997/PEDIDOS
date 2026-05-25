# Guía de Instalación — NuevaExpress en cPanel
## https://nuevaexpress.com

---

## PASO 1: Base de Datos MySQL

1. En cPanel → **MySQL Databases**
2. Crea una base de datos: `nuevaexp_pedidos` (o el nombre que prefieras)
3. Crea un usuario MySQL con contraseña segura
4. Asigna el usuario a la base de datos con **todos los privilegios**
5. Ve a **phpMyAdmin** → selecciona la base de datos → **Importar** → sube `database/schema.sql`

---

## PASO 2: Configuración PHP

1. En la carpeta `api/config/` copia `config.example.php` y renómbralo a `config.php`
2. Edita `config.php` con tus datos:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'nuevaexp_pedidos');   // nombre de tu BD
define('DB_USER', 'nuevaexp_user');       // usuario MySQL
define('DB_PASS', 'TuContraseñaSegura');  // contraseña MySQL
define('APP_URL', 'https://nuevaexpress.com');
define('APP_ENV', 'production');
define('JWT_SECRET', 'genera_aqui_64_caracteres_aleatorios');
```

**Generar JWT_SECRET seguro** — en phpMyAdmin ejecuta:
```sql
SELECT HEX(RANDOM_BYTES(32));
```
Copia el resultado y úsalo como JWT_SECRET.

---

## PASO 3: Subir Archivos vía FTP/File Manager

Estructura en `public_html/`:
```
public_html/
├── .htaccess          ← del directorio public/
├── index.html
├── negocio.html
├── terminos.html
├── restablecer-contrasena.html
├── 404.html
├── assets/
├── cliente/
├── negocio/           ← carpeta (no el archivo)
├── admin/
├── repartidor/
├── uploads/           ← CREAR VACÍA con permisos 755
└── api/
    ├── .htaccess
    ├── index.php
    ├── config/
    │   └── config.php  ← TU ARCHIVO CONFIGURADO
    ├── controllers/
    ├── helpers/
    └── middleware/
```

**IMPORTANTE:** El archivo `config.php` con tus credenciales NUNCA debe subirse al repositorio.

---

## PASO 4: Permisos de Carpetas

En cPanel → File Manager → selecciona la carpeta `uploads/`:
- Permisos: `755`

Si el servidor no puede escribir archivos, también verifica:
- `public_html/uploads/` → `755`
- Subcarpetas que se creen automáticamente → `755`

---

## PASO 5: Verificar mod_rewrite

El `.htaccess` requiere que mod_rewrite esté habilitado (está activo en la mayoría de cPanel).

Prueba abriendo: `https://nuevaexpress.com/api/health`

Deberías ver:
```json
{"success":true,"data":{"status":"ok","version":"1.2","domain":"https://nuevaexpress.com"}}
```

Si ves error 500, verifica que `api/config/config.php` existe y tiene los datos correctos.

---

## PASO 6: Crear tu Cuenta Admin

1. Ve a `https://nuevaexpress.com/cliente/register.html`
2. Regístrate con rol **Cliente** (cualquier rol sirve por ahora)
3. En phpMyAdmin, ejecuta:
```sql
UPDATE users SET role_id = 1 WHERE email = 'TU_EMAIL@ejemplo.com';
```
4. Cierra sesión y vuelve a entrar
5. Serás redirigido automáticamente a `/admin/`

---

## PASO 7: Primeros pasos en el Admin

1. Ve a **Negocios → Agregar Negocio** para crear los primeros negocios
2. O comparte el link de registro con los negocios: `https://nuevaexpress.com/cliente/register.html`
   - Los negocios se registran con rol "Negocio"
   - Desde su panel crean su perfil en **Crear Negocio**
3. Ve a **Negocios** para verificar y activar los negocios registrados

---

## Versión PHP requerida

- **PHP 8.0+** recomendado (usa `match()` y `named arguments`)
- **MySQL 5.7+** o **MariaDB 10.3+**
- Extensiones PHP necesarias: `pdo`, `pdo_mysql`, `fileinfo`, `mbstring`

En cPanel → **PHP Selector** o **MultiPHP Manager** puedes seleccionar la versión.

---

## Solución de Problemas Comunes

| Error | Causa | Solución |
|-------|-------|----------|
| 500 Internal Server Error | config.php mal configurado | Verifica DB_HOST, DB_NAME, DB_USER, DB_PASS |
| 403 Forbidden | mod_rewrite no habilitado | Contacta a tu hosting |
| Las imágenes no suben | Permisos de `uploads/` | Cambia permisos a 755 |
| El email de reset no llega | PHP mail() desactivado | Activa en cPanel → Email → MX Entry |
| Error de BD al importar | Versión SQL incompatible | Importa con phpMyAdmin (no CLI) |

---

## URLs del Sistema

| Rol | URL |
|-----|-----|
| Directorio público | https://nuevaexpress.com/ |
| Perfil de negocio | https://nuevaexpress.com/negocio.html?slug=nombre-negocio |
| Panel cliente | https://nuevaexpress.com/cliente/ |
| Panel negocio | https://nuevaexpress.com/negocio/ |
| Panel admin | https://nuevaexpress.com/admin/ |
| Panel repartidor | https://nuevaexpress.com/repartidor/ |
| API health check | https://nuevaexpress.com/api/health |
| Términos | https://nuevaexpress.com/terminos.html |
