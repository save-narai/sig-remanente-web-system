<?php
require_once __DIR__ . "/../../middleware/auth.php";
require_once __DIR__ . "/../../middleware/permiso.php";
require_once __DIR__ . "/../../config/conexion.php";
require_once __DIR__ . "/../../helpers/csrf.php";
require_once __DIR__ . "/../../services/formacionService.php";
require_once __DIR__ . "/../../services/formacionImportadorGoogleSheetsService.php";

if (!tienePermiso('gestionar_formacion')) {
    header("Location: ../dashboard.php");
    exit;
}

generarCsrf();

$configFormacion = require __DIR__ . '/../../config/formacion.php';

$googleConfigurado = googleSheetsFormacionConfigurado(
    $configFormacion
);

require_once __DIR__ . "/../../includes/header.php";
?>

<div class="page formacion-importar">

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

        <h1 class="page-title">Formación -- Importar</h1>

        <a href="index.php" class="btn btn-back">Volver a Formación</a>

    </div>

    <div class="form-info formacion-importar__info">

        <i class="fa-solid fa-circle-info"></i>

        <div>

            <strong>Contrato de importación propuesto</strong>

            <p>
                Esta plataforma todavía no ha recibido el archivo real que usa el
                equipo de Formación. Las columnas de abajo son el <strong>contrato
                propuesto por la plataforma</strong> -- cuando llegue el archivo
                real, se compara contra este contrato y se ajusta si hace falta.
            </p>

            <p class="formacion-importar__cta">
                <a href="<?= BASE_URL ?>/controllers/formacionController.php?action=descargar_plantilla_formacion" class="btn btn-secondary btn-sm">
                    <i class="fa-solid fa-download"></i>
                    Descargar plantilla de importación de Formación
                </a>
            </p>

        </div>

    </div>

    <div class="form-card formacion-importar__contrato">

        <div class="table-responsive">
        <table class="table gx-table formacion-importar__tabla">

            <thead>
                <tr>
                    <th>Columna</th>
                    <th>Obligatoria</th>
                    <th>Tipo</th>
                    <th>Significado</th>
                    <th>Ejemplo</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach (CONTRATO_FORMACION as $columna => $definicion): ?>
                <tr>
                    <td><code><?= htmlspecialchars($columna) ?></code></td>
                    <td><?= $definicion['obligatorio'] ? 'Sí' : 'No' ?></td>
                    <td><?= htmlspecialchars($definicion['tipo']) ?></td>
                    <td><?= htmlspecialchars($definicion['descripcion']) ?></td>
                    <td><em><?= htmlspecialchars($definicion['ejemplo']) ?></em></td>
                </tr>
                <?php endforeach; ?>
            </tbody>

        </table>
        </div>

        <p class="form-hint formacion-importar__regla">
            Regla de identificación: si <code>joven_id</code> no está disponible en el
            archivo, el joven <strong>no se vincula automáticamente por nombre</strong> --
            la fila queda "Sin correspondencia" hasta que un administrador la resuelva
            manualmente desde la previsualización.
        </p>

    </div>

    <div class="form-card formacion-importar__card">

        <h2 class="form-title">Subir archivo .xlsx</h2>

        <p class="formacion-importar__explicacion">
            Seleccioná el archivo exportado por el equipo de Formación en formato
            <code>.xlsx</code> (máximo 5&nbsp;MB) y procesalo para revisar una
            previsualización antes de confirmar cualquier cambio.
        </p>

        <form
            action="<?= BASE_URL ?>/controllers/formacionController.php"
            method="POST"
            enctype="multipart/form-data"
            class="form"
        >

            <?= csrfField(); ?>

            <input type="hidden" name="action" value="importar_xlsx_formacion">

            <div class="form-group form-group-full">

                <label class="form-label">Archivo (.xlsx, máx. 5 MB)</label>

                <input
                    type="file"
                    name="archivo"
                    class="form-input"
                    accept=".xlsx"
                    required
                >

            </div>

            <div class="form-actions formacion-importar__accion">

                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-file-import"></i>
                    Procesar archivo
                </button>

            </div>

        </form>

        <p class="form-hint formacion-importar__nota">
            No se guarda nada en la base de datos todavía -- el siguiente paso
            muestra una previsualización (nuevos / modificados / duplicados / sin
            correspondencia) para que la confirmes o la descartes.
        </p>

    </div>

    <div class="form-card formacion-importar__card">

        <h2 class="form-title">Google Sheets</h2>

        <p class="form-hint formacion-importar__nota">
            <?php if ($googleConfigurado): ?>
                Configurado. (Esto no debería mostrarse todavía en un despliegue nuevo --
                revisa <code>config/formacion.php</code>.)
            <?php else: ?>
                No configurado todavía. Mientras no existan las credenciales reales de
                Google (cuenta de servicio, ID del Sheet), la importación funciona
                únicamente por archivo .xlsx. Ver los comentarios de
                <code>config/formacion.php</code> para los pasos exactos de activación.
            <?php endif; ?>
        </p>

    </div>

</div>

<?php require_once __DIR__ . "/../../includes/footer.php"; ?>
