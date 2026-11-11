# Plataforma Contratación MOD en Azure — guía de puesta en marcha y operación

La misma imagen Docker corre en Render (base MariaDB dentro del contenedor) y en
Azure (base MySQL administrada). Lo decide **una variable**: si existe `DB_HOST`,
el arranque (`docker-entrypoint.sh`) usa el servidor externo con TLS; si no, usa
la base local como siempre. Render no cambia.

```
Navegador ──HTTPS──▶ App Service (contenedor: Apache + PHP)
                         │  TLS (puerto 3306, validado)      ┌────────────────────────┐
                         ├──────────────────────────────────▶│ MySQL Flexible Server  │
                         │                                    │  icafal_rrhh (H57)     │
                         │  /mnt/documentos (Azure Files)     │  icafal_rrhh_h58 (H58) │
                         └─▶ CV, cédulas, certificados        └────────────────────────┘
```

## Costo mensual (precio de lista, Chile Central)

| Recurso | US$/mes |
|---|---|
| MySQL Flexible Server B1ms + 20 GB | ≈ 20,6 |
| App Service plan B1 Linux | ≈ 17,4 |
| Registro de contenedores (ya existe) | ≈ 5 |
| Azure Files, Standard LRS (pocos GB) | < 1 |
| **Total** | **≈ 43–45** |

Los respaldos de MySQL (14 días) están incluidos hasta el tamaño del disco.

## Qué hay en esta carpeta

| Archivo | Para qué |
|---|---|
| `aprovisionar.ps1` | Crea todo en Azure (idempotente). `-SoloMostrar` imprime lo que haría sin ejecutar nada. |
| `desplegar.ps1` | Construye la imagen en el registro, la publica y reinicia la app. También sirve para volver atrás (`-Etiqueta <versión anterior> -SinConstruir`). |
| `verificar.ps1` | Revisa páginas, carpetas bloqueadas y el registro de arranque. |
| `ca/` | Certificado raíz de Microsoft para validar la conexión TLS a MySQL. |

Los secretos (claves de MySQL) los genera `aprovisionar.ps1` y quedan en
`%USERPROFILE%\.icafal\azure-secretos.env`, legible solo por tu usuario y **fuera
del repositorio**. Para activar el correo, agrega ahí `BREVO_API_KEY=...` y
`SMTP_FROM_EMAIL=...` y vuelve a correr `aprovisionar.ps1`.

## Puesta en marcha (una vez)

1. Un administrador de la suscripción registra el proveedor:
   `az provider register -n Microsoft.DBforMySQL --wait`
2. `az login` y luego `.\azure\desplegar.ps1 -SoloImagen` (sube la imagen).
3. `.\azure\aprovisionar.ps1` (crea todo, ~10 min).
4. `.\azure\verificar.ps1 -App <nombre que imprimió el paso 3>`.
   La primera carga tarda unos minutos: baja la imagen y crea las dos bases.

En ese punto la plataforma funciona con datos de demostración (usuarios de
`seed_deploy.sql`, clave inicial conocida). **No la uses con datos reales hasta
importar los usuarios reales (siguiente sección) o cambiar esas claves desde el
panel Desarrollador.**

## Pasar los datos reales desde Render

Está ensayado de punta a punta (MariaDB → MySQL 8.4): se comparan huellas MD5 de
postulaciones, usuarios, bitácora, cargos, cursos, JSON y horas, y coinciden.

1. **Congelar**: hacerlo en horario sin postulaciones (noche o fin de semana).
   Desde que se exporta hasta que se cambia el enlace no deben entrar datos nuevos
   a Render, porque quedarían fuera de la copia.
2. En el Web Shell de Render: `bash /app/database/exportar_datos.sh`
   Es de solo lectura. Genera una carpeta con los datos de cada obra, los
   documentos subidos, un manifiesto de filas por tabla y huellas SHA-256.
3. Llevar la carpeta fuera de Render. Recomendado: subirla desde el propio shell
   de Render a un contenedor privado de Azure con una dirección firmada (SAS) de
   corta duración, y bajarla en tu PC:
   `curl -X PUT -H "x-ms-blob-type: BlockBlob" --upload-file archivo "<URL con SAS>"`
4. Importar en Azure desde tu PC (Git Bash), usando el cliente MySQL y el
   archivo `%USERPROFILE%\.icafal\azure-mysql.cnf` que dejó `aprovisionar.ps1`
   (requiere una regla de firewall temporal para tu IP:
   `az mysql flexible-server firewall-rule create ...`, y borrarla al terminar):
   ```bash
   export ICAFAL_MYSQL_CNF="$USERPROFILE/.icafal/azure-mysql.cnf"
   bash database/importar_datos.sh <carpeta_export> --confirmo
   ```
   El importador verifica huellas, se niega a pisar una base que ya tenga
   postulaciones, y al final compara conteos tabla por tabla contra el manifiesto.
5. Subir los documentos al recurso compartido:
   `az storage file upload-batch --account-name <cuenta> -d documentos -s <carpeta descomprimida>`
   (cada archivo conserva su nombre aleatorio; las rutas en la base no cambian).
6. Reiniciar la app, correr `verificar.ps1` y entrar con un usuario real.

## Antes de mover el piloto a Azure (decisiones)

* **Enlace de los QR.** Los afiches impresos apuntan a `icafal-rrhh.onrender.com`.
  Al cambiar de servidor, o se reimprimen, o se deja Render redirigiendo al
  enlace nuevo, o se usa un dominio propio (`rrhh.icafal.cl`) que apunte a donde
  esté la plataforma en cada momento — lo mejor a largo plazo (requiere que TI
  cree el registro DNS).
* **Modo prueba de correo.** En Azure parte sin `BREVO_API_KEY`: los correos
  quedan en `correos_fallidos` (con reintento). Antes de importar datos reales,
  decide si se envían o si `EMAIL_MODO_PRUEBA=1` los redirige a una casilla propia.
* **Datos personales.** La plataforma guarda RUT, cédulas y certificados.
  Avisar a TI/Seguridad que quedan alojados en Azure (región Chile Central).
* **Una sola instancia.** Las sesiones viven en el contenedor; no escalar a más de
  una instancia sin antes mover las sesiones a la base. Con el volumen del
  piloto no hace falta.

## Operación

* **Ver el registro:** `az webapp log tail -g rg_contratacionmod-maqueta -n <app>`
* **Nueva versión:** `.\azure\desplegar.ps1 -App <app>`; para volver atrás,
  `-Etiqueta <anterior> -SinConstruir`. Las etiquetas están en el registro.
* **Migración SQL nueva** (`database/migracion_vX.sql`), siempre en todas las obras:
  ```bash
  export ICAFAL_MYSQL_CNF="$USERPROFILE/.icafal/azure-mysql.cnf"
  for DB in icafal_rrhh icafal_rrhh_h58; do mysql --defaults-extra-file="$ICAFAL_MYSQL_CNF" "$DB" < database/migracion_vX.sql; done
  ```
  (misma regla de siempre: la migración va **antes** de publicar el código que la usa).
* **Respaldos de la base:** automáticos, 14 días, restauración a cualquier punto:
  `az mysql flexible-server restore -g <grupo> -n <servidor_nuevo> --source-server <servidor> --restore-time <UTC>`
  (crea un servidor nuevo; no pisa el actual).
* **Respaldos de documentos:** el recurso compartido tiene papelera de 14 días
  (recupera borrados). Para copias fechadas, instantáneas:
  `az storage share snapshot --account-name <cuenta> -n documentos`
  (recomendado: una semanal, y siempre una antes de cualquier cambio grande).
* **Si la creación de una base queda a medias** (el contenedor se detuvo justo
  ahí): conectarse como administrador, `DROP DATABASE icafal_rrhh_h58;` (o la que
  corresponda) y reiniciar la app; el arranque la vuelve a crear limpia.

## Seguridad (lo que ya está resuelto)

* La app se conecta con un usuario que **solo puede leer y escribir datos**
  (sin crear ni borrar tablas, ni crear usuarios). El administrador solo se usa
  al arrancar y en las migraciones.
* TLS 1.2+ obligatorio hacia MySQL, con validación del certificado.
* Firewall de MySQL: solo las IP de salida de la aplicación.
* Los documentos **no se pueden pedir por URL** (`/backend/uploads/...` responde
  403); solo se entregan a través de los endpoints que validan la sesión. Esto
  ya aplica también a Render con esta versión.
* Sin claves en el repositorio ni en la línea de comandos de ningún script.

## Cómo se probó (sin costo de infraestructura)

* Esquema y los endpoints de lectura de la API (con los 9 roles en H57 y el
  Desarrollador en H58, más las pantallas del panel Desarrollador) contra MySQL 8.4
  con TLS obligatorio y el usuario restringido: 0 errores SQL. La aplicación no
  usa ninguna instrucción que requiera permisos más allá de leer y escribir datos.
* Arranque completo en Linux con servidor MySQL 8.4 aparte: bases creadas,
  reinicio sin re-inicializar, cambio de clave de la app, carpeta de documentos
  externa, migración en todas las obras, caminos de error (servidor inalcanzable,
  certificado no confiable, base a medio crear).
* Ruta de Render (MariaDB local) sin regresiones.
* Ensayo de migración MariaDB → MySQL 8.4 con huellas idénticas.
