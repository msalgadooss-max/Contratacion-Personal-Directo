-- v10.27: límite de intentos fallidos (10 en 15 min) para login y consultas
-- públicas. Se corre UNA vez en la base en vivo (Render Web Shell). Es
-- compatible hacia atrás: el código no bloquea a nadie si la tabla no existe.
CREATE TABLE IF NOT EXISTS intentos_fallidos (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tipo       VARCHAR(20)  NOT NULL,
    clave      VARCHAR(190) NOT NULL,
    creado_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_intentos (tipo, clave, creado_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
