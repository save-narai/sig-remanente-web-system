<?php

declare(strict_types=1);

require_once __DIR__ . '/actividadService.php';
require_once __DIR__ . '/jovenService.php';
require_once __DIR__ . '/discipuladoService.php';

/* ==========================================================
   REPORTES (mensual / trimestral / anual) Y CIERRE ANUAL
   ----------------------------------------------------------
   UN solo motor de datos (generarDatosReporte) alimenta la
   vista en pantalla, el Excel, el PDF y el cierre anual: no
   hay cálculos duplicados que puedan diferir entre sí.

   Reglas que respeta (las mismas del resto del sistema):
   - Asistencia: solo reuniones juveniles válidas
     (TIPOS_REUNION_JUVENIL); una ausencia existe únicamente
     donde hay asistencia.asistio = 0 (nunca se inventa una
     ausencia por falta de registro); las fechas salen de
     reuniones.fecha, no de fechas de registro.
   - Actividad juvenil: modelo único ACTIVO/ALERTA/INACTIVO.
   - Estado congregacional: NUEVO/CONGREGANTE.
   - Servidores: usuarios activos de la plataforma.

   IMPORTANTE (honestidad de los datos): el estado de
   actividad y el estado congregacional NO se guardan
   históricamente -- reflejan el momento en que se genera el
   reporte. Por eso el cierre anual guarda la fotografía en
   ese instante: es la única forma de conservar cómo estaba
   cada año.
========================================================== */

const REPORTE_TIPOS = ['mensual', 'trimestral', 'anual'];

const REPORTE_MESES = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
];

/* ==========================================================
   PERÍODO
   - mensual: el mes indicado
   - trimestral: los 3 meses que TERMINAN en el mes indicado
   - anual: el año completo
========================================================== */

function calcularPeriodoReporte(string $tipo, int $anio, ?int $mes): array
{
    if (!in_array($tipo, REPORTE_TIPOS, true)) {
        throw new InvalidArgumentException('Tipo de reporte inválido.');
    }

    $anioMax = (int) date('Y') + 1;

    if ($anio < 2000 || $anio > $anioMax) {
        throw new InvalidArgumentException('Año inválido.');
    }

    if ($tipo === 'anual') {

        return [
            'inicio' => sprintf('%04d-01-01', $anio),
            'fin' => sprintf('%04d-12-31', $anio),
            'texto' => 'Año ' . $anio,
            'mes' => null,
        ];
    }

    if ($mes === null || $mes < 1 || $mes > 12) {
        throw new InvalidArgumentException('Mes inválido.');
    }

    $finMes = (new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes)))
        ->modify('last day of this month');

    if ($tipo === 'mensual') {

        return [
            'inicio' => $finMes->format('Y-m-01'),
            'fin' => $finMes->format('Y-m-d'),
            'texto' => REPORTE_MESES[$mes] . ' ' . $anio,
            'mes' => $mes,
        ];
    }

    $inicio = $finMes->modify('first day of this month')->modify('-2 months');

    return [
        'inicio' => $inicio->format('Y-m-d'),
        'fin' => $finMes->format('Y-m-d'),
        'texto' => REPORTE_MESES[(int) $inicio->format('n')] . ' '
            . $inicio->format('Y') . ' a ' . REPORTE_MESES[$mes] . ' ' . $anio,
        'mes' => $mes,
    ];
}

/* ==========================================================
   DATOS DEL REPORTE
========================================================== */

function generarDatosReporte(PDO $pdo, string $tipo, int $anio, ?int $mes = null): array
{
    $periodo = calcularPeriodoReporte($tipo, $anio, $mes);

    $inicio = $periodo['inicio'];
    $fin = $periodo['fin'];

    // Estados al día antes de contarlos (mismas funciones que el dashboard).
    actualizarEstadoActividad($pdo);

    if (function_exists('actualizarEstadoCongregacional')) {
        actualizarEstadoCongregacional($pdo);
    }

    /* ---------- JÓVENES ---------- */

    $totalJovenes = (int) $pdo->query("
        SELECT COUNT(*) FROM jovenes WHERE estado_actividad != 'ELIMINADO'
    ")->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)

        FROM jovenes

        WHERE estado_actividad != 'ELIMINADO'
        AND fecha_ingreso BETWEEN :inicio AND :fin
    ");
    $stmt->execute(['inicio' => $inicio, 'fin' => $fin]);
    $nuevosPeriodo = (int) $stmt->fetchColumn();

    $actividad = resumenActividadJuvenil($pdo);

    $congregacional = $pdo->query("
        SELECT estado_espiritual, COUNT(*)

        FROM jovenes

        WHERE estado_actividad != 'ELIMINADO'

        GROUP BY estado_espiritual
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    /* ---------- REUNIONES ---------- */

    $stmt = $pdo->prepare("
        SELECT tipo, COUNT(*) AS total

        FROM reuniones

        WHERE fecha BETWEEN :inicio AND :fin

        GROUP BY tipo

        ORDER BY total DESC, tipo ASC
    ");
    $stmt->execute(['inicio' => $inicio, 'fin' => $fin]);
    $reunionesPorTipo = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalReuniones = array_sum(array_map(
        static fn(array $f): int => (int) $f['total'],
        $reunionesPorTipo
    ));

    /* ---------- ASISTENCIA JUVENIL ---------- */

    $tipos = TIPOS_REUNION_JUVENIL;

    $marcadores = implode(',', array_fill(0, count($tipos), '?'));

    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT r.id) AS reuniones_juveniles,
            COUNT(DISTINCT CASE WHEN a.reunion_id IS NOT NULL THEN r.id END) AS con_registro,
            COALESCE(SUM(a.asistio = 1), 0) AS presentes,
            COALESCE(SUM(a.asistio = 0), 0) AS ausentes

        FROM reuniones r

        LEFT JOIN asistencia a
            ON a.reunion_id = r.id

        WHERE r.tipo IN ($marcadores)
        AND r.fecha BETWEEN ? AND ?
    ");

    $stmt->execute(array_merge($tipos, [$inicio, $fin]));

    $asis = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $presentes = (int) ($asis['presentes'] ?? 0);
    $ausentes = (int) ($asis['ausentes'] ?? 0);
    $conRegistro = (int) ($asis['con_registro'] ?? 0);

    /* ---------- SERVIDORES (usuarios activos) ---------- */

    $servidoresPorRol = $pdo->query("
        SELECT r.nombre AS rol, COUNT(*) AS total

        FROM usuarios u

        INNER JOIN roles r
            ON r.id = u.rol_id

        WHERE u.activo = 1

        GROUP BY r.nombre

        ORDER BY total DESC, r.nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    /* ---------- DISCIPULADO ---------- */

    $ciclos = [];

    foreach (obtenerCiclosDiscipulado($pdo) as $ciclo) {

        $cicloInicio = $ciclo['fecha_inicio'] ?? null;
        $cicloFin = $ciclo['fecha_fin'] ?? null;

        // Solo los ciclos que se traslapan con el período del reporte.
        if ($cicloInicio !== null && $cicloInicio > $fin) {
            continue;
        }

        if ($cicloFin !== null && $cicloFin < $inicio) {
            continue;
        }

        $resumen = obtenerResumenCicloDiscipulado($pdo, (int) $ciclo['id']);

        $ciclos[] = [
            'nombre' => (string) ($ciclo['nombre'] ?? ''),
            'estado' => (string) ($ciclo['estado'] ?? ''),
            'inicio' => $cicloInicio,
            'fin' => $cicloFin,
            'participantes' => (int) $resumen['participantes'],
            'completados' => (int) $resumen['completados'],
            'avance_promedio' => (float) $resumen['avance_promedio'],
        ];
    }

    /* ---------- SEGUIMIENTOS ---------- */

    $stmt = $pdo->prepare("
        SELECT estado_proceso, COUNT(*) AS total

        FROM seguimientos

        WHERE fecha_contacto BETWEEN :inicio AND :fin

        GROUP BY estado_proceso

        ORDER BY total DESC
    ");
    $stmt->execute(['inicio' => $inicio, 'fin' => $fin]);
    $seguimientosPorEstado = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [

        'meta' => [
            'tipo' => $tipo,
            'anio' => $anio,
            'mes' => $periodo['mes'],
            'inicio' => $inicio,
            'fin' => $fin,
            'periodo_texto' => $periodo['texto'],
            'generado_en' => date('Y-m-d H:i:s'),
        ],

        'jovenes' => [
            'total' => $totalJovenes,
            'nuevos_periodo' => $nuevosPeriodo,
            'activos' => (int) $actividad['activos'],
            'alerta' => (int) $actividad['alerta'],
            'inactivos' => (int) $actividad['inactivos'],
            'nuevo' => (int) ($congregacional['NUEVO'] ?? 0),
            'congregante' => (int) ($congregacional['CONGREGANTE'] ?? 0),
        ],

        'reuniones' => [
            'total' => $totalReuniones,
            'por_tipo' => $reunionesPorTipo,
        ],

        'asistencia' => [
            'reuniones_juveniles' => (int) ($asis['reuniones_juveniles'] ?? 0),
            'reuniones_con_registro' => $conRegistro,
            'presentes' => $presentes,
            'ausentes' => $ausentes,
            'porcentaje' => ($presentes + $ausentes) > 0
                ? round(($presentes / ($presentes + $ausentes)) * 100, 1)
                : null,
            'promedio_por_reunion' => $conRegistro > 0
                ? round($presentes / $conRegistro, 1)
                : null,
        ],

        'servidores' => [
            'total' => array_sum(array_map(
                static fn(array $f): int => (int) $f['total'],
                $servidoresPorRol
            )),
            'por_rol' => $servidoresPorRol,
        ],

        'discipulado' => [
            'ciclos' => $ciclos,
        ],

        'seguimientos' => [
            'total' => array_sum(array_map(
                static fn(array $f): int => (int) $f['total'],
                $seguimientosPorEstado
            )),
            'por_estado' => $seguimientosPorEstado,
        ],
    ];
}

/* ==========================================================
   SECCIONES (una sola estructura que consumen la vista, el
   Excel y el PDF -- así los tres muestran exactamente lo mismo)

   Cada sección: ['titulo', 'columnas' => [...], 'filas' => [[...]],
                  'nota' => ?string]
========================================================== */

function reporteSecciones(array $r): array
{
    $j = $r['jovenes'];
    $a = $r['asistencia'];

    $pct = static fn(?float $v): string => $v === null ? 'Sin datos' : $v . '%';

    $secciones = [];

    $secciones[] = [
        'titulo' => 'Jóvenes',
        'columnas' => ['Indicador', 'Valor'],
        'filas' => [
            ['Total de jóvenes (no eliminados)', $j['total']],
            ['Jóvenes que ingresaron en el período', $j['nuevos_periodo']],
            ['Actividad: activos', $j['activos']],
            ['Actividad: en alerta (4 a 11 ausencias seguidas)', $j['alerta']],
            ['Actividad: inactivos (12 o más ausencias seguidas)', $j['inactivos']],
            ['Estado congregacional: nuevos (menos de 6 meses)', $j['nuevo']],
            ['Estado congregacional: congregantes (6 meses o más)', $j['congregante']],
        ],
        'nota' => 'Actividad y estado congregacional reflejan el momento en que se generó este reporte.',
    ];

    $filasReuniones = [['Total de reuniones en el período', $r['reuniones']['total']]];

    foreach ($r['reuniones']['por_tipo'] as $fila) {
        $filasReuniones[] = ['   ' . $fila['tipo'], (int) $fila['total']];
    }

    $secciones[] = [
        'titulo' => 'Reuniones',
        'columnas' => ['Indicador', 'Valor'],
        'filas' => $filasReuniones,
        'nota' => null,
    ];

    $secciones[] = [
        'titulo' => 'Asistencia juvenil',
        'columnas' => ['Indicador', 'Valor'],
        'filas' => [
            ['Reuniones juveniles en el período', $a['reuniones_juveniles']],
            ['Reuniones con asistencia registrada', $a['reuniones_con_registro']],
            ['Asistencias registradas (presentes)', $a['presentes']],
            ['Ausencias registradas', $a['ausentes']],
            ['Porcentaje de asistencia', $pct($a['porcentaje'])],
            ['Promedio de asistentes por reunión', $a['promedio_por_reunion'] ?? 'Sin datos'],
        ],
        'nota' => 'Solo reuniones juveniles (Reunión Jóvenes y Grupo Conexión). Una ausencia cuenta únicamente cuando quedó registrada; una reunión sin registro no suma ausencias.',
    ];

    $filasServidores = [['Servidores activos en la plataforma', $r['servidores']['total']]];

    foreach ($r['servidores']['por_rol'] as $fila) {
        $filasServidores[] = ['   ' . $fila['rol'], (int) $fila['total']];
    }

    $secciones[] = [
        'titulo' => 'Servidores del ministerio',
        'columnas' => ['Indicador', 'Valor'],
        'filas' => $filasServidores,
        'nota' => 'Son los usuarios activos registrados en la plataforma.',
    ];

    $filasCiclos = [];

    foreach ($r['discipulado']['ciclos'] as $c) {
        $filasCiclos[] = [
            $c['nombre'],
            $c['estado'],
            $c['inicio'] ?? '—',
            $c['fin'] ?? '—',
            $c['participantes'],
            $c['completados'],
            $c['avance_promedio'] . '%',
        ];
    }

    $secciones[] = [
        'titulo' => 'Discipulado / Formación',
        'columnas' => ['Ciclo', 'Estado', 'Inicio', 'Fin', 'Participantes', 'Completaron', 'Avance promedio'],
        'filas' => $filasCiclos,
        'nota' => empty($filasCiclos)
            ? 'No hay ciclos de discipulado que coincidan con este período.'
            : 'Participantes y avance reflejan el momento en que se generó este reporte.',
    ];

    $filasSeg = [['Seguimientos con contacto en el período', $r['seguimientos']['total']]];

    foreach ($r['seguimientos']['por_estado'] as $fila) {
        $filasSeg[] = ['   ' . $fila['estado_proceso'], (int) $fila['total']];
    }

    $secciones[] = [
        'titulo' => 'Seguimientos',
        'columnas' => ['Indicador', 'Valor'],
        'filas' => $filasSeg,
        'nota' => null,
    ];

    return $secciones;
}

/* ==========================================================
   CIERRE ANUAL
========================================================== */

function cierresAnualesDisponible(PDO $pdo): bool
{
    static $disponible = null;

    if ($disponible !== null) {
        return $disponible;
    }

    try {

        $stmt = $pdo->query("
            SELECT COUNT(*)

            FROM information_schema.TABLES

            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'cierres_anuales'
        ");

        $disponible = (int) $stmt->fetchColumn() > 0;

    } catch (Throwable $e) {

        $disponible = false;
    }

    return $disponible;
}

function listarCierresAnuales(PDO $pdo): array
{
    if (!cierresAnualesDisponible($pdo)) {
        return [];
    }

    return $pdo->query("
        SELECT id, anio, cerrado_por_nombre, cerrado_en

        FROM cierres_anuales

        ORDER BY anio DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerCierreAnual(PDO $pdo, int $id): ?array
{
    if (!cierresAnualesDisponible($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id, anio, datos, cerrado_por_nombre, cerrado_en

        FROM cierres_anuales

        WHERE id = :id
    ");

    $stmt->execute(['id' => $id]);

    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fila) {
        return null;
    }

    $datos = json_decode((string) $fila['datos'], true);

    if (!is_array($datos)) {
        return null;
    }

    $datos['meta']['origen'] = 'cierre';
    $datos['meta']['cerrado_en'] = $fila['cerrado_en'];
    $datos['meta']['cerrado_por'] = $fila['cerrado_por_nombre'];

    return $datos;
}

/**
 * Guarda la fotografía del año. NO modifica ningún otro dato.
 * Un solo cierre por año, inmutable: si ya existe, se rechaza
 * (jamás se sobrescribe el historial en silencio).
 */
function guardarCierreAnual(PDO $pdo, int $anio, int $usuarioId, string $usuarioNombre): int
{
    if (!cierresAnualesDisponible($pdo)) {
        throw new RuntimeException(
            'Falta ejecutar la migración database/migrations/20260927_cierres_anuales.sql.'
        );
    }

    if ($anio > (int) date('Y')) {
        throw new InvalidArgumentException('No se puede cerrar un año que todavía no empieza.');
    }

    $datos = generarDatosReporte($pdo, 'anual', $anio);

    $datos['meta']['origen'] = 'cierre';

    $stmt = $pdo->prepare("
        INSERT INTO cierres_anuales
            (anio, datos, cerrado_por, cerrado_por_nombre)
        VALUES
            (:anio, :datos, :usuario_id, :usuario_nombre)
    ");

    try {

        $stmt->execute([
            'anio' => $anio,
            'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'usuario_id' => $usuarioId,
            'usuario_nombre' => mb_substr($usuarioNombre, 0, 150),
        ]);

    } catch (PDOException $e) {

        if ((string) $e->getCode() === '23000') {
            throw new RuntimeException('El año ' . $anio . ' ya fue cerrado. Un cierre no se puede sobrescribir.');
        }

        throw $e;
    }

    return (int) $pdo->lastInsertId();
}
