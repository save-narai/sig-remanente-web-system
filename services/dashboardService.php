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

    // Distribución por estado congregacional (Fase 6 -- reemplaza a
    // lo que antes se llamaba "estado espiritual"; ahora son solo 2
    // categorías: NUEVO/CONGREGANTE, ver jovenService.php).
    $resumen["porEstadoEspiritual"] =
        obtenerDistribucionEstadoEspiritual($pdo);

    // Servidores del Ministerio de Jóvenes: son los USUARIOS ACTIVOS
    // registrados en la plataforma (administradora/secretaria, líder,
    // sublíder y usuarios independientes) -- decisión posterior a la
    // reunión de afinación. Ya no se calcula desde jóvenes ni se
    // excluye ningún rol: quien tiene una cuenta activa está sirviendo
    // en el ministerio. Se conserva la clave "servidoresLideresJovenes"
    // para no romper la vista.
    $resumen["servidoresLideresJovenes"] =
        (int) $pdo->query("
            SELECT COUNT(*)

            FROM usuarios

            WHERE activo = 1
        ")->fetchColumn();

    // Actividad juvenil: única fuente de verdad, modelo 4/12
    // confirmado (ACTIVO/ALERTA/INACTIVO). El modelo antiguo
    // (Conectado/Observación/Riesgo/Alto Riesgo, basado solo en
    // "Grupo Conexión" y umbrales 3/4 propios) fue retirado por
    // completo: podía mostrar "Conectado" mientras
    // jovenes.estado_actividad ya marcaba INACTIVO.
    $actividad = resumenActividadJuvenil($pdo);

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

        // Modelo único (4/12 ausencias consecutivas demostrables).
        // "alerta" = 4 a 11 ausencias consecutivas (informativo,
        // no persistido). "inactivos" ya viene arriba en $resumen,
        // tomado de jovenes.estado_actividad (persistido); se repite
        // aquí también por si una vista quiere el número "en vivo"
        // (antes de que actualizarEstadoActividad() corra de nuevo).
        "alerta" =>
            $actividad["alerta"],

        "activosEnVivo" =>
            $actividad["activos"],

        "inactivosEnVivo" =>
            $actividad["inactivos"],

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
   DISTRIBUCION POR ESTADO CONGREGACIONAL (Fase 6)
   ---------------------------------------------------------
   Ya NO son 5 categorias. jovenes.estado_espiritual quedo
   restringido a NUEVO/CONGREGANTE unicamente (ver migracion
   20260922_estado_congregacional_y_vinculo_usuario.sql y
   jovenService.php::ESTADOS_CONGREGACIONALES). Discipulado y
   Servidor/Lider ya no viven aqui.
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
        "congregante" => (int)($filas["CONGREGANTE"] ?? 0)
    ];
}
