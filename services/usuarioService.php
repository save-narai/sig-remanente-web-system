<?php

declare(strict_types=1);

require_once __DIR__ . '/cumpleanosService.php';

/* ==========================================================
   USUARIO SERVICE
========================================================== */

/*
|--------------------------------------------------------------------------
| Este servicio centraliza toda la lógica relacionada con la
| administración de usuarios del sistema.
|
| Responsabilidades
|
| • Consultar usuarios
| • Validar información
| • Crear usuarios
| • Editar usuarios
| • Eliminar usuarios
| • Activar y desactivar usuarios
| • Cambiar contraseñas
| • Generar contraseñas temporales
|
|--------------------------------------------------------------------------
*/

/* ==========================================================
   CONSULTAS
========================================================== */

/*
|--------------------------------------------------------------------------
| Todas las funciones de este bloque tienen únicamente la
| responsabilidad de consultar información.
|
| No realizan validaciones.
| No modifican registros.
| No insertan información.
|
|--------------------------------------------------------------------------
*/

/* ==========================================================
   VALIDACIONES
========================================================== */

/*
|--------------------------------------------------------------------------
| Este bloque contiene todas las reglas de negocio para
| validar usuarios, correos, roles y restricciones del
| sistema.
|--------------------------------------------------------------------------
*/

/* ==========================================================
   UTILIDADES
========================================================== */

/*
|--------------------------------------------------------------------------
| Funciones auxiliares reutilizables por todo el servicio.
|
| Ejemplos:
|
| • Encriptar contraseñas
| • Generar contraseñas temporales
| • Obtener información de apoyo
|
|--------------------------------------------------------------------------
*/

/* ==========================================================
   GESTIÓN DE USUARIOS
========================================================== */

/*
|--------------------------------------------------------------------------
| Este bloque contiene únicamente acciones sobre usuarios.
|
| • Crear
| • Editar
| • Eliminar
| • Activar
| • Desactivar
| • Cambiar contraseña
|
|--------------------------------------------------------------------------
*/

/* ==========================================================
   CONSULTA BASE
========================================================== */

/**
 * Construye la consulta base de usuarios.
 */
function obtenerConsultaUsuarios(): string
{
    return "

        SELECT

            u.id,
            u.nombre,
            u.usuario,
            u.correo,
            u.password,
            u.rol_id,
            u.activo,
            u.fecha_creacion,

            r.nombre AS rol_nombre

        FROM usuarios u

        INNER JOIN roles r
            ON r.id = u.rol_id

    ";
}

/* ==========================================================
   OBTENER USUARIO POR ID
========================================================== */

function obtenerUsuarioPorId(
    PDO $pdo,
    int $id
): array|false
{

    $sql = obtenerConsultaUsuarios() . "

        WHERE u.id = :id

        LIMIT 1

    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([

        ':id' => $id

    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);

}

/* ==========================================================
   OBTENER USUARIO POR USERNAME
========================================================== */

function obtenerUsuarioPorUsername(
    PDO $pdo,
    string $usuario
): array|false
{

    $sql = obtenerConsultaUsuarios() . "

        WHERE u.usuario = :usuario

        LIMIT 1

    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([

        ':usuario' => $usuario

    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);

}

/* ==========================================================
   OBTENER USUARIO POR CORREO
========================================================== */

function obtenerUsuarioPorCorreo(
    PDO $pdo,
    string $correo
): array|false
{

    $sql = obtenerConsultaUsuarios() . "

        WHERE u.correo = :correo

        LIMIT 1

    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([

        ':correo' => $correo

    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);

}

/* ==========================================================
   OBTENER USUARIO POR CREDENCIAL
========================================================== */

function obtenerUsuarioPorCredencial(
    PDO $pdo,
    string $credencial
): array|false
{

    if (

        filter_var(

            $credencial,

            FILTER_VALIDATE_EMAIL

        )

    ) {

        return obtenerUsuarioPorCorreo(

            $pdo,

            $credencial

        );

    }

    return obtenerUsuarioPorUsername(

        $pdo,

        $credencial

    );

}

/* ==========================================================
   OBTENER TODOS LOS USUARIOS
========================================================== */

function obtenerUsuarios(
    PDO $pdo
): array
{

    $sql = obtenerConsultaUsuarios() . "

        ORDER BY

            u.nombre ASC

    ";

    $stmt = $pdo->query($sql);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);

}

/* ==========================================================
   EXISTE USUARIO
========================================================== */

function existeUsuario(
    PDO $pdo,
    int $id
): bool
{

    return obtenerUsuarioPorId(

        $pdo,

        $id

    ) !== false;

}

/* ==========================================================
   VALIDAR USERNAME DISPONIBLE
========================================================== */

function validarUsernameDisponible(
    PDO $pdo,
    string $usuario
): bool
{

    return obtenerUsuarioPorUsername(

        $pdo,

        $usuario

    ) === false;

}

/* ==========================================================
   VALIDAR USERNAME EN EDICIÓN
========================================================== */

function validarUsernameEdicion(
    PDO $pdo,
    int $id,
    string $usuario
): bool
{

    $usuarioExistente = obtenerUsuarioPorUsername(

        $pdo,

        $usuario

    );

    if (!$usuarioExistente) {

        return true;

    }

    return (int) $usuarioExistente['id'] === $id;

}

/* ==========================================================
   VALIDAR CORREO DISPONIBLE
========================================================== */

function validarCorreoDisponible(
    PDO $pdo,
    string $correo
): bool
{

    return obtenerUsuarioPorCorreo(

        $pdo,

        $correo

    ) === false;

}

/* ==========================================================
   VALIDAR CORREO EN EDICIÓN
========================================================== */

function validarCorreoEdicion(
    PDO $pdo,
    int $id,
    string $correo
): bool
{

    $usuarioExistente = obtenerUsuarioPorCorreo(

        $pdo,

        $correo

    );

    if (!$usuarioExistente) {

        return true;

    }

    return (int) $usuarioExistente['id'] === $id;

}

/* ==========================================================
   OBTENER ROL
========================================================== */

function obtenerRolPorId(
    PDO $pdo,
    int $rolId
): array|false
{

    $stmt = $pdo->prepare("

        SELECT

            id,
            nombre

        FROM roles

        WHERE id = :id

        LIMIT 1

    ");

    $stmt->execute([

        ':id' => $rolId

    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);

}

/* ==========================================================
   VALIDAR ROL
========================================================== */

function validarRol(
    PDO $pdo,
    int $rolId
): array
{

    $rol = obtenerRolPorId(

        $pdo,

        $rolId

    );

    if (!$rol) {

        throw new Exception(

            'El rol seleccionado no existe.'

        );

    }

    return $rol;

}

/* ==========================================================
   ES ROL ADMINISTRADOR
========================================================== */

function esRolAdministrador(
    PDO $pdo,
    int $rolId
): bool
{

    $rol = obtenerRolPorId(

        $pdo,

        $rolId

    );

    if (!$rol) {

        return false;

    }

    return in_array(

        mb_strtolower(trim($rol['nombre'])),

        [

            'admin',

            'administrador'

        ],

        true

    );

}

/* ==========================================================
   ENCRIPTAR CONTRASEÑA
========================================================== */

function encriptarPassword(
    string $password
): string
{

    return password_hash(

        $password,

        PASSWORD_DEFAULT

    );

}

/* ==========================================================
   VERIFICAR CONTRASEÑA
========================================================== */

function verificarPassword(
    string $password,
    string $hash
): bool
{

    return password_verify(

        $password,

        $hash

    );

}

/* ==========================================================
   GENERAR CONTRASEÑA TEMPORAL
========================================================== */

function generarPasswordTemporal(
    int $longitud = 12
): string
{

    $caracteres =
        'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#$%';

    $password = '';

    $maximo = strlen($caracteres) - 1;

    for (

        $i = 0;

        $i < $longitud;

        $i++

    ) {

        $password .= $caracteres[

            random_int(

                0,

                $maximo

            )

        ];

    }

    return $password;

}

/* ==========================================================
   NORMALIZAR USUARIO
========================================================== */

function normalizarUsuario(
    string $usuario
): string
{

    return strtolower(

        trim($usuario)

    );

}

/* ==========================================================
   NORMALIZAR CORREO
========================================================== */

function normalizarCorreo(
    string $correo
): string
{

    return strtolower(

        trim($correo)

    );

}

/* ==========================================================
   NORMALIZAR NOMBRE
========================================================== */

function normalizarNombre(
    string $nombre
): string
{

    return trim(

        preg_replace(

            '/\s+/',

            ' ',

            $nombre

        )

    );

}

/* ==========================================================
   GENERAR TOKEN
========================================================== */

function generarTokenSeguro(
    int $bytes = 32
): string
{

    return bin2hex(

        random_bytes(

            $bytes

        )

    );

}

/* ==========================================================
   VINCULAR JOVEN A USUARIO (Fase 6)
   ----------------------------------------------------------
   Relación mínima joven <-> usuario que no existía antes del
   Plan Maestro Fase 6. jovenes.usuario_id es UNIQUE, así que
   primero se desvincula cualquier joven que apuntara a este
   usuario (por si se está cambiando o quitando el vínculo)
   antes de fijar el nuevo. $jovenId null/0 = sin vínculo.
========================================================== */

function vincularJovenAUsuario(
    PDO $pdo,
    int $usuarioId,
    ?int $jovenId
): void {

    $pdo->prepare("
        UPDATE jovenes
        SET usuario_id = NULL
        WHERE usuario_id = :usuario_id
    ")->execute([
        'usuario_id' => $usuarioId
    ]);

    if (!empty($jovenId)) {

        $pdo->prepare("
            UPDATE jovenes
            SET usuario_id = :usuario_id
            WHERE id = :joven_id
        ")->execute([
            'usuario_id' => $usuarioId,
            'joven_id' => $jovenId
        ]);
    }
}

/* ==========================================================
   CREAR USUARIO
========================================================== */

function crearUsuario(
    PDO $pdo,
    array $datos
): array
{

    /* ======================================================
       NORMALIZAR DATOS
    ====================================================== */

    $nombre = normalizarNombre(

        $datos['nombre'] ?? ''

    );

    $usuario = normalizarUsuario(

        $datos['usuario'] ?? ''

    );

    $correo = normalizarCorreo(

        $datos['correo'] ?? ''

    );

    $rolId = (int) (

        $datos['rol_id'] ?? 0

    );

    $activo = (int) (

        $datos['activo'] ?? 1

    );

    /* ======================================================
       VALIDACIONES
    ====================================================== */

    if (

        $nombre === '' ||

        $usuario === '' ||

        $correo === '' ||

        $rolId <= 0

    ) {

        throw new Exception(

            'Todos los campos son obligatorios.'

        );

    }

    if (

        !filter_var(

            $correo,

            FILTER_VALIDATE_EMAIL

        )

    ) {

        throw new Exception(

            'El correo electrónico no es válido.'

        );

    }

    if (

        !validarUsernameDisponible(

            $pdo,

            $usuario

        )

    ) {

        throw new Exception(

            'El nombre de usuario ya se encuentra registrado.'

        );

    }

    if (

        !validarCorreoDisponible(

            $pdo,

            $correo

        )

    ) {

        throw new Exception(

            'El correo electrónico ya se encuentra registrado.'

        );

    }

    /* ======================================================
       VALIDAR ROL
    ====================================================== */

    validarRol(

        $pdo,

        $rolId

    );

    if (

        esRolAdministrador(

            $pdo,

            $rolId

        )

    ) {

        throw new Exception(

            'No está permitido crear otro Administrador.'

        );

    }

    /* ======================================================
       CONTRASEÑA
    ====================================================== */

    $passwordTemporal =

        generarPasswordTemporal();

    $passwordHash =

        encriptarPassword(

            $passwordTemporal

        );

    /* ======================================================
       INSERTAR
    ====================================================== */

    $stmt = $pdo->prepare("

        INSERT INTO usuarios (

            nombre,
            usuario,
            correo,
            password,
            rol_id,
            activo

        )

        VALUES (

            :nombre,
            :usuario,
            :correo,
            :password,
            :rol_id,
            :activo

        )

    ");

    $stmt->execute([

        ':nombre'   => $nombre,

        ':usuario'  => $usuario,

        ':correo'   => $correo,

        ':password' => $passwordHash,

        ':rol_id'   => $rolId,

        ':activo'   => $activo

    ]);

    $usuarioId = (int) $pdo->lastInsertId();

    /* ======================================================
       VINCULAR JOVEN (Fase 6 -- opcional, fuente de verdad de
       "servidor del Ministerio de Jóvenes")
    ====================================================== */

    vincularJovenAUsuario(
        $pdo,
        $usuarioId,
        !empty($datos['joven_id']) ? (int) $datos['joven_id'] : null
    );

    guardarPreferenciaAlertaCumpleanos($pdo, $usuarioId, $datos);

    /* ======================================================
       RESPUESTA
    ====================================================== */

    return [

        'id' => $usuarioId,

        'nombre' => $nombre,

        'usuario' => $usuario,

        'correo' => $correo,

        'rol_id' => $rolId,

        'activo' => $activo,

        'password_temporal' => $passwordTemporal

    ];

}

/* ==========================================================
   EDITAR USUARIO
========================================================== */

function editarUsuario(
    PDO $pdo,
    array $datos
): void
{

    /* ======================================================
       NORMALIZAR DATOS
    ====================================================== */

    $id = (int) (

        $datos['id'] ?? 0

    );

    $nombre = normalizarNombre(

        $datos['nombre'] ?? ''

    );

    $usuario = normalizarUsuario(

        $datos['usuario'] ?? ''

    );

    $correo = normalizarCorreo(

        $datos['correo'] ?? ''

    );

    $rolId = (int) (

        $datos['rol_id'] ?? 0

    );

    $activo = (int) (

        $datos['activo'] ?? 1

    );

    $password = trim(

        $datos['password'] ?? ''

    );

    /* ======================================================
       VALIDACIONES
    ====================================================== */

    if (

        $id <= 0

    ) {

        throw new Exception(

            'Usuario inválido.'

        );

    }

    if (

        !existeUsuario(

            $pdo,

            $id

        )

    ) {

        throw new Exception(

            'El usuario no existe.'

        );

    }

    if (

        $nombre === '' ||

        $usuario === '' ||

        $correo === '' ||

        $rolId <= 0

    ) {

        throw new Exception(

            'Todos los campos son obligatorios.'

        );

    }

    if (

        !filter_var(

            $correo,

            FILTER_VALIDATE_EMAIL

        )

    ) {

        throw new Exception(

            'El correo electrónico no es válido.'

        );

    }

    if (

        !validarUsernameEdicion(

            $pdo,

            $id,

            $usuario

        )

    ) {

        throw new Exception(

            'El nombre de usuario ya está registrado.'

        );

    }

    if (

        !validarCorreoEdicion(

            $pdo,

            $id,

            $correo

        )

    ) {

        throw new Exception(

            'El correo electrónico ya está registrado.'

        );

    }

    validarRol(

        $pdo,

        $rolId

    );

    /* ======================================================
       PROTEGER ADMINISTRADOR
    ====================================================== */

    if (

        esAdministradorPrincipal(

            $pdo,

            $id

        ) &&

        !esRolAdministrador(

            $pdo,

            $rolId

        )

    ) {

        throw new Exception(

            'No es posible modificar el rol del Administrador principal.'

        );

    }

    /* ======================================================
       ACTUALIZAR
    ====================================================== */

    if (

        $password !== ''

    ) {

        $password = encriptarPassword(

            $password

        );

        $stmt = $pdo->prepare("

            UPDATE usuarios

            SET

                nombre = :nombre,
                usuario = :usuario,
                correo = :correo,
                password = :password,
                rol_id = :rol_id,
                activo = :activo

            WHERE id = :id

        ");

        $stmt->execute([

            ':nombre' => $nombre,

            ':usuario' => $usuario,

            ':correo' => $correo,

            ':password' => $password,

            ':rol_id' => $rolId,

            ':activo' => $activo,

            ':id' => $id

        ]);

        vincularJovenAUsuario(
            $pdo,
            $id,
            !empty($datos['joven_id']) ? (int) $datos['joven_id'] : null
        );

    guardarPreferenciaAlertaCumpleanos($pdo, $id, $datos);

        return;

    }

    $stmt = $pdo->prepare("

        UPDATE usuarios

        SET

            nombre = :nombre,
            usuario = :usuario,
            correo = :correo,
            rol_id = :rol_id,
            activo = :activo

        WHERE id = :id

    ");

    $stmt->execute([

        ':nombre' => $nombre,

        ':usuario' => $usuario,

        ':correo' => $correo,

        ':rol_id' => $rolId,

        ':activo' => $activo,

        ':id' => $id

    ]);

    vincularJovenAUsuario(
        $pdo,
        $id,
        !empty($datos['joven_id']) ? (int) $datos['joven_id'] : null
    );

    guardarPreferenciaAlertaCumpleanos($pdo, $id, $datos);

}

/* ==========================================================
   CAMBIAR CONTRASEÑA
========================================================== */

function cambiarPassword(
    PDO $pdo,
    int $id,
    string $password
): void
{

    /* ======================================================
       VALIDACIONES
    ====================================================== */

    if (

        $id <= 0

    ) {

        throw new Exception(

            'Usuario inválido.'

        );

    }

    if (

        !existeUsuario(

            $pdo,

            $id

        )

    ) {

        throw new Exception(

            'El usuario no existe.'

        );

    }

    $password = trim(

        $password

    );

    if (

        $password === ''

    ) {

        throw new Exception(

            'Debe ingresar una contraseña.'

        );

    }

    if (

        strlen(

            $password

        ) < 8

    ) {

        throw new Exception(

            'La contraseña debe tener al menos 8 caracteres.'

        );

    }

    /* ======================================================
       ACTUALIZAR
    ====================================================== */

    $stmt = $pdo->prepare("

        UPDATE usuarios

        SET

            password = :password

        WHERE

            id = :id

    ");

    $stmt->execute([

        ':password' => encriptarPassword(

            $password

        ),

        ':id' => $id

    ]);

}

/* ==========================================================
   ES ADMINISTRADOR PRINCIPAL
========================================================== */

function esAdministradorPrincipal(
    PDO $pdo,
    int $usuarioId
): bool
{

    $usuario = obtenerUsuarioPorId(

        $pdo,

        $usuarioId

    );

    if (

        !$usuario

    ) {

        return false;

    }

    return esRolAdministrador(

        $pdo,

        (int) $usuario['rol_id']

    );

}

/* ==========================================================
   PUEDE GESTIONAR USUARIO
========================================================== */

function puedeGestionarUsuario(
    PDO $pdo,
    int $usuarioActualId,
    int $usuarioDestinoId
): bool
{

    /*
    |--------------------------------------------------------------------------
    | El mismo usuario siempre puede editar su información.
    |--------------------------------------------------------------------------
    */

    if (

        $usuarioActualId === $usuarioDestinoId

    ) {

        return true;

    }

    /*
    |--------------------------------------------------------------------------
    | Nadie puede administrar al Administrador principal,
    | excepto él mismo.
    |--------------------------------------------------------------------------
    */

    if (

        esAdministradorPrincipal(

            $pdo,

            $usuarioDestinoId

        )

    ) {

        return false;

    }

    return true;

}

/* ==========================================================
   PUEDE ELIMINAR USUARIO
========================================================== */

function puedeEliminarUsuario(
    PDO $pdo,
    int $usuarioActualId,
    int $usuarioDestinoId
): bool
{

    /*
    |--------------------------------------------------------------------------
    | Nunca permitir eliminar la propia cuenta.
    |--------------------------------------------------------------------------
    */

    if (

        $usuarioActualId === $usuarioDestinoId

    ) {

        return false;

    }

    /*
    |--------------------------------------------------------------------------
    | Nunca permitir eliminar al Administrador.
    |--------------------------------------------------------------------------
    */

    if (

        esAdministradorPrincipal(

            $pdo,

            $usuarioDestinoId

        )

    ) {

        return false;

    }

    return true;

}

/* ==========================================================
   CAMBIAR ESTADO DEL USUARIO
========================================================== */

function cambiarEstadoUsuario(
    PDO $pdo,
    int $usuarioActualId,
    int $usuarioDestinoId,
    bool $activo
): void
{

    /* ======================================================
       VALIDACIONES
    ====================================================== */

    if (

        $usuarioDestinoId <= 0

    ) {

        throw new Exception(

            'Usuario inválido.'

        );

    }

    if (

        !existeUsuario(

            $pdo,

            $usuarioDestinoId

        )

    ) {

        throw new Exception(

            'El usuario no existe.'

        );

    }

    if (

        !puedeGestionarUsuario(

            $pdo,

            $usuarioActualId,

            $usuarioDestinoId

        )

    ) {

        throw new Exception(

            'No tiene permisos para modificar este usuario.'

        );

    }

    /* ======================================================
       ACTUALIZAR
    ====================================================== */

    $stmt = $pdo->prepare("

        UPDATE usuarios

        SET

            activo = :activo

        WHERE

            id = :id

    ");

    $stmt->execute([

        ':activo' => $activo ? 1 : 0,

        ':id' => $usuarioDestinoId

    ]);

}

/* ==========================================================
   ACTIVAR USUARIO
========================================================== */

function activarUsuario(
    PDO $pdo,
    int $usuarioActualId,
    int $usuarioDestinoId
): void
{

    cambiarEstadoUsuario(

        $pdo,

        $usuarioActualId,

        $usuarioDestinoId,

        true

    );

}

/* ==========================================================
   DESACTIVAR USUARIO
========================================================== */

function desactivarUsuario(
    PDO $pdo,
    int $usuarioActualId,
    int $usuarioDestinoId
): void
{

    cambiarEstadoUsuario(

        $pdo,

        $usuarioActualId,

        $usuarioDestinoId,

        false

    );

}

/* ==========================================================
   ELIMINAR USUARIO
========================================================== */

function eliminarUsuario(
    PDO $pdo,
    int $usuarioActualId,
    int $usuarioDestinoId
): void
{

    /* ======================================================
       VALIDACIONES
    ====================================================== */

    if (

        $usuarioDestinoId <= 0

    ) {

        throw new Exception(

            'Usuario inválido.'

        );

    }

    if (

        !existeUsuario(

            $pdo,

            $usuarioDestinoId

        )

    ) {

        throw new Exception(

            'El usuario no existe.'

        );

    }

    if (

        !puedeEliminarUsuario(

            $pdo,

            $usuarioActualId,

            $usuarioDestinoId

        )

    ) {

        throw new Exception(

            'No tiene permisos para eliminar este usuario.'

        );

    }

    /* ======================================================
       ELIMINAR
    ====================================================== */

    $stmt = $pdo->prepare("

        DELETE FROM usuarios

        WHERE id = :id

    ");

    $stmt->execute([

        ':id' => $usuarioDestinoId

    ]);

}