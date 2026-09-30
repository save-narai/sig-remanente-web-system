<?php

require_once __DIR__ . "/../../middleware/auth.php";
require_once __DIR__ . "/../../middleware/permiso.php";
require_once __DIR__ . "/../../services/actividadService.php";
require_once __DIR__ . "/../../config/conexion.php";

require_once __DIR__ . "/../../services/seguimientoService.php";
require_once __DIR__ . "/../../services/excepcionSeguimientoService.php";
require_once __DIR__ . "/../../helpers/format.php";
require_once __DIR__ . "/../../helpers/fechas.php";


/* =========================
   PERMISOS
========================= */

if (!tienePermiso('gestionar_seguimientos')) {

    header("Location: ../dashboard.php");
    exit;
}


/* =========================
   ACTUALIZAR ACTIVIDAD
========================= */

actualizarEstadoActividad($pdo);


/* =========================
   RESUMEN
========================= */

$data =
    obtenerResumenSeguimientosMes(
        $pdo
    );

$seguimientosMes =
    $data['seguimientosMes']
    ?? [];

$historialMes =
    $data['historialMes']
    ?? [];

$totalActivos =
    $data['totalActivos']
    ?? 0;

$totalConSeguimiento =
    $data['totalConSeguimiento']
    ?? 0;

$totalSinSeguimiento =
    $data['totalSinSeguimiento']
    ?? 0;

$totalExcepciones =
    $data['totalExcepciones']
    ?? 0;

$porcentaje =
    $data['porcentaje']
    ?? 0;

$color =
    $data['color']
    ?? '';

$mesTexto =
    $data['mesTexto']
    ?? '';



/* =========================
   MESES EN ESPAÑOL
========================= */

$mesesEspanol = [

    1  => 'Enero',
    2  => 'Febrero',
    3  => 'Marzo',
    4  => 'Abril',
    5  => 'Mayo',
    6  => 'Junio',
    7  => 'Julio',
    8  => 'Agosto',
    9  => 'Septiembre',
    10 => 'Octubre',
    11 => 'Noviembre',
    12 => 'Diciembre'

];


/* =========================
   ALERTAS
========================= */

/*
 * IMPORTANTE:
 *
 * Ya no hacemos aquí una consulta
 * independiente para decidir quién
 * está sin seguimiento.
 *
 * Toda la regla vive en:
 *
 *   seguimientoService.php
 *
 * Allí se considera:
 *
 * - joven activo
 * - joven nuevo
 * - SIN seguimiento FINALIZADO
 *   en cualquier fecha
 * - SIN excepción del período actual
 *
 * Por eso un joven contactado en febrero
 * no vuelve a aparecer como pendiente
 * en agosto.
 */

$alertas =
    obtenerJovenesSinSeguimiento();



/* =========================
   HEADER
========================= */

require_once __DIR__ . "/../../includes/header.php";

?>

<div class="seguimientos-page">

    <!-- =========================
         HEADER
    ========================== -->

    <div class="page-header">

        <div class="page-header-left">

            <h1 class="page-title">
                Seguimientos
            </h1>

            <p class="page-subtitle">
                Consolidado mensual del acompañamiento ministerial.
            </p>

            <span class="badge badge-info">
                <?= e($mesTexto) ?>
            </span>

        </div>


        <div class="page-header-right">

            <?php if (tienePermiso('asignar_seguimientos')): ?>

                <a
                    href="<?= BASE_URL ?>/views/seguimientos/asignaciones.php"
                    class="btn btn-primary seguimiento-btn-asignaciones"
                >

                    <i class="fa-solid fa-user-plus"></i>

                    Asignaciones

                </a>

            <?php endif; ?>


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
                        id="exportWord"
                    >

                        <i class="fa-solid fa-file-word"></i>

                        Word

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


    <!-- =========================
         ESTADÍSTICAS
    ========================== -->

    <?php
    // Mismo criterio (mismo mes/año) que obtenerResumenSeguimientosMes():
    // hipervínculos que abren el listado real de jóvenes de cada
    // categoría, garantizando que el número y el listado sean
    // siempre exactamente el mismo conjunto de datos.
    $anioActualSeg = (int) date('Y');
    $mesActualSeg = (int) date('m');

    $verCategoria = $_GET['ver'] ?? '';

    $categoriasValidas = ['activos', 'con_seguimiento', 'sin_seguimiento', 'excepciones'];

    if (!in_array($verCategoria, $categoriasValidas, true)) {
        $verCategoria = '';
    }
    ?>

    <section class="gx-stats">

        <a
            href="?ver=activos"
            class="stat-card info <?= $verCategoria === 'activos' ? 'stat-card--activa' : '' ?>"
        >

            <span class="stat-number">
                <?= $totalActivos ?>
            </span>

            <span class="stat-label">
                Jóvenes activos
            </span>

        </a>


        <a
            href="?ver=con_seguimiento"
            class="stat-card success <?= $verCategoria === 'con_seguimiento' ? 'stat-card--activa' : '' ?>"
        >

            <span class="stat-number">
                <?= $totalConSeguimiento ?>
            </span>

            <span class="stat-label">
                Con seguimiento
            </span>

        </a>


        <a
            href="?ver=sin_seguimiento"
            class="stat-card danger <?= $verCategoria === 'sin_seguimiento' ? 'stat-card--activa' : '' ?>"
        >

            <span class="stat-number">
                <?= $totalSinSeguimiento ?>
            </span>

            <span class="stat-label">
                Sin seguimiento
            </span>

        </a>


        <div class="stat-card <?= e($color) ?>">

            <span class="stat-number">

                <?= $porcentaje ?>%

            </span>

            <span class="stat-label">

                Cumplimiento

            </span>

        </div>

    </section>


    <!-- =========================
         DETALLE DE LA CATEGORÍA SELECCIONADA
         (misma consulta que alimenta el número de arriba)
    ========================== -->

    <?php if ($verCategoria !== ''): ?>

    <?php
    $etiquetasCategoria = [
        'activos' => 'Jóvenes activos (nuevos)',
        'con_seguimiento' => 'Con seguimiento',
        'sin_seguimiento' => 'Sin seguimiento',
        'excepciones' => 'Con excepción este mes',
    ];

    $listaCategoria = obtenerListaSeguimientosMesPorCategoria(
        $pdo,
        $verCategoria,
        $anioActualSeg,
        $mesActualSeg
    );
    ?>

    <section class="page-section" id="detalle-categoria">

        <div class="section-header">

            <div>
                <h2 class="section-title">
                    <?= htmlspecialchars($etiquetasCategoria[$verCategoria] ?? 'Detalle') ?>
                    (<?= count($listaCategoria) ?>)
                </h2>
                <p class="section-subtitle">
                    <?= htmlspecialchars($mesTexto) ?>
                </p>
            </div>

            <a href="?" class="btn btn-back btn-sm">
                Cerrar
            </a>

        </div>

        <?php if (empty($listaCategoria)): ?>

            <p class="text-center">No hay jóvenes en esta categoría.</p>

        <?php else: ?>

            <div class="table-responsive">
                <table class="table gx-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <?php if ($verCategoria === 'excepciones'): ?>
                                <th>Motivo</th>
                            <?php endif; ?>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($listaCategoria as $joven): ?>
                            <tr>
                                <td><?= htmlspecialchars($joven['nombre_completo']) ?></td>
                                <?php if ($verCategoria === 'excepciones'): ?>
                                    <td><?= htmlspecialchars($joven['motivo'] ?? '—') ?></td>
                                <?php endif; ?>
                                <td>
                                    <a
                                        href="<?= BASE_URL ?>/views/jovenes/ver.php?id=<?= (int) $joven['id'] ?>"
                                        class="btn btn-primary btn-sm"
                                    >
                                        Ver perfil
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    </section>

    <?php endif; ?>


    <!-- =========================
         ALERTAS
    ========================== -->

    <section class="page-section">

        <div class="section-header">

            <div>

                <h2 class="section-title">
                    Jóvenes sin seguimiento
                </h2>

                <p class="section-subtitle">
                    Jóvenes que aún no han completado su seguimiento inicial.
                </p>

            </div>

        </div>


        <?php if (!empty($alertas)): ?>

            <div class="seguimientos-alertas">

                <?php foreach ($alertas as $j): ?>

                    <div class="seguimiento-alerta-card">

                        <div class="seguimiento-alerta-left">

                            <div>

                                <h4>

                                    <?= e(
                                        $j["nombre_completo"]
                                    ) ?>

                                </h4>

                                <span>

                                    <?= e(
                                        $j["telefono"]
                                        ?: "Sin teléfono"
                                    ) ?>

                                </span>

                            </div>

                        </div>


                        <div class="seguimiento-alerta-right">

                            <span class="badge badge-danger">
                                Pendiente
                            </span>


                            <a
                                href="../jovenes/ver.php?id=<?= (int)$j["id"] ?>"
                                class="btn btn-sm btn-perfil <?= ($j["genero"] ?? "") === "F"
                                    ? "btn-perfil-chica"
                                    : "btn-perfil-chico" ?>"
                            >

                                <i class="fa-solid fa-user"></i>

                                Ver perfil

                            </a>


                            <button
                                type="button"
                                class="btn btn-sm btn-checklist"
                                data-joven-id="<?= (int)$j["id"] ?>"
                                data-joven-nombre="<?= e(
                                    $j["nombre_completo"]
                                ) ?>"
                            >

                                <i class="fa-solid fa-square-check"></i>

                                Checklist

                            </button>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="gx-empty">

                <i class="fa-solid fa-circle-check"></i>

                <p>

                    Todos los jóvenes han completado su seguimiento inicial
                    o tienen una excepción registrada.

                </p>

            </div>

        <?php endif; ?>

    </section>


    <br>
    <br>


    <!-- =====================================
         TABLA
    ====================================== -->

    <section class="page-section">

        <div class="section-header">

            <div>

                <h2 class="section-title">
                    Historial de seguimientos
                </h2>

                <p class="section-subtitle">
                    Registros correspondientes a <?= e($mesTexto) ?>.
                </p>

            </div>

        </div>


        <!-- =====================================
             BUSCADOR
        ====================================== -->

        <div class="gx-toolbar">

            <div class="search-wrapper">

                <input
                    id="buscador"
                    type="text"
                    class="search-input"
                    placeholder="Buscar seguimiento..."
                >

            </div>

        </div>


        <!-- =====================================
             TABLA
        ====================================== -->

        <div class="table-responsive">

            <table
                id="tablaSeguimientos"
                class="table gx-table"
            >

                <thead>

                    <tr>

                        <th>
                            Nombre
                        </th>

                        <th>
                            Modalidad
                        </th>

                        <th>
                            Estado
                        </th>

                        <th>
                            Responsable
                        </th>

                        <th>
                            Fecha
                        </th>

                        <th>
                            Acciones
                        </th>

                    </tr>

                </thead>


                <tbody>

                    <?php if (!empty($historialMes)): ?>

                        <?php foreach ($historialMes as $registro): ?>

                            <?php

                            /*
                            |--------------------------------------------------------------------------
                            | TIPO DE REGISTRO
                            |--------------------------------------------------------------------------
                            */

                            $esExcepcion =
                                (
                                    $registro['tipo_registro']
                                    ?? ''
                                ) === 'EXCEPCION';


                            /*
                            |--------------------------------------------------------------------------
                            | MODALIDAD
                            |--------------------------------------------------------------------------
                            */

                            $modalidad =
                                strtoupper(
                                    trim(
                                        $registro['modalidad_contacto']
                                        ?? ''
                                    )
                                );


                            $modalidadClases = [

                                'WHATSAPP' =>
                                    'modalidad-whatsapp',

                                'LLAMADA' =>
                                    'modalidad-llamada',

                                'VISITA' =>
                                    'modalidad-visita',

                                'MENSAJE' =>
                                    'modalidad-mensaje'

                            ];


                            $modalidadTexto = [

                                'WHATSAPP' =>
                                    'WhatsApp',

                                'LLAMADA' =>
                                    'Llamada',

                                'VISITA' =>
                                    'Visita',

                                'MENSAJE' =>
                                    'Mensaje'

                            ];


                            $modalidadClase =
                                $modalidadClases[$modalidad]
                                ?? 'modalidad-default';


                            $modalidadNombre =
                                $modalidadTexto[$modalidad]
                                ?? (
                                    $modalidad !== ''
                                        ? ucfirst(
                                            strtolower(
                                                $modalidad
                                            )
                                        )
                                        : 'Sin modalidad'
                                );


                            /*
                            |--------------------------------------------------------------------------
                            | ESTADO
                            |--------------------------------------------------------------------------
                            */

                            $estadoProceso =
                                strtoupper(
                                    trim(
                                        $registro['estado_proceso']
                                        ?? ''
                                    )
                                );


                            $estadoClase =
                                strtolower(
                                    str_replace(
                                        '_',
                                        '-',
                                        $estadoProceso
                                    )
                                );


                            $estadoNombre =
                                $estadoProceso !== ''
                                    ? ucfirst(
                                        strtolower(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $estadoProceso
                                            )
                                        )
                                    )
                                    : 'Sin estado';


                            /*
                            |--------------------------------------------------------------------------
                            | PERFIL
                            |--------------------------------------------------------------------------
                            */

                            $clasePerfil =
                                ($registro['genero'] ?? '') === 'F'
                                    ? 'btn-perfil-chica'
                                    : 'btn-perfil-chico';

                            ?>

                            <tr>

                                <!-- =================================
                                     NOMBRE
                                ================================== -->

                                <td>

                                    <span class="seguimiento-nombre">

                                        <?= e(
                                            $registro['nombre_completo']
                                        ) ?>

                                    </span>

                                </td>


                                <!-- =================================
                                     MODALIDAD
                                ================================== -->

                                <td>

                                    <?php if ($esExcepcion): ?>

                                        <span
                                            class="badge badge-warning badge-modalidad"
                                        >

                                            <i
                                                class="fa-solid fa-triangle-exclamation"
                                            ></i>

                                            Excepción

                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="badge badge-modalidad <?= e(
                                                $modalidadClase
                                            ) ?>"
                                        >

                                            <?php if ($modalidad === 'WHATSAPP'): ?>

                                                <i
                                                    class="fa-brands fa-whatsapp"
                                                ></i>

                                            <?php elseif ($modalidad === 'LLAMADA'): ?>

                                                <i
                                                    class="fa-solid fa-phone"
                                                ></i>

                                            <?php elseif ($modalidad === 'VISITA'): ?>

                                                <i
                                                    class="fa-solid fa-house"
                                                ></i>

                                            <?php elseif ($modalidad === 'MENSAJE'): ?>

                                                <i
                                                    class="fa-solid fa-message"
                                                ></i>

                                            <?php else: ?>

                                                <i
                                                    class="fa-solid fa-comments"
                                                ></i>

                                            <?php endif; ?>


                                            <?= e(
                                                $modalidadNombre
                                            ) ?>

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- =================================
                                     ESTADO
                                ================================== -->

                                <td>

                                    <?php if ($esExcepcion): ?>

                                        <span
                                            class="estado excepcion"
                                        >

                                            <i
                                                class="fa-solid fa-circle-info"
                                            ></i>

                                            <?= e(
                                                nombreMotivoExcepcionSeguimiento(
                                                    $registro['excepcion_motivo']
                                                )
                                            ) ?>

                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="estado <?= e(
                                                $estadoClase
                                            ) ?>"
                                        >

                                            <?= e(
                                                $estadoNombre
                                            ) ?>

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- =================================
                                     RESPONSABLE
                                ================================== -->

                                <td>

                                    <span class="seguimiento-responsable">

                                        <?= e(
                                            $registro['responsable_nombre']
                                            ?? '-'
                                        ) ?>

                                    </span>

                                </td>


                                <!-- =================================
                                     FECHA
                                ================================== -->

                                <td>

                                    <?php if ($esExcepcion): ?>

                                        <?php

                                        $mesExcepcion =
                                            (int)(
                                                $registro['excepcion_mes']
                                                ?? 0
                                            );

                                        $anioExcepcion =
                                            (int)(
                                                $registro['excepcion_anio']
                                                ?? 0
                                            );

                                        ?>

                                        <span class="seguimiento-fecha">

                                            <?= e(
                                                $mesesEspanol[
                                                    $mesExcepcion
                                                ] ?? ''
                                            ) ?>

                                            <?= $anioExcepcion ?>

                                        </span>

                                    <?php else: ?>

                                        <span class="seguimiento-fecha">

                                            <?= e(
                                                formatearFecha(
                                                    $registro['fecha_registro']
                                                )
                                            ) ?>

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- =================================
                                     ACCIONES
                                ================================== -->

                                <td>

                                    <div class="seguimiento-acciones">

                                        <!-- =============================
                                             VER PERFIL
                                        ============================== -->

                                        <a
                                            href="../jovenes/ver.php?id=<?= (int)$registro['joven_id'] ?>"
                                            class="btn btn-sm btn-perfil <?= e(
                                                $clasePerfil
                                            ) ?>"
                                        >

                                            <i
                                                class="fa-solid fa-user"
                                            ></i>

                                            Ver perfil

                                        </a>


                                        <!-- =============================
                                             EDITAR EXCEPCIÓN
                                        ============================== -->
<?php if ($esExcepcion): ?>

    <button
        type="button"
        class="btn btn-sm btn-warning btn-editar-excepcion"
        data-id="<?= (int)$registro['excepcion_id'] ?>"
        data-joven-id="<?= (int)$registro['joven_id'] ?>"
        data-joven-nombre="<?= e(
            $registro['nombre_completo']
        ) ?>"
    >


        Editar

    </button>


    <form
        action="../../controllers/excepcionSeguimientoController.php"
        method="POST"
        style="display:inline;"
        onsubmit="return confirm(
            '¿Quieres eliminar esta excepción? El joven volverá a aparecer como pendiente.'
        );"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars(
                $_SESSION['csrf_token'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        >

        <input
            type="hidden"
            name="action"
            value="eliminar_excepcion_seguimiento"
        >

        <input
            type="hidden"
            name="id"
            value="<?= (int)$registro['excepcion_id'] ?>"
        >

        <button
            type="submit"
            class="btn btn-sm btn-danger"
        >


            Quitar excepción

        </button>

    </form>

<?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <!-- =================================
                             TABLA VACÍA
                        ================================== -->

                        <tr>

                            <td
                                colspan="6"
                                class="table-empty"
                            >

                                <div class="gx-empty">

                                    <i
                                        class="fa-solid fa-inbox"
                                    ></i>

                                    <p>
                                        No hay registros de seguimiento
                                        durante este mes.
                                    </p>

                                </div>

                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>


    <!-- =====================================================
         MODAL EXCEPCIÓN DE SEGUIMIENTO
    ====================================================== -->

    <div
        id="modalExcepcionSeguimiento"
        class="gx-modal"
        aria-hidden="true"
    >

        <div class="gx-modal__overlay"></div>

        <div
            class="gx-modal__content"
            role="dialog"
            aria-modal="true"
            aria-labelledby="modalExcepcionTitulo"
        >

            <div class="gx-modal__header">

                <div>

                    <h2 id="modalExcepcionTitulo">
                        Registrar excepción
                    </h2>

                    <p>
                        Indica por qué este joven no tendrá seguimiento
                        durante el período actual.
                    </p>

                </div>

                <button
                    type="button"
                    class="gx-modal__close"
                    id="cerrarModalExcepcion"
                    aria-label="Cerrar"
                >

                    <i class="fa-solid fa-xmark"></i>

                </button>

            </div>


            <div class="gx-modal__body">

                <div class="form-info">

                    <i class="fa-solid fa-circle-info"></i>

                    <span>
                        Joven:
                        <strong id="excepcionJovenNombre">
                            —
                        </strong>
                    </span>

                </div>


                <form
                    id="formExcepcionSeguimiento"
                    action="../../controllers/excepcionSeguimientoController.php"
                    method="POST"
                    class="form"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            $_SESSION['csrf_token'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="crear_excepcion_seguimiento"
                    >

                    <input
                        type="hidden"
                        name="joven_id"
                        id="excepcionJovenId"
                        value=""
                    >


                    <div class="form-group">

                        <label
                            class="form-label"
                            for="motivoExcepcion"
                        >

                            <i class="fa-solid fa-list-check"></i>

                            Motivo

                        </label>


                        <select
                            id="motivoExcepcion"
                            name="motivo"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Seleccionar motivo
                            </option>

                            <option value="SIN_TELEFONO">
                                No tiene teléfono
                            </option>

                            <option value="JOVEN_ANTIGUO">
                                Joven antiguo
                            </option>

                            <option value="REGRESO">
                                Regresó al ministerio
                            </option>

                            <option value="TRASLADO">
                                Viene de otra iglesia
                            </option>

                            <option value="NO_CORRESPONDE">
                                No corresponde seguimiento de nuevo
                            </option>

                            <option value="OTRO">
                                Otro motivo
                            </option>

                        </select>

                    </div>


                    <div class="form-group">

                        <label
                            class="form-label"
                            for="observacionesExcepcion"
                        >

                            <i class="fa-solid fa-comment-dots"></i>

                            Observaciones

                        </label>


                        <textarea
                            id="observacionesExcepcion"
                            name="observaciones"
                            class="form-textarea"
                            rows="4"
                            maxlength="1000"
                            placeholder="Agrega una observación si es necesario..."
                        ></textarea>

                    </div>


                    <div class="form-actions">

                        <button
                            type="button"
                            class="btn btn-back"
                            id="cancelarExcepcion"
                        >

                            Cancelar

                        </button>


                        <button
                            type="submit"
                            class="btn btn-primary"
                        >

                     

                            Registrar excepción

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

</div>


<script>
    window.excepcionSeguimientoUrl =
        <?= json_encode(
            BASE_URL
            . '/controllers/excepcionSeguimientoController.php'
        ) ?>;
</script>


<script
    src="<?= BASE_URL ?>/assets/js/modulos/seguimientos/index.js"
></script>


<script
    src="<?= BASE_URL ?>/assets/js/modulos/seguimientos/excepciones.js"
></script>


<?php require_once __DIR__ . "/../../includes/footer.php"; ?>