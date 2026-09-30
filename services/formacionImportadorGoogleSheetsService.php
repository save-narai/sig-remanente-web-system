<?php

declare(strict_types=1);

/* =========================================================
   IMPORTADOR GOOGLE SHEETS -- FORMACIÓN (Fase 7, punto 11)
   ---------------------------------------------------------
   INTENCIONALMENTE SIN IMPLEMENTAR. No hay credenciales de
   Google, no hay ID de Sheet real, y el cliente oficial de
   Google API (google/apiclient) NO está agregado a
   composer.json todavía -- agregarlo ahora sería instalar una
   dependencia sin usarla, y el Plan Maestro pide evitar
   dependencias sin justificación clara.

   Cuando exista configuración real (config/formacion.php ->
   'google_sheets'), la implementación futura debe:

     1. Autenticarse con la cuenta de servicio configurada.
     2. Leer el rango de celdas del Sheet (encabezados + filas).
     3. Normalizar los encabezados exactamente igual que
        formacionImportadorXlsxService.php (mismo array de
        columna => valor por fila).
     4. Reutilizar SIN DUPLICAR la lógica ya existente:
        compararEncabezadosConContrato(), procesarFilaFormacion()
        y el mismo bloque de inserción en
        formacion_importaciones / formacion_importacion_filas
        que usa procesarArchivoXlsxFormacion() -- Excel y Google
        Sheets deben terminar en el mismo "ProcesadorFormacion"
        (formacionService.php), nunca en una lógica paralela.

   Formación NUNCA recibe credenciales de la plataforma: la
   cuenta de servicio de Google solo necesita permiso de
   LECTURA sobre el Sheet, compartido hacia ella -- no al revés.
========================================================= */

require_once __DIR__ . '/formacionService.php';

/* =========================================================
   ¿ESTÁ CONFIGURADA LA INTEGRACIÓN?
========================================================= */

function googleSheetsFormacionConfigurado(
    array $configFormacion
): bool {

    $config = $configFormacion['google_sheets'] ?? [];

    return !empty($config['habilitado'])
        && !empty($config['sheet_id'])
        && !empty($config['credenciales_path'])
        && file_exists($config['credenciales_path']);
}


/* =========================================================
   IMPORTAR DESDE GOOGLE SHEETS

   Lanza una excepción clara y explica exactamente qué falta
   -- no simula ni inventa una respuesta exitosa.
========================================================= */

function importarDesdeGoogleSheetsFormacion(
    PDO $pdo,
    int $usuarioId,
    array $configFormacion
): array {

    if (!googleSheetsFormacionConfigurado($configFormacion)) {

        throw new Exception(
            'La integración con Google Sheets no está configurada. ' .
            'Faltan: sheet_id y/o credenciales_path en config/formacion.php ' .
            '(ver los comentarios de ese archivo para los pasos exactos), ' .
            'y la librería google/apiclient vía Composer. Mientras tanto, ' .
            'usa la importación por archivo .xlsx.'
        );
    }

    // No implementado a propósito -- ver el bloque de comentarios
    // al inicio de este archivo para los pasos que faltan una vez
    // exista configuración real.
    throw new Exception(
        'El conector de Google Sheets está preparado pero no implementado ' .
        'todavía (falta el cliente de Google API). Usa la importación por ' .
        'archivo .xlsx mientras tanto.'
    );
}
