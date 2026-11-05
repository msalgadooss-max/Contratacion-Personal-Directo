-- v10.29: el RUT deja de ser UNIQUE en postulaciones, para poder RECONTRATAR.
-- Hoy, si alguien con un ciclo anterior cerrado (Contratado / Proceso_completo /
-- Rechazado) vuelve a postular, el INSERT falla con "Duplicate entry" (error 500).
-- public/postular.php ya impide dos postulaciones EN CURSO con el mismo RUT.
--
-- Se corre UNA vez en la base en vivo, ANTES del Manual Deploy. Compatible con el
-- código anterior (solo quita una restricción y deja un índice normal).

ALTER TABLE postulaciones DROP INDEX rut, ADD INDEX idx_postulaciones_rut (rut);

-- Verificación (debe mostrar Non_unique = 1):
-- SHOW INDEX FROM postulaciones WHERE Column_name = 'rut';
