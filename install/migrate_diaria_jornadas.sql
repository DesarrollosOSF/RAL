-- Migración: actividades diarias con jornadas por fecha (MySQL 5.7 compatible)
-- Ejecutar dentro de la BD `control_sedes`.
-- Nota: si ya tienes datos previos, se asignarán automáticamente a la jornada de HOY.

DELIMITER //
CREATE PROCEDURE sp_migrate_diaria_jornadas()
BEGIN
  DECLARE v_has_col INT DEFAULT 0;
  DECLARE v_has_uniq_old INT DEFAULT 0;
  DECLARE v_has_uniq_new INT DEFAULT 0;
  DECLARE v_has_idx_j INT DEFAULT 0;
  DECLARE v_fecha_hoy DATE;
  DECLARE v_jornada_hoy_id INT DEFAULT 0;

  -- 1) Asegurar tablas base
  CREATE TABLE IF NOT EXISTS jornadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL UNIQUE,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  -- 2) Agregar jornada_id si no existe
  SELECT COUNT(*) INTO v_has_col
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'actividades_usuario'
    AND column_name = 'jornada_id';

  IF v_has_col = 0 THEN
    ALTER TABLE actividades_usuario ADD COLUMN jornada_id INT NULL;
  END IF;

  -- 3) Crear o recuperar jornada de hoy
  SET v_fecha_hoy = CURDATE();
  INSERT INTO jornadas (fecha, activa)
  VALUES (v_fecha_hoy, 1)
  ON DUPLICATE KEY UPDATE activa = 1;

  SELECT id INTO v_jornada_hoy_id
  FROM jornadas
  WHERE fecha = v_fecha_hoy
  LIMIT 1;

  -- 4) Asignar jornada hoy a filas existentes (legacy)
  UPDATE actividades_usuario
  SET jornada_id = v_jornada_hoy_id
  WHERE jornada_id IS NULL;

  -- 5) Asegurar NOT NULL
  ALTER TABLE actividades_usuario MODIFY jornada_id INT NOT NULL;

  -- 6) Quitar unique anterior si existe
  SELECT COUNT(*) INTO v_has_uniq_old
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'actividades_usuario'
    AND index_name = 'uniq_usuario_actividad';

  IF v_has_uniq_old > 0 THEN
    ALTER TABLE actividades_usuario DROP INDEX uniq_usuario_actividad;
  END IF;

  -- 7) Crear unique nuevo si no existe
  SELECT COUNT(*) INTO v_has_uniq_new
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'actividades_usuario'
    AND index_name = 'uniq_jornada_usuario_actividad';

  IF v_has_uniq_new = 0 THEN
    ALTER TABLE actividades_usuario
      ADD UNIQUE KEY uniq_jornada_usuario_actividad (jornada_id, usuario_id, actividad_id);
  END IF;

  -- 8) Índice jornada_id si no existe
  SELECT COUNT(*) INTO v_has_idx_j
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'actividades_usuario'
    AND index_name = 'idx_jornada';

  IF v_has_idx_j = 0 THEN
    ALTER TABLE actividades_usuario ADD KEY idx_jornada (jornada_id);
  END IF;
END//

DELIMITER ;

CALL sp_migrate_diaria_jornadas();
DROP PROCEDURE sp_migrate_diaria_jornadas;

