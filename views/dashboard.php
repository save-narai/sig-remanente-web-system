<?php

date_default_timezone_set('America/Bogota');

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/format.php';
require_once __DIR__ . '/../helpers/ui.php';
require_once __DIR__ . '/../controllers/dashboardController.php';

$config = require __DIR__ . '/../config/app.php';

/* =====================================================
   USER
===================================================== */

$nombre = usuarioNombre() ?? 'Usuario';

$rol = usuarioRol() ?? 'USER';

/* =====================================================
   UI
===================================================== */

$momento = saludoActual();

$rolLabel = nombreRol($rol);

/* =====================================================
   DATA
===================================================== */

$resumen = $data['resumen'] ?? [];

$graficas = $data['graficas'] ?? [
    'mensual' => [],
    'tipos'   => [],
    'estado'  => []
];

$alertas = $data['alertas'] ?? 0;
$riesgo   = $data['riesgo'] ?? 0;
$alto     = $data['alto'] ?? 0;

/* =====================================================
   CARDS
===================================================== */

$cards = [

    [
        "titulo" => "Total Jóvenes",
        "valor"  => $resumen['totalJovenes'] ?? 0,
        "icono"  => "fa-users",
        "extra"  => "Registrados",
        "href"   => BASE_URL . "/views/jovenes/index.php?filtro=todos"
    ],

    [
        "titulo" => "Activos",
        "valor"  => $resumen['activos'] ?? 0,
        "icono"  => "fa-user-check",
        "extra"  => "Actualmente",
        "href"   => BASE_URL . "/views/jovenes/index.php?filtro=activos"
    ],

    [
        "titulo" => "Inactivos",
        "valor"  => $resumen['inactivos'] ?? 0,
        "icono"  => "fa-user-xmark",
        "extra"  => "Sin actividad",
        "href"   => BASE_URL . "/views/jovenes/index.php?filtro=inactivos"
    ],

    // NOTA: esta tarjeta "Servidores" sigue viniendo de es_servidor,
    // sin ningún cambio (ver informe de Etapa 3 sobre qué hacer con
    // ella frente a la nueva "Servidores/Líderes de Jóvenes").
    [
        "titulo" => "Servidores (todos los ministerios)",
        "valor"  => $resumen['servidores'] ?? 0,
        "icono"  => "fa-hands-praying",
        "extra"  => "Activos"
    ],

    [
        "titulo" => "Reuniones",
        "valor"  => $resumen['reuniones'] ?? 0,
        "icono"  => "fa-calendar",
        "extra"  => "Realizadas"
    ],

    [
        "titulo" => "Asistencia",
        "valor"  => ($resumen['asistencia'] ?? 0) . '%',
        "icono"  => "fa-chart-line",
        "extra"  => "Promedio"
    ],

    [
        "titulo" => "Nuevos",
        "valor"  => $resumen['nuevos'] ?? 0,
        "icono"  => "fa-user-plus",
        "extra"  => "Ingreso ≤ 3 meses",
        "href"   => BASE_URL . "/views/jovenes/index.php?filtro=nuevos"
    ],

    [
        "titulo" => "Antiguos",
        "valor"  => $resumen['antiguos'] ?? 0,
        "icono"  => "fa-user-clock",
        "extra"  => "Registrados"
    ],

    // ---- Nuevos indicadores (Fase 2 - Etapa 3) ----

    [
        "titulo" => "En riesgo",
        "valor"  => $riesgo,
        "icono"  => "fa-triangle-exclamation",
        "extra"  => "Baja asistencia reciente",
        "href"   => BASE_URL . "/views/jovenes/index.php?filtro=riesgo2"
    ],

    [
        "titulo" => "Alto riesgo",
        "valor"  => $alto,
        "icono"  => "fa-circle-exclamation",
        "extra"  => "2 meses sin asistir",
        "href"   => BASE_URL . "/views/jovenes/index.php?filtro=riesgo3"
    ],

    [
        "titulo" => "Congregantes",
        "valor"  => $resumen['porEstadoEspiritual']['congregante'] ?? 0,
        "icono"  => "fa-church",
        "extra"  => "Estado espiritual",
        "href"   => BASE_URL . "/views/jovenes/index.php?filtro=congregantes"
    ],

    [
        "titulo" => "Discipulado",
        "valor"  => $resumen['porEstadoEspiritual']['discipulado'] ?? 0,
        "icono"  => "fa-book-bible",
        "extra"  => "Estado espiritual",
        "href"   => BASE_URL . "/views/jovenes/index.php?filtro=discipulado"
    ],

    [
        "titulo" => "Servidores/Líderes de Jóvenes",
        "valor"  => $resumen['servidoresLideresJovenes'] ?? 0,
        "icono"  => "fa-star",
        "extra"  => "Provisional (estado espiritual)",
        "href"   => BASE_URL . "/views/jovenes/index.php?filtro=servidores_lideres"
    ],

    [
        "titulo" => "Seguimiento pendiente",
        "valor"  => $resumen['seguimientoPendiente'] ?? 0,
        "icono"  => "fa-user-clock",
        "extra"  => "Jóvenes nuevos sin asignar",
        "href"   => BASE_URL . "/views/seguimientos/asignaciones.php?anio=" . date('Y') . "&mes=" . date('n')
    ],

    [
        "titulo" => "Atención de discipulado",
        "valor"  => $resumen['discipuladoAtencion'] ?? 0,
        "icono"  => "fa-graduation-cap",
        "extra"  => "Ciclos activos",
        "href"   => BASE_URL . "/views/formacion/discipulado/index.php?estado=ACTIVO"
    ]
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page">
    <!-- =====================================================
       HEADER
    ===================================================== -->

    <div class="page-header">

        <div class="page-header-left">

            <h1 class="page-title">

                Panel Principal

            </h1>

            <div class="page-subtitle">

                <?= $momento ?>, <?= $rolLabel ?>

            </div>

        </div>

        <div class="page-header-right">

            <span class="topbar-user">

                <?= htmlspecialchars($nombre) ?>

            </span>

            <a
                href="<?= BASE_URL ?>/views/jovenes/crear.php"
                class="btn btn-primary"
            >

                <i class="fa-solid fa-plus"></i>

                <?= $config['textos']['nuevo_joven'] ?>

            </a>

        </div>

    </div>

    <!-- =====================================================
       CONTENT
    ===================================================== -->

    <div class="page-content">

        <!-- =====================================================
           ALERTA
        ===================================================== -->

        <?php if ($alertas > 0): ?>

        <div class="alerta-dashboard">

            <i class="fa-solid fa-triangle-exclamation"></i>

            <?= $riesgo ?> en riesgo •

            <?= $alto ?> en alto riesgo

        </div>

        <?php endif; ?>

        <!-- =====================================================
           SECTION
        ===================================================== -->

        <div class="page-section">

            <h2 class="page-section-title">

                Resumen General

            </h2>

            <!-- =====================================================
               CARDS
            ===================================================== -->

            <div class="dashboard__cards">

                <?php foreach($cards as $card): ?>

                <?php $tag = isset($card['href']) ? 'a' : 'div'; ?>

                <<?= $tag ?>
                    class="dashboard__card<?= isset($card['href']) ? ' dashboard__card--link' : '' ?>"
                    <?= isset($card['href']) ? 'href="' . htmlspecialchars($card['href']) . '"' : '' ?>
                >

                    <div class="dashboard__card-top">

                        <div class="dashboard__card-icon">

                            <i class="fa-solid <?= $card['icono'] ?>"></i>

                        </div>

                        <div class="dashboard__card-title">

                            <?= $card['titulo'] ?>

                        </div>

                    </div>

                    <div class="dashboard__card-body">

                        <div class="dashboard__card-value">

                            <?= $card['valor'] ?>

                        </div>

                        <div class="dashboard__card-extra">

                            <?= $card['extra'] ?>

                        </div>

                    </div>

                </<?= $tag ?>>

                <?php endforeach; ?>

            </div>

        </div>

    </div>

</div>



<script src="<?= BASE_URL ?>/assets/js/modulos/dashboard/dashboard.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>