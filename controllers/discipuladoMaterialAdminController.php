<?php
declare(strict_types=1);
require_once __DIR__ . '/controller.php';
controllerInit(); $pdo = controllerPdo();

/* ==========================================================
   MIME reales aceptados por tipo. Se valida EXTENSIÓN + MIME
   real (finfo, no el que declara el navegador) -- mismo
   criterio de seguridad que ya usa el importador de Formación.
   .docx es en realidad un ZIP, por eso su MIME real es
   application/zip o el vnd.openxmlformats... según el sistema;
   se aceptan ambos.
========================================================== */

const MATERIAL_TIPOS_PERMITIDOS = [
    'pdf' => [
        'tipo' => 'PDF',
        'mimes' => ['application/pdf'],
    ],
    'docx' => [
        'tipo' => 'WORD',
        'mimes' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
    ],
];

controllerRun(['guardar_material_discipulado' => function () use ($pdo) {
    controllerRequirePermission('gestionar_reuniones');

    $baseId = (int)($_POST['clase_base_id'] ?? 0);
    $file = $_FILES['archivo'] ?? $_FILES['pdf'] ?? null;

    if ($baseId < 1 || !$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 15728640) {
        throw new Exception('Seleccione un archivo (PDF o Word) de hasta 15 MB.');
    }

    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));

    if (!isset(MATERIAL_TIPOS_PERMITIDOS[$extension])) {
        throw new Exception('El archivo debe ser PDF o Word (.docx).');
    }

    $config = MATERIAL_TIPOS_PERMITIDOS[$extension];

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

    if (!in_array($mime, $config['mimes'], true)) {
        throw new Exception('El archivo no es un ' . ($extension === 'pdf' ? 'PDF' : 'Word') . ' válido.');
    }

    $tipo = $config['tipo'];

    $nombre = bin2hex(random_bytes(16)) . '.' . $extension;
    $destino = __DIR__ . '/../storage/discipulado/' . $nombre;

    if (!move_uploaded_file($file['tmp_name'], $destino)) {
        throw new Exception('No fue posible guardar el archivo.');
    }

    // Solo se reemplaza el material del MISMO tipo (PDF con PDF,
    // Word con Word) -- el otro formato de la misma clase no se toca.
    $anterior = $pdo->prepare(
        'SELECT archivo_generado FROM materiales_discipulado WHERE clase_base_id = :id AND tipo = :tipo'
    );
    $anterior->execute(['id' => $baseId, 'tipo' => $tipo]);
    $previo = $anterior->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO materiales_discipulado (clase_base_id, tipo, nombre_original, archivo_generado, mime_type, tamano_bytes)
         VALUES (:id, :tipo, :original, :archivo, :mime, :tamano)
         ON DUPLICATE KEY UPDATE nombre_original = VALUES(nombre_original), archivo_generado = VALUES(archivo_generado), mime_type = VALUES(mime_type), tamano_bytes = VALUES(tamano_bytes)'
    );

    $stmt->execute([
        'id' => $baseId,
        'tipo' => $tipo,
        'original' => basename((string)$file['name']),
        'archivo' => $nombre,
        'mime' => $mime,
        'tamano' => (int)$file['size'],
    ]);

    if ($previo && is_file(__DIR__ . '/../storage/discipulado/' . basename($previo))) {
        unlink(__DIR__ . '/../storage/discipulado/' . basename($previo));
    }

    return controllerRedirect(
        '../views/formacion/discipulado/materiales.php',
        'Material (' . ($tipo === 'PDF' ? 'PDF' : 'Word') . ') guardado correctamente.'
    );
}], ['redirect' => '../views/formacion/discipulado/materiales.php']);
