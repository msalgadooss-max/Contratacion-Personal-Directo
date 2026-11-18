-- v10.49: kit de EPP por cargo (lo configura Prevención; le llega a Bodega desde el Día 0).
-- Aditiva: dos tablas nuevas. No toca nada existente. Se puede correr más de una vez.
--
--   epp_items       catálogo de elementos de protección (casco, lentes, guantes...).
--                   tipo_talla: 'calzado' / 'overol' = se usa la talla que declara el postulante;
--                   'ninguna' = se entrega estándar.
--   epp_kit_cargo   cantidad de cada elemento por cargo. cargo_id = 0 es el KIT BASE (lo que recibe
--                   todo trabajador). Para un cargo solo se guardan las DIFERENCIAS contra el base
--                   (un elemento extra, otra cantidad, o cantidad 0 = "este cargo no lo lleva").
--
-- Catálogo y kits iniciales: SUGERIDOS según el EPP básico habitual en obras de edificación en Chile
-- (DS 594, Ley 16.744 y Ley 20.096 de radiación UV). Prevención los revisa y ajusta desde su panel.

-- Importante: los nombres llevan tildes; sin esto un cliente mysql con juego de caracteres latin1 los dejaría ilegibles.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS epp_items (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre     VARCHAR(120) NOT NULL,
    categoria  VARCHAR(40)  NOT NULL DEFAULT 'General',
    tipo_talla ENUM('ninguna','calzado','overol') NOT NULL DEFAULT 'ninguna',
    activo     TINYINT(1)   NOT NULL DEFAULT 1,
    orden      INT          NOT NULL DEFAULT 100,
    UNIQUE KEY uq_epp_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epp_kit_cargo (
    cargo_id INT UNSIGNED      NOT NULL,
    item_id  INT UNSIGNED      NOT NULL,
    cantidad SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (cargo_id, item_id),
    KEY idx_epp_kit_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO epp_items (nombre, categoria, tipo_talla, orden) VALUES
    ('Casco de seguridad con barbiquejo',          'Cabeza',        'ninguna', 10),
    ('Legionario / cubrenuca',                     'Cabeza',        'ninguna', 20),
    ('Lentes de seguridad claros',                 'Ojos y cara',   'ninguna', 30),
    ('Lentes de seguridad oscuros',                'Ojos y cara',   'ninguna', 40),
    ('Careta facial',                              'Ojos y cara',   'ninguna', 50),
    ('Careta de soldador',                         'Ojos y cara',   'ninguna', 60),
    ('Protector auditivo (tapones)',               'Oído',          'ninguna', 70),
    ('Protector auditivo tipo fono',               'Oído',          'ninguna', 80),
    ('Respirador desechable (mascarilla)',         'Respiratoria',  'ninguna', 90),
    ('Respirador media máscara con filtros',       'Respiratoria',  'ninguna', 100),
    ('Guantes de cabritilla',                      'Manos',         'ninguna', 110),
    ('Guantes multiflex',                          'Manos',         'ninguna', 120),
    ('Guantes de PVC / goma',                      'Manos',         'ninguna', 130),
    ('Guantes de soldador',                        'Manos',         'ninguna', 140),
    ('Zapatos de seguridad (punta de acero)',      'Pies',          'calzado', 150),
    ('Botas de goma con punta de acero',           'Pies',          'calzado', 160),
    ('Overol / ropa de trabajo',                   'Cuerpo',        'overol',  170),
    ('Chaleco reflectante (geólogo)',              'Cuerpo',        'ninguna', 180),
    ('Traje de agua',                              'Cuerpo',        'ninguna', 190),
    ('Delantal de cuero',                          'Cuerpo',        'ninguna', 200),
    ('Mangas de cuero',                            'Cuerpo',        'ninguna', 210),
    ('Arnés de seguridad cuerpo completo',         'Trabajo en altura', 'ninguna', 220),
    ('Cabo de vida con amortiguador',              'Trabajo en altura', 'ninguna', 230),
    ('Bloqueador solar',                           'Otros',         'ninguna', 240),
    ('Rodilleras',                                 'Otros',         'ninguna', 250);

-- Kit base (cargo_id = 0): lo que recibe todo trabajador al ingresar.
INSERT IGNORE INTO epp_kit_cargo (cargo_id, item_id, cantidad)
SELECT 0, i.id, b.cantidad
  FROM (SELECT 'Casco de seguridad con barbiquejo' AS nombre, 1 AS cantidad
        UNION ALL SELECT 'Legionario / cubrenuca', 1
        UNION ALL SELECT 'Lentes de seguridad claros', 1
        UNION ALL SELECT 'Protector auditivo (tapones)', 1
        UNION ALL SELECT 'Respirador desechable (mascarilla)', 2
        UNION ALL SELECT 'Guantes de cabritilla', 2
        UNION ALL SELECT 'Zapatos de seguridad (punta de acero)', 1
        UNION ALL SELECT 'Overol / ropa de trabajo', 2
        UNION ALL SELECT 'Chaleco reflectante (geólogo)', 1
        UNION ALL SELECT 'Bloqueador solar', 1) b
  JOIN epp_items i ON i.nombre = b.nombre;

-- Extras sugeridos por cargo (diferencias contra el kit base). Los nombres de cargo que no existan
-- en esta base simplemente no insertan nada.
INSERT IGNORE INTO epp_kit_cargo (cargo_id, item_id, cantidad)
SELECT c.id, i.id, x.cantidad
  FROM (SELECT 'Rigger' AS cargo, 'Arnés de seguridad cuerpo completo' AS item, 1 AS cantidad
        UNION ALL SELECT 'Rigger', 'Cabo de vida con amortiguador', 1
        UNION ALL SELECT 'Maestro Camarero', 'Arnés de seguridad cuerpo completo', 1
        UNION ALL SELECT 'Maestro Camarero', 'Cabo de vida con amortiguador', 1
        UNION ALL SELECT 'Jornal Concretero', 'Botas de goma con punta de acero', 1
        UNION ALL SELECT 'Jornal Concretero', 'Guantes de PVC / goma', 2
        UNION ALL SELECT 'Jornal Concretero', 'Traje de agua', 1
        UNION ALL SELECT 'Jornal Picador', 'Protector auditivo tipo fono', 1
        UNION ALL SELECT 'Jornal Excavador', 'Protector auditivo tipo fono', 1
        UNION ALL SELECT 'Jornal Aseo', 'Guantes de PVC / goma', 2
        UNION ALL SELECT 'Paletero', 'Botas de goma con punta de acero', 1) x
  JOIN cargos c ON c.nombre_cargo = x.cargo
  JOIN epp_items i ON i.nombre = x.item;
