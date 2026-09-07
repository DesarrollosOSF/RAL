## Control-Sedes (tablero de actividades con control de tiempo)

### Requisitos
- PHP (con PDO) y MySQL
- MySQL 5.7+

### Instalación
1. Crear la BD `control_sedes` en MySQL.
2. Ejecutar: `install/schema_control_sedes.sql`
3. Ajustar credenciales de BD en `config/database.php` si aplica.
4. Crear un primer usuario administrador (bootstrap):
   - Abrir `install/create_admin.php` y crear el admin inicial.

### Si ya instalaste versiones anteriores
Si ya tenías el esquema creado antes de la lógica diaria, ejecuta la migración:
- `install/migrate_diaria_jornadas.sql`

### Rutas en XAMPP (según tu workspace)
Si tu `DocumentRoot` es `c:/xampp/htdocs`, entonces la ruta base del proyecto es:
- `http://localhost/DesarrollosDuvan/Control-Sedes/`

Páginas principales:
- Login: `http://localhost/DesarrollosDuvan/Control-Sedes/login.php`
- Tablero: `http://localhost/DesarrollosDuvan/Control-Sedes/index.php`
- Admin crear actividades: `http://localhost/DesarrollosDuvan/Control-Sedes/admin/actividades.php`
- Admin crear usuarios: `http://localhost/DesarrollosDuvan/Control-Sedes/admin/usuarios.php`
- Admin reportes de tiempo (rango de fechas, usuario, actividad): `http://localhost/DesarrollosDuvan/Control-Sedes/admin/reportes_tiempo_actividad.php`

### Crear usuario desde el login
En `login.php` tienes una opción para **Crear usuario**.
Al registrarte, podrás iniciar sesión y acceder al tablero.

