-- Ampliar `rol`: valores como auxiliar_administrativo tienen 24 caracteres; VARCHAR(20) truncaba.
-- Ejecutar en la BD control_sedes (MySQL 5.7+).

ALTER TABLE usuarios
  MODIFY rol VARCHAR(50) NOT NULL DEFAULT 'usuario';

UPDATE usuarios
SET rol = 'auxiliar_administrativo'
WHERE rol IN ('auxiliar_administr', 'auxiliar_administrativos');
