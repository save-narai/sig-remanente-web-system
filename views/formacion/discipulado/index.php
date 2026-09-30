<?php

require_once __DIR__ . '/../../../middleware/auth.php';
require_once __DIR__ . '/../../../middleware/permiso.php';
require_once __DIR__ . '/../../../config/conexion.php';
require_once __DIR__ . '/../../../services/discipuladoService.php';

if (!tienePermiso('gestionar_reuniones')) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Obtener ciclos
|--------------------------------------------------------------------------
|
| El índice principal NO recibe ciclo_id.
| Aquí se muestran todos los ciclos disponibles, o solo los de un
| estado si se pide por la URL (?estado=ACTIVO), reutilizando el
| filtro que obtenerCiclosDiscipulado ya soportaba.
|
*/

$estadoFiltro = strtoupper((string)($_GET['estado'] ?? ''));

$filtrosCiclos = [];

if ($estadoFiltro !== '') {
    $filtrosCiclos['estado'] = $estadoFiltro;
}

$ciclos = [];

if (function_exists('obtenerCiclosDiscipulado')) {
    $ciclos = obtenerCiclosDiscipulado($pdo, $filtrosCiclos);
}

if (!is_array($ciclos)) {
    $ciclos = [];
}

/*
|--------------------------------------------------------------------------
| Historial de Discipulado sin ciclo asignado
|--------------------------------------------------------------------------
|
| Reuniones reales (tipo='Discipulado') registradas antes de que
| existiera el módulo de ciclos/clases. Estrictamente informativo:
| no se crea ciclo, clase, modalidad ni progreso para ellas.
|
*/

$historialSinCiclo = [];

if (function_exists('obtenerReunionesDiscipuladoSinVincular')) {
    $historialSinCiclo = obtenerReunionesDiscipuladoSinVincular($pdo);
}

if (!is_array($historialSinCiclo)) {
    $historialSinCiclo = [];
}

require_once __DIR__ . '/../../../includes/header.php';

?>

<div class="discipulado-page">

    <div class="page-header">

        <div class="page-header-left">

            <h1 class="page-title">
                Discipulado Jóvenes
            </h1>

            <p class="page-subtitle">
                Gestión de ciclos de discipulado.
            </p>

        </div>

        <div class="page-header-right">

            <a
                href="crear.php"
                class="btn btn-primary"
            >
                Crear ciclo
            </a>

        </div>

    </div>


    <div class="page-section">

        <div class="section-header">

            <div>

                <h2 class="section-title">
                    Ciclos de discipulado
                </h2>

                <p class="section-subtitle">
                    Selecciona un ciclo para gestionar sus participantes y clases.
                </p>

            </div>

        </div>


        <div class="table-responsive">

            <table class="table gx-table">

                <thead>

                    <tr>
                        <th>Nombre</th>
                        <th>Inicio</th>
                        <th>Finalización</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>

                </thead>


                <tbody>

                    <?php if (empty($ciclos)): ?>

                        <tr>

                            <td colspan="5" class="text-center">

                                No hay ciclos de discipulado registrados.

                            </td>

                        </tr>

                    <?php else: ?>


                        <?php foreach ($ciclos as $ciclo): ?>

                            <?php

                            $cicloId = (int) ($ciclo['id'] ?? 0);

                            $estado = $ciclo['estado'] ?? 'ACTIVO';

                            $badgeEstado = match ($estado) {

                                'ACTIVO' =>
                                    'badge-success',

                                'FINALIZADO' =>
                                    'badge-info',

                                'CANCELADO' =>
                                    'badge-danger',

                                default =>
                                    'badge-info'

                            };

                            ?>


                            <tr>

                                <td>

                                    <strong>
                                        <?= htmlspecialchars(
                                            (string) ($ciclo['nombre'] ?? 'Sin nombre')
                                        ) ?>
                                    </strong>

                                    <?php if (!empty($ciclo['descripcion'])): ?>

                                        <br>

                                        <small>
                                            <?= htmlspecialchars(
                                                (string) $ciclo['descripcion']
                                            ) ?>
                                        </small>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        (string) ($ciclo['fecha_inicio'] ?? '—')
                                    ) ?>

                                </td>


                                <td>

                                    <?= htmlspecialchars(
                                        (string) ($ciclo['fecha_fin'] ?? '—')
                                    ) ?>

                                </td>


                                <td>

                                    <span class="badge <?= $badgeEstado ?>">

                                        <?= htmlspecialchars(
                                            (string) $estado
                                        ) ?>

                                    </span>

                                </td>


                                <td>

                                    <div class="table-actions">

                                        <a
                                            href="ver.php?ciclo_id=<?= $cicloId ?>"
                                            class="btn btn-primary btn-sm"
                                        >
                                            Ver ciclo
                                        </a>


                                        <a
                                            href="editar.php?ciclo_id=<?= $cicloId ?>"
                                            class="btn btn-back btn-sm"
                                        >
                                            Editar
                                        </a>

                                        <form
                                            action="<?= BASE_URL ?>/controllers/discipuladoCicloController.php"
                                            method="POST"
                                            onsubmit="return confirm('<?= $estado === 'CANCELADO' ? '¿Reactivar este ciclo?' : '¿Eliminar (cancelar) este ciclo? Las clases, participantes y asistencia registrados se conservan, solo deja de aparecer como activo.' ?>');"
                                        >
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="cambiar_estado_ciclo_discipulado">
                                            <input type="hidden" name="id" value="<?= $cicloId ?>">
                                            <?php if ($estado === 'CANCELADO'): ?>
                                                <input type="hidden" name="estado" value="ACTIVO">
                                                <button type="submit" class="btn btn-primary btn-sm">Reactivar</button>
                                            <?php else: ?>
                                                <input type="hidden" name="estado" value="CANCELADO">
                                                <button type="submit" class="btn btn-back btn-sm">Eliminar</button>
                                            <?php endif; ?>
                                        </form>

                                    </div>

                                </td>

                            </tr>


                        <?php endforeach; ?>


                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <?php if (!empty($historialSinCiclo)): ?>

    <div class="page-section">

        <div class="section-header">

            <div>

                <h2 class="section-title">
                    Historial de Discipulado sin ciclo asignado
                </h2>

                <p class="section-subtitle">
                    Reuniones reales de Discipulado registradas antes de que existiera este módulo. Es información histórica, no datos incompletos por corregir.
                </p>

            </div>

        </div>

        <div class="table-responsive">

            <table class="table gx-table">

                <thead>

                    <tr>
                        <th>Fecha</th>
                        <th>Título</th>
                        <th>Tipo</th>
                        <th>Asistencias reales</th>
                        <th>Jóvenes distintos</th>
                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($historialSinCiclo as $reunion): ?>

                        <tr>

                            <td>
                                <?= htmlspecialchars((string) ($reunion['fecha'] ?? '—')) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars((string) ($reunion['titulo'] ?? '—')) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars((string) ($reunion['tipo'] ?? '—')) ?>
                            </td>

                            <td>
                                <?= (int) ($reunion['asistencias_reales'] ?? 0) ?>
                            </td>

                            <td>
                                <?= (int) ($reunion['jovenes_distintos'] ?? 0) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </div>

    <?php endif; ?>

</div>


<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>