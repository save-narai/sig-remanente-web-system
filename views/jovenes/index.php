<?php
require_once __DIR__ . "/../../middleware/auth.php";
require_once __DIR__ . "/../../middleware/permiso.php";
require_once __DIR__ . "/../../services/actividadService.php";
require_once __DIR__ . "/../../services/jovenService.php";
require_once __DIR__ . "/../../config/conexion.php";
require_once __DIR__ . "/../../helpers/csrf.php";


if (!tienePermiso('gestionar_jovenes')) {
    header("Location: ../dashboard.php");
    exit;
}

generarCsrf();

// Refresca jovenes.estado_actividad antes de leerlo/filtrarlo/contarlo
// en esta vista -- sin esto, el listado podía mostrar un estado
// ACTIVO/INACTIVO desactualizado si nadie había visitado el Dashboard
// (u otra vista que ya llama a esta función) desde el último cambio
// de asistencia. Misma llamada que ya hacen dashboardController.php,
// historial.php y perfil_pdf.php -- fuente única y siempre fresca.
actualizarEstadoActividad($pdo);

/* =========================================================
   FILTROS (multi-filtro real)
   ---------------------------------------------------------
   4 grupos independientes que se combinan entre sí con AND:
   Estado (excluyente, un solo valor) · Actividad/Riesgo ·
   Estado espiritual · Características (estos 3 últimos
   admiten varios valores a la vez, combinados con OR dentro
   del propio grupo). "Eliminados" es excluyente de todo lo
   demás (papelera administrativa).

   Compatibilidad: el antiguo ?filtro=X (un solo valor) sigue
   funcionando — se traduce aquí mismo al esquema nuevo antes
   de leer nada más, para no romper enlaces/marcadores ya
   guardados. No se muestra ese formato en la interfaz nueva.
========================================================= */

if (
    isset($_GET['filtro']) &&
    !isset($_GET['estado']) &&
    !isset($_GET['riesgo']) &&
    !isset($_GET['espiritu']) &&
    !isset($_GET['caracteristica'])
) {

    $mapaFiltroAntiguo = [
        'todos'                        => ['estado' => 'todos'],
        'activos'                      => ['estado' => 'activos'],
        'inactivos'                    => ['estado' => 'inactivos'],
        'eliminados'                   => ['estado' => 'eliminados'],
        // Compatibilidad con marcadores antiguos: el modelo viejo
        // tenía 2 niveles (Riesgo/Alto riesgo); el modelo único
        // confirmado solo tiene 1 nivel intermedio (Alerta), así
        // que ambos degradan al mismo filtro.
        'riesgo2'                      => ['riesgo' => ['alerta']],
        'riesgo3'                      => ['riesgo' => ['alerta']],
        'nuevos'                       => ['caracteristica' => ['nuevos']],
        'antiguos'                     => ['caracteristica' => ['antiguos']],
        'congregantes'                 => ['espiritu' => ['congregantes']],
        // Fase 6: "discipulado" y "servidores_lideres" ya no son
        // estado_espiritual. El primero ya no tiene equivalente
        // (la fuente de verdad es el módulo de discipulado, no un
        // filtro de jóvenes); el segundo ahora sale de Usuarios.
        'servidores_lideres'           => ['caracteristica' => ['servidores_ministerio_jovenes']],
        'servidores_todos_ministerios' => ['caracteristica' => ['servidores_todos_ministerios']]
    ];

    $legacy = (string) $_GET['filtro'];

    if (isset($mapaFiltroAntiguo[$legacy])) {
        $_GET = array_merge($_GET, $mapaFiltroAntiguo[$legacy]);
    }
}

$estadosPermitidos = ['todos', 'activos', 'inactivos', 'eliminados'];
$riesgoPermitidos = ['alerta'];
$espirituPermitidos = ['nuevo_espiritual', 'congregantes'];
$caracteristicaPermitidos = ['nuevos', 'antiguos', 'servidores_todos_ministerios', 'servidores_ministerio_jovenes'];

$estado = (string) ($_GET['estado'] ?? 'todos');

if (!in_array($estado, $estadosPermitidos, true)) {
    $estado = 'todos';
}

$riesgoSeleccion = array_values(array_intersect(
    (array) ($_GET['riesgo'] ?? []),
    $riesgoPermitidos
));

$espirituSeleccion = array_values(array_intersect(
    (array) ($_GET['espiritu'] ?? []),
    $espirituPermitidos
));

$caracteristicaSeleccion = array_values(array_intersect(
    (array) ($_GET['caracteristica'] ?? []),
    $caracteristicaPermitidos
));

// "Eliminados" es excluyente: si está activo, se ignora cualquier
// otro grupo seleccionado (no tiene sentido combinar la papelera
// con riesgo/estado espiritual/características).
if ($estado === 'eliminados') {
    $riesgoSeleccion = [];
    $espirituSeleccion = [];
    $caracteristicaSeleccion = [];
}

$totalFiltrosActivos =
    ($estado !== 'todos' ? 1 : 0) +
    count($riesgoSeleccion) +
    count($espirituSeleccion) +
    count($caracteristicaSeleccion);

/* =========================================================
   QUERY
========================================================= */

// Refresca jovenes.estado_espiritual (estado congregacional) antes
// de leerlo/filtrarlo -- es una funcion pura de fecha_ingreso, el
// UPDATE es una sola sentencia barata (ver jovenService.php).
actualizarEstadoCongregacional($pdo);

$query = "
SELECT
    j.id,
    j.nombre_completo,
    j.fecha_nacimiento,
    j.edad_manual,
    j.fecha_actualizacion_edad,
    j.estado_espiritual,
    j.estado_actividad,
    j.fecha_ingreso,
    j.usuario_id

FROM jovenes j

LEFT JOIN usuarios u
    ON u.id = j.usuario_id

LEFT JOIN roles r
    ON r.id = u.rol_id
";

$where = [];

if ($estado === 'eliminados') {

    $where[] = "j.estado_actividad = 'ELIMINADO'";

} else {

    $where[] = "j.estado_actividad != 'ELIMINADO'";

    if ($estado === 'activos') {
        $where[] = "j.estado_actividad = 'ACTIVO'";
    }

    if ($estado === 'inactivos') {
        $where[] = "j.estado_actividad = 'INACTIVO'";
    }
}

// Estado congregacional (Fase 6): solo NUEVO/CONGREGANTE, derivado
// de fecha_ingreso. "Discipulado" y "Servidor/Líder" ya no viven
// aquí (ver Características más abajo).
if (!empty($espirituSeleccion)) {

    $condiciones = [];

    if (in_array('nuevo_espiritual', $espirituSeleccion, true)) {
        $condiciones[] = "j.estado_espiritual = 'NUEVO'";
    }

    if (in_array('congregantes', $espirituSeleccion, true)) {
        $condiciones[] = "j.estado_espiritual = 'CONGREGANTE'";
    }

    $where[] = '(' . implode(' OR ', $condiciones) . ')';
}

// Características: "nuevos"/"antiguos" mismo criterio exacto de
// dashboardService.php::obtenerNuevosAntiguos(); "servidores de
// cualquier ministerio" = es_servidor (columna propia, sin relación
// con lo demás); "servidor del Ministerio de Jóvenes" (Fase 6) =
// misma fuente y mismo criterio que
// actividadService.php::esServidorMinisterioJovenes() (usuario_id
// vinculado y su rol no es el ADMIN protegido). Varios valores del
// grupo se combinan con OR.
if (!empty($caracteristicaSeleccion)) {

    $condiciones = [];

    if (in_array('nuevos', $caracteristicaSeleccion, true)) {
        $condiciones[] = "TIMESTAMPDIFF(MONTH, j.fecha_ingreso, CURDATE()) <= 3";
    }

    if (in_array('antiguos', $caracteristicaSeleccion, true)) {
        $condiciones[] = "TIMESTAMPDIFF(MONTH, j.fecha_ingreso, CURDATE()) > 3";
    }

    if (in_array('servidores_todos_ministerios', $caracteristicaSeleccion, true)) {
        $condiciones[] = "j.es_servidor = 1";
    }

    if (in_array('servidores_ministerio_jovenes', $caracteristicaSeleccion, true)) {
        $condiciones[] = "(j.usuario_id IS NOT NULL AND u.activo = 1)";
    }

    $where[] = '(' . implode(' OR ', $condiciones) . ')';
}

$query .= " WHERE " . implode(" AND ", $where);

$query .= " ORDER BY j.nombre_completo ASC";

$stmt = $pdo->prepare($query);

$stmt->execute();

$jovenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Alerta: modelo único confirmado (4/12 ausencias consecutivas
// demostrables). Se reutiliza actividadService.php::etiquetaVisualActividadJuvenil()
// (la misma fuente central que ya usa el Dashboard), filtrando en PHP
// después de traer la lista, para que el número de este listado y el
// del KPI del Dashboard salgan siempre del mismo criterio.
if (!empty($riesgoSeleccion)) {

    $jovenes = array_values(array_filter(
        $jovenes,
        function (array $j) use ($pdo): bool {
            $etiqueta = etiquetaVisualActividadJuvenil($pdo, (int) $j["id"]);
            return $etiqueta["clasificacion"] === 'ALERTA';
        }
    ));
}



/* =========================================================
   HELPERS DE URL PARA LOS CHIPS DE FILTROS ACTIVOS
========================================================= */

function jovenesUrlSinValor(
    string $estado,
    array $riesgo,
    array $espiritu,
    array $caracteristica,
    string $grupo,
    ?string $valor = null
): string {

    if ($grupo === 'estado') {
        $estado = 'todos';
    }

    if ($grupo === 'riesgo' && $valor !== null) {
        $riesgo = array_values(array_diff($riesgo, [$valor]));
    }

    if ($grupo === 'espiritu' && $valor !== null) {
        $espiritu = array_values(array_diff($espiritu, [$valor]));
    }

    if ($grupo === 'caracteristica' && $valor !== null) {
        $caracteristica = array_values(array_diff($caracteristica, [$valor]));
    }

    $parametros = ['estado' => $estado];

    if (!empty($riesgo)) {
        $parametros['riesgo'] = $riesgo;
    }

    if (!empty($espiritu)) {
        $parametros['espiritu'] = $espiritu;
    }

    if (!empty($caracteristica)) {
        $parametros['caracteristica'] = $caracteristica;
    }

    return '?' . http_build_query($parametros);
}

require_once __DIR__ . "/../../includes/header.php";

$etiquetasFiltro = [
    'activos' => 'Activos',
    'inactivos' => 'Inactivos',
    'eliminados' => 'Eliminados',
    'alerta' => 'Alerta',
    'nuevo_espiritual' => 'Nuevo',
    'congregantes' => 'Congregante',
    'nuevos' => 'Nuevos (≤3 meses)',
    'antiguos' => 'Antiguos',
    'servidores_todos_ministerios' => 'Servidores (todos los ministerios)',
    'servidores_ministerio_jovenes' => 'Servidores del Ministerio de Jóvenes'
];

?>

<div class="page">

    <?php if(isset($_SESSION["success"])): ?>

    <script>
    document.addEventListener("DOMContentLoaded", () => {

        showToast(
            <?= json_encode($_SESSION["success"]); ?>,
            "success"
        );

    });
    </script>

    <?php unset($_SESSION["success"]); endif; ?>



<!-- HEADER -->

<div class="page-header">

    <div class="page-header-left">

        <h1 class="page-title">
            Gestión de Jóvenes
        </h1>

        <div class="page-subtitle">
            Administra registros, seguimiento y actividad juvenil
        </div>

    </div>

    <div class="page-header-right">

        <!-- NUEVO -->

        <a
            href="<?= BASE_URL ?>/views/jovenes/crear.php"
            class="btn btn-primary"
        >

            <i class="fa-solid fa-plus"></i>

            Nuevo

        </a>

        <!-- EXPORT -->

        <div class="export-dropdown">

            <button
                type="button"
                class="export-dropdown__trigger"
            >

                <i class="fa-solid fa-download"></i>

                Exportar

                <i class="fa-solid fa-chevron-down"></i>

            </button>

            <div class="export-dropdown__menu">

                <button
                    type="button"
                    class="export-option"
                    id="exportPdf"
                >

                    <i class="fa-solid fa-file-pdf"></i>

                    PDF

                </button>

                <button
                    type="button"
                    class="export-option"
                    id="exportExcel"
                >

                    <i class="fa-solid fa-file-excel"></i>

                    Excel

                </button>

                <button
                    type="button"
                    class="export-option"
                    id="exportCsv"
                >

                    <i class="fa-solid fa-file-csv"></i>

                    CSV

                </button>

                <button
                    type="button"
                    class="export-option"
                    id="exportPrint"
                >

                    <i class="fa-solid fa-print"></i>

                    Imprimir

                </button>

            </div>

        </div>

    </div>

</div>

    <!-- FILTROS -->

    <div class="filters-panel">

        <form method="GET" class="filters-panel__form" id="formFiltrosJovenes">

            <div class="filters-panel__groups">

                <details class="filter-dropdown">

                    <summary>
                        Estado
                        <?php if ($estado !== 'todos'): ?>
                            <span class="filter-dropdown__badge" data-count-badge>1</span>
                        <?php endif; ?>
                    </summary>

                    <div class="filter-dropdown__body">

                        <label class="filter-dropdown__option">
                            <input type="radio" name="estado" value="todos" <?= $estado === 'todos' ? 'checked' : '' ?>>
                            Todos
                        </label>

                        <label class="filter-dropdown__option">
                            <input type="radio" name="estado" value="activos" <?= $estado === 'activos' ? 'checked' : '' ?>>
                            Activos
                        </label>

                        <label class="filter-dropdown__option">
                            <input type="radio" name="estado" value="inactivos" <?= $estado === 'inactivos' ? 'checked' : '' ?>>
                            Inactivos
                        </label>

                        <label class="filter-dropdown__option">
                            <input type="radio" name="estado" value="eliminados" <?= $estado === 'eliminados' ? 'checked' : '' ?>>
                            Eliminados
                        </label>

                    </div>

                </details>

                <details class="filter-dropdown" <?= !empty($riesgoSeleccion) ? 'open' : '' ?>>

                    <summary>
                        Actividad / Riesgo
                        <?php if (!empty($riesgoSeleccion)): ?>
                            <span class="filter-dropdown__badge" data-count-badge><?= count($riesgoSeleccion) ?></span>
                        <?php endif; ?>
                    </summary>

                    <div class="filter-dropdown__body" data-group="riesgo">

                        <label class="filter-dropdown__option">
                            <input type="checkbox" name="riesgo[]" value="alerta" <?= in_array('alerta', $riesgoSeleccion, true) ? 'checked' : '' ?>>
                            Alerta (4-11 ausencias)
                        </label>

                    </div>

                </details>

                <details class="filter-dropdown" <?= !empty($espirituSeleccion) ? 'open' : '' ?>>

                    <summary>
                        Estado congregacional
                        <?php if (!empty($espirituSeleccion)): ?>
                            <span class="filter-dropdown__badge" data-count-badge><?= count($espirituSeleccion) ?></span>
                        <?php endif; ?>
                    </summary>

                    <div class="filter-dropdown__body" data-group="espiritu">

                        <label class="filter-dropdown__option" title="Menos de 6 meses desde la fecha de ingreso">
                            <input type="checkbox" name="espiritu[]" value="nuevo_espiritual" <?= in_array('nuevo_espiritual', $espirituSeleccion, true) ? 'checked' : '' ?>>
                            Nuevo
                        </label>

                        <label class="filter-dropdown__option" title="6 meses o más desde la fecha de ingreso">
                            <input type="checkbox" name="espiritu[]" value="congregantes" <?= in_array('congregantes', $espirituSeleccion, true) ? 'checked' : '' ?>>
                            Congregante
                        </label>

                    </div>

                </details>

                <details class="filter-dropdown" <?= !empty($caracteristicaSeleccion) ? 'open' : '' ?>>

                    <summary>
                        Características
                        <?php if (!empty($caracteristicaSeleccion)): ?>
                            <span class="filter-dropdown__badge" data-count-badge><?= count($caracteristicaSeleccion) ?></span>
                        <?php endif; ?>
                    </summary>

                    <div class="filter-dropdown__body" data-group="caracteristica">

                        <label class="filter-dropdown__option" title="Fecha de ingreso hace 3 meses o menos">
                            <input type="checkbox" name="caracteristica[]" value="nuevos" <?= in_array('nuevos', $caracteristicaSeleccion, true) ? 'checked' : '' ?>>
                            Nuevos (últimos 3 meses)
                        </label>

                        <label class="filter-dropdown__option" title="Fecha de ingreso hace más de 3 meses">
                            <input type="checkbox" name="caracteristica[]" value="antiguos" <?= in_array('antiguos', $caracteristicaSeleccion, true) ? 'checked' : '' ?>>
                            Antiguos
                        </label>

                        <label class="filter-dropdown__option" title="es_servidor = Sí, de cualquier ministerio">
                            <input type="checkbox" name="caracteristica[]" value="servidores_todos_ministerios" <?= in_array('servidores_todos_ministerios', $caracteristicaSeleccion, true) ? 'checked' : '' ?>>
                            Servidores (todos los ministerios)
                        </label>

                        <label class="filter-dropdown__option" title="Tiene una cuenta de usuario activa vinculada (administradora, líder, sublíder o usuario independiente)">
                            <input type="checkbox" name="caracteristica[]" value="servidores_ministerio_jovenes" <?= in_array('servidores_ministerio_jovenes', $caracteristicaSeleccion, true) ? 'checked' : '' ?>>
                            Servidores del Ministerio de Jóvenes
                        </label>

                    </div>

                </details>

            </div>

            <div class="filters-panel__actions">

                <button type="submit" class="btn btn-primary btn-sm">
                    Aplicar filtros
                </button>

                <?php if ($totalFiltrosActivos > 0): ?>
                    <a href="?" class="btn btn-back btn-sm">
                        Limpiar todo
                    </a>
                <?php endif; ?>

            </div>

        </form>

        <?php if ($totalFiltrosActivos > 0): ?>

        <div class="filters-panel__chips">

            <span class="filters-panel__chips-label">
                <?= $totalFiltrosActivos ?> filtro<?= $totalFiltrosActivos > 1 ? 's' : '' ?> activo<?= $totalFiltrosActivos > 1 ? 's' : '' ?>:
            </span>

            <?php if ($estado !== 'todos'): ?>
                <a
                    class="filter-chip filter-chip--active"
                    href="<?= jovenesUrlSinValor($estado, $riesgoSeleccion, $espirituSeleccion, $caracteristicaSeleccion, 'estado') ?>"
                >
                    <?= $etiquetasFiltro[$estado] ?? ucfirst($estado) ?> ✕
                </a>
            <?php endif; ?>

            <?php foreach ($riesgoSeleccion as $valor): ?>
                <a
                    class="filter-chip filter-chip--danger"
                    href="<?= jovenesUrlSinValor($estado, $riesgoSeleccion, $espirituSeleccion, $caracteristicaSeleccion, 'riesgo', $valor) ?>"
                >
                    <?= $etiquetasFiltro[$valor] ?? $valor ?> ✕
                </a>
            <?php endforeach; ?>

            <?php foreach ($espirituSeleccion as $valor): ?>
                <a
                    class="filter-chip filter-chip--active"
                    href="<?= jovenesUrlSinValor($estado, $riesgoSeleccion, $espirituSeleccion, $caracteristicaSeleccion, 'espiritu', $valor) ?>"
                >
                    <?= $etiquetasFiltro[$valor] ?? $valor ?> ✕
                </a>
            <?php endforeach; ?>

            <?php foreach ($caracteristicaSeleccion as $valor): ?>
                <a
                    class="filter-chip filter-chip--default"
                    href="<?= jovenesUrlSinValor($estado, $riesgoSeleccion, $espirituSeleccion, $caracteristicaSeleccion, 'caracteristica', $valor) ?>"
                >
                    <?= $etiquetasFiltro[$valor] ?? $valor ?> ✕
                </a>
            <?php endforeach; ?>

        </div>

        <?php endif; ?>

    </div>

    <!-- BUSCADOR -->

    <div class="search-bar">

        <input
            type="text"
            id="buscador"
            class="search-input"
            placeholder="Buscar joven..."
        >

    </div>

    <!-- TABLA -->

       <div class="page-section">

        <div class="table-wrapper">

        <table
            id="tablaJovenes"
            class="table"
        >

            <thead>

                <tr>

                    <th>Nombre</th>
                    <th>Edad</th>
                    <th>Estado</th>
                    <th>Actividad</th>
                    <th>Conexión</th>
                    <th>Tiempo</th>
                    <th>Seguimiento</th>
                    <th>Acciones</th>

                </tr>

            </thead>

            <tbody>

            <?php foreach($jovenes as $j): ?>

            <?php

            $edad = null;
            $edadAprox = false;

            if (!empty($j["fecha_nacimiento"])) {

                $edad = (
                    new DateTime($j["fecha_nacimiento"])
                )->diff(new DateTime())->y;

            } elseif (!empty($j["edad_manual"])) {

                $edad = (int)$j["edad_manual"];

                if (!empty($j["fecha_actualizacion_edad"])) {

                    $edad += (
                        new DateTime($j["fecha_actualizacion_edad"])
                    )->diff(new DateTime())->y;
                }

                $edadAprox = true;
            }

            $meses = 0;
            $dias = 0;

            if (!empty($j["fecha_ingreso"])) {

                $diff = (
                    new DateTime($j["fecha_ingreso"])
                )->diff(new DateTime());

                $meses = ($diff->y * 12) + $diff->m;

                $dias = $diff->days;
            }

            $años = floor($meses / 12);
            $restoMeses = $meses % 12;

            // Misma fuente que el filtro de arriba y que el Dashboard:
            // actividadService.php::etiquetaVisualActividadJuvenil().
            $conexion = etiquetaVisualActividadJuvenil($pdo, (int) $j["id"]);
            $conexionReal = $conexion["estado"];

            ?>

            <tr>

                <td>
                    <?= htmlspecialchars($j["nombre_completo"]) ?>
                </td>

                <td data-order="<?= $edad ?? 0 ?>">

                    <?= $edad ?? "—" ?>

                    <?= $edadAprox ? " (aprox)" : "" ?>

                </td>

                <td>

                    <?= ucfirst(strtolower(
                        htmlspecialchars($j["estado_espiritual"] ?? "-")
                    )) ?>

                </td>

                <td
                <?= $j["estado_actividad"] === "ACTIVO"
                    ? 'data-order="1"'
                    : 'data-order="2"' ?>
                >

                    <span
                        class="estado
                        <?= match($j["estado_actividad"]) {
                            "ACTIVO" => "estado--activo",
                            "INACTIVO" => "estado--inactivo",
                            default => "estado--eliminado"
                        } ?>">
                    </span>

                </td>

                <td
                    title="<?= htmlspecialchars($conexionReal) ?>"

                <?php

                if ($conexionReal === "Inactivo") {

                    echo 'data-order="3">';
                    echo '<span class="joven-riesgo3">Inactivo</span>';

                } elseif ($conexionReal === "Alerta") {

                    echo 'data-order="2">';
                    echo '<span class="joven-riesgo2">Alerta</span>';

                } else {

                    echo 'data-order="1">';
                    echo '<span class="joven-ok">Activo</span>';
                }

                ?>
                </td>

                <td data-order="<?= $dias ?>">

                    <?php if ($dias <= 7): ?>

                        <span class="joven-tiempo-main">
                            Muy nuevo
                        </span>

                    <?php elseif ($dias <= 30): ?>

                        <span class="joven-tiempo-main">
                            Nuevo
                        </span>

                    <?php else: ?>

                        <div class="joven-tiempo-main">

                            <?= $años > 0
                                ? $años . " año" . ($años > 1 ? "s" : "")
                                : "" ?>

                        </div>

                        <div class="joven-tiempo-sub">

                            <?= $restoMeses > 0
                                ? $restoMeses . " mes" . ($restoMeses > 1 ? "es" : "")
                                : "" ?>

                        </div>

                    <?php endif; ?>

                </td>

                <td

                <?php

                if ($dias <= 30) {

                    echo 'data-order="1">';
                    echo '<span class="joven-seg joven-seg--nuevo">Inicio</span>';

                } elseif ($meses <= 3) {

                    echo 'data-order="2">';
                    echo '<span class="joven-seg joven-seg--proceso">Consolidación</span>';

                } elseif ($meses <= 12) {

                    echo 'data-order="3">';
                    echo '<span class="joven-seg joven-seg--camino">Crecimiento</span>';

                } else {

                    echo 'data-order="4">';
                    echo '<span class="joven-seg joven-seg--fiel">Maduro</span>';
                }

                ?>
                </td>

                <td>

                 <div class="table-actions">

    <!-- VER -->

    <a
        href="<?= BASE_URL ?>/views/jovenes/ver.php?id=<?= (int)$j["id"] ?>"
        class="btn-icon btn-view"
        data-tooltip="Ver detalles"
    >

        <i class="fa-solid fa-eye"></i>

    </a>

    <!-- EDITAR -->

    <a
        href="<?= BASE_URL ?>/views/jovenes/editar.php?id=<?= (int)$j["id"] ?>"
        class="btn-icon btn-edit"
        data-tooltip="Editar"
    >

        <i class="fa-solid fa-pen"></i>

    </a>

    <?php if (tienePermiso('eliminar_jovenes')): ?>

        <?php if ($j["estado_actividad"] !== "ELIMINADO"): ?>

            <!-- ELIMINAR -->

            <form
                method="POST"
                class="inline-form"
                action="<?= BASE_URL ?>/controllers/jovenController.php"
            >

                <input
                    type="hidden"
                    name="action"
                    value="eliminar_joven"
                >

                <input
                    type="hidden"
                    name="id"
                    value="<?= (int)$j["id"] ?>"
                >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION["csrf_token"]) ?>"
                >

                <button
                    type="submit"
                    class="btn-icon btn-delete"
                    data-tooltip="Eliminar"
                    onclick="return confirm('¿Seguro que deseas eliminar este joven?')"
                >

                    <i class="fa-solid fa-trash"></i>

                </button>

            </form>

        <?php else: ?>

            <!-- RECUPERAR -->

            <form
                method="POST"
                class="inline-form"
                action="<?= BASE_URL ?>/controllers/jovenController.php"
            >

                <input
                    type="hidden"
                    name="action"
                    value="recuperar_joven"
                >

                <input
                    type="hidden"
                    name="id"
                    value="<?= (int)$j["id"] ?>"
                >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION["csrf_token"]) ?>"
                >

                <button
                    type="submit"
                    class="btn-icon btn-success"
                    data-tooltip="Recuperar"
                    onclick="return confirm('¿Recuperar este joven?')"
                >

                    <i class="fa-solid fa-rotate-left"></i>

                </button>

            </form>

            <!-- ELIMINAR DEFINITIVAMENTE -->

            <form
                method="POST"
                class="inline-form"
                action="<?= BASE_URL ?>/controllers/jovenController.php"
            >

                <input
                    type="hidden"
                    name="action"
                    value="eliminar_definitivo"
                >

                <input
                    type="hidden"
                    name="id"
                    value="<?= (int)$j["id"] ?>"
                >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION["csrf_token"]) ?>"
                >

                <button
                    type="submit"
                    class="btn-icon btn-delete"
                    data-tooltip="Eliminar definitivamente"
                    onclick="return confirm('Esta acción no se puede deshacer. ¿Continuar?')"
                >

                    <i class="fa-solid fa-trash-can"></i>

                </button>

            </form>

        <?php endif; ?>

    <?php endif; ?>

</div>

                </td>

            </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

<script>

document.addEventListener("DOMContentLoaded", () => {

    /* =====================================
       CONTADOR EN VIVO DE FILTROS (por grupo)
       -------------------------------------
       Solo actualiza el numerito del <summary>
       mientras se marca/desmarca, antes de
       "Aplicar filtros". No cambia la consulta;
       eso lo sigue haciendo el servidor al
       enviar el formulario.
    ===================================== */

    document
        .querySelectorAll(".filter-dropdown__body[data-group]")
        .forEach(grupo => {

            const detalle = grupo.closest(".filter-dropdown");
            const resumen = detalle?.querySelector("summary");

            if (!resumen) {
                return;
            }

            const actualizarBadge = () => {

                const marcados = grupo.querySelectorAll(
                    "input[type=checkbox]:checked"
                ).length;

                let badge = resumen.querySelector("[data-count-badge]");

                if (marcados === 0) {

                    badge?.remove();
                    return;
                }

                if (!badge) {

                    badge = document.createElement("span");
                    badge.className = "filter-dropdown__badge";
                    badge.setAttribute("data-count-badge", "");
                    resumen.appendChild(badge);
                }

                badge.textContent = String(marcados);
            };

            grupo
                .querySelectorAll("input[type=checkbox]")
                .forEach(input => {

                    input.addEventListener("change", actualizarBadge);

                });

        });

    /* =====================================
       DATATABLE
    ===================================== */

    const tabla = initDataTable("#tablaJovenes");

    if (!tabla) {
        return;
    }

    /* =====================================
       BUSCADOR
    ===================================== */

    const buscador = document.getElementById("buscador");

    if (buscador) {

        buscador.addEventListener("input", function () {

            tabla.search(this.value).draw();

        });

    }

    /* =====================================
       EXPORTACIONES
    ===================================== */

    const exportaciones = {

        exportPdf: "pdf",
        exportExcel: "excel",
        exportWord: "word",
        exportCsv: "csv",
        exportPrint: "print"

    };

    Object.entries(exportaciones).forEach(([id, tipo]) => {

        const boton = document.getElementById(id);

        if (!boton) {
            return;
        }

        boton.addEventListener("click", () => {

            const botones = tabla.buttons();

            if (!botones) {
                return;
            }

            switch (tipo) {

                case "pdf":
                    botones.container().find(".buttons-pdf").click();
                    break;

                case "excel":
                    botones.container().find(".buttons-excel").click();
                    break;

                case "word":
                    botones.container().find(".buttons-word").click();
                    break;

                case "csv":
                    botones.container().find(".buttons-csv").click();
                    break;

                case "print":
                    botones.container().find(".buttons-print").click();
                    break;

            }

        });

    });

});

</script>

<?php require_once __DIR__ . "/../../includes/footer.php"; ?>