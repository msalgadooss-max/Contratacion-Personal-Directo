#!/bin/bash
set -e

DATADIR=/var/lib/mysql
PORT="${PORT:-8000}"

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

# --- 3) Crea el esquema + datos semilla si la base aun no existe --------
if ! mysql -h127.0.0.1 -uroot -e "USE icafal_rrhh" 2>/dev/null; then
  echo "[entrypoint] Cargando schema.sql + seed_deploy.sql..."
  mysql -h127.0.0.1 -uroot --default-character-set=utf8mb4 < /app/database/schema.sql
  mysql -h127.0.0.1 -uroot --default-character-set=utf8mb4 < /app/database/seed_deploy.sql
fi

# --- 4) Genera config.php desde variables de entorno (nunca desde git) --
cat > /app/backend/config/config.php <<PHP
<?php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'icafal_rrhh');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('BASE_URL', '${BASE_URL}');

define('SMTP_HOST', '${SMTP_HOST}');
define('SMTP_PORT', ${SMTP_PORT:-587});
define('SMTP_USER', '${SMTP_USER}');
define('SMTP_PASS', '${SMTP_PASS}');
define('SMTP_SECURE', '${SMTP_SECURE:-tls}');
define('SMTP_FROM_EMAIL', '${SMTP_FROM_EMAIL}');
define('SMTP_FROM_NAME', 'RRHH ICAFAL');

// v6.3: si esta definida, el correo sale por la API HTTPS de Brevo en
// vez de SMTP (necesario en hostings que bloquean los puertos salientes).
define('BREVO_API_KEY', '${BREVO_API_KEY}');

define('TOKEN_PRIVADO_HORAS_VALIDEZ', 72);
define('TOKEN_SUBSANACION_HORAS_VALIDEZ', 72);
define('CODIGO_SEGUIMIENTO_LARGO', 6);
define('BANCO_RETENCION_MESES', 6);

define('OBRA_NOMBRE', 'Obra H57 Padre Hurtado IV');

// v10.14 (pedido explícito del usuario, segundo exportador Buk
// "Trabajos"): códigos exactos sacados de las hojas de referencia del
// template Trabajos.xls para esta obra -- Sub-área base de "H57
// Conjunto Padre Hurtado Etapa 4" bajo Gerencia Edificación (hoja
// "Sub-áreas"), y la Empresa real (confirmado por el usuario: Icafal
// Ingeniería y Construcción S.A., no la que traía la fila de ejemplo
// del template).
define('OBRA_SUBAREA_BUK', '9042');
define('OBRA_SUBAREA_NOMBRE_BUK', 'H57 Conjunto Padre Hurtado Etapa 4');
define('OBRA_EMPRESA_BUK', 'Icafal Ingeniería y Construcción S.A.');
// v10.14: confirmado contra un envío real de Luis López a Buk (Ariel
// Torres / Elin Sánchez, 10-09) -- "Obra" y "Recinto para marcar
// asistencia" van con este código corto en minúscula, no con
// OBRA_COMUNA_BUK como se había asumido antes de ver un caso real (esa
// columna, "Comuna/Localidad", Luis la deja en blanco).
define('OBRA_CODIGO_CORTO_BUK', 'h57');
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

define('SESSION_NAME', 'icafal_rrhh_sesion');
define('APP_DEBUG', false);
PHP

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
chmod o+x "$DATADIR"
mkdir -p "$DATADIR/app_uploads" "$DATADIR/app_carpetas_postulantes"
chown -R www-data:www-data "$DATADIR/app_uploads" "$DATADIR/app_carpetas_postulantes"

rm -rf /app/backend/uploads /app/backend/carpetas_postulantes
ln -s "$DATADIR/app_uploads" /app/backend/uploads
ln -s "$DATADIR/app_carpetas_postulantes" /app/backend/carpetas_postulantes

# v6.8: Render entrega el puerto real en la variable $PORT en tiempo de
# arranque (cambia entre despliegues), asi que el puerto de Apache no se
# puede fijar en la imagen -- se reemplaza aqui, recien al arrancar.
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

echo "[entrypoint] Arrancando Apache en el puerto $PORT..."
exec apache2-foreground
