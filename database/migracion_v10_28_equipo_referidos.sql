-- v10.28: organigrama (Jefe de Terreno -> Capataces) y referidos por enlace.
-- Se corre UNA vez en la base en vivo (Render Web Shell), ANTES del Manual
-- Deploy. Son solo columnas nuevas que aceptan NULL: el código anterior
-- sigue funcionando igual con esto aplicado.
--
--   mysql -h127.0.0.1 -uroot icafal_rrhh -e "<cada sentencia>"

ALTER TABLE usuarios
  ADD COLUMN jefe_terreno_id INT UNSIGNED NULL AFTER activo,
  ADD CONSTRAINT fk_usuario_jefe_terreno
      FOREIGN KEY (jefe_terreno_id) REFERENCES usuarios(id) ON DELETE SET NULL;

ALTER TABLE postulaciones
  ADD COLUMN referido_por_id INT UNSIGNED NULL AFTER capataz_esperado_id,
  ADD CONSTRAINT fk_postulacion_referido_por
      FOREIGN KEY (referido_por_id) REFERENCES usuarios(id) ON DELETE SET NULL;

-- Verificación:
-- SHOW COLUMNS FROM usuarios LIKE 'jefe_terreno_id';
-- SHOW COLUMNS FROM postulaciones LIKE 'referido_por_id';
