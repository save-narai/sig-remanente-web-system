<?php

declare(strict_types=1);

/* ==========================================================
   CUMPLEAÑOS
   ----------------------------------------------------------
   - Cálculo de cumpleaños (hoy / próximos días) desde
     jovenes.fecha_nacimiento -- ÚNICA fuente de verdad para
     el correo y para el panel del dashboard.
   - Alerta diaria por correo a los usuarios marcados con
     usuarios.recibe_alertas_cumpleanos = 1 (acordado en la
     reunión: solo dos personas; sus correos ya viven en
     usuarios.correo, no se duplican en ningún archivo).

   Compatible con InfinityFree: NO usa cron ni procesos en
   segundo plano. La alerta se dispara la primera vez que
   alguien abre el dashboard cada día (una tabla de control
   garantiza un solo envío diario).

   Defensivo a propósito: si la migración todavía no se
   ejecutó, o el correo falla, NUNCA debe romper el dashboard.
========================================================== */

/* ==========================================================
   ¿ESTÁ APLICADA LA MIGRACIÓN?
========================================================== */

function cumpleanosDisponible(PDO $pdo): bool
{
    static $disponible = null;

    if ($disponible !== null) {
        return $disponible;
    }

    try {

        $stmt = $pdo->query("
            SELECT COUNT(*)

            FROM information_schema.COLUMNS

            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'usuarios'
            AND COLUMN_NAME = 'recibe_alertas_cumpleanos'
        ");

        $columna = (int) $stmt->fetchColumn() > 0;

        $stmt = $pdo->query("
            SELECT COUNT(*)

            FROM information_schema.TABLES

            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'cumpleanos_alertas_enviadas'
        ");

        $tabla = (int) $stmt->fetchColumn() > 0;

        $disponible = $columna && $tabla;

    } catch (Throwable $e) {

        $disponible = false;
    }

    return $disponible;
}

/* ==========================================================
   PRÓXIMOS CUMPLEAÑOS (incluye hoy)

   Devuelve, ordenados por cercanía, los jóvenes (no eliminados,
   con fecha_nacimiento) que cumplen años en los próximos $dias
   días. dias_faltan = 0 significa que es hoy.

   29 de febrero: en años no bisiestos se celebra el 28.
========================================================== */

function obtenerProximosCumpleanos(
    PDO $pdo,
    int $dias = 7,
    ?DateTimeImmutable $hoy = null
): array {

    $hoy = ($hoy ?? new DateTimeImmutable('today'))->setTime(0, 0);

    $stmt = $pdo->query("
        SELECT id, nombre_completo, fecha_nacimiento

        FROM jovenes

        WHERE estado_actividad != 'ELIMINADO'
        AND fecha_nacimiento IS NOT NULL
    ");

    $resultado = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $joven) {

        try {
            $nacimiento = new DateTimeImmutable($joven['fecha_nacimiento']);
        } catch (Throwable $e) {
            continue;
        }

        $proximo = proximaFechaCumpleanos($nacimiento, $hoy);

        $faltan = (int) $hoy->diff($proximo)->format('%a');

        if ($faltan > $dias) {
            continue;
        }

        $resultado[] = [
            'id' => (int) $joven['id'],
            'nombre_completo' => $joven['nombre_completo'],
            'fecha_cumpleanos' => $proximo->format('Y-m-d'),
            'cumple' => (int) $proximo->format('Y') - (int) $nacimiento->format('Y'),
            'dias_faltan' => $faltan,
        ];
    }

    usort($resultado, static function (array $a, array $b): int {
        return [$a['dias_faltan'], $a['nombre_completo']]
            <=> [$b['dias_faltan'], $b['nombre_completo']];
    });

    return $resultado;
}

function proximaFechaCumpleanos(
    DateTimeImmutable $nacimiento,
    DateTimeImmutable $hoy
): DateTimeImmutable {

    $mes = (int) $nacimiento->format('n');
    $dia = (int) $nacimiento->format('j');

    foreach ([(int) $hoy->format('Y'), (int) $hoy->format('Y') + 1] as $anio) {

        $diaReal = $dia;

        if ($mes === 2 && $dia === 29 && !checkdate(2, 29, $anio)) {
            $diaReal = 28;
        }

        $candidato = (new DateTimeImmutable('today'))
            ->setDate($anio, $mes, $diaReal)
            ->setTime(0, 0);

        if ($candidato >= $hoy) {
            return $candidato;
        }
    }

    return $hoy;
}

function obtenerCumpleanosDeHoy(PDO $pdo): array
{
    return array_values(array_filter(
        obtenerProximosCumpleanos($pdo, 0),
        static fn(array $c): bool => $c['dias_faltan'] === 0
    ));
}

/* ==========================================================
   DESTINATARIOS
========================================================== */

function obtenerDestinatariosCumpleanos(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, nombre, correo

        FROM usuarios

        WHERE activo = 1
        AND recibe_alertas_cumpleanos = 1
        AND correo IS NOT NULL
        AND correo != ''
    ");

    return array_values(array_filter(
        $stmt->fetchAll(PDO::FETCH_ASSOC),
        static fn(array $u): bool => filter_var($u['correo'], FILTER_VALIDATE_EMAIL) !== false
    ));
}

function usuarioRecibeAlertasCumpleanos(PDO $pdo, int $usuarioId): bool
{
    if (!cumpleanosDisponible($pdo)) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT recibe_alertas_cumpleanos FROM usuarios WHERE id = :id
    ");

    $stmt->execute(['id' => $usuarioId]);

    return (int) $stmt->fetchColumn() === 1;
}

/* ==========================================================
   GUARDAR LA PREFERENCIA (desde Usuarios crear/editar)

   Solo actúa si el formulario envió la clave (los formularios
   nuevos siempre la envían) y si la migración ya está aplicada.
========================================================== */

function guardarPreferenciaAlertaCumpleanos(
    PDO $pdo,
    int $usuarioId,
    array $datos
): void {

    if (!array_key_exists('recibe_alertas_cumpleanos', $datos)) {
        return;
    }

    if (!cumpleanosDisponible($pdo)) {
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE usuarios

        SET recibe_alertas_cumpleanos = :valor

        WHERE id = :id
    ");

    $stmt->execute([
        'valor' => !empty($datos['recibe_alertas_cumpleanos']) ? 1 : 0,
        'id' => $usuarioId,
    ]);
}

/* ==========================================================
   ALERTA DIARIA POR CORREO

   Estados devueltos (informativos, no lanza excepciones):
   - migracion_pendiente / correo_no_disponible
   - sin_cumpleaneros / sin_destinatarios
   - ya_procesado
   - enviado / error

   Control anti-duplicado: la fila de hoy en
   cumpleanos_alertas_enviadas se "reclama" con INSERT IGNORE
   (dos visitas simultáneas no pueden enviar dos veces). Si el
   envío falla, se permite reintentar pasada 1 hora -- así un
   SMTP caído no se reintenta en cada carga del dashboard.
========================================================== */

function procesarAlertaCumpleanosDelDia(PDO $pdo): array
{
    if (!cumpleanosDisponible($pdo)) {
        return ['estado' => 'migracion_pendiente'];
    }

    $cumpleaneros = obtenerCumpleanosDeHoy($pdo);

    if (empty($cumpleaneros)) {
        return ['estado' => 'sin_cumpleaneros'];
    }

    $destinatarios = obtenerDestinatariosCumpleanos($pdo);

    if (empty($destinatarios)) {
        return ['estado' => 'sin_destinatarios'];
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!is_file($autoload)) {
        return ['estado' => 'correo_no_disponible'];
    }

    /* ---- reclamar el día ---- */

    $claim = $pdo->prepare("
        INSERT IGNORE INTO cumpleanos_alertas_enviadas
            (fecha, estado, destinatarios, cumpleaneros)
        VALUES
            (CURDATE(), 'PROCESANDO', :dest, :cumple)
    ");

    $claim->execute([
        'dest' => count($destinatarios),
        'cumple' => count($cumpleaneros),
    ]);

    if ($claim->rowCount() === 0) {

        $reintento = $pdo->prepare("
            UPDATE cumpleanos_alertas_enviadas

            SET estado = 'PROCESANDO',
                destinatarios = :dest,
                cumpleaneros = :cumple,
                actualizado_en = NOW()

            WHERE fecha = CURDATE()
            AND estado IN ('ERROR', 'PROCESANDO')
            AND actualizado_en < (NOW() - INTERVAL 60 MINUTE)
        ");

        $reintento->execute([
            'dest' => count($destinatarios),
            'cumple' => count($cumpleaneros),
        ]);

        if ($reintento->rowCount() === 0) {
            return ['estado' => 'ya_procesado'];
        }
    }

    /* ---- enviar ---- */

    require_once __DIR__ . '/mailService.php';

    $asunto = 'Cumpleaños de hoy - ' . date('d/m/Y');

    $enviados = 0;

    foreach ($destinatarios as $destinatario) {

        try {

            enviarCorreo(
                $destinatario['correo'],
                $destinatario['nombre'],
                $asunto,
                construirMensajeCumpleanos($destinatario['nombre'], $cumpleaneros)
            );

            $enviados++;

        } catch (Throwable $e) {

            error_log('Alerta de cumpleaños: fallo al enviar a un destinatario.');
        }
    }

    $estado = $enviados > 0 ? 'ENVIADO' : 'ERROR';

    $pdo->prepare("
        UPDATE cumpleanos_alertas_enviadas

        SET estado = :estado, actualizado_en = NOW()

        WHERE fecha = CURDATE()
    ")->execute(['estado' => $estado]);

    return [
        'estado' => $enviados > 0 ? 'enviado' : 'error',
        'enviados' => $enviados,
    ];
}

function construirMensajeCumpleanos(string $nombreDestinatario, array $cumpleaneros): string
{
    $filas = '';

    foreach ($cumpleaneros as $c) {

        $filas .= '<li><strong>'
            . htmlspecialchars($c['nombre_completo'], ENT_QUOTES, 'UTF-8')
            . '</strong> cumple '
            . (int) $c['cumple']
            . ' años</li>';
    }

    return '<div style="font-family:Arial,sans-serif;font-size:15px;color:#222">'
        . '<p>Hola ' . htmlspecialchars($nombreDestinatario, ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p>Hoy están de cumpleaños:</p>'
        . '<ul>' . $filas . '</ul>'
        . '<p>Un saludo desde SIG Remanente.</p>'
        . '</div>';
}
