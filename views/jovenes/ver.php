<?php

require_once __DIR__ . "/../../middleware/auth.php";
require_once __DIR__ . "/../../middleware/permiso.php";
require_once __DIR__ . "/../../config/conexion.php";
require_once __DIR__ . "/../../helpers/csrf.php";
require_once __DIR__ . "/../../services/seguimientoService.php";


/* =========================
   ACTUALIZAR ACTIVIDAD
========================= */

// (Comentario ya existía pero la llamada real faltaba -- corregido:
// sin esto, esta vista podía mostrar un badge/estado desactualizado.
// Misma llamada que dashboardController.php, historial.php e
// index.php.)
actualizarEstadoActividad($pdo);
actualizarEstadoCongregacional($pdo);

generarCsrf();

/* =========================
   PERMISOS
========================= */

if (!tienePermiso('gestionar_jovenes')) {

    $_SESSION["error"] = "No tienes permiso";

    header("Location: ../dashboard.php");

    exit;
}

/* =========================
   VALIDAR ID
========================= */

$id = isset($_GET["id"])
    ? (int) $_GET["id"]
    : 0;

if ($id <= 0) {

    $_SESSION["error"] = "ID inválido";

    header("Location: index.php");

    exit;
}

/* =========================
   DATOS JOVEN
========================= */

$stmt = $pdo->prepare("
    SELECT *
    FROM jovenes
    WHERE id = :id
");

$stmt->execute([
    "id" => $id
]);

$joven = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$joven) {

    $_SESSION["error"] = "Joven no encontrado";

    header("Location: index.php");

    exit;
}

/* =========================
   CLASE GÉNERO
========================= */

$claseGenero = ($joven["genero"] ?? '') === "F"
    ? "perfil-chica"
    : "perfil-chico";

/* =========================
   EDAD
========================= */

$edad = "—";

$edadAprox = false;

if (!empty($joven["fecha_nacimiento"])) {

    $edad = (new DateTime(
        $joven["fecha_nacimiento"]
    ))->diff(new DateTime())->y;

} elseif (!empty($joven["edad_manual"])) {

    $edad = (int)$joven["edad_manual"];

    $edadAprox = true;
}

/* =========================
   RESUMEN ASISTENCIA
========================= */

$stmt = $pdo->prepare("
    SELECT

        SUM(asistio = 1) AS presentes,

        SUM(asistio = 0) AS ausentes

    FROM asistencia

    WHERE joven_id = :joven_id
");

$stmt->execute([
    "joven_id" => $id
]);

$resumen = $stmt->fetch(PDO::FETCH_ASSOC);

$presentes = (int)($resumen["presentes"] ?? 0);

$ausentes = (int)($resumen["ausentes"] ?? 0);

$totalAsistencia = $presentes + $ausentes;

$porcentaje = $totalAsistencia > 0
    ? round(($presentes / $totalAsistencia) * 100)
    : 0;

/* =========================
   PERFIL DE ASISTENCIA JUVENIL -- PARTE 1

   Indicadores derivados, solo tipos juveniles
   (Reunión Jóvenes / Grupo Conexión), solo asistio=1.
   No confundir con el bloque anterior (que suma toda la
   tabla "asistencia" sin filtrar tipo de reunión).
========================= */

$perfilAsistencia = obtenerPerfilAsistenciaJuvenil(
    $pdo,
    $id
);

/* =========================
   ESTADO DE ACTIVIDAD (modelo único 4/12)
========================= */
$con = etiquetaVisualActividadJuvenil(
    $pdo,
    $id
);

$estadoConexion = $con["estado"];

$claseConexion = match ($con["color"]) {

    "danger" => "conexion-danger",

    "warning" => "conexion-warning",

    default => "conexion-ok"

};

/* =========================
   TOTAL SEGUIMIENTOS
========================= */

$stmt = $pdo->prepare("
    SELECT COUNT(*)

    FROM seguimientos

    WHERE joven_id = :id
");

$stmt->execute([
    "id" => $id
]);

$totalSeguimientos =
    (int)$stmt->fetchColumn();

/* =========================
   HISTORIAL DE SEGUIMIENTOS
   + EXCEPCIONES
========================= */

$historialJoven = obtenerHistorialJoven(
    $pdo,
    $id
);

$totalSeguimientos = count(
    $historialJoven
);

$seguimientos = array_slice(
    $historialJoven,
    0,
    5
);

/* =========================
   ESTADO ACTIVIDAD
========================= */

$estadoActividad = strtoupper(
    $joven["estado_actividad"] ?? "ACTIVO"
);

?>

<?php require_once __DIR__ . "/../../includes/header.php"; ?>


<!-- =====================================================
     PERFIL
===================================================== -->

<div class="ver-wrapper">

    <section class="perfil-card <?= $claseGenero ?>">

        <div class="gx-profile">

            <!-- ==========================================
                 HEADER
            =========================================== -->

            <div class="gx-profile__header">

                <div class="gx-profile__identity">

                    <h1 class="gx-profile__name">
                        <?= htmlspecialchars($joven["nombre_completo"]) ?>
                    </h1>

                    <div class="gx-profile__status">

                        <span class="perfil-conexion <?= $claseConexion ?>">

                            <i class="fa-solid fa-circle"></i>

                            <?= htmlspecialchars($estadoConexion) ?>

                        </span>

                    </div>

                </div>

                <div class="gx-profile__activity">

                    <?php if ($estadoActividad === "ACTIVO"): ?>

                        <span class="badge-activo">
                            Activo
                        </span>

                    <?php elseif ($estadoActividad === "INACTIVO"): ?>

                        <span class="badge-inactivo">
                            Inactivo
                        </span>

                    <?php else: ?>

                        <span class="badge-eliminado">
                            Eliminado
                        </span>

                    <?php endif; ?>

                    <a
                        href="<?= BASE_URL ?>/views/jovenes/perfil_pdf.php?id=<?= $id ?>"
                        target="_blank"
                        class="btn btn-pdf btn-sm <?= $claseGenero ?>"
                        title="Exportar el perfil del joven en PDF"
                    >
                        <i class="fa-solid fa-file-pdf"></i>
                        Perfil Joven
                    </a>

                </div>

            </div>

     <!-- ==========================================
     INFORMACIÓN GENERAL
========================================== -->

<section class="gx-profile__section">

    <h3 class="gx-profile__section-title">
        Información General
    </h3>

    <div class="gx-profile__rows">

        <article class="gx-profile__row">

            <span class="gx-profile__label">
                Edad
            </span>

            <span class="gx-profile__value">
                <?= $edad ?>
                <?= $edadAprox ? " (aprox.)" : "" ?>
            </span>

        </article>

        <article class="gx-profile__row">

            <span class="gx-profile__label">
                Género
            </span>

            <span class="gx-profile__value">
                <?= htmlspecialchars($joven["genero"] ?? "—") ?>
            </span>

        </article>

        <article class="gx-profile__row">

            <span class="gx-profile__label">
                Teléfono
            </span>

            <span class="gx-profile__value">
                <?= htmlspecialchars($joven["telefono"] ?: "No registrado") ?>
            </span>

        </article>

        <article class="gx-profile__row">

            <span class="gx-profile__label">
                Estado espiritual
            </span>

            <span class="gx-profile__value">

                <span class="estado <?= strtolower(htmlspecialchars($joven["estado_espiritual"] ?? "nuevo")) ?>">

                    <?= ucfirst(strtolower(htmlspecialchars($joven["estado_espiritual"] ?? "Nuevo"))) ?>

                </span>

            </span>

        </article>

        <article class="gx-profile__row">

            <span class="gx-profile__label">
                Fecha de ingreso
            </span>

            <span class="gx-profile__value">

                <?= !empty($joven["fecha_ingreso"])
                    ? date("d/m/Y", strtotime($joven["fecha_ingreso"]))
                    : "—" ?>

            </span>

        </article>

    </div>

</section>

     <!-- ==========================================
     ASISTENCIA JUVENIL (PERFIL DE ASISTENCIA -- PARTE 1)
========================================== -->

<section class="gx-profile__section">

    <h3 class="gx-profile__section-title">
        Asistencia Juvenil
    </h3>

    <!-- ==========================================
         RESUMEN (siempre visible) -- las métricas
         más consultadas primero; el detalle completo
         queda bajo demanda en el <details> de abajo.
    =========================================== -->

    <div class="gx-profile__rows gx-profile__rows--resumen">

        <article class="gx-profile__row">

            <span class="gx-profile__label">
                Racha actual de ausencias
            </span>

            <span class="gx-profile__value">
                <?= $perfilAsistencia["ausencias_consecutivas_actuales"] ?>
                <?= $perfilAsistencia["hay_desconocido_en_racha"]
                    ? " (con reuniones sin registro más atrás)"
                    : "" ?>
            </span>

        </article>

        <article class="gx-profile__row">

            <span class="gx-profile__label">
                Última asistencia
            </span>

            <span class="gx-profile__value">
                <?= $perfilAsistencia["ultima_asistencia"]
                    ? date("d/m/Y", strtotime($perfilAsistencia["ultima_asistencia"]))
                    : "Nunca ha asistido" ?>
            </span>

        </article>

        <article class="gx-profile__row">

            <span class="gx-profile__label">
                Porcentaje de asistencia (últimos 12 meses)
            </span>

            <span class="gx-profile__value">
                <?= $perfilAsistencia["porcentaje_asistencia_12_meses"] !== null
                    ? $perfilAsistencia["porcentaje_asistencia_12_meses"] . "%"
                    : "Sin datos" ?>
            </span>

        </article>

        <article class="gx-profile__row">

            <span class="gx-profile__label">
                Patrón intermitente
            </span>

            <span class="gx-profile__value">
                <?php if (!$perfilAsistencia["intermitencia_evaluable"]): ?>

                    Datos insuficientes

                <?php elseif ($perfilAsistencia["patron_intermitente"]): ?>

                    Sí

                <?php else: ?>

                    No

                <?php endif; ?>
            </span>

        </article>

    </div>

    <!-- ==========================================
         DETALLE COMPLETO (bajo demanda)
    =========================================== -->

    <details class="gx-profile__detalle">

        <summary>
            Ver detalle completo de asistencia y últimas 8 reuniones
        </summary>

    <div class="gx-profile__rows">

        <article class="gx-profile__row">

            <span class="gx-profile__label">
                Primera asistencia
            </span>

            <span class="gx-profile__value">
                <?= $perfilAsistencia["tiene_primera_asistencia"]
                    ? "Sí"
                    : "No" ?>
            </span>

        </article>

        <article class="gx-profile__row">

            <span class="gx-profile__label">
                Asistencias históricas
            </span>

            <span class="gx-profile__value">
                <?= $perfilAsistencia["total_historico"] ?>
            </span>

        </article>

        <article class="gx-profile__row">

            <span class="gx-profile__label">
                Asistencias últimos 12 meses
            </span>

            <span class="gx-profile__value">
                <?= $perfilAsistencia["ultimos_12_meses"] ?>
            </span>

        </article>

        <article class="gx-profile__row">

            <span class="gx-profile__label">
                Porcentaje de asistencia (histórico)
            </span>

            <span class="gx-profile__value">
                <?= $perfilAsistencia["porcentaje_asistencia_historico"] !== null
                    ? $perfilAsistencia["porcentaje_asistencia_historico"] . "%"
                    : "Sin datos" ?>
            </span>

        </article>

        <article class="gx-profile__row">

            <span class="gx-profile__label">
                Asistencia en últimas 8 reuniones
            </span>

            <span class="gx-profile__value">
                <?= $perfilAsistencia["asistencias_ultimas_8_reuniones"] ?>
                de las evaluables
                <?= $perfilAsistencia["porcentaje_ultimas_8_reuniones"] !== null
                    ? " (" . $perfilAsistencia["porcentaje_ultimas_8_reuniones"] . "%)"
                    : "" ?>
            </span>

        </article>

        <article class="gx-profile__row">

            <span class="gx-profile__label"></span>

            <span class="gx-profile__value" style="font-size: 0.85em; opacity: 0.75;">
                Basado en las últimas 8 reuniones juveniles registradas. No modifica el estado de actividad.
            </span>

        </article>

    </div>

    <!-- ==========================================
         ÚLTIMAS 8 REUNIONES
    =========================================== -->

    <h3 class="gx-profile__section-title">
        Últimas 8 reuniones
    </h3>

    <?php if (empty($perfilAsistencia["ultimas_8_reuniones"])): ?>

        <p class="gx-profile__value">
            No hay reuniones juveniles registradas todavía.
        </p>

    <?php else: ?>

        <div class="gx-profile__rows">

            <?php foreach ($perfilAsistencia["ultimas_8_reuniones"] as $reunionReciente): ?>

                <?php
                    $etiquetaEstado = match ($reunionReciente["estado"]) {
                        "PRESENTE" => "Presente",
                        "AUSENTE" => "Ausente",
                        default => "Sin registro",
                    };
                ?>

                <article class="gx-profile__row">

                    <span class="gx-profile__label">
                        <?= date("d/m/Y", strtotime($reunionReciente["fecha"])) ?>
                        — <?= htmlspecialchars($reunionReciente["tipo"]) ?>
                    </span>

                    <span class="gx-profile__value">
                        <?= $etiquetaEstado ?>
                    </span>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    </details>

</section>


        </div> <!-- /.gx-profile -->

    </section> <!-- /.perfil-card -->

</div> <!-- /.ver-wrapper -->

<!-- =====================================================
     OBSERVACIONES GENERALES
===================================================== -->

<section class="gx-card gx-card--soft">

    <div class="gx-card__header">

        <div>

            <h2>
                Observaciones generales
            </h2>

            <p>
                Información adicional registrada del joven.
            </p>

        </div>

    </div>

    <div class="gx-card__body">

        <?php if (!empty($joven["observaciones"])): ?>

            <p>

                <?= nl2br(
                    htmlspecialchars($joven["observaciones"])
                ) ?>

            </p>

        <?php else: ?>

            <div class="gx-empty">

                <i class="fa-solid fa-note-sticky"></i>

                <h3>
                    Sin observaciones
                </h3>

                <p>
                    No existen observaciones registradas para este joven.
                </p>

            </div>

        <?php endif; ?>

    </div>

</section>

<br>
<br>

<!-- =====================================================
     ÚLTIMOS SEGUIMIENTOS
===================================================== -->

<section class="gx-card gx-card--soft">

    <div class="gx-card__header gx-card__header--seguimientos">

        <div>

            <h2>
                Últimos seguimientos
            </h2>

            <p>
                Se muestran los <?= count($seguimientos) ?> seguimientos más recientes.
            </p>

        </div>

<?php if ($totalSeguimientos > 0): ?>

<a
    href="<?= BASE_URL ?>/views/seguimientos/historial.php?joven_id=<?= $id ?>"
    class="btn btn-outline"
>
    Ver historial completo (<?= $totalSeguimientos ?>)
</a>

<?php endif; ?>

    </div>

    <div class="gx-card__body">

        <?php if (!empty($seguimientos)): ?>

            <div class="gx-timeline">

               <?php foreach ($seguimientos as $s): ?>

    <article class="gx-timeline__item">

        <div class="gx-timeline__line"></div>

        <div class="gx-timeline__dot"></div>

        <div class="gx-timeline__content">

            <?php if ($s["tipo_registro"] === "EXCEPCION"): ?>

                <div class="gx-timeline__top">

                    <div>

                        <h4>
                            Excepción de seguimiento
                        </h4>

                        <small>
                            <i class="fa-regular fa-calendar"></i>

                            <?= date(
                                "d/m/Y",
                                strtotime($s["fecha_registro"])
                            ) ?>
                        </small>

                    </div>

                    <span class="estado excepcion">

            <i class="fa-solid fa-triangle-exclamation"></i>

            Excepción

        </span>

                </div>

                <div class="gx-timeline__meta">

                    <span>
                        <i class="fa-solid fa-circle-exclamation"></i>

                        Motivo:

                        <?= htmlspecialchars(
                            $s["excepcion_motivo"] ?? "No especificado"
                        ) ?>
                    </span>

                </div>

                <p>

                    <?= nl2br(
                        htmlspecialchars(
                            $s["observaciones"]
                            ?: "Sin observaciones registradas."
                        )
                    ) ?>

                </p>

                <div class="gx-timeline__meta">

                    <span>

                        <i class="fa-solid fa-user"></i>

                        <?= htmlspecialchars(
                            $s["responsable_nombre"]
                            ?? "Sin responsable"
                        ) ?>

                    </span>

                </div>

            <?php else: ?>

                <!-- SEGUIMIENTO REAL -->

                <div class="gx-timeline__top">

                    <div>

                        <h4>

                            <?= ucfirst(
                                strtolower(
                                    htmlspecialchars(
                                        $s["modalidad_contacto"]
                                    )
                                )
                            ) ?>

                        </h4>

                        <small>

                            <i class="fa-regular fa-calendar"></i>

                            <?= date(
                                "d/m/Y",
                                strtotime(
                                    $s["fecha_contacto"]
                                )
                            ) ?>

                        </small>

                    </div>

                    <span class="estado <?= strtolower(
                        str_replace(
                            "_",
                            "-",
                            $s["estado_proceso"]
                        )
                    ) ?>">

                        <?= ucfirst(
                            strtolower(
                                str_replace(
                                    "_",
                                    " ",
                                    $s["estado_proceso"]
                                )
                            )
                        ) ?>

                    </span>

                </div>

                <div class="gx-timeline__meta">

                    <span>

                        <i class="fa-solid fa-user"></i>

                        <?= htmlspecialchars(
                            $s["responsable_nombre"]
                            ?? "Sin responsable"
                        ) ?>

                    </span>

                </div>

                <p>

                    <?= nl2br(
                        htmlspecialchars(
                            $s["observaciones"]
                            ?: "Sin observaciones registradas."
                        )
                    ) ?>

                </p>

            <?php endif; ?>

        </div>

    </article>

<?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="gx-empty">

                <i class="fa-solid fa-notes-medical"></i>

                <h3>
                    Aún no hay seguimientos
                </h3>

                <p>
                    Este joven todavía no tiene seguimientos registrados.
                </p>

                <a
                    href="<?= BASE_URL ?>/views/seguimientos/crear.php?id=<?= $id ?>"
                    class="btn btn-primary">

                    Registrar primer seguimiento

                </a>

            </div>

        <?php endif; ?>

    </div>

</section>









 <!-- =====================================

     ACCIONES

====================================== -->



<div class="gx-actions">



    <div class="gx-actions__primary">

        <a

            href="<?= BASE_URL ?>/views/seguimientos/crear.php?id=<?= $id ?>"

            class="btn btn-primary"

        >

            <i class="fa-solid fa-plus"></i>

            Registrar seguimiento

        </a>



    </div>



    <div class="gx-actions__secondary">



        <a

            href="<?= BASE_URL ?>/views/jovenes/editar.php?id=<?= $id ?>"

            class="btn btn-primary"

        >

        

            Editar joven

        </a>



        <a

            href="<?= BASE_URL ?>/views/jovenes/historial.php?id=<?= $id ?>"

            class="btn btn-primary"

        >

           

            Historial

        </a>



        <a

            href="<?= BASE_URL ?>/views/jovenes/index.php"

            class="btn btn-primary"

        >

            Volver

        </a>



    </div>



</div>



  <!-- =====================================

     JAVASCRIPT

===================================== -->



<script

    src="<?= BASE_URL ?>/assets/js/modulos/jovenes/ver.js">

</script>



<?php



require_once __DIR__ . "/../../includes/footer.php";



?>