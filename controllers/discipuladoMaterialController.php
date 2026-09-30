<?php
declare(strict_types=1);

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/permiso.php';
require_once __DIR__ . '/../config/conexion.php';

if (!tienePermiso('gestionar_reuniones')) { http_response_code(403); exit('Acceso no autorizado.'); }

$id = (int)($_GET['id'] ?? 0);
$modo = ($_GET['modo'] ?? 'ver') === 'descargar' ? 'descargar' : 'ver';
$stmt = $pdo->prepare('SELECT archivo_generado, nombre_original, mime_type FROM materiales_discipulado WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$material = $stmt->fetch(PDO::FETCH_ASSOC);
$archivo = $material ? realpath(__DIR__ . '/../storage/discipulado/' . basename($material['archivo_generado'])) : false;
$directorio = realpath(__DIR__ . '/../storage/discipulado');

if (!$material || !$archivo || !$directorio || !str_starts_with($archivo, $directorio . DIRECTORY_SEPARATOR)) { http_response_code(404); exit('Material no encontrado.'); }

// El Word (.docx) no se puede mostrar "en línea" en el navegador de forma
// fiable -- se fuerza descarga para ese caso aunque modo=ver.
$esWord = str_ends_with(strtolower($material['archivo_generado']), '.docx');

header('Content-Type: ' . ($material['mime_type'] ?: 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($archivo));
header('Content-Disposition: ' . (($modo === 'descargar' || $esWord) ? 'attachment' : 'inline') . '; filename="' . rawurlencode($material['nombre_original']) . '"');
readfile($archivo);
exit;
