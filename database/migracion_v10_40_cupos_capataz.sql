-- v10.40: cupos por capataz + cada persona queda con su capataz (cuadrilla) para que cada
-- Profesional de Terreno vea solo a su gente.
-- Se corre UNA vez en cada base en vivo (Render Web Shell), despues del Manual Deploy:
--   bash /app/database/aplicar_en_todas_las_obras.sh /app/database/migracion_v10_40_cupos_capataz.sql
-- Solo agrega columnas NULL/DEFAULT y una tabla nueva: el codigo se comporta igual que antes
-- hasta que esto este aplicado (y despues tambien, para los cupos "generales").

-- 1) A que capataz va dirigida una solicitud de cupos (NULL = cupo general del equipo).
ALTER TABLE solicitudes_cupo
  ADD COLUMN capataz_id INT UNSIGNED NULL AFTER usuario_id,
  ADD CONSTRAINT fk_solicitud_capataz
      FOREIGN KEY (capataz_id) REFERENCES usuarios(id) ON DELETE SET NULL;

-- 2) El capataz "dueno" de cada persona seleccionada (cuadrilla) y si su cupo salio de los
--    cupos reservados para ese capataz (para devolverlo al mismo lugar si se deshace).
ALTER TABLE postulaciones
  ADD COLUMN capataz_id INT UNSIGNED NULL AFTER referido_por_id,
  ADD COLUMN cupo_reservado TINYINT(1) NOT NULL DEFAULT 0 AFTER capataz_id,
  ADD CONSTRAINT fk_postulacion_capataz
      FOREIGN KEY (capataz_id) REFERENCES usuarios(id) ON DELETE SET NULL;

-- 3) Cupos reservados que le quedan a cada capataz, por cargo.
CREATE TABLE cupos_capataz (
    capataz_id  INT UNSIGNED NOT NULL,
    cargo_id    INT UNSIGNED NOT NULL,
    reservados  INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (capataz_id, cargo_id),
    CONSTRAINT fk_cupos_capataz_usuario FOREIGN KEY (capataz_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_cupos_capataz_cargo   FOREIGN KEY (cargo_id)   REFERENCES cargos(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Quien selecciono a las personas ya existentes pasa a ser su capataz.
UPDATE postulaciones p
   SET p.capataz_id = (
         SELECT t.usuario_id FROM trazabilidad_logs t
          WHERE t.postulacion_id = p.id
            AND t.accion LIKE 'Cambio de estado: % -> Pre_aprobado_terreno'
          ORDER BY t.fecha_hora ASC, t.id ASC LIMIT 1)
 WHERE p.capataz_id IS NULL
   AND p.estado NOT IN ('Pendiente', 'En_banco', 'Rechazado');

-- Verificacion:
-- SHOW COLUMNS FROM postulaciones LIKE 'capataz_id';
-- SELECT COUNT(*) FROM postulaciones WHERE capataz_id IS NOT NULL;
