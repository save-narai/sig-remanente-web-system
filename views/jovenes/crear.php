<?php

require_once __DIR__ . "/../../middleware/auth.php";
require_once __DIR__ . "/../../middleware/permiso.php";
require_once __DIR__ . "/../../helpers/csrf.php";

/* =====================================
   CSRF
===================================== */

generarCsrf();

/* =====================================
   PERMISOS
===================================== */

if (!tienePermiso('gestionar_jovenes')) {

    header("Location: ../dashboard.php");
    exit;
}

/* =====================================
   HEADER
===================================== */

require_once __DIR__ . "/../../includes/header.php";

?>

<div class="form-card">

    <!-- =====================================
         HEADER
    ====================================== -->

    <div class="form-header">

        <div class="form-header-icon">

            <i class="fa-solid fa-user-plus"></i>

        </div>

        <div class="form-header-content">

            <h1 class="form-title">
                Crear Joven
            </h1>

            <p class="form-subtitle">
                Registra un nuevo joven dentro del sistema de seguimiento y discipulado.
            </p>

        </div>

    </div>

    <!-- =====================================
         INFORMACIÓN
    ====================================== -->

    <div class="form-info">

        <i class="fa-solid fa-circle-info"></i>

        <span>
            Registra la información básica del joven. Posteriormente podrás editar sus datos y consultar su historial de asistencia y seguimiento.
        </span>

    </div>

    <!-- =====================================
         FORMULARIO
    ====================================== -->

    <form
    id="formJoven"
    class="form"
    action="<?= BASE_URL ?>/controllers/jovenController.php"
    method="POST"
    autocomplete="off"
>

    <input
        type="hidden"
        name="csrf_token"
        value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
    >

    <input
        type="hidden"
        name="action"
        value="crear_joven"
    >

        <div class="form-grid">

            <!-- NOMBRE -->

            <div class="form-group form-group-full">

                <label class="form-label">
                    Nombre Completo
                </label>

                <input
                    class="form-input"
                    type="text"
                    name="nombre_completo"
                    maxlength="120"
                    autocomplete="off"
                    required
                >

            </div>

            <!-- FECHA NACIMIENTO -->

            <div class="form-group">

                <label class="form-label">
                    Fecha de Nacimiento
                </label>

                <input
                    class="form-input"
                    type="date"
                    name="fecha_nacimiento"
                    id="fecha"
                >

            </div>

            <!-- EDAD -->

            <div class="form-group">

                <label class="form-label">
                    Edad
                </label>

                <input
                    class="form-input"
                    type="number"
                    name="edad_manual"
                    id="edad"
                    min="1"
                    max="120"
                >

            </div>

            <!-- TELÉFONO -->

            <div class="form-group">

                <label class="form-label">
                    Teléfono
                </label>

                <input
                    class="form-input"
                    type="tel"
                    name="telefono"
                    id="telefono"
                    maxlength="10"
                    inputmode="numeric"
                    autocomplete="off"
                >

                <small
                    id="telefonoError"
                    class="telefono-error"
                ></small>

                <div class="check-wrapper">

                    <label class="check-custom">

                        <input
                            type="checkbox"
                            name="sinTelefono"
                            id="sinTelefono"
                        >

                        <span class="checkmark"></span>

                        <span>
                            No tiene teléfono
                        </span>

                    </label>

                </div>

            </div>

            <!-- FECHA INGRESO -->

            <div class="form-group">

                <label class="form-label">
                    Fecha de Ingreso
                </label>

                <input
                    class="form-input"
                    type="date"
                    name="fecha_ingreso"
                    value="<?= htmlspecialchars(date('Y-m-d')) ?>"
                    required
                >

                <p class="form-hint">
                    El estado congregacional (Nuevo/Congregante) se calcula automáticamente a partir de esta fecha -- no es un campo manual.
                </p>

            </div>

            <!-- GÉNERO -->

            <div class="form-group">

                <label class="form-label">
                    Género
                </label>

                <select
                    class="form-select"
                    name="genero"
                    required
                >

                    <option value="">
                        Seleccionar
                    </option>

                    <option value="M">
                        Masculino
                    </option>

                    <option value="F">
                        Femenino
                    </option>

                </select>

            </div>

            <!-- SERVIDOR: campo manual retirado (Fase 8 -- pulido).
                 La condición de servidor del Ministerio de Jóvenes ya
                 no se establece aquí; proviene del vínculo con
                 Usuarios (jovenes.usuario_id) que implementamos en la
                 Fase 6. La columna es_servidor sigue existiendo en la
                 base de datos (servidor de OTRO ministerio) pero deja
                 de ser un campo del formulario -- ya no corresponde
                 recolectarlo manualmente aquí. -->

            <div class="form-group form-group-full">

                <label class="form-label">
                    Observaciones
                </label>

                <textarea
                    class="form-textarea"
                    name="observaciones"
                    rows="4"
                    maxlength="2000"
                    placeholder="Ej: llegó invitado por un amigo, llegó por evangelización en el parque, llegó por el concierto de jóvenes..."
                ></textarea>

                <p class="form-hint">
                    Opcional -- útil especialmente para registrar cómo llegó el joven al ministerio (evangelización, invitado, evento, etc.). Se puede editar después desde su perfil.
                </p>

            </div>

        </div>

        <!-- BOTONES -->

        <div class="form-actions">

            <a
                href="index.php"
                class="btn btn-back"
            >

                

                Volver

            </a>

            <button
                type="submit"
                class="btn btn-primary"
            >

                

                Guardar Joven

            </button>

        </div>

    </form>

</div>

<script src="<?= BASE_URL ?>/assets/js/components/normalizar-nombre.js"></script>
<script src="<?= BASE_URL ?>/assets/js/modulos/jovenes/crear.js"></script>

<?php require_once __DIR__ . "/../../includes/footer.php"; ?>
