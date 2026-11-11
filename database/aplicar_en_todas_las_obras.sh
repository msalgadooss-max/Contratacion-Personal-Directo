#!/bin/bash
# v10.32 - Aplica un archivo SQL (una migracion) en la base de CADA obra.
#
# Cada obra tiene su propia base de datos, asi que una migracion hay que
# correrla en todas. Este script lee las bases del registro de obras
# (backend/config/obras_registro.php) y corre el mismo archivo en cada una,
# avisando de cual falla (por ejemplo, "Duplicate column" si ya estaba
# aplicada), sin detenerse en la primera.
#
# Uso, en el Web Shell de Render (ANTES del Manual Deploy, como siempre):
#   bash /app/database/aplicar_en_todas_las_obras.sh /app/database/migracion_vX.sql
# v10.34: funciona igual con la base local (Render) y con la externa (Azure,
# por SSH al contenedor): el cliente lo resuelve database/lib_mysql.sh.
#
# Importante: la obra nueva (H58) se crea con el schema.sql ACTUAL, que ya
# incluye todas las migraciones anteriores. Esto solo hace falta para las
# migraciones que se escriban de ahora en adelante, y solo en las obras que
# ya existian antes de esa migracion.
# shellcheck source=database/lib_mysql.sh
source "$(dirname "$0")/lib_mysql.sh"

ARCHIVO="$1"
if [ -z "$ARCHIVO" ] || [ ! -f "$ARCHIVO" ]; then
  echo "Uso: bash $0 /ruta/al/archivo.sql"
  exit 1
fi

BASES=$(php -r '$o = require "/app/backend/config/obras_registro.php"; foreach ($o as $x) { echo $x["db"], "\n"; }')
for DBX in $BASES; do
  if icafal_mysql -e "USE \`$DBX\`" 2>/dev/null; then
    echo "== $DBX =="
    if icafal_mysql --default-character-set=utf8mb4 "$DBX" < "$ARCHIVO"; then
      echo "   OK"
    else
      echo "   ATENCION: fallo en $DBX (revisa el mensaje de arriba)"
    fi
  else
    echo "== $DBX == (todavia no existe, se omite)"
  fi
done
