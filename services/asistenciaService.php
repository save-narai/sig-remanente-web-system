<?php

declare(strict_types=1);

/* ==========================================================
   ASISTENCIA SERVICE V3.2

   RESPONSABILIDAD

   - Validar la solicitud de asistencia.
   - Obtener la reunión.
   - Obtener los jóvenes participantes.
   - Construir el contexto.
   - Extraer los checklists enviados.
   - Construir registros individuales.
   - Delegar la lógica especializada.

   IMPORTANTE

   Este Service NO contiene la lógica interna del
   discipulado.

   Este Service NO calcula indicadores.

   Este Service coordina los servicios especializados.
========================================================== */


/* ==========================================================
   SERVICIOS ESPECIALIZADOS
========================================================== */

require_once __DIR__ . '/actividadService.php';
require_once __DIR__ . '/discipuladoService.php';
require_once __DIR__ . '/historialService.php';


/*
   indicadoresService.php todavía NO existe.

   NO se incluye aquí hasta que creemos ese Service.
*/


/* ==========================================================
   OBTENER TIPO DE REUNIÓN
========================================================== */

function obtenerTipoReunion(
    PDO $pdo,
    int $reunionId
): string|false {

    $stmt = $pdo->prepare("

        SELECT tipo

        FROM reuniones

        WHERE id = :id

        LIMIT 1

    ");

    $stmt->execute([

        'id' => $reunionId

    ]);

    $tipo = $stmt->fetchColumn();

    if ($tipo === false) {

        return false;

    }

    return (string)$tipo;

}


/* ==========================================================
   OBTENER FECHA DE REUNIÓN (FASE 3)

   Funcion nueva y minima, separada de obtenerTipoReunion()
   para no tocar su firma ni a su unico consumidor existente.
   Se usa para prevenir asistencias retroactivas incorrectas
   en procesarJovenes() (ver seccion 10 del prompt de Fase 3).
========================================================== */

function obtenerFechaReunion(
    PDO $pdo,
    int $reunionId
): ?string {

    $stmt = $pdo->prepare("

        SELECT fecha

        FROM reuniones

        WHERE id = :id

        LIMIT 1

    ");

    $stmt->execute([
        'id' => $reunionId
    ]);

    $fecha = $stmt->fetchColumn();

    return $fecha !== false ? (string) $fecha : null;

}


/* ==========================================================
   OBTENER PARTICIPANTES PARA ASISTENCIA

   NOTA (corrección de conflicto): esta función NO es la
   misma que obtenerJovenesActivos() de jovenService.php, y
   nunca debió compartir su nombre -- son dos consultas con
   propósito y filtro distintos:

     - jovenService.php::obtenerJovenesActivos() trae solo
       estado_actividad='ACTIVO' (id, nombre_completo,
       telefono, genero, estado_espiritual). La usa
       discipuladoService.php para elegibilidad de
       inscripción.

     - Esta función trae estado_actividad <> 'ELIMINADO'
       (incluye ACTIVO e INACTIVO) más edad, fecha_ingreso y
       fecha_creacion. Un joven INACTIVO igual debe poder
       recibir su fila de asistencia (asistir de nuevo es
       justamente lo que puede revertir su estado en el
       modelo 4/12) -- usar el filtro más estricto de
       jovenService.php aquí sería una regresión real, no
       una simplificación.

   Se renombra para eliminar el "Cannot redeclare" sin alterar
   el comportamiento de ninguna de las dos: cada una conserva
   su propio nombre, su propio filtro y su único consumidor.
========================================================== */

function obtenerParticipantesAsistencia(
    PDO $pdo
): array {

    /*
       FASE 3: se agregan fecha_ingreso y fecha_creacion.
       Se usan unicamente en procesarJovenes() para evitar
       crear retroactivamente asistencia de jovenes que no
       correspondian temporalmente a una reunion (ver
       auditoria previa: reabrir una reunion antigua no debe
       fabricar ausencias para quien todavia no existia).
       No cambia el comportamiento para ningun otro consumidor
       de este arreglo.
    */

    $stmt = $pdo->query("

        SELECT
            id,
            nombre_completo,
            edad,
            estado_actividad,
            fecha_ingreso,
            fecha_creacion

        FROM jovenes

        WHERE estado_actividad <> 'ELIMINADO'

        ORDER BY nombre_completo ASC

    ");

    return $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );

}


/* ==========================================================
   VALIDAR SOLICITUD
========================================================== */

function validarSolicitudAsistencia(
    array $datos
): void {

    if (
        empty($datos['reunion_id'])
    ) {

        throw new Exception(
            'La reunión es obligatoria.'
        );

    }


    if (
        !is_numeric($datos['reunion_id'])
    ) {

        throw new Exception(
            'El ID de la reunión no es válido.'
        );

    }

}


/* ==========================================================
   OBTENER CONTEXTO DE ASISTENCIA
========================================================== */

function obtenerContextoAsistencia(
    PDO $pdo,
    array $datos
): array {

    $reunionId =
        (int)$datos['reunion_id'];


    /* ------------------------------------------------------
       OBTENER TIPO
    ------------------------------------------------------ */

    $tipo =
        obtenerTipoReunion(
            $pdo,
            $reunionId
        );


    if ($tipo === false) {

        throw new Exception(
            'La reunión no existe.'
        );

    }


    $tipo =
        strtoupper(
            trim($tipo)
        );


    /* ------------------------------------------------------
       OBTENER FECHA (FASE 3)

       Se usa exclusivamente para no crear retroactivamente
       asistencia de jovenes que no correspondian
       temporalmente a esta reunion (ver procesarJovenes()).
    ------------------------------------------------------ */

    $fechaReunion =
        obtenerFechaReunion(
            $pdo,
            $reunionId
        );


    /* ------------------------------------------------------
       OBTENER PARTICIPANTES
    ------------------------------------------------------ */

    $jovenes =
        obtenerParticipantesAsistencia(
            $pdo
        );


    return [

        /* --------------------------------------------------
           REUNIÓN
        -------------------------------------------------- */

        'reunion_id' =>
            $reunionId,

        'tipo_reunion' =>
            $tipo,

        'fecha_reunion' =>
            $fechaReunion,


        /* --------------------------------------------------
           PARTICIPANTES
        -------------------------------------------------- */

        'jovenes' =>
            $jovenes,


        /* --------------------------------------------------
           CONTEXTO MINISTERIAL
        -------------------------------------------------- */

        'contexto' => [

            'es_reunion_jovenes' =>
                $tipo === 'REUNION_JOVENES',

            'es_grupo_conexion' =>
                $tipo === 'GRUPO_CONEXION',

            'es_discipulado' =>
                $tipo === 'DISCIPULADO',

            'es_evento_especial' =>
                $tipo === 'EVENTO_ESPECIAL'

        ]

    ];

}


/* ==========================================================
   OBTENER CHECKLISTS
========================================================== */

function obtenerChecklists(
    array $datos
): array {

    return [

        /* --------------------------------------------------
           ASISTENCIA
        -------------------------------------------------- */

        'asistencia' =>
            is_array(
                $datos['asistencia'] ?? null
            )
                ? $datos['asistencia']
                : [],


        /* --------------------------------------------------
           PRIMERA VEZ

           Aplica a cualquier tipo de reunión.

           Representa:
           "Primera vez en esta reunión/espacio".
        -------------------------------------------------- */

        'primera_vez' =>
            is_array(
                $datos['primera_vez'] ?? null
            )
                ? $datos['primera_vez']
                : [],


        /* --------------------------------------------------
           GRUPO DE EDAD
        -------------------------------------------------- */

        'grupo_edad' =>
            is_array(
                $datos['grupo_edad'] ?? null
            )
                ? $datos['grupo_edad']
                : []

    ];

}


/* ==========================================================
   CONSTRUIR REGISTRO DE ASISTENCIA
========================================================== */

function construirRegistroAsistencia(
    array $contexto,
    int $jovenId,
    array $checklists
): array {

    /* ------------------------------------------------------
       DETERMINAR ASISTENCIA
    ------------------------------------------------------ */

    $asistio =
        isset(
            $checklists['asistencia'][$jovenId]
        )
            ? 1
            : 0;


    /* ------------------------------------------------------
       DETERMINAR TIPO DE REUNIÓN
    ------------------------------------------------------ */

    $tipoReunion =
        $contexto['tipo_reunion'];


    /* ------------------------------------------------------
       DISCIPULADO AUTOMÁTICO

       Si la reunión es DISCIPULADO y el joven asistió,
       participa_discipulado = 1.

       No existe checkbox independiente.
    ------------------------------------------------------ */

    $participaDiscipulado =
        (
            $tipoReunion === 'DISCIPULADO'
            &&
            $asistio === 1
        )
            ? 1
            : 0;


    /* ------------------------------------------------------
       GRUPO DE CONEXIÓN AUTOMÁTICO

       Si la reunión es GRUPO_CONEXION y el joven asistió,
       grupo_conexion = 1.

       No existe checkbox independiente.
    ------------------------------------------------------ */

    $grupoConexion =
        (
            $tipoReunion === 'GRUPO_CONEXION'
            &&
            $asistio === 1
        )
            ? 1
            : 0;


    /* ------------------------------------------------------
       PRIMERA VEZ EN LA REUNIÓN

       Se registra cuando:

       1. El joven asistió.
       2. Se marcó explícitamente como primera vez.

       Este dato aplica a cualquier tipo de reunión.

       IMPORTANTE:

       Esto NO determina si el joven entra por primera vez
       al proceso de discipulado.

       Esa lógica pertenece a discipuladoService.php.
    ------------------------------------------------------ */

    $primeraVez =
        (
            $asistio === 1
            &&
            isset(
                $checklists['primera_vez'][$jovenId]
            )
        )
            ? 1
            : 0;


    return [

        /* --------------------------------------------------
           IDENTIFICACIÓN
        -------------------------------------------------- */

        'reunion_id' =>
            (int)$contexto['reunion_id'],

        'tipo_reunion' =>
            $tipoReunion,

        'joven_id' =>
            $jovenId,


        /* --------------------------------------------------
           ASISTENCIA
        -------------------------------------------------- */

        'asistio' =>
            $asistio,


        /* --------------------------------------------------
           GRUPO DE EDAD
        -------------------------------------------------- */

        'grupo_edad' =>
            $checklists['grupo_edad'][$jovenId]
            ?? null,


        /* --------------------------------------------------
           DISCIPULADO
        -------------------------------------------------- */

        'participa_discipulado' =>
            $participaDiscipulado,


        /* --------------------------------------------------
           GRUPO DE CONEXIÓN
        -------------------------------------------------- */

        'grupo_conexion' =>
            $grupoConexion,


        /* --------------------------------------------------
           PRIMERA VEZ EN LA REUNIÓN
        -------------------------------------------------- */

        'primera_vez' =>
            $primeraVez,


        /* --------------------------------------------------
           CONTEXTO
        -------------------------------------------------- */

        'contexto' =>
            $contexto['contexto']

    ];

}


/* ==========================================================
   GUARDAR / ACTUALIZAR REGISTRO DE ASISTENCIA
========================================================== */

function guardarRegistroAsistencia(
    PDO $pdo,
    array $datos
): void {

    $stmt = $pdo->prepare("

        INSERT INTO asistencia
        (
            reunion_id,
            joven_id,
            asistio,
            grupo_edad,
            participa_discipulado,
            grupo_conexion,
            primera_vez,
            fecha_registro
        )

        VALUES
        (
            :reunion,
            :joven,
            :asistio,
            :grupo_edad,
            :discipulado,
            :conexion,
            :primera_vez,
            NOW()
        )

        ON DUPLICATE KEY UPDATE

            asistio =
                VALUES(asistio),

            grupo_edad =
                VALUES(grupo_edad),

            participa_discipulado =
                VALUES(participa_discipulado),

            grupo_conexion =
                VALUES(grupo_conexion),

            primera_vez =
                VALUES(primera_vez),

            fecha_registro =
                NOW()
    ");


    $stmt->execute([

        ':reunion' =>
            (int)$datos['reunion_id'],

        ':joven' =>
            (int)$datos['joven_id'],

        ':asistio' =>
            (int)$datos['asistio'],

        ':grupo_edad' =>
            $datos['grupo_edad'] ?? null,

        ':discipulado' =>
            (int)(
                $datos['participa_discipulado']
                ?? 0
            ),

        ':conexion' =>
            (int)(
                $datos['grupo_conexion']
                ?? 0
            ),

        ':primera_vez' =>
            (int)(
                $datos['primera_vez']
                ?? 0
            )

    ]);

}


/* ==========================================================
   ACTUALIZAR ACTIVIDAD DEL JOVEN
========================================================== */

function actualizarActividadJoven(
    PDO $pdo,
    int $jovenId
): void {

    $stmt = $pdo->prepare("

        UPDATE jovenes

        SET ultima_actividad = NOW()

        WHERE id = :id

    ");


    $stmt->execute([

        ':id' =>
            $jovenId

    ]);

}


/* ==========================================================
   PROCESAR ACTIVIDAD

   Solo actualiza la última actividad cuando el joven
   realmente asistió.
========================================================== */

function procesarActividad(
    PDO $pdo,
    array $registro
): void {

    if (
        (int)$registro['asistio'] !== 1
    ) {

        return;

    }


    actualizarActividadJoven(
        $pdo,
        (int)$registro['joven_id']
    );

}


/* ==========================================================
   PROCESAR HISTORIAL

   Registra el evento de asistencia únicamente cuando
   el joven asistió.

   IMPORTANTE:

   No vuelve a calcular discipulado ni conexión.

   Utiliza los valores que ya fueron calculados por
   construirRegistroAsistencia().
========================================================== */

function procesarHistorial(
    PDO $pdo,
    array $registro
): void {

    if (
        (int)$registro['asistio'] !== 1
    ) {

        return;

    }


    /* ------------------------------------------------------
       El historialService es responsable del almacenamiento
       del evento.
    ------------------------------------------------------ */

    if (
        function_exists('registrarEventoHistorial')
    ) {

        registrarEventoHistorial(

            $pdo,

            [

                'joven_id' =>
                    (int)$registro['joven_id'],

                'reunion_id' =>
                    (int)$registro['reunion_id'],

                'tipo_evento' =>
                    defined('EVENTO_ASISTENCIA')
                        ? EVENTO_ASISTENCIA
                        : 'ASISTENCIA',

                'titulo' =>
                    'Asistencia registrada',

                'descripcion' =>
                    'Se registró asistencia en una reunión.',

                'datos_json' => [

                    'tipo_reunion' =>
                        $registro['tipo_reunion'],

                    'grupo_edad' =>
                        $registro['grupo_edad'],


                    /* --------------------------------------------------
                       DISCIPULADO

                       Usa el valor ya calculado por el registro.
                    -------------------------------------------------- */

                    'participa_discipulado' =>
                        (int)(
                            $registro['participa_discipulado']
                            ?? 0
                        ),


                    /* --------------------------------------------------
                       GRUPO DE CONEXIÓN

                       Usa el valor ya calculado por el registro.
                    -------------------------------------------------- */

                    'grupo_conexion' =>
                        (int)(
                            $registro['grupo_conexion']
                            ?? 0
                        ),


                    /* --------------------------------------------------
                       PRIMERA VEZ EN LA REUNIÓN

                       Usa el valor ya calculado por el registro.
                    -------------------------------------------------- */

                    'primera_vez' =>
                        (int)(
                            $registro['primera_vez']
                            ?? 0
                        )

                ],

            ]

        );

    }

}


/* ==========================================================
   PROCESAR REGISTRO INDIVIDUAL

   Este es el coordinador de un solo joven.

   ORDEN:

   1. Guardar asistencia.
   2. Actualizar actividad.
   3. Procesar historial.
   4. Procesar discipulado.

   IMPORTANTE:

   NO se define procesarDiscipulado() aquí.

   Esa función pertenece exclusivamente a:

   services/discipuladoService.php
========================================================== */

function procesarRegistro(
    PDO $pdo,
    array $registro
): void {

    /* ------------------------------------------------------
       1. GUARDAR ASISTENCIA
    ------------------------------------------------------ */

    guardarRegistroAsistencia(
        $pdo,
        $registro
    );


    /* ------------------------------------------------------
       2. ACTIVIDAD
    ------------------------------------------------------ */

    procesarActividad(
        $pdo,
        $registro
    );


    /* ------------------------------------------------------
       3. HISTORIAL
    ------------------------------------------------------ */

    procesarHistorial(
        $pdo,
        $registro
    );


    /* ------------------------------------------------------
       4. DISCIPULADO

       Solo delegamos.

       NO implementamos la lógica aquí.
    ------------------------------------------------------ */

    procesarDiscipulado(
        $pdo,
        $registro['tipo_reunion'],
        $registro
    );

}


/* ==========================================================
   PROCESAR JÓVENES

   Recorre todos los participantes activos y construye
   el registro correspondiente a cada uno.
========================================================== */

function procesarJovenes(
    PDO $pdo,
    array $contexto,
    array $checklists
): void {

    /* ------------------------------------------------------
       VALIDACIÓN
    ------------------------------------------------------ */

    if (
        empty($contexto['jovenes'])
    ) {

        return;

    }


    /* ------------------------------------------------------
       RECORRER PARTICIPANTES
    ------------------------------------------------------ */

    foreach (
        $contexto['jovenes']
        as $joven
    ) {

        /* --------------------------------------------------
           SOPORTAR ARRAY COMPLETO O ID
        -------------------------------------------------- */

        if (
            is_array($joven)
        ) {

            $jovenId =
                (int)($joven['id'] ?? 0);

        } else {

            $jovenId =
                (int)$joven;

        }


        /* --------------------------------------------------
           IGNORAR ID INVÁLIDO
        -------------------------------------------------- */

        if (
            $jovenId <= 0
        ) {

            continue;

        }


        /* --------------------------------------------------
           FASE 3 -- PREVENIR ASISTENCIA RETROACTIVA
           INCORRECTA

           Si el joven ingreso despues de la fecha de esta
           reunion, o ni siquiera existia todavia en el
           sistema en esa fecha, NO se crea/actualiza su
           registro de asistencia para ella. Esto evita que
           reabrir y volver a guardar una reunion antigua
           fabrique ausencias para alguien que no
           correspondia temporalmente a ese evento.

           No borra ni altera ninguna asistencia ya existente:
           solo evita crear una nueva en este caso.
        -------------------------------------------------- */

        if (
            is_array($joven)
            &&
            !empty($contexto['fecha_reunion'])
        ) {

            $fechaReunion =
                $contexto['fecha_reunion'];

            $fechaIngresoJoven =
                $joven['fecha_ingreso'] ?? null;

            if (
                $fechaIngresoJoven !== null
                &&
                $fechaIngresoJoven > $fechaReunion
            ) {

                continue;

            }

            if (
                $fechaIngresoJoven === null
                &&
                !empty($joven['fecha_creacion'])
            ) {

                $fechaCreacionJoven =
                    substr((string) $joven['fecha_creacion'], 0, 10);

                if ($fechaCreacionJoven > $fechaReunion) {

                    continue;

                }
            }
        }


        /* --------------------------------------------------
           CONSTRUIR REGISTRO
        -------------------------------------------------- */

        $registro =
            construirRegistroAsistencia(
                $contexto,
                $jovenId,
                $checklists
            );


        /* --------------------------------------------------
           PROCESAR REGISTRO
        -------------------------------------------------- */

        procesarRegistro(
            $pdo,
            $registro
        );

    }

}


/* ==========================================================
   INICIAR TRANSACCIÓN
========================================================== */

function iniciarTransaccion(
    PDO $pdo
): void {

    if (
        !$pdo->inTransaction()
    ) {

        $pdo->beginTransaction();

    }

}


/* ==========================================================
   CONFIRMAR TRANSACCIÓN
========================================================== */

function confirmarTransaccion(
    PDO $pdo
): void {

    if (
        $pdo->inTransaction()
    ) {

        $pdo->commit();

    }

}


/* ==========================================================
   CANCELAR TRANSACCIÓN
========================================================== */

function cancelarTransaccion(
    PDO $pdo
): void {

    if (
        $pdo->inTransaction()
    ) {

        $pdo->rollBack();

    }

}


/* ==========================================================
   VALIDAR CONTEXTO DE EJECUCIÓN
========================================================== */

function validarContextoEjecucion(
    array $contexto,
    array $checklists
): void {

    if (
        empty($contexto['reunion_id'])
    ) {

        throw new Exception(
            'No existe la reunión.'
        );

    }


    if (
        empty($contexto['tipo_reunion'])
    ) {

        throw new Exception(
            'No existe el tipo de reunión.'
        );

    }


    if (
        !isset($contexto['jovenes'])
        ||
        !is_array($contexto['jovenes'])
    ) {

        throw new Exception(
            'La lista de jóvenes es inválida.'
        );

    }


    if (
        !is_array($checklists)
    ) {

        throw new Exception(
            'Los checklists son inválidos.'
        );

    }

}


/* ==========================================================
   EJECUTAR REGISTRO DE ASISTENCIA
========================================================== */

function ejecutarRegistroAsistencia(
    PDO $pdo,
    array $contexto,
    array $checklists
): void {

    /* ------------------------------------------------------
       VALIDAR ANTES DE ABRIR TRANSACCIÓN
    ------------------------------------------------------ */

    validarContextoEjecucion(
        $contexto,
        $checklists
    );


    /* ------------------------------------------------------
       INICIAR TRANSACCIÓN
    ------------------------------------------------------ */

    iniciarTransaccion($pdo);


    try {

        /* --------------------------------------------------
           PROCESAR TODOS LOS JÓVENES
        -------------------------------------------------- */

        procesarJovenes(
            $pdo,
            $contexto,
            $checklists
        );


        /* --------------------------------------------------
           CONFIRMAR TODO
        -------------------------------------------------- */

        confirmarTransaccion(
            $pdo
        );


    } catch (Throwable $e) {

        /* --------------------------------------------------
           SI ALGO FALLA, DESHACER TODO
        -------------------------------------------------- */

        cancelarTransaccion(
            $pdo
        );

        throw $e;

    }

}


/* ==========================================================
   LIMPIAR CONTEXTO
========================================================== */

function limpiarContexto(
    array &$contexto
): void {

    unset(
        $contexto['contexto']
    );

}


/* ==========================================================
   GUARDAR ASISTENCIA

   PUNTO ÚNICO DE ENTRADA DEL MÓDULO.
========================================================== */

function guardarAsistencia(
    PDO $pdo,
    array $datos
): void {

    /* ------------------------------------------------------
       1. VALIDAR SOLICITUD
    ------------------------------------------------------ */

    validarSolicitudAsistencia(
        $datos
    );


    /* ------------------------------------------------------
       2. OBTENER CONTEXTO
    ------------------------------------------------------ */

    $contexto =
        obtenerContextoAsistencia(
            $pdo,
            $datos
        );


    /* ------------------------------------------------------
       3. OBTENER CHECKLISTS
    ------------------------------------------------------ */

    $checklists =
        obtenerChecklists(
            $datos
        );


    /* ------------------------------------------------------
       4. PROCESAR TODO
    ------------------------------------------------------ */

    ejecutarRegistroAsistencia(
        $pdo,
        $contexto,
        $checklists
    );


    /* ------------------------------------------------------
       5. LIMPIAR CONTEXTO
    ------------------------------------------------------ */

    limpiarContexto(
        $contexto
    );

}


/* ==========================================================
   FIN ASISTENCIA SERVICE
========================================================== */