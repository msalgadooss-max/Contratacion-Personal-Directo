<?php
// Redirige el dominio "pelado" al formulario público de postulación,
// para que entrar sin ninguna ruta especifica tambien funcione.
// v10.32: con prefijo de obra (/h58) redirige dentro de esa obra.
require_once __DIR__ . '/backend/config/config.php';
header('Location: ' . OBRA_PREFIJO . '/frontend/public/index.html');
exit;
