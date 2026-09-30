<?php

declare(strict_types=1);

require_once __DIR__ . '/reporteService.php';

/* ==========================================================
   EXPORTACIÓN DE REPORTES
   ----------------------------------------------------------
   Excel (PhpSpreadsheet) y PDF (Dompdf) -- ambas librerías ya
   están declaradas en composer.json. Si "composer install" no
   se ha ejecutado en el servidor, se devuelve un mensaje claro
   en vez de un error fatal.

   Excel, PDF y la vista en pantalla leen la MISMA lista de
   secciones (reporteSecciones), así nunca difieren.
========================================================== */

function reporteNombreArchivo(array $reporte, string $extension): string
{
    $meta = $reporte['meta'];

    $base = 'reporte-' . $meta['tipo'] . '-' . $meta['anio'];

    if (!empty($meta['mes'])) {
        $base .= '-' . str_pad((string) $meta['mes'], 2, '0', STR_PAD_LEFT);
    }

    return $base . '.' . $extension;
}

function reporteTitulo(array $reporte): string
{
    $meta = $reporte['meta'];

    $etiqueta = match ($meta['tipo']) {
        'mensual' => 'Reporte mensual',
        'trimestral' => 'Reporte trimestral',
        default => 'Reporte anual',
    };

    return $etiqueta . ' -- ' . $meta['periodo_texto'];
}

function reporteLibreriaDisponible(string $clase): bool
{
    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (is_file($autoload)) {
        require_once $autoload;
    }

    return class_exists($clase);
}

/* ==========================================================
   HTML (lo usa el PDF; mismo aspecto sobrio que el resto)
========================================================== */

function reporteHtml(array $reporte): string
{
    $meta = $reporte['meta'];

    $html = '<html><head><meta charset="UTF-8"><style>'
        . 'body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#1f2937}'
        . 'h1{font-size:18px;margin:0 0 4px}'
        . 'h2{font-size:13px;margin:18px 0 6px;color:#c2410c}'
        . 'p.sub{margin:0 0 12px;color:#6b7280}'
        . 'p.nota{margin:4px 0 0;color:#6b7280;font-size:9px}'
        . 'table{width:100%;border-collapse:collapse}'
        . 'th{background:#f97316;color:#fff;text-align:left;padding:6px 8px;font-size:10px}'
        . 'td{padding:6px 8px;border-bottom:1px solid #e5e7eb}'
        . '</style></head><body>';

    $html .= '<h1>' . htmlspecialchars(reporteTitulo($reporte), ENT_QUOTES, 'UTF-8') . '</h1>';

    $subtitulo = 'Período: ' . $meta['inicio'] . ' a ' . $meta['fin']
        . ' · Generado: ' . $meta['generado_en'];

    if (($meta['origen'] ?? '') === 'cierre') {
        $subtitulo .= ' · Cierre anual guardado el ' . ($meta['cerrado_en'] ?? '')
            . ' por ' . ($meta['cerrado_por'] ?? '--');
    }

    $html .= '<p class="sub">' . htmlspecialchars($subtitulo, ENT_QUOTES, 'UTF-8') . '</p>';

    foreach (reporteSecciones($reporte) as $seccion) {

        $html .= '<h2>' . htmlspecialchars($seccion['titulo'], ENT_QUOTES, 'UTF-8') . '</h2>';

        if (empty($seccion['filas'])) {

            $html .= '<p class="nota">' . htmlspecialchars((string) $seccion['nota'], ENT_QUOTES, 'UTF-8') . '</p>';

            continue;
        }

        $html .= '<table><thead><tr>';

        foreach ($seccion['columnas'] as $col) {
            $html .= '<th>' . htmlspecialchars($col, ENT_QUOTES, 'UTF-8') . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($seccion['filas'] as $fila) {

            $html .= '<tr>';

            foreach ($fila as $celda) {
                $html .= '<td>' . htmlspecialchars((string) $celda, ENT_QUOTES, 'UTF-8') . '</td>';
            }

            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        if (!empty($seccion['nota'])) {
            $html .= '<p class="nota">' . htmlspecialchars($seccion['nota'], ENT_QUOTES, 'UTF-8') . '</p>';
        }
    }

    return $html . '</body></html>';
}

/* ==========================================================
   PDF
========================================================== */

function exportarReportePdf(array $reporte): void
{
    if (!reporteLibreriaDisponible(\Dompdf\Dompdf::class)) {

        http_response_code(500);

        exit(
            'Dompdf no está instalado todavía en este servidor. '
            . 'Ejecuta "composer install" antes de descargar el PDF.'
        );
    }

    $options = new \Dompdf\Options();

    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->setDefaultFont('DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);

    $dompdf->loadHtml(reporteHtml($reporte));

    $dompdf->setPaper('A4', 'portrait');

    $dompdf->render();

    $dompdf->stream(reporteNombreArchivo($reporte, 'pdf'), ['Attachment' => true]);

    exit;
}

/* ==========================================================
   EXCEL
========================================================== */

function exportarReporteXlsx(array $reporte): void
{
    if (!reporteLibreriaDisponible(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {

        http_response_code(500);

        exit(
            'PhpSpreadsheet no está instalado todavía en este servidor. '
            . 'Ejecuta "composer install" antes de descargar el Excel.'
        );
    }

    $meta = $reporte['meta'];

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

    $hoja = $spreadsheet->getActiveSheet();

    $hoja->setTitle('Reporte');

    $hoja->setCellValue('A1', reporteTitulo($reporte));

    $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14);

    $hoja->setCellValue(
        'A2',
        'Período: ' . $meta['inicio'] . ' a ' . $meta['fin'] . ' · Generado: ' . $meta['generado_en']
    );

    $fila = 4;

    $maxColumnas = 2;

    foreach (reporteSecciones($reporte) as $seccion) {

        $hoja->setCellValue('A' . $fila, $seccion['titulo']);

        $hoja->getStyle('A' . $fila)->getFont()->setBold(true)->setSize(12);

        $fila++;

        if (empty($seccion['filas'])) {

            $hoja->setCellValue('A' . $fila, (string) $seccion['nota']);

            $fila += 2;

            continue;
        }

        foreach ($seccion['columnas'] as $i => $col) {

            $coordenada = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . $fila;

            $hoja->setCellValue($coordenada, $col);

            $hoja->getStyle($coordenada)->getFont()->setBold(true);

            $hoja->getStyle($coordenada)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('FDBA74');
        }

        $maxColumnas = max($maxColumnas, count($seccion['columnas']));

        $fila++;

        foreach ($seccion['filas'] as $datos) {

            foreach ($datos as $i => $celda) {

                $coordenada = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . $fila;

                // Números como números (se pueden sumar en Excel); todo lo
                // demás como texto explícito para que Excel no reinterprete
                // fechas/porcentajes ni pierda la sangría de los subtítulos.
                if (is_int($celda) || is_float($celda)) {

                    $hoja->setCellValue($coordenada, $celda);

                } else {

                    $hoja->setCellValueExplicit(
                        $coordenada,
                        (string) $celda,
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                    );
                }
            }

            $fila++;
        }

        if (!empty($seccion['nota'])) {

            $hoja->setCellValue('A' . $fila, $seccion['nota']);

            $hoja->getStyle('A' . $fila)->getFont()->setItalic(true)->setSize(9);

            $fila++;
        }

        $fila++;
    }

    for ($i = 1; $i <= $maxColumnas; $i++) {

        $hoja->getColumnDimension(
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i)
        )->setAutoSize(true);
    }

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    header('Content-Disposition: attachment; filename="' . reporteNombreArchivo($reporte, 'xlsx') . '"');

    header('X-Content-Type-Options: nosniff');

    $writer->save('php://output');

    exit;
}
