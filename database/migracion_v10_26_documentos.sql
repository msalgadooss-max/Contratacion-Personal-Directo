-- v10.26: cotizaciones AFP + documentos pendientes/opcionales.
-- Se corre UNA vez en la base en vivo (Render Web Shell), ANTES del
-- Manual Deploy. Es compatible hacia atrás: el código anterior sigue
-- funcionando con esto aplicado.
--
--   mysql -h127.0.0.1 -uroot icafal_rrhh < /app/database/migracion_v10_26_documentos.sql
--
-- (o pegar las dos sentencias de abajo una por una).

ALTER TABLE postulacion_documentos
  MODIFY tipo ENUM('cedula_identidad','cedula_identidad_reverso','certificado_afp',
                   'cotizaciones_afp','certificado_salud','ultimo_finiquito',
                   'certificado_residencia') NOT NULL;

CREATE TABLE IF NOT EXISTS documentos_pendientes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    postulacion_id  INT UNSIGNED NOT NULL,
    tipo            VARCHAR(40) NOT NULL,
    condicion       ENUM('pendiente', 'opcional') NOT NULL DEFAULT 'pendiente',
    nota            VARCHAR(255) NULL,
    marcado_por     INT UNSIGNED NULL,
    marcado_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resuelto_at     DATETIME NULL,
    CONSTRAINT fk_docpend_postulacion
        FOREIGN KEY (postulacion_id) REFERENCES postulaciones(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_docpend_usuario
        FOREIGN KEY (marcado_por) REFERENCES usuarios(id)
        ON DELETE SET NULL,
    UNIQUE KEY uq_docpend_postulacion_tipo (postulacion_id, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verificación:
-- SHOW COLUMNS FROM postulacion_documentos LIKE 'tipo';
-- SELECT COUNT(*) FROM documentos_pendientes;
