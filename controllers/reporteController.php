<?php

declare(strict_types=1);

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/../services/reporteExportService.php';

controllerInit();

$pdo = controllerPdo();

/* ==========================================================
   DESCARGA (GET) -- respuesta de archivo, no JSON/redirect.
   Mismo permiso que el Dashboard, donde vive el componente.
========================================================== */

if (
    controllerAction() === 'descargar_reporte'
    && strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
) {

    controllerRequirePermission('ver_dashboard');

    $formato = strtolower((string) ($_GET['formato'] ?? ''));

    if (!in_array($formato, ['xlsx', 'pdf'], true)) {

        http_response_code(400);

        exit('Formato inválido.');
    }

    try {

        $cierreId = (int) ($_GET['cierre_id'] ?? 0);

        if ($cierreId > 0) {

            $reporte = obtenerCierreAnual($pdo, $cierreId);

            if ($reporte === null) {

                http_response_code(404);

                exit('Cierre no encontrado.');
            }

        } else {

            $tipo = strtolower((string) ($_GET['tipo'] ?? ''));

            $mes = isset($_GET['mes']) && $_GET['mes'] !== ''
                ? (int) $_GET['mes']
                : null;

            $reporte = generarDatosReporte(
                $pdo,
                $tipo,
                (int) ($_GET['anio'] ?? 0),
                $mes
            );
        }

    } catch (InvalidArgumentException $e) {

        http_response_code(400);

        exit($e->getMessage());
    }

    if ($formato === 'pdf') {
        exportarReportePdf($reporte);
    }

    exportarReporteXlsx($reporte);
}

/* ==========================================================
   CERRAR AÑO (POST) -- solo administración. Solo AGREGA una
   fotografía; no modifica ni borra datos del ministerio.
========================================================== */

if (controllerAction() === 'cerrar_anio') {

    try {

        controllerRequireMethod('POST');

        controllerValidateCsrf();

        if (!esAdmin()) {

            redirect(
                BASE_URL . '/views/reportes/index.php',
                'error',
                'Solo la administración puede cerrar un año.'
            );

            exit;
        }

        guardarCierreAnual(
            $pdo,
            (int) ($_POST['anio'] ?? 0),
            (int) usuarioId(),
            (string) (usuarioNombre() ?? '')
        );

        redirect(
            BASE_URL . '/views/reportes/index.php',
            'success',
            'Año cerrado. La fotografía quedó guardada y se puede descargar cuando quieras.'
        );

    } catch (RuntimeException | InvalidArgumentException $e) {

        // Mensajes propios de guardarCierreAnual (año ya cerrado, migración
        // pendiente, año futuro): seguros de mostrar tal cual.
        redirect(
            BASE_URL . '/views/reportes/index.php',
            'error',
            $e->getMessage()
        );

    } catch (Throwable $e) {

        error_log('Cierre anual: ' . get_class($e));

        redirect(
            BASE_URL . '/views/reportes/index.php',
            'error',
            'No fue posible cerrar el año. Intenta de nuevo o revisa el registro de errores del servidor.'
        );
    }

    exit;
}

http_response_code(400);

exit('Acción inválida.');
