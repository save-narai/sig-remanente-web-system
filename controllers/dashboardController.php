<?php

declare(strict_types=1);

require_once __DIR__ . '/controller.php';

require_once __DIR__ . '/../services/actividadService.php';
require_once __DIR__ . '/../services/jovenService.php';
require_once __DIR__ . '/../services/dashboardService.php';
require_once __DIR__ . '/../services/cumpleanosService.php';

controllerInit();

controllerRequirePermission('ver_dashboard');

$pdo = controllerPdo();

/* =========================================================
   ACTIVIDAD
========================================================= */

actualizarEstadoActividad($pdo);
actualizarEstadoCongregacional($pdo);

/* =========================================================
   DASHBOARD
========================================================= */

$data = obtenerDashboardData($pdo);

/* =========================================================
   CUMPLEAÑOS
   ---------------------------------------------------------
   Dispara (una sola vez al día) el correo de alerta a los
   usuarios marcados, y prepara el panel del dashboard para
   ellos. Blindado: si falta la migración o falla el correo,
   el dashboard se muestra igual.
========================================================= */

$cumpleanosPanel = [];

try {

    procesarAlertaCumpleanosDelDia($pdo);

    if (
        function_exists('usuarioId')
        && usuarioId() !== null
        && usuarioRecibeAlertasCumpleanos($pdo, (int) usuarioId())
    ) {
        $cumpleanosPanel = obtenerProximosCumpleanos($pdo, 7);
    }

} catch (Throwable $e) {

    error_log('Cumpleaños (dashboard): ' . get_class($e));
}
