#!/bin/bash
# v10.34 - Importa los datos generados por exportar_datos.sh en ESTE servidor
# (destino: normalmente Azure Database for MySQL). REEMPLAZA los datos de las
# tablas que vienen en el manifiesto, por eso pide confirmacion explicita.
#
# Uso:   bash importar_datos.sh <carpeta_export> --confirmo [--documentos <carpeta_destino>]
#
# Protecciones:
#   * sin --confirmo no hace nada;
#   * verifica las huellas SHA256 antes de tocar nada;
#   * se niega si el destino YA tiene postulaciones (es decir, datos reales
#     propios), salvo que se agregue --reemplazar-datos-existentes;
#   * al terminar compara fila por fila (conteo por tabla) contra el manifiesto
#     y sale con error si algo no coincide.
set -euo pipefail
# shellcheck source=database/lib_mysql.sh
source "$(dirname "$0")/lib_mysql.sh"

DIR="${1:-}"; shift || true
CONFIRMO=0; REEMPLAZAR=0; DOCS=""
while [ $# -gt 0 ]; do
  case "$1" in
    --confirmo) CONFIRMO=1 ;;
    --reemplazar-datos-existentes) REEMPLAZAR=1 ;;
    --documentos) DOCS="${2:-}"; shift ;;
    *) echo "Opcion desconocida: $1"; exit 1 ;;
  esac
  shift
done
if [ -z "$DIR" ] || [ ! -f "$DIR/manifiesto.txt" ]; then
  echo "Uso: bash $0 <carpeta_export> --confirmo [--documentos <carpeta_destino>] [--reemplazar-datos-existentes]"; exit 1
fi
if [ "$CONFIRMO" != "1" ]; then
  echo "Esto REEMPLAZA los datos del servidor destino con los de $DIR."
  echo "Si es lo que quieres, repite el comando agregando --confirmo."; exit 1
fi

echo "== verificando huellas =="
(cd "$DIR" && sha256sum -c SHA256SUMS) || { echo "ERROR: las huellas no coinciden, el export esta corrupto o incompleto."; exit 1; }

BASES=$(cut -f1 "$DIR/manifiesto.txt" | sort -u)
for DBX in $BASES; do
  icafal_mysql -e "USE \`$DBX\`" 2>/dev/null || { echo "ERROR: la base $DBX no existe en el destino (arranca la plataforma una vez para que cree las bases)."; exit 1; }
  EXISTENTES=$(icafal_mysql -N -e "SELECT COUNT(*) FROM \`$DBX\`.postulaciones")
  if [ "$EXISTENTES" != "0" ] && [ "$REEMPLAZAR" != "1" ]; then
    echo "ERROR: $DBX ya tiene $EXISTENTES postulaciones. No se pisa. (Si de verdad quieres reemplazarlas: --reemplazar-datos-existentes)"; exit 1
  fi
done

for DBX in $BASES; do
  echo "== $DBX: vaciando tablas del manifiesto =="
  {
    echo "SET FOREIGN_KEY_CHECKS=0;"
    awk -F'\t' -v d="$DBX" '$1==d { printf "TRUNCATE TABLE `%s`.`%s`;\n", d, $2 }' "$DIR/manifiesto.txt"
    echo "SET FOREIGN_KEY_CHECKS=1;"
  } | icafal_mysql
  echo "== $DBX: importando =="
  { echo "SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0;"; cat "$DIR/$DBX.sql"; echo "SET FOREIGN_KEY_CHECKS=1;"; } \
    | icafal_mysql --default-character-set=utf8mb4 "$DBX"
done

echo "== verificando conteos contra el manifiesto =="
FALLAS=0
while IFS=$'\t' read -r DBX T N; do
  M=$(icafal_mysql -N -e "SELECT COUNT(*) FROM \`$DBX\`.\`$T\`")
  if [ "$M" != "$N" ]; then echo "  DIFERENCIA $DBX.$T: origen=$N destino=$M"; FALLAS=$((FALLAS+1)); fi
done < "$DIR/manifiesto.txt"
[ "$FALLAS" = "0" ] && echo "  todas las tablas coinciden" || { echo "ERROR: $FALLAS tablas no coinciden"; exit 1; }

if [ -n "$DOCS" ]; then
  echo "== documentos -> $DOCS =="
  mkdir -p "$DOCS"
  tar -xzf "$DIR/documentos.tar.gz" -C "$DOCS"
  chown -R www-data:www-data "$DOCS" 2>/dev/null || true
  echo "   $(find "$DOCS" -type f | wc -l) archivos"
fi
echo "IMPORTACION COMPLETA"
