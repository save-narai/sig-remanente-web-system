<?php

declare(strict_types=1);

/* =========================================================
   FORMACIÓN -- Fase 7
   ---------------------------------------------------------
   IMPORTANTE: este archivo define un CONTRATO PROPUESTO por
   la plataforma, no la estructura real del archivo que use el
   equipo de Formación (todavía no la conocemos). Cuando llegue
   el archivo real, compararEncabezadosConContrato() es el punto
   donde se valida contra este contrato -- si hay diferencias,
   se ajusta este archivo, no se inventa que el archivo real ya
   coincide.

   jovenes sigue siendo la ÚNICA fuente de identidad de los
   jóvenes. Este servicio nunca escribe nombre/teléfono/fecha de
   nacimiento -- solo datos propios de Formación, relacionados
   por joven_id (ver migración
   20260923_formacion_importacion.sql).
========================================================= */

require_once __DIR__ . '/jovenService.php';

/* =========================================================
   CONTRATO DE IMPORTACIÓN (PROPUESTO)
   ---------------------------------------------------------
   Nombres de columna esperados en la fila de encabezados del
   archivo. 'joven_id' es el identificador preferido; el resto
   de columnas de identificación son solo apoyo para la fase de
   resolución manual (nunca para un cruce automático silencioso,
   ver resolverJovenParaFila()).
========================================================= */

const CONTRATO_FORMACION = [

    'joven_id' => [
        'obligatorio' => false,
        'tipo' => 'entero',
        'descripcion' => 'ID interno del joven en esta plataforma, si el equipo de Formación ya lo conoce. Identificador preferido -- si está presente y es válido, se usa directamente.',
        'ejemplo' => '128',
    ],

    'nombre_completo' => [
        'obligatorio' => true,
        'tipo' => 'texto',
        'descripcion' => 'Nombre completo del joven. Se usa SOLO como apoyo para identificarlo cuando no hay joven_id -- nunca actualiza el nombre real del joven en la plataforma.',
        'ejemplo' => 'Ejemplo Apellido Ejemplo',
    ],

    'documento_identidad' => [
        'obligatorio' => false,
        'tipo' => 'texto',
        'descripcion' => 'Cédula u otro documento de identidad, dato adicional opcional para ayudar a identificar al joven cuando no hay joven_id.',
        'ejemplo' => '00000000',
    ],

    'ciclo_formacion' => [
        'obligatorio' => true,
        'tipo' => 'texto',
        'descripcion' => 'Nombre del ciclo o proceso de formación, tal como lo reporta el equipo de Formación (texto libre -- no se asume que coincide con un ciclo del módulo interno de discipulado).',
        'ejemplo' => 'Ciclo Ejemplo 2026',
    ],

    'leccion_actual' => [
        'obligatorio' => false,
        'tipo' => 'texto',
        'descripcion' => 'Lección o unidad en la que se encuentra el joven actualmente.',
        'ejemplo' => 'Lección 3',
    ],

    'progreso_porcentaje' => [
        'obligatorio' => false,
        'tipo' => 'número entero (0-100)',
        'descripcion' => 'Porcentaje de avance dentro del ciclo de formación.',
        'ejemplo' => '40',
    ],

    'fecha_dato' => [
        'obligatorio' => false,
        'tipo' => 'fecha (AAAA-MM-DD)',
        'descripcion' => 'Fecha a la que corresponde este dato de progreso.',
        'ejemplo' => '2026-09-01',
    ],

    'observaciones' => [
        'obligatorio' => false,
        'tipo' => 'texto',
        'descripcion' => 'Observaciones libres del equipo de Formación sobre este joven.',
        'ejemplo' => 'Ejemplo de observación.',
    ],

];

const ESTADOS_FILA_FORMACION = [
    'NUEVO',
    'MODIFICADO',
    'DUPLICADO',
    'SIN_CORRESPONDENCIA',
    'SIN_CAMBIOS',
];


/* =========================================================
   COMPARAR ENCABEZADOS CONTRA EL CONTRATO

   Punto de validación obligatorio antes de procesar cualquier
   fila. No fuerza la importación si faltan columnas
   obligatorias -- eso lo decide quien llama a esta función.
========================================================= */

function compararEncabezadosConContrato(
    array $encabezadosArchivo
): array {

    $normalizados = array_map(
        static fn ($h) => strtolower(trim((string) $h)),
        $encabezadosArchivo
    );

    $coinciden = [];
    $faltantesObligatorias = [];
    $faltantesOpcionales = [];

    foreach (CONTRATO_FORMACION as $columna => $definicion) {

        if (in_array($columna, $normalizados, true)) {

            $coinciden[] = $columna;

        } elseif ($definicion['obligatorio']) {

            $faltantesObligatorias[] = $columna;

        } else {

            $faltantesOpcionales[] = $columna;
        }
    }

    $columnasNoReconocidas = array_values(array_diff(
        $normalizados,
        array_keys(CONTRATO_FORMACION)
    ));

    return [
        'coinciden' => $coinciden,
        'faltantes_obligatorias' => $faltantesObligatorias,
        'faltantes_opcionales' => $faltantesOpcionales,
        'columnas_no_reconocidas' => $columnasNoReconocidas,
        'valido' => empty($faltantesObligatorias),
    ];
}


/* =========================================================
   NORMALIZAR UNA FILA CRUDA SEGÚN EL CONTRATO

   $filaCruda: array asociativo columna(normalizada) => valor,
   tal como vino del lector de archivo (XLSX u otro).
========================================================= */

function normalizarFilaFormacion(
    array $filaCruda
): array {

    $normalizada = [];

    foreach (CONTRATO_FORMACION as $columna => $definicion) {

        $valor = $filaCruda[$columna] ?? null;

        if ($valor === null || $valor === '') {
            $normalizada[$columna] = null;
            continue;
        }

        $normalizada[$columna] = match ($definicion['tipo']) {

            'entero' => (int) $valor,

            'número entero (0-100)' => max(0, min(100, (int) $valor)),

            'fecha (AAAA-MM-DD)' => (
                static function ($v) {
                    $v = trim((string) $v);
                    $ts = strtotime($v);
                    return $ts !== false
                        ? date('Y-m-d', $ts)
                        : null;
                }
            )($valor),

            default => trim((string) $valor),
        };
    }

    return $normalizada;
}


/* =========================================================
   RESOLVER JOVEN PARA UNA FILA

   Regla obligatoria (Fase 7, punto 4): NUNCA se hace una
   coincidencia automática silenciosa solo por nombre. Si la
   fila no trae joven_id válido, queda SIN_CORRESPONDENCIA
   -- se sugieren candidatos por nombre para que un admin
   los revise y decida manualmente, pero no se asigna solo.
========================================================= */

function resolverJovenParaFila(
    PDO $pdo,
    array $filaNormalizada
): array {

    $jovenId = $filaNormalizada['joven_id'] ?? null;

    if (!empty($jovenId)) {

        $stmt = $pdo->prepare("
            SELECT id FROM jovenes
            WHERE id = :id AND estado_actividad != 'ELIMINADO'
        ");

        $stmt->execute(['id' => (int) $jovenId]);

        if ($stmt->fetchColumn()) {

            return [
                'joven_id' => (int) $jovenId,
                'resuelto' => true,
                'candidatos' => [],
            ];
        }

        // joven_id vino en el archivo pero no existe en la
        // plataforma -- no se inventa, queda sin corresponder.
        return [
            'joven_id' => null,
            'resuelto' => false,
            'candidatos' => [],
            'motivo' => "joven_id {$jovenId} no existe en la plataforma",
        ];
    }

    // Sin joven_id: se buscan candidatos por nombre SOLO para
    // mostrarlos como sugerencia -- nunca se elige automáticamente.
    $candidatos = [];

    $nombre = trim((string) ($filaNormalizada['nombre_completo'] ?? ''));

    if ($nombre !== '') {

        $stmt = $pdo->prepare("
            SELECT id, nombre_completo FROM jovenes
            WHERE nombre_completo LIKE :nombre
            AND estado_actividad != 'ELIMINADO'
            LIMIT 5
        ");

        $stmt->execute(['nombre' => '%' . $nombre . '%']);

        $candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return [
        'joven_id' => null,
        'resuelto' => false,
        'candidatos' => $candidatos,
        'motivo' => 'Sin joven_id en el archivo -- requiere resolución manual',
    ];
}


/* =========================================================
   CLASIFICAR UNA FILA (NUEVO/MODIFICADO/DUPLICADO/
   SIN_CORRESPONDENCIA/SIN_CAMBIOS)

   $jovenesYaVistosEnEsteLote: joven_id => true, para detectar
   duplicados DENTRO del propio archivo (no solo contra lo que
   ya existía en formacion_registros).
========================================================= */

function clasificarFilaFormacion(
    PDO $pdo,
    array $filaNormalizada,
    ?int $jovenId,
    array &$jovenesYaVistosEnEsteLote
): array {

    if ($jovenId === null) {

        return [
            'estado' => 'SIN_CORRESPONDENCIA',
            'motivo' => 'No se pudo determinar el joven de forma segura',
        ];
    }

    if (isset($jovenesYaVistosEnEsteLote[$jovenId])) {

        return [
            'estado' => 'DUPLICADO',
            'motivo' => 'Este joven ya aparece antes en el mismo archivo',
        ];
    }

    $jovenesYaVistosEnEsteLote[$jovenId] = true;

    $stmt = $pdo->prepare("
        SELECT ciclo_formacion, leccion_actual, progreso_porcentaje,
               observaciones, fecha_dato
        FROM formacion_registros
        WHERE joven_id = :joven_id
    ");

    $stmt->execute(['joven_id' => $jovenId]);

    $existente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existente) {

        return [
            'estado' => 'NUEVO',
            'motivo' => null,
        ];
    }

    $camposComparables = [
        'ciclo_formacion',
        'leccion_actual',
        'progreso_porcentaje',
        'observaciones',
        'fecha_dato',
    ];

    foreach ($camposComparables as $campo) {

        $nuevo = $filaNormalizada[$campo] ?? null;
        $viejo = $existente[$campo] ?? null;

        if ((string) $nuevo !== (string) $viejo) {

            return [
                'estado' => 'MODIFICADO',
                'motivo' => null,
            ];
        }
    }

    return [
        'estado' => 'SIN_CAMBIOS',
        'motivo' => null,
    ];
}


/* =========================================================
   PROCESAR UNA FILA (compartido entre XLSX y, en el futuro,
   Google Sheets -- "mismo procesador interno", punto 11)

   Recibe una fila cruda ya leída del origen (sea XLSX u otro),
   la normaliza, resuelve el joven y la clasifica. No escribe
   nada en formacion_registros todavía -- eso ocurre solo al
   confirmar (confirmarImportacionFormacion()).
========================================================= */

function procesarFilaFormacion(
    PDO $pdo,
    array $filaCruda,
    array &$jovenesYaVistosEnEsteLote
): array {

    $normalizada = normalizarFilaFormacion($filaCruda);

    $resolucion = resolverJovenParaFila($pdo, $normalizada);

    $clasificacion = clasificarFilaFormacion(
        $pdo,
        $normalizada,
        $resolucion['joven_id'],
        $jovenesYaVistosEnEsteLote
    );

    return [
        'datos' => $normalizada,
        'joven_id' => $resolucion['joven_id'],
        'candidatos' => $resolucion['candidatos'] ?? [],
        'estado' => $clasificacion['estado'],
        'motivo' => $clasificacion['motivo'] ?? ($resolucion['motivo'] ?? null),
    ];
}


/* =========================================================
   CONFIRMAR IMPORTACIÓN

   Escribe en formacion_registros (upsert) solo las filas NUEVO
   y MODIFICADO todavía no confirmadas de esta importación. Las
   filas quedan como registro histórico (trazabilidad, punto 16)
   -- no se borran.
========================================================= */

function confirmarImportacionFormacion(
    PDO $pdo,
    int $importacionId
): array {

    $stmtImportacion = $pdo->prepare("
        SELECT * FROM formacion_importaciones WHERE id = :id
    ");

    $stmtImportacion->execute(['id' => $importacionId]);

    $importacion = $stmtImportacion->fetch(PDO::FETCH_ASSOC);

    if (!$importacion) {
        throw new Exception('Importación no encontrada.');
    }

    if ($importacion['estado'] !== 'PENDIENTE_CONFIRMACION') {
        throw new Exception('Esta importación ya fue confirmada o descartada.');
    }

    $stmtFilas = $pdo->prepare("
        SELECT * FROM formacion_importacion_filas
        WHERE importacion_id = :importacion_id
        AND estado_fila IN ('NUEVO', 'MODIFICADO')
    ");

    $stmtFilas->execute(['importacion_id' => $importacionId]);

    $filas = $stmtFilas->fetchAll(PDO::FETCH_ASSOC);

    $aplicadas = 0;

    foreach ($filas as $fila) {

        $datos = json_decode($fila['datos_json'], true) ?: [];

        $stmtUpsert = $pdo->prepare("
            INSERT INTO formacion_registros (
                joven_id, ciclo_formacion, leccion_actual,
                progreso_porcentaje, observaciones, fecha_dato,
                importacion_id
            ) VALUES (
                :joven_id, :ciclo_formacion, :leccion_actual,
                :progreso_porcentaje, :observaciones, :fecha_dato,
                :importacion_id
            )
            ON DUPLICATE KEY UPDATE
                ciclo_formacion = VALUES(ciclo_formacion),
                leccion_actual = VALUES(leccion_actual),
                progreso_porcentaje = VALUES(progreso_porcentaje),
                observaciones = VALUES(observaciones),
                fecha_dato = VALUES(fecha_dato),
                importacion_id = VALUES(importacion_id)
        ");

        $stmtUpsert->execute([
            'joven_id' => (int) $fila['joven_id'],
            'ciclo_formacion' => $datos['ciclo_formacion'] ?? null,
            'leccion_actual' => $datos['leccion_actual'] ?? null,
            'progreso_porcentaje' => $datos['progreso_porcentaje'] ?? null,
            'observaciones' => $datos['observaciones'] ?? null,
            'fecha_dato' => $datos['fecha_dato'] ?? null,
            'importacion_id' => $importacionId,
        ]);

        $pdo->prepare("
            UPDATE formacion_importacion_filas
            SET confirmada = 1
            WHERE id = :id
        ")->execute(['id' => $fila['id']]);

        $aplicadas++;
    }

    $pdo->prepare("
        UPDATE formacion_importaciones
        SET estado = 'CONFIRMADA', fecha_confirmacion = NOW()
        WHERE id = :id
    ")->execute(['id' => $importacionId]);

    return ['filas_aplicadas' => $aplicadas];
}


/* =========================================================
   DESCARTAR IMPORTACIÓN

   No borra las filas (auditoría) -- solo marca la importación
   como descartada, para que confirmarImportacionFormacion()
   la rechace si alguien intenta confirmarla después.
========================================================= */

function descartarImportacionFormacion(
    PDO $pdo,
    int $importacionId
): void {

    $pdo->prepare("
        UPDATE formacion_importaciones
        SET estado = 'DESCARTADA'
        WHERE id = :id AND estado = 'PENDIENTE_CONFIRMACION'
    ")->execute(['id' => $importacionId]);
}


/* =========================================================
   OBTENER IMPORTACIÓN CON SUS FILAS (para la previsualización)
========================================================= */

function obtenerImportacionFormacion(
    PDO $pdo,
    int $importacionId
): ?array {

    $stmt = $pdo->prepare("
        SELECT * FROM formacion_importaciones WHERE id = :id
    ");

    $stmt->execute(['id' => $importacionId]);

    $importacion = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$importacion) {
        return null;
    }

    $stmtFilas = $pdo->prepare("
        SELECT f.*, j.nombre_completo AS joven_nombre
        FROM formacion_importacion_filas f
        LEFT JOIN jovenes j ON j.id = f.joven_id
        WHERE f.importacion_id = :importacion_id
        ORDER BY f.fila_numero ASC
    ");

    $stmtFilas->execute(['importacion_id' => $importacionId]);

    $importacion['filas'] = $stmtFilas->fetchAll(PDO::FETCH_ASSOC);

    return $importacion;
}


/* =========================================================
   LISTAR IMPORTACIONES (historial/trazabilidad)
========================================================= */

function listarImportacionesFormacion(
    PDO $pdo
): array {

    return $pdo->query("
        SELECT i.*, u.nombre AS usuario_nombre
        FROM formacion_importaciones i
        LEFT JOIN usuarios u ON u.id = i.usuario_id
        ORDER BY i.fecha_importacion DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}


/* =========================================================
   LISTAR REGISTROS DE FORMACIÓN (datos ya confirmados)
========================================================= */

function listarRegistrosFormacion(
    PDO $pdo
): array {

    return $pdo->query("
        SELECT r.*, j.nombre_completo
        FROM formacion_registros r
        INNER JOIN jovenes j ON j.id = r.joven_id
        ORDER BY j.nombre_completo ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}
