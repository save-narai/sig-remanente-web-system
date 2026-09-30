<?php

require_once __DIR__ . '/../../../middleware/auth.php';
require_once __DIR__ . '/../../../middleware/permiso.php';
require_once __DIR__ . '/../../../config/conexion.php';
require_once __DIR__ . '/../../../services/discipuladoService.php';

if (!tienePermiso('gestionar_reuniones')) {

    header('Location: ../../dashboard.php');
    exit;

}

$clases = obtenerClasesBaseDiscipulado($pdo, false);

$materiales = $pdo
    ->query('SELECT * FROM materiales_discipulado')
    ->fetchAll(PDO::FETCH_ASSOC);

// Indexado por clase y tipo (PDF/WORD) -- ahora puede haber hasta
// dos materiales por clase, cada uno independiente.
$porBase = [];

foreach ($materiales as $m) {
    $tipo = $m['tipo'] ?? 'PDF';
    $porBase[(int)$m['clase_base_id']][$tipo] = $m;
}

require_once __DIR__ . '/../../../includes/header.php';

?>

<div class="discipulado-page">

    <!-- =====================================
         PAGE HEADER
    ===================================== -->

    <div class="page-header">

        <div class="page-header-left">
            <h1 class="page-title">Material de discipulado</h1>
        </div>

        <div class="page-header-right">
            <a class="btn btn-back" href="index.php">Volver a ciclos</a>
        </div>

    </div>

    <!-- =====================================
         LISTA: CLASE → PDF
    ===================================== -->

    <div class="page-section">

        <div class="table-responsive">

            <table class="table gx-table">

                <thead>
                    <tr>
                        <th>Clase</th>
                        <th>Material</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($clases as $clase): ?>

                        <tr>

                            <td>
                                Clase <?= (int)$clase['numero_orden'] ?> —
                                <?= htmlspecialchars($clase['nombre']) ?>
                            </td>

                            <td>

                                <?php
                                $mPdf = $porBase[(int)$clase['id']]['PDF'] ?? null;
                                $mWord = $porBase[(int)$clase['id']]['WORD'] ?? null;
                                ?>

                                <div class="discipulado-material-slot">

                                    <span class="discipulado-material-slot__etiqueta">PDF:</span>

                                    <?php if ($mPdf): ?>

                                        <a
                                            class="btn btn-primary btn-sm"
                                            target="_blank"
                                            href="<?= BASE_URL ?>/controllers/discipuladoMaterialController.php?id=<?= (int)$mPdf['id'] ?>"
                                        >
                                            Ver
                                        </a>

                                        <a
                                            class="btn btn-back btn-sm"
                                            href="<?= BASE_URL ?>/controllers/discipuladoMaterialController.php?id=<?= (int)$mPdf['id'] ?>&modo=descargar"
                                        >
                                            Descargar
                                        </a>

                                    <?php else: ?>

                                        <span>Sin PDF</span>

                                    <?php endif; ?>

                                    <form
                                        action="<?= BASE_URL ?>/controllers/discipuladoMaterialAdminController.php"
                                        method="POST"
                                        enctype="multipart/form-data"
                                        class="discipulado-material-form"
                                    >

                                        <?= csrfField() ?>

                                        <input type="hidden" name="action" value="guardar_material_discipulado">
                                        <input type="hidden" name="clase_base_id" value="<?= (int)$clase['id'] ?>">

                                        <label class="discipulado-material-cambiar">
                                            <?= $mPdf ? 'Reemplazar' : 'Subir' ?>
                                            <input
                                                type="file"
                                                name="archivo"
                                                accept="application/pdf"
                                                required
                                                onchange="this.form.submit()"
                                                hidden
                                            >
                                        </label>

                                    </form>

                                </div>

                                <div class="discipulado-material-slot">

                                    <span class="discipulado-material-slot__etiqueta">Word:</span>

                                    <?php if ($mWord): ?>

                                        <a
                                            class="btn btn-back btn-sm"
                                            href="<?= BASE_URL ?>/controllers/discipuladoMaterialController.php?id=<?= (int)$mWord['id'] ?>&modo=descargar"
                                        >
                                            Descargar
                                        </a>

                                    <?php else: ?>

                                        <span>Sin Word</span>

                                    <?php endif; ?>

                                    <form
                                        action="<?= BASE_URL ?>/controllers/discipuladoMaterialAdminController.php"
                                        method="POST"
                                        enctype="multipart/form-data"
                                        class="discipulado-material-form"
                                    >

                                        <?= csrfField() ?>

                                        <input type="hidden" name="action" value="guardar_material_discipulado">
                                        <input type="hidden" name="clase_base_id" value="<?= (int)$clase['id'] ?>">

                                        <label class="discipulado-material-cambiar">
                                            <?= $mWord ? 'Reemplazar' : 'Subir' ?>
                                            <input
                                                type="file"
                                                name="archivo"
                                                accept=".docx"
                                                required
                                                onchange="this.form.submit()"
                                                hidden
                                            >
                                        </label>

                                    </form>

                                </div>

                            </td>


                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
