<?php
/**
 * v10.31 - Panel de Desarrollador: correos que NO pudieron salir (guardados
 * por Mailer::enviar). Devuelve destinatario, asunto, motivo y cuántas veces
 * se intentó -- nunca el contenido completo del correo.
 *
 * A prueba de fallos: si la tabla aún no existe (migración sin correr),
 * responde lista vacía en vez de error.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

iniciarSesionSegura();
requireRol(['Desarrollador']);
exigirMetodo('GET');

$pdo = obtenerConexion();
try {
    $stmt = $pdo->query(
        "SELECT id, destinatario, nombre, asunto, error, intentos, creado_at, ultimo_intento_at
           FROM correos_fallidos
          WHERE estado = 'fallido'
          ORDER BY creado_at DESC
          LIMIT 200"
    );
    $correos = $stmt->fetchAll();
    $tabla = true;
} catch (PDOException $e) {
    $correos = [];
    $tabla = false;
}

responderOk(['correos' => $correos, 'tabla_disponible' => $tabla]);
