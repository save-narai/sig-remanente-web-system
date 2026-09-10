<?php

declare(strict_types=1);



/* ======================================================
   CONSTANTES
====================================================== */

const ESTADOS_NUEVOS = [
    'NUEVO',
    'CONSOLIDACION'
];

const ESTADOS_MADUROS = [
    'MADURO',
    'LIDER'
];


/* ======================================================
   ACTUALIZAR ESTADOS AUTOMÁTICOS
====================================================== */

function actualizarEstadoActividad(PDO $pdo): void
{
    /* INACTIVOS */

    $stmt = $pdo->prepare("
        UPDATE jovenes
        SET estado_actividad = 'INACTIVO'
        WHERE estado_actividad != 'ELIMINADO'
        AND ultima_actividad IS NOT NULL
        AND DATEDIFF(NOW(), ultima_actividad) >= 60
    ");

    $stmt->execute();

    /* ACTIVOS */

    $stmt = $pdo->prepare("
        UPDATE jovenes
        SET estado_actividad = 'ACTIVO'
        WHERE estado_actividad != 'ELIMINADO'
        AND ultima_actividad IS NOT NULL
        AND DATEDIFF(NOW(), ultima_actividad) < 60
    ");

    $stmt->execute();
}


/* ======================================================
   FALTAS CONSECUTIVAS EN CONEXIÓN
====================================================== */

function faltasConsecutivasConexion(
    PDO $pdo,
    int $joven_id
): int {

    $stmt = $pdo->prepare("
        SELECT a.asistio

        FROM asistencia a

        INNER JOIN reuniones r
            ON a.reunion_id = r.id

        WHERE a.joven_id = :id

        AND r.tipo = 'GRUPO_CONEXION'

        ORDER BY r.fecha DESC

        LIMIT 5
    ");

    $stmt->execute([
        "id" => $joven_id
    ]);

    $asistencias = $stmt->fetchAll(
        PDO::FETCH_COLUMN
    );

    $faltas = 0;

    foreach ($asistencias as $asistio) {

        if ((int) $asistio === 0) {

            $faltas++;

        } else {

            break;
        }
    }

    return $faltas;
}


/* ======================================================
   MESES EN EL MINISTERIO
====================================================== */

function mesesMinisterio(
    ?string $fechaIngreso
): int {

    if (empty($fechaIngreso)) {

        return 0;
    }

    $inicio = new DateTime(
        $fechaIngreso
    );

    $hoy = new DateTime();

    $diff = $inicio->diff($hoy);

    return ($diff->y * 12)
        + $diff->m;
}


/* ======================================================
   ESTADO DE CONEXIÓN DEL JOVEN
====================================================== */

function estadoConexionJoven(
    PDO $pdo,
    int $joven_id
): array {

    $stmt = $pdo->prepare("
        SELECT

            estado_espiritual,
            fecha_ingreso,
            es_servidor

        FROM jovenes

        WHERE id = :id
    ");

    $stmt->execute([
        "id" => $joven_id
    ]);

    $joven = $stmt->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$joven) {

        return [

            "estado" => "Desconocido",

            "color" => "warning",

            "icono" => "⚪"
        ];
    }

    $faltas = faltasConsecutivasConexion(
        $pdo,
        $joven_id
    );

    $estadoEspiritual = strtoupper(
        $joven["estado_espiritual"] ?? ''
    );

    $meses = mesesMinisterio(
        $joven["fecha_ingreso"] ?? null
    );

    $esServidor = (int) (
        $joven["es_servidor"] ?? 0
    );

    /* NUEVOS */

    $esNuevo =
        $meses <= 3
        ||
        in_array(
            $estadoEspiritual,
            ESTADOS_NUEVOS
        );

    /* MADUROS */

    $esMaduro =
        $meses >= 12
        ||
        $esServidor === 1
        ||
        in_array(
            $estadoEspiritual,
            ESTADOS_MADUROS
        );

    /* ALTO RIESGO */

    if ($esNuevo && $faltas >= 4) {

        return [

            "estado" => "Alto Riesgo",

            "color" => "danger",

            "icono" => "🔴"
        ];
    }

    /* RIESGO */

    if (!$esMaduro && $faltas >= 3) {

        return [

            "estado" => "Riesgo",

            "color" => "warning",

            "icono" => "🟡"
        ];
    }

    /* OBSERVACIÓN */

    if ($esMaduro && $faltas >= 3) {

        return [

            "estado" => "Observación",

            "color" => "info",

            "icono" => "🔵"
        ];
    }

    /* CONECTADO */

    return [

        "estado" => "Conectado",

        "color" => "ok",

        "icono" => "🟢"
    ];
}


/* ======================================================
   RESUMEN GLOBAL DEL MINISTERIO
====================================================== */

function resumenConexionMinisterial(
    PDO $pdo
): array {

    $stmt = $pdo->query("
        SELECT id

        FROM jovenes

        WHERE estado_actividad != 'ELIMINADO'
    ");

    $jovenes = $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );

    $conectados = 0;
    $observacion = 0;
    $riesgo = 0;
    $alto = 0;

    foreach ($jovenes as $joven) {

        $estado = estadoConexionJoven(
            $pdo,
            (int) $joven["id"]
        );

        switch ($estado["estado"]) {

            case "Alto Riesgo":
                $alto++;
            break;

            case "Riesgo":
                $riesgo++;
            break;

            case "Observación":
                $observacion++;
            break;

            default:
                $conectados++;
            break;
        }
    }

    return [

        "conectados" => $conectados,

        "observacion" => $observacion,

        "riesgo" => $riesgo,

        "alto" => $alto
    ];
}