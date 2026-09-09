-- =====================================================================
-- migrate_ral_ajustes.sql (Versión Final Alineada con Front-End)
-- =====================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------
-- 1) Tabla de grupos RAL
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `actividad_grupos` (
  `id` INT NOT NULL,
  `slug` VARCHAR(60) NOT NULL,
  `nombre` VARCHAR(120) NOT NULL,
  `orden` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_grupo_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `actividad_grupos` (`id`, `slug`, `nombre`, `orden`) VALUES
  (1, 'admin_comercial',  'Actividades administrativas comercial',  1),
  (2, 'admin_cartera',    'Actividades administrativas cartera',    2),
  (3, 'servicios',        'Servicios',                              3),
  (4, 'facturacion',      'Facturación',                            4),
  (5, 'otras_admin',      'Otras actividades administrativas',      5),
  (6, 'no_clasificadas',  'No clasificadas (pausas / tiempos no laborables)', 6)
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`), `orden` = VALUES(`orden`);

-- ---------------------------------------------------------------------
-- 2) Columnas de clasificación en `actividades`
-- ---------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actividades' AND COLUMN_NAME = 'grupo_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE actividades ADD COLUMN grupo_id INT NULL AFTER activo, ADD KEY idx_actividades_grupo (grupo_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actividades' AND CONSTRAINT_NAME = 'fk_actividades_grupo'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE actividades ADD CONSTRAINT fk_actividades_grupo FOREIGN KEY (grupo_id) REFERENCES actividad_grupos(id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col2_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actividades' AND COLUMN_NAME = 'servicio_subtipo'
);
SET @sql := IF(@col2_exists = 0,
  'ALTER TABLE actividades ADD COLUMN servicio_subtipo VARCHAR(30) NULL AFTER grupo_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 3) Columnas de detalle en `actividad_notas` (Soporta la lógica JS)
-- ---------------------------------------------------------------------
SET @col_tipo_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actividad_notas' AND COLUMN_NAME = 'servicio_tipo'
);
SET @sql := IF(@col_tipo_exists = 0,
  'ALTER TABLE actividad_notas ADD COLUMN servicio_tipo VARCHAR(30) NULL AFTER observaciones COMMENT ''empresarial, particular, osf, terceros, mascotas, servicios_no_prestados''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_subtipo_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actividad_notas' AND COLUMN_NAME = 'servicio_subtipo'
);
SET @sql := IF(@col_subtipo_exists = 0,
  'ALTER TABLE actividad_notas ADD COLUMN servicio_subtipo VARCHAR(30) NULL AFTER servicio_tipo COMMENT ''completo, inicial, final, prevision, particular, negados, no_prestados''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_terceros_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actividad_notas' AND COLUMN_NAME = 'es_terceros'
);
SET @sql := IF(@col_terceros_exists = 0,
  'ALTER TABLE actividad_notas ADD COLUMN es_terceros TINYINT(1) NOT NULL DEFAULT 0 AFTER servicio_subtipo',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_mascota_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actividad_notas' AND COLUMN_NAME = 'es_mascota'
);
SET @sql := IF(@col_mascota_exists = 0,
  'ALTER TABLE actividad_notas ADD COLUMN es_mascota TINYINT(1) NOT NULL DEFAULT 0 AFTER es_terceros',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 4) Días hábiles configurables por mes
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ral_dias_habiles` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `anio` SMALLINT NOT NULL,
  `mes` TINYINT NOT NULL,
  `dias_habiles` TINYINT NOT NULL,
  `creado_por` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ral_habiles_anio_mes` (`anio`, `mes`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 5) Ausencias por usuaria
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ral_ausencias` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `usuario_id` INT NOT NULL,
  `tipo` ENUM('vacaciones','permiso','compensatorio') NOT NULL,
  `fecha` DATE NOT NULL,
  `dias` DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  `observaciones` VARCHAR(255) DEFAULT NULL,
  `creado_por` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ral_ausencias_usuario_fecha` (`usuario_id`, `fecha`),
  KEY `idx_ral_ausencias_fecha` (`fecha`),
  CONSTRAINT `fk_ral_ausencias_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 6) Clasificar actividades existentes
-- ---------------------------------------------------------------------
UPDATE actividades SET grupo_id = 1 WHERE LOWER(TRIM(titulo)) IN (
  'cambio de papelería', 'cambio/actualización papelería', 'actualización de papelería',
  'gestion comercial', 'gestión comercial', 'ingreso de contratos', 'ingreso de contrato',
  'empaquetamiento macotas', 'empaquetamiento mascotas'
);

UPDATE actividades SET grupo_id = 2 WHERE LOWER(TRIM(titulo)) IN (
  'ingreso soportes cobra', 'gestión de cobro', 'gestion de cobro'
);

UPDATE actividades SET grupo_id = 3 WHERE LOWER(TRIM(titulo)) IN (
  'prestación de servicio funerario', 'prestacion de servicio funerario',
  'pagar destino final'
);
UPDATE actividades SET servicio_subtipo = 'completo' WHERE LOWER(TRIM(titulo)) LIKE '%prestaci%n de servicio funerario%' AND (servicio_subtipo IS NULL OR servicio_subtipo = '');
UPDATE actividades SET servicio_subtipo = 'pago_destino_final' WHERE LOWER(TRIM(titulo)) = 'pagar destino final';

UPDATE actividades SET grupo_id = 4 WHERE LOWER(TRIM(titulo)) IN (
  'facturación', 'facturacion', 'cobro de contrato en campo'
);

UPDATE actividades SET grupo_id = 5 WHERE LOWER(TRIM(titulo)) IN (
  'reunión / capacitación', 'reunion / capacitacion', 'cafetería', 'cafeteria',
  'aseo de sede', 'consignación bancaria', 'consignacion bancaria',
  'soporte técnico', 'soporte tecnico', 'orientación al cliente',
  'orientacion al cliente', 'otros'
);

UPDATE actividades SET grupo_id = 6 WHERE LOWER(TRIM(titulo)) IN (
  'almuerzo', 'break mañana', 'break manana', 'break tarde',
  'pausa activa', 'tiempo no laborado'
);

COMMIT;