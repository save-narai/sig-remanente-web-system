<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/validaciones.php';
require_once __DIR__ . '/jovenService.php';
require_once __DIR__ . '/usuarioService.php';
require_once __DIR__ . '/historialService.php';
require_once __DIR__ . '/reunionService.php';

/* ==========================================================
   DISCIPULADO SERVICE V3.0 — FORMACIÓN POR CICLOS
   ----------------------------------------------------------
   REESTRUCTURACIÓN (FASE 3):

   La versión anterior de este Service (V2.0) trabajaba sobre
   las tablas `discipulados` y `discipulado_clases`, que NUNCA
   llegaron a crearse en la base de datos real del proyecto
   (confirmado en la auditoría: ninguna consulta a esas tablas
   pudo haberse ejecutado jamás, y ningún controlador llamaba
   a esas funciones). Por eso se reescribe por completo en
   lugar de "migrarse": no existía dato ni comportamiento en
   producción que preservar. El archivo original queda
   respaldado en discipuladoService.php.v2.bak.

   La nueva versión trabaja sobre el modelo por ciclos:

       ciclos_discipulado
       clases_discipulado
       discipulado_inscripciones
       discipulado_progreso
       discipulado_reuniones
       discipulado_eventos
       discipulado_observaciones

   ALCANCE DE ESTA FASE (Fase 3):

   Únicamente CICLOS de discipulado (listar, obtener, crear,
   editar, cambiar estado). Las funciones de clases,
   inscripciones y progreso se agregan en las fases
   siguientes (4, 5 y 6), sobre esas mismas tablas ya creadas
   en la migración de la Fase 2.

   IMPORTANTE — PUNTO DE ENGANCHE CONSERVADO:

   La función procesarDiscipulado($pdo, $tipoReunion, $registro)
   sigue existiendo, con la misma firma, y sigue siendo llamada
   por asistenciaService.php::procesarRegistro(). Su lógica
   real (decidir si debe registrar una clase completada) se
   implementa en la Fase 7/8, cuando existan también las
   funciones de inscripciones y progreso. Hasta entonces
   permanece como no-operación segura, exactamente como
   estaba antes de esta reestructuración.
========================================================== */


/* ==========================================================
   CONSTANTES DE ESTADO

   Se reutilizan para `ciclos_discipulado.estado` y, en la
   Fase 5, también para `discipulado_inscripciones.estado`
   (comparten el mismo dominio de valores).
========================================================== */

const DISCIPULADO_ACTIVO = 'ACTIVO';

const DISCIPULADO_FINALIZADO = 'FINALIZADO';

const DISCIPULADO_CANCELADO = 'CANCELADO';

const ESTADOS_CICLO_DISCIPULADO = [

    DISCIPULADO_ACTIVO,

    DISCIPULADO_FINALIZADO,

    DISCIPULADO_CANCELADO

];


/* ==========================================================
   VALIDAR ESTADO DE CICLO
========================================================== */

function validarEstadoCicloDiscipulado(
    string $estado
): void {

    if (
        !in_array(
            strtoupper($estado),
            ESTADOS_CICLO_DISCIPULADO,
            true
        )
    ) {

        throw new Exception(
            'Estado de ciclo inválido.'
        );

    }

}


/* ==========================================================
   OBTENER CICLOS DE DISCIPULADO

   Lista todos los ciclos con sus contadores (clases e
   inscritos) para la vista de listado. Acepta filtro
   opcional por estado.
========================================================== */

function obtenerCiclosDiscipulado(
    PDO $pdo,
    array $filtros = []
): array {

    $condiciones = [];

    $parametros = [];

    if (
        !empty($filtros['estado'])
    ) {

        validarEstadoCicloDiscipulado(
            (string)$filtros['estado']
        );

        $condiciones[] = 'c.estado = :estado';

        $parametros['estado'] =
            strtoupper((string)$filtros['estado']);

    }

    $where =
        $condiciones
            ? ('WHERE ' . implode(' AND ', $condiciones))
            : '';

    $stmt = $pdo->prepare("

        SELECT

            c.id,

            c.nombre,

            c.descripcion,

            c.fecha_inicio,

            c.fecha_fin,

            c.estado,

            c.fecha_creacion,

            (
                SELECT COUNT(*)
                FROM clases_discipulado cd
                WHERE cd.ciclo_id = c.id
            ) AS total_clases,

            (
                SELECT COUNT(*)
                FROM discipulado_inscripciones di
                WHERE di.ciclo_id = c.id
            ) AS total_inscritos,

            (
                SELECT COUNT(*)
                FROM discipulado_inscripciones di
                WHERE di.ciclo_id = c.id
                AND di.estado = 'ACTIVO'
            ) AS total_inscritos_activos

        FROM ciclos_discipulado c

        {$where}

        ORDER BY c.fecha_inicio DESC, c.id DESC

    ");

    $stmt->execute($parametros);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);

}


/* ==========================================================
   OBTENER CICLO POR ID

   Incluye los responsables asignados.
========================================================== */

function obtenerCicloDiscipuladoPorId(
    PDO $pdo,
    int $id
): array|false {

    $stmt = $pdo->prepare("

        SELECT *

        FROM ciclos_discipulado

        WHERE id = :id

        LIMIT 1

    ");

    $stmt->execute([
        'id' => $id
    ]);

    $ciclo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ciclo) {

        return false;

    }

    $ciclo['responsables'] =
        obtenerResponsablesCicloDiscipulado(
            $pdo,
            $id
        );

    return $ciclo;

}


/* ==========================================================
   EXISTE CICLO CON EL MISMO NOMBRE

   NOTA: la tabla `ciclos_discipulado` no tiene una llave
   UNIQUE sobre `nombre` (un mismo nombre no es técnicamente
   imposible: por ejemplo dos sedes podrían usar "Ciclo 1").
   Esta validación es una protección a nivel de aplicación
   para evitar duplicados accidentales, no una restricción
   de base de datos.
========================================================== */

function existeCicloDiscipuladoConNombre(
    PDO $pdo,
    string $nombre,
    ?int $excluirId = null
): bool {

    $sql = "
        SELECT COUNT(*)
        FROM ciclos_discipulado
        WHERE UPPER(nombre) = UPPER(:nombre)
    ";

    $parametros = [
        'nombre' => $nombre
    ];

    if ($excluirId !== null) {

        $sql .= ' AND id <> :excluir_id';

        $parametros['excluir_id'] = $excluirId;

    }

    $stmt = $pdo->prepare($sql);

    $stmt->execute($parametros);

    return (int)$stmt->fetchColumn() > 0;

}


/* ==========================================================
   OBTENER RESPONSABLES DE UN CICLO
========================================================== */

function obtenerResponsablesCicloDiscipulado(
    PDO $pdo,
    int $cicloId
): array {

    $stmt = $pdo->prepare("

        SELECT

            u.id,

            u.nombre

        FROM ciclo_discipulado_responsables cdr

        INNER JOIN usuarios u
            ON u.id = cdr.usuario_id

        WHERE cdr.ciclo_id = :ciclo_id

        ORDER BY u.nombre ASC

    ");

    $stmt->execute([
        'ciclo_id' => $cicloId
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);

}


/* ==========================================================
   GUARDAR RESPONSABLES DE UN CICLO

   Reemplaza la lista completa (borra e inserta), igual que
   guardarPermisosRol() en rolService.php.

   Debe llamarse dentro de una transacción ya abierta por el
   llamador.
========================================================== */

function guardarResponsablesCicloDiscipulado(
    PDO $pdo,
    int $cicloId,
    array $usuarioIds
): void {

    $stmt = $pdo->prepare("

        DELETE FROM ciclo_discipulado_responsables

        WHERE ciclo_id = :ciclo_id

    ");

    $stmt->execute([
        'ciclo_id' => $cicloId
    ]);

    $usuarioIds = array_unique(

        array_filter(

            array_map(
                'intval',
                $usuarioIds
            ),

            fn (int $id) => $id > 0

        )

    );

    if (empty($usuarioIds)) {

        return;

    }

    $stmt = $pdo->prepare("

        INSERT INTO ciclo_discipulado_responsables
        (
            ciclo_id,
            usuario_id
        )
        VALUES
        (
            :ciclo_id,
            :usuario_id
        )

    ");

    foreach ($usuarioIds as $usuarioId) {

        $stmt->execute([
            'ciclo_id' => $cicloId,
            'usuario_id' => $usuarioId
        ]);

    }

}


/* ==========================================================
   VALIDAR DATOS BÁSICOS DE CICLO

   Compartido por crear y editar.
========================================================== */

function validarDatosCicloDiscipulado(
    string $nombre,
    string $fechaInicio,
    string $fechaFin
): void {

    if ($nombre === '') {

        throw new Exception(
            'Debe ingresar el nombre del ciclo.'
        );

    }

    if (!validarFecha($fechaInicio)) {

        throw new Exception(
            'La fecha de inicio no es válida.'
        );

    }

    if (
        $fechaFin !== ''
        &&
        !validarFecha($fechaFin)
    ) {

        throw new Exception(
            'La fecha de finalización no es válida.'
        );

    }

    if (
        $fechaFin !== ''
        &&
        strtotime($fechaFin) < strtotime($fechaInicio)
    ) {

        throw new Exception(
            'La fecha de finalización no puede ser anterior a la fecha de inicio.'
        );

    }

}


/* ==========================================================
   CREAR CICLO DE DISCIPULADO
========================================================== */

function crearCicloDiscipulado(
    PDO $pdo,
    array $datos
): int {

    $nombre = trim((string)($datos['nombre'] ?? ''));

    $descripcion = trim((string)($datos['descripcion'] ?? ''));

    $fechaInicio = trim((string)($datos['fecha_inicio'] ?? ''));

    $fechaFin = trim((string)($datos['fecha_fin'] ?? ''));

    $responsables = $datos['responsables'] ?? [];

    $monitorId = (int)($datos['monitor_id'] ?? 0);
    $encargadoId = (int)($datos['encargado_principal_id'] ?? 0);

    if (!is_array($responsables)) {

        $responsables = [];

    }

    /* --------------------------------------------------
       ESTADO INICIAL (revisión previa a la Fase 6)

       Por defecto un ciclo nace ACTIVO, igual que en la
       Fase 3. Pero ahora también se puede crear como
       PLANIFICADO, para preparar un ciclo futuro (crear
       clases, inscribir participantes) antes de que
       comience formalmente. No se admite crear un ciclo
       ya FINALIZADO o CANCELADO.
    -------------------------------------------------- */

    $estadoInicial = strtoupper(
        trim((string)($datos['estado'] ?? DISCIPULADO_ACTIVO))
    );

    if (
        !in_array(
            $estadoInicial,
            [DISCIPULADO_ACTIVO],
            true
        )
    ) {

        $estadoInicial = DISCIPULADO_ACTIVO;

    }

    validarDatosCicloDiscipulado(
        $nombre,
        $fechaInicio,
        $fechaFin
    );

    foreach ([$monitorId, $encargadoId] as $usuarioIdResponsable) {
        if ($usuarioIdResponsable > 0 && !existeUsuario($pdo, $usuarioIdResponsable)) {
            throw new Exception('El monitor o encargado principal seleccionado no existe.');
        }
    }

    if (
        existeCicloDiscipuladoConNombre(
            $pdo,
            $nombre
        )
    ) {

        throw new Exception(
            'Ya existe un ciclo de discipulado con ese nombre.'
        );

    }

    $pdo->beginTransaction();

    try {

        $stmt = $pdo->prepare("

            INSERT INTO ciclos_discipulado
            (
                nombre,
                descripcion,
                fecha_inicio,
                fecha_fin,
                estado,
                creado_por,
                monitor_id,
                encargado_principal_id
            )
            VALUES
            (
                :nombre,
                :descripcion,
                :fecha_inicio,
                :fecha_fin,
                :estado,
                :creado_por,
                :monitor_id,
                :encargado_principal_id
            )

        ");

        $stmt->execute([

            'nombre' => $nombre,

            'descripcion' =>
                $descripcion !== '' ? $descripcion : null,

            'fecha_inicio' => $fechaInicio,

            'fecha_fin' =>
                $fechaFin !== '' ? $fechaFin : null,

            'estado' => $estadoInicial,

            'creado_por' => usuarioId(),
            'monitor_id' => $monitorId > 0 ? $monitorId : null,
            'encargado_principal_id' => $encargadoId > 0 ? $encargadoId : null

        ]);

        $id = (int)$pdo->lastInsertId();

        guardarResponsablesCicloDiscipulado(
            $pdo,
            $id,
            $responsables
        );

        crearClasesInicialesDelCiclo($pdo, $id);

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {

            $pdo->rollBack();

        }

        throw $e;

    }

    return $id;

}


/* ==========================================================
   EDITAR CICLO DE DISCIPULADO

   No modifica el estado (eso lo hace
   cambiarEstadoCicloDiscipulado, con sus propias reglas).
========================================================== */

function editarCicloDiscipulado(
    PDO $pdo,
    array $datos
): void {

    $id = (int)($datos['id'] ?? 0);

    $nombre = trim((string)($datos['nombre'] ?? ''));

    $descripcion = trim((string)($datos['descripcion'] ?? ''));

    $fechaInicio = trim((string)($datos['fecha_inicio'] ?? ''));

    $fechaFin = trim((string)($datos['fecha_fin'] ?? ''));

    $responsables = $datos['responsables'] ?? [];

    if (!is_array($responsables)) {

        $responsables = [];

    }

    if ($id <= 0) {

        throw new Exception(
            'Ciclo inválido.'
        );

    }

    $cicloActual = obtenerCicloDiscipuladoPorId(
        $pdo,
        $id
    );

    if (!$cicloActual) {

        throw new Exception(
            'El ciclo no existe.'
        );

    }

    validarDatosCicloDiscipulado(
        $nombre,
        $fechaInicio,
        $fechaFin
    );

    if (
        existeCicloDiscipuladoConNombre(
            $pdo,
            $nombre,
            $id
        )
    ) {

        throw new Exception(
            'Ya existe un ciclo de discipulado con ese nombre.'
        );

    }

    $pdo->beginTransaction();

    try {

        $stmt = $pdo->prepare("

            UPDATE ciclos_discipulado

            SET
                nombre = :nombre,
                descripcion = :descripcion,
                fecha_inicio = :fecha_inicio,
                fecha_fin = :fecha_fin

            WHERE id = :id

        ");

        $stmt->execute([

            'nombre' => $nombre,

            'descripcion' =>
                $descripcion !== '' ? $descripcion : null,

            'fecha_inicio' => $fechaInicio,

            'fecha_fin' =>
                $fechaFin !== '' ? $fechaFin : null,

            'id' => $id

        ]);

        guardarResponsablesCicloDiscipulado(
            $pdo,
            $id,
            $responsables
        );

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {

            $pdo->rollBack();

        }

        throw $e;

    }

}


/* ==========================================================
   CAMBIAR ESTADO DE CICLO (ACTIVAR / FINALIZAR / CANCELAR)

   Reglas:

   - Al pasar a FINALIZADO o CANCELADO, si no tiene
     fecha_fin, se completa automáticamente con la fecha
     actual.

   - Al reactivar (volver a ACTIVO), se limpia fecha_fin.
========================================================== */

function cambiarEstadoCicloDiscipulado(
    PDO $pdo,
    int $id,
    string $estado
): void {

    $estado = strtoupper(trim($estado));

    validarEstadoCicloDiscipulado($estado);

    $ciclo = obtenerCicloDiscipuladoPorId(
        $pdo,
        $id
    );

    if (!$ciclo) {

        throw new Exception(
            'El ciclo no existe.'
        );

    }

    $fechaFin = $ciclo['fecha_fin'];

    if (
        in_array(
            $estado,
            [DISCIPULADO_FINALIZADO, DISCIPULADO_CANCELADO],
            true
        )
        &&
        empty($fechaFin)
    ) {

        $fechaFin = date('Y-m-d');

    }

    if (
        in_array(
            $estado,
            [DISCIPULADO_ACTIVO],
            true
        )
    ) {

        $fechaFin = null;

    }

    $stmt = $pdo->prepare("

        UPDATE ciclos_discipulado

        SET
            estado = :estado,
            fecha_fin = :fecha_fin

        WHERE id = :id

    ");

    $stmt->execute([

        'estado' => $estado,

        'fecha_fin' => $fechaFin,

        'id' => $id

    ]);

}


/* ==========================================================
   PROCESAR DISCIPULADO

   Punto de enganche invocado por
   asistenciaService.php::procesarRegistro() en CADA registro
   de asistencia de CADA joven activo, para CUALQUIER tipo de
   reunión — asistio 0 o 1, joven inscrito o no.

   IMPLEMENTADO EN LA FASE 7. Reglas (ver Fase 7 completa):

   1. Solo actúa si tipoReunion === DISCIPULADO. Cualquier
      otro tipo se ignora de inmediato (sección 16).
   2. Solo actúa si asistio === 1. Una inasistencia NUNCA
      completa una clase (sección 10).
   3. Solo actúa si la reunión tiene un vínculo explícito a
      ciclo + clase (discipulado_reuniones). Una reunión de
      discipulado SIN esa asociación no toca el progreso
      (sección 4) — evita que una reunión administrativa o
      informativa altere el checklist.
   4. Solo actúa si el joven tiene una inscripción ACTIVA en
      ESE ciclo. Si no está inscrito, o su inscripción está
      CANCELADA/FINALIZADA, no se crea progreso — pero la
      asistencia general YA quedó guardada por
      guardarRegistroAsistencia() en el paso anterior de
      procesarRegistro(), así que nada se rompe (secciones
      17 y 18).
   5. Si la clase ya tiene progreso registrado para esa
      inscripción (por cualquier vía — manual o de otra
      reunión), NO se toca. Nunca se sobrescribe un progreso
      existente desde este flujo automático (sección 8), y
      esto protege una recuperación previa de ser borrada por
      la corrección de una asistencia distinta (secciones 14
      y 15: esta función NUNCA elimina ni revierte progreso —
      solo puede crearlo cuando no existía).

   IMPORTANTE — BLINDAJE DE LA TRANSACCIÓN:

   asistenciaService.php::ejecutarRegistroAsistencia() envuelve
   TODOS los jóvenes de la reunión en una única transacción
   (ver iniciarTransaccion/confirmarTransaccion). Si esta
   función lanzara una excepción para un joven no inscrito (un
   caso ESPERADO, no un error), se revertiría el guardado de
   asistencia de TODOS los jóvenes de la reunión. Por eso
   ninguna de las condiciones "no aplica" de arriba lanza
   excepción — simplemente retornan. El try/catch de abajo es
   una protección adicional para cualquier fallo verdaderamente
   inesperado: se registra en el log del servidor pero jamás
   interrumpe el guardado general de asistencia (sección 17:
   "no debe romper el registro general de asistencia").
========================================================== */

function procesarDiscipulado(
    PDO $pdo,
    string $tipoReunion,
    array $registro
): void {

    if (strtoupper($tipoReunion) !== 'DISCIPULADO') {

        return;

    }

    try {

        $vinculo = obtenerVinculoReunionDiscipulado(
            $pdo,
            (int)$registro['reunion_id']
        );

        if (!$vinculo) {

            /* Reunión de discipulado sin ciclo/clase asociado:
               no afecta el progreso (sección 4). */

            return;

        }

        $cicloId = (int)$vinculo['ciclo_id'];

        $claseId = (int)$vinculo['clase_id'];

        $jovenId = (int)$registro['joven_id'];

        $inscripcion = obtenerInscripcionActivaEnCicloDeJoven(
            $pdo,
            $jovenId,
            $cicloId
        );

        if (!$inscripcion) {

            /* No inscrito, o inscripción CANCELADA/FINALIZADA
               en este ciclo: no se crea ni revierte progreso
               (secciones 17 y 18). La asistencia general ya
               se guardó. */

            return;

        }

        $inscripcionId = (int)$inscripcion['id'];

        /* ----------------------------------------------------
           CASO 1: ASISTIÓ → completar la clase (comportamiento
           existente, sin cambios).
        ---------------------------------------------------- */

        if ((int)($registro['asistio'] ?? 0) === 1) {

            if (
                obtenerProgresoClaseInscripcion(
                    $pdo,
                    $inscripcionId,
                    $claseId
                )
            ) {

                /* Ya existe progreso para esta clase (manual o
                   de otra reunión): no duplicar, no sobrescribir
                   (sección 8). */

                return;

            }

            $reunion = obtenerReunionPorId(
                $pdo,
                (int)$registro['reunion_id']
            );

            completarClaseProgresoDiscipulado(

                $pdo,

                $cicloId,

                $inscripcionId,

                $claseId,

                [

                    'modalidad' => $vinculo['modalidad'],

                    'fecha' =>
                        $reunion['fecha']
                        ?? date('Y-m-d'),

                    'es_recuperacion' =>
                        (bool)$vinculo['es_recuperacion'],

                    'reunion_id' =>
                        (int)$registro['reunion_id']

                ]

            );

            return;

        }

        /* ----------------------------------------------------
           CASO 2: NO ASISTIÓ → revertir ÚNICAMENTE el progreso
           automático que había sido generado por ESTA MISMA
           reunión (sección 10).

           No se toca:

           - progreso manual (reunion_id NULL);
           - progreso/recuperación registrado desde otra
             reunión;

           porque revertirProgresoAutomaticoReunionDiscipulado()
           exige una coincidencia exacta de inscripcion_id +
           clase_id + reunion_id antes de borrar cualquier fila.
        ---------------------------------------------------- */

        revertirProgresoAutomaticoReunionDiscipulado(

            $pdo,

            $inscripcionId,

            $claseId,

            (int)$registro['reunion_id']

        );

    } catch (Throwable $e) {

        /* Nunca dejar que un problema en la integración de
           discipulado tumbe el registro general de asistencia
           de toda la reunión (sección 17). */

        error_log(
            'procesarDiscipulado: ' . $e->getMessage()
        );

    }

}


/* ==========================================================
   REVERTIR PROGRESO AUTOMÁTICO DE UNA REUNIÓN ESPECÍFICA
   (SECCIÓN 10)

   A diferencia de revertirProgresoClaseDiscipulado() (acción
   manual desde el checklist/progreso, que borra sin importar
   el origen), esta función SOLO borra la fila de
   discipulado_progreso cuando coincide exactamente con:

   - la inscripción
   - la clase
   - el reunion_id que la generó

   Así se garantiza que:

   - un progreso manual (reunion_id NULL) nunca se toca aquí;
   - un progreso/recuperación generado por OTRA reunión nunca
     se toca aquí;
   - solo se revierte el progreso que había sido creado
     automáticamente por la asistencia que ahora se está
     corrigiendo a "no asistió".
========================================================== */

function revertirProgresoAutomaticoReunionDiscipulado(
    PDO $pdo,
    int $inscripcionId,
    int $claseId,
    int $reunionId
): void {

    if ($reunionId <= 0) {

        return;

    }

    $stmt = $pdo->prepare("

        DELETE FROM discipulado_progreso

        WHERE inscripcion_id = :inscripcion_id

        AND clase_id = :clase_id

        AND reunion_id = :reunion_id

    ");

    $stmt->execute([
        'inscripcion_id' => $inscripcionId,
        'clase_id' => $claseId,
        'reunion_id' => $reunionId
    ]);

}


/* ==========================================================
   ==========================================================
   FASE 4 — CLASES CONFIGURABLES POR CICLO
   ==========================================================
   ----------------------------------------------------------
   Trabaja sobre `clases_discipulado`, ya creada en la
   migración de la Fase 2. La cantidad de clases NO está
   hardcodeada: cada ciclo define las suyas.

   Relación obligatoria: ciclo → clases. Toda clase debe
   pertenecer a un ciclo existente, y las operaciones de
   edición/cambio de estado/eliminación deben confirmar que
   la clase realmente pertenece al ciclo indicado en la ruta.
========================================================== */


/* ==========================================================
   CONSTANTES DE CLASE
========================================================== */

const CLASE_PROGRAMADA = 'PROGRAMADA';

const CLASE_REALIZADA = 'REALIZADA';

const CLASE_CANCELADA = 'CANCELADA';

const ESTADOS_CLASE_DISCIPULADO = [

    CLASE_PROGRAMADA,

    CLASE_REALIZADA,

    CLASE_CANCELADA

];

const MODALIDADES_DISCIPULADO = [

    'PRESENCIAL',

    'VIRTUAL'

];

/* ==========================================================
   CATÁLOGO DE CLASES BASE

   La definición oficial vive una sola vez en
   clases_base_discipulado. Las filas de clases_discipulado
   son las asignaciones de esa definición a cada ciclo y
   conservan únicamente los datos operativos del ciclo.
========================================================== */

function obtenerClasesBaseDiscipulado(PDO $pdo, bool $soloActivas = true): array {
    $sql = 'SELECT * FROM clases_base_discipulado';
    if ($soloActivas) {
        $sql .= ' WHERE activo = 1';
    }
    $sql .= ' ORDER BY numero_orden ASC, id ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function existeClaseBaseConOrden(PDO $pdo, int $numeroOrden): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM clases_base_discipulado WHERE numero_orden = :numero_orden');
    $stmt->execute(['numero_orden' => $numeroOrden]);
    return (int)$stmt->fetchColumn() > 0;
}

function crearClaseBaseDiscipulado(PDO $pdo, array $datos): int {
    $nombre = trim((string)($datos['nombre'] ?? ''));
    $numeroOrden = (int)($datos['numero_orden'] ?? 0);
    $descripcion = trim((string)($datos['descripcion'] ?? ''));
    $modalidad = trim((string)($datos['modalidad_programada'] ?? ''));

    validarDatosClaseDiscipulado($nombre, $numeroOrden, '', $modalidad);
    if (existeClaseBaseConOrden($pdo, $numeroOrden)) {
        throw new Exception('Ya existe una clase base con ese número/orden.');
    }

    $stmt = $pdo->prepare('INSERT INTO clases_base_discipulado
        (numero_orden, nombre, descripcion, modalidad_programada, repasos_requeridos, activo)
        VALUES (:numero_orden, :nombre, :descripcion, :modalidad_programada, 2, 1)');
    $stmt->execute([
        'numero_orden' => $numeroOrden,
        'nombre' => $nombre,
        'descripcion' => $descripcion !== '' ? $descripcion : null,
        'modalidad_programada' => $modalidad !== '' ? strtoupper($modalidad) : null,
    ]);
    return (int)$pdo->lastInsertId();
}

/* Asigna las clases oficiales vigentes sin crear duplicados.
   Debe invocarse dentro de la transacción de creación del ciclo. */
function crearClasesInicialesDelCiclo(PDO $pdo, int $cicloId): void {
    if ($cicloId <= 0 || !obtenerCicloDiscipuladoPorId($pdo, $cicloId)) {
        throw new Exception('El ciclo no existe para asignar sus clases base.');
    }

    $clasesBase = obtenerClasesBaseDiscipulado($pdo);
    if ($clasesBase === []) {
        throw new Exception('No hay clases base activas en el catálogo.');
    }

    $existen = $pdo->prepare('SELECT COUNT(*) FROM clases_discipulado WHERE ciclo_id = :ciclo_id');
    $existen->execute(['ciclo_id' => $cicloId]);
    if ((int)$existen->fetchColumn() > 0) {
        return;
    }

    $insertar = $pdo->prepare('INSERT INTO clases_discipulado
        (ciclo_id, clase_base_id, numero_orden, nombre, descripcion, modalidad_programada, repasos_requeridos, estado)
        VALUES (:ciclo_id, :clase_base_id, :numero_orden, :nombre, :descripcion, :modalidad_programada, :repasos_requeridos, :estado)');

    foreach ($clasesBase as $claseBase) {
        $insertar->execute([
            'ciclo_id' => $cicloId,
            'clase_base_id' => (int)$claseBase['id'],
            'numero_orden' => (int)$claseBase['numero_orden'],
            'nombre' => $claseBase['nombre'],
            'descripcion' => $claseBase['descripcion'],
            'modalidad_programada' => $claseBase['modalidad_programada'],
            'repasos_requeridos' => (int)$claseBase['repasos_requeridos'],
            'estado' => CLASE_PROGRAMADA,
        ]);
    }
}

function asignarProfesorClaseDiscipulado(PDO $pdo, int $cicloId, int $claseId, ?int $profesorId): void {
    if (!obtenerClaseDiscipuladoDeCiclo($pdo, $cicloId, $claseId)) {
        throw new Exception('La clase no existe o no pertenece a este ciclo.');
    }
    if ($profesorId !== null && $profesorId > 0 && !existeUsuario($pdo, $profesorId)) {
        throw new Exception('El profesor seleccionado no existe.');
    }
    $stmt = $pdo->prepare('UPDATE clases_discipulado SET profesor_id = :profesor_id WHERE id = :id AND ciclo_id = :ciclo_id');
    $stmt->execute(['profesor_id' => $profesorId && $profesorId > 0 ? $profesorId : null, 'id' => $claseId, 'ciclo_id' => $cicloId]);
}


/* ==========================================================
   VALIDAR ESTADO DE CLASE
========================================================== */

function validarEstadoClaseDiscipulado(
    string $estado
): void {

    if (
        !in_array(
            strtoupper($estado),
            ESTADOS_CLASE_DISCIPULADO,
            true
        )
    ) {

        throw new Exception(
            'Estado de clase inválido.'
        );

    }

}


/* ==========================================================
   VALIDAR MODALIDAD

   La modalidad de una clase es opcional (puede no estar
   programada todavía), por eso se acepta null/cadena vacía.
========================================================== */

function validarModalidadDiscipulado(
    ?string $modalidad
): void {

    if (
        $modalidad === null
        ||
        trim($modalidad) === ''
    ) {

        return;

    }

    if (
        !in_array(
            strtoupper($modalidad),
            MODALIDADES_DISCIPULADO,
            true
        )
    ) {

        throw new Exception(
            'Modalidad inválida.'
        );

    }

}


/* ==========================================================
   OBTENER CLASES DE UN CICLO

   Ordenadas por numero_orden, tal como pide la relación
   ciclo → clase 1 → clase 2 → ... → clase N.
========================================================== */

function obtenerClasesDiscipulado(
    PDO $pdo,
    int $cicloId
): array {

    $stmt = $pdo->prepare("

        SELECT cd.*, u.nombre AS profesor_nombre,
            (
                SELECT MIN(m2.id)
                FROM materiales_discipulado m2
                WHERE m2.clase_base_id = cd.clase_base_id
            ) AS material_id

        FROM clases_discipulado cd
        LEFT JOIN usuarios u ON u.id = cd.profesor_id

        WHERE cd.ciclo_id = :ciclo_id

        ORDER BY cd.numero_orden ASC

    ");

    $stmt->execute([
        'ciclo_id' => $cicloId
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);

}


/* ==========================================================
   OBTENER CLASE POR ID
========================================================== */

function obtenerClaseDiscipuladoPorId(
    PDO $pdo,
    int $claseId
): array|false {

    $stmt = $pdo->prepare("

        SELECT *

        FROM clases_discipulado

        WHERE id = :id

        LIMIT 1

    ");

    $stmt->execute([
        'id' => $claseId
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);

}


/* ==========================================================
   OBTENER CLASE DE UN CICLO ESPECÍFICO

   Comprobación de integridad exigida por la Fase 4: cuando
   la ruta trae ciclo_id + id, hay que confirmar que la clase
   realmente pertenece a ese ciclo (y no a otro).
========================================================== */

function obtenerClaseDiscipuladoDeCiclo(
    PDO $pdo,
    int $cicloId,
    int $claseId
): array|false {

    $stmt = $pdo->prepare("

        SELECT *

        FROM clases_discipulado

        WHERE id = :id

        AND ciclo_id = :ciclo_id

        LIMIT 1

    ");

    $stmt->execute([
        'id' => $claseId,
        'ciclo_id' => $cicloId
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);

}


/* ==========================================================
   EXISTE NÚMERO DE ORDEN EN EL CICLO

   Replica a nivel de aplicación la restricción
   UNIQUE(ciclo_id, numero_orden) de la migración, para poder
   mostrar un mensaje claro antes de golpear la base de
   datos.
========================================================== */

function existeNumeroOrdenClaseDiscipulado(
    PDO $pdo,
    int $cicloId,
    int $numeroOrden,
    ?int $excluirId = null
): bool {

    $sql = "
        SELECT COUNT(*)
        FROM clases_discipulado
        WHERE ciclo_id = :ciclo_id
        AND numero_orden = :numero_orden
    ";

    $parametros = [
        'ciclo_id' => $cicloId,
        'numero_orden' => $numeroOrden
    ];

    if ($excluirId !== null) {

        $sql .= ' AND id <> :excluir_id';

        $parametros['excluir_id'] = $excluirId;

    }

    $stmt = $pdo->prepare($sql);

    $stmt->execute($parametros);

    return (int)$stmt->fetchColumn() > 0;

}


/* ==========================================================
   SIGUIENTE NÚMERO DE ORDEN DISPONIBLE

   Utilidad para prellenar el formulario de creación
   (MAX(numero_orden) + 1, o 1 si el ciclo no tiene clases
   todavía). No obliga a usarlo: el usuario puede escribir
   otro número si el ciclo lo requiere (por ejemplo, para
   intercalar un repaso).
========================================================== */

function siguienteNumeroOrdenClaseDiscipulado(
    PDO $pdo,
    int $cicloId
): int {

    $stmt = $pdo->prepare("

        SELECT COALESCE(MAX(numero_orden), 0) + 1

        FROM clases_discipulado

        WHERE ciclo_id = :ciclo_id

    ");

    $stmt->execute([
        'ciclo_id' => $cicloId
    ]);

    return (int)$stmt->fetchColumn();

}


/* ==========================================================
   VALIDAR DATOS BÁSICOS DE CLASE

   Compartido por crear y editar.
========================================================== */

function validarDatosClaseDiscipulado(
    string $nombre,
    int $numeroOrden,
    string $fechaProgramada,
    ?string $modalidadProgramada
): void {

    if ($nombre === '') {

        throw new Exception(
            'Debe ingresar el nombre de la clase.'
        );

    }

    if ($numeroOrden < 1) {

        throw new Exception(
            'El número/orden de la clase debe ser mayor a cero.'
        );

    }

    if (
        $fechaProgramada !== ''
        &&
        !validarFecha($fechaProgramada)
    ) {

        throw new Exception(
            'La fecha programada no es válida.'
        );

    }

    validarModalidadDiscipulado(
        $modalidadProgramada
    );

}


/* ==========================================================
   CREAR CLASE DE DISCIPULADO
========================================================== */

function crearClaseDiscipulado(
    PDO $pdo,
    array $datos
): int {

    $cicloId = (int)($datos['ciclo_id'] ?? 0);

    $nombre = trim((string)($datos['nombre'] ?? ''));

    $descripcion = trim((string)($datos['descripcion'] ?? ''));

    $numeroOrden = (int)($datos['numero_orden'] ?? 0);

    $fechaProgramada = trim((string)($datos['fecha_programada'] ?? ''));

    $modalidadProgramada = trim((string)($datos['modalidad_programada'] ?? ''));

    $observaciones = trim((string)($datos['observaciones'] ?? ''));

    if ($cicloId <= 0) {

        throw new Exception(
            'Ciclo inválido.'
        );

    }

    /* --------------------------------------------------
       LA CLASE DEBE PERTENECER A UN CICLO EXISTENTE
    -------------------------------------------------- */

    if (
        !obtenerCicloDiscipuladoPorId($pdo, $cicloId)
    ) {

        throw new Exception(
            'El ciclo indicado no existe.'
        );

    }

    validarDatosClaseDiscipulado(
        $nombre,
        $numeroOrden,
        $fechaProgramada,
        $modalidadProgramada
    );

    if (
        existeNumeroOrdenClaseDiscipulado(
            $pdo,
            $cicloId,
            $numeroOrden
        )
    ) {

        throw new Exception(
            'Ya existe una clase con ese número/orden en este ciclo.'
        );

    }

    $pdo->beginTransaction();

    try {

        $claseBaseId = crearClaseBaseDiscipulado($pdo, [
            'numero_orden' => $numeroOrden,
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'modalidad_programada' => $modalidadProgramada,
        ]);

        $stmt = $pdo->prepare("

        INSERT INTO clases_discipulado
        (
            ciclo_id,
            clase_base_id,
            numero_orden,
            nombre,
            descripcion,
            fecha_programada,
            modalidad_programada,
            repasos_requeridos,
            estado,
            observaciones
        )
        VALUES
        (
            :ciclo_id,
            :clase_base_id,
            :numero_orden,
            :nombre,
            :descripcion,
            :fecha_programada,
            :modalidad_programada,
            :repasos_requeridos,
            :estado,
            :observaciones
        )

    ");

    $stmt->execute([

        'ciclo_id' => $cicloId,

        'clase_base_id' => $claseBaseId,

        'numero_orden' => $numeroOrden,

        'nombre' => $nombre,

        'descripcion' =>
            $descripcion !== '' ? $descripcion : null,

        'fecha_programada' =>
            $fechaProgramada !== '' ? $fechaProgramada : null,

        'modalidad_programada' =>
            $modalidadProgramada !== ''
                ? strtoupper($modalidadProgramada)
                : null,

        'repasos_requeridos' => 2,

        'estado' => CLASE_PROGRAMADA,

        'observaciones' =>
            $observaciones !== '' ? $observaciones : null

    ]);

        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

}


/* ==========================================================
   EDITAR CLASE DE DISCIPULADO

   No modifica el estado (ver cambiarEstadoClaseDiscipulado).
========================================================== */

function editarClaseDiscipulado(
    PDO $pdo,
    array $datos
): void {

    $id = (int)($datos['id'] ?? 0);

    $cicloId = (int)($datos['ciclo_id'] ?? 0);

    $nombre = trim((string)($datos['nombre'] ?? ''));

    $descripcion = trim((string)($datos['descripcion'] ?? ''));

    $numeroOrden = (int)($datos['numero_orden'] ?? 0);

    $fechaProgramada = trim((string)($datos['fecha_programada'] ?? ''));

    $modalidadProgramada = trim((string)($datos['modalidad_programada'] ?? ''));

    $observaciones = trim((string)($datos['observaciones'] ?? ''));

    if ($id <= 0 || $cicloId <= 0) {

        throw new Exception(
            'Clase inválida.'
        );

    }

    $claseActual = obtenerClaseDiscipuladoDeCiclo(
        $pdo,
        $cicloId,
        $id
    );

    if (!$claseActual) {

        throw new Exception(
            'La clase no existe o no pertenece a este ciclo.'
        );

    }

    validarDatosClaseDiscipulado(
        $nombre,
        $numeroOrden,
        $fechaProgramada,
        $modalidadProgramada
    );

    if (
        existeNumeroOrdenClaseDiscipulado(
            $pdo,
            $cicloId,
            $numeroOrden,
            $id
        )
    ) {

        throw new Exception(
            'Ya existe una clase con ese número/orden en este ciclo.'
        );

    }

    $stmt = $pdo->prepare("

        UPDATE clases_discipulado

        SET
            numero_orden = :numero_orden,
            nombre = :nombre,
            descripcion = :descripcion,
            fecha_programada = :fecha_programada,
            modalidad_programada = :modalidad_programada,
            observaciones = :observaciones

        WHERE id = :id

        AND ciclo_id = :ciclo_id

    ");

    $stmt->execute([

        'numero_orden' => $numeroOrden,

        'nombre' => $nombre,

        'descripcion' =>
            $descripcion !== '' ? $descripcion : null,

        'fecha_programada' =>
            $fechaProgramada !== '' ? $fechaProgramada : null,

        'modalidad_programada' =>
            $modalidadProgramada !== ''
                ? strtoupper($modalidadProgramada)
                : null,

        'observaciones' =>
            $observaciones !== '' ? $observaciones : null,

        'id' => $id,

        'ciclo_id' => $cicloId

    ]);

}


/* ==========================================================
   CAMBIAR ESTADO DE CLASE
   (PROGRAMADA / REALIZADA / CANCELADA)
========================================================== */

function cambiarEstadoClaseDiscipulado(
    PDO $pdo,
    int $cicloId,
    int $claseId,
    string $estado
): void {

    $estado = strtoupper(trim($estado));

    validarEstadoClaseDiscipulado($estado);

    $clase = obtenerClaseDiscipuladoDeCiclo(
        $pdo,
        $cicloId,
        $claseId
    );

    if (!$clase) {

        throw new Exception(
            'La clase no existe o no pertenece a este ciclo.'
        );

    }

    $stmt = $pdo->prepare("

        UPDATE clases_discipulado

        SET estado = :estado

        WHERE id = :id

        AND ciclo_id = :ciclo_id

    ");

    $stmt->execute([

        'estado' => $estado,

        'id' => $claseId,

        'ciclo_id' => $cicloId

    ]);

}


/* ==========================================================
   CLASE TIENE REFERENCIAS (PROGRESO / REUNIONES)

   Aunque las Fases 6 y 7 todavía no llenan estas tablas,
   la comprobación ya se deja lista: si en el futuro una
   clase tiene progreso registrado o una reunión asociada,
   eliminarla destruiría trazabilidad real, así que debe
   bloquearse.
========================================================== */

function claseDiscipuladoTieneReferencias(
    PDO $pdo,
    int $claseId
): bool {

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM discipulado_progreso
        WHERE clase_id = :clase_id
    ");

    $stmt->execute([
        'clase_id' => $claseId
    ]);

    if ((int)$stmt->fetchColumn() > 0) {

        return true;

    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM discipulado_reuniones
        WHERE clase_id = :clase_id
    ");

    $stmt->execute([
        'clase_id' => $claseId
    ]);

    return (int)$stmt->fetchColumn() > 0;

}


/* ==========================================================
   ELIMINAR CLASE DE DISCIPULADO

   Solo se permite si:

   1. La clase pertenece al ciclo indicado.
   2. No tiene progreso ni reuniones asociadas (ver
      claseDiscipuladoTieneReferencias).

   En cualquier otro caso, se rechaza en vez de eliminar
   destructivamente.
========================================================== */

function eliminarClaseDiscipulado(
    PDO $pdo,
    int $cicloId,
    int $claseId
): void {

    $clase = obtenerClaseDiscipuladoDeCiclo(
        $pdo,
        $cicloId,
        $claseId
    );

    if (!$clase) {

        throw new Exception(
            'La clase no existe o no pertenece a este ciclo.'
        );

    }

    if (
        claseDiscipuladoTieneReferencias(
            $pdo,
            $claseId
        )
    ) {

        throw new Exception(
            'No es posible eliminar esta clase: ya tiene progreso o reuniones asociadas. Puedes cancelarla en su lugar.'
        );

    }

    $stmt = $pdo->prepare("

        DELETE FROM clases_discipulado

        WHERE id = :id

        AND ciclo_id = :ciclo_id

    ");

    $stmt->execute([
        'id' => $claseId,
        'ciclo_id' => $cicloId
    ]);

}


/* ==========================================================
   ==========================================================
   FASE 5 — INSCRIPCIÓN DE JÓVENES AL CICLO
   ==========================================================
   ----------------------------------------------------------
   Trabaja sobre `discipulado_inscripciones` y
   `discipulado_observaciones`, ambas ya creadas en la
   migración de la Fase 2 — no fue necesaria ninguna tabla ni
   migración nueva para esta fase.

   Relación: JOVEN (existente en `jovenes`, sin duplicarlo) →
   INSCRIPCIÓN → ciclo. El progreso por clase (Fase 6) es un
   concepto aparte: aquí NO se toca `discipulado_progreso`.

   Reglas de unicidad reforzadas a nivel de aplicación (y ya
   garantizadas también por la base de datos, ver migración):

   - UNIQUE(joven_id, ciclo_id): un joven no puede tener dos
     inscripciones en el mismo ciclo (columna generada
     `joven_activo_unico` + UNIQUE(joven_id, ciclo_id)).
   - Solo puede existir UNA inscripción con estado ACTIVO por
     joven, sin importar el ciclo (columna generada
     `joven_activo_unico`, mismo criterio que tenía
     discipuladoService V2::existeDiscipuladoActivo()).
========================================================== */


/* ==========================================================
   VALIDAR MODALIDAD PRINCIPAL (OBLIGATORIA)

   A diferencia de la modalidad de una clase (opcional,
   puede recuperarse en otra modalidad), la modalidad
   principal de la inscripción es obligatoria.
========================================================== */

function validarModalidadPrincipalDiscipulado(
    string $modalidad
): void {

    if (trim($modalidad) === '') {

        throw new Exception(
            'Debe seleccionar la modalidad principal del joven.'
        );

    }

    validarModalidadDiscipulado($modalidad);

}


/* ==========================================================
   OBTENER INSCRIPCIONES DE UN CICLO

   Incluye datos del joven, del responsable, y contadores de
   progreso calculados en vivo desde `discipulado_progreso`
   (todavía vacía hasta la Fase 6: por eso hoy siempre
   mostrará 0 completadas — es el valor real, no un
   placeholder inventado).
========================================================== */

function obtenerInscripcionesDiscipulado(
    PDO $pdo,
    int $cicloId
): array {

    $stmt = $pdo->prepare("

        SELECT

            di.id,

            di.joven_id,

            di.ciclo_id,

            di.modalidad_principal,

            di.repaso_1,

            di.repaso_2,

            di.responsable_id,

            di.estado,

            di.fecha_inscripcion,

            di.fecha_finalizacion,

            di.listo_para_finalizar,

            di.motivo_cancelacion,

            j.nombre_completo,

            j.telefono,

            u.nombre AS responsable_nombre,

            (
                SELECT COUNT(*)
                FROM clases_discipulado cd
                WHERE cd.ciclo_id = di.ciclo_id
            ) AS total_clases,

            (
                SELECT COUNT(*)
                FROM discipulado_progreso dp
                WHERE dp.inscripcion_id = di.id
                AND dp.completada = 1
            ) AS clases_completadas

        FROM discipulado_inscripciones di

        INNER JOIN jovenes j
            ON j.id = di.joven_id

        LEFT JOIN usuarios u
            ON u.id = di.responsable_id

        WHERE di.ciclo_id = :ciclo_id

        ORDER BY j.nombre_completo ASC

    ");

    $stmt->execute([
        'ciclo_id' => $cicloId
    ]);

    $inscripciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($inscripciones as &$inscripcion) {

        $total = (int)$inscripcion['total_clases'];

        $completadas = (int)$inscripcion['clases_completadas'];

        $inscripcion['clases_pendientes'] =
            max(0, $total - $completadas);

        $inscripcion['progreso_porcentaje'] =
            $total > 0
                ? round(($completadas / $total) * 100, 1)
                : 0.0;

    }

    unset($inscripcion);

    return $inscripciones;

}


/* ==========================================================
   OBTENER INSCRIPCIÓN POR ID
========================================================== */

function obtenerInscripcionDiscipuladoPorId(
    PDO $pdo,
    int $id
): array|false {

    $stmt = $pdo->prepare("

        SELECT *

        FROM discipulado_inscripciones

        WHERE id = :id

        LIMIT 1

    ");

    $stmt->execute([
        'id' => $id
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);

}


/* ==========================================================
   OBTENER INSCRIPCIÓN DE UN CICLO ESPECÍFICO

   Comprobación de integridad exigida por la Fase 5: evita
   manipular ciclo_id/id por URL para operar sobre una
   inscripción de otro ciclo.
========================================================== */

function obtenerInscripcionDiscipuladoDeCiclo(
    PDO $pdo,
    int $cicloId,
    int $inscripcionId
): array|false {

    $stmt = $pdo->prepare("

        SELECT *

        FROM discipulado_inscripciones

        WHERE id = :id

        AND ciclo_id = :ciclo_id

        LIMIT 1

    ");

    $stmt->execute([
        'id' => $inscripcionId,
        'ciclo_id' => $cicloId
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);

}


/* ==========================================================
   OBTENER INSCRIPCIÓN ACTIVA DE UN JOVEN

   Un joven solo puede tener una inscripción ACTIVA a la vez,
   sin importar el ciclo (misma regla que existeDiscipuladoActivo
   en la versión anterior del Service).
========================================================== */

function obtenerInscripcionActivaDeJoven(
    PDO $pdo,
    int $jovenId
): array|false {

    $stmt = $pdo->prepare("

        SELECT

            di.*,

            c.nombre AS ciclo_nombre

        FROM discipulado_inscripciones di

        INNER JOIN ciclos_discipulado c
            ON c.id = di.ciclo_id

        WHERE di.joven_id = :joven_id

        AND di.estado = 'ACTIVO'

        LIMIT 1

    ");

    $stmt->execute([
        'joven_id' => $jovenId
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);

}


/* ==========================================================
   EXISTE INSCRIPCIÓN DEL JOVEN EN ESE CICLO

   Replica a nivel de aplicación la restricción
   UNIQUE(joven_id, ciclo_id) de la migración (sin importar
   el estado: si ya existe una fila cancelada, tampoco se
   puede volver a inscribir en el mismo ciclo con una fila
   nueva).
========================================================== */

function existeInscripcionDiscipuladoEnCiclo(
    PDO $pdo,
    int $jovenId,
    int $cicloId
): bool {

    $stmt = $pdo->prepare("

        SELECT COUNT(*)
        FROM discipulado_inscripciones
        WHERE joven_id = :joven_id
        AND ciclo_id = :ciclo_id

    ");

    $stmt->execute([
        'joven_id' => $jovenId,
        'ciclo_id' => $cicloId
    ]);

    return (int)$stmt->fetchColumn() > 0;

}


/* ==========================================================
   OBTENER JÓVENES DISPONIBLES PARA INSCRIBIR EN UN CICLO

   Reutiliza obtenerJovenesActivos() de jovenService.php (no
   duplica esa consulta) y descarta:

   - jóvenes que ya tienen una fila de inscripción en ESTE
     ciclo (cualquier estado);
   - jóvenes con una inscripción ACTIVA en OTRO ciclo (un
     joven no puede estar en dos procesos de discipulado
     activos a la vez).
========================================================== */

function obtenerJovenesDisponiblesParaInscripcionDiscipulado(
    PDO $pdo,
    int $cicloId
): array {

    $jovenes = obtenerJovenesActivos($pdo);

    $stmt = $pdo->prepare("

        SELECT joven_id

        FROM discipulado_inscripciones

        WHERE ciclo_id = :ciclo_id

    ");

    $stmt->execute([
        'ciclo_id' => $cicloId
    ]);

    $yaEnEsteCiclo = array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );

    $stmt = $pdo->query("

        SELECT joven_id
        FROM discipulado_inscripciones
        WHERE estado = 'ACTIVO'

    ");

    $conInscripcionActiva = array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );

    $excluidos = array_flip(
        array_merge(
            $yaEnEsteCiclo,
            $conInscripcionActiva
        )
    );

    return array_values(

        array_filter(

            $jovenes,

            fn (array $joven) =>
                !isset($excluidos[(int)$joven['id']])

        )

    );

}


/* ==========================================================
   INSCRIBIR JOVEN EN CICLO DE DISCIPULADO
========================================================== */

function inscribirJovenDiscipulado(
    PDO $pdo,
    array $datos
): int {

    $jovenId = (int)($datos['joven_id'] ?? 0);

    $cicloId = (int)($datos['ciclo_id'] ?? 0);

    $modalidadPrincipal = trim((string)($datos['modalidad_principal'] ?? ''));

    $responsableId = (int)($datos['responsable_id'] ?? 0);

    $fechaInscripcion = trim((string)($datos['fecha_inscripcion'] ?? ''));

    $observacion = trim((string)($datos['observacion'] ?? ''));

    if ($jovenId <= 0) {

        throw new Exception(
            'Debe seleccionar un joven.'
        );

    }

    if ($cicloId <= 0) {

        throw new Exception(
            'Ciclo inválido.'
        );

    }

    /* --------------------------------------------------
       EL JOVEN DEBE EXISTIR (SIN CREAR NI DUPLICAR)
    -------------------------------------------------- */

    if (!existeJoven($pdo, $jovenId)) {

        throw new Exception(
            'El joven seleccionado no existe.'
        );

    }

    /* --------------------------------------------------
       EL CICLO DEBE EXISTIR Y ESTAR EN UN ESTADO QUE
       ADMITA INSCRIPCIONES

       Revisión previa a la Fase 6: ahora también se
       permite inscribir en ciclos PLANIFICADO (un ciclo
       futuro que se está preparando), además de ACTIVO.
       No se permite inscribir en ciclos FINALIZADO o
       CANCELADO.
    -------------------------------------------------- */

    $ciclo = obtenerCicloDiscipuladoPorId($pdo, $cicloId);

    if (!$ciclo) {

        throw new Exception(
            'El ciclo indicado no existe.'
        );

    }

    if (
        !in_array(
            $ciclo['estado'],
            [DISCIPULADO_ACTIVO],
            true
        )
    ) {

        throw new Exception(
            'Solo se pueden inscribir jóvenes en ciclos activos.'
        );

    }

    validarModalidadPrincipalDiscipulado(
        $modalidadPrincipal
    );

    if ($fechaInscripcion === '') {

        $fechaInscripcion = date('Y-m-d');

    } elseif (!validarFecha($fechaInscripcion)) {

        throw new Exception(
            'La fecha de inscripción no es válida.'
        );

    }

    /* --------------------------------------------------
       REGLA 1: NO INSCRIBIR DOS VECES EN EL MISMO CICLO
    -------------------------------------------------- */

    if (
        existeInscripcionDiscipuladoEnCiclo(
            $pdo,
            $jovenId,
            $cicloId
        )
    ) {

        throw new Exception(
            'Este joven ya tiene una inscripción registrada en este ciclo.'
        );

    }

    /* --------------------------------------------------
       SOLO UNA INSCRIPCIÓN ACTIVA POR JOVEN A LA VEZ
    -------------------------------------------------- */

    $inscripcionActiva = obtenerInscripcionActivaDeJoven(
        $pdo,
        $jovenId
    );

    if ($inscripcionActiva) {

        throw new Exception(
            'Este joven ya tiene una inscripción activa en el ciclo "' .
            $inscripcionActiva['ciclo_nombre'] .
            '". Debe finalizarla o retirarla antes de inscribirlo en otro ciclo.'
        );

    }

    $pdo->beginTransaction();

    try {

        $stmt = $pdo->prepare("

            INSERT INTO discipulado_inscripciones
            (
                joven_id,
                ciclo_id,
                modalidad_principal,
                responsable_id,
                estado,
                fecha_inscripcion
            )
            VALUES
            (
                :joven_id,
                :ciclo_id,
                :modalidad_principal,
                :responsable_id,
                :estado,
                :fecha_inscripcion
            )

        ");

        $stmt->execute([

            'joven_id' => $jovenId,

            'ciclo_id' => $cicloId,

            'modalidad_principal' =>
                strtoupper($modalidadPrincipal),

            'responsable_id' =>
                $responsableId > 0 ? $responsableId : null,

            'estado' => DISCIPULADO_ACTIVO,

            'fecha_inscripcion' => $fechaInscripcion

        ]);

        $inscripcionId = (int)$pdo->lastInsertId();

        if ($observacion !== '') {

            agregarObservacionInscripcionDiscipulado(
                $pdo,
                $inscripcionId,
                $observacion
            );

        }

        registrarEventoHistorial(

            $pdo,

            [

                'joven_id' => $jovenId,

                'reunion_id' => null,

                'tipo_evento' => EVENTO_DISCIPULADO,

                'titulo' => 'Inicio de discipulado',

                'descripcion' =>
                    'El joven fue inscrito en el ciclo de discipulado.',

                'datos_json' => [

                    'ciclo_id' => $cicloId,

                    'inscripcion_id' => $inscripcionId,

                    'modalidad_principal' =>
                        strtoupper($modalidadPrincipal)

                ],

                'usuario_id' => usuarioId()

            ]

        );

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {

            $pdo->rollBack();

        }

        throw $e;

    }

    return $inscripcionId;

}


/* ==========================================================
   CAMBIAR MODALIDAD PRINCIPAL DE LA INSCRIPCIÓN

   Es independiente de la modalidad de cada clase (que se
   maneja en discipulado_progreso, Fase 6): esto solo cambia
   la modalidad principal con la que el joven quedó
   registrado en el ciclo.
========================================================== */

function cambiarModalidadPrincipalInscripcionDiscipulado(
    PDO $pdo,
    int $cicloId,
    int $inscripcionId,
    string $modalidad
): void {

    $modalidad = strtoupper(trim($modalidad));

    validarModalidadPrincipalDiscipulado($modalidad);

    $inscripcion = obtenerInscripcionDiscipuladoDeCiclo(
        $pdo,
        $cicloId,
        $inscripcionId
    );

    if (!$inscripcion) {

        throw new Exception(
            'La inscripción no existe o no pertenece a este ciclo.'
        );

    }

    $stmt = $pdo->prepare("

        UPDATE discipulado_inscripciones

        SET modalidad_principal = :modalidad

        WHERE id = :id

        AND ciclo_id = :ciclo_id

    ");

    $stmt->execute([

        'modalidad' => $modalidad,

        'id' => $inscripcionId,

        'ciclo_id' => $cicloId

    ]);

}


/* ==========================================================
   ACTUALIZAR REPASO (1 o 2) DE LA INSCRIPCIÓN

   Ver migración 20260902_repasos_checklist_asistencia.sql:
   son 2 casillas simples e independientes de cualquier clase
   puntual, usadas por la vista de Asistencia.
========================================================== */

function actualizarRepasoInscripcionDiscipulado(
    PDO $pdo,
    int $cicloId,
    int $inscripcionId,
    int $numeroRepaso,
    bool $valor
): void {

    if (!in_array($numeroRepaso, [1, 2], true)) {

        throw new Exception(
            'Número de repaso inválido.'
        );

    }

    $inscripcion = obtenerInscripcionDiscipuladoDeCiclo(
        $pdo,
        $cicloId,
        $inscripcionId
    );

    if (!$inscripcion) {

        throw new Exception(
            'La inscripción no existe o no pertenece a este ciclo.'
        );

    }

    $columna = 'repaso_' . $numeroRepaso;

    $stmt = $pdo->prepare("

        UPDATE discipulado_inscripciones

        SET {$columna} = :valor

        WHERE id = :id

        AND ciclo_id = :ciclo_id

    ");

    $stmt->execute([

        'valor' => $valor ? 1 : 0,

        'id' => $inscripcionId,

        'ciclo_id' => $cicloId

    ]);

}


/* ==========================================================
   CAMBIAR ESTADO DE LA INSCRIPCIÓN

   NOTA DE ALCANCE (Fase 5): esta función soporta los tres
   estados porque la columna ya los contempla, pero en esta
   fase la interfaz solo ofrece ACTIVO (reactivar) y
   CANCELADO (retirar). La transición a FINALIZADO debe
   quedar condicionada al progreso real de clases, que se
   implementa en la Fase 6 — habilitarla aquí hubiera
   significado poder "finalizar" a un joven sin haber
   completado ninguna clase.
========================================================== */

function cambiarEstadoInscripcionDiscipulado(
    PDO $pdo,
    int $cicloId,
    int $inscripcionId,
    string $estado,
    ?string $motivo = null
): void {

    $estado = strtoupper(trim($estado));

    validarEstadoCicloDiscipulado($estado);

    $inscripcion = obtenerInscripcionDiscipuladoDeCiclo(
        $pdo,
        $cicloId,
        $inscripcionId
    );

    if (!$inscripcion) {

        throw new Exception(
            'La inscripción no existe o no pertenece a este ciclo.'
        );

    }

    $fechaFinalizacion = $inscripcion['fecha_finalizacion'];

    $motivoCancelacion = $inscripcion['motivo_cancelacion'];

    if (
        in_array(
            $estado,
            [DISCIPULADO_FINALIZADO, DISCIPULADO_CANCELADO],
            true
        )
        &&
        empty($fechaFinalizacion)
    ) {

        $fechaFinalizacion = date('Y-m-d');

    }

    if ($estado === DISCIPULADO_CANCELADO) {

        $motivoCancelacion =
            $motivo !== null && trim($motivo) !== ''
                ? trim($motivo)
                : $motivoCancelacion;

    }

    if ($estado === DISCIPULADO_ACTIVO) {

        $fechaFinalizacion = null;

        $motivoCancelacion = null;

        $inscripcionActiva = obtenerInscripcionActivaDeJoven(
            $pdo,
            (int)$inscripcion['joven_id']
        );

        if (
            $inscripcionActiva
            &&
            (int)$inscripcionActiva['id'] !== $inscripcionId
        ) {

            throw new Exception(
                'Este joven ya tiene otra inscripción activa. No es posible reactivar esta.'
            );

        }

    }

    $stmt = $pdo->prepare("

        UPDATE discipulado_inscripciones

        SET
            estado = :estado,
            fecha_finalizacion = :fecha_finalizacion,
            motivo_cancelacion = :motivo_cancelacion

        WHERE id = :id

        AND ciclo_id = :ciclo_id

    ");

    $stmt->execute([

        'estado' => $estado,

        'fecha_finalizacion' => $fechaFinalizacion,

        'motivo_cancelacion' => $motivoCancelacion,

        'id' => $inscripcionId,

        'ciclo_id' => $cicloId

    ]);

    registrarEventoHistorial(

        $pdo,

        [

            'joven_id' => (int)$inscripcion['joven_id'],

            'reunion_id' => null,

            'tipo_evento' => EVENTO_DISCIPULADO,

            'titulo' => 'Cambio de estado en discipulado',

            'descripcion' =>
                "La inscripción de discipulado cambió a estado {$estado}.",

            'datos_json' => [

                'inscripcion_id' => $inscripcionId,

                'ciclo_id' => $cicloId,

                'estado' => $estado

            ],

            'usuario_id' => usuarioId()

        ]

    );

}


/* ==========================================================
   OBSERVACIONES DE LA INSCRIPCIÓN
   (tabla discipulado_observaciones, creada en la Fase 2)
========================================================== */

function obtenerObservacionesInscripcionDiscipulado(
    PDO $pdo,
    int $inscripcionId
): array {

    $stmt = $pdo->prepare("

        SELECT

            do.id,

            do.observacion,

            do.fecha_creacion,

            u.nombre AS usuario_nombre

        FROM discipulado_observaciones do

        LEFT JOIN usuarios u
            ON u.id = do.usuario_id

        WHERE do.inscripcion_id = :inscripcion_id

        ORDER BY do.fecha_creacion DESC

    ");

    $stmt->execute([
        'inscripcion_id' => $inscripcionId
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);

}


function agregarObservacionInscripcionDiscipulado(
    PDO $pdo,
    int $inscripcionId,
    string $observacion,
    ?int $usuarioId = null
): void {

    $observacion = trim($observacion);

    if ($observacion === '') {

        throw new Exception(
            'La observación no puede estar vacía.'
        );

    }

    $stmt = $pdo->prepare("

        INSERT INTO discipulado_observaciones
        (
            inscripcion_id,
            usuario_id,
            observacion
        )
        VALUES
        (
            :inscripcion_id,
            :usuario_id,
            :observacion
        )

    ");

    $stmt->execute([

        'inscripcion_id' => $inscripcionId,

        'usuario_id' => $usuarioId ?? usuarioId(),

        'observacion' => $observacion

    ]);

}


/* ==========================================================
   ==========================================================
   FASE 6 — PROGRESO POR CLASE
   ==========================================================
   ----------------------------------------------------------
   Trabaja sobre `discipulado_progreso`, ya creada en la
   migración de la Fase 2. Se revisó columna por columna y la
   estructura ya es exactamente la necesaria — no fue
   necesaria ninguna migración nueva:

     inscripcion_id, clase_id  → identificadores
                                 (joven_id se deriva de la
                                 inscripción, no se duplica)
     completada                → boolean (PENDIENTE/COMPLETADA)
     fecha                     → fecha REAL de completado
                                 (independiente de
                                 clases_discipulado.fecha_programada)
     modalidad                 → PRESENCIAL/VIRTUAL de ESA clase
                                 (independiente de la modalidad
                                 principal de la inscripción)
     es_recuperacion           → boolean: distingue "virtual
                                 normal" de "recuperación"
     responsable_id            → usuario que registró (auditoría,
                                 sin hardcodear nombres)
     observaciones             → texto libre de esa clase puntual
     reunion_id                → ya preparado para la Fase 7,
                                 sin usarse todavía
     UNIQUE(inscripcion_id, clase_id) → una clase no puede
                                 tener dos progresos válidos
                                 para la misma inscripción

   DECISIÓN DE DISEÑO (checklist automático, sección 11):

   NO se crean filas de progreso al inscribir al joven (ni
   manual ni automáticamente). "Pendiente" se DERIVA de la
   ausencia de una fila en `discipulado_progreso` para ese
   par (inscripcion_id, clase_id). Solo se inserta una fila
   cuando la clase realmente se marca como completada. Esto
   evita crear y mantener 9 filas "vacías" por joven que no
   aportan información real.

   NO SE TOCA `discipulado_reuniones` ni se marca ninguna
   clase como completada a partir de asistencia — eso es la
   Fase 7. `reunion_id` queda NULL en todas las filas que
   crea esta fase.

   NO SE IMPLEMENTA FINALIZACIÓN: aunque una inscripción
   llegue a 100%, no se escribe nada en
   `discipulado_inscripciones.listo_para_finalizar` — ese
   campo se deja para la fase de finalización. Aquí el 100%
   solo se calcula y se muestra en la vista.
========================================================== */


/* ==========================================================
   OBTENER CHECKLIST DE UNA INSCRIPCIÓN

   Devuelve TODAS las clases del ciclo (en orden), cada una
   indicando si está completada para esta inscripción y, si
   lo está, con qué modalidad/fecha/observación/responsable.
   Las clases sin fila en `discipulado_progreso` se muestran
   como pendientes.
========================================================== */

function obtenerProgresoInscripcionDiscipulado(
    PDO $pdo,
    int $cicloId,
    int $inscripcionId
): array {

    $stmt = $pdo->prepare("

        SELECT

            cd.id AS clase_id,

            cd.numero_orden,

            cd.nombre AS clase_nombre,

            cd.fecha_programada,

            cd.modalidad_programada,

            dp.id AS progreso_id,

            dp.fecha AS fecha_completado,

            dp.modalidad AS modalidad_completado,

            dp.es_recuperacion,

            dp.observaciones,

            dp.responsable_id,

            u.nombre AS responsable_nombre,

            r.tipo AS reunion_tipo,

            r.fecha AS reunion_fecha

        FROM clases_discipulado cd

        LEFT JOIN discipulado_progreso dp
            ON dp.clase_id = cd.id
            AND dp.inscripcion_id = :inscripcion_id

        LEFT JOIN usuarios u
            ON u.id = dp.responsable_id

        LEFT JOIN reuniones r
            ON r.id = dp.reunion_id

        WHERE cd.ciclo_id = :ciclo_id

        ORDER BY cd.numero_orden ASC

    ");

    $stmt->execute([
        'inscripcion_id' => $inscripcionId,
        'ciclo_id' => $cicloId
    ]);

    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($filas as &$fila) {

        $fila['completada'] = !empty($fila['progreso_id']);

    }

    unset($fila);

    return $filas;

}


/* ==========================================================
   OBTENER RESUMEN DE PROGRESO DE UNA INSCRIPCIÓN

   Reutiliza obtenerProgresoInscripcionDiscipulado() en vez
   de repetir la consulta.
========================================================== */

function obtenerResumenProgresoInscripcionDiscipulado(
    PDO $pdo,
    int $cicloId,
    int $inscripcionId
): array {

    $checklist = obtenerProgresoInscripcionDiscipulado(
        $pdo,
        $cicloId,
        $inscripcionId
    );

    $total = count($checklist);

    $completadas = count(

        array_filter(

            $checklist,

            fn (array $c) => $c['completada']

        )

    );

    $pendientes = array_values(

        array_filter(

            $checklist,

            fn (array $c) => !$c['completada']

        )

    );

    $completadasOrdenadas = array_values(

        array_filter(

            $checklist,

            fn (array $c) => $c['completada']

        )

    );

    $ultimaCompletada =
        !empty($completadasOrdenadas)
            ? end($completadasOrdenadas)
            : null;

    $proximaPendiente =
        !empty($pendientes)
            ? $pendientes[0]
            : null;

    return [

        'total_clases' => $total,

        'clases_completadas' => $completadas,

        'clases_pendientes' => $total - $completadas,

        'progreso_porcentaje' =>
            $total > 0
                ? round(($completadas / $total) * 100, 1)
                : 0.0,

        'completo' =>
            $total > 0 && $completadas === $total,

        'ultima_clase_completada' => $ultimaCompletada,

        'proxima_clase_pendiente' => $proximaPendiente,

        'pendientes' => $pendientes

    ];

}


/* ==========================================================
   OBTENER PROGRESO DE UNA CLASE ESPECÍFICA
========================================================== */

function obtenerProgresoClaseInscripcion(
    PDO $pdo,
    int $inscripcionId,
    int $claseId
): array|false {

    $stmt = $pdo->prepare("

        SELECT *

        FROM discipulado_progreso

        WHERE inscripcion_id = :inscripcion_id

        AND clase_id = :clase_id

        LIMIT 1

    ");

    $stmt->execute([
        'inscripcion_id' => $inscripcionId,
        'clase_id' => $claseId
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);

}


/* ==========================================================
   VALIDAR DATOS DE PROGRESO
========================================================== */

function validarDatosProgresoDiscipulado(
    string $modalidad,
    string $fecha
): void {

    validarModalidadPrincipalDiscipulado(
        $modalidad
    );

    if (
        $fecha === ''
        ||
        !validarFecha($fecha)
    ) {

        throw new Exception(
            'La fecha de completado no es válida.'
        );

    }

}


/* ==========================================================
   MARCAR/EDITAR CLASE COMO COMPLETADA (UPSERT)

   Cadena de integridad exigida por la Fase 6 (sección 14):

   1. La inscripción existe Y pertenece al ciclo indicado
      (obtenerInscripcionDiscipuladoDeCiclo).
   2. El joven de esa inscripción existe (se deriva de la
      inscripción — no se confía en un joven_id separado
      enviado por el formulario).
   3. La clase existe Y pertenece al mismo ciclo
      (obtenerClaseDiscipuladoDeCiclo).
   4. La inscripción debe estar ACTIVA (no se registra
      progreso sobre una inscripción cancelada).

   Como clase_id e inscripcion_id ya quedan verificados contra
   el ciclo antes de tocar la base de datos, el UPSERT que
   sigue solo puede afectar la fila correcta — no es posible
   completar la clase de otro ciclo ni de un joven no
   inscrito manipulando el id por URL/formulario.
========================================================== */

function completarClaseProgresoDiscipulado(
    PDO $pdo,
    int $cicloId,
    int $inscripcionId,
    int $claseId,
    array $datos
): int {

    $modalidad = trim((string)($datos['modalidad'] ?? ''));

    $fecha = trim(
        (string)($datos['fecha'] ?? date('Y-m-d'))
    );

    $esRecuperacion =
        !empty($datos['es_recuperacion']);

    $observaciones = trim((string)($datos['observaciones'] ?? ''));

    /* --------------------------------------------------
       reunion_id (FASE 7): opcional. Solo se recibe cuando
       la clase se completa a partir de una asistencia
       (ver procesarDiscipulado). Al completar manualmente
       desde el checklist (Fase 6) simplemente no viene, y
       la columna queda NULL exactamente como antes.
    -------------------------------------------------- */

    $reunionId =
        !empty($datos['reunion_id'])
            ? (int)$datos['reunion_id']
            : null;

    /* --------------------------------------------------
       1-2. LA INSCRIPCIÓN (Y POR ENDE EL JOVEN) EXISTE Y
       PERTENECE A ESTE CICLO
    -------------------------------------------------- */

    $inscripcion = obtenerInscripcionDiscipuladoDeCiclo(
        $pdo,
        $cicloId,
        $inscripcionId
    );

    if (!$inscripcion) {

        throw new Exception(
            'La inscripción no existe o no pertenece a este ciclo.'
        );

    }

    if (
        !obtenerJovenPorId(
            $pdo,
            (int)$inscripcion['joven_id']
        )
    ) {

        throw new Exception(
            'El joven de esta inscripción ya no existe.'
        );

    }

    if ($inscripcion['estado'] !== DISCIPULADO_ACTIVO) {

        throw new Exception(
            'Solo se puede registrar progreso sobre una inscripción activa.'
        );

    }

    /* --------------------------------------------------
       3. LA CLASE EXISTE Y PERTENECE A ESTE CICLO
    -------------------------------------------------- */

    $clase = obtenerClaseDiscipuladoDeCiclo(
        $pdo,
        $cicloId,
        $claseId
    );

    if (!$clase) {

        throw new Exception(
            'La clase no existe o no pertenece a este ciclo.'
        );

    }

    validarDatosProgresoDiscipulado(
        $modalidad,
        $fecha
    );

    $progresoExistente = obtenerProgresoClaseInscripcion(
        $pdo,
        $inscripcionId,
        $claseId
    );

    if ($progresoExistente) {

        throw new Exception(
            'Esta clase ya se encuentra completada.'
        );

    }

    $stmt = $pdo->prepare("

            INSERT INTO discipulado_progreso
            (
                inscripcion_id,
                clase_id,
                completada,
                fecha,
                modalidad,
                es_recuperacion,
                responsable_id,
                observaciones,
                reunion_id
            )
            VALUES
            (
                :inscripcion_id,
                :clase_id,
                1,
                :fecha,
                :modalidad,
                :es_recuperacion,
                :responsable_id,
                :observaciones,
                :reunion_id
            )

        ");

    $stmt->execute([

            'inscripcion_id' => $inscripcionId,

            'clase_id' => $claseId,

            'fecha' => $fecha,

            'modalidad' => strtoupper($modalidad),

            'es_recuperacion' => $esRecuperacion ? 1 : 0,

            'responsable_id' => usuarioId(),

            'observaciones' =>
                $observaciones !== '' ? $observaciones : null,

            'reunion_id' => $reunionId

    ]);

    $progresoId = (int)$pdo->lastInsertId();


    registrarEventoHistorial(

        $pdo,

        [

            'joven_id' => (int)$inscripcion['joven_id'],

            'reunion_id' => $reunionId,

            'tipo_evento' => EVENTO_CLASE,

            'titulo' =>
                $esRecuperacion
                    ? 'Recuperación de clase de discipulado'
                    : 'Clase de discipulado completada',

            'descripcion' =>
                'Clase "' . $clase['nombre'] . '" registrada como completada (' .
                strtoupper($modalidad) . ')' .
                ($reunionId ? ', desde asistencia de reunión.' : '.'),

            'datos_json' => [

                'ciclo_id' => $cicloId,

                'inscripcion_id' => $inscripcionId,

                'clase_id' => $claseId,

                'modalidad' => strtoupper($modalidad),

                'es_recuperacion' => $esRecuperacion,

                'reunion_id' => $reunionId

            ],

            'usuario_id' => usuarioId()

        ]

    );

    return $progresoId;

}


/* ==========================================================
   REVERTIR CLASE A PENDIENTE

   Elimina la fila de progreso (vuelve a ser "pendiente" por
   ausencia de registro). Misma cadena de integridad que
   completarClaseProgresoDiscipulado().
========================================================== */

function revertirProgresoClaseDiscipulado(
    PDO $pdo,
    int $cicloId,
    int $inscripcionId,
    int $claseId
): void {

    $inscripcion = obtenerInscripcionDiscipuladoDeCiclo(
        $pdo,
        $cicloId,
        $inscripcionId
    );

    if (!$inscripcion) {

        throw new Exception(
            'La inscripción no existe o no pertenece a este ciclo.'
        );
    }

    $clase = obtenerClaseDiscipuladoDeCiclo(
        $pdo,
        $cicloId,
        $claseId
    );

    if (!$clase) {

        throw new Exception(
            'La clase no existe o no pertenece a este ciclo.'
        );

    }

    $stmt = $pdo->prepare("

        DELETE FROM discipulado_progreso

        WHERE inscripcion_id = :inscripcion_id

        AND clase_id = :clase_id

    ");

    $stmt->execute([
        'inscripcion_id' => $inscripcionId,
        'clase_id' => $claseId
    ]);

}


/* ==========================================================
   CHECKLIST COMPLETO DEL CICLO (MATRIZ JOVEN × CLASE)

   Reutiliza obtenerClasesDiscipulado() y
   obtenerInscripcionesDiscipulado() (ya existentes) y agrega
   una sola consulta más para traer todo el progreso del
   ciclo de una vez, en vez de una consulta por joven.
========================================================== */

function obtenerChecklistDiscipulado(
    PDO $pdo,
    int $cicloId
): array {

    $clases = obtenerClasesDiscipulado(
        $pdo,
        $cicloId
    );

    $inscripciones = obtenerInscripcionesDiscipulado(
        $pdo,
        $cicloId
    );

    $stmt = $pdo->prepare("

        SELECT

            dp.inscripcion_id,

            dp.clase_id,

            dp.es_recuperacion,

            dp.modalidad

        FROM discipulado_progreso dp

        INNER JOIN discipulado_inscripciones di
            ON di.id = dp.inscripcion_id

        WHERE di.ciclo_id = :ciclo_id

    ");

    $stmt->execute([
        'ciclo_id' => $cicloId
    ]);

    $progresoMapa = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {

        $progresoMapa
            [(int)$fila['inscripcion_id']]
            [(int)$fila['clase_id']] = [

                'es_recuperacion' => (bool)$fila['es_recuperacion'],

                'modalidad' => $fila['modalidad']

            ];

    }

    foreach ($inscripciones as &$inscripcion) {

        $inscripcionId = (int)$inscripcion['id'];

        $celdas = [];

        foreach ($clases as $clase) {

            $claseId = (int)$clase['id'];

            $celdas[$claseId] =
                $progresoMapa[$inscripcionId][$claseId]
                    ?? null;

        }

        $inscripcion['celdas'] = $celdas;

    }

    unset($inscripcion);

    return [

        'clases' => $clases,

        'inscripciones' => $inscripciones

    ];

}


/* ==========================================================
/* ==========================================================
   ==========================================================
   FASE 7 — INTEGRACIÓN CON REUNIONES Y ASISTENCIA
   ==========================================================
   ----------------------------------------------------------
   Se revisó primero todo el sistema existente:

   - `reuniones` (services/reunionService.php): crearReunion()/
     actualizarReunion() solo manejan `tipo` y `fecha`. NO se
     tocan esas funciones — se reutilizan tal cual, y se
     agrega la asociación a ciclo/clase como un paso adicional
     alrededor de ellas (crearReunionDiscipulado /
     actualizarReunionDiscipulado, abajo), exactamente como
     pide la sección 29 ("no crear un controlador paralelo").

   - `discipulado_reuniones` (Fase 2): ya tenía reunion_id,
     ciclo_id, clase_id, modalidad — actúa como tabla puente,
     tal como sugiere la sección 27. Le faltaba una sola
     columna (`es_recuperacion`, sección 12) — se agregó vía
     la migración 2026_08_31_discipulado_reuniones_recuperacion.sql,
     aditiva, sin afectar filas existentes (la tabla estaba
     vacía).

   - `discipulado_progreso.reunion_id` (Fase 2): ya existía
     pero completarClaseProgresoDiscipulado() (Fase 6) nunca
     lo llenaba. Se amplió esa misma función (arriba, en la
     sección Fase 6) para aceptar un `reunion_id` opcional en
     $datos — el flujo manual del checklist sigue funcionando
     exactamente igual (no lo envía, queda NULL como antes).

   - `asistencia`: NO se modifica su estructura. Se reutiliza
     guardarRegistroAsistencia() (asistenciaService.php) sin
     cambios. La integración ocurre exclusivamente dentro de
     procesarDiscipulado() (arriba en este archivo), que ya
     era el punto de enganche que asistenciaService.php llama
     por cada joven — se implementó su lógica real ahí mismo,
     sin tocar asistenciaService.php.

   - `participa_discipulado` (columna vieja de `asistencia`):
     sigue existiendo y se sigue llenando igual que antes por
     construirRegistroAsistencia() (asistenciaService.php,
     sin cambios) — es un indicador general de "asistió a una
     reunión de discipulado", conceptualmente distinto y
     compatible con el progreso por clase de esta fase.
========================================================== */


/* ==========================================================
   OBTENER INSCRIPCIÓN ACTIVA DE UN JOVEN EN UN CICLO
   ESPECÍFICO

   Distinta de existeInscripcionActivaJoven() (Fase 5), que
   busca en CUALQUIER ciclo. Aquí se necesita la inscripción
   activa en el ciclo exacto de la reunión.
========================================================== */

function obtenerInscripcionActivaEnCicloDeJoven(
    PDO $pdo,
    int $jovenId,
    int $cicloId
): array|false {

    $stmt = $pdo->prepare("

        SELECT *

        FROM discipulado_inscripciones

        WHERE joven_id = :joven_id

        AND ciclo_id = :ciclo_id

        AND estado = 'ACTIVO'

        LIMIT 1

    ");

    $stmt->execute([
        'joven_id' => $jovenId,
        'ciclo_id' => $cicloId
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);

}


/* ==========================================================
   OBTENER VÍNCULO DISCIPULADO DE UNA REUNIÓN

   Devuelve la fila de `discipulado_reuniones` (con nombre de
   ciclo y clase) para esa reunión, o false si esta reunión no
   tiene ciclo/clase asociado (reunión de discipulado general,
   o reunión de otro tipo).
========================================================== */

function obtenerVinculoReunionDiscipulado(
    PDO $pdo,
    int $reunionId
): array|false {

    $stmt = $pdo->prepare("

        SELECT

            dr.*,

            c.nombre AS ciclo_nombre,

            c.estado AS ciclo_estado,

            cl.nombre AS clase_nombre,

            cl.numero_orden

        FROM discipulado_reuniones dr

        INNER JOIN ciclos_discipulado c
            ON c.id = dr.ciclo_id

        INNER JOIN clases_discipulado cl
            ON cl.id = dr.clase_id

        WHERE dr.reunion_id = :reunion_id

        LIMIT 1

    ");

    $stmt->execute([
        'reunion_id' => $reunionId
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);


}


/* ==========================================================
   REUNIONES DE DISCIPULADO SIN VINCULAR (HISTÓRICO REAL)
   ----------------------------------------------------------
   Solo lectura. Devuelve reuniones tipo='Discipulado' que NO
   tienen fila en discipulado_reuniones — es decir, asistencia
   histórica real registrada antes de que existiera el módulo
   de ciclos/clases. No se inventa ciclo, clase, modalidad ni
   progreso: esta función no escribe nada, solo expone lo que
   ya existe en reuniones/asistencia tal cual.
========================================================== */

function obtenerReunionesDiscipuladoSinVincular(
    PDO $pdo
): array {

    $stmt = $pdo->prepare("

        SELECT

            r.id,
            r.titulo,
            r.tipo,
            r.fecha,

            COUNT(a.id) AS asistencias_reales,

            COUNT(DISTINCT a.joven_id) AS jovenes_distintos

        FROM reuniones r

        LEFT JOIN asistencia a
            ON a.reunion_id = r.id
            AND a.asistio = 1

        WHERE r.tipo = 'Discipulado'

        AND NOT EXISTS (

            SELECT 1
            FROM discipulado_reuniones dr
            WHERE dr.reunion_id = r.id
        )

        GROUP BY r.id, r.titulo, r.tipo, r.fecha

        ORDER BY r.fecha ASC

    ");

    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);

}


/* ==========================================================
   VINCULAR (O DESVINCULAR) UNA REUNIÓN A CICLO + CLASE

   Reemplaza siempre el vínculo anterior (si lo había) antes
   de crear uno nuevo — así sirve tanto para crear como para
   editar sin duplicar lógica. Si $cicloId es 0, la reunión
   queda sin vínculo (reunión de discipulado sin clase, o
   reunión de otro tipo).

   VALIDACIONES (sección 19 — no confiar en el formulario):

   - El ciclo debe existir.
   - La clase debe existir Y pertenecer EXACTAMENTE a ese
     ciclo (obtenerClaseDiscipuladoDeCiclo ya lo garantiza).
   - La modalidad debe ser válida.
========================================================== */

function vincularReunionDiscipulado(
    PDO $pdo,
    int $reunionId,
    int $cicloId,
    int $claseId,
    string $modalidad,
    bool $esRecuperacion
): void {

    $stmt = $pdo->prepare("

        DELETE FROM discipulado_reuniones

        WHERE reunion_id = :reunion_id

    ");

    $stmt->execute([
        'reunion_id' => $reunionId
    ]);

    if ($cicloId <= 0) {

        /* Sin ciclo: reunión de discipulado sin clase
           asociada, o reunión de otro tipo. Queda sin
           vínculo — no afectará el progreso (sección 4). */

        return;

    }

    if (!obtenerCicloDiscipuladoPorId($pdo, $cicloId)) {

        throw new Exception(
            'El ciclo de discipulado indicado no existe.'
        );

    }

    if ($claseId <= 0) {

        throw new Exception(
            'Debe seleccionar una clase de ese ciclo.'
        );

    }

    if (
        !obtenerClaseDiscipuladoDeCiclo(
            $pdo,
            $cicloId,
            $claseId
        )
    ) {

        throw new Exception(
            'La clase indicada no pertenece a este ciclo.'
        );

    }

    validarModalidadPrincipalDiscipulado($modalidad);

    $stmt = $pdo->prepare("

        INSERT INTO discipulado_reuniones
        (
            reunion_id,
            ciclo_id,
            clase_id,
            modalidad,
            es_recuperacion
        )
        VALUES
        (
            :reunion_id,
            :ciclo_id,
            :clase_id,
            :modalidad,
            :es_recuperacion
        )

    ");

    $stmt->execute([

        'reunion_id' => $reunionId,

        'ciclo_id' => $cicloId,

        'clase_id' => $claseId,

        'modalidad' => strtoupper($modalidad),

        'es_recuperacion' => $esRecuperacion ? 1 : 0

    ]);

}


/* ==========================================================
   CREAR REUNIÓN (CON POSIBLE VÍNCULO DE DISCIPULADO)

   Reutiliza crearReunion() de reunionService.php TAL CUAL —
   no se modifica esa función. Solo se envuelve en una
   transacción junto con el vínculo opcional a ciclo/clase.
========================================================== */

function crearReunionDiscipulado(
    PDO $pdo,
    array $datos
): int {

    $pdo->beginTransaction();

    try {

        $reunionId = crearReunion($pdo, $datos);

        $tipo = strtoupper(
            trim((string)($datos['tipo'] ?? ''))
        );

        if ($tipo === 'DISCIPULADO') {

            vincularReunionDiscipulado(

                $pdo,

                $reunionId,

                (int)($datos['ciclo_id'] ?? 0),

                (int)($datos['clase_id'] ?? 0),

                (string)($datos['modalidad_reunion'] ?? ''),

                !empty($datos['es_recuperacion'])

            );

        }

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {

            $pdo->rollBack();

        }

        throw $e;

    }

    return $reunionId;

}


/* ==========================================================
   ACTUALIZAR REUNIÓN (CON POSIBLE VÍNCULO DE DISCIPULADO)

   Reutiliza actualizarReunion() TAL CUAL. Si el tipo deja de
   ser Discipulado, o se quita el ciclo, el vínculo anterior
   se elimina (vincularReunionDiscipulado con cicloId = 0).
========================================================== */

function actualizarReunionDiscipulado(
    PDO $pdo,
    array $datos
): void {

    $pdo->beginTransaction();

    try {

        actualizarReunion($pdo, $datos);

        $reunionId = (int)($datos['id'] ?? 0);

        $tipo = strtoupper(
            trim((string)($datos['tipo'] ?? ''))
        );

        vincularReunionDiscipulado(

            $pdo,

            $reunionId,

            $tipo === 'DISCIPULADO'
                ? (int)($datos['ciclo_id'] ?? 0)
                : 0,

            (int)($datos['clase_id'] ?? 0),

            (string)($datos['modalidad_reunion'] ?? ''),

            !empty($datos['es_recuperacion'])

        );

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {

            $pdo->rollBack();

        }

        throw $e;

    }

}


/* ==========================================================
   ASISTENTES SIN INSCRIPCIÓN ACTIVA (ADVERTENCIA CONTROLADA)

   Solo lectura — usada por views/reuniones/ver.php para
   avisar (sección 17) cuáles asistentes de una reunión de
   discipulado vinculada a un ciclo NO tienen inscripción
   activa, y por eso no generaron progreso. La asistencia
   general de esos jóvenes sí se guardó normalmente.
========================================================== */

function obtenerAsistentesSinInscripcionDiscipulado(
    PDO $pdo,
    int $reunionId,
    int $cicloId
): array {

    $stmt = $pdo->prepare("

        SELECT

            j.id,

            j.nombre_completo

        FROM asistencia a

        INNER JOIN jovenes j
            ON j.id = a.joven_id

        WHERE a.reunion_id = :reunion_id

        AND a.asistio = 1

        AND NOT EXISTS (

            SELECT 1
            FROM discipulado_inscripciones di
            WHERE di.joven_id = a.joven_id
            AND di.ciclo_id = :ciclo_id
            AND di.estado = 'ACTIVO'

        )

        ORDER BY j.nombre_completo ASC

    ");

    $stmt->execute([
        'reunion_id' => $reunionId,
        'ciclo_id' => $cicloId
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);

}


/* ==========================================================
   FASE 8 — SEGUIMIENTO OPERATIVO
========================================================== */

function obtenerEventosDiscipulado(PDO $pdo, int $cicloId, bool $soloProximos = false): array {
    if (!obtenerCicloDiscipuladoPorId($pdo, $cicloId)) {
        throw new Exception('El ciclo no existe.');
    }
    $sql = 'SELECT * FROM discipulado_eventos WHERE ciclo_id = :ciclo_id';
    if ($soloProximos) { $sql .= ' AND fecha >= CURDATE()'; }
    $sql .= ' ORDER BY fecha ASC, hora ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['ciclo_id' => $cicloId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function crearEventoDiscipulado(PDO $pdo, int $cicloId, array $datos): int {
    if (!obtenerCicloDiscipuladoPorId($pdo, $cicloId)) { throw new Exception('El ciclo no existe.'); }
    $nombre = trim((string)($datos['nombre'] ?? ''));
    $fecha = trim((string)($datos['fecha'] ?? ''));
    $hora = trim((string)($datos['hora'] ?? ''));
    $descripcion = trim((string)($datos['descripcion'] ?? ''));
    if ($nombre === '' || mb_strlen($nombre) > 150) { throw new Exception('El nombre del evento es obligatorio y debe tener máximo 150 caracteres.'); }
    if (!validarFecha($fecha)) { throw new Exception('La fecha del evento no es válida.'); }
    if ($hora !== '' && !preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $hora)) { throw new Exception('La hora del evento no es válida.'); }
    $stmt = $pdo->prepare('INSERT INTO discipulado_eventos (ciclo_id, nombre, fecha, hora, descripcion, creado_por) VALUES (:ciclo_id, :nombre, :fecha, :hora, :descripcion, :creado_por)');
    $stmt->execute(['ciclo_id' => $cicloId, 'nombre' => $nombre, 'fecha' => $fecha, 'hora' => $hora !== '' ? $hora : null, 'descripcion' => $descripcion !== '' ? $descripcion : null, 'creado_por' => usuarioId()]);
    return (int)$pdo->lastInsertId();
}

function actualizarEventoDiscipulado(PDO $pdo, int $cicloId, int $eventoId, array $datos): void {
    if (!obtenerCicloDiscipuladoPorId($pdo, $cicloId)) { throw new Exception('El ciclo no existe.'); }
    $nombre = trim((string)($datos['nombre'] ?? ''));
    $fecha = trim((string)($datos['fecha'] ?? ''));
    $hora = trim((string)($datos['hora'] ?? ''));
    $descripcion = trim((string)($datos['descripcion'] ?? ''));
    if ($eventoId <= 0 || $nombre === '' || mb_strlen($nombre) > 150) { throw new Exception('El evento es inválido.'); }
    if (!validarFecha($fecha)) { throw new Exception('La fecha del evento no es válida.'); }
    if ($hora !== '' && !preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $hora)) { throw new Exception('La hora del evento no es válida.'); }
    $stmt = $pdo->prepare('UPDATE discipulado_eventos SET nombre = :nombre, fecha = :fecha, hora = :hora, descripcion = :descripcion WHERE id = :id AND ciclo_id = :ciclo_id');
    $stmt->execute(['nombre' => $nombre, 'fecha' => $fecha, 'hora' => $hora !== '' ? $hora : null, 'descripcion' => $descripcion !== '' ? $descripcion : null, 'id' => $eventoId, 'ciclo_id' => $cicloId]);
    if ($stmt->rowCount() === 0) {
        $verificar = $pdo->prepare('SELECT id FROM discipulado_eventos WHERE id = :id AND ciclo_id = :ciclo_id');
        $verificar->execute(['id' => $eventoId, 'ciclo_id' => $cicloId]);
        if (!$verificar->fetchColumn()) { throw new Exception('El evento no existe o no pertenece al ciclo.'); }
    }
}

function eliminarEventoDiscipulado(PDO $pdo, int $cicloId, int $eventoId): void {
    $stmt = $pdo->prepare('DELETE FROM discipulado_eventos WHERE id = :id AND ciclo_id = :ciclo_id');
    $stmt->execute(['id' => $eventoId, 'ciclo_id' => $cicloId]);
    if ($stmt->rowCount() === 0) { throw new Exception('El evento no existe o no pertenece al ciclo.'); }
}

function obtenerAlertaInscripcionDiscipulado(PDO $pdo, int $cicloId, int $inscripcionId): array {
    $resumen = obtenerResumenProgresoInscripcionDiscipulado($pdo, $cicloId, $inscripcionId);
    $ciclo = obtenerCicloDiscipuladoPorId($pdo, $cicloId);
    $hoy = date('Y-m-d');
    $programadas = array_filter(obtenerProgresoInscripcionDiscipulado($pdo, $cicloId, $inscripcionId), fn(array $clase) => !empty($clase['fecha_programada']) && $clase['fecha_programada'] <= $hoy);
    $atrasado = count($programadas) > (int)$resumen['clases_completadas'];
    $fechaFinal = $ciclo['fecha_fin'] ?? null;
    foreach (obtenerEventosDiscipulado($pdo, $cicloId) as $evento) {
        if (mb_strtoupper($evento['nombre']) === 'FINALIZA DISCIPULADO') { $fechaFinal = $evento['fecha']; break; }
    }
    $proximoFinal = $fechaFinal && $fechaFinal >= $hoy && $fechaFinal <= date('Y-m-d', strtotime('+7 days')) && !$resumen['completo'];
    $recuperaciones = count(array_filter(obtenerProgresoInscripcionDiscipulado($pdo, $cicloId, $inscripcionId), fn(array $clase) => !empty($clase['es_recuperacion'])));
    if ($resumen['completo']) { $estado = 'COMPLETADO'; $mensaje = 'Listo para finalizar'; }
    elseif ($proximoFinal) { $estado = 'PROXIMO_A_FINALIZAR'; $mensaje = 'Próximo a finalizar con clases pendientes'; }
    elseif ($atrasado) { $estado = 'ATRASADO'; $mensaje = 'Avance inferior a las clases programadas'; }
    elseif ($resumen['clases_pendientes'] > 0) { $estado = 'PENDIENTES'; $mensaje = 'Tiene clases pendientes'; }
    else { $estado = 'SIN_ALERTAS'; $mensaje = 'Sin alertas de progreso'; }
    return ['estado' => $estado, 'mensaje' => $mensaje, 'recuperaciones' => $recuperaciones, 'avance_esperado_disponible' => count($programadas) > 0];
}

function obtenerResumenCicloDiscipulado(PDO $pdo, int $cicloId): array {
    $inscripciones = obtenerInscripcionesDiscipulado($pdo, $cicloId);
    $total = count($inscripciones); $completados = 0; $pendientes = 0; $suma = 0.0; $atencion = 0;
    foreach ($inscripciones as &$inscripcion) {
        $alerta = obtenerAlertaInscripcionDiscipulado($pdo, $cicloId, (int)$inscripcion['id']);
        $inscripcion['alerta'] = $alerta;
        $suma += (float)$inscripcion['progreso_porcentaje'];
        if ((int)$inscripcion['clases_pendientes'] > 0) { $pendientes++; }
        if ($alerta['estado'] === 'COMPLETADO') { $completados++; }
        if (in_array($alerta['estado'], ['PENDIENTES', 'ATRASADO', 'PROXIMO_A_FINALIZAR'], true)) { $atencion++; }
    }
    unset($inscripcion);
    $eventos = obtenerEventosDiscipulado($pdo, $cicloId, true);
    return ['participantes' => $total, 'completados' => $completados, 'en_progreso' => $total - $completados, 'con_pendientes' => $pendientes, 'total_clases' => count(obtenerClasesDiscipulado($pdo, $cicloId)), 'avance_promedio' => $total ? round($suma / $total, 1) : 0.0, 'requieren_atencion' => $atencion, 'proximo_evento' => $eventos[0] ?? null, 'inscripciones' => $inscripciones];
}

/* ==========================================================
   FIN DEL SERVICE (FASE 8)
========================================================== */
