<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/permiso.php';
require_once __DIR__ . '/jovenService.php';
require_once __DIR__ . '/notificacionService.php';


/*
|--------------------------------------------------------------------------
| Seguimiento Service
|--------------------------------------------------------------------------
|
| Gestiona:
|
| - Seguimientos reales.
| - Historial de seguimientos + excepciones.
| - Resumen del ciclo de seguimiento.
| - Jóvenes pendientes de su ciclo inicial.
| - Conexión entre seguimiento y asignación.
|
| REGLAS:
|
| 1. Un joven puede tener múltiples seguimientos.
|
| 2. La asignación pertenece a un período administrativo
|    (año/mes).
|
| 3. El seguimiento puede tener cualquier fecha pasada
|    válida; no tiene que coincidir con el mes de la asignación.
|
| 4. El ciclo inicial se considera cumplido cuando existe
|    al menos un seguimiento FINALIZADO.
|
| 5. Un joven antiguo con su ciclo inicial ya FINALIZADO
|    no vuelve automáticamente a "Sin seguimiento".
|
| 6. Si un joven ingresó en febrero y fue contactado en agosto,
|    ese contacto FINALIZADO cuenta para el ciclo y para
|    el consolidado de agosto.
|
| 7. Un joven puede recibir nuevos seguimientos posteriores
|    sin perder los anteriores.
|
| 8. Las excepciones pertenecen al período administrativo
|    seleccionado.
|
|--------------------------------------------------------------------------
*/


/* ==========================================================
   CONSTANTES
========================================================== */

const MODALIDADES_SEGUIMIENTO = [

    'WHATSAPP',
    'LLAMADA',
    'VISITA',
    'MENSAJE'

];


const ESTADOS_SEGUIMIENTO = [

    'PENDIENTE',
    'EN_PROCESO',
    'FINALIZADO'

];


/* ==========================================================
   VALIDAR FECHA
========================================================== */

function validarFechaSeguimiento(
    ?string $fecha
): string {

    $fecha =
        trim(
            (string)$fecha
        );

    if ($fecha === '') {

        throw new Exception(
            'Debe ingresar la fecha de contacto.'
        );
    }

    $timestamp =
        strtotime(
            $fecha
        );

    if ($timestamp === false) {

        throw new Exception(
            'La fecha de contacto no es válida.'
        );
    }

    $fechaNormalizada =
        date(
            'Y-m-d',
            $timestamp
        );

    $hoy =
        date('Y-m-d');

    if ($fechaNormalizada > $hoy) {

        throw new Exception(
            'La fecha no puede ser futura.'
        );
    }

    return $fechaNormalizada;
}


/* ==========================================================
   VALIDAR MODALIDAD
========================================================== */

function validarModalidadSeguimiento(
    string $modalidad
): string {

    $modalidad =
        strtoupper(
            trim(
                $modalidad
            )
        );

    if (!in_array(
        $modalidad,
        MODALIDADES_SEGUIMIENTO,
        true
    )) {

        throw new Exception(
            'Modalidad de contacto inválida.'
        );
    }

    return $modalidad;
}


/* ==========================================================
   VALIDAR ESTADO
========================================================== */

function validarEstadoSeguimiento(
    string $estado
): string {

    $estado =
        strtoupper(
            trim(
                $estado
            )
        );

    if (!in_array(
        $estado,
        ESTADOS_SEGUIMIENTO,
        true
    )) {

        throw new Exception(
            'Estado del proceso inválido.'
        );
    }

    return $estado;
}


/* ==========================================================
   VALIDAR RESPONSABLE
========================================================== */

function validarResponsableSeguimiento(
    PDO $pdo,
    ?int $responsableId
): ?int {

    if (
        $responsableId === null ||
        $responsableId <= 0
    ) {

        return null;
    }

    $stmt =
        $pdo->prepare("
            SELECT id

            FROM usuarios

            WHERE id = :id

            LIMIT 1
        ");

    $stmt->execute([

        ':id' =>
            $responsableId

    ]);

    if (!$stmt->fetchColumn()) {

        throw new Exception(
            'El responsable seleccionado no existe.'
        );
    }

    return $responsableId;
}


/* ==========================================================
   VALIDAR OBSERVACIONES
========================================================== */

function validarObservacionesSeguimiento(
    ?string $observaciones
): ?string {

    $observaciones =
        trim(
            (string)$observaciones
        );

    if ($observaciones === '') {

        return null;
    }

    if (
        mb_strlen(
            $observaciones
        ) > 2000
    ) {

        throw new Exception(
            'Las observaciones son demasiado largas.'
        );
    }

    return $observaciones;
}


/* ==========================================================
   VALIDAR JOVEN
========================================================== */

function validarJovenSeguimiento(
    PDO $pdo,
    int $jovenId
): array {

    if ($jovenId <= 0) {

        throw new Exception(
            'Joven inválido.'
        );
    }

    $joven =
        obtenerJovenPorId(
            $pdo,
            $jovenId
        );

    if (!$joven) {

        throw new Exception(
            'El joven no existe.'
        );
    }

    return $joven;
}


/* ==========================================================
   PREPARAR DATOS
========================================================== */

function prepararDatosSeguimiento(
    PDO $pdo,
    array $datos
): array {

    $jovenId =
        (int)(
            $datos['joven_id']
            ?? 0
        );

    $joven =
        validarJovenSeguimiento(
            $pdo,
            $jovenId
        );

    $fechaContacto =
        validarFechaSeguimiento(
            $datos['fecha_contacto']
            ?? null
        );

    $modalidad =
        validarModalidadSeguimiento(
            $datos['modalidad_contacto']
            ?? ''
        );

    $estado =
        validarEstadoSeguimiento(
            $datos['estado_proceso']
            ?? ''
        );

    $responsableId = null;

    if (
        isset(
            $datos['responsable_id']
        )
        &&
        $datos['responsable_id'] !== ''
    ) {

        $responsableId =
            (int)(
                $datos['responsable_id']
            );
    }

    $responsableId =
        validarResponsableSeguimiento(
            $pdo,
            $responsableId
        );

    $observaciones =
        validarObservacionesSeguimiento(
            $datos['observaciones']
            ?? null
        );

    return [

        'jovenId' =>
            $jovenId,

        'joven' =>
            $joven,

        'fechaContacto' =>
            $fechaContacto,

        'modalidad' =>
            $modalidad,

        'estado' =>
            $estado,

        'responsableId' =>
            $responsableId,

        'observaciones' =>
            $observaciones

    ];
}


/* ==========================================================
   OBTENER ASIGNACIÓN ACTIVA DEL USUARIO ACTUAL
========================================================== */

function obtenerAsignacionActivaParaUsuarioSeguimiento(
    PDO $pdo,
    int $jovenId
): ?array {

    if ($jovenId <= 0) {

        return null;
    }

    $usuarioActual =
        usuarioId();

    if (
        $usuarioActual === null ||
        $usuarioActual <= 0
    ) {

        throw new Exception(
            'No se pudo identificar al usuario actual.'
        );
    }

    $stmt =
        $pdo->prepare("
            SELECT

                a.id,
                a.joven_id,
                a.usuario_id,
                a.asignado_por,
                a.anio,
                a.mes,
                a.estado,

                j.nombre_completo AS joven_nombre,

                u.nombre AS responsable_nombre

            FROM asignaciones_seguimiento a

            INNER JOIN jovenes j
                ON a.joven_id = j.id

            LEFT JOIN usuarios u
                ON a.usuario_id = u.id

            WHERE a.joven_id = :joven_id

            AND a.usuario_id = :usuario_id

            AND a.estado IN (
                'PENDIENTE',
                'EN_PROCESO'
            )

            ORDER BY
                a.anio DESC,
                a.mes DESC,
                a.id DESC

            LIMIT 1
        ");

    $stmt->execute([

        ':joven_id' =>
            $jovenId,

        ':usuario_id' =>
            (int)$usuarioActual

    ]);

    return $stmt->fetch(
        PDO::FETCH_ASSOC
    ) ?: null;
}


/* ==========================================================
   OBTENER ASIGNACIÓN ACTIVA DEL JOVEN
========================================================== */

function obtenerAsignacionActivaParaJoven(
    PDO $pdo,
    int $jovenId
): ?array {

    if ($jovenId <= 0) {

        return null;
    }

    $stmt =
        $pdo->prepare("
            SELECT

                a.id,
                a.joven_id,
                a.usuario_id,
                a.asignado_por,
                a.anio,
                a.mes,
                a.estado,

                j.nombre_completo AS joven_nombre,

                u.nombre AS responsable_nombre

            FROM asignaciones_seguimiento a

            INNER JOIN jovenes j
                ON a.joven_id = j.id

            LEFT JOIN usuarios u
                ON a.usuario_id = u.id

            WHERE a.joven_id = :joven_id

            AND a.estado IN (
                'PENDIENTE',
                'EN_PROCESO'
            )

            ORDER BY
                a.anio DESC,
                a.mes DESC,
                a.id DESC

            LIMIT 1
        ");

    $stmt->execute([

        ':joven_id' =>
            $jovenId

    ]);

    return $stmt->fetch(
        PDO::FETCH_ASSOC
    ) ?: null;
}


/* ==========================================================
   COMPLETAR ASIGNACIÓN DESDE SEGUIMIENTO
========================================================== */

function completarAsignacionDesdeSeguimiento(
    PDO $pdo,
    array $asignacion
): void {

    $asignacionId =
        (int)(
            $asignacion['id']
            ?? 0
        );

    if ($asignacionId <= 0) {

        throw new Exception(
            'La asignación asociada al seguimiento no es válida.'
        );
    }

    $stmt =
        $pdo->prepare("
            UPDATE asignaciones_seguimiento

            SET
                estado = 'COMPLETADO',
                fecha_completado = NOW()

            WHERE id = :id

            AND estado IN (
                'PENDIENTE',
                'EN_PROCESO'
            )
        ");

    $stmt->execute([

        ':id' =>
            $asignacionId

    ]);

    /*
     * Recuperar asignación actualizada.
     */

    $stmt =
        $pdo->prepare("
            SELECT

                a.id,
                a.joven_id,
                a.usuario_id,
                a.asignado_por,
                a.anio,
                a.mes,
                a.estado,

                j.nombre_completo AS joven_nombre,

                u.nombre AS responsable_nombre

            FROM asignaciones_seguimiento a

            INNER JOIN jovenes j
                ON a.joven_id = j.id

            LEFT JOIN usuarios u
                ON a.usuario_id = u.id

            WHERE a.id = :id

            LIMIT 1
        ");

    $stmt->execute([

        ':id' =>
            $asignacionId

    ]);

    $actualizada =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    if (!$actualizada) {

        throw new Exception(
            'No se pudo recuperar la asignación completada.'
        );
    }

    /*
     * Notificar únicamente si realmente quedó completada.
     */

    if (
        strtoupper(
            trim(
                (string)(
                    $actualizada['estado']
                    ?? ''
                )
            )
        ) !== 'COMPLETADO'
    ) {

        return;
    }

    $destinatario =
        (int)(
            $actualizada['asignado_por']
            ?? 0
        );

    if ($destinatario <= 0) {

        return;
    }

    $jovenNombre =
        $actualizada['joven_nombre']
        ?? 'este joven';

    $responsableNombre =
        $actualizada['responsable_nombre']
        ?? 'el usuario responsable';

    crearNotificacion(

        $pdo,

        [

            'usuario_id' =>
                $destinatario,

            'tipo' =>
                'ASIGNACION_COMPLETADA',

            'titulo' =>
                'Seguimiento completado',

            'mensaje' =>
                "El seguimiento de "
                . $jovenNombre
                . " fue completado por "
                . $responsableNombre
                . ".",

            'joven_id' =>
                (int)(
                    $actualizada['joven_id']
                    ?? 0
                ),

            'asignacion_id' =>
                $asignacionId

        ]

    );
}


/* ==========================================================
   RESOLVER EXCEPCIÓN DEL PERÍODO ACTUAL
   ----------------------------------------------------------
   Se elimina únicamente la excepción del mes actual
   cuando el joven registra un seguimiento FINALIZADO.
========================================================== */

function resolverExcepcionPeriodoActual(
    PDO $pdo,
    int $jovenId
): void {

    if ($jovenId <= 0) {

        return;
    }

    $stmt = $pdo->prepare("

        DELETE FROM excepciones_seguimiento

        WHERE joven_id = :joven_id

        AND anio = YEAR(CURDATE())

        AND mes = MONTH(CURDATE())

    ");

    $stmt->execute([

        ':joven_id' =>
            $jovenId

    ]);
}

/* ==========================================================
   CREAR SEGUIMIENTO
========================================================== */

function crearSeguimiento(
    PDO $pdo,
    array $datos
): int {

    if (
        !tienePermiso('gestionar_seguimientos')
        &&
        !tienePermiso('gestionar_mis_seguimientos')
    ) {

        throw new Exception(
            'No tienes permiso para registrar seguimientos.'
        );
    }


    $datos =
        prepararDatosSeguimiento(
            $pdo,
            $datos
        );


    /* ======================================================
       USUARIO ACTUAL
    ====================================================== */

    $usuarioActual =
        usuarioId();


    if (
        $usuarioActual === null ||
        $usuarioActual <= 0
    ) {

        throw new Exception(
            'No se pudo identificar al usuario actual.'
        );
    }


    $usuarioActual =
        (int)$usuarioActual;


    /* ======================================================
       BUSCAR ASIGNACIÓN ACTIVA
    ====================================================== */

    $asignacion = null;


    /*
     * USUARIO NORMAL
     *
     * Puede registrar el seguimiento aunque no exista
     * una asignación activa.
     *
     * Si existe una asignación propia:
     *
     *   FINALIZADO
     *       ↓
     *   COMPLETADO
     *
     * Si no existe:
     *
     *   se registra solamente el seguimiento.
     */

    if (
        !tienePermiso(
            'gestionar_seguimientos'
        )
    ) {

        $asignacion =
            obtenerAsignacionActivaParaUsuarioSeguimiento(
                $pdo,
                $datos['jovenId']
            );

    }


    /*
     * ADMINISTRADOR / LÍDER / SUBLÍDER
     *
     * Puede registrar sobre cualquier asignación
     * activa del joven.
     */

    else {

        $asignacion =
            obtenerAsignacionActivaParaJoven(
                $pdo,
                $datos['jovenId']
            );

    }


    /* ======================================================
       RESPONSABLE
    ====================================================== */

    $responsableFinal =
        $datos['responsableId'];


    if (
        $responsableFinal === null
    ) {

        $responsableFinal =
            $usuarioActual;

    }


    /* ======================================================
       TRANSACCIÓN
    ====================================================== */

    $transaccionPropia =
        !$pdo->inTransaction();


    if (
        $transaccionPropia
    ) {

        $pdo->beginTransaction();

    }


    try {

        /* ==================================================
           INSERTAR SEGUIMIENTO
        ================================================== */

        $stmt =
            $pdo->prepare("
                INSERT INTO seguimientos
                (
                    joven_id,
                    fecha_contacto,
                    modalidad_contacto,
                    estado_proceso,
                    responsable_id,
                    observaciones
                )
                VALUES
                (
                    :joven_id,
                    :fecha_contacto,
                    :modalidad,
                    :estado,
                    :responsable_id,
                    :observaciones
                )
            ");


        $stmt->execute([

            ':joven_id' =>
                $datos['jovenId'],

            ':fecha_contacto' =>
                $datos['fechaContacto'],

            ':modalidad' =>
                $datos['modalidad'],

            ':estado' =>
                $datos['estado'],

            ':responsable_id' =>
                $responsableFinal,

            ':observaciones' =>
                $datos['observaciones']

        ]);


        $seguimientoId =
            (int)$pdo->lastInsertId();


        /* ==================================================
           ACTUALIZAR ACTIVIDAD DEL JOVEN
        ================================================== */

        $stmt =
            $pdo->prepare("
                UPDATE jovenes

                SET

                    ultima_actividad = NOW(),

                    estado_actividad = 'ACTIVO'

                WHERE id = :id
            ");


        $stmt->execute([

            ':id' =>
                $datos['jovenId']

        ]);


        /* ==================================================
           RESOLVER EXCEPCIÓN DEL PERÍODO ACTUAL
           
           Si este contacto FINALIZADO representa que
           realmente se atendió al joven, eliminamos la
           excepción administrativa del período actual.
           
           Así:
           
           EXCEPCIÓN
                ↓
           contacto real
                ↓
           FINALIZADO
                ↓
           deja de figurar como excepción
        ================================================== */

        if (
            $datos['estado'] === 'FINALIZADO'
        ) {

            $stmt =
                $pdo->prepare("
                    DELETE FROM excepciones_seguimiento

                    WHERE joven_id = :joven_id

                    AND anio = YEAR(CURDATE())

                    AND mes = MONTH(CURDATE())
                ");


            $stmt->execute([

                ':joven_id' =>
                    $datos['jovenId']

            ]);

        }


        /* ==================================================
           COMPLETAR ASIGNACIÓN
           
           Solo cuando:
           
           - el seguimiento es FINALIZADO
           - existe una asignación activa
        ================================================== */

        if (
            $datos['estado'] === 'FINALIZADO'
            &&
            $asignacion
        ) {

            completarAsignacionDesdeSeguimiento(

                $pdo,

                $asignacion

            );

        }


        /* ==================================================
           COMMIT
        ================================================== */

        if (
            $transaccionPropia
        ) {

            $pdo->commit();

        }


        return $seguimientoId;


    } catch (
        Throwable $e
    ) {

        if (
            $transaccionPropia
            &&
            $pdo->inTransaction()
        ) {

            $pdo->rollBack();

        }


        throw $e;

    }

}


/* ==========================================================
   HISTORIAL UNIFICADO DEL PERÍODO
========================================================== */

function obtenerHistorialSeguimientosMes(
    PDO $pdo,
    ?int $anio = null,
    ?int $mes = null
): array {

    $anio ??=
        (int)date('Y');

    $mes ??=
        (int)date('m');

    if (
        $anio <= 0 ||
        $mes < 1 ||
        $mes > 12
    ) {

        return [];
    }

    /*
     * Consolidado:
     *
     * - Muestra el último seguimiento FINALIZADO
     *   de cada joven activo / nuevo.
     *
     * - La fecha puede ser histórica.
     *
     * - Las excepciones pertenecen específicamente
     *   al año/mes consultado.
     *
     * El historial completo del joven continúa mostrando
     * todos sus seguimientos.
     */

    $sql = "

        SELECT

            s.id AS registro_id,

            s.id AS seguimiento_id,

            NULL AS excepcion_id,

            s.joven_id,

            j.nombre_completo,

            j.telefono,

            j.genero,

            'SEGUIMIENTO' AS tipo_registro,

            s.modalidad_contacto,

            s.estado_proceso,

            NULL AS excepcion_motivo,

            s.observaciones,

            s.fecha_contacto AS fecha_registro,

            s.fecha_contacto,

            u.nombre AS responsable_nombre,

            s.responsable_id,

            NULL AS excepcion_anio,

            NULL AS excepcion_mes,

            NULL AS excepcion_created_at

        FROM seguimientos s

        INNER JOIN jovenes j
            ON j.id = s.joven_id

        LEFT JOIN usuarios u
            ON u.id = s.responsable_id

        WHERE j.estado_actividad = 'ACTIVO'

        AND j.estado_espiritual = 'NUEVO'

        AND s.estado_proceso = 'FINALIZADO'

        AND s.fecha_contacto IS NOT NULL

        AND s.id = (

            SELECT s2.id

            FROM seguimientos s2

            WHERE s2.joven_id = s.joven_id

            AND s2.estado_proceso = 'FINALIZADO'

            AND s2.fecha_contacto IS NOT NULL

            ORDER BY
                s2.fecha_contacto DESC,
                s2.id DESC

            LIMIT 1
        )


        UNION ALL


        SELECT

            e.id AS registro_id,

            NULL AS seguimiento_id,

            e.id AS excepcion_id,

            e.joven_id,

            j.nombre_completo,

            j.telefono,

            j.genero,

            'EXCEPCION' AS tipo_registro,

            NULL AS modalidad_contacto,

            NULL AS estado_proceso,

            e.motivo AS excepcion_motivo,

            e.observaciones,

            e.created_at AS fecha_registro,

            NULL AS fecha_contacto,

            u.nombre AS responsable_nombre,

            e.responsable_id,

            e.anio AS excepcion_anio,

            e.mes AS excepcion_mes,

            e.created_at AS excepcion_created_at

        FROM excepciones_seguimiento e

        INNER JOIN jovenes j
            ON j.id = e.joven_id

        LEFT JOIN usuarios u
            ON u.id = e.responsable_id

        WHERE e.anio = :anio_excepcion

        AND e.mes = :mes_excepcion


        ORDER BY

            fecha_registro DESC,

            nombre_completo ASC
    ";

    $stmt =
        $pdo->prepare(
            $sql
        );

    $stmt->execute([

        ':anio_excepcion' =>
            $anio,

        ':mes_excepcion' =>
            $mes

    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}


/* ==========================================================
   RESUMEN GENERAL DEL PERÍODO
========================================================== */

function obtenerResumenSeguimientosMes(
    ?PDO $pdoParam = null
): array {

    global $pdo;

    $pdo =
        $pdoParam
        ?? $pdo;

    $mesNumero =
        (int)date('m');

    $anio =
        (int)date('Y');


    $meses = [

        1  => 'Enero',
        2  => 'Febrero',
        3  => 'Marzo',
        4  => 'Abril',
        5  => 'Mayo',
        6  => 'Junio',
        7  => 'Julio',
        8  => 'Agosto',
        9  => 'Septiembre',
        10 => 'Octubre',
        11 => 'Noviembre',
        12 => 'Diciembre'

    ];


    $mesTexto =
        $meses[$mesNumero]
        . ' '
        . $anio;


    /* ======================================================
       TOTAL JÓVENES ACTIVOS / NUEVOS
    ====================================================== */

    $stmt =
        $pdo->query("
            SELECT COUNT(*)

            FROM jovenes j

            WHERE j.estado_actividad = 'ACTIVO'

            AND j.estado_espiritual = 'NUEVO'
        ");

    $totalActivos =
        (int)$stmt->fetchColumn();


    /* ======================================================
       TOTAL CON SEGUIMIENTO

       Cualquier FINALIZADO histórico cuenta.

       La excepción del período actual no duplica al joven.
    ====================================================== */

    $stmt =
        $pdo->prepare("
            SELECT COUNT(DISTINCT j.id)

            FROM jovenes j

            INNER JOIN seguimientos s
                ON s.joven_id = j.id

            WHERE j.estado_actividad = 'ACTIVO'

            AND j.estado_espiritual = 'NUEVO'

            AND s.estado_proceso = 'FINALIZADO'

            AND s.fecha_contacto IS NOT NULL

            AND NOT EXISTS (

                SELECT 1

                FROM excepciones_seguimiento e

                WHERE e.joven_id = j.id

                AND e.anio = :anio_exc

                AND e.mes = :mes_exc

            )
        ");

    $stmt->execute([

        ':anio_exc' =>
            $anio,

        ':mes_exc' =>
            $mesNumero

    ]);

    $totalConSeguimiento =
        (int)$stmt->fetchColumn();


    /* ======================================================
       TOTAL EXCEPCIONES

       Una excepción cuenta únicamente cuando el joven
       NO tiene un seguimiento FINALIZADO histórico.
    ====================================================== */

    $stmt =
        $pdo->prepare("
            SELECT COUNT(DISTINCT e.joven_id)

            FROM excepciones_seguimiento e

            INNER JOIN jovenes j
                ON j.id = e.joven_id

            WHERE e.anio = :anio

            AND e.mes = :mes

            AND j.estado_actividad = 'ACTIVO'

            AND j.estado_espiritual = 'NUEVO'

            AND NOT EXISTS (

                SELECT 1

                FROM seguimientos s

                WHERE s.joven_id = j.id

                AND s.estado_proceso = 'FINALIZADO'

                AND s.fecha_contacto IS NOT NULL

            )
        ");

    $stmt->execute([

        ':anio' =>
            $anio,

        ':mes' =>
            $mesNumero

    ]);

    $totalExcepciones =
        (int)$stmt->fetchColumn();


    /* ======================================================
       SIN SEGUIMIENTO
    ====================================================== */

    $totalSinSeguimiento =
        max(

            0,

            $totalActivos
            -
            $totalConSeguimiento
            -
            $totalExcepciones

        );


    /* ======================================================
       TOTAL ATENDIDOS
    ====================================================== */

    $totalAtendidos =
        $totalConSeguimiento
        +
        $totalExcepciones;


    /* ======================================================
       PORCENTAJE
    ====================================================== */

    $porcentaje =
        $totalActivos > 0

            ? round(

                (
                    $totalAtendidos
                    /
                    $totalActivos
                )
                *
                100

            )

            : 0;


    /* ======================================================
       SEMÁFORO
    ====================================================== */

    $color =
        'danger';

    if (
        $porcentaje >= 90
    ) {

        $color =
            'ok';

    } elseif (
        $porcentaje >= 70
    ) {

        $color =
            'warning';
    }


    /* ======================================================
       HISTORIAL DEL PERÍODO
    ====================================================== */

    $historialMes =
        obtenerHistorialSeguimientosMes(

            $pdo,

            $anio,

            $mesNumero

        );


    return [

        'mesTexto' =>
            $mesTexto,

        'totalActivos' =>
            $totalActivos,

        'seguimientosMes' =>
            $historialMes,

        'historialMes' =>
            $historialMes,

        'totalConSeguimiento' =>
            $totalConSeguimiento,

        'totalExcepciones' =>
            $totalExcepciones,

        'totalSinSeguimiento' =>
            $totalSinSeguimiento,

        'porcentaje' =>
            $porcentaje,

        'color' =>
            $color

    ];
}


/* ==========================================================
   LISTADO DE JÓVENES POR CATEGORÍA DEL RESUMEN MENSUAL
   ----------------------------------------------------------
   Mismos 4 criterios EXACTOS que obtenerResumenSeguimientosMes()
   (misma fuente de verdad), pero devolviendo las filas en vez
   de un COUNT -- para que las tarjetas del resumen puedan ser
   hipervínculos que muestren el listado real, garantizando que
   el número de la tarjeta y el listado que abre sean siempre
   el mismo conjunto de datos.
========================================================== */

function obtenerListaSeguimientosMesPorCategoria(
    PDO $pdo,
    string $categoria,
    int $anio,
    int $mes
): array {

    switch ($categoria) {

        case 'activos':

            $stmt = $pdo->prepare("
                SELECT j.id, j.nombre_completo

                FROM jovenes j

                WHERE j.estado_actividad = 'ACTIVO'
                AND j.estado_espiritual = 'NUEVO'

                ORDER BY j.nombre_completo ASC
            ");

            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        case 'con_seguimiento':

            $stmt = $pdo->prepare("
                SELECT DISTINCT j.id, j.nombre_completo

                FROM jovenes j

                INNER JOIN seguimientos s
                    ON s.joven_id = j.id

                WHERE j.estado_actividad = 'ACTIVO'
                AND j.estado_espiritual = 'NUEVO'
                AND s.estado_proceso = 'FINALIZADO'
                AND s.fecha_contacto IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1
                    FROM excepciones_seguimiento e
                    WHERE e.joven_id = j.id
                    AND e.anio = :anio
                    AND e.mes = :mes
                )

                ORDER BY j.nombre_completo ASC
            ");

            $stmt->execute(['anio' => $anio, 'mes' => $mes]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        case 'excepciones':

            $stmt = $pdo->prepare("
                SELECT DISTINCT j.id, j.nombre_completo, e.motivo

                FROM excepciones_seguimiento e

                INNER JOIN jovenes j
                    ON j.id = e.joven_id

                WHERE e.anio = :anio
                AND e.mes = :mes
                AND j.estado_actividad = 'ACTIVO'
                AND j.estado_espiritual = 'NUEVO'
                AND NOT EXISTS (
                    SELECT 1
                    FROM seguimientos s
                    WHERE s.joven_id = j.id
                    AND s.estado_proceso = 'FINALIZADO'
                    AND s.fecha_contacto IS NOT NULL
                )

                ORDER BY j.nombre_completo ASC
            ");

            $stmt->execute(['anio' => $anio, 'mes' => $mes]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        case 'sin_seguimiento':

            $stmt = $pdo->prepare("
                SELECT j.id, j.nombre_completo

                FROM jovenes j

                WHERE j.estado_actividad = 'ACTIVO'
                AND j.estado_espiritual = 'NUEVO'
                AND NOT EXISTS (
                    SELECT 1
                    FROM seguimientos s
                    WHERE s.joven_id = j.id
                    AND s.estado_proceso = 'FINALIZADO'
                    AND s.fecha_contacto IS NOT NULL
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM excepciones_seguimiento e
                    WHERE e.joven_id = j.id
                    AND e.anio = :anio
                    AND e.mes = :mes
                )

                ORDER BY j.nombre_completo ASC
            ");

            $stmt->execute(['anio' => $anio, 'mes' => $mes]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        default:

            return [];
    }
}


/* ==========================================================
   SEGUIMIENTOS POR JOVEN
========================================================== */

function obtenerSeguimientosPorJoven(
    PDO $pdo,
    int $jovenId
): array {

    if ($jovenId <= 0) {

        return [];
    }

    $stmt =
        $pdo->prepare("
            SELECT

                s.*,

                'SEGUIMIENTO' AS tipo_registro,

                NULL AS excepcion_motivo,

                u.nombre AS responsable_nombre

            FROM seguimientos s

            LEFT JOIN usuarios u
                ON s.responsable_id = u.id

            WHERE s.joven_id = :joven_id

            ORDER BY

                s.fecha_contacto DESC,

                s.id DESC
        ");

    $stmt->execute([

        ':joven_id' =>
            $jovenId

    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}


/* ==========================================================
   HISTORIAL COMPLETO POR JOVEN
========================================================== */

function obtenerHistorialJoven(
    PDO $pdo,
    int $jovenId
): array {

    if ($jovenId <= 0) {

        return [];
    }

    $stmt =
        $pdo->prepare("
            SELECT

                s.id AS registro_id,

                s.id AS seguimiento_id,

                s.joven_id,

                'SEGUIMIENTO' AS tipo_registro,

                s.modalidad_contacto,

                s.estado_proceso,

                s.observaciones,

                s.fecha_contacto AS fecha_registro,

                s.fecha_contacto,

                s.responsable_id,

                u.nombre AS responsable_nombre,

                NULL AS excepcion_id,

                NULL AS excepcion_motivo,

                NULL AS excepcion_anio,

                NULL AS excepcion_mes,

                NULL AS excepcion_created_at

            FROM seguimientos s

            LEFT JOIN usuarios u
                ON s.responsable_id = u.id

            WHERE s.joven_id = :joven_id_1


            UNION ALL


            SELECT

                e.id AS registro_id,

                NULL AS seguimiento_id,

                e.joven_id,

                'EXCEPCION' AS tipo_registro,

                NULL AS modalidad_contacto,

                NULL AS estado_proceso,

                e.observaciones,

                e.created_at AS fecha_registro,

                NULL AS fecha_contacto,

                e.responsable_id,

                u.nombre AS responsable_nombre,

                e.id AS excepcion_id,

                e.motivo AS excepcion_motivo,

                e.anio AS excepcion_anio,

                e.mes AS excepcion_mes,

                e.created_at AS excepcion_created_at

            FROM excepciones_seguimiento e

            LEFT JOIN usuarios u
                ON e.responsable_id = u.id

            WHERE e.joven_id = :joven_id_2

            ORDER BY

                fecha_registro DESC,

                registro_id DESC
        ");

    $stmt->execute([

        ':joven_id_1' =>
            $jovenId,

        ':joven_id_2' =>
            $jovenId

    ]);

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}


/* ==========================================================
   COMPATIBILIDAD
========================================================== */

function obtenerHistorialSeguimientoPorJoven(
    PDO $pdo,
    int $jovenId
): array {

    return obtenerHistorialJoven(

        $pdo,

        $jovenId

    );
}


/* ==========================================================
   COMPATIBILIDAD HISTORIAL COMPLETO
========================================================== */

function obtenerHistorialCompletoPorJoven(
    PDO $pdo,
    int $jovenId
): array {

    return obtenerHistorialJoven(

        $pdo,

        $jovenId

    );
}


/* ==========================================================
   CONTAR SEGUIMIENTOS FINALIZADOS
========================================================== */

function contarSeguimientosMes(
    PDO $pdo,
    int $jovenId
): int {

    if ($jovenId <= 0) {

        return 0;
    }

    /*
     * Se conserva el nombre de la función
     * por compatibilidad con el resto del proyecto.
     *
     * Devuelve todos los FINALIZADOS del joven,
     * no solamente los del mes actual.
     */

    $stmt =
        $pdo->prepare("
            SELECT COUNT(*)

            FROM seguimientos

            WHERE joven_id = :id

            AND estado_proceso = 'FINALIZADO'

            AND fecha_contacto IS NOT NULL
        ");

    $stmt->execute([

        ':id' =>
            $jovenId

    ]);

    return (int)$stmt->fetchColumn();
}


/* ==========================================================
   JÓVENES SIN SEGUIMIENTO
========================================================== */

function obtenerJovenesSinSeguimiento(): array {

    global $pdo;

    /*
     * SOLO APARECEN JÓVENES:
     *
     * - activos
     * - en estado NUEVO
     * - que ingresaron este mes
     *   o nunca han tenido un FINALIZADO
     * - sin excepción del período actual
     *
     * Un joven que completó su ciclo en febrero
     * no vuelve a aparecer como pendiente en agosto.
     */

    $stmt =
        $pdo->prepare("
            SELECT

                j.id,

                j.nombre_completo,

                j.telefono,

                j.genero,

                j.estado_espiritual,

                j.fecha_ingreso

            FROM jovenes j

            WHERE j.estado_actividad = 'ACTIVO'

            AND j.estado_espiritual = 'NUEVO'

            AND (

                (

                    j.fecha_ingreso IS NOT NULL

                    AND YEAR(j.fecha_ingreso)
                        = YEAR(CURDATE())

                    AND MONTH(j.fecha_ingreso)
                        = MONTH(CURDATE())

                )

                OR

                NOT EXISTS (

                    SELECT 1

                    FROM seguimientos s

                    WHERE s.joven_id = j.id

                    AND s.estado_proceso = 'FINALIZADO'

                    AND s.fecha_contacto IS NOT NULL

                )

            )

            AND NOT EXISTS (

                SELECT 1

                FROM excepciones_seguimiento e

                WHERE e.joven_id = j.id

                AND e.anio = YEAR(CURDATE())

                AND e.mes = MONTH(CURDATE())

            )

            ORDER BY

                CASE

                    WHEN

                        j.fecha_ingreso IS NOT NULL

                        AND YEAR(j.fecha_ingreso)
                            = YEAR(CURDATE())

                        AND MONTH(j.fecha_ingreso)
                            = MONTH(CURDATE())

                    THEN 0

                    ELSE 1

                END,

                j.fecha_ingreso ASC,

                j.nombre_completo ASC
        ");

    $stmt->execute();

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );
}


/* ==========================================================
   OBTENER SEGUIMIENTO POR ID
========================================================== */

function obtenerSeguimientoPorId(
    PDO $pdo,
    int $id
): ?array {

    if ($id <= 0) {

        return null;
    }

    $stmt =
        $pdo->prepare("
            SELECT

                s.*,

                'SEGUIMIENTO' AS tipo_registro,

                j.nombre_completo AS joven_nombre,

                u.nombre AS responsable_nombre

            FROM seguimientos s

            INNER JOIN jovenes j
                ON s.joven_id = j.id

            LEFT JOIN usuarios u
                ON s.responsable_id = u.id

            WHERE s.id = :id

            LIMIT 1
        ");

    $stmt->execute([

        ':id' =>
            $id

    ]);

    return $stmt->fetch(
        PDO::FETCH_ASSOC
    ) ?: null;
}


/* ==========================================================
   EXISTE SEGUIMIENTO
========================================================== */

function existeSeguimiento(
    PDO $pdo,
    int $id
): bool {

    if ($id <= 0) {

        return false;
    }

    $stmt =
        $pdo->prepare("
            SELECT id

            FROM seguimientos

            WHERE id = :id

            LIMIT 1
        ");

    $stmt->execute([

        ':id' =>
            $id

    ]);

    return (bool)$stmt->fetchColumn();
}


/* ==========================================================
   VALIDAR SEGUIMIENTO
========================================================== */

function validarSeguimiento(
    PDO $pdo,
    int $id
): void {

    if ($id <= 0) {

        throw new Exception(
            'Seguimiento inválido.'
        );
    }

    if (
        !existeSeguimiento(
            $pdo,
            $id
        )
    ) {

        throw new Exception(
            'El seguimiento no existe.'
        );
    }
}


/* ==========================================================
   ELIMINAR SEGUIMIENTO
========================================================== */

function eliminarSeguimiento(
    PDO $pdo,
    int $id
): void {

    exigirPermiso(
        'gestionar_seguimientos'
    );

    validarSeguimiento(
        $pdo,
        $id
    );

    $stmt =
        $pdo->prepare("
            DELETE

            FROM seguimientos

            WHERE id = :id
        ");

    $stmt->execute([

        ':id' =>
            $id

    ]);
}