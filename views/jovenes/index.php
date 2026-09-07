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

/* =========================
   FILTROS
========================= */

$permitidos = [
    "todos",
    "activos",
    "inactivos",
    "eliminados",
    "riesgo2",
    "riesgo3",
    "nuevos",
    "congregantes",
    "discipulado",
    "servidores_lideres"
];

$filtro = $_GET["filtro"] ?? "todos";

if (!in_array($filtro, $permitidos)) {
    $filtro = "todos";
}

/* =========================
   QUERY
========================= */

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

$where[] = "j.estado_actividad != 'ELIMINADO'";

if ($filtro === "activos") {
    $where[] = "j.estado_actividad = 'ACTIVO'";
}

if ($filtro === "inactivos") {
    $where[] = "j.estado_actividad = 'INACTIVO'";
}

if ($filtro === "eliminados") {
    $where = ["j.estado_actividad = 'ELIMINADO'"];
}

// "Nuevos": mismo criterio exacto que dashboardService.php::obtenerNuevosAntiguos()
// (fecha_ingreso <= 3 meses). No existía ninguna función/consulta reutilizable
// para el LISTADO (solo el conteo agregado del dashboard), así que se repite
// aquí la misma condición TIMESTAMPDIFF, sin inventar un criterio distinto.
if ($filtro === "nuevos") {
    $where[] = "TIMESTAMPDIFF(MONTH, j.fecha_ingreso, CURDATE()) <= 3";
}

// Congregantes / Discipulado / Servidores-Líderes: estado_espiritual tal cual,
// las mismas categorías de jovenService.php (ESTADOS_ESPIRITUALES). No se
// mezcla con es_servidor.
if ($filtro === "congregantes") {
    $where[] = "j.estado_espiritual = 'CONGREGANTE'";
}

if ($filtro === "discipulado") {
    $where[] = "j.estado_espiritual = 'DISCIPULADO'";
}

if ($filtro === "servidores_lideres") {
    $where[] = "j.estado_espiritual IN ('SERVIDOR', 'LIDER')";
}

if (!empty($where)) {
    $query .= " WHERE " . implode(" AND ", $where);
}

$query .= " ORDER BY j.nombre_completo ASC";

$stmt = $pdo->prepare($query);

$stmt->execute();

$jovenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Riesgo / Alto riesgo: ya NO se calculan con una fórmula propia de esta
// vista. Se reutiliza actividadService.php::estadoConexionJoven() (la misma
// fuente central que ya usa el Dashboard), filtrando en PHP después de traer
// la lista, para que el número de este listado y el del KPI del Dashboard
// salgan siempre del mismo criterio.
if ($filtro === "riesgo2" || $filtro === "riesgo3") {

    $estadoBuscado = $filtro === "riesgo2" ? "Riesgo" : "Alto Riesgo";

    $jovenes = array_values(array_filter(
        $jovenes,
        function (array $j) use ($pdo, $estadoBuscado): bool {
            $conexion = estadoConexionJoven($pdo, (int) $j["id"]);
            return $conexion["estado"] === $estadoBuscado;
        }
    ));
}



require_once __DIR__ . "/../../includes/header.php";
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

    <div class="jovenes__filtros">

        <a
            href="?filtro=todos"
            class="jovenes__tag jovenes__tag--todos <?= $filtro === 'todos' ? 'jovenes__tag--active' : '' ?>"
        >
            Todos
        </a>

        <a
            href="?filtro=activos"
            class="jovenes__tag jovenes__tag--activos <?= $filtro === 'activos' ? 'jovenes__tag--active' : '' ?>"
        >
            Activos
        </a>

        <a
            href="?filtro=inactivos"
            class="jovenes__tag jovenes__tag--inactivos <?= $filtro === 'inactivos' ? 'jovenes__tag--active' : '' ?>"
        >
            Inactivos
        </a>

        <a
            href="?filtro=eliminados"
            class="jovenes__tag jovenes__tag--inactivos <?= $filtro === 'eliminados' ? 'jovenes__tag--active' : '' ?>"
        >
            Eliminados
        </a>

        <a
            href="?filtro=riesgo2"
            class="jovenes__tag jovenes__tag--riesgo <?= $filtro === 'riesgo2' ? 'jovenes__tag--active' : '' ?>"
        >
            Riesgo
        </a>

        <a
            href="?filtro=riesgo3"
            class="jovenes__tag jovenes__tag--alto <?= $filtro === 'riesgo3' ? 'jovenes__tag--active' : '' ?>"
        >
            Alto riesgo
        </a>

        <a
            href="?filtro=nuevos"
            class="jovenes__tag jovenes__tag--activos <?= $filtro === 'nuevos' ? 'jovenes__tag--active' : '' ?>"
            title="Fecha de ingreso hace 3 meses o menos"
        >
            Nuevos (≤ 3 meses)
        </a>

        <a
            href="?filtro=congregantes"
            class="jovenes__tag jovenes__tag--activos <?= $filtro === 'congregantes' ? 'jovenes__tag--active' : '' ?>"
        >
            Congregantes
        </a>

        <a
            href="?filtro=discipulado"
            class="jovenes__tag jovenes__tag--activos <?= $filtro === 'discipulado' ? 'jovenes__tag--active' : '' ?>"
        >
            Discipulado
        </a>

        <a
            href="?filtro=servidores_lideres"
            class="jovenes__tag jovenes__tag--activos <?= $filtro === 'servidores_lideres' ? 'jovenes__tag--active' : '' ?>"
            title="estado_espiritual = SERVIDOR o LIDER (provisional, no distingue ministerio todavía)"
        >
            Servidores/Líderes
        </a>

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