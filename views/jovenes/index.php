<?php
require_once __DIR__ . "/../../middleware/auth.php";
require_once __DIR__ . "/../../middleware/permiso.php";
require_once __DIR__ . "/../../services/actividadService.php";
require_once __DIR__ . "/../../config/conexion.php";
require_once __DIR__ . "/../../helpers/csrf.php";


if (!tienePermiso('gestionar_jovenes')) {
    header("Location: ../dashboard.php");
    exit;
}

generarCsrf();

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
        'riesgo2'                      => ['riesgo' => ['riesgo2']],
        'riesgo3'                      => ['riesgo' => ['riesgo3']],
        'nuevos'                       => ['caracteristica' => ['nuevos']],
        'antiguos'                     => ['caracteristica' => ['antiguos']],
        'congregantes'                 => ['espiritu' => ['congregantes']],
        'discipulado'                  => ['espiritu' => ['discipulado']],
        'servidores_lideres'           => ['espiritu' => ['servidores_lideres']],
        'servidores_todos_ministerios' => ['caracteristica' => ['servidores_todos_ministerios']]
    ];

    $legacy = (string) $_GET['filtro'];

    if (isset($mapaFiltroAntiguo[$legacy])) {
        $_GET = array_merge($_GET, $mapaFiltroAntiguo[$legacy]);
    }
}

$estadosPermitidos = ['todos', 'activos', 'inactivos', 'eliminados'];
$riesgoPermitidos = ['riesgo2', 'riesgo3'];
$espirituPermitidos = ['congregantes', 'discipulado', 'servidores_lideres'];
$caracteristicaPermitidos = ['nuevos', 'antiguos', 'servidores_todos_ministerios'];

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

$query = "
SELECT
    j.id,
    j.nombre_completo,
    j.fecha_nacimiento,
    j.edad_manual,
    j.fecha_actualizacion_edad,
    j.estado_espiritual,
    j.estado_actividad,
    j.fecha_ingreso

FROM jovenes j
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

// Estado espiritual: mismas categorías de jovenService.php
// (ESTADOS_ESPIRITUALES). Varios valores se combinan con OR
// entre sí (ej. Congregantes O Discipulado), y ese grupo se
// combina con AND respecto a los demás grupos.
if (!empty($espirituSeleccion)) {

    $condiciones = [];

    if (in_array('congregantes', $espirituSeleccion, true)) {
        $condiciones[] = "j.estado_espiritual = 'CONGREGANTE'";
    }

    if (in_array('discipulado', $espirituSeleccion, true)) {
        $condiciones[] = "j.estado_espiritual = 'DISCIPULADO'";
    }

    if (in_array('servidores_lideres', $espirituSeleccion, true)) {
        $condiciones[] = "j.estado_espiritual IN ('SERVIDOR', 'LIDER')";
    }

    $where[] = '(' . implode(' OR ', $condiciones) . ')';
}

// Características: "nuevos"/"antiguos" mismo criterio exacto de
// dashboardService.php::obtenerNuevosAntiguos(); "servidores de
// cualquier ministerio" = es_servidor, sin relación con
// estado_espiritual. Varios valores del grupo se combinan con OR.
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

    $where[] = '(' . implode(' OR ', $condiciones) . ')';
}

$query .= " WHERE " . implode(" AND ", $where);

$query .= " ORDER BY j.nombre_completo ASC";

$stmt = $pdo->prepare($query);

$stmt->execute();

$jovenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Riesgo / Alto riesgo: ya NO se calculan con una fórmula propia de esta
// vista. Se reutiliza actividadService.php::estadoConexionJoven() (la misma
// fuente central que ya usa el Dashboard), filtrando en PHP después de traer
// la lista, para que el número de este listado y el del KPI del Dashboard
// salgan siempre del mismo criterio. Varios valores del grupo (Riesgo,
// Alto riesgo) se combinan con OR, igual que los demás grupos.
if (!empty($riesgoSeleccion)) {

    $estadosBuscados = [];

    if (in_array('riesgo2', $riesgoSeleccion, true)) {
        $estadosBuscados[] = 'Riesgo';
    }

    if (in_array('riesgo3', $riesgoSeleccion, true)) {
        $estadosBuscados[] = 'Alto Riesgo';
    }

    $jovenes = array_values(array_filter(
        $jovenes,
        function (array $j) use ($pdo, $estadosBuscados): bool {
            $conexion = estadoConexionJoven($pdo, (int) $j["id"]);
            return in_array($conexion["estado"], $estadosBuscados, true);
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
    'riesgo2' => 'Riesgo',
    'riesgo3' => 'Alto riesgo',
    'congregantes' => 'Congregantes',
    'discipulado' => 'Discipulado',
    'servidores_lideres' => 'Servidores/Líderes',
    'nuevos' => 'Nuevos (≤3 meses)',
    'antiguos' => 'Antiguos',
    'servidores_todos_ministerios' => 'Servidores (todos los ministerios)'
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
                            <input type="checkbox" name="riesgo[]" value="riesgo2" <?= in_array('riesgo2', $riesgoSeleccion, true) ? 'checked' : '' ?>>
                            Riesgo
                        </label>

                        <label class="filter-dropdown__option">
                            <input type="checkbox" name="riesgo[]" value="riesgo3" <?= in_array('riesgo3', $riesgoSeleccion, true) ? 'checked' : '' ?>>
                            Alto riesgo
                        </label>

                    </div>

                </details>

                <details class="filter-dropdown" <?= !empty($espirituSeleccion) ? 'open' : '' ?>>

                    <summary>
                        Estado espiritual
                        <?php if (!empty($espirituSeleccion)): ?>
                            <span class="filter-dropdown__badge" data-count-badge><?= count($espirituSeleccion) ?></span>
                        <?php endif; ?>
                    </summary>

                    <div class="filter-dropdown__body" data-group="espiritu">

                        <label class="filter-dropdown__option">
                            <input type="checkbox" name="espiritu[]" value="congregantes" <?= in_array('congregantes', $espirituSeleccion, true) ? 'checked' : '' ?>>
                            Congregantes
                        </label>

                        <label class="filter-dropdown__option">
                            <input type="checkbox" name="espiritu[]" value="discipulado" <?= in_array('discipulado', $espirituSeleccion, true) ? 'checked' : '' ?>>
                            Discipulado
                        </label>

                        <label class="filter-dropdown__option" title="estado_espiritual = SERVIDOR o LIDER (provisional, no distingue ministerio todavía)">
                            <input type="checkbox" name="espiritu[]" value="servidores_lideres" <?= in_array('servidores_lideres', $espirituSeleccion, true) ? 'checked' : '' ?>>
                            Servidores/Líderes
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
            // actividadService.php::estadoConexionJoven().
            $conexion = estadoConexionJoven($pdo, (int) $j["id"]);
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

                if ($conexionReal === "Alto Riesgo") {

                    echo 'data-order="4">';
                    echo '<span class="joven-riesgo3">Alto riesgo</span>';

                } elseif ($conexionReal === "Riesgo") {

                    echo 'data-order="3">';
                    echo '<span class="joven-riesgo2">Riesgo</span>';

                } elseif ($conexionReal === "Observación") {

                    echo 'data-order="2">';
                    echo '<span class="joven-observacion">Observación</span>';

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