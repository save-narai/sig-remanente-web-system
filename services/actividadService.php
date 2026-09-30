<?php

declare(strict_types=1);



/* ======================================================
   CONSTANTES
====================================================== */

const ESTADOS_NUEVOS = [
    'NUEVO',
    'CONSOLIDACION'
];

const ESTADOS_MADUROS = [
    'MADURO',
    'LIDER'
];


/* ======================================================
   FASE 1/2 -- MODELO DE ACTIVIDAD/INACTIVIDAD JUVENIL
   BASADO EN REUNIONES (reemplaza al criterio de 60 dias
   como criterio PRINCIPAL de estado_actividad).

   TIPOS_REUNION_JUVENIL contiene los valores REALES que
   reunionService.php::crearReunion() persiste en
   reuniones.tipo (no las constantes internas del
   formulario, como 'REUNION_JOVENES'/'GRUPO_CONEXION').
   Se centraliza aqui para no repetir el bug ya detectado
   en el antiguo faltasConsecutivasConexion() (comparar una
   constante interna contra el texto realmente guardado;
   esa función quedó retirada al migrar al modelo único
   4/12, ver etiquetaVisualActividadJuvenil()).

   Discipulado, Evento Especial y Otro NO cuentan para el
   criterio general de actividad juvenil.
====================================================== */

const TIPOS_REUNION_JUVENIL = [
    'Reunión Jóvenes',
    'Grupo Conexión',
];

const TIPO_REUNION_GRUPO_CONEXION = 'Grupo Conexión';

const UMBRAL_ALERTA_JUVENIL = 4;
const UMBRAL_INACTIVO_JUVENIL = 12;

/* ======================================================
   ES SERVIDOR DEL MINISTERIO DE JOVENES (Fase 6, ajustada
   en la ronda de mejoras posterior a la reunion)

   Fuente de verdad: modulo de Usuarios, via jovenes.usuario_id.
   Un joven es servidor del Ministerio de Jovenes cuando tiene
   una cuenta de usuario ACTIVA vinculada -- sin excepciones por
   rol. Antes se excluia al rol ADMIN por considerarlo un
   administrador tecnico, pero en este ministerio el
   administrador ES la secretaria (un cargo real de servicio),
   asi que ese caso especial se retira: todo usuario registrado
   (administradora/secretaria, lider, sublider, usuarios
   independientes) es servidor del ministerio.
====================================================== */

function esServidorMinisterioJovenes(PDO $pdo, array $joven): bool
{
    $usuarioId = $joven['usuario_id'] ?? null;

    if ($usuarioId === null) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)

        FROM usuarios

        WHERE id = :usuario_id
        AND activo = 1
    ");

    $stmt->execute([
        'usuario_id' => (int) $usuarioId,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}


/* ======================================================
   ES SERVIDOR (excepcion de actividad -- Fase 1/2, corregida
   en Fase 6)

   Dos fuentes independientes, combinadas con OR:
   - es_servidor: servidor de CUALQUIER ministerio (columna ya
     existente de jovenes, sin relacion con este cambio).
   - esServidorMinisterioJovenes(): servidor especificamente del
     Ministerio de Jovenes, ahora determinado desde Usuarios
     (Fase 6) en vez del antiguo estado_espiritual SERVIDOR/LIDER.
====================================================== */

function esServidorOLiderJoven(PDO $pdo, array $joven): bool
{
    $esServidorOtroMinisterio = (int) ($joven['es_servidor'] ?? 0) === 1;

    return $esServidorOtroMinisterio
        || esServidorMinisterioJovenes($pdo, $joven);
}


/* ======================================================
   AUSENCIAS CONSECUTIVAS DEMOSTRABLES EN REUNIONES DE
   JOVENES (FASE 1/2, corregida en FASE 3 segun auditorias)

   Reglas aplicadas:

   - Solo cuentan las reuniones de TIPOS_REUNION_JUVENIL
     ('Reunión Jóvenes' y 'Grupo Conexión'); Discipulado,
     Evento Especial y Otro quedan fuera.
   - Se usa reuniones.fecha para ordenar y para filtrar por
     fecha_ingreso. NUNCA asistencia.fecha_registro ni
     asistencia.created_at (son metadatos de cuando se
     capturo/guardo el dato, no la fecha real del evento).
   - No se cuentan reuniones anteriores a fecha_ingreso del
     joven: no se penaliza a nadie por reuniones ocurridas
     antes de su ingreso.
   - FASE 3 (segun auditoria de impacto y auditoria 2):
     SOLO una fila con asistio=0 explicito cuenta como
     ausencia demostrable. Una reunion SIN fila de asistencia
     para el joven es DESCONOCIDA, nunca se convierte en
     ausencia (queda prohibido usar COALESCE(asistio, 0) para
     este calculo). Un desconocido ROMPE la racha para
     efectos de clasificacion: se detiene el conteo ahi mismo
     (no se salta el hueco para seguir sumando ausencias mas
     antiguas, porque entonces dejarian de ser "consecutivas"
     en sentido probatorio).
   - Una asistencia explicita (asistio=1) tambien detiene el
     conteo (la racha demostrable termina en lo acumulado
     hasta ahi; reinicia en 0 si la reunion mas reciente ya
     es una asistencia).
   - No se inventa una antiguedad minima adicional: si un
     joven recien ingresado todavia no tiene reuniones
     evaluables, esta funcion devuelve 0 ausencias de forma
     natural (no se fabrica una racha que no existe).

   Devuelve un arreglo, no un entero:
     'ausencias_consecutivas_demostrables' => int
     'hay_desconocido' => bool (si el conteo se detuvo en una
        reunion sin fila, en vez de en una asistencia o al
        agotar el historico evaluable)
====================================================== */

function ausenciasConsecutivasJuveniles(
    PDO $pdo,
    int $joven_id,
    ?string $fechaIngreso = null
): array {

    if ($fechaIngreso === null) {

        $stmtJoven = $pdo->prepare("
            SELECT fecha_ingreso
            FROM jovenes
            WHERE id = :id
        ");

        $stmtJoven->execute([
            'id' => $joven_id
        ]);

        $fechaIngreso = $stmtJoven->fetchColumn() ?: null;
    }

    $placeholders = [];
    $params = [
        'joven_id' => $joven_id,
    ];

    foreach (TIPOS_REUNION_JUVENIL as $i => $tipo) {
        $key = "tipo{$i}";
        $placeholders[] = ":{$key}";
        $params[$key] = $tipo;
    }

    $condicionFecha = '';

    if ($fechaIngreso !== null) {
        $condicionFecha = 'AND r.fecha >= :fecha_ingreso';
        $params['fecha_ingreso'] = $fechaIngreso;
    }

    /*
       Sin COALESCE ni MAX/GROUP BY: la clave unica
       (reunion_id, joven_id) de "asistencia" garantiza como
       maximo una fila por par, asi que un LEFT JOIN simple
       ya entrega NULL cuando no existe fila -- exactamente
       la senal de "desconocido" que necesitamos preservar.
    */

    $sql = "
        SELECT
            r.id,
            r.fecha,
            a.asistio

        FROM reuniones r

        LEFT JOIN asistencia a
            ON a.reunion_id = r.id
            AND a.joven_id = :joven_id

        WHERE r.tipo IN (" . implode(', ', $placeholders) . ")
        {$condicionFecha}

        ORDER BY r.fecha DESC, r.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $reuniones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ausencias = 0;
    $hayDesconocido = false;

    foreach ($reuniones as $reunion) {

        if ($reunion['asistio'] === null) {

            /*
             * DESCONOCIDO: no existe fila de asistencia para
             * este joven en esta reunion. No se puede
             * demostrar presencia ni ausencia. Rompe la
             * racha demostrable aqui mismo.
             */

            $hayDesconocido = true;

            break;
        }

        if ((int) $reunion['asistio'] === 1) {

            /*
             * Asistencia explicita: la racha demostrable
             * termina aqui (reinicia en lo ya acumulado).
             */

            break;
        }

        /* asistio === 0 explicito: ausencia demostrable */

        $ausencias++;
    }

    return [
        'ausencias_consecutivas_demostrables' => $ausencias,
        'hay_desconocido' => $hayDesconocido,
    ];
}


/* ======================================================
   ESTADO DE ACTIVIDAD JUVENIL DERIVADO (FASE 3)

   Envoltorio de conveniencia sobre
   ausenciasConsecutivasJuveniles() + clasificarActividadJuvenil().
   No agrega ninguna columna ni tabla nueva: todo es
   derivado en el momento de la consulta. Pensado para que
   una futura vista/dashboard pueda mostrar "ALERTA" sin
   necesitar persistirlo (el ENUM de jovenes.estado_actividad
   sigue siendo solo ACTIVO/INACTIVO/ELIMINADO).
====================================================== */

function obtenerEstadoActividadJuvenil(
    PDO $pdo,
    int $joven_id,
    ?string $fechaIngreso = null
): array {

    $resultado = ausenciasConsecutivasJuveniles(
        $pdo,
        $joven_id,
        $fechaIngreso
    );

    $resultado['clasificacion_actividad'] = clasificarActividadJuvenil(
        $resultado['ausencias_consecutivas_demostrables']
    );

    return $resultado;
}


/* ======================================================
   CLASIFICAR ACTIVIDAD JUVENIL (FASE 1/2)

   0-3 ausencias consecutivas  -> ACTIVO
   4-11 ausencias consecutivas -> ALERTA (desliz, informativo,
                                   NO se persiste en
                                   jovenes.estado_actividad
                                   porque ese enum solo admite
                                   ACTIVO/INACTIVO/ELIMINADO)
   12+ ausencias consecutivas  -> INACTIVO
====================================================== */

function clasificarActividadJuvenil(int $ausenciasConsecutivas): string
{
    if ($ausenciasConsecutivas >= UMBRAL_INACTIVO_JUVENIL) {
        return 'INACTIVO';
    }

    if ($ausenciasConsecutivas >= UMBRAL_ALERTA_JUVENIL) {
        return 'ALERTA';
    }

    return 'ACTIVO';
}


/* ======================================================
   ACTUALIZAR ESTADOS AUTOMATICOS (FASE 1/2)

   Reemplaza el criterio binario de 60 dias
   (DATEDIFF(NOW(), ultima_actividad)) como criterio
   PRINCIPAL de actividad/inactividad juvenil, por el
   modelo de 4/12 reuniones consecutivas sin asistencia
   (ver ausenciasConsecutivasJuveniles()).

   - jovenes.estado_actividad sigue siendo la unica fuente
     persistida (ACTIVO/INACTIVO/ELIMINADO); no se agrega
     ninguna columna nueva. El estado intermedio "ALERTA"
     es derivado y NO se guarda aqui (el enum de la BD no
     lo admite); esta disponible via
     clasificarActividadJuvenil() para quien lo necesite
     mostrar (dashboard, listados, etc.) sin escribirlo en
     la BD.
   - ELIMINADO nunca se toca (protegido, igual que antes).
   - Servidores/lideres (esServidorOLiderJoven()) quedan
     excluidos de que esta funcion los marque INACTIVO
     automaticamente por la regla juvenil: no reciben el
     mismo criterio estricto. No se inventa un umbral
     alterno para ellos; simplemente no se les aplica este.
   - Se actualiza fila por fila (no con un UPDATE masivo por
     DATEDIFF) porque el calculo de racha por reuniones no es
     expresable en una sola sentencia SQL sencilla; el mismo
     patron de recorrer jovenes en PHP ya se usa en
     resumenActividadJuvenil().
====================================================== */

function actualizarEstadoActividad(PDO $pdo): void
{
    $stmt = $pdo->query("
        SELECT
            id,
            fecha_ingreso,
            es_servidor,
            usuario_id,
            estado_actividad

        FROM jovenes

        WHERE estado_actividad != 'ELIMINADO'
    ");

    $jovenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $update = $pdo->prepare("
        UPDATE jovenes
        SET estado_actividad = :estado
        WHERE id = :id
        AND estado_actividad != 'ELIMINADO'
    ");

    foreach ($jovenes as $joven) {

        $jovenId = (int) $joven['id'];

        $resultado = ausenciasConsecutivasJuveniles(
            $pdo,
            $jovenId,
            $joven['fecha_ingreso'] ?? null
        );

        /*
         * FASE 3: solo se cuentan ausencias demostrables
         * (asistio=0 explicito). Si el conteo se detuvo en
         * un desconocido (fila inexistente), lo acumulado
         * hasta ese punto ya refleja el limite de lo que se
         * puede probar -- por diseño nunca llega a 12 si el
         * desconocido aparecio antes, asi que no hace falta
         * ninguna comprobacion adicional aqui para cumplir
         * "ante falta de evidencia, no se acusa inactividad".
         */

        $clasificacion = clasificarActividadJuvenil(
            $resultado['ausencias_consecutivas_demostrables']
        );

        /*
         * Servidores/lideres: no se les aplica
         * automaticamente el INACTIVO juvenil. Se dejan
         * como ACTIVO (no se inventa un umbral alterno).
         */

        if ($clasificacion === 'INACTIVO' && esServidorOLiderJoven($pdo, $joven)) {
            $clasificacion = 'ACTIVO';
        }

        $nuevoEstado = $clasificacion === 'INACTIVO'
            ? 'INACTIVO'
            : 'ACTIVO';

        if ($nuevoEstado === $joven['estado_actividad']) {
            continue;
        }

        $update->execute([
            'estado' => $nuevoEstado,
            'id'     => $jovenId,
        ]);
    }
}


/* ======================================================
   ETIQUETA VISUAL DE ACTIVIDAD JUVENIL (para badges de
   listado, perfil individual y PDF)

   UNICA fuente de verdad para mostrar el estado de un
   joven en pantalla. Sustituye por completo al modelo
   antiguo (estadoConexionJoven() / resumenConexionMinisterial()
   / faltasConsecutivasConexion()), que usaba solo reuniones
   de 'Grupo Conexion' (ultimas 5) y umbrales 3/4 propios,
   distintos del modelo 4/12 confirmado. Ese modelo antiguo
   podia mostrar "Conectado" en pantalla mientras
   jovenes.estado_actividad ya marcaba INACTIVO -- ya no
   puede ocurrir porque ahora ambos salen de la misma fuente
   (clasificarActividadJuvenil() sobre
   ausenciasConsecutivasJuveniles(), igual que
   actualizarEstadoActividad()).

   Servidores/lideres: misma excepcion que
   actualizarEstadoActividad() -- si la clasificacion cruda
   daria INACTIVO, se muestra ACTIVO (no se inventa un
   umbral alterno). ALERTA si se muestra igual para todos
   (el prompt maestro solo exime del criterio ESTRICTO de
   inactividad, no pide ocultar la alerta informativa).
====================================================== */

function etiquetaVisualActividadJuvenil(
    PDO $pdo,
    int $joven_id
): array {

    $stmt = $pdo->prepare("
        SELECT
            fecha_ingreso,
            es_servidor,
            usuario_id

        FROM jovenes

        WHERE id = :id
    ");

    $stmt->execute([
        'id' => $joven_id
    ]);

    $joven = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $resultado = ausenciasConsecutivasJuveniles(
        $pdo,
        $joven_id,
        $joven['fecha_ingreso'] ?? null
    );

    $clasificacion = clasificarActividadJuvenil(
        $resultado['ausencias_consecutivas_demostrables']
    );

    if (
        $clasificacion === 'INACTIVO'
        && esServidorOLiderJoven($pdo, $joven)
    ) {
        $clasificacion = 'ACTIVO';
    }

    return match ($clasificacion) {

        'INACTIVO' => [
            'estado' => 'Inactivo',
            'clasificacion' => 'INACTIVO',
            'color' => 'danger',
            'icono' => '🔴',
        ],

        'ALERTA' => [
            'estado' => 'Alerta',
            'clasificacion' => 'ALERTA',
            'color' => 'warning',
            'icono' => '🟡',
        ],

        default => [
            'estado' => 'Activo',
            'clasificacion' => 'ACTIVO',
            'color' => 'default',
            'icono' => '🟢',
        ],
    };
}


/* ======================================================
   RESUMEN GLOBAL DE ACTIVIDAD JUVENIL (para el Dashboard)

   Reemplaza a resumenConexionMinisterial(). Cuenta
   ACTIVO/ALERTA/INACTIVO -- las 3 unicas categorias que
   define el modelo confirmado (4/12 ausencias). No se
   inventan categorias de negocio adicionales ("Conectado",
   "Observacion", "Riesgo", "Alto Riesgo" quedan retiradas).
====================================================== */

function resumenActividadJuvenil(
    PDO $pdo
): array {

    $stmt = $pdo->query("
        SELECT id

        FROM jovenes

        WHERE estado_actividad != 'ELIMINADO'
    ");

    $jovenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $activos = 0;
    $alerta = 0;
    $inactivos = 0;

    foreach ($jovenes as $joven) {

        $etiqueta = etiquetaVisualActividadJuvenil(
            $pdo,
            (int) $joven['id']
        );

        switch ($etiqueta['clasificacion']) {

            case 'INACTIVO':
                $inactivos++;
            break;

            case 'ALERTA':
                $alerta++;
            break;

            default:
                $activos++;
            break;
        }
    }

    return [
        'activos' => $activos,
        'alerta' => $alerta,
        'inactivos' => $inactivos,
    ];
}
