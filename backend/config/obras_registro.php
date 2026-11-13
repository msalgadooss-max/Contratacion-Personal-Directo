<?php
/**
 * v10.32 - Registro de obras. UNICA fuente de verdad de que obras existen.
 *
 * Cada obra tiene su PROPIA base de datos (usuarios, cargos, cupos,
 * postulaciones, todo), asi que las personas y datos de una obra jamas se
 * mezclan con los de otra. Todas conviven en el mismo servicio, el mismo
 * MariaDB y el mismo disco.
 *
 * La obra se elige por el prefijo de la direccion web:
 *   - h57 (por defecto, SIN prefijo): https://<dominio>/frontend/public/index.html
 *   - h58:                            https://<dominio>/h58/frontend/public/index.html
 *
 * Para agregar una obra nueva: una entrada mas aqui + una linea en el
 * Dockerfile no hace falta (el AliasMatch ya cubre /h<numero>/). La base
 * se crea sola en el proximo arranque (docker-entrypoint.sh).
 *
 * Los datos 'buk' alimentan la planilla "Trabajos" que exporta el JAO.
 * Los que estan vacios salen vacios en la planilla (mejor vacio que con
 * los datos de otra obra): completarlos aqui cuando se conozcan.
 */
return [
    'h57' => [
        'prefijo' => '',
        'db'      => 'icafal_rrhh',
        'nombre'  => 'Obra H57 Padre Hurtado IV',
        'buk'     => [
            'subarea'          => '9042',
            'subarea_nombre'   => 'H57 Conjunto Padre Hurtado Etapa 4',
            'empresa'          => 'Icafal Ingeniería y Construcción S.A.',
            'codigo_corto'     => 'h57',
            'supervisor_rut'   => '13.855.883-5',
            'supervisor_ficha' => 'ICP00254',
            'horario_semanal'  => '42',
            // Plantilla "Trabajos" que acepta Buk para esta obra: 'sep2026' = la del
            // envio real de septiembre (37 columnas, con ctrlit_recinto*); 'nov2026' =
            // la descargada el 09-11-2026 (36 columnas, sin ctrlit_recinto*).
            'plantilla_trabajos' => 'sep2026',
            // Si se informa asistencia (Registra asistencia + Recinto para marcar).
            'asistencia'       => true,
        ],
    ],
    'h58' => [
        'prefijo' => '/h58',
        'db'      => 'icafal_rrhh_h58',
        'nombre'  => 'Obra H58 Padre Hurtado III',
        'buk'     => [
            // Datos tomados de los templates de Buk de H58 (09-11-2026): sub-area 11021
            // "H58 Conjunto Padre Hurtado Etapa 3"; supervisor 15317108-4 (mismo formato que el RUT de los trabajadores en el export), ficha CPV00010.
            'subarea'          => '11021',
            'subarea_nombre'   => 'H58 Conjunto Padre Hurtado Etapa 3',
            'empresa'          => 'Icafal Ingeniería y Construcción S.A.',
            'codigo_corto'     => 'h58',
            'supervisor_rut'   => '15317108-4',
            'supervisor_ficha' => 'CPV00010',
            'horario_semanal'  => '42',
            'plantilla_trabajos' => 'nov2026',
            // La hoja "Recintos" del template de H58 viene vacia y su fila de ejemplo deja
            // la asistencia en blanco: informar el recinto "h58" haria referencia a algo
            // que en Buk no existe.
            'asistencia'       => false,
        ],
    ],
];
