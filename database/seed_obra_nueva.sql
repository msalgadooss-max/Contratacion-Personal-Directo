-- =====================================================================
-- v10.32 - Ajuste de una obra NUEVA recien creada (ej. H58).
-- docker-entrypoint.sh corre antes schema.sql + seed_deploy.sql contra la
-- base nueva (con el nombre de base cambiado) y despues este script, con la
-- base nueva como base por defecto. Solo corre UNA vez: cuando la base de la
-- obra todavia no existe.
--
-- Deja la obra "limpia":
--   * sin los usuarios de demostracion del seed (clave conocida Clave123),
--   * sin cupos de partida,
--   * con el/los usuario(s) Desarrollador copiados de la obra principal
--     (H57), con SU MISMA clave -- asi el Desarrollador entra a la obra nueva
--     con sus credenciales de siempre y crea desde la pestaña "Usuarios" a
--     las personas reales de esa obra (JAO, Prevencion, Capataces, etc.).
-- =====================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELETE FROM usuarios;

INSERT INTO usuarios (nombre, correo, password, rol, activo, debe_cambiar_password)
SELECT nombre, correo, password, rol, activo, 0
  FROM icafal_rrhh.usuarios
 WHERE rol = 'Desarrollador' AND activo = 1;

UPDATE cargos SET cupos_totales = 0, cupos_activos = 0;
