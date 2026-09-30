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

// Modelo único de actividad juvenil (4/12 ausencias consecutivas
// demostrables). El antiguo desglose Riesgo/Alto riesgo (umbrales
// 3/4 propios, solo "Grupo Conexión") fue retirado: "Inactivos" ya
// se muestra en el panel de jóvenes (dato persistido) y "Alerta" es
// la única categoría intermedia que existe en el modelo confirmado.
$alerta = $data['alerta'] ?? 0;

/* =====================================================
   INDICADORES POR PANEL
   -----------------------------------------------------
   Cada indicador es "stat-card" (components/_stats.scss)
   con una clase semántica (info/success/danger/warning/
   purple). Los enlaces ya usan el esquema nuevo de
   multi-filtro de jovenes/index.php (?estado=, ?riesgo[]=,
   ?espiritu[]=, ?caracteristica[]=).
===================================================== */

$jovenesUrl = BASE_URL . '/views/jovenes/index.php';

$totalJovenesResumen = (int) ($resumen['totalJovenes'] ?? 0);

$pct = function (int $valor) use ($totalJovenesResumen): ?string {
    if ($totalJovenesResumen <= 0) {
        return null;
    }
    return round(($valor / $totalJovenesResumen) * 100, 1) . '% del total';
};

$panelJovenes = [
    ['titulo' => 'Total Jóvenes', 'valor' => $totalJovenesResumen, 'clase' => 'info', 'contexto' => 'Registrados', 'href' => $jovenesUrl . '?estado=todos'],
    ['titulo' => 'Activos', 'valor' => $resumen['activos'] ?? 0, 'clase' => 'success', 'contexto' => $pct((int) ($resumen['activos'] ?? 0)), 'href' => $jovenesUrl . '?estado=activos'],
    ['titulo' => 'Inactivos', 'valor' => $resumen['inactivos'] ?? 0, 'clase' => 'danger', 'contexto' => $pct((int) ($resumen['inactivos'] ?? 0)), 'href' => $jovenesUrl . '?estado=inactivos'],
    ['titulo' => 'Nuevos (≤3 meses)', 'valor' => $resumen['nuevos'] ?? 0, 'clase' => 'purple', 'contexto' => $pct((int) ($resumen['nuevos'] ?? 0)), 'href' => $jovenesUrl . '?caracteristica[]=nuevos'],
    ['titulo' => 'Antiguos (>3 meses)', 'valor' => $resumen['antiguos'] ?? 0, 'clase' => 'info', 'contexto' => $pct((int) ($resumen['antiguos'] ?? 0)), 'href' => $jovenesUrl . '?caracteristica[]=antiguos'],
];

// Panel de atención, ordenado por severidad (más urgente primero).
// "Requieren atención (discipulado)" vive aquí, junto a las demás
// señales de "esto necesita revisión humana" — distinto y
// claramente diferenciado de "Ciclos activos" (panel de Ministerio).
$panelAtencion = [
    ['titulo' => 'Alerta (4-11 ausencias)', 'valor' => $alerta, 'clase' => 'warning', 'icono' => 'fa-triangle-exclamation', 'href' => $jovenesUrl . '?riesgo[]=alerta'],
    ['titulo' => 'Seguimiento pendiente', 'valor' => $resumen['seguimientoPendiente'] ?? 0, 'clase' => 'info', 'icono' => 'fa-user-clock', 'href' => BASE_URL . '/views/seguimientos/asignaciones.php?anio=' . date('Y') . '&mes=' . date('n')],
    ['titulo' => 'Requieren atención (discipulado)', 'valor' => $resumen['discipuladoAtencion'] ?? 0, 'clase' => 'purple', 'icono' => 'fa-graduation-cap', 'href' => BASE_URL . '/views/formacion/discipulado/index.php?estado=ACTIVO'],
];

// Ministerio/operación: Congregantes y Discipulado se depuraron de
// aquí a propósito — ya están representados en la dona de Estado
// espiritual de abajo; mostrarlos también como tarjeta sería
// duplicar el mismo dato dos veces en la misma pantalla.
$panelMinisterio = [
    // Servidores = usuarios activos registrados en la plataforma
    // (administradora, líder, sublíder, usuarios independientes).
    // Ya no se cuenta desde la ficha de los jóvenes.
    ['titulo' => 'Servidores del Ministerio', 'valor' => $resumen['servidoresLideresJovenes'] ?? 0, 'clase' => 'info', 'href' => BASE_URL . '/views/usuarios/index.php'],
    ['titulo' => 'Ciclos activos', 'valor' => $resumen['ciclosDiscipuladoActivos'] ?? 0, 'clase' => 'success', 'href' => BASE_URL . '/views/formacion/discipulado/index.php?estado=ACTIVO'],
];

$panelReuniones = [
    ['titulo' => 'Reuniones realizadas', 'valor' => $resumen['reuniones'] ?? 0, 'clase' => 'info', 'href' => BASE_URL . '/views/reuniones/index.php'],
    // Sin "href": es un promedio, no un grupo de registros navegable.
    ['titulo' => 'Asistencia promedio', 'valor' => ($resumen['asistencia'] ?? 0) . '%', 'clase' => 'success', 'href' => null],
];

/* =====================================================
   DONA DE ESTADO CONGREGACIONAL (CSS puro, sin librerías)
   -----------------------------------------------------
   Fase 6: ya NO son 5 categorías -- jovenes.estado_espiritual
   quedó restringido a NUEVO/CONGREGANTE (jovenService.php
   ESTADOS_CONGREGACIONALES). Discipulado y Servidor/Líder ya
   no viven aquí (ver tarjeta "Servidores del Ministerio de
   Jóvenes" más abajo, ahora desde Usuarios). Se calculan los
   cortes del conic-gradient a partir de datos reales; si no
   hay ningún joven todavía, se muestra un círculo vacío en
   vez de inventar proporciones.
===================================================== */

$distribucionEspiritual = [
    ['clave' => 'nuevo', 'etiqueta' => 'Nuevo', 'color' => '#3b82f6', 'filtro' => 'nuevo_espiritual'],
    ['clave' => 'congregante', 'etiqueta' => 'Congregante', 'color' => '#22c55e', 'filtro' => 'congregantes'],
];

$totalEspiritual = array_sum($resumen['porEstadoEspiritual'] ?? []);

$acumulado = 0;
$segmentosDona = [];

foreach ($distribucionEspiritual as $categoria) {

    $valor = (int) ($resumen['porEstadoEspiritual'][$categoria['clave']] ?? 0);

    $porcentaje = $totalEspiritual > 0 ? ($valor / $totalEspiritual) * 100 : 0;

    $inicio = $acumulado;
    $acumulado += $porcentaje;

    $segmentosDona[] = $categoria['color'] . ' ' . $inicio . '% ' . $acumulado . '%';

    $distribucionEspiritual[array_search($categoria, $distribucionEspiritual)]['valor'] = $valor;
}

$gradienteDona = $totalEspiritual > 0
    ? 'conic-gradient(' . implode(', ', $segmentosDona) . ')'
    : 'conic-gradient(var(--border-color) 0% 100%)';

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
           JÓVENES — tira compacta (un solo contenedor, 5 segmentos)
        ===================================================== -->

        <div class="metric-strip">

            <?php foreach ($panelJovenes as $item): ?>

            <?php $tag = !empty($item['href']) ? 'a' : 'div'; ?>

            <<?= $tag ?>
                class="metric-strip__item <?= htmlspecialchars($item['clase']) ?>"
                <?= !empty($item['href']) ? 'href="' . htmlspecialchars($item['href']) . '"' : '' ?>
            >

                <span class="metric-strip__value"><?= $item['valor'] ?></span>

                <span class="metric-strip__label"><?= htmlspecialchars($item['titulo']) ?></span>

                <?php if (!empty($item['contexto'])): ?>

                <span class="metric-strip__contexto"><?= htmlspecialchars($item['contexto']) ?></span>

                <?php endif; ?>

            </<?= $tag ?>>

            <?php endforeach; ?>

        </div>

        <!-- =====================================================
           FILA PRINCIPAL — Estado espiritual (dona grande) +
           panel de atención jerarquizado, lado a lado
        ===================================================== -->

        <div class="dashboard-row">

            <div class="page-section dashboard-row__dona">

                <h2 class="page-section-title">
                    Estado espiritual
                </h2>

                <div class="dona-panel">

                    <div class="dona dona--grande" style="background:<?= $gradienteDona ?>">

                        <div class="dona__hueco dona__hueco--grande">

                            <span class="dona__total"><?= $totalEspiritual ?></span>

                            <span class="dona__total-label">jóvenes</span>

                        </div>

                    </div>

                    <ul class="dona__leyenda">

                        <?php foreach ($distribucionEspiritual as $categoria): ?>

                        <?php $porcentajeCategoria = $totalEspiritual > 0 ? round(($categoria['valor'] / $totalEspiritual) * 100, 1) : 0; ?>

                        <li>

                        <?php if (!empty($categoria['filtro'])): ?>
                            <a class="dona__leyenda-link" href="<?= htmlspecialchars($jovenesUrl . '?espiritu[]=' . $categoria['filtro']) ?>">
                        <?php else: ?>
                            <span class="dona__leyenda-link">
                        <?php endif; ?>

                            <span class="dona__punto" style="background:<?= $categoria['color'] ?>"></span>

                            <span class="dona__leyenda-etiqueta"><?= htmlspecialchars($categoria['etiqueta']) ?></span>

                            <span class="dona__leyenda-barra">
                                <span class="dona__leyenda-barra-relleno" style="width:<?= $porcentajeCategoria ?>%;background:<?= $categoria['color'] ?>"></span>
                            </span>

                            <span class="dona__leyenda-valor"><?= (int) $categoria['valor'] ?></span>

                            <span class="dona__leyenda-porcentaje">
                                <?= $totalEspiritual > 0 ? $porcentajeCategoria . '%' : '—' ?>
                            </span>

                        <?= !empty($categoria['filtro']) ? '</a>' : '</span>' ?>

                        </li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            </div>

            <div class="page-section dashboard-row__atencion">

                <h2 class="page-section-title">
                    Actividad, riesgo y seguimiento
                </h2>

                <div class="attention-panel">

                    <?php foreach ($panelAtencion as $item): ?>

                    <?php $tag = !empty($item['href']) ? 'a' : 'div'; ?>

                    <<?= $tag ?>
                        class="attention-panel__row <?= htmlspecialchars($item['clase']) ?>"
                        <?= !empty($item['href']) ? 'href="' . htmlspecialchars($item['href']) . '"' : '' ?>
                    >

                        <span class="attention-panel__icon">
                            <i class="fa-solid <?= htmlspecialchars($item['icono']) ?>"></i>
                        </span>

                        <span class="attention-panel__label"><?= htmlspecialchars($item['titulo']) ?></span>

                        <span class="attention-panel__value"><?= $item['valor'] ?></span>

                    </<?= $tag ?>>

                    <?php endforeach; ?>

                </div>

            </div>

        </div>

        <!-- =====================================================
           MINISTERIO / OPERACIÓN — 3 indicadores, menor jerarquía
           (Congregantes/Discipulado ya están en la dona de arriba,
           no se repiten aquí)
        ===================================================== -->

        <?php if (!empty($cumpleanosPanel)): ?>

        <!-- =====================================================
             CUMPLEAÑOS (solo para usuarios marcados para recibir
             la alerta) -- hoy y próximos 7 días
        ====================================================== -->

        <div class="page-section cumpleanos-panel">

            <h2 class="page-section-title">
                <i class="fa-solid fa-cake-candles"></i>
                Cumpleaños de hoy y próximos 7 días
            </h2>

            <ul class="cumpleanos-lista">

                <?php foreach ($cumpleanosPanel as $cumple): ?>

                <li class="cumpleanos-item <?= $cumple['dias_faltan'] === 0 ? 'cumpleanos-item--hoy' : '' ?>">

                    <a href="<?= BASE_URL ?>/views/jovenes/ver.php?id=<?= (int) $cumple['id'] ?>">
                        <?= htmlspecialchars($cumple['nombre_completo']) ?>
                    </a>

                    <span class="cumpleanos-item__detalle">
                        cumple <?= (int) $cumple['cumple'] ?> años --
                        <?php if ($cumple['dias_faltan'] === 0): ?>
                            <strong>hoy</strong>
                        <?php elseif ($cumple['dias_faltan'] === 1): ?>
                            mañana
                        <?php else: ?>
                            en <?= (int) $cumple['dias_faltan'] ?> días (<?= date('d/m', strtotime($cumple['fecha_cumpleanos'])) ?>)
                        <?php endif; ?>
                    </span>

                </li>

                <?php endforeach; ?>

            </ul>

        </div>

        <?php endif; ?>

        <!-- =====================================================
             REPORTES (mensual / trimestral / anual) -- descarga en
             Excel o PDF; el mes y el año actuales por defecto
        ====================================================== -->

        <div class="page-section">

            <h2 class="page-section-title">
                <i class="fa-solid fa-file-lines"></i>
                Reportes
            </h2>

            <div class="dashboard-reportes">

                <p class="dashboard-reportes__texto">
                    Resumen de jóvenes, reuniones, asistencia, servidores, discipulado
                    y seguimientos, listo para entregar. También puedes cerrar el año
                    y consultar los cierres anteriores.
                </p>

                <?php
                $urlRep = static fn(string $formato, string $tipo): string =>
                    BASE_URL . '/controllers/reporteController.php?' . http_build_query([
                        'action' => 'descargar_reporte',
                        'formato' => $formato,
                        'tipo' => $tipo,
                        'anio' => (int) date('Y'),
                        'mes' => (int) date('n'),
                    ]);
                ?>

                <a href="<?= htmlspecialchars($urlRep('xlsx', 'mensual')) ?>" class="btn btn-secondary btn-sm">
                    <i class="fa-solid fa-file-excel"></i> Mes actual (Excel)
                </a>

                <a href="<?= htmlspecialchars($urlRep('pdf', 'mensual')) ?>" class="btn btn-secondary btn-sm">
                    <i class="fa-solid fa-file-pdf"></i> Mes actual (PDF)
                </a>

                <a href="<?= BASE_URL ?>/views/reportes/index.php" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-chart-column"></i> Más reportes y cierre anual
                </a>

            </div>

        </div>

        <div class="page-section">

            <h2 class="page-section-title">
                Ministerio y operación
            </h2>

            <div class="stats-grid stats-grid--mini">

                <?php foreach ($panelMinisterio as $item): ?>

                <?php $tag = !empty($item['href']) ? 'a' : 'div'; ?>

                <<?= $tag ?>
                    class="stat-card stat-card--mini <?= htmlspecialchars($item['clase']) ?>"
                    <?= !empty($item['href']) ? 'href="' . htmlspecialchars($item['href']) . '"' : '' ?>
                >

                    <span class="stat-number"><?= $item['valor'] ?></span>

                    <span class="stat-label"><?= htmlspecialchars($item['titulo']) ?></span>

                </<?= $tag ?>>

                <?php endforeach; ?>

            </div>

        </div>

        <!-- =====================================================
           REUNIONES Y ASISTENCIA — tira compacta de cierre
        ===================================================== -->

        <div class="metric-strip metric-strip--cierre">

            <?php foreach ($panelReuniones as $item): ?>

            <?php $tag = !empty($item['href']) ? 'a' : 'div'; ?>

            <<?= $tag ?>
                class="metric-strip__item <?= htmlspecialchars($item['clase']) ?>"
                <?= !empty($item['href']) ? 'href="' . htmlspecialchars($item['href']) . '"' : '' ?>
            >

                <span class="metric-strip__value"><?= $item['valor'] ?></span>

                <span class="metric-strip__label"><?= htmlspecialchars($item['titulo']) ?></span>

            </<?= $tag ?>>

            <?php endforeach; ?>

        </div>

    </div>

</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>