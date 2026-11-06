-- v10.31: (1) correos que fallan quedan guardados para reintentar, (2) cambio de
-- clave obligatorio en el primer ingreso, (3) evaluación de recontratación.
-- Se corre en la base en vivo ANTES del Manual Deploy. Compatible con el código
-- anterior (solo agrega una columna con valor por defecto y dos tablas nuevas).
-- El código nuevo NO se cae si falta algo de esto: simplemente no usa la función.

ALTER TABLE usuarios
  ADD COLUMN debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 0 AFTER jefe_terreno_id;

CREATE TABLE IF NOT EXISTS correos_fallidos (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    destinatario       VARCHAR(190) NOT NULL,
    nombre             VARCHAR(150) NOT NULL DEFAULT '',
    asunto             VARCHAR(255) NOT NULL,
    cuerpo             MEDIUMTEXT NOT NULL,
    error              VARCHAR(255) NULL,
    intentos           INT UNSIGNED NOT NULL DEFAULT 1,
    estado             ENUM('fallido', 'reenviado', 'descartado') NOT NULL DEFAULT 'fallido',
    creado_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_intento_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_correos_estado (estado, creado_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluaciones_trabajador (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    postulacion_id   INT UNSIGNED NOT NULL UNIQUE,
    rut              VARCHAR(20) NOT NULL,
    recomienda       ENUM('si', 'con_reservas', 'no') NOT NULL,
    comentario       VARCHAR(500) NULL,
    fecha_termino    DATE NULL,
    evaluado_por     INT UNSIGNED NULL,
    creado_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_eval_rut (rut),
    CONSTRAINT fk_eval_postulacion FOREIGN KEY (postulacion_id) REFERENCES postulaciones(id) ON DELETE CASCADE,
    CONSTRAINT fk_eval_usuario FOREIGN KEY (evaluado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verificación:
-- SHOW COLUMNS FROM usuarios LIKE 'debe_cambiar_password';
-- SHOW TABLES LIKE 'correos_fallidos';
-- SHOW TABLES LIKE 'evaluaciones_trabajador';
