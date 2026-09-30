<?php
require_once __DIR__ . "/../../middleware/auth.php";
require_once __DIR__ . "/../../middleware/permiso.php";
require_once __DIR__ . "/../../config/conexion.php";
require_once __DIR__ . "/../../helpers/csrf.php";
require_once __DIR__ . "/../../services/formacionService.php";

if (!tienePermiso('gestionar_formacion') && !tienePermiso('gestionar_reuniones')) {
    header("Location: ../dashboard.php");
    exit;
}

$puedeImportar = tienePermiso('gestionar_formacion');

generarCsrf();

$registros = $puedeImportar ? listarRegistrosFormacion($pdo) : [];
$importaciones = $puedeImportar ? listarImportacionesFormacion($pdo) : [];

require_once __DIR__ . "/../../includes/header.php";
?>

<div class="page">

    <?php if(isset($_SESSION["success"])): ?>
    <script>
    document.addEventListener("DOMContentLoaded", () => {
        showToast(<?= json_encode($_SESSION["success"]) ?>, "success");
    });
    </script>
    <?php unset($_SESSION["success"]); endif; ?>

    <div class="page-header">

        <h1 class="page-title">Formación</h1>

        <div class="page-header-actions">

            <a href="discipulado/index.php" class="btn btn-secondary">
                <i class="fa-solid fa-people-group"></i>
                Discipulado
            </a>

            <?php if ($puedeImportar): ?>
            <a href="importar.php" class="btn btn-primary">
                <i class="fa-solid fa-file-import"></i>
                Importar Formación
            </a>
            <?php endif; ?>

        </div>

    </div>

    <?php if (!$puedeImportar): ?>

        <div class="empty-state">
            No tienes permiso para importar o consultar datos de Formación. Puedes
            acceder al módulo de Discipulado desde el botón de arriba.
        </div>

    <?php else: ?>

    <div class="form-info">

        <i class="fa-solid fa-circle-info"></i>

        <div>
            <strong>Fuente de los datos</strong>
            <p>
                Los registros de esta tabla vienen exclusivamente de importaciones
                confirmadas. La identidad del joven (nombre, teléfono, fecha de
                nacimiento) sigue viniendo únicamente del módulo de Jóvenes -- aquí solo
                se guarda información propia de Formación (ciclo, lección, progreso),
                relacionada por <code>joven_id</code>.
            </p>
        </div>

    </div>

    <div class="page-section">

        <h2 class="page-section-title">Registros de Formación (<?= count($registros) ?>)</h2>

        <?php if (empty($registros)): ?>

            <div class="empty-state">
                Todavía no hay ningún registro confirmado. Usa "Importar Formación" para
                cargar el primero.
            </div>

        <?php else: ?>

        <div class="table-responsive">
        <table class="table gx-table">

            <thead>
                <tr>
                    <th>Joven</th>
                    <th>Ciclo de formación</th>
                    <th>Lección actual</th>
                    <th>Progreso</th>
                    <th>Fecha del dato</th>
                    <th>Actualizado</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($registros as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['nombre_completo']) ?></td>
                        <td><?= htmlspecialchars($r['ciclo_formacion'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($r['leccion_actual'] ?? '—') ?></td>
                        <td><?= $r['progreso_porcentaje'] !== null ? ((int) $r['progreso_porcentaje'] . '%') : '—' ?></td>
                        <td><?= htmlspecialchars($r['fecha_dato'] ?? '—') ?></td>
                        <td><?= htmlspecialchars((string) $r['fecha_actualizacion']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>

        </table>
        </div>

        <?php endif; ?>

    </div>

    <div class="page-section">

        <h2 class="page-section-title">Historial de importaciones</h2>

        <?php if (empty($importaciones)): ?>

            <div class="empty-state">
                Todavía no se ha subido ningún archivo.
            </div>

        <?php else: ?>

        <div class="table-responsive">
        <table class="table gx-table">

            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Archivo</th>
                    <th>Origen</th>
                    <th>Usuario</th>
                    <th>Nuevos</th>
                    <th>Modificados</th>
                    <th>Sin correspondencia</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($importaciones as $imp): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $imp['fecha_importacion']) ?></td>
                        <td><?= htmlspecialchars($imp['archivo_nombre']) ?></td>
                        <td><?= htmlspecialchars($imp['tipo_origen']) ?></td>
                        <td><?= htmlspecialchars($imp['usuario_nombre'] ?? '—') ?></td>
                        <td><?= (int) $imp['nuevos'] ?></td>
                        <td><?= (int) $imp['modificados'] ?></td>
                        <td><?= (int) $imp['sin_correspondencia'] ?></td>
                        <td><?= htmlspecialchars($imp['estado']) ?></td>
                        <td>
                            <a class="btn btn-secondary btn-sm" href="previsualizar.php?importacion_id=<?= (int) $imp['id'] ?>">
                                Ver
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>

        </table>
        </div>

        <?php endif; ?>

    </div>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . "/../../includes/footer.php"; ?>
