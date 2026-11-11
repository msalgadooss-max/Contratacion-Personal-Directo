#!/bin/bash
# v10.34 - Exporta TODOS los datos de la plataforma (todas las obras) para
# llevarlos a otro servidor (p.ej. de Render/MariaDB a Azure/MySQL 8.4).
#
# Genera una carpeta con:
#   <base>.sql        datos de cada obra (solo INSERTs; sin tablas ni triggers,
#                     que el destino ya trae de su propio schema.sql)
#   manifiesto.txt    filas por tabla, para que el importador verifique
#   documentos.tar.gz CV y documentos subidos + carpetas por postulante
#   SHA256SUMS        huellas de todo lo anterior
#
# Uso (Web Shell de Render):   bash /app/database/exportar_datos.sh [carpeta_salida]
# Es de SOLO LECTURA: no modifica nada de la base de origen.
set -euo pipefail
# shellcheck source=database/lib_mysql.sh
source "$(dirname "$0")/lib_mysql.sh"

OUT="${1:-/tmp/icafal_export_$(date +%Y%m%d_%H%M%S)}"
mkdir -p "$OUT"
: > "$OUT/manifiesto.txt"

BASES=$(php -r '$o = require "/app/backend/config/obras_registro.php"; foreach ($o as $x) { echo $x["db"], "\n"; }')
for DBX in $BASES; do
  if ! icafal_mysql -e "USE \`$DBX\`" 2>/dev/null; then
    echo "== $DBX == (no existe en el origen, se omite)"
    continue
  fi
  echo "== $DBX =="
  # --no-create-info/--skip-triggers: solo datos. --complete-insert: nombra las
  # columnas, asi el destino no depende del orden de columnas del origen.
  # --single-transaction: copia consistente sin bloquear la plataforma.
  icafal_mysqldump --no-create-info --skip-triggers --skip-add-locks --complete-insert \
      --hex-blob --single-transaction --default-character-set=utf8mb4 "$DBX" > "$OUT/$DBX.sql"
  for T in $(icafal_mysql -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$DBX' AND table_type='BASE TABLE' ORDER BY 1"); do
    N=$(icafal_mysql -N -e "SELECT COUNT(*) FROM \`$DBX\`.\`$T\`")
    printf '%s\t%s\t%s\n' "$DBX" "$T" "$N" >> "$OUT/manifiesto.txt"
  done
  echo "   $(wc -l < "$OUT/$DBX.sql") lineas, $(grep -c "^$DBX" "$OUT/manifiesto.txt") tablas"
done

# Documentos subidos (siguen los symlinks de /app/backend hasta el disco real).
UP=$(readlink -f /app/backend/uploads)
CP=$(readlink -f /app/backend/carpetas_postulantes)
echo "== documentos =="
tar -czf "$OUT/documentos.tar.gz" -C "$(dirname "$UP")" "$(basename "$UP")" -C "$(dirname "$CP")" "$(basename "$CP")"
echo "   $(find "$UP" -type f | wc -l) archivos en uploads, $(find "$CP" -type f | wc -l) en carpetas_postulantes"

(cd "$OUT" && sha256sum ./* > SHA256SUMS)
echo
echo "Listo: $OUT"
ls -la "$OUT"
