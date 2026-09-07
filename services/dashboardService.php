<?php

declare(strict_types=1);

/* =========================================================
   DASHBOARD COMPLETO
   ---------------------------------------------------------
   Esta capa NO calcula actividad/riesgo por su cuenta.
   Reutiliza actividadService.php (fuente central de verdad
   para actividad/riesgo, ver Fase 5 del prompt maestro) y
   asignacionSeguimientoService.php (fuente central para
   seguimiento pendiente de jóvenes nuevos, Fase 6/14).
========================================================= */

require_once __DIR__ . '/actividadService.php';
require_once __DIR__ . '/asignacionSeguimientoService.php';
require_once __DIR__ . '/discipuladoService.php';

function obtenerDashboardData(PDO $pdo): array
{
    $resumen = obtenerResumenGeneral($pdo);

    $resumen["reuniones"] =
        obtenerTotalReuniones($pdo);

    $resumen["asistencia"] =
        obtenerAsistenciaGeneral($pdo);

    $nuevos = obtenerNuevosAntiguos($pdo);

    $resumen["nuevos"] =
        $nuevos["nuevos"];

    $resumen["antiguos"] =
        $nuevos["antiguos"];

    // Distribución por estado_espiritual (congregantes, discipulado, etc.)
    // usa las mismas 5 categorías ya definidas en jovenService.php
    // (ESTADOS_ESPIRITUALES), no se inventa ninguna nueva.
    $resumen["porEstadoEspiritual"] =
        obtenerDistribucionEstadoEspiritual($pdo);

    // Indicador provisional "Servidores/Líderes de Jóvenes" para el
    // Dashboard: estado_espiritual IN ('SERVIDOR','LIDER'). A
    // propósito NO es lo mismo que resumen['servidores'] (que sigue
    // viniendo de la columna es_servidor, sin tocar, para lo que ya
    // la use). PENDIENTE documentado: ni estado_espiritual ni
    // es_servidor tienen en el modelo actual una columna que indique
    // "ministerio de Jóvenes" vs "otro ministerio" (lo que pide la
    // Fase 4) — este número es el mejor disponible hoy, no una
    // certeza sobre a qué ministerio pertenece cada quien.
    $resumen["servidoresLideresJovenes"] =
        $resumen["porEstadoEspiritual"]["servidor"] +
        $resumen["porEstadoEspiritual"]["lider"];

    // Actividad/riesgo: se reutiliza actividadService.php tal cual
    // (Conectado / Observación / Riesgo / Alto Riesgo), en vez de la
    // lógica propia que tenía este archivo antes (ver informe de la
    // Fase 2 - Etapa 1 para el detalle de la duplicación encontrada).
    $conexion = resumenConexionMinisterial($pdo);

    // Seguimiento pendiente (jóvenes NUEVOS sin nadie asignado este
    // mes): se reutiliza asignacionSeguimientoService.php, la misma
    // función que ya usa views/seguimientos/asignaciones.php.
    $seguimientoPendiente = obtenerJovenesPendientesSinAsignar(
        $pdo,
        (int) date('Y'),
        (int) date('n')
    );

    // Discipulado/Formación que requiere atención: se suma
    // requieren_atencion (ya calculado por discipuladoService.php,
    // obtenerResumenCicloDiscipulado) de cada ciclo ACTIVO. No se
    // reimplementa ninguna regla de alerta, solo se agrega entre
    // ciclos. Es un conteo de INSCRIPCIONES con alerta, no de
    // jóvenes únicos: una misma persona con más de una inscripción
    // podría contarse más de una vez (poco probable hoy, pero no se
    // asume lo contrario).
    $ciclosActivos = obtenerCiclosDiscipulado($pdo, ['estado' => 'ACTIVO']);

    $discipuladoAtencion = 0;

    foreach ($ciclosActivos as $ciclo) {

        $resumenCiclo = obtenerResumenCicloDiscipulado($pdo, (int) $ciclo['id']);

        $discipuladoAtencion += (int) $resumenCiclo['requieren_atencion'];
    }

    // Total de ciclos de discipulado (cualquier estado) para la
    // tarjeta "Ciclos" del Dashboard. Se reutiliza el conteo de
    // activos ya obtenido arriba, no se repite esa consulta.
    $resumen["ciclosDiscipuladoTotal"] =
        count(obtenerCiclosDiscipulado($pdo));

    $resumen["ciclosDiscipuladoActivos"] =
        count($ciclosActivos);

    return [

        "resumen" => $resumen,

        "graficas" => [

            "mensual" => [],
            "tipos" => [],
            "estado" => []
        ],

        // Se conservan estas 3 claves (mismo nombre y mismo criterio
        // de combinación que antes: alertas = riesgo + alto) para no
        // romper la vista actual, que ya las consume. Lo único que
        // cambió es la FUENTE del número: ahora sale de
        // actividadService en vez de una copia local del cálculo.
        "alertas" =>
            $conexion["riesgo"] + $conexion["alto"],

        "riesgo" =>
            $conexion["riesgo"],

        "alto" =>
            $conexion["alto"],

        // Nuevo, todavía sin usar en la vista (eso es la Etapa 2):
        // se deja disponible aquí para que el rediseño del dashboard
        // pueda tomarlo, ya con la fuente correcta y sin duplicar
        // ninguna consulta.
        "conectados" =>
            $conexion["conectados"],

        "observacion" =>
            $conexion["observacion"],

        // Las 3 fuentes de "atención pendiente" se entregan POR
        // SEPARADO a propósito (decisión del usuario): no se suman
        // en un único número porque no representan lo mismo (una es
        // riesgo de asistencia, otra es seguimiento de jóvenes
        // nuevos, otra es avance de discipulado) y una misma persona
        // podría aparecer en más de una. La Etapa 2 decide cómo se
        // muestran (separadas, agrupadas, o ambas).
        "seguimientoPendiente" =>
            count($seguimientoPendiente),

        "discipuladoAtencion" =>
            $discipuladoAtencion
    ];
}


/* =========================================================
   RESUMEN GENERAL
========================================================= */

function obtenerResumenGeneral(
    PDO $pdo
): array {

    $stmt = $pdo->prepare("
        SELECT

            COUNT(*) as total,

            SUM(
                estado_actividad = 'ACTIVO'
            ) as activos,

            SUM(
                estado_actividad = 'INACTIVO'
            ) as inactivos,

            SUM(
                es_servidor = 1
                AND estado_actividad != 'ELIMINADO'
            ) as servidores

        FROM jovenes

        WHERE estado_actividad != 'ELIMINADO'
    ");

    $stmt->execute();

    $data =
        $stmt->fetch(PDO::FETCH_ASSOC);

    return [

        "totalJovenes" =>
            (int)($data["total"] ?? 0),

        "activos" =>
            (int)($data["activos"] ?? 0),

        "inactivos" =>
            (int)($data["inactivos"] ?? 0),

        "servidores" =>
            (int)($data["servidores"] ?? 0)
    ];
}


/* =========================================================
   TOTAL REUNIONES
========================================================= */

function obtenerTotalReuniones(
    PDO $pdo
): int {

    return (int) $pdo
        ->query("
            SELECT COUNT(*)
            FROM reuniones
        ")
        ->fetchColumn();
}


/* =========================================================
   ASISTENCIA GENERAL
========================================================= */

function obtenerAsistenciaGeneral(
    PDO $pdo
): float {

    $stmt = $pdo->prepare("
        SELECT

            COUNT(*) as total,

            SUM(asistio = 1)
            as presentes

        FROM asistencia
    ");

    $stmt->execute();

    $data =
        $stmt->fetch(PDO::FETCH_ASSOC);

    $total =
        (int)($data["total"] ?? 0);

    $presentes =
        (int)($data["presentes"] ?? 0);

    return $total > 0

        ? round(
            ($presentes / $total) * 100,
            1
        )

        : 0;
}


/* =========================================================
   NUEVOS VS ANTIGUOS
========================================================= */

function obtenerNuevosAntiguos(
    PDO $pdo
): array {

    $stmt = $pdo->prepare("
        SELECT

            SUM(
                TIMESTAMPDIFF(
                    MONTH,
                    fecha_ingreso,
                    CURDATE()
                ) <= 3
            ) as nuevos,

            SUM(
                TIMESTAMPDIFF(
                    MONTH,
                    fecha_ingreso,
                    CURDATE()
                ) > 3
            ) as antiguos

        FROM jovenes

        WHERE estado_actividad != 'ELIMINADO'
    ");

    $stmt->execute();

    $data =
        $stmt->fetch(PDO::FETCH_ASSOC);

    return [

        "nuevos" =>
            (int)($data["nuevos"] ?? 0),

        "antiguos" =>
            (int)($data["antiguos"] ?? 0)
    ];
}


/* =========================================================
   DISTRIBUCION POR ESTADO ESPIRITUAL
   ---------------------------------------------------------
   Reutiliza las mismas 5 categorias ya definidas en
   jovenService.php (ESTADOS_ESPIRITUALES): NUEVO,
   CONGREGANTE, DISCIPULADO, SERVIDOR, LIDER. No se agrega
   ninguna categoria ni columna nueva.
========================================================= */

function obtenerDistribucionEstadoEspiritual(
    PDO $pdo
): array {

    $stmt = $pdo->prepare("
        SELECT

            estado_espiritual,
            COUNT(*) as total

        FROM jovenes

        WHERE estado_actividad != 'ELIMINADO'

        GROUP BY estado_espiritual
    ");

    $stmt->execute();

    $filas = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    return [

        "nuevo" => (int)($filas["NUEVO"] ?? 0),
        "congregante" => (int)($filas["CONGREGANTE"] ?? 0),
        "discipulado" => (int)($filas["DISCIPULADO"] ?? 0),
        "servidor" => (int)($filas["SERVIDOR"] ?? 0),
        "lider" => (int)($filas["LIDER"] ?? 0)
    ];
}
