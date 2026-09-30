<?php

declare(strict_types=1);

require_once __DIR__ . "/../../middleware/auth.php";
require_once __DIR__ . "/../../middleware/permiso.php";
require_once __DIR__ . "/../../helpers/csrf.php";
require_once __DIR__ . "/../../config/conexion.php";

if (!tienePermiso('gestionar_usuarios')) {

    header("Location: ../dashboard.php");
    exit;

}

$id = (int) ($_GET['id'] ?? 0);

require_once __DIR__ . "/../../services/usuarioService.php";

$usuario = obtenerUsuarioPorId($pdo, $id);

if (!$usuario) {

    header("Location: index.php");
    exit;

}

require_once __DIR__ . "/../../includes/header.php";

?>

<div class="form-card">

    <div class="form-header">

        <div class="form-header-icon">

            <i class="fa-solid fa-key"></i>

        </div>

        <div class="form-header-content">

            <h1 class="form-title">

                Cambiar Contraseña

            </h1>

            <p class="form-subtitle">

                Actualiza la contraseña del usuario

                <strong>

                    <?= htmlspecialchars($usuario['nombre']) ?>

                </strong>

            </p>

        </div>

    </div>

    <form
        class="form"
        action="<?= BASE_URL ?>/controllers/usuarioController.php"
        method="POST"
    >

        <?= csrfField(); ?>

        <input
            type="hidden"
            name="action"
            value="cambiar_password"
        >

        <input
            type="hidden"
            name="id"
            value="<?= (int) $usuario['id'] ?>"
        >

        <div class="form-group">

            <label class="form-label">

                Nueva Contraseña

            </label>

            <input
                id="password"
                class="form-input"
                type="password"
                name="password"
                minlength="6"
                autocomplete="new-password"
                required
            >

        </div>

        <div class="form-group">

            <label class="form-label">

                Confirmar Contraseña

            </label>

            <input
                id="confirmarPassword"
                class="form-input"
                type="password"
                name="confirmar_password"
                minlength="6"
                autocomplete="new-password"
                required
            >

            <small
                id="passwordError"
                class="telefono-error"
            ></small>

        </div>

        <div class="form-actions">

            <a
                href="index.php"
                class="btn btn-back"
            >

                <i class="fa-solid fa-arrow-left"></i>

                Volver

            </a>

            <button
                id="btnGuardar"
                type="submit"
                class="btn btn-primary"
            >

                Guardar Contraseña

            </button>

        </div>

    </form>

</div>

<script
    src="<?= BASE_URL ?>/assets/js/modulos/usuarios/reset-password.js">
</script>

<?php require_once __DIR__ . "/../../includes/footer.php"; ?>