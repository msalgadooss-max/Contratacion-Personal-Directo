<?php
/**
 * v10.39 - Bodega ve a las personas LIBERADAS (check de Prevención del Día 0) por día,
 * listas para ingresar al día siguiente. Rango por ?desde= y ?hasta= (hoy por defecto).
 * Los filtros finos (cargo, capataz, estado, texto) los aplica el panel en pantalla;
 * el Excel (exportar_liberados_excel.php) acepta los mismos parámetros.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Jefe_Bodega']);
exigirModuloActivo(MODULO_BODEGA_ACTIVO, 'Bodega');
exigirMetodo('GET');

[$desde, $hasta] = rangoFechasDeUrl();
if ((new DateTime($hasta))->diff(new DateTime($desde))->days > 92) {
    responderError('Elige un rango de hasta 3 meses.', 422);
}

$filas = liberadosPorDia(obtenerConexion(), $desde, $hasta);
responderOk(['desde' => $desde, 'hasta' => $hasta, 'liberados' => $filas]);
