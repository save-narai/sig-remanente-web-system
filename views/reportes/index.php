<?php
require_once __DIR__ . "/../../middleware/auth.php";
require_once __DIR__ . "/../../middleware/permiso.php";
require_once __DIR__ . "/../../config/conexion.php";
require_once __DIR__ . "/../../helpers/csrf.php";
require_once __DIR__ . "/../../services/reporteService.php";

if (!tienePermiso('ver_dashboard')) {
    header("Location: ../dashboard.php");
    exit;
}

generarCsrf();

/* =========================================================
   PARÁMETROS (con valores por defecto: mes en curso)
========================================================= */

$anioActual = (int) date('Y');
$mesActual = (int) date('n');

$tipo = strtolower((string) ($_GET['tipo'] ?? 'mensual'));

if (!in_array($tipo, REPORTE_TIPOS, true)) {
    $tipo = 'mensual';
}

$anio = (int) ($_GET['anio'] ?? $anioActual);

$mes = (int) ($_GET['mes'] ?? $mesActual);

if ($mes < 1 || $mes > 12) {
    $mes = $mesActual;
}

$mesParametro = $tipo === 'anual' ? null : $mes;

$reporte = null;
$errorReporte = null;

if (isset($_GET['ver'])) {

    try {

        $reporte = generarDatosReporte($pdo, $tipo, $anio, $mesParametro);

    } catch (InvalidArgumentException $e) {

        $errorReporte = $e->getMessage();

    } catch (Throwable $e) {

        error_log('Reporte: ' . get_class($e));

        $errorReporte = 'No fue posible generar el reporte. Revisa el registro de errores del servidor.';
    }
}

$cierres = listarCierresAnuales($pdo);

$aniosCerrados = array_map(static fn(array $c): int => (int) $c['anio'], $cierres);

$urlDescarga = static function (string $formato, array $extra) : string {

    return BASE_URL . '/controllers/reporteController.php?' . http_build_query(
        array_merge(['action' => 'descargar_reporte', 'formato' => $formato], $extra)
    );
};

require_once __DIR__ . "/../../includes/header.php";
?>

<div class="page reportes-page">

    <?php if(isset($_SESSION["success"])): ?>
    <script>
    document.addEventListener("DOMContentLoaded", () => {
        showToast(<?= json_encode($_SESSION["success"]) ?>, "success");
    });
    </script>
    <?php unset($_SESSION["success"]); endif; ?>

    <?php if(isset($_SESSION["error"])): ?>
    <script>
    document.addEventListener("DOMContentLoaded", () => {
        showToast(<?= json_encode($_SESSION["error"]) ?>, "error");
    });
    </script>
    <?php unset($_SESSION["error"]); endif; ?>

    <div class="page-header">

        <h1 class="page-title">Reportes</h1>

        <a href="../dashboard.php" class="btn btn-back">Volver al dashboard</a>

    </div>

    <!-- =====================================================
         GENERAR REPORTE
    ====================================================== -->

    <div class="form-card reportes-page__card">

        <h2 class="form-title">Generar reporte</h2>

        <p class="reportes-page__explicacion">
            Elige el tipo de reporte y el período. Puedes verlo en pantalla o
            descargarlo en Excel o PDF.
        </p>

        <form method="GET" class="form reportes-page__form">

            <input type="hidden" name="ver" value="1">

            <!-- Los botones de descarga envían este mismo formulario al
                 controlador (formaction), así siempre usan lo que está
                 seleccionado ahora mismo en los campos, no lo anterior. -->
            <input type="hidden" name="action" value="descargar_reporte">

            <div class="form-group">

                <label class="form-label" for="tipo">Tipo</label>

                <select class="form-select" name="tipo" id="tipo">
                    <option value="mensual" <?= $tipo === 'mensual' ? 'selected' : '' ?>>Mensual (un mes)</option>
                    <option value="trimestral" <?= $tipo === 'trimestral' ? 'selected' : '' ?>>Trimestral (3 meses que terminan en el mes elegido)</option>
                    <option value="anual" <?= $tipo === 'anual' ? 'selected' : '' ?>>Anual (todo el año)</option>
                </select>

            </div>

            <div class="form-group" id="grupo-mes">

                <label class="form-label" for="mes">Mes</label>

                <select class="form-select" name="mes" id="mes">
                    <?php foreach (REPORTE_MESES as $numero => $nombre): ?>
                        <option value="<?= $numero ?>" <?= $mes === $numero ? 'selected' : '' ?>>
                            <?= htmlspecialchars($nombre) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

            </div>

            <div class="form-group">

                <label class="form-label" for="anio">Año</label>

                <select class="form-select" name="anio" id="anio">
                    <?php for ($a = $anioActual; $a >= $anioActual - 6; $a--): ?>
                        <option value="<?= $a ?>" <?= $anio === $a ? 'selected' : '' ?>><?= $a ?></option>
                    <?php endfor; ?>
                </select>

            </div>

            <div class="form-actions reportes-page__acciones">

                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-chart-column"></i>
                    Ver reporte
                </button>

                <button
                    type="submit"
                    class="btn btn-secondary"
                    formaction="<?= BASE_URL ?>/controllers/reporteController.php"
                    name="formato"
                    value="xlsx"
                >
                    <i class="fa-solid fa-file-excel"></i>
                    Descargar Excel
                </button>

                <button
                    type="submit"
                    class="btn btn-secondary"
                    formaction="<?= BASE_URL ?>/controllers/reporteController.php"
                    name="formato"
                    value="pdf"
                >
                    <i class="fa-solid fa-file-pdf"></i>
                    Descargar PDF
                </button>

            </div>

        </form>

    </div>

    <?php if ($errorReporte !== null): ?>

        <div class="form-info"><i class="fa-solid fa-triangle-exclamation"></i>
            <div><?= htmlspecialchars($errorReporte) ?></div>
        </div>

    <?php endif; ?>

    <!-- =====================================================
         VISTA DEL REPORTE
    ====================================================== -->

    <?php if ($reporte !== null): ?>

    <div class="form-card reportes-page__card">

        <h2 class="form-title"><?= htmlspecialchars(reporteTitulo($reporte)) ?></h2>

        <p class="reportes-page__explicacion">
            Período: <?= htmlspecialchars($reporte['meta']['inicio']) ?> a
            <?= htmlspecialchars($reporte['meta']['fin']) ?>
        </p>

        <?php foreach (reporteSecciones($reporte) as $seccion): ?>

            <h3 class="reportes-page__seccion"><?= htmlspecialchars($seccion['titulo']) ?></h3>

            <?php if (!empty($seccion['filas'])): ?>

            <div class="table-responsive">
                <table class="table gx-table reportes-page__tabla">
                    <thead>
                        <tr>
                            <?php foreach ($seccion['columnas'] as $col): ?>
                                <th><?= htmlspecialchars($col) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($seccion['filas'] as $fila): ?>
                            <tr>
                                <?php foreach ($fila as $celda): ?>
                                    <td><?= htmlspecialchars((string) $celda) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php endif; ?>

            <?php if (!empty($seccion['nota'])): ?>
                <p class="form-hint reportes-page__nota"><?= htmlspecialchars($seccion['nota']) ?></p>
            <?php endif; ?>

        <?php endforeach; ?>

    </div>

    <?php endif; ?>

    <!-- =====================================================
         CIERRES ANUALES
    ====================================================== -->

    <div class="form-card reportes-page__card">

        <h2 class="form-title">Cierres anuales</h2>

        <p class="reportes-page__explicacion">
            Un cierre guarda una <strong>fotografía</strong> de los indicadores del
            año, para poder compararlo con otros años. <strong>No borra ni modifica</strong>
            jóvenes, reuniones ni asistencias -- el conteo de cada año empieza de cero
            por sí solo, porque los reportes cuentan por fechas. Un cierre no se puede
            sobrescribir.
        </p>

        <?php if (!cierresAnualesDisponible($pdo)): ?>

            <p class="form-hint">
                Los cierres anuales no están disponibles todavía: falta ejecutar la migración
                <code>database/migrations/20260927_cierres_anuales.sql</code>.
                Los reportes mensual, trimestral y anual funcionan sin ella.
            </p>

        <?php else: ?>

            <?php if (empty($cierres)): ?>

                <p class="form-hint">Todavía no hay años cerrados.</p>

            <?php else: ?>

            <div class="table-responsive">
                <table class="table gx-table reportes-page__tabla">
                    <thead>
                        <tr>
                            <th>Año</th>
                            <th>Cerrado por</th>
                            <th>Fecha del cierre</th>
                            <th>Descargar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cierres as $cierre): ?>
                            <tr>
                                <td><?= (int) $cierre['anio'] ?></td>
                                <td><?= htmlspecialchars((string) ($cierre['cerrado_por_nombre'] ?? '--')) ?></td>
                                <td><?= htmlspecialchars((string) $cierre['cerrado_en']) ?></td>
                                <td>
                                    <a href="<?= htmlspecialchars($urlDescarga('xlsx', ['cierre_id' => (int) $cierre['id']])) ?>" class="btn btn-secondary btn-sm">Excel</a>
                                    <a href="<?= htmlspecialchars($urlDescarga('pdf', ['cierre_id' => (int) $cierre['id']])) ?>" class="btn btn-secondary btn-sm">PDF</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php endif; ?>

            <?php if (esAdmin()): ?>

                <?php
                $aniosPorCerrar = [];

                for ($a = $anioActual; $a >= $anioActual - 6; $a--) {
                    if (!in_array($a, $aniosCerrados, true)) {
                        $aniosPorCerrar[] = $a;
                    }
                }
                ?>

                <?php if (!empty($aniosPorCerrar)): ?>

                <form
                    action="<?= BASE_URL ?>/controllers/reporteController.php"
                    method="POST"
                    class="form reportes-page__form reportes-page__cierre"
                    onsubmit="return confirm('¿Cerrar el año seleccionado? Se guardará una fotografía de sus indicadores y no se podrá sobrescribir. No se borra ningún dato.');"
                >

                    <?= csrfField(); ?>

                    <input type="hidden" name="action" value="cerrar_anio">

                    <div class="form-group">

                        <label class="form-label" for="anio_cierre">Año a cerrar</label>

                        <select class="form-select" name="anio" id="anio_cierre">
                            <?php foreach ($aniosPorCerrar as $a): ?>
                                <option value="<?= $a ?>"><?= $a ?></option>
                            <?php endforeach; ?>
                        </select>

                    </div>

                    <div class="form-actions reportes-page__acciones">

                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-lock"></i>
                            Cerrar año
                        </button>

                    </div>

                </form>

                <?php endif; ?>

            <?php else: ?>

                <p class="form-hint">Solo la administración puede cerrar un año.</p>

            <?php endif; ?>

        <?php endif; ?>

    </div>

</div>

<script>
// El mes no aplica al reporte anual.
(function () {
    const tipo = document.getElementById('tipo');
    const grupoMes = document.getElementById('grupo-mes');

    function actualizar() {
        grupoMes.style.display = tipo.value === 'anual' ? 'none' : '';
    }

    tipo.addEventListener('change', actualizar);
    actualizar();
})();
</script>

<?php require_once __DIR__ . "/../../includes/footer.php"; ?>
