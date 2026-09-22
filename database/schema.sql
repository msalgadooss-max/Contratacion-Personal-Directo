-- =====================================================================
-- Sistema de Reclutamiento y Onboarding - ICAFAL
-- Script de creacion de base de datos MySQL / MariaDB
-- =====================================================================
-- Motor InnoDB obligatorio (soporte de FKs y transacciones).
-- Charset utf8mb4 para acentos, "ñ" y compatibilidad total.
-- =====================================================================

-- v9.1: SET NAMES explícito además de la bandera --default-character-set
-- de la línea de comandos -- se detectaron acentos/"ñ" corruptos en
-- Render pese a esa bandera, así que se refuerza sin ambigüedad la
-- codificación de ESTA sesión antes de crear nada.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS icafal_rrhh
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE icafal_rrhh;

-- ---------------------------------------------------------------------
-- Tabla: cargos
-- Catalogo de cargos disponibles y su dotacion (cupos).
-- v10.14: cupos_activos se descuenta en terreno/aprobar.php, en el
-- momento en que el Capataz selecciona/arrastra a la persona a este
-- cargo -- NO recien cuando llega a 'Contratado' (asi era antes, via un
-- trigger que se retiro, ver el bloque de TRIGGERS mas abajo). Con el
-- descuento tan tarde, el mismo cupo se podia "prestar" a mas de una
-- persona mientras el resto de la contratacion tomaba dias en cerrar.
-- terreno/deshacer_seleccion.php devuelve el cupo si el Capataz se
-- equivoca de cargo.
-- ---------------------------------------------------------------------
CREATE TABLE cargos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre_cargo    VARCHAR(100) NOT NULL,
    -- v10.14 (pedido explicito del usuario): codigo exacto de la hoja
    -- "Cargos" del template Buk "Trabajos.xls" -- necesario para el
    -- segundo exportador ("Codigo Cargo*"). NULL para cargos que aun no
    -- se hayan mapeado a un codigo real de Buk.
    codigo_buk      VARCHAR(80) NULL,
    cupos_totales   INT UNSIGNED NOT NULL DEFAULT 0,
    cupos_activos   INT UNSIGNED NOT NULL DEFAULT 0,
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    creado_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: usuarios
-- Usuarios internos del sistema (no incluye a los postulantes).
-- password: hash bcrypt generado con password_hash() de PHP.
-- 'Gerencia' es un rol de solo lectura: ve TODAS las postulaciones en
-- TODAS las fases (panel de avance global), pero no puede aprobar,
-- rechazar ni modificar ningun estado, y no ve datos_contratacion.
-- ---------------------------------------------------------------------
CREATE TABLE usuarios (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre          VARCHAR(150) NOT NULL,
    correo          VARCHAR(150) NOT NULL UNIQUE,
    password        VARCHAR(255) NOT NULL,
    rol             ENUM(
                        'Jefe_Terreno',
                        'Capataz',
                        'Admin_Contrato',
                        'Prevencionista',
                        'Jefe_Bodega',
                        'Jefe_Administrativo',
                        'Gerencia',
                        'Porteria',
                        'Desarrollador'
                    ) NOT NULL,
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    creado_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: solicitudes_cupo (v4)
-- Bitacora de cada solicitud de cupos que hace Jefe_Terreno para un
-- cargo especifico (ej. "necesito 8 Jornal Concretero"). Cada fila
-- aprobada suma su "cantidad" a cargos.cupos_totales y cupos_activos.
-- Se registra por separado de trazabilidad_logs porque es un evento a
-- nivel de CARGO, no de una postulacion puntual.
-- ---------------------------------------------------------------------
-- v6.9: la solicitud de cupos de Jefe_Terreno ya NO abre cupos de
-- inmediato -- queda "Pendiente" hasta que Admin_Contrato la aprueba
-- (recien ahi se suma a cupos_totales/cupos_activos del cargo, ver
-- admin_contrato/solicitudes_cupo_aprobar.php). Esto es lo que en la
-- reunion con Ricardo se llamo "vacante": una solicitud aprobada.
-- v6.10: cargo_id ahora acepta NULL -- Jefe_Terreno puede pedir cupos
-- para un cargo que todavia no existe en el catalogo (cargo_nuevo_nombre),
-- en vez de estar limitado a los cargos predeterminados. Se crea el
-- cargo real recien cuando Admin_Contrato aprueba la solicitud (ver
-- admin_contrato/solicitudes_cupo_aprobar.php), nunca antes.
CREATE TABLE solicitudes_cupo (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cargo_id            INT UNSIGNED NULL,
    cargo_nuevo_nombre  VARCHAR(100) NULL,
    cantidad            INT UNSIGNED NOT NULL,
    usuario_id          INT UNSIGNED NULL,
    estado              ENUM('Pendiente', 'Aprobada', 'Rechazada') NOT NULL DEFAULT 'Pendiente',
    resuelta_por        INT UNSIGNED NULL,
    resuelta_at         DATETIME NULL,
    motivo_rechazo      VARCHAR(255) NULL,
    -- v10.3: Admin_Contrato puede abrir MENOS o MAS cupos que los pedidos
    -- por Jefe_Terreno (ej. pidio 5, pero solo hay presupuesto para 3).
    -- `cantidad` arriba sigue siendo el pedido ORIGINAL, sin tocar, para
    -- que quede trazabilidad de que se pidio versus que se aprobo.
    cantidad_aprobada     INT UNSIGNED NULL,
    observacion_aprobacion VARCHAR(255) NULL,
    creado_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_solicitud_cargo
        FOREIGN KEY (cargo_id) REFERENCES cargos(id),
    CONSTRAINT fk_solicitud_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_solicitud_resuelta_por
        FOREIGN KEY (resuelta_por) REFERENCES usuarios(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: postulaciones (datos publicos / no sensibles)
-- Un registro por persona que postula via el formulario publico (QR).
-- codigo_seguimiento: 6 caracteres alfanumericos, usado por el
--   postulante junto a su RUT para consultar su estado.
-- token_privado: se genera solo al pasar a 'Aprobado_admin'; habilita
--   el formulario privado de datos de contratacion y expira.
-- 'En_banco' (v2): la persona postulo con interes en un cargo que en
--   ese momento no tenia cupos_activos. Queda fuera del pipeline hasta
--   que Jefe_Terreno la "invita" a un cupo ya autorizado (ver
--   backend/api/terreno/banco_invitar.php), momento en que pasa a
--   'Pre_aprobado_terreno' y recien ahi nace la postulacion formal.
-- cv_ruta_archivo (v2): ruta relativa del CV subido en la Fase 0,
--   guardado fuera del webroot (backend/uploads/cv/). Se sirve
--   unicamente a traves de backend/api/documentos/ver.php, que exige
--   sesion de un rol autorizado -- nunca es una URL publica directa.
--
-- v3 -- alineado a "Template Empleados.xls" (planilla que se sube a
-- Buk): apellido/segundo_apellido/nombre, tipo_documento y region
-- corresponden a columnas VERDES de esa plantilla (Etapa 1).
-- nombre_completo se sigue guardando (derivado de nombre+apellidos)
-- para no romper todo el resto del sistema que ya lo usa para mostrar
-- a la persona en pantallas y correos.
-- tipo_documento='Otro' significa que la persona no tiene RUT chileno
-- (extranjero sin cedula aun): no se valida digito verificador y se
-- marca con un indicador visual en los dashboards.
--
-- v3.1 -- Admin_Contrato y el postulante trabajan EN PARALELO, no en
-- cadena: al aprobar, Jefe_Terreno dispara los dos caminos a la vez
-- (otorgarAccesoEtapa2() le da el link al postulante Y deja la
-- postulacion visible para Admin_Contrato). admin_autorizado_at marca
-- cuando Admin_Contrato ya dijo que si; la Etapa 2 completa se detecta
-- por la sola EXISTENCIA de la fila en datos_contratacion (no por un
-- estado aparte). El estado recien avanza a 'Aprobado_admin' cuando
-- AMBAS condiciones se cumplen -- ver
-- backend/includes/functions.php::intentarAvanzarAAprobadoAdmin().
-- Por eso 'Datos_completados' practicamente no se usa como estado en
-- v3.1 (se deja en el ENUM por compatibilidad): quien termina primero
-- simplemente espera a que el otro termine, sin que la postulacion
-- retroceda ni se bloquee.
-- El nuevo orden de estados visible es:
--   Pendiente -> Pre_aprobado_terreno -> Aprobado_admin ->
--   [Induccion_ok] -> [EPP_listo] -> Contratado
-- (los dos ultimos solo si Prevencion/Bodega estan activos).
--
-- v4 -- obra: nombre de la obra a la que corresponde esta postulacion
-- (hoy fija via config OBRA_NOMBRE, se guarda igual por trazabilidad si
-- en el futuro conviven varias obras). identidad_verificada_at/_por:
-- verificacion MANUAL (con un clic, no OCR) de que el RUT declarado
-- coincide con el de la foto/PDF de cedula subida en Etapa 2 -- gatilla
-- junto con datos_jao el poder "Firmar Contrato" el dia 2 (ver
-- admin_general/verificar_identidad.php y firmar_contrato.php).
-- ---------------------------------------------------------------------
CREATE TABLE postulaciones (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tipo_documento              ENUM('RUT', 'Otro') NOT NULL DEFAULT 'RUT',
    rut                         VARCHAR(20) NOT NULL UNIQUE,
    nombre_completo             VARCHAR(200) NOT NULL,
    nombre                      VARCHAR(100) NOT NULL DEFAULT '',
    apellido                    VARCHAR(100) NOT NULL DEFAULT '',
    segundo_apellido            VARCHAR(100) NULL,
    telefono                    VARCHAR(20) NOT NULL,
    correo                      VARCHAR(150) NOT NULL,
    region                      VARCHAR(100) NOT NULL DEFAULT '',
    comuna                      VARCHAR(100) NOT NULL,
    cargo_id                    INT UNSIGNED NOT NULL,
    obra                        VARCHAR(150) NOT NULL DEFAULT '',
    codigo_seguimiento          CHAR(6) NOT NULL UNIQUE,
    estado                      ENUM(
                                    'En_banco',
                                    'Pendiente',
                                    'Pre_aprobado_terreno',
                                    'Aprobado_admin',
                                    'Datos_completados',
                                    'Induccion_ok',
                                    'EPP_listo',
                                    'Contratado',
                                    -- v7: cierre operativo real (reunion Ricardo, 31-ago). 'Contratado'
                                    -- ya no es el ultimo estado: falta que Capataz o Jefe_Terreno
                                    -- confirmen que recibieron al trabajador en el frente de obra
                                    -- (ver terreno/recepcion_confirmar.php). El trigger que descuenta
                                    -- el cupo sigue disparando en la transicion HACIA 'Contratado',
                                    -- asi que agregar este estado despues no lo afecta.
                                    'Proceso_completo',
                                    'Rechazado'
                                ) NOT NULL DEFAULT 'Pendiente',
    token_privado               VARCHAR(64) NULL,
    token_expira_at             DATETIME NULL,
    -- v7: selección en terreno en DOS pasos secuenciales (reunión Ricardo,
    -- 31-ago). Jefe_Terreno hace un primer filtro (aprobado_jt_at) SIN
    -- cambiar `estado` -- la postulación sigue 'Pendiente'. Recién cuando
    -- el Capataz la selecciona en persona (terreno/aprobar.php, ver
    -- filtro por rol) el estado avanza a 'Pre_aprobado_terreno', igual
    -- que antes. Antes de este cambio, Jefe_Terreno y Capataz eran
    -- intercambiables para un unico paso; ahora son secuenciales.
    aprobado_jt_at              DATETIME NULL,
    aprobado_jt_por             INT UNSIGNED NULL,
    admin_autorizado_at         DATETIME NULL,
    admin_autorizado_por        INT UNSIGNED NULL,
    -- v7: QR de "ingreso a faena" (dia 1) -- se envia por correo apenas
    -- se llega a 'Aprobado_admin' (mismo momento en que hoy se avisa a
    -- Prevencion/Bodega, ver notificarIngresoFaena()). Portico escanea
    -- ese QR (con la camara de su celular, sin apps ni login: el QR
    -- codifica una URL publica) y confirma en persona -- recien ahi el
    -- JAO puede empezar a verificar documentos (ver
    -- admin_general/verificar_identidad.php). No es lo mismo que el QR
    -- de "acceso a la obra" del correo de contratacion exitosa (ese
    -- sigue igual, es el cierre del dia 2).
    ingreso_faena_at            DATETIME NULL,
    -- v7: el JAO ahora tiene DOS acciones separadas en vez de una sola
    -- "Finalizar Contratacion": verificar documentos (dia 1, columna
    -- identidad_verificada_at que ya existia) y firmar el contrato
    -- (dia 2, 8am, columnas nuevas de aqui abajo). Bodega es quien
    -- recien pasa el estado a 'Contratado' al entregar el EPP -- ver
    -- bodega/marcar_epp.php.
    contrato_firmado_at         DATETIME NULL,
    contrato_firmado_por        INT UNSIGNED NULL,
    -- v7: cierre operativo -- Capataz o Jefe_Terreno confirman que
    -- fueron a buscar al trabajador ya contratado y equipado (ver
    -- terreno/recepcion_confirmar.php). Recien ahi estado ->
    -- 'Proceso_completo'.
    recibido_terreno_at         DATETIME NULL,
    recibido_terreno_por        INT UNSIGNED NULL,
    identidad_verificada_at     DATETIME NULL,
    identidad_verificada_por    INT UNSIGNED NULL,
    -- v5: token de un solo proposito para que el postulante corrija
    -- SOLO el/los documentos que el JAO marco como rechazados (ver
    -- admin_general/rechazar_documento.php), sin repetir toda la
    -- Etapa 2. Se genera/renueva cada vez que hay un rechazo nuevo.
    token_subsanacion           VARCHAR(64) NULL,
    token_subsanacion_expira_at DATETIME NULL,
    consentimiento_ley19628     TINYINT(1) NOT NULL DEFAULT 0,
    cv_ruta_archivo             VARCHAR(255) NULL,
    -- v6.9: alternativa cuando el postulante no tiene CV (Ricardo,
    -- reunion 28-ago) -- ultimo cargo/fecha/descripcion en texto libre,
    -- en vez de bloquearlo por no tener el archivo.
    experiencia_sin_cv          TEXT NULL,
    -- v10.19 (pedido explicito del usuario, tras la reunion con la obra
    -- del 16-09): "match" entre el postulante y el Capataz que lo esta
    -- esperando -- se declara acá mismo en Etapa 1 (portería), NO
    -- reserva cupo por si solo todavia (eso queda para una version
    -- futura que ademas reparta los cupos por capataz/frente), pero le
    -- permite al Capataz correcto identificar de inmediato "este viene
    -- por mi" en vez de tener que llamar a todos los que llegan.
    -- Opcional: el postulante puede no saber quien lo convoco.
    capataz_esperado_id         INT UNSIGNED NULL,
    exportado_at                DATETIME NULL,
    creado_at                   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_postulaciones_cargo
        FOREIGN KEY (cargo_id) REFERENCES cargos(id),
    CONSTRAINT fk_aprobado_jt_por
        FOREIGN KEY (aprobado_jt_por) REFERENCES usuarios(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_admin_autorizado_por
        FOREIGN KEY (admin_autorizado_por) REFERENCES usuarios(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_contrato_firmado_por
        FOREIGN KEY (contrato_firmado_por) REFERENCES usuarios(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_recibido_terreno_por
        FOREIGN KEY (recibido_terreno_por) REFERENCES usuarios(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_identidad_verificada_por
        FOREIGN KEY (identidad_verificada_por) REFERENCES usuarios(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_capataz_esperado
        FOREIGN KEY (capataz_esperado_id) REFERENCES usuarios(id)
        ON DELETE SET NULL,
    INDEX idx_postulaciones_estado (estado),
    INDEX idx_postulaciones_token (token_privado),
    INDEX idx_postulaciones_token_subsanacion (token_subsanacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: datos_contratacion (datos privados/sensibles)
-- Se llena UNA sola vez, por el propio postulante, via el link con
-- token privado (Fase 2 -- ver nota de reordenamiento arriba). Solo
-- Admin_Contrato y Jefe_Administrativo tienen acceso a esta tabla a
-- nivel de backend.
-- v3: nacionalidad/region/comuna/ciudad/pais/estudios se agregan para
-- calzar con las columnas celestes de "Template Empleados.xls". Las
-- tallas de EPP quedan NULL-ables porque Bodega esta pausado (v2) y
-- ya no se piden en el formulario -- si Bodega se reactiva, se vuelven
-- a pedir sin necesitar otro cambio de esquema.
-- ---------------------------------------------------------------------
-- v3.3: afp_alerta_jao se marca en el momento de guardar (ver
-- guardar_datos.php) cuando el postulante elige un regimen previsional
-- antiguo (Servicios de Seguro Social / Empart) en vez de una de las 7
-- AFP vigentes -- se ACEPTA el dato igual, pero se avisa al JAO cuando
-- abre su dashboard para que lo verifique manualmente.
CREATE TABLE datos_contratacion (
    id                              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    postulacion_id                  INT UNSIGNED NOT NULL UNIQUE,
    fecha_nacimiento                DATE NOT NULL,
    estado_civil                    VARCHAR(30) NOT NULL,
    sexo                            VARCHAR(20) NOT NULL,
    nacionalidad                    VARCHAR(60) NOT NULL DEFAULT '',
    direccion_exacta                VARCHAR(255) NOT NULL,
    region                          VARCHAR(100) NOT NULL DEFAULT '',
    comuna                          VARCHAR(100) NOT NULL DEFAULT '',
    ciudad                          VARCHAR(100) NOT NULL DEFAULT '',
    pais                            VARCHAR(80) NOT NULL DEFAULT 'Chile',
    afp                             VARCHAR(100) NOT NULL,
    afp_alerta_jao                  TINYINT(1) NOT NULL DEFAULT 0,
    isapre_fonasa                   VARCHAR(100) NOT NULL,
    estudios                        VARCHAR(60) NOT NULL DEFAULT '',
    banco                           VARCHAR(100) NOT NULL,
    tipo_cuenta                     VARCHAR(50) NOT NULL,
    numero_cuenta                   VARCHAR(50) NOT NULL,
    contacto_emergencia_nombre      VARCHAR(150) NOT NULL,
    contacto_emergencia_telefono    VARCHAR(20) NOT NULL,
    talla_calzado                   VARCHAR(10) NULL,
    talla_pantalon                  VARCHAR(10) NULL,
    talla_overol                    VARCHAR(10) NULL,
    talla_polera                    VARCHAR(10) NULL,
    creado_at                       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_datos_postulacion
        FOREIGN KEY (postulacion_id) REFERENCES postulaciones(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: postulacion_documentos (v3)
-- Los 5 documentos legales que el postulante sube en la Fase 2, mas el
-- CV de la Fase 0 se maneja aparte (postulaciones.cv_ruta_archivo, ya
-- existia). Un renglon por documento; se puede resubir (upsert) sin
-- duplicar filas gracias a la UNIQUE(postulacion_id, tipo).
--
-- v5: el JAO puede rechazar un documento puntual (incluida la cedula,
-- para el caso "el RUT de la foto no coincide") con un comentario que
-- se le envia al postulante. Esto NO retrocede el estado de la
-- postulacion ni deshace lo ya avanzado (admin_autorizado_at,
-- etapa2_completada siguen intactos): solo bloquea "Firmar Contrato"
-- (dia 2) hasta que ese documento puntual se resuba (ver
-- admin_general/rechazar_documento.php y firmar_contrato.php).
-- ---------------------------------------------------------------------
CREATE TABLE postulacion_documentos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    postulacion_id  INT UNSIGNED NOT NULL,
    -- v6: cedula_identidad_reverso se agrega porque la cedula chilena
    -- (y muchos documentos de identidad extranjeros) trae datos
    -- relevantes por ambos lados -- se piden y validan como dos
    -- documentos independientes (cada uno se puede rechazar por
    -- separado si uno de los dos sale ilegible).
    tipo            ENUM(
                        'cedula_identidad',
                        'cedula_identidad_reverso',
                        'certificado_afp',
                        'certificado_salud',
                        'ultimo_finiquito',
                        'certificado_residencia'
                    ) NOT NULL,
    ruta_archivo    VARCHAR(255) NOT NULL,
    rechazado_at    DATETIME NULL,
    rechazado_por   INT UNSIGNED NULL,
    motivo_rechazo  VARCHAR(500) NULL,
    resubido_at     DATETIME NULL,
    subido_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_documento_postulacion
        FOREIGN KEY (postulacion_id) REFERENCES postulaciones(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_documento_rechazado_por
        FOREIGN KEY (rechazado_por) REFERENCES usuarios(id)
        ON DELETE SET NULL,
    UNIQUE KEY uq_postulacion_tipo (postulacion_id, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: datos_jao (v3)
-- Los campos AMARILLOS de "Template Empleados.xls": los llena el Jefe
-- Administrativo (JAO) despues de revisar la ficha completa, justo
-- antes de "Finalizar Contratacion". Separada de datos_contratacion
-- porque es informacion de otra naturaleza (parametros de nomina/Buk,
-- no datos personales del trabajador) y la llena otro rol.
-- ---------------------------------------------------------------------
CREATE TABLE datos_jao (
    id                              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    postulacion_id                  INT UNSIGNED NOT NULL UNIQUE,
    codigo_ficha                    VARCHAR(50) NOT NULL,
    ingreso_compania                DATE NOT NULL,
    forma_pago                      VARCHAR(60) NOT NULL,
    regimen_previsional             VARCHAR(60) NOT NULL,
    afc                             VARCHAR(60) NOT NULL,
    jubilado                        VARCHAR(10) NOT NULL DEFAULT 'No',
    escala_sueldo                   VARCHAR(60) NOT NULL,
    proceso                         VARCHAR(60) NOT NULL,
    tipo_transfer                   VARCHAR(60) NOT NULL,
    fecha_reconocimiento            DATE NULL,
    recomendado                     VARCHAR(10) NOT NULL DEFAULT 'No',
    bono_obra                       VARCHAR(50) NULL,
    retencion_judicial              VARCHAR(20) NOT NULL DEFAULT 'No está',
    seguro_covid_fecha_inicio       DATE NULL,
    -- v10.14 (pedido explícito del usuario, tras revisar un envío real a
    -- Buk): solo aplican cuando el postulante está en una Isapre real
    -- (datos_contratacion.isapre_fonasa <> 'Fonasa') -- el JAO las llena
    -- a mano en ese caso. Si está en Fonasa, quedan NULL a propósito
    -- (el exportador las deja en blanco, no en "0").
    plan_isapre_uf                  DECIMAL(10,2) NULL,
    plan_isapre_pesos               INT UNSIGNED NULL,
    discapacidad                    VARCHAR(10) NOT NULL DEFAULT 'No',
    fecha_notif_discapacidad        DATE NULL,
    invalidez                       VARCHAR(60) NOT NULL DEFAULT 'No',
    fecha_notif_invalidez           DATE NULL,
    creado_por                      INT UNSIGNED NULL,
    creado_at                       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_jao_postulacion
        FOREIGN KEY (postulacion_id) REFERENCES postulaciones(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_jao_usuario
        FOREIGN KEY (creado_por) REFERENCES usuarios(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: trazabilidad_logs
-- Bitacora de todo cambio de estado y accion relevante.
-- usuario_id es NULL cuando la accion la origina el propio postulante
-- (postulacion publica, envio de datos privados).
-- ---------------------------------------------------------------------
CREATE TABLE trazabilidad_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    postulacion_id  INT UNSIGNED NOT NULL,
    usuario_id      INT UNSIGNED NULL,
    accion          VARCHAR(255) NOT NULL,
    fecha_hora      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_postulacion
        FOREIGN KEY (postulacion_id) REFERENCES postulaciones(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_log_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE SET NULL,
    INDEX idx_log_postulacion (postulacion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: cierre_remuneraciones (v3)
-- Fila unica (id=1) que define cuando remuneraciones esta procesando
-- (desde/hasta = ventana en que SI se puede contratar; ver
-- cierreRemuneracionesActivo() -- fuera de ese rango, o dentro del
-- cierre de quincena, "Finalizar Contratacion" queda bloqueado, pero el
-- resto del proceso -- postulacion, aprobaciones, datos privados --
-- sigue funcionando con normalidad).
-- ---------------------------------------------------------------------
CREATE TABLE cierre_remuneraciones (
    id              TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    activo          TINYINT(1) NOT NULL DEFAULT 0,
    -- v10.14 (pedido explícito del usuario, item 15): antes era solo un
    -- interruptor manual on/off. Ahora el JAO puede en cambio programar
    -- una VENTANA de fechas (desde/hasta) -- mientras la fecha de hoy
    -- caiga adentro, el cierre se considera activo automáticamente, sin
    -- que nadie tenga que acordarse de desactivarlo a mano. `activo`
    -- se mantiene como override manual rápido para un cierre de
    -- emergencia sin fechas definidas.
    desde           DATE NULL,
    hasta           DATE NULL,
    -- v10.15 (pedido explícito del usuario, item 5 de la lista post-
    -- prueba): "en la quincena también se cierra el proceso unos días,
    -- más acotado". A diferencia de desde/hasta (ventana permitida), este
    -- es un rango BLOQUEADO que se aplica aunque hoy esté dentro de la
    -- ventana mensual -- dos cierres de remuneraciones al mes (quincena y
    -- mes), cada uno con su propio rango de fechas.
    quincena_desde  DATE NULL,
    quincena_hasta  DATE NULL,
    actualizado_por INT UNSIGNED NULL,
    actualizado_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cierre_usuario
        FOREIGN KEY (actualizado_por) REFERENCES usuarios(id)
        ON DELETE SET NULL,
    CONSTRAINT chk_cierre_id_unico CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cierre_remuneraciones (id, activo) VALUES (1, 0);

-- ---------------------------------------------------------------------
-- Tabla: encuesta_satisfaccion (v10.14)
-- Encuesta anonima para los trabajadores que prueban el proceso durante
-- el piloto: 8 preguntas del 1 al 7 (1 = muy dificil/malo, 7 = muy
-- facil/bueno) sobre distintas aristas del proceso, mas un comentario
-- abierto opcional. Publica y sin RUT a proposito -- lo que importa es
-- la percepcion general, no poder rastrear quien respondio (ver
-- backend/api/public/encuesta_guardar.php y frontend/public/encuesta.html).
-- ---------------------------------------------------------------------
CREATE TABLE encuesta_satisfaccion (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- v10.14 (pedido explícito del usuario): "sin nombres y con edad
    -- como dato... es importante marcar eso... para sacar una medición
    -- y promedio" -- se mantiene anónima (nada de nombre) pero se pide
    -- la edad, para poder cruzarla luego contra las respuestas.
    edad                 TINYINT UNSIGNED NOT NULL,
    cargo_probado        VARCHAR(100) NULL,
    claridad_pasos       TINYINT UNSIGNED NOT NULL,
    facilidad_datos      TINYINT UNSIGNED NOT NULL,
    facilidad_documentos TINYINT UNSIGNED NOT NULL,
    claridad_correos     TINYINT UNSIGNED NOT NULL,
    tiempo_espera        TINYINT UNSIGNED NOT NULL,
    claridad_seguimiento TINYINT UNSIGNED NOT NULL,
    dificultad_general   TINYINT UNSIGNED NOT NULL,
    recomendaria         TINYINT UNSIGNED NOT NULL,
    comentario           VARCHAR(1000) NULL,
    creado_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: dev_accesos (v5)
-- Bitacora de cada vez que el rol Desarrollador "entra como" otro
-- usuario desde el panel de desarrollador, para poder auditar quien
-- vio que perfil y cuando -- ver dev/entrar_como.php.
-- ---------------------------------------------------------------------
CREATE TABLE dev_accesos (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desarrollador_id    INT UNSIGNED NOT NULL,
    usuario_objetivo_id INT UNSIGNED NOT NULL,
    rol_objetivo        VARCHAR(30) NOT NULL,
    creado_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dev_acceso_dev
        FOREIGN KEY (desarrollador_id) REFERENCES usuarios(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_dev_acceso_objetivo
        FOREIGN KEY (usuario_objetivo_id) REFERENCES usuarios(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: dev_qr_accesos (v6.4)
-- Enlaces de acceso directo ("magic link") generados desde el panel de
-- Desarrollador: quien escanea el QR entra directo al dashboard del rol
-- indicado, sin pedir correo/clave. Pensado para repartir por WhatsApp
-- o imprimir en una demo -- multiuso mientras no expire, nunca para un
-- rol Desarrollador (ver dev/qr_login.php).
-- ---------------------------------------------------------------------
CREATE TABLE dev_qr_accesos (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token               VARCHAR(64) NOT NULL UNIQUE,
    usuario_objetivo_id INT UNSIGNED NOT NULL,
    creado_por          INT UNSIGNED NULL,
    expira_at           DATETIME NOT NULL,
    creado_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_qracceso_objetivo
        FOREIGN KEY (usuario_objetivo_id) REFERENCES usuarios(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_qracceso_creador
        FOREIGN KEY (creado_por) REFERENCES usuarios(id)
        ON DELETE SET NULL,
    INDEX idx_qracceso_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- v9: Catálogo de cursos de Prevención (reunión Ricardo, 31-ago),
-- evolución del módulo de "videos de inducción" de v6.9. Inspirado en
-- academiamlp.cl ("Mi Formación"): cursos agrupados por categoría, cada
-- uno con su video y una evaluación simple que el propio postulante
-- responde -- pero que Prevención (una persona) revisa y aprueba o
-- reprueba a mano, no un corrector automático. Sin vigencia/vencimiento
-- por ahora (queda abierto para más adelante, dado el alto % de
-- reingreso). Aplica SOLO a postulantes en proceso, no a trabajadores ya
-- contratados -- por eso cuelga de `postulaciones`, igual que el resto
-- del módulo de Prevención.
-- El catálogo (titulo/categoria/url/preguntas) es editable sin tocar
-- código -- las URLs reales las define Prevención cuando tenga el
-- contenido grabado; por ahora quedan con un valor placeholder en el
-- seed.
-- =====================================================================
CREATE TABLE cursos_induccion (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    categoria             VARCHAR(80) NOT NULL DEFAULT 'General',
    titulo                VARCHAR(150) NOT NULL,
    descripcion           VARCHAR(300) NULL,
    duracion_estimada     VARCHAR(40) NULL,
    url                   VARCHAR(500) NOT NULL,
    -- Array JSON de preguntas abiertas (texto libre), ej:
    -- ["¿Qué debes hacer si detectas una condición insegura?", "..."].
    -- El postulante responde en texto libre; Prevención lee las
    -- respuestas y aprueba/reprueba -- no hay corrección automática.
    preguntas_evaluacion  JSON NOT NULL,
    orden                 INT UNSIGNED NOT NULL DEFAULT 0,
    activo                TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE postulacion_cursos (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    postulacion_id       INT UNSIGNED NOT NULL,
    curso_id             INT UNSIGNED NOT NULL,
    visto_at             DATETIME NULL,
    -- Array JSON de respuestas del postulante, mismo orden que
    -- preguntas_evaluacion. enviado_at NULL = todavía no envía su
    -- evaluación (o la reprobaron y aún no reintenta); enviado_at
    -- puesto + estado='Pendiente' = enviada y esperando que Prevención
    -- la revise.
    respuestas           JSON NULL,
    enviado_at           DATETIME NULL,
    estado               ENUM('Pendiente', 'Aprobado', 'Reprobado') NOT NULL DEFAULT 'Pendiente',
    evaluado_at          DATETIME NULL,
    evaluado_por         INT UNSIGNED NULL,
    comentario_evaluador VARCHAR(500) NULL,
    UNIQUE KEY uq_postulacion_curso (postulacion_id, curso_id),
    CONSTRAINT fk_pcurso_postulacion
        FOREIGN KEY (postulacion_id) REFERENCES postulaciones(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_pcurso_curso
        FOREIGN KEY (curso_id) REFERENCES cursos_induccion(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_pcurso_evaluador
        FOREIGN KEY (evaluado_por) REFERENCES usuarios(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- TRIGGERS
-- =====================================================================
-- El backend, justo antes de cualquier UPDATE de estado, ejecuta:
--   SET @current_user_id = <id del usuario logueado>;   (o NULL si es
--   una accion publica, ej. el postulante llenando su formulario)
-- Con esto el trigger deja registrado QUIEN y CUANDO (con segundos)
-- hizo cada cambio de estado, sin depender de que cada endpoint
-- recuerde insertar el log manualmente -> evita omisiones y duplica
-- la logica de auditoria en un solo lugar confiable.
-- =====================================================================

DELIMITER $$

CREATE TRIGGER trg_postulaciones_log_estado
AFTER UPDATE ON postulaciones
FOR EACH ROW
BEGIN
    IF NEW.estado <> OLD.estado THEN
        INSERT INTO trazabilidad_logs (postulacion_id, usuario_id, accion, fecha_hora)
        VALUES (
            NEW.id,
            @current_user_id,
            CONCAT('Cambio de estado: ', OLD.estado, ' -> ', NEW.estado),
            NOW()
        );
    END IF;
END$$

-- v10.14 (pedido explicito del usuario, nota de mano "Mejorar Post.
-- Piloto"): el trigger trg_postulaciones_descuenta_cupo que vivia acá
-- -- descontaba 1 cupo activo recien cuando la postulacion llegaba a
-- 'Contratado' -- se RETIRA. El cupo ahora se descuenta mucho antes, en
-- terreno/aprobar.php, en el momento en que el Capataz selecciona a la
-- persona (ver el comentario en la tabla `cargos` mas arriba). Dejarlo
-- descontando tambien acá habria descontado el cupo DOS VECES por la
-- misma persona. Si se despliega sobre una base de datos que ya lo
-- tiene creado, hay que correr a mano:
--   DROP TRIGGER IF EXISTS trg_postulaciones_descuenta_cupo;

DELIMITER ;

-- =====================================================================
-- DATOS DE EJEMPLO (opcional - borrar en produccion si no aplica)
-- =====================================================================
-- v4: catalogo de cargos para Obra H57 Padre Hurtado IV. Todos parten
-- con cupos_totales=0 / cupos_activos=0: los cupos ya no se cargan a
-- mano aqui, se abren via el flujo de "Solicitar cupos" de
-- Jefe_Terreno (ver terreno/solicitar_cupo.php), que es lo que decide
-- cuantos postulantes ven "cupos disponibles" para cada cargo.
-- v10.14 (pedido explícito del usuario): nombre_cargo debe calzar
-- EXACTO con el nombre oficial de la hoja "Cargos" del template Buk
-- "Trabajos.xls" (mismo tilde/mayúscula), y codigo_buk es el código de
-- esa misma hoja. La lista completa (27 cargos) sale de cruzar dos
-- fuentes reales que aportó el usuario: (1) el catálogo oficial de
-- Buk, y (2) el historial real de dotación de la obra H57 (Conjunto
-- Padre Hurtado Etapa 4) en "Version_Rev.xlsx", filtrando por esa obra
-- y excluyendo cargos de línea de mando (Capataz de Obras, Jefe de
-- Obras, Jefe de Patio, Jefe de Bodega), administrativos (Ayudante
-- Administrativo, Ayudante Oficina Técnica), y profesionales/encargados
-- (Asist. en Prev. de Riesgos, Asistente de Calidad) -- ninguno de esos
-- es "cupo" de mano de obra directa que Terreno deba poder solicitar.
-- Los primeros 14 ya estaban en el catálogo (con 2 nombres corregidos:
-- "Albañil" y "Maestro Urbanización" -- Buk los tiene sin tilde); los
-- 13 siguientes son nuevos, confirmados en el historial real de H57.
INSERT INTO cargos (nombre_cargo, codigo_buk, cupos_totales, cupos_activos) VALUES
    ('Maestro Urbanizacion', 'maestro_urbanizacion', 0, 0),
    ('Ayudante Maestro Urbanización', 'ayudante_maestro_urbanizacion', 0, 0),
    ('Ayudante de Maestro', 'ayudante_de_maestro', 0, 0),
    ('Maestro Pintor', 'maestro_pintor', 0, 0),
    ('Carpintero', 'carpintero', 0, 0),
    ('Gasfiter', 'gasfiter', 0, 0),
    ('Ayudante de Gásfiter', 'ayudante_de_gasfiter', 0, 0),
    ('Paletero', 'paletero', 0, 0),
    ('Jornal Concretero', 'jornal_concretero', 0, 0),
    ('Albanil', 'albanil', 0, 0),
    ('Ayudante Carpintero', 'ayudante_carpintero', 0, 0),
    ('Maestro Camarero', 'maestro_camarero', 0, 0),
    ('Jornal Picador', 'jornal_picador', 0, 0),
    ('Jornal Excavador', 'jornal_excavador', 0, 0),
    ('Jornalero', 'jornalero', 0, 0),
    ('Ayudante', 'ayudante', 0, 0),
    ('Rigger', 'rigger', 0, 0),
    ('Ayudante Trazador', 'ayudante_trazador', 0, 0),
    ('Trazador', 'trazador', 0, 0),
    ('Chofer Operador Máquina', 'chofer_operador_maquina', 0, 0),
    ('Jornal Aseo', 'jornal_aseo', 0, 0),
    ('Ayudante de Bodega', 'ayudante_de_bodega', 0, 0),
    ('Ayudante Elec Mantención', 'ayudante_elec_mantencion', 0, 0),
    ('Pañolero', 'panolero', 0, 0),
    ('Junior de Obra', 'junior_de_obra', 0, 0),
    ('Electrico de Mantencion', 'electrico_de_mantencion', 0, 0),
    ('Nivelador', 'nivelador', 0, 0);

-- v10.5 (Mejorar APP, punto 2): el postulante ya no elige cargo al
-- postular -- el Capataz se lo asigna despues, en persona, cuando lo
-- selecciona en terreno (ver terreno/aprobar.php). Toda postulacion
-- nueva nace apuntando a este cargo interno "Por asignar" (nunca se le
-- muestra al postulante) hasta que el Capataz la reemplaza por el
-- cargo real. activo=0 para que jamas aparezca en los selectores de
-- cargos reales (cargos_disponibles.php, solicitar_cupo, etc).
INSERT INTO cargos (nombre_cargo, cupos_totales, cupos_activos, activo) VALUES
    ('Por asignar', 0, 0, 0);

-- Usuario administrativo de ejemplo (contraseña: "CambiarAhora123!").
-- Generado con password_hash('CambiarAhora123!', PASSWORD_BCRYPT).
-- CAMBIAR esta contraseña / hash antes de ir a produccion.
INSERT INTO usuarios (nombre, correo, password, rol) VALUES
    ('Administrador General', 'admin@icafal.cl',
     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jefe_Administrativo');
