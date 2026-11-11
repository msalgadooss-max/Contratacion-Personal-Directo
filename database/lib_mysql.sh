#!/bin/bash
# v10.34 - Cliente MySQL comun para el arranque y las migraciones.
#
# La plataforma puede correr con dos tipos de base de datos:
#   * LOCAL (Render, desarrollo): MariaDB dentro del mismo contenedor, root
#     sin clave por 127.0.0.1.
#   * EXTERNA (Azure Database for MySQL): servidor aparte, por red, con TLS.
#     El arranque deja las credenciales en un archivo de opciones
#     (/run/icafal/admin.cnf, permisos 600, solo vive mientras el contenedor)
#     y desde ese momento este mismo comando las usa en todos lados:
#     docker-entrypoint.sh, aplicar_en_todas_las_obras.sh y cualquier
#     comando manual por SSH.
#
# Uso:  source /app/database/lib_mysql.sh ; icafal_mysql -e "SHOW DATABASES"
ICAFAL_MYSQL_CNF="${ICAFAL_MYSQL_CNF:-/run/icafal/admin.cnf}"

icafal_mysql() {
  if [ -f "$ICAFAL_MYSQL_CNF" ]; then
    # --defaults-extra-file debe ir SIEMPRE primero.
    mysql --defaults-extra-file="$ICAFAL_MYSQL_CNF" "$@"
  else
    mysql -h127.0.0.1 -uroot "$@"
  fi
}

icafal_mysqldump() {
  if [ -f "$ICAFAL_MYSQL_CNF" ]; then
    mysqldump --defaults-extra-file="$ICAFAL_MYSQL_CNF" "$@"
  else
    mysqldump -h127.0.0.1 -uroot "$@"
  fi
}
