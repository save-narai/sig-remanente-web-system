<?php

declare(strict_types=1);

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/../services/formacionService.php';
require_once __DIR__ . '/../services/formacionImportadorXlsxService.php';

controllerInit();

$pdo = controllerPdo();

$configFormacion = require __DIR__ . '/../config/formacion.php';

/* ==========================================================
   PLANTILLA XLSX -- descarga directa (no pasa por
   controllerRun, es una respuesta de archivo, no JSON/redirect)
========================================================== */

if (
    controllerAction() === 'descargar_plantilla_formacion'
    && strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
) {

    controllerRequirePermission('gestionar_formacion');

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {

        http_response_code(500);

        exit(
            'PhpSpreadsheet no está instalado todavía en este servidor. ' .
            'Ejecuta "composer install" (ver composer.json) antes de descargar la plantilla.'
        );
    }

    $spreadsheet = generarPlantillaXlsxFormacion();

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter(
        $spreadsheet,
        'Xlsx'
    );

    header(
        'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    header(
        'Content-Disposition: attachment; filename="plantilla-importacion-formacion.xlsx"'
    );

    header('X-Content-Type-Options: nosniff');

    $writer->save('php://output');

    exit;
}

/* ==========================================================
   IMPORTAR ARCHIVO XLSX -- involucra $_FILES, no encaja en el
   patrón controllerRun (pensado para $_POST simple)
========================================================== */

if (controllerAction() === 'importar_xlsx_formacion') {

    controllerRequireMethod('POST');

    controllerValidateCsrf();

    controllerRequirePermission('gestionar_formacion');

    try {

        $resultado = procesarArchivoXlsxFormacion(
            $pdo,
            $_FILES['archivo'] ?? [],
            (int) usuarioId(),
            $configFormacion['importacion_xlsx']
        );

        if (!$resultado['exito']) {

            $faltantes = implode(
                ', ',
                $resultado['comparacion']['faltantes_obligatorias']
            );

            redirect(
                BASE_URL . '/views/formacion/importar.php',
                'error',
                'El archivo no cumple el contrato de importación. Faltan columnas obligatorias: ' . $faltantes
            );

        }

        redirect(
            BASE_URL . '/views/formacion/previsualizar.php?importacion_id=' . $resultado['importacion_id'],
            'success',
            'Archivo procesado. Revisa la previsualización antes de confirmar.'
        );

    } catch (Throwable $e) {

        redirect(
            BASE_URL . '/views/formacion/importar.php',
            'error',
            $e->getMessage()
        );

    }

    exit;
}

/* ==========================================================
   RESTO DE ACCIONES (confirmar / descartar) -- estas sí
   encajan en el patrón estándar controllerRun
========================================================== */

controllerRun(

    [

        'confirmar_importacion_formacion' => function () use ($pdo) {

            controllerRequirePermission('gestionar_formacion');

            $importacionId = (int) ($_POST['importacion_id'] ?? 0);

            $resultado = confirmarImportacionFormacion(
                $pdo,
                $importacionId
            );

            return controllerRedirect(
                '../views/formacion/index.php',
                'Importación confirmada. ' . $resultado['filas_aplicadas'] . ' registro(s) aplicado(s).'
            );

        },

        'descartar_importacion_formacion' => function () use ($pdo) {

            controllerRequirePermission('gestionar_formacion');

            $importacionId = (int) ($_POST['importacion_id'] ?? 0);

            descartarImportacionFormacion(
                $pdo,
                $importacionId
            );

            return controllerRedirect(
                '../views/formacion/importar.php',
                'Importación descartada. No se aplicó ningún cambio.'
            );

        },

    ]

);
