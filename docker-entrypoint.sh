#!/bin/bash
set -e

DATADIR=/var/lib/mysql
PORT="${PORT:-8000}"

# v10.34: la plataforma corre con dos tipos de base de datos, con la MISMA imagen:
#   * LOCAL   (por defecto; Render y desarrollo): MariaDB dentro del contenedor.
#   * EXTERNA (cuando existe la variable DB_HOST; Azure Database for MySQL):
#     el servidor de base de datos es otro, por red y con TLS. En ese modo NO
#     se levanta MariaDB local.
# Variables del modo EXTERNO:
#   DB_HOST, DB_PORT (3306)            servidor
#   DB_ADMIN_USER / DB_ADMIN_PASS      usuario administrador: crea bases, tablas
#                                      y el usuario de la aplicacion (opcional;
#                                      si no se define se usa DB_USER / DB_PASS)
#   DB_USER / DB_PASS                  usuario que usa la aplicacion en runtime
#                                      (si es distinto del administrador se crea
#                                      con permisos solo de datos, sin DDL)
#   DB_SSL                             verify (defecto: cifrado + valida el
#                                      certificado) | required (cifrado sin
#                                      validar) | off
#   UPLOADS_BASE                       carpeta persistente para los documentos
#                                      subidos (defecto: el datadir local)
if [ -n "${DB_HOST:-}" ]; then
  MODO_BD=externa
else
  MODO_BD=local
fi
echo "[entrypoint] Modo de base de datos: $MODO_BD"

# Cliente mysql unico (local o externo). Ver database/lib_mysql.sh.
# shellcheck source=database/lib_mysql.sh
source /app/database/lib_mysql.sh

# Literal PHP entre comillas simples (escapa \ y ') para config.php.
phpq() { local s="$1"; s="${s//\\/\\\\}"; s="${s//\'/\\\'}"; printf "'%s'" "$s"; }

if [ "$MODO_BD" = "local" ]; then
  rm -f "$ICAFAL_MYSQL_CNF"   # por si quedo uno de un arranque anterior en modo externo
  # --- 1) Inicializa MariaDB si el datadir esta vacio (disco efimero: -----
  #         esto corre de nuevo en cada arranque del contenedor en Render) --
  if [ ! -d "$DATADIR/mysql" ]; then
    echo "[entrypoint] Inicializando MariaDB..."
    mariadb-install-db --user=mysql --datadir="$DATADIR" --skip-test-db > /dev/null
  fi

  echo "[entrypoint] Iniciando MariaDB..."
  # v9.1: character-set-server/collation-server EXPLICITOS al arrancar el
  # propio servidor -- se detectaron nombres con tildes/"ñ" corruptos de
  # forma intermitente entre un arranque de contenedor y otro (probando el
  # piloto de punta a punta), pese a que la base, las tablas y cada
  # conexion via PDO ya pedian utf8mb4 explicitamente. Fijarlo aqui, al
  # nivel del propio proceso mysqld, elimina cualquier dependencia de un
  # my.cnf por defecto del paquete o de una negociacion de charset que
  # pudiera llegar tarde para las primeras conexiones (schema.sql/
  # seed_deploy.sql corren segundos despues de este arranque).
  mysqld_safe --datadir="$DATADIR" --skip-networking=0 --bind-address=127.0.0.1 \
    --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci &

  # --- 2) Espera a que acepte conexiones (por el socket local) -------------
  for i in $(seq 1 30); do
    if mysqladmin ping --silent 2>/dev/null; then
      break
    fi
    sleep 1
  done

  # --- 2.5) MariaDB deja a root autenticado SOLO por socket local por -----
  #           defecto -- el propio PHP (PDO, via TCP a 127.0.0.1) y los
  #           comandos de abajo necesitan una cuenta que acepte conexion
  #           por red. Esto se hace una sola vez, por el socket local
  #           (donde root SI puede entrar sin clave).
  mysql -uroot <<'SQL'
ALTER USER 'root'@'localhost' IDENTIFIED BY '';
CREATE USER IF NOT EXISTS 'root'@'%' IDENTIFIED BY '';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL
  echo "[entrypoint] Cuenta root habilitada para conexiones TCP."

  CFG_DB_HOST="127.0.0.1"; CFG_DB_PORT="3306"; CFG_DB_USER="root"; CFG_DB_PASS=""
  CFG_DB_SSL="off"; CFG_DB_SSL_CA=""
else
  # --- 1) Servidor externo (Azure Database for MySQL) ----------------------
  CFG_DB_HOST="$DB_HOST"
  CFG_DB_PORT="${DB_PORT:-3306}"
  CFG_DB_USER="${DB_USER:-}"
  CFG_DB_PASS="${DB_PASS:-}"
  CFG_DB_SSL="${DB_SSL:-verify}"
  CFG_DB_SSL_CA="${DB_SSL_CA:-/etc/ssl/certs/ca-certificates.crt}"
  ADMIN_USER="${DB_ADMIN_USER:-$CFG_DB_USER}"
  ADMIN_PASS="${DB_ADMIN_PASS:-$CFG_DB_PASS}"

  if [ -z "$CFG_DB_USER" ] || [ -z "$CFG_DB_PASS" ]; then
    echo "[entrypoint] ERROR: con DB_HOST definido tambien se necesitan DB_USER y DB_PASS."
    exit 1
  fi
  case "$CFG_DB_SSL" in verify|required|off) ;; *)
    echo "[entrypoint] ERROR: DB_SSL debe ser verify, required u off (vale: $CFG_DB_SSL)."; exit 1 ;;
  esac

  # Archivo de opciones del cliente mysql (credenciales fuera de la linea de
  # comandos, permisos 600). Dentro de comillas dobles: \ y " se escapan.
  optq() { local s="$1"; s="${s//\\/\\\\}"; s="${s//\"/\\\"}"; printf '"%s"' "$s"; }
  umask 077
  mkdir -p "$(dirname "$ICAFAL_MYSQL_CNF")"
  {
    echo "[client]"
    echo "host=$(optq "$CFG_DB_HOST")"
    echo "port=$CFG_DB_PORT"
    echo "user=$(optq "$ADMIN_USER")"
    echo "password=$(optq "$ADMIN_PASS")"
    echo "default-character-set=utf8mb4"
    case "$CFG_DB_SSL" in
      verify)   echo "ssl=1"; echo "ssl-ca=$CFG_DB_SSL_CA"; echo "ssl-verify-server-cert=1" ;;
      required) echo "ssl=1"; echo "ssl-verify-server-cert=0" ;;
      off)      echo "ssl=0" ;;
    esac
  } > "$ICAFAL_MYSQL_CNF"
  umask 022

  echo "[entrypoint] Esperando al servidor $CFG_DB_HOST:$CFG_DB_PORT (TLS: $CFG_DB_SSL)..."
  CONECTO=0
  for i in $(seq 1 "${DB_WAIT_TRIES:-60}"); do
    if ERR=$(icafal_mysql -e "SELECT 1" 2>&1 >/dev/null); then CONECTO=1; break; fi
    sleep 3
  done
  if [ "$CONECTO" != "1" ]; then
    echo "[entrypoint] ERROR: no fue posible conectar al servidor de base de datos (reintentos cada 3 s, DB_WAIT_TRIES, 60 por defecto = 3 minutos)."
    echo "[entrypoint] Ultimo mensaje: $ERR"
    echo "[entrypoint] Revisa: DB_HOST/DB_ADMIN_USER/DB_ADMIN_PASS, el firewall del servidor y DB_SSL (prueba required)."
    exit 1
  fi
  echo "[entrypoint] Conexion con la base de datos OK."

  # Usuario de la aplicacion con permisos minimos (solo datos, sin DDL): si la
  # aplicacion sufriera una inyeccion SQL no podria borrar tablas ni crear
  # usuarios. El administrador (con DDL) solo se usa aqui y en las migraciones.
  if [ "$ADMIN_USER" != "$CFG_DB_USER" ]; then
    if ! [[ "$CFG_DB_PASS" =~ ^[A-Za-z0-9._~@%^*+=:,-]+$ ]]; then
      echo "[entrypoint] ERROR: DB_PASS solo puede tener letras, numeros y . _ ~ @ % ^ * + = : , -"
      exit 1
    fi
    if ! [[ "$CFG_DB_USER" =~ ^[A-Za-z0-9_]+$ ]]; then
      echo "[entrypoint] ERROR: DB_USER solo puede tener letras, numeros y _"
      exit 1
    fi
    icafal_mysql <<SQL
CREATE USER IF NOT EXISTS '$CFG_DB_USER'@'%' IDENTIFIED BY '$CFG_DB_PASS';
ALTER USER '$CFG_DB_USER'@'%' IDENTIFIED BY '$CFG_DB_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`icafal\_rrhh%\`.* TO '$CFG_DB_USER'@'%';
SQL
    echo "[entrypoint] Usuario de aplicacion '$CFG_DB_USER' listo (solo SELECT/INSERT/UPDATE/DELETE)."
  fi
fi

# --- 3) Crea el esquema + datos semilla si la base aun no existe --------
#        v10.34: si la carga falla a medias se borra la base a medio crear
#        (esta base NO existia antes de este intento) y el arranque se detiene,
#        para reintentar limpio. Antes quedaba a medias y el siguiente
#        arranque la daba por buena.
if ! icafal_mysql -e "USE icafal_rrhh" 2>/dev/null; then
  echo "[entrypoint] Cargando schema.sql + seed_deploy.sql..."
  if ! { icafal_mysql --default-character-set=utf8mb4 < /app/database/schema.sql \
         && icafal_mysql --default-character-set=utf8mb4 < /app/database/seed_deploy.sql; }; then
    echo "[entrypoint] ERROR: no se pudo crear la base principal. Se elimina la base a medio crear para reintentar."
    icafal_mysql -e "DROP DATABASE IF EXISTS icafal_rrhh" || true
    exit 1
  fi
fi

# --- 3.5) v10.32: obras adicionales (H58, ...) ---------------------------
#          Cada obra tiene su PROPIA base de datos dentro del mismo MariaDB
#          y del mismo disco persistente. El registro de obras (unica
#          fuente de verdad) es backend/config/obras_registro.php. Una base
#          solo se crea si todavia no existe: nunca toca una ya creada.
#          La obra principal (H57, base icafal_rrhh) no se toca aqui.
#          Blindaje: pase lo que pase aqui, el arranque NUNCA se detiene (el
#          contenedor sigue y H57 funciona igual). Si la creacion falla a
#          medias, la base a medio crear se borra para reintentar limpia en
#          el proximo arranque.
OBRAS_EXTRA=$(php -r '$o = require "/app/backend/config/obras_registro.php"; foreach ($o as $x) { if ($x["prefijo"] !== "") { echo $x["db"], "\n"; } }' 2>/dev/null || true)
for DBX in $OBRAS_EXTRA; do
  if ! icafal_mysql -e "USE \`$DBX\`" 2>/dev/null; then
    echo "[entrypoint] Creando base de la obra adicional: $DBX ..."
    if sed "s/icafal_rrhh/$DBX/g" /app/database/schema.sql | icafal_mysql --default-character-set=utf8mb4 \
       && sed "s/icafal_rrhh/$DBX/g" /app/database/seed_deploy.sql | icafal_mysql --default-character-set=utf8mb4 \
       && icafal_mysql --default-character-set=utf8mb4 "$DBX" < /app/database/seed_obra_nueva.sql; then
      echo "[entrypoint] Base $DBX lista."
    else
      echo "[entrypoint] ATENCION: no se pudo crear la base $DBX. Se elimina para reintentar en el proximo arranque. La obra principal (H57) NO se ve afectada."
      icafal_mysql -e "DROP DATABASE IF EXISTS \`$DBX\`" || true
    fi
  fi
done

# --- 4) Genera config.php desde variables de entorno (nunca desde git) --
#        v10.34: cada valor sale como literal PHP escapado (phpq), asi una
#        clave con comillas o "$" no puede romper el archivo.
cat > /app/backend/config/config.php <<PHP
<?php
define('DB_HOST', $(phpq "$CFG_DB_HOST"));
define('DB_PORT', $(phpq "$CFG_DB_PORT"));
// DB_NAME lo define obras.php segun la obra de la peticion (v10.32).
define('DB_USER', $(phpq "$CFG_DB_USER"));
define('DB_PASS', $(phpq "$CFG_DB_PASS"));
define('DB_CHARSET', 'utf8mb4');
// v10.34: TLS hacia la base (off = local; verify/required = servidor externo).
define('DB_SSL_MODE', $(phpq "$CFG_DB_SSL"));
define('DB_SSL_CA', $(phpq "$CFG_DB_SSL_CA"));

// v10.32: dominio sin prefijo; obras.php arma BASE_URL agregando /h58 si corresponde.
define('BASE_URL_RAIZ', $(phpq "${BASE_URL:-}"));

define('SMTP_HOST', $(phpq "${SMTP_HOST:-}"));
define('SMTP_PORT', ${SMTP_PORT:-587});
define('SMTP_USER', $(phpq "${SMTP_USER:-}"));
define('SMTP_PASS', $(phpq "${SMTP_PASS:-}"));
define('SMTP_SECURE', $(phpq "${SMTP_SECURE:-tls}"));
define('SMTP_FROM_EMAIL', $(phpq "${SMTP_FROM_EMAIL:-}"));
define('SMTP_FROM_NAME', 'RRHH ICAFAL');

// v6.3: si esta definida, el correo sale por la API HTTPS de Brevo en
// vez de SMTP (necesario en hostings que bloquean los puertos salientes).
define('BREVO_API_KEY', $(phpq "${BREVO_API_KEY:-}"));

define('TOKEN_PRIVADO_HORAS_VALIDEZ', 72);
define('TOKEN_SUBSANACION_HORAS_VALIDEZ', 72);
define('CODIGO_SEGUIMIENTO_LARGO', 6);
define('BANCO_RETENCION_MESES', 6);

# v10.32: nombre de la obra, datos de Buk, base de datos y sesion salen del
# registro de obras (backend/config/obras_registro.php). Los datos de H57 que
# estaban aqui (sub-area 9042, supervisor, horario, etc.) pasaron ahi, sin cambios.
require __DIR__ . '/obras.php';
define('LIMITE_APROBACIONES_DIARIAS_TERRENO', 25);

// v10.14 (pedido explícito del usuario: "activar nuevamente los
// perfiles de prevención y bodega definitivamente, activos 100% y de
// punta a punta"): arranca Etapa 2. Prevención y Bodega pasan a ser
// candados digitales reales dentro de la app -- admin_general/
// firmar_contrato.php ya sabía manejar ambos casos desde que se
// escribió (ver su docblock), así que este es el único cambio que
// hacía falta para activar el flujo completo.
define('MODULO_PREVENCION_ACTIVO', true);
define('MODULO_BODEGA_ACTIVO', true);

define('APP_DEBUG', false);
PHP
php -l /app/backend/config/config.php > /dev/null || { echo "[entrypoint] ERROR: config.php generado no es PHP valido."; exit 1; }

# v10.11 (hallazgo critico, pedido explicito del usuario): estas dos
# carpetas vivian en el disco efimero del CONTENEDOR (no en $DATADIR,
# que es el unico disco persistente real de este servicio en Render --
# confirmado en el dashboard, montado en /var/lib/mysql). Cualquier
# redeploy o reinicio las borraba por completo, perdiendo para siempre
# los CV y documentos que los postulantes ya habian subido -- la fila en
# postulaciones/postulacion_documentos seguia apuntando a un archivo que
# ya no existia. Ahora se guardan DENTRO del disco persistente (en
# subcarpetas propias, sin tocar nada de lo que usa MariaDB) y se
# enlazan con symlinks a las rutas de siempre -- el codigo PHP
# (guardarArchivoSubido() y todo lo que despues lee o descarga esos
# documentos) sigue usando exactamente las mismas rutas relativas, sin
# ningun cambio. Efecto secundario util: el snapshot diario que Render
# ya le hace a ese disco ahora tambien respalda los documentos.
#
# o+x en el datadir: el minimo necesario para que www-data pueda
# ATRAVESAR /var/lib/mysql y llegar a sus dos subcarpetas -- no le da
# permiso de leer ni listar los archivos propios de MariaDB, que siguen
# siendo 700 mysql:mysql.
#
# v10.34: con base EXTERNA los documentos van a UPLOADS_BASE (un disco
# persistente propio de la plataforma, p.ej. un recurso compartido de Azure
# Files montado en /mnt/documentos), no al datadir de MariaDB. En esos discos
# de red chown/chmod pueden no aplicar (los permisos vienen del montaje):
# se intenta y, si no corresponde, se sigue. Lo que SI se verifica es que
# www-data pueda escribir -- si no puede, se avisa fuerte en el log.
UPLOADS_BASE="${UPLOADS_BASE:-$DATADIR}"
if [ "$UPLOADS_BASE" = "$DATADIR" ]; then
  chmod o+x "$DATADIR"
fi
mkdir -p "$UPLOADS_BASE/app_uploads" "$UPLOADS_BASE/app_carpetas_postulantes"
chown -R www-data:www-data "$UPLOADS_BASE/app_uploads" "$UPLOADS_BASE/app_carpetas_postulantes" 2>/dev/null || true

if su -s /bin/sh www-data -c "touch '$UPLOADS_BASE/app_uploads/.probe' && rm -f '$UPLOADS_BASE/app_uploads/.probe'" 2>/dev/null; then
  echo "[entrypoint] Documentos subidos: $UPLOADS_BASE (escritura OK)."
else
  echo "[entrypoint] ATENCION: www-data NO puede escribir en $UPLOADS_BASE/app_uploads. Las postulaciones con documentos fallaran. Revisa el montaje del disco."
fi

rm -rf /app/backend/uploads /app/backend/carpetas_postulantes
ln -s "$UPLOADS_BASE/app_uploads" /app/backend/uploads
ln -s "$UPLOADS_BASE/app_carpetas_postulantes" /app/backend/carpetas_postulantes

# v6.8: Render entrega el puerto real en la variable $PORT en tiempo de
# arranque (cambia entre despliegues), asi que el puerto de Apache no se
# puede fijar en la imagen -- se reemplaza aqui, recien al arrancar.
# v10.34: idempotente -- si el contenedor se reinicia sobre el mismo disco
# (docker restart / reinicio del App Service), el archivo ya trae el puerto
# anterior; con el patron viejo ("Listen 80" sin ancla) "Listen 8000" pasaba a
# "Listen 80000" y Apache no volvia a arrancar.
sed -i -E "s/^Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i -E "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

echo "[entrypoint] Arrancando Apache en el puerto $PORT..."
exec apache2-foreground
