<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/* ==========================================================
   MAIL SERVICE
========================================================== */

/*
|--------------------------------------------------------------------------
| Este servicio centraliza el envío de correos electrónicos
| del sistema.
|
| Responsabilidades
|
| • Configurar PHPMailer
| • Enviar correos
| • Plantillas de correo
|
|--------------------------------------------------------------------------
*/

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ==========================================================
   CREAR MAILER
========================================================== */

function crearMailer(): PHPMailer
{

    $config = require __DIR__ . '/../config/mail.php';

    $mail = new PHPMailer(true);

    $mail->isSMTP();

    $mail->Host = $config['host'];

    $mail->SMTPAuth = true;

    $mail->CharSet = 'UTF-8';

$mail->Encoding = 'base64';

$mail->isHTML(true);

$mail->SMTPDebug = 0;

    $mail->Username = $config['username'];

    $mail->Password = $config['password'];

    $mail->SMTPSecure = $config['encryption'];

    $mail->Port = $config['port'];

    // Tiempo máximo de espera del servidor SMTP (por defecto PHPMailer
    // espera hasta 300 s). Con un SMTP caído eso congelaría la página
    // que dispare el envío (ej. la alerta de cumpleaños al abrir el
    // dashboard); 15 s es suficiente para un handshake normal.
    $mail->Timeout = 15;

    $mail->CharSet = 'UTF-8';

    $mail->isHTML(true);

    $mail->setFrom(

        $config['from_email'],

        $config['from_name']

    );

    return $mail;

}

/* ==========================================================
   ENVIAR CORREO
========================================================== */

function enviarCorreo(
    string $destinatario,
    string $nombre,
    string $asunto,
    string $mensaje
): void
{

    $mail = crearMailer();

    try {

        $mail->addAddress(

            $destinatario,

            $nombre

        );

        $mail->Subject = $asunto;

        $mail->Body = $mensaje;

        $mail->send();

    } catch (Exception $e) {

        throw new Exception(

            'No fue posible enviar el correo electrónico.'

        );

    }

}

/* ==========================================================
   ENVIAR CORREO DE RECUPERACIÓN
========================================================== */

function enviarCorreoRecuperacion(
    array $usuario,
    string $token
): void
{

    $enlace = BASE_URL .
        '/views/auth/reset-password.php?token=' .
        urlencode($token);

    $asunto = 'Recuperación de contraseña - SIG Remanente';

    $mensaje = '

        <div style="font-family:Arial,sans-serif;
                    max-width:650px;
                    margin:auto;
                    padding:30px;
                    border:1px solid #e5e7eb;
                    border-radius:12px;">

            <h2 style="color:#f97316;">
                Recuperación de contraseña
            </h2>

            <p>

                Hola <strong>' .
                    htmlspecialchars($usuario['nombre']) .
                '</strong>,

            </p>

            <p>

                Hemos recibido una solicitud para
                restablecer la contraseña de tu cuenta.

            </p>

            <p>

                Haz clic en el siguiente botón para
                crear una nueva contraseña.

            </p>

            <p style="text-align:center; margin:35px 0;">

                <a
                    href="' . $enlace . '"
                    style="
                        background:#f97316;
                        color:#fff;
                        padding:14px 28px;
                        text-decoration:none;
                        border-radius:8px;
                        display:inline-block;
                        font-weight:bold;
                    ">

                    Restablecer contraseña

                </a>

            </p>

            <p>

                Si el botón no funciona, copia
                y pega este enlace en tu navegador:

            </p>

            <p>

                <a href="' . $enlace . '">

                    ' . $enlace . '

                </a>

            </p>

            <hr>

            <small style="color:#6b7280;">

                Este enlace expirará en 1 hora.

                Si no solicitaste este cambio,
                puedes ignorar este mensaje.

            </small>

        </div>

    ';

    enviarCorreo(

        $usuario['correo'],

        $usuario['nombre'],

        $asunto,

        $mensaje

    );

}

/* ==========================================================
   FIN DEL SERVICIO
========================================================== */