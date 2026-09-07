# Control-Sedes - Instalación rápida

1) Crear base de datos en MySQL:
   - `control_sedes`

2) Ejecutar el script:
   - `install/schema_control_sedes.sql`

3) Crear un usuario administrador:
   - Puedes insertar manualmente en tabla `usuarios` un `password_hash` generado con PHP (`password_hash`)
   - Recomendación: usar `admin.php` (próximamente) o crear desde la consola.

4) Ajustar credenciales en:
   - `config/database.php`

## Nota
Este módulo es compatible con los requisitos del tablero:
- Asignadas -> Iniciadas -> Finalizadas
- Tiempo independiente por actividad (por usuario-actividad)
- Actividades diarias por jornada (por fecha) para poder reportar cada 15/30 días

