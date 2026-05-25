# PedidosGT — Sistema de Directorio y Pedidos

## Stack
- **Backend:** PHP 8+ puro (sin frameworks)
- **Base de datos:** MySQL 5.7+
- **Frontend:** HTML + CSS + JavaScript vanilla

## Estructura
```
PEDIDOS/
├── api/              # Backend PHP
│   ├── config/       # DB y configuración
│   ├── controllers/  # Lógica de negocio
│   ├── middleware/   # Auth, seguridad
│   └── helpers/      # Utilidades
├── database/         # Schema SQL
└── public/           # Frontend
    ├── index.html    # Directorio público
    ├── cliente/      # Panel cliente
    ├── negocio/      # Panel negocio
    ├── admin/        # Panel administrador
    └── repartidor/   # Panel repartidor
```

## Instalación en cPanel

### 1. Base de datos
- Crea una base de datos MySQL en cPanel
- Importa `database/schema.sql`

### 2. Configuración
- Copia `api/config/config.example.php` → `api/config/config.php`
- Edita los datos de conexión, APP_URL y JWT_SECRET

### 3. Subir archivos
- Sube todo el contenido a `public_html/`
- Asegúrate que `api/.htaccess` está activo (mod_rewrite)

### 4. Carpeta uploads
- Crea `public_html/uploads/` con permisos 755

### 5. Admin inicial
- Registra un usuario con rol `cliente`
- En MySQL, cambia su `role_id` a 1 (admin):
  ```sql
  UPDATE users SET role_id = 1 WHERE email = 'tuemail@ejemplo.com';
  ```

## Seguridad implementada
- PDO prepared statements (previene SQL Injection)
- htmlspecialchars en todos los outputs (previene XSS)
- CSRF tokens
- Rate limiting por IP
- Headers de seguridad (CSP, X-Frame-Options, etc.)
- Bcrypt para contraseñas (cost 12)
- Tokens de sesión seguros (64 bytes random)
- Validación de tipos MIME en uploads
- Errores no expuestos en producción

## Roles
| Rol | Panel | Acceso |
|-----|-------|--------|
| admin | /admin/ | Todo el sistema |
| negocio | /negocio/ | Sus pedidos y perfil |
| cliente | /cliente/ | Sus pedidos |
| repartidor | /repartidor/ | Entregas disponibles |

## Tarifas (configurables en config.php)
- **Tarifa de servicio:** Q5.00 por pedido
- **Tarifa de delivery:** Q15.00 (zonas céntricas)
