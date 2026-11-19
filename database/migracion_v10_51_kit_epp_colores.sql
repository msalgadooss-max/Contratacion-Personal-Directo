-- v10.51: kits de EPP más simples (11 elementos) y con color de casco (rojo / amarillo / azul).
--   epp_items.colores   1 = el elemento viene en colores (hoy solo el casco)
--   epp_items.icono     dibujo que se muestra en el panel (casco, lentes, overol...)
--   epp_kit_cargo.color color elegido para ese cargo (solo en elementos con colores)
--
-- SE CORRE UNA SOLA VEZ POR BASE: en la primera corrida agrega las columnas, deja el catálogo con los 11
-- elementos pedidos y REEMPLAZA los kits sugeridos de la v10.49. Si se vuelve a correr, no hace nada
-- (no pisa lo que Prevención haya configurado después). Los elementos que salen del catálogo se
-- desactivan, no se borran. Funciona igual en MariaDB y en MySQL.
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS icafal_v10_51;
DELIMITER //
CREATE PROCEDURE icafal_v10_51()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = 'epp_items' AND column_name = 'colores')
     AND EXISTS (SELECT 1 FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'epp_items') THEN

    ALTER TABLE epp_items ADD COLUMN colores TINYINT(1) NOT NULL DEFAULT 0 AFTER tipo_talla;
    ALTER TABLE epp_items ADD COLUMN icono VARCHAR(20) NOT NULL DEFAULT 'extra' AFTER colores;
    ALTER TABLE epp_kit_cargo ADD COLUMN color VARCHAR(10) NULL;

    -- Los elementos que ya existían se renombran (así conservan su lugar en el catálogo).
    UPDATE epp_items SET nombre = 'Casco de seguridad', colores = 1, icono = 'casco', orden = 10 WHERE nombre = 'Casco de seguridad con barbiquejo';
    UPDATE epp_items SET nombre = 'Lentes claros', icono = 'lentes', orden = 20 WHERE nombre = 'Lentes de seguridad claros';
    UPDATE epp_items SET nombre = 'Lentes oscuros', icono = 'lentes_oscuros', orden = 30 WHERE nombre = 'Lentes de seguridad oscuros';
    INSERT IGNORE INTO epp_items (nombre, categoria, tipo_talla, colores, icono, orden) VALUES ('Barbiquejo', 'Cabeza', 'ninguna', 0, 'barbiquejo', 40);
    UPDATE epp_items SET nombre = 'Overol', icono = 'overol', orden = 50 WHERE nombre = 'Overol / ropa de trabajo';
    UPDATE epp_items SET nombre = 'Zapato de seguridad', icono = 'zapato', orden = 60 WHERE nombre = 'Zapatos de seguridad (punta de acero)';
    UPDATE epp_items SET icono = 'guantes', orden = 70 WHERE nombre = 'Guantes de cabritilla';
    UPDATE epp_items SET nombre = 'Protector auditivo', icono = 'fono', orden = 80 WHERE nombre = 'Protector auditivo (tapones)';
    UPDATE epp_items SET nombre = 'Chaleco reflectante', icono = 'chaleco', orden = 90 WHERE nombre = 'Chaleco reflectante (geólogo)';
    UPDATE epp_items SET nombre = 'Botas de agua', icono = 'botas', orden = 100 WHERE nombre = 'Botas de goma con punta de acero';
    UPDATE epp_items SET nombre = 'Legionario', icono = 'legionario', orden = 110 WHERE nombre = 'Legionario / cubrenuca';

    -- Los demás elementos de la v10.49 salen del catálogo (desactivados; se pueden reactivar desde el panel).
    UPDATE epp_items SET activo = 0
     WHERE nombre IN ('Careta facial', 'Careta de soldador', 'Protector auditivo tipo fono', 'Respirador desechable (mascarilla)',
                      'Respirador media máscara con filtros', 'Guantes multiflex', 'Guantes de PVC / goma', 'Guantes de soldador',
                      'Traje de agua', 'Delantal de cuero', 'Mangas de cuero', 'Arnés de seguridad cuerpo completo',
                      'Cabo de vida con amortiguador', 'Bloqueador solar', 'Rodilleras');

    -- Kits sugeridos nuevos (Prevención los ajusta). Kit base = cargo_id 0.
    DELETE FROM epp_kit_cargo;
    INSERT INTO epp_kit_cargo (cargo_id, item_id, cantidad, color)
    SELECT 0, i.id, b.cantidad, b.color
      FROM (SELECT 'Casco de seguridad' AS nombre, 1 AS cantidad, 'amarillo' AS color
            UNION ALL SELECT 'Lentes claros', 1, NULL
            UNION ALL SELECT 'Barbiquejo', 1, NULL
            UNION ALL SELECT 'Overol', 2, NULL
            UNION ALL SELECT 'Zapato de seguridad', 1, NULL
            UNION ALL SELECT 'Guantes de cabritilla', 2, NULL
            UNION ALL SELECT 'Protector auditivo', 1, NULL
            UNION ALL SELECT 'Chaleco reflectante', 1, NULL) b
      JOIN epp_items i ON i.nombre = b.nombre;

    INSERT INTO epp_kit_cargo (cargo_id, item_id, cantidad, color)
    SELECT c.id, i.id, x.cantidad, x.color
      FROM (SELECT 'Rigger' AS cargo, 'Casco de seguridad' AS item, 1 AS cantidad, 'rojo' AS color
            UNION ALL SELECT 'Maestro Camarero', 'Casco de seguridad', 1, 'azul'
            UNION ALL SELECT 'Jornal Concretero', 'Botas de agua', 1, NULL
            UNION ALL SELECT 'Jornal Concretero', 'Legionario', 1, NULL) x
      JOIN cargos c ON c.nombre_cargo = x.cargo
      JOIN epp_items i ON i.nombre = x.item;
  END IF;
END//
DELIMITER ;
CALL icafal_v10_51();
DROP PROCEDURE icafal_v10_51;
