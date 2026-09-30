<?php

declare(strict_types=1);

/* =====================================================
   DEPENDENCIAS
===================================================== */

require_once __DIR__ . "/../../vendor/autoload.php";

require_once __DIR__ . "/../../middleware/auth.php";
require_once __DIR__ . "/../../middleware/permiso.php";
require_once __DIR__ . "/../../config/conexion.php";
require_once __DIR__ . "/../../services/actividadService.php";
require_once __DIR__ . "/../../services/jovenService.php";
require_once __DIR__ . "/../../helpers/format.php";
require_once __DIR__ . "/../../helpers/fechas.php";

use Dompdf\Dompdf;
use Dompdf\Options;

/* =====================================================
   PERMISOS
===================================================== */

if (!tienePermiso("gestionar_jovenes")) {

    exit("Acceso denegado.");

}

/* =====================================================
   ACTIVIDAD
===================================================== */

actualizarEstadoActividad($pdo);
actualizarEstadoCongregacional($pdo);

/* =====================================================
   ID
===================================================== */

$id = (int) ($_GET["id"] ?? 0);

if ($id <= 0) {

    exit("Joven inválido.");

}

/* =====================================================
   JOVEN
===================================================== */

$stmt = $pdo->prepare("
    SELECT
        id,
        nombre_completo,
        telefono,
        genero,
        fecha_nacimiento,
        edad_manual,
        fecha_actualizacion_edad,
        estado_espiritual,
        estado_actividad,
        observaciones,
        fecha_ingreso
    FROM jovenes
    WHERE id = :id
    LIMIT 1
");

$stmt->execute([
    ":id" => $id
]);

$joven = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$joven) {

    exit("No existe el joven.");

}

/* =====================================================
   EDAD
===================================================== */

$edad = "—";
$edadAprox = false;

if (!empty($joven["fecha_nacimiento"])) {

    $edad = (
        new DateTime($joven["fecha_nacimiento"])
    )->diff(new DateTime())->y;

} elseif (!empty($joven["edad_manual"])) {

    $edad = (int)$joven["edad_manual"];

    if (!empty($joven["fecha_actualizacion_edad"])) {

        $edad += (
            new DateTime($joven["fecha_actualizacion_edad"])
        )->diff(new DateTime())->y;

    }

    $edadAprox = true;

}

/* =====================================================
   CONEXIÓN
===================================================== */

$con = etiquetaVisualActividadJuvenil($pdo, $id);

$estadoConexion = $con["estado"];

$colorConexion = match ($con["color"]) {

    "danger" => "#dc2626",

    "warning" => "#d97706",

    default => "#16a34a"

};

/* =====================================================
   ASISTENCIA
===================================================== */

$stmt = $pdo->prepare("
    SELECT

        SUM(asistio = 1) presentes,

        SUM(asistio = 0) ausentes

    FROM asistencia

    WHERE joven_id = :id
");

$stmt->execute([
    ":id" => $id
]);

$asis = $stmt->fetch(PDO::FETCH_ASSOC);

$presentes = (int)($asis["presentes"] ?? 0);

$ausentes = (int)($asis["ausentes"] ?? 0);

$total = $presentes + $ausentes;

$porcentaje = $total > 0

    ? round(($presentes / $total) * 100)

    : 0;

/* =====================================================
   SEGUIMIENTOS
===================================================== */

$stmt = $pdo->prepare("
    SELECT

        s.fecha_contacto,

        s.modalidad_contacto,

        s.estado_proceso,

        s.observaciones,

        u.nombre responsable

    FROM seguimientos s

    LEFT JOIN usuarios u

        ON u.id = s.responsable_id

    WHERE s.joven_id = :id

    ORDER BY s.fecha_contacto DESC
");

$stmt->execute([
    ":id" => $id
]);

$seguimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalSeguimientos = count($seguimientos);

/* =====================================================
   HISTORIAL
===================================================== */

$stmt = $pdo->prepare("
    SELECT

        r.tipo,

        r.fecha,

        a.asistio

    FROM asistencia a

    INNER JOIN reuniones r

        ON r.id = a.reunion_id

    WHERE a.joven_id = :id

    ORDER BY r.fecha DESC
");

$stmt->execute([
    ":id" => $id
]);

$historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   EVALUACIÓN AUTOMÁTICA
===================================================== */

$evaluacion = "";

if ($porcentaje >= 90) {

    $evaluacion =
        "Excelente nivel de participación. El joven mantiene una asistencia constante y no presenta indicadores de riesgo.";

} elseif ($porcentaje >= 70) {

    $evaluacion =
        "Buen nivel de participación. Se recomienda continuar el acompañamiento para mantener la constancia.";

} elseif ($porcentaje >= 50) {

    $evaluacion =
        "El nivel de asistencia es medio. Es recomendable fortalecer el seguimiento pastoral para evitar una disminución en la participación.";

} else {

    $evaluacion =
        "Se detecta un bajo nivel de participación. Es recomendable realizar un acompañamiento cercano y fortalecer el proceso de conexión.";

}

/* =====================================================
   DOMPDF
===================================================== */

$options = new Options();

$options->set("isRemoteEnabled", true);
$options->set("isHtml5ParserEnabled", true);
$options->setDefaultFont("DejaVu Sans");

$dompdf = new Dompdf($options);                                                                                       /* =====================================================
   HTML
===================================================== */

$html = <<<HTML

<!DOCTYPE html>

<html lang="es">

<head>

<meta charset="UTF-8">

<style>

*{
    box-sizing:border-box;
}

body{

    font-family:DejaVu Sans,sans-serif;

    color:#374151;

    font-size:12px;

    line-height:1.55;

}

h1,h2,h3,h4{

    margin:0;

}

.header{

    border-bottom:3px solid #2563eb;

    padding-bottom:14px;

    margin-bottom:28px;

}

.title{

    font-size:28px;

    font-weight:bold;

    color:#1e3a8a;

}

.subtitle{

    margin-top:4px;

    color:#6b7280;

    font-size:13px;

}

.generated{

    margin-top:8px;

    font-size:11px;

    color:#9ca3af;

}

.card{

    border:1px solid #e5e7eb;

    border-radius:10px;

    padding:18px;

    margin-bottom:22px;

}

.section-title{

    font-size:17px;

    color:#1e3a8a;

    margin-bottom:14px;

    border-left:4px solid #2563eb;

    padding-left:10px;

}

.info-table{

    width:100%;

    border-collapse:collapse;

}

.info-table td{

    padding:8px 6px;

    border-bottom:1px solid #f1f5f9;

}

.label{

    width:180px;

    font-weight:bold;

    color:#374151;

}

.status{

    display:inline-block;

    padding:5px 12px;

    border-radius:20px;

    font-size:11px;

    font-weight:bold;

    color:#ffffff;

}

.stats{

    width:100%;

    border-collapse:separate;

    border-spacing:12px;

    margin-top:8px;

}

.stat{

    width:20%;

    background:#f8fafc;

    border:1px solid #e2e8f0;

    border-radius:8px;

    text-align:center;

    padding:16px;

}

.stat-value{

    display:block;

    font-size:24px;

    font-weight:bold;

    color:#2563eb;

}

.stat-label{

    margin-top:6px;

    font-size:11px;

    color:#6b7280;

}

.note{

    background:#f8fafc;

    border-left:5px solid #2563eb;

    padding:14px;

    line-height:1.8;

}

.warning{

    background:#fff8e6;

    border-left:6px solid #d97706;

}

.success{

    background:#ecfdf5;

    border-left:6px solid #16a34a;

}

.danger{

    background:#fef2f2;

    border-left:6px solid #dc2626;

}

.footer{

    position:fixed;

    bottom:-25px;

    left:0;

    right:0;

    text-align:center;

    font-size:10px;

    color:#9ca3af;

}

</style>

</head>

<body>

<div class="footer">

Informe generado automáticamente por GX • Página {PAGE_NUM} de {PAGE_COUNT}

</div>

<div class="header">

<div class="title">

Informe Integral del Joven

</div>

<div class="subtitle">

Ministerio de Jóvenes

</div>

<div class="generated">

Generado el: <?= date("d/m/Y H:i") ?>

</div>

</div>

<div class="card">

<h2 class="section-title">

Información General

</h2>

<table class="info-table">

<tr>

<td class="label">

Nombre completo

</td>

<td>

{$joven["nombre_completo"]}

</td>

</tr>

<tr>

<td class="label">

Estado de conexión

</td>

<td>

<span
class="status"
style="background:{$colorConexion};">

{$estadoConexion}

</span>

</td>

</tr>

<tr>

<td class="label">

Estado de actividad

</td>

<td>

{$joven["estado_actividad"]}

</td>

</tr>

<tr>

<td class="label">

Edad

</td>

<td>

{$edad}

                                                                                                                               {$edad}
HTML;

/* =====================================================
   CONTINUAR HTML
===================================================== */

$fechaIngreso = !empty($joven["fecha_ingreso"])
    ? formatearFecha($joven["fecha_ingreso"])
    : "—";

$telefono = !empty($joven["telefono"])
    ? e($joven["telefono"])
    : "—";

$genero = match ($joven["genero"] ?? "") {

    "M" => "Masculino",

    "F" => "Femenino",

    default => "—"

};

$estadoEspiritual = ucfirst(
    strtolower(
        $joven["estado_espiritual"] ?? "Sin registrar"
    )
);

$observaciones = trim(
    $joven["observaciones"] ?? ""
);

$html .= <<<HTML

<tr>

<td class="label">

Género

</td>

<td>

{$genero}

</td>

</tr>

<tr>

<td class="label">

Teléfono

</td>

<td>

{$telefono}

</td>

</tr>

<tr>

<td class="label">

Estado espiritual

</td>

<td>

{$estadoEspiritual}

</td>

</tr>

<tr>

<td class="label">

Fecha de ingreso

</td>

<td>

{$fechaIngreso}

</td>

</tr>

</table>

</div>

<div class="card">

<h2 class="section-title">

Indicadores generales

</h2>

<table class="stats">

<tr>

<td class="stat">

<span class="stat-value">

{$total}

</span>

<span class="stat-label">

Reuniones

</span>

</td>

<td class="stat">

<span class="stat-value">

{$presentes}

</span>

<span class="stat-label">

Presentes

</span>

</td>

<td class="stat">

<span class="stat-value">

{$ausentes}

</span>

<span class="stat-label">

Ausencias

</span>

</td>

<td class="stat">

<span class="stat-value">

{$porcentaje}%

</span>

<span class="stat-label">

Asistencia

</span>

</td>

<td class="stat">

<span class="stat-value">

{$totalSeguimientos}

</span>

<span class="stat-label">

Seguimientos

</span>

</td>

</tr>

</table>

</div>

<div class="card">

<h2 class="section-title">

Observaciones

</h2>

<div class="note">

HTML;

if (!empty($observaciones)) {

    $html .= nl2br(e($observaciones));

} else {

    $html .= "No existen observaciones registradas.";

}

$html .= <<<HTML

</div>

</div>

HTML;

/* =====================================================
   EVALUACIÓN AUTOMÁTICA
===================================================== */

$claseEvaluacion = "success";

if ($porcentaje < 50) {

    $claseEvaluacion = "danger";

} elseif ($porcentaje < 70) {

    $claseEvaluacion = "warning";

}

$html .= <<<HTML

<div class="card">

<h2 class="section-title">

Evaluación automática

</h2>

<div class="note {$claseEvaluacion}">

{$evaluacion}

</div>

</div>

<div class="card">

<h2 class="section-title">

Últimos seguimientos

</h2>

<table
style="
width:100%;
border-collapse:collapse;
">

<tr
style="
background:#f8fafc;
">

<th align="left">Fecha</th>

<th align="left">Modalidad</th>

<th align="left">Responsable</th>

<th align="left">Estado</th>

</tr>

HTML;

foreach ($seguimientos as $s) {

    $estado = ucfirst(
        strtolower(
            str_replace(
                "_",
                " ",
                $s["estado_proceso"]
            )
        )
    );

    $modalidad = ucfirst(
        strtolower(
            $s["modalidad_contacto"]
        )
    );

    $responsable = e(
        $s["responsable"] ?? "Sin responsable"
    );

    $fecha = formatearFecha(
        $s["fecha_contacto"]
    );

    $html .= "

    <tr>

        <td>{$fecha}</td>

        <td>{$modalidad}</td>

        <td>{$responsable}</td>

        <td>{$estado}</td>

    </tr>";

}                                                                                                                                                     /* =====================================================
   HISTORIAL COMPLETO
===================================================== */

$html .= <<<HTML

</table>

</div>

<div class="card">

<h2 class="section-title">

Historial completo de asistencia

</h2>

<table
style="
width:100%;
border-collapse:collapse;
font-size:11px;
">

<tr
style="
background:#2563eb;
color:#ffffff;
">

<th
style="padding:8px;"
width="8%"
>#</th>

<th
style="padding:8px;"
width="22%"
>Fecha</th>

<th
style="padding:8px;"
width="45%"
>Reunión</th>

<th
style="padding:8px;"
width="25%"
>Estado</th>

</tr>

HTML;

$contador = 1;

foreach ($historial as $h) {

    $tipo = match ($h["tipo"]) {

        "REUNION_JOVENES" => "Reunión",

        "GRUPO_CONEXION" => "Grupo Conexión",

        "DISCIPULADO" => "Discipulado",

        "EVENTO_ESPECIAL" => "Evento Especial",

        default => ucfirst(
            strtolower(
                str_replace(
                    "_",
                    " ",
                    $h["tipo"]
                )
            )
        )

    };

    $estado = ((int)$h["asistio"] === 1)
        ? "Presente"
        : "Ausente";

    $colorEstado = ((int)$h["asistio"] === 1)
        ? "#16a34a"
        : "#dc2626";

    $fecha = formatearFecha($h["fecha"]);

    $html .= "

    <tr>

        <td
        style='padding:7px;border-bottom:1px solid #e5e7eb;'>

            {$contador}

        </td>

        <td
        style='padding:7px;border-bottom:1px solid #e5e7eb;'>

            {$fecha}

        </td>

        <td
        style='padding:7px;border-bottom:1px solid #e5e7eb;'>

            {$tipo}

        </td>

        <td
        style='padding:7px;
        border-bottom:1px solid #e5e7eb;
        color:{$colorEstado};
        font-weight:bold;'>

            {$estado}

        </td>

    </tr>";

    $contador++;

}

/* =====================================================
   RESUMEN
===================================================== */

$html .= <<<HTML

</table>

</div>

<div class="card">

<h2 class="section-title">

Resumen general

</h2>

<table
class="info-table">

<tr>

<td class="label">

Total de reuniones

</td>

<td>

{$total}

</td>

</tr>

<tr>

<td class="label">

Presentes

</td>

<td>

{$presentes}

</td>

</tr>

<tr>

<td class="label">

Ausencias

</td>

<td>

{$ausentes}

</td>

</tr>

<tr>

<td class="label">

Porcentaje de asistencia

</td>

<td>

{$porcentaje}%

</td>

</tr>

<tr>

<td class="label">

Seguimientos registrados

</td>

<td>

{$totalSeguimientos}

</td>

</tr>

</table>

</div>

<div
style="
margin-top:30px;
padding-top:15px;
border-top:1px solid #d1d5db;
text-align:center;
font-size:10px;
color:#6b7280;
">

Este documento fue generado automáticamente por el Sistema GX.

</div>

</body>

</html>

HTML;

/* =====================================================
   GENERAR PDF
===================================================== */

$dompdf->loadHtml($html);

$dompdf->setPaper("A4", "portrait");

$dompdf->render();

/* =====================================================
   NOMBRE DEL ARCHIVO
===================================================== */

$nombreArchivo = preg_replace(

    "/[^A-Za-z0-9]/",

    "_",

    $joven["nombre_completo"]

);

/* =====================================================
   DESCARGA
===================================================== */

$dompdf->stream(

    "Perfil_{$nombreArchivo}.pdf",

    [

        "Attachment" => true

    ]

);

exit;