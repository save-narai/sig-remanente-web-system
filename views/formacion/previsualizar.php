<?php
require_once __DIR__ . "/../../middleware/auth.php";
require_once __DIR__ . "/../../middleware/permiso.php";
require_once __DIR__ . "/../../config/conexion.php";
require_once __DIR__ . "/../../helpers/csrf.php";
require_once __DIR__ . "/../../services/formacionService.php";

if (!tienePermiso('gestionar_formacion')) {
    header("Location: ../dashboard.php");
    exit;
}

generarCsrf();

$importacionId = (int) ($_GET['importacion_id'] ?? 0);

$importacion = obtenerImportacionFormacion($pdo, $importacionId);

if (!$importacion) {
    header("Location: importar.php");
    exit;
}

$etiquetasEstado = [
    'NUEVO' => ['texto' => 'Nuevo', 'clase' => 'joven-ok'],
    'MODIFICADO' => ['texto' => 'Modificado', 'clase' => 'joven-riesgo2'],
    'DUPLICADO' => ['texto' => 'Duplicado', 'clase' => 'joven-riesgo2'],
    'SIN_CORRESPONDENCIA' => ['texto' => 'Sin correspondencia', 'clase' => 'joven-riesgo3'],
    'SIN_CAMBIOS' => ['texto' => 'Sin cambios', 'clase' => ''],
];

require_once __DIR__ . "/../../includes/header.php";
?>

<div class="page">

    <?php if(isset($_SESSION["error"])): ?>
    <script>
    document.addEventListener("DOMContentLoaded", () => {
        showToast(<?= json_encode($_SESSION["error"]) ?>, "error");
    });
    </script>
    <?php unset($_SESSION["error"]); endif; ?>

    <div class="page-header">

        <h1 class="page-title">Previsualización de importación</h1>

        <a href="importar.php" class="btn btn-back">Volver</a>

    </div>

    <div class="form-info">

        <i class="fa-solid fa-circle-info"></i>

        <div>
            <strong><?= htmlspecialchars($importacion['archivo_nombre']) ?></strong>
            <p>
                Ningún dato se ha guardado todavía en Formación. Revisa el resumen y las
                filas problemáticas antes de confirmar.
            </p>
        </div>

    </div>

    <div class="stats-grid stats-grid--mini">

        <div class="stat-card stat-card--mini"><span class="stat-number"><?= (int) $importacion['nuevos'] ?></span><span class="stat-label">Nuevos</span></div>
        <div class="stat-card stat-card--mini"><span class="stat-number"><?= (int) $importacion['modificados'] ?></span><span class="stat-label">Modificados</span></div>
        <div class="stat-card stat-card--mini"><span class="stat-number"><?= (int) $importacion['duplicados'] ?></span><span class="stat-label">Duplicados</span></div>
        <div class="stat-card stat-card--mini"><span class="stat-number"><?= (int) $importacion['sin_correspondencia'] ?></span><span class="stat-label">Sin correspondencia</span></div>
        <div class="stat-card stat-card--mini"><span class="stat-number"><?= (int) $importacion['sin_cambios'] ?></span><span class="stat-label">Sin cambios</span></div>

    </div>

    <?php if ($importacion['estado'] !== 'PENDIENTE_CONFIRMACION'): ?>

        <div class="form-info">
            <i class="fa-solid fa-circle-check"></i>
            <div>
                Esta importación ya fue <strong><?= strtolower($importacion['estado']) ?></strong>
                el <?= htmlspecialchars((string) $importacion['fecha_confirmacion']) ?>.
                No se puede volver a confirmar.
            </div>
        </div>

    <?php endif; ?>

    <div class="form-card">

        <div class="table-responsive">
        <table class="table gx-table">

            <thead>
                <tr>
                    <th>Fila</th>
                    <th>Joven</th>
                    <th>Ciclo</th>
                    <th>Lección</th>
                    <th>Progreso</th>
                    <th>Estado</th>
                    <th>Motivo</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($importacion['filas'] as $fila): ?>
                    <?php $datos = json_decode($fila['datos_json'], true) ?: []; ?>
                    <?php $etiqueta = $etiquetasEstado[$fila['estado_fila']] ?? ['texto' => $fila['estado_fila'], 'clase' => '']; ?>
                    <tr>
                        <td><?= (int) $fila['fila_numero'] ?></td>
                        <td>
                            <?= $fila['joven_nombre']
                                ? htmlspecialchars($fila['joven_nombre'])
                                : htmlspecialchars($datos['nombre_completo'] ?? '—') . ' (sin vincular)'
                            ?>
                        </td>
                        <td><?= htmlspecialchars($datos['ciclo_formacion'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($datos['leccion_actual'] ?? '—') ?></td>
                        <td><?= $datos['progreso_porcentaje'] !== null ? ((int) $datos['progreso_porcentaje'] . '%') : '—' ?></td>
                        <td><span class="<?= $etiqueta['clase'] ?>"><?= htmlspecialchars($etiqueta['texto']) ?></span></td>
                        <td><?= htmlspecialchars($fila['motivo'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>

        </table>
        </div>

        <p class="form-hint">
            Las filas "Sin correspondencia" y "Duplicado" no se guardan al confirmar --
            quedan documentadas aquí para revisión manual. Para vincular un joven que no
            tenía <code>joven_id</code>, edítalo desde el listado de Jóvenes y vuelve a
            importar con el <code>joven_id</code> correcto.
        </p>

    </div>

    <?php if ($importacion['estado'] === 'PENDIENTE_CONFIRMACION'): ?>

    <div class="form-actions">

        <form
            action="<?= BASE_URL ?>/controllers/formacionController.php"
            method="POST"
            onsubmit="return confirm('¿Confirmar esta importación? Se aplicarán los registros Nuevo y Modificado.');"
        >
            <?= csrfField(); ?>
            <input type="hidden" name="action" value="confirmar_importacion_formacion">
            <input type="hidden" name="importacion_id" value="<?= (int) $importacion['id'] ?>">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-check"></i>
                Confirmar importación
            </button>
        </form>

        <form
            action="<?= BASE_URL ?>/controllers/formacionController.php"
            method="POST"
            onsubmit="return confirm('¿Descartar esta importación? No se guardará ningún cambio.');"
        >
            <?= csrfField(); ?>
            <input type="hidden" name="action" value="descartar_importacion_formacion">
            <input type="hidden" name="importacion_id" value="<?= (int) $importacion['id'] ?>">
            <button type="submit" class="btn btn-back">
                Descartar
            </button>
        </form>

    </div>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . "/../../includes/footer.php"; ?>
