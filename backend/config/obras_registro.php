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
        ],
    ],
    'h58' => [
        'prefijo' => '/h58',
        'db'      => 'icafal_rrhh_h58',
        'nombre'  => 'Obra H58 Padre Hurtado III',
        'buk'     => [
            'subarea'          => '',
            'subarea_nombre'   => 'H58 Conjunto Padre Hurtado Somos Todos Etapa 3',
            'empresa'          => 'Icafal Ingeniería y Construcción S.A.',
            'codigo_corto'     => 'h58',
            'supervisor_rut'   => '',
            'supervisor_ficha' => '',
            'horario_semanal'  => '42',
        ],
    ],
];
