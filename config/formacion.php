<?php

/*
|--------------------------------------------------------------------------
| CONFIGURACIÓN DE FORMACIÓN (Fase 7)
|--------------------------------------------------------------------------
|
| Google Sheets queda deshabilitado hasta que exista configuración
| real. NO se colocan credenciales, tokens ni IDs de Google en este
| archivo ni en ningún otro del repositorio -- si en el futuro se
| activa esta integración, 'credenciales_path' debe apuntar a un
| archivo FUERA del control de versiones (por ejemplo, uno que el
| .gitignore excluya explícitamente), nunca a una ruta versionada.
|
| Para activar la integración con Google Sheets hace falta, como
| mínimo:
|   1. Un proyecto de Google Cloud con la API de Sheets habilitada.
|   2. Una cuenta de servicio (service account) con acceso de
|      SOLO LECTURA al Sheet de Formación (compartir el Sheet con
|      el correo de la cuenta de servicio).
|   3. El archivo JSON de credenciales de esa cuenta de servicio,
|      guardado fuera del repositorio, con su ruta absoluta en
|      'credenciales_path' abajo.
|   4. El ID del Sheet (se obtiene de su URL) en 'sheet_id'.
|   5. Instalar el cliente oficial de Google API vía Composer
|      (google/apiclient) -- no está agregado todavía a
|      composer.json porque no se sabe si esta vía se usará.
|
| Mientras 'sheet_id' y 'credenciales_path' estén vacíos, el sistema
| trata la integración como NO CONFIGURADA y solo permite importar
| por archivo .xlsx.
|--------------------------------------------------------------------------
*/

return [

    'google_sheets' => [

        'habilitado' => false,

        'sheet_id' => '',

        'credenciales_path' => '',

    ],

    'importacion_xlsx' => [

        // Límite práctico de tamaño para evitar agotar memoria en
        // hosting compartido (InfinityFree) -- ajustar si se conoce
        // el límite real de memory_limit del entorno de destino.
        'tamano_maximo_bytes' => 5 * 1024 * 1024, // 5 MB

        'extensiones_permitidas' => ['xlsx'],

        'mimes_permitidos' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],

        // Carpeta de almacenamiento temporal -- reutiliza el mismo
        // patrón ya protegido del proyecto (storage/.htaccess con
        // "Require all denied", ver storage/formacion/.htaccess).
        'carpeta_temporal' => __DIR__ . '/../storage/formacion',

    ],

];
