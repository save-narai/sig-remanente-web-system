<?php

declare(strict_types=1);

/* =========================================================
   IMPORTADOR XLSX -- FORMACIÓN (Fase 7)
   ---------------------------------------------------------
   Depende de PhpSpreadsheet (phpoffice/phpspreadsheet, ya
   agregado a composer.json). Requiere `composer install` en
   el servidor antes de poder usarse -- igual que dompdf y
   phpmailer, que ya estaban declarados sin vendor/ instalado.
========================================================= */

require_once __DIR__ . '/formacionService.php';

/* =========================================================
   VALIDAR ARCHIVO SUBIDO (seguridad, punto 17)
========================================================= */

function validarArchivoXlsxFormacion(
    array $archivo,
    array $configImportacion
): void {

    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception('No se pudo recibir el archivo (error de subida).');
    }

    $tamanoMaximo = $configImportacion['tamano_maximo_bytes'] ?? (5 * 1024 * 1024);

    if (($archivo['size'] ?? 0) > $tamanoMaximo) {
        throw new Exception(
            'El archivo supera el tamaño máximo permitido (' .
            round($tamanoMaximo / 1024 / 1024, 1) . ' MB).'
        );
    }

    $nombreOriginal = (string) ($archivo['name'] ?? '');

    $extension = strtolower(
        (string) pathinfo($nombreOriginal, PATHINFO_EXTENSION)
    );

    $extensionesPermitidas = $configImportacion['extensiones_permitidas'] ?? ['xlsx'];

    if (!in_array($extension, $extensionesPermitidas, true)) {
        throw new Exception('Solo se permiten archivos .xlsx.');
    }

    // MIME real del contenido (no confiar solo en la extensión ni en
    // el Content-Type que reporta el navegador).
    $rutaTemporal = (string) ($archivo['tmp_name'] ?? '');

    if ($rutaTemporal === '' || !is_uploaded_file($rutaTemporal)) {
        throw new Exception('Archivo inválido.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeReal = finfo_file($finfo, $rutaTemporal);
    finfo_close($finfo);

    $mimesPermitidos = $configImportacion['mimes_permitidos'] ?? [];

    if (!in_array($mimeReal, $mimesPermitidos, true)) {
        throw new Exception(
            "El contenido del archivo no corresponde a un .xlsx válido (tipo detectado: {$mimeReal})."
        );
    }
}


/* =========================================================
   LEER Y PROCESAR UN ARCHIVO XLSX DE FORMACIÓN

   Deja la importación en estado PENDIENTE_CONFIRMACION -- no
   escribe nada en formacion_registros todavía (eso ocurre en
   confirmarImportacionFormacion(), tras la previsualización).
========================================================= */

function procesarArchivoXlsxFormacion(
    PDO $pdo,
    array $archivo,
    int $usuarioId,
    array $configImportacion
): array {

    validarArchivoXlsxFormacion($archivo, $configImportacion);

    if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
        throw new Exception(
            'PhpSpreadsheet no está instalado todavía en este servidor. ' .
            'Ejecuta "composer install" (ver composer.json) antes de importar archivos .xlsx.'
        );
    }

    // Se copia a la carpeta protegida (storage/formacion, con
    // .htaccess "Require all denied") en vez de dejarlo en el tmp
    // del sistema, y se procesa desde ahí -- nunca dentro de una
    // ruta pública/ejecutable como PHP.
    $carpetaTemporal = $configImportacion['carpeta_temporal']
        ?? (__DIR__ . '/../storage/formacion');

    if (!is_dir($carpetaTemporal)) {
        mkdir($carpetaTemporal, 0750, true);
    }

    $nombreSeguro = uniqid('formacion_', true) . '.xlsx';

    $rutaDestino = rtrim($carpetaTemporal, '/') . '/' . $nombreSeguro;

    if (!move_uploaded_file($archivo['tmp_name'], $rutaDestino)) {
        throw new Exception('No se pudo procesar el archivo subido.');
    }

    try {

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($rutaDestino);

        $hoja = $spreadsheet->getActiveSheet();

        $filasCrudas = $hoja->toArray(null, true, true, false);

        if (count($filasCrudas) < 2) {
            throw new Exception('El archivo no tiene filas de datos (solo encabezados, o está vacío).');
        }

        $encabezados = array_map(
            static fn ($h) => strtolower(trim((string) $h)),
            $filasCrudas[0]
        );

        $comparacion = compararEncabezadosConContrato($encabezados);

        if (!$comparacion['valido']) {

            return [
                'exito' => false,
                'comparacion' => $comparacion,
            ];
        }

        // importación (encabezado)
        $pdo->prepare("
            INSERT INTO formacion_importaciones (
                archivo_nombre, tipo_origen, usuario_id
            ) VALUES (
                :archivo_nombre, 'XLSX', :usuario_id
            )
        ")->execute([
            'archivo_nombre' => $archivo['name'],
            'usuario_id' => $usuarioId,
        ]);

        $importacionId = (int) $pdo->lastInsertId();

        $contadores = [
            'NUEVO' => 0,
            'MODIFICADO' => 0,
            'DUPLICADO' => 0,
            'SIN_CORRESPONDENCIA' => 0,
            'SIN_CAMBIOS' => 0,
        ];

        $jovenesYaVistosEnEsteLote = [];

        $stmtFila = $pdo->prepare("
            INSERT INTO formacion_importacion_filas (
                importacion_id, fila_numero, joven_id,
                datos_json, estado_fila, motivo
            ) VALUES (
                :importacion_id, :fila_numero, :joven_id,
                :datos_json, :estado_fila, :motivo
            )
        ");

        for ($i = 1; $i < count($filasCrudas); $i++) {

            $filaCruda = array_combine(
                $encabezados,
                array_pad($filasCrudas[$i], count($encabezados), null)
            );

            if (empty(array_filter($filaCruda, static fn ($v) => $v !== null && $v !== ''))) {
                continue; // fila completamente vacía, se ignora
            }

            $resultado = procesarFilaFormacion(
                $pdo,
                $filaCruda,
                $jovenesYaVistosEnEsteLote
            );

            $stmtFila->execute([
                'importacion_id' => $importacionId,
                'fila_numero' => $i + 1, // +1: la fila 1 es el encabezado
                'joven_id' => $resultado['joven_id'],
                'datos_json' => json_encode($resultado['datos'], JSON_UNESCAPED_UNICODE),
                'estado_fila' => $resultado['estado'],
                'motivo' => $resultado['motivo'],
            ]);

            $contadores[$resultado['estado']]++;
        }

        $pdo->prepare("
            UPDATE formacion_importaciones
            SET total_filas = :total, nuevos = :nuevos,
                modificados = :modificados, duplicados = :duplicados,
                sin_correspondencia = :sin_correspondencia,
                sin_cambios = :sin_cambios
            WHERE id = :id
        ")->execute([
            'total' => array_sum($contadores),
            'nuevos' => $contadores['NUEVO'],
            'modificados' => $contadores['MODIFICADO'],
            'duplicados' => $contadores['DUPLICADO'],
            'sin_correspondencia' => $contadores['SIN_CORRESPONDENCIA'],
            'sin_cambios' => $contadores['SIN_CAMBIOS'],
            'id' => $importacionId,
        ]);

        return [
            'exito' => true,
            'importacion_id' => $importacionId,
            'resumen' => $contadores,
        ];

    } finally {

        // El archivo original ya no hace falta una vez leído -- no
        // se deja acumulado en storage/formacion indefinidamente.
        if (file_exists($rutaDestino)) {
            unlink($rutaDestino);
        }
    }
}


/* =========================================================
   GENERAR PLANTILLA OFICIAL DE IMPORTACIÓN (Fase 7, punto 13)

   Se genera siempre desde CONTRATO_FORMACION (una sola fuente
   de verdad) -- nunca puede quedar desincronizada del validador.
   Incluye UNA fila de ejemplo, claramente marcada como tal, con
   datos ficticios (nunca datos reales).
========================================================= */

function generarPlantillaXlsxFormacion(): \PhpOffice\PhpSpreadsheet\Spreadsheet
{

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        throw new Exception(
            'PhpSpreadsheet no está instalado todavía en este servidor. Ejecuta "composer install".'
        );
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

    // ---- Hoja 1: plantilla para llenar ----
    $hoja = $spreadsheet->getActiveSheet();
    $hoja->setTitle('Plantilla de importación');

    $columna = 1;

    foreach (CONTRATO_FORMACION as $nombreColumna => $definicion) {

        $hoja->setCellValueByColumnAndRow($columna, 1, $nombreColumna);

        $hoja->setCellValueByColumnAndRow(
            $columna,
            2,
            $definicion['ejemplo'] . ' (EJEMPLO -- reemplazar por datos reales)'
        );

        $columna++;
    }

    $hoja->getStyle('1:1')->getFont()->setBold(true);

    // ---- Hoja 2: descripción de columnas ----
    $hojaDescripcion = $spreadsheet->createSheet();
    $hojaDescripcion->setTitle('Descripción de columnas');

    $hojaDescripcion->setCellValue('A1', 'Columna');
    $hojaDescripcion->setCellValue('B1', 'Obligatoria');
    $hojaDescripcion->setCellValue('C1', 'Tipo');
    $hojaDescripcion->setCellValue('D1', 'Significado');
    $hojaDescripcion->setCellValue('E1', 'Ejemplo');
    $hojaDescripcion->getStyle('A1:E1')->getFont()->setBold(true);

    $fila = 2;

    foreach (CONTRATO_FORMACION as $nombreColumna => $definicion) {

        $hojaDescripcion->setCellValue("A{$fila}", $nombreColumna);
        $hojaDescripcion->setCellValue("B{$fila}", $definicion['obligatorio'] ? 'Sí' : 'No');
        $hojaDescripcion->setCellValue("C{$fila}", $definicion['tipo']);
        $hojaDescripcion->setCellValue("D{$fila}", $definicion['descripcion']);
        $hojaDescripcion->setCellValue("E{$fila}", $definicion['ejemplo']);

        $fila++;
    }

    $hojaDescripcion->setCellValue(
        "A{$fila}",
        'Regla de identificación: si "joven_id" no está disponible, el joven NO se vincula automáticamente por nombre -- queda pendiente de resolución manual desde la plataforma.'
    );

    $spreadsheet->setActiveSheetIndex(0);

    return $spreadsheet;
}
