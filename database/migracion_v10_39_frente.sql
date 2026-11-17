-- v10.39: frente / sector de obra de cada Capataz (organigrama por Profesional de Terreno).
-- Se corre UNA vez en cada base en vivo (Render Web Shell), ANTES del Manual Deploy:
--   bash /app/database/aplicar_en_todas_las_obras.sh /app/database/migracion_v10_39_frente.sql
-- Es solo una columna nueva que acepta NULL: el codigo anterior sigue funcionando igual.

ALTER TABLE usuarios
  ADD COLUMN frente VARCHAR(100) NULL AFTER jefe_terreno_id;

-- Verificacion:
-- SHOW COLUMNS FROM usuarios LIKE 'frente';
