<?php

declare(strict_types=1);

require_once __DIR__ . '/actividadService.php';
require_once __DIR__ . '/../middleware/permiso.php';

/*
|--------------------------------------------------------------------------
| Joven Service
|--------------------------------------------------------------------------
|
| Servicio encargado de la gestión de jóvenes.
|
*/

/* ==========================================================
   CONSTANTES
========================================================== */

const GENEROS = [

    'M',

    'F'

];

/* ==========================================================
   ESTADO CONGREGACIONAL (Fase 6 -- reemplaza a lo que antes
   se llamaba "estado espiritual"). Ya NO mezcla servidor/
   líder/discipulado: esos ahora vienen de Usuarios y de
   Discipulado respectivamente (ver esServidorMinisterioJovenes()
   en actividadService.php y el módulo discipuladoService.php).

   Es un dato PURAMENTE derivado de fecha_ingreso -- nunca un
   campo manual del formulario (evita contradicciones entre lo
   que alguien selecciona y lo que dice la fecha real).
========================================================== */

const ESTADOS_CONGREGACIONALES = [

    'NUEVO',

    'CONGREGANTE'

];

const MESES_NUEVO_A_CONGREGANTE = 6;

const ESTADOS_ACTIVIDAD = [

    'ACTIVO',

    'INACTIVO',

    'ELIMINADO'

];

/* ==========================================================
   CALCULAR ESTADO CONGREGACIONAL

   NUEVO = menos de 6 meses desde fecha_ingreso.
   CONGREGANTE = 6 meses o mas desde fecha_ingreso.

   Sin fecha_ingreso (no debería ocurrir, pero por seguridad)
   se trata como NUEVO -- nunca se asume CONGREGANTE sin
   evidencia de la fecha real.
========================================================== */

function calcularEstadoCongregacional(
    ?string $fechaIngreso
): string {

    if (empty($fechaIngreso)) {
        return 'NUEVO';
    }

    $diff = (
        new DateTime($fechaIngreso)
    )->diff(new DateTime());

    $meses = ($diff->y * 12) + $diff->m;

    return $meses >= MESES_NUEVO_A_CONGREGANTE
        ? 'CONGREGANTE'
        : 'NUEVO';
}

/* ==========================================================
   ACTUALIZAR ESTADO CONGREGACIONAL (masivo)

   A diferencia de estado_actividad (que necesita recorrer
   reuniones por joven), esto es una funcion pura de
   fecha_ingreso: una sola sentencia UPDATE alcanza, sin
   necesidad de recorrer jovenes en PHP. Segura de llamar en
   cualquier vista que lea estado_espiritual (barata).
========================================================== */

function actualizarEstadoCongregacional(
    PDO $pdo
): void {

    $pdo->exec("
        UPDATE jovenes
        SET estado_espiritual = CASE
            WHEN fecha_ingreso IS NULL THEN 'NUEVO'
            WHEN TIMESTAMPDIFF(MONTH, fecha_ingreso, CURDATE()) >= " . MESES_NUEVO_A_CONGREGANTE . " THEN 'CONGREGANTE'
            ELSE 'NUEVO'
        END
        WHERE estado_actividad != 'ELIMINADO'
    ");
}

/* ==========================================================
   OBTENER JOVENES DISPONIBLES PARA VINCULAR A UN USUARIO
   (Fase 6 -- para el select "Joven asociado" en Usuarios)

   Devuelve jóvenes sin usuario_id todavía, más el joven
   actualmente vinculado a $usuarioIdActual si se está
   editando (para que su propio vínculo siga apareciendo
   seleccionado en el <select>, aunque ya tenga usuario_id).
========================================================== */

function obtenerJovenesDisponiblesParaVincular(
    PDO $pdo,
    ?int $usuarioIdActual = null
): array {

    $stmt = $pdo->prepare("
        SELECT id, nombre_completo

        FROM jovenes

        WHERE estado_actividad != 'ELIMINADO'
        AND (
            usuario_id IS NULL
            OR usuario_id = :usuario_id_actual
        )

        ORDER BY nombre_completo ASC
    ");

    $stmt->execute([
        'usuario_id_actual' => $usuarioIdActual
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ==========================================================
   OBTENER JOVEN POR ID
========================================================== */

function obtenerJovenPorId(
    PDO $pdo,
    int $id
): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM jovenes
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([

        ':id' => $id

    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}


/* ==========================================================
   PERFIL DE ASISTENCIA JUVENIL -- PARTE 1 + PARTE 2

   Indicadores derivados (no se persiste nada nuevo en BD):

     - tiene_primera_asistencia: bool
     - total_historico: int  (asistio=1, tipos juveniles)
     - ultimos_12_meses: int (asistio=1, tipos juveniles,
       reuniones.fecha dentro de los ultimos 12 meses)
     - porcentaje_asistencia_historico: int|null  (PARTE 2 --
       antes se llamaba 'porcentaje_asistencia'; renombrado
       para no confundirlo con el de 12 meses. Sobre TODO el
       historico demostrable, no solo los ultimos 12 meses)
     - porcentaje_asistencia_12_meses: int|null (nuevo -- solo
       evidencia explicita dentro de los ultimos 12 meses)
     - ultima_asistencia: string|null (fecha, PARTE 2)
     - ultimas_8_reuniones: array (PARTE 2)
     - asistencias_ultimas_8_reuniones: int (nuevo -- conteo de
       PRESENTE explicito dentro de ultimas_8_reuniones)
     - porcentaje_ultimas_8_reuniones: int|null (nuevo -- sobre
       evidencia explicita dentro de las ultimas 8 reuniones)
     - ausencias_consecutivas_actuales: int (PARTE 2)
     - hay_desconocido_en_racha: bool (metadato auxiliar, PARTE 2)

   Reglas aplicadas (consistentes con el modelo 4/12 de
   actividadService.php):

     - Solo cuentan reuniones de TIPOS_REUNION_JUVENIL
       ('Reunión Jóvenes' y 'Grupo Conexión'), reutilizando
       la misma constante centralizada -- no se duplica la
       lista de tipos en un segundo lugar. Discipulado,
       Evento Especial y Otro NO cuentan (no se mezclan
       ministerios, ver Caso E/H de las pruebas obligatorias).
     - Se usa reuniones.fecha para todo lo temporal, NUNCA
       asistencia.fecha_registro ni asistencia.created_at.
     - "Primera asistencia" es un hecho binario independiente
       de estado_actividad/estado_espiritual/intermitencia: no
       se mezcla con esos conceptos aqui.

   PARTE 2 -- manejo de evidencia (igual criterio que el
   modelo 4/12 en actividadService.php):

     - asistio=1 -> presencia demostrada.
     - asistio=0 -> ausencia demostrada.
     - fila inexistente -> DESCONOCIDO. Nunca se convierte en
       ausencia ni en presencia. No entra al denominador del
       porcentaje, aparece como "SIN_REGISTRO" en las ultimas
       8 reuniones, y corta la racha actual sin contarse.
     - El porcentaje solo usa evidencia explicita (asistio=0
       o 1); si no hay ninguna, el resultado es null (la vista
       debe mostrar "Sin datos", nunca "0%").
     - La racha actual reutiliza literalmente
       ausenciasConsecutivasJuveniles() de actividadService.php
       (ya implementada y probada en la Fase 3 del modelo
       4/12) -- no se duplica esa logica aqui.
========================================================== */

function obtenerPerfilAsistenciaJuvenil(
    PDO $pdo,
    int $jovenId
): array {

    $placeholders = [];
    $params = [
        'joven_id' => $jovenId,
    ];

    foreach (TIPOS_REUNION_JUVENIL as $i => $tipo) {
        $key = "tipo{$i}";
        $placeholders[] = ":{$key}";
        $params[$key] = $tipo;
    }

    $listaTipos = implode(', ', $placeholders);

    /*
       Fecha de referencia para "ultimos 12 meses": se calcula
       en PHP con la fecha actual del servidor (igual criterio
       que mesesMinisterio() en actividadService.php), y se
       compara contra reuniones.fecha.
    */

    $params['fecha_limite_12_meses'] = (new DateTime())
        ->modify('-12 months')
        ->format('Y-m-d');

    /* ------------------------------------------------------
       CONSULTA 1: agregados (historico, 12 meses, ausencias
       explicitas historicas para el porcentaje, y ultima
       asistencia). Un solo INNER JOIN asistencia+reuniones
       -- por construccion, las filas inexistentes (UNKNOWN)
       jamas entran aqui, asi que nunca se cuentan como 0.
    ------------------------------------------------------ */

    $sql = "
        SELECT
            SUM(
                CASE WHEN a.asistio = 1 THEN 1 ELSE 0 END
            ) AS total_historico,

            SUM(
                CASE
                    WHEN a.asistio = 1
                     AND r.fecha >= :fecha_limite_12_meses
                    THEN 1
                    ELSE 0
                END
            ) AS ultimos_12_meses,

            SUM(
                CASE
                    WHEN a.asistio = 0
                     AND r.fecha >= :fecha_limite_12_meses
                    THEN 1
                    ELSE 0
                END
            ) AS ausencias_explicitas_12_meses,

            SUM(
                CASE WHEN a.asistio = 0 THEN 1 ELSE 0 END
            ) AS ausencias_explicitas_historicas,

            MAX(
                CASE WHEN a.asistio = 1 THEN r.fecha END
            ) AS ultima_asistencia

        FROM asistencia a

        INNER JOIN reuniones r
            ON r.id = a.reunion_id

        WHERE a.joven_id = :joven_id

        AND r.tipo IN ({$listaTipos})
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $fila = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalHistorico     = (int) ($fila['total_historico'] ?? 0);
    $ultimos12Meses     = (int) ($fila['ultimos_12_meses'] ?? 0);
    $ausenciasExplicitas12Meses = (int) ($fila['ausencias_explicitas_12_meses'] ?? 0);
    $ausenciasExplicitas = (int) ($fila['ausencias_explicitas_historicas'] ?? 0);
    $ultimaAsistencia   = $fila['ultima_asistencia'] ?? null;

    $denominadorDemostrable = $totalHistorico + $ausenciasExplicitas;

    $porcentajeAsistenciaHistorico = $denominadorDemostrable > 0
        ? (int) round(($totalHistorico / $denominadorDemostrable) * 100)
        : null;

    $denominador12Meses = $ultimos12Meses + $ausenciasExplicitas12Meses;

    $porcentajeAsistencia12Meses = $denominador12Meses > 0
        ? (int) round(($ultimos12Meses / $denominador12Meses) * 100)
        : null;

    /* ------------------------------------------------------
       CONSULTA 2: últimas 8 reuniones juveniles (todas, con
       o sin fila para este joven -- LEFT JOIN sin COALESCE,
       para preservar el NULL de "sin registro").
    ------------------------------------------------------ */

    $sqlUltimas8 = "
        SELECT
            r.fecha,
            r.tipo,
            a.asistio

        FROM reuniones r

        LEFT JOIN asistencia a
            ON a.reunion_id = r.id
            AND a.joven_id = :joven_id

        WHERE r.tipo IN ({$listaTipos})

        ORDER BY r.fecha DESC, r.id DESC

        LIMIT 8
    ";

    $paramsUltimas8 = $params;
    unset($paramsUltimas8['fecha_limite_12_meses']);

    $stmtUltimas8 = $pdo->prepare($sqlUltimas8);
    $stmtUltimas8->execute($paramsUltimas8);

    $ultimas8Reuniones = [];

    foreach ($stmtUltimas8->fetchAll(PDO::FETCH_ASSOC) as $reunion) {

        if ($reunion['asistio'] === null) {
            $estado = 'SIN_REGISTRO';
        } elseif ((int) $reunion['asistio'] === 1) {
            $estado = 'PRESENTE';
        } else {
            $estado = 'AUSENTE';
        }

        $ultimas8Reuniones[] = [
            'fecha'  => $reunion['fecha'],
            'tipo'   => $reunion['tipo'],
            'estado' => $estado,
        ];
    }

    /* ------------------------------------------------------
       RACHA ACTUAL -- reutiliza la funcion ya implementada
       y probada del modelo 4/12 (actividadService.php). No
       se duplica esa logica de "desconocido rompe la racha".
    ------------------------------------------------------ */

    $racha = ausenciasConsecutivasJuveniles(
        $pdo,
        $jovenId
    );

    /* ------------------------------------------------------
       PARTE 3 -- INTERMITENCIA INFORMATIVA

       REGLA OFICIAL ADOPTADA (ya no es propuesta ni está
       pendiente de validación: el responsable del ministerio
       la confirmó). Queda como característica INFORMATIVA:
       no cambia estado_actividad, no convierte a nadie en
       INACTIVO, no reemplaza la alerta de 4 ausencias ni la
       de 12, y no implica desconexión de la iglesia.

       Puramente derivada de ultimas_8_reuniones (ya calculada
       arriba) y de la racha actual (ya calculada arriba). No
       se persiste nada, no es un estado de BD, no se llama
       para modificar estado_actividad.

       Reglas (segun el prompt de Parte 3):

       A/B. Minimo 2 presentes explicitos y 2 ausentes
            explicitos entre las ultimas 8 reuniones.
       C.   Alternancia real: no basta un unico cambio de
            grupo (ej. P,P,A,A tiene 1 sola transicion y NO
            cuenta); se exige que existan al menos 2
            transiciones en la secuencia de estados EXPLICITOS
            (ignorando por completo los SIN_REGISTRO al
            construir esa secuencia -- un SIN_REGISTRO nunca
            se convierte en P ni en A, y nunca se usa para
            fabricar una transicion).
       D.   Si ausencias_consecutivas_actuales >= UMBRAL_ALERTA_JUVENIL
            (4), la situacion actual tiene prioridad: se
            reporta NO intermitente de forma confiada (no es
            "datos insuficientes": la propia racha ya es
            evidencia suficiente para esa conclusion).

       intermitencia_evaluable distingue "No intermitente"
       (hay evidencia suficiente y el patron simplemente no se
       cumple) de "Datos insuficientes" (no hay suficientes
       estados explicitos -- menos de 4 -- para siquiera poder
       alcanzar los minimos 2/2, sin importar como se combinen).
       Cuando no es evaluable, patron_intermitente se devuelve
       en null (ni true ni false), para que la vista pueda
       mostrar "Datos insuficientes" en vez de arriesgarse a
       decir "No" con una base debil.
    ------------------------------------------------------ */

    $secuenciaCronologica = array_reverse($ultimas8Reuniones);

    $estadosExplicitos = array_values(array_filter(
        array_map(
            static fn(array $r): string => $r['estado'],
            $secuenciaCronologica
        ),
        static fn(string $estado): bool => $estado !== 'SIN_REGISTRO'
    ));

    $intermitenciaPresentes = count(array_filter(
        $estadosExplicitos,
        static fn(string $e): bool => $e === 'PRESENTE'
    ));

    $intermitenciaAusentes = count(array_filter(
        $estadosExplicitos,
        static fn(string $e): bool => $e === 'AUSENTE'
    ));

    $transiciones = 0;

    for ($i = 1; $i < count($estadosExplicitos); $i++) {
        if ($estadosExplicitos[$i] !== $estadosExplicitos[$i - 1]) {
            $transiciones++;
        }
    }

    $hayAlternanciaReal = $transiciones >= 2;

    /*
       Porcentaje de las ultimas 8 reuniones: solo sobre
       evidencia explicita (mismo criterio que los demas
       porcentajes -- un SIN_REGISTRO no cuenta ni como
       presencia ni como ausencia, y no entra al denominador).
    */

    $denominadorUltimas8 = $intermitenciaPresentes + $intermitenciaAusentes;

    $porcentajeUltimas8Reuniones = $denominadorUltimas8 > 0
        ? (int) round(($intermitenciaPresentes / $denominadorUltimas8) * 100)
        : null;

    $rachaActualExcluye =
        $racha['ausencias_consecutivas_demostrables'] >= UMBRAL_ALERTA_JUVENIL;

    if ($rachaActualExcluye) {

        /*
         * La racha actual ya es evidencia suficiente: se
         * reporta NO intermitente de forma confiada, sin
         * importar la alternancia historica.
         */

        $intermitenciaEvaluable = true;
        $patronIntermitente = false;

    } elseif (count($estadosExplicitos) < 4) {

        /*
         * Con menos de 4 estados explicitos es matematicamente
         * imposible alcanzar 2 presentes + 2 ausentes -- pero
         * eso no significa "No intermitente", significa que no
         * hay evidencia suficiente para afirmarlo.
         */

        $intermitenciaEvaluable = false;
        $patronIntermitente = null;

    } else {

        $intermitenciaEvaluable = true;

        $patronIntermitente = (
            $intermitenciaPresentes >= 2
            && $intermitenciaAusentes >= 2
            && $hayAlternanciaReal
        );
    }

    return [
        'tiene_primera_asistencia'          => $totalHistorico > 0,
        'total_historico'                   => $totalHistorico,
        'ultimos_12_meses'                  => $ultimos12Meses,
        'porcentaje_asistencia_historico'   => $porcentajeAsistenciaHistorico,
        'porcentaje_asistencia_12_meses'    => $porcentajeAsistencia12Meses,
        'ultima_asistencia'                 => $ultimaAsistencia,
        'ultimas_8_reuniones'               => $ultimas8Reuniones,
        'asistencias_ultimas_8_reuniones'   => $intermitenciaPresentes,
        'porcentaje_ultimas_8_reuniones'    => $porcentajeUltimas8Reuniones,
        'ausencias_consecutivas_actuales'   => $racha['ausencias_consecutivas_demostrables'],
        'hay_desconocido_en_racha'          => $racha['hay_desconocido'],
        'patron_intermitente'               => $patronIntermitente,
        'intermitencia_evaluable'           => $intermitenciaEvaluable,
        'intermitencia_presentes'           => $intermitenciaPresentes,
        'intermitencia_ausentes'            => $intermitenciaAusentes,
    ];
}



/* ==========================================================
   OBTENER POR TELÉFONO
========================================================== */

function obtenerJovenPorTelefono(
    PDO $pdo,
    string $telefono
): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM jovenes
        WHERE telefono = :telefono
        LIMIT 1
    ");

    $stmt->execute([

        ':telefono' => $telefono

    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* ==========================================================
   EXISTE JOVEN
========================================================== */

function existeJoven(
    PDO $pdo,
    int $id
): bool
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM jovenes
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([

        ':id' => $id

    ]);

    return (bool) $stmt->fetch();
}

/* ==========================================================
   EXISTE DUPLICADO
========================================================== */

function existeJovenDuplicado(
    PDO $pdo,
    string $nombre,
    ?string $telefono,
    int $ignorarId = 0
): bool
{
    $sql = "
        SELECT id
        FROM jovenes
        WHERE nombre_completo = :nombre
        AND telefono <=> :telefono
    ";

    if ($ignorarId > 0) {

        $sql .= "
            AND id != :id
        ";

    }

    $sql .= "
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);

    $params = [

        ':nombre' => trim($nombre),

        ':telefono' => $telefono

    ];

    if ($ignorarId > 0) {

        $params[':id'] = $ignorarId;

    }

    $stmt->execute($params);

    return (bool) $stmt->fetch();
}

/* ==========================================================
   OBTENER TODOS LOS JÓVENES
========================================================== */

function obtenerJovenes(
    PDO $pdo,
    bool $incluirEliminados = false
): array
{
    $sql = "
        SELECT *
        FROM jovenes
    ";

    if (!$incluirEliminados) {

        $sql .= "
            WHERE estado_actividad != 'ELIMINADO'
        ";

    }

    $sql .= "
        ORDER BY nombre_completo ASC
    ";

    return $pdo
        ->query($sql)
        ->fetchAll(PDO::FETCH_ASSOC);
}

/* ==========================================================
   OBTENER JÓVENES ACTIVOS
========================================================== */

function obtenerJovenesActivos(
    PDO $pdo
): array
{
    $stmt = $pdo->query("
        SELECT

            id,

            nombre_completo,

            telefono,

            genero,

            estado_espiritual

        FROM jovenes

        WHERE estado_actividad = 'ACTIVO'

        ORDER BY nombre_completo ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ==========================================================
   CONTAR JÓVENES ACTIVOS
========================================================== */

function contarJovenesActivos(
    PDO $pdo
): int
{
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM jovenes
        WHERE estado_actividad = 'ACTIVO'
    ");

    return (int) $stmt->fetchColumn();
}

/* ==========================================================
   VALIDAR JOVEN
========================================================== */

function validarJoven(
    PDO $pdo,
    int $id
): void
{
    if ($id <= 0) {

        throw new Exception(
            'Joven inválido.'
        );

    }

    if (!existeJoven($pdo, $id)) {

        throw new Exception(
            'El joven no existe.'
        );

    }
}

/* ==========================================================
   VALIDAR NOMBRE
========================================================== */

function validarNombreJoven(
    string $nombre
): string
{
    $nombre = normalizarNombrePersona($nombre);

    if ($nombre === '') {

        throw new Exception(
            'Debe ingresar el nombre del joven.'
        );

    }

    if (mb_strlen($nombre) > 150) {

        throw new Exception(
            'El nombre es demasiado largo.'
        );

    }

    return $nombre;
}

/* ==========================================================
   VALIDAR GÉNERO
========================================================== */

function validarGeneroJoven(
    string $genero
): string
{
    $genero = strtoupper(
        trim($genero)
    );

    if (!in_array(
        $genero,
        GENEROS,
        true
    )) {

        throw new Exception(
            'Debe seleccionar un género válido.'
        );

    }

    return $genero;
}

/* ==========================================================
   VALIDAR SERVIDOR
========================================================== */

function validarServidorJoven(
    int $servidor
): int
{
    if (!in_array(
        $servidor,
        [0, 1],
        true
    )) {

        throw new Exception(
            'Valor de servidor inválido.'
        );

    }

    return $servidor;
}

/* ==========================================================
   VALIDAR FECHA INGRESO
========================================================== */

function validarFechaIngresoJoven(
    ?string $fecha
): ?string
{
    if (
        $fecha === null ||
        $fecha === ''
    ) {

        return null;

    }

    if (!strtotime($fecha)) {

        throw new Exception(
            'Fecha de ingreso inválida.'
        );

    }

    return $fecha;
}

/* ==========================================================
   VALIDAR EDAD
========================================================== */

function validarEdadJoven(
    ?string $fechaNacimiento,
    ?int $edadManual
): array
{
    if ($fechaNacimiento) {

        if (!strtotime($fechaNacimiento)) {

            throw new Exception(
                'Fecha de nacimiento inválida.'
            );

        }

        return [

            $fechaNacimiento,

            null,

            null

        ];
    }

    if ($edadManual === null) {

        throw new Exception(
            'Debe ingresar la edad o la fecha de nacimiento.'
        );

    }

    if (
        $edadManual < 0 ||
        $edadManual > 120
    ) {

        throw new Exception(
            'Edad inválida.'
        );

    }

    return [

        null,

        $edadManual,

        date('Y-m-d')

    ];
}

/* ==========================================================
   VALIDAR TELÉFONO
========================================================== */

function validarTelefonoJoven(
    ?string $telefono,
    bool $sinTelefono
): ?string
{
    if ($sinTelefono) {

        return null;

    }

    $telefono = trim(
        (string) $telefono
    );

    if ($telefono === '') {

        throw new Exception(
            'Debe ingresar un teléfono o marcar "Sin teléfono".'
        );

    }

    if (!preg_match(
        '/^[0-9]{7,15}$/',
        $telefono
    )) {

        throw new Exception(
            'El teléfono no es válido.'
        );

    }

    return $telefono;
}

/* ==========================================================
   VALIDAR DUPLICADO
========================================================== */

function validarDuplicadoJoven(
    PDO $pdo,
    string $nombre,
    ?string $telefono,
    int $id = 0
): void
{
    if (
        existeJovenDuplicado(
            $pdo,
            $nombre,
            $telefono,
            $id
        )
    ) {

        throw new Exception(
            'Ya existe un joven con ese nombre y teléfono.'
        );

    }
}

/* ==========================================================
   VALIDAR OBSERVACIONES
========================================================== */

function validarObservacionesJoven(
    ?string $texto
): ?string
{
    $texto = trim(
        (string) $texto
    );

    if ($texto === '') {

        return null;

    }

    if (mb_strlen($texto) > 5000) {

        throw new Exception(
            'Las observaciones son demasiado largas.'
        );

    }

    return $texto;
}

/* ==========================================================
   PREPARAR DATOS DEL JOVEN
========================================================== */

function prepararDatosJoven(
    PDO $pdo,
    array $datos,
    int $id = 0
): array
{
    /* ==========================================
       NOMBRE
    ========================================== */

    $nombre = validarNombreJoven(
        $datos['nombre_completo'] ?? ''
    );

    /* ==========================================
       GÉNERO
    ========================================== */

    $genero = validarGeneroJoven(
        $datos['genero'] ?? ''
    );

    /* ==========================================
       FECHA INGRESO
    ========================================== */

    $fechaIngreso =
        validarFechaIngresoJoven(
            $datos['fecha_ingreso'] ?? null
        );

    /* ==========================================
       ESTADO CONGREGACIONAL (Fase 6 -- derivado,
       nunca tomado del formulario. Depende de
       fechaIngreso, por eso se calcula despues.)
    ========================================== */

    $estadoEspiritual =
        calcularEstadoCongregacional(
            $fechaIngreso
        );

    /* ==========================================
       SERVIDOR (de cualquier ministerio -- campo
       distinto y ya existente, sin relación con la
       migración de Fase 6).

       Fase 8 -- el campo manual se retiró del
       formulario (crear.php/editar.php), así que ya
       no llega en $datos. Para no perder el dato
       histórico de un joven que YA tenía es_servidor=1,
       si estamos editando (id>0) y el formulario no
       envía la clave, se preserva el valor actual en
       base de datos en vez de asumir 0. Al crear
       (id=0) no hay nada que preservar: 0 por defecto.
    ========================================== */

    if (array_key_exists('es_servidor', $datos)) {

        $esServidor = validarServidorJoven(
            (int) $datos['es_servidor']
        );

    } elseif ($id > 0) {

        $stmtEsServidor = $pdo->prepare("
            SELECT es_servidor FROM jovenes WHERE id = :id
        ");

        $stmtEsServidor->execute(['id' => $id]);

        $esServidor = validarServidorJoven(
            (int) ($stmtEsServidor->fetchColumn() ?: 0)
        );

    } else {

        $esServidor = validarServidorJoven(0);

    }

    /* ==========================================
       EDAD
    ========================================== */

    [

        $fechaNacimiento,

        $edadManual,

        $fechaActualizacionEdad

    ] = validarEdadJoven(

        !empty($datos['fecha_nacimiento'])
            ? $datos['fecha_nacimiento']
            : null,

        !empty($datos['edad_manual'])
            ? (int)$datos['edad_manual']
            : null

    );

    /* ==========================================
       TELÉFONO
    ========================================== */

    $telefono =
        validarTelefonoJoven(

            $datos['telefono'] ?? null,

            isset($datos['sinTelefono'])

        );

    /* ==========================================
       DUPLICADOS
    ========================================== */

    validarDuplicadoJoven(

        $pdo,

        $nombre,

        $telefono,

        $id

    );

    /* ==========================================
       OBSERVACIONES
    ========================================== */

    $observaciones =
        validarObservacionesJoven(
            $datos['observaciones'] ?? null
        );

    /* ==========================================
       RESPUESTA
    ========================================== */

    return [

        'nombre' => $nombre,

        'fechaNacimiento' => $fechaNacimiento,

        'edadManual' => $edadManual,

        'fechaActualizacionEdad' => $fechaActualizacionEdad,

        'telefono' => $telefono,

        'genero' => $genero,

        'estadoEspiritual' => $estadoEspiritual,

        'fechaIngreso' => $fechaIngreso,

        'esServidor' => $esServidor,

        'observaciones' => $observaciones

    ];
}

/* ==========================================================
   CREAR JOVEN
========================================================== */

function crearJoven(
    PDO $pdo,
    array $datos
): int
{
    exigirPermiso('gestionar_jovenes');

    $datos = prepararDatosJoven(
        $pdo,
        $datos
    );

    $stmt = $pdo->prepare("
        INSERT INTO jovenes (

            nombre_completo,
            fecha_nacimiento,
            edad_manual,
            fecha_actualizacion_edad,
            telefono,
            genero,
            estado_espiritual,
            estado_actividad,
            fecha_ingreso,
            es_servidor,
            observaciones

        ) VALUES (

            :nombre,
            :fechaNacimiento,
            :edadManual,
            :fechaActualizacionEdad,
            :telefono,
            :genero,
            :estadoEspiritual,
            'ACTIVO',
            :fechaIngreso,
            :esServidor,
            :observaciones

        )
    ");

    $stmt->execute([

        ':nombre' => $datos['nombre'],
        ':fechaNacimiento' => $datos['fechaNacimiento'],
        ':edadManual' => $datos['edadManual'],
        ':fechaActualizacionEdad' => $datos['fechaActualizacionEdad'],
        ':telefono' => $datos['telefono'],
        ':genero' => $datos['genero'],
        ':estadoEspiritual' => $datos['estadoEspiritual'],
        ':fechaIngreso' => $datos['fechaIngreso'],
        ':esServidor' => $datos['esServidor'],
        ':observaciones' => $datos['observaciones']

    ]);

    return (int)$pdo->lastInsertId();
}

/* ==========================================================
   EDITAR JOVEN
========================================================== */

function editarJoven(
    PDO $pdo,
    int $id,
    array $datos
): void
{
    exigirPermiso('gestionar_jovenes');

    validarJoven(
        $pdo,
        $id
    );

    $datos = prepararDatosJoven(
        $pdo,
        $datos,
        $id
    );

    $stmt = $pdo->prepare("
        UPDATE jovenes
        SET

            nombre_completo = :nombre,
            fecha_nacimiento = :fechaNacimiento,
            edad_manual = :edadManual,
            fecha_actualizacion_edad = :fechaActualizacionEdad,
            telefono = :telefono,
            genero = :genero,
            estado_espiritual = :estadoEspiritual,
            fecha_ingreso = :fechaIngreso,
            es_servidor = :esServidor,
            observaciones = :observaciones

        WHERE id = :id
    ");

    $stmt->execute([

        ':nombre' => $datos['nombre'],
        ':fechaNacimiento' => $datos['fechaNacimiento'],
        ':edadManual' => $datos['edadManual'],
        ':fechaActualizacionEdad' => $datos['fechaActualizacionEdad'],
        ':telefono' => $datos['telefono'],
        ':genero' => $datos['genero'],
        ':estadoEspiritual' => $datos['estadoEspiritual'],
        ':fechaIngreso' => $datos['fechaIngreso'],
        ':esServidor' => $datos['esServidor'],
        ':observaciones' => $datos['observaciones'],
        ':id' => $id

    ]);
}

/* ==========================================================
   CAMBIAR ESTADO
========================================================== */

function cambiarEstadoJoven(
    PDO $pdo,
    int $id,
    string $estado
): void
{
    exigirPermiso('gestionar_jovenes');

    validarJoven(
        $pdo,
        $id
    );

    if (!in_array(
        $estado,
        ESTADOS_ACTIVIDAD,
        true
    )) {

        throw new Exception(
            'Estado inválido.'
        );

    }

    $stmt = $pdo->prepare("
        UPDATE jovenes
        SET estado_actividad = :estado
        WHERE id = :id
    ");

    $stmt->execute([

        ':estado' => $estado,
        ':id' => $id

    ]);
}

/* ==========================================================
   ELIMINAR
========================================================== */

function eliminarJoven(
    PDO $pdo,
    int $id
): void
{
    cambiarEstadoJoven(
        $pdo,
        $id,
        'ELIMINADO'
    );
}

/* ==========================================================
   RECUPERAR
========================================================== */

function recuperarJoven(
    PDO $pdo,
    int $id
): void
{
    cambiarEstadoJoven(
        $pdo,
        $id,
        'ACTIVO'
    );
}

/* ==========================================================
   ELIMINAR DEFINITIVAMENTE
========================================================== */

function eliminarDefinitivo(
    PDO $pdo,
    int $id
): void
{
    exigirPermiso(
        'eliminar_jovenes'
    );

    validarJoven(
        $pdo,
        $id
    );

    $stmt = $pdo->prepare("
        DELETE
        FROM jovenes
        WHERE id = :id
    ");

    $stmt->execute([

        ':id' => $id

    ]);
}

