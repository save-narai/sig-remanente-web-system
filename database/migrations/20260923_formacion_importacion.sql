-- FASE 7 -- Infraestructura de Formación (importación XLSX / futuro
-- Google Sheets). NO crea una segunda fuente de verdad de jóvenes
-- (jovenes sigue siendo la única); solo agrega lo que hace falta
-- para representar datos propios de Formación y su trazabilidad.
--
-- No duplica el módulo de discipulado: si en el futuro se confirma
-- que un "ciclo_formacion" (texto libre, tal como lo reporta el
-- archivo externo) corresponde a un ciclo real de
-- `ciclos_discipulado`, existe un campo opcional para vincularlo
-- manualmente -- no se asume ninguna correspondencia automática
-- porque no conocemos la estructura real del archivo de Formación.
--
-- No destructiva: no toca `jovenes` ni tablas de discipulado.

START TRANSACTION;

-- 1) Encabezado de cada importación (una fila por archivo subido).
CREATE TABLE formacion_importaciones (

    id INT AUTO_INCREMENT PRIMARY KEY,

    archivo_nombre VARCHAR(255) NOT NULL,

    tipo_origen ENUM('XLSX', 'GOOGLE_SHEETS') NOT NULL DEFAULT 'XLSX',

    usuario_id INT NULL,

    fecha_importacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    total_filas INT NOT NULL DEFAULT 0,
    nuevos INT NOT NULL DEFAULT 0,
    modificados INT NOT NULL DEFAULT 0,
    duplicados INT NOT NULL DEFAULT 0,
    sin_correspondencia INT NOT NULL DEFAULT 0,
    sin_cambios INT NOT NULL DEFAULT 0,

    estado ENUM('PENDIENTE_CONFIRMACION', 'CONFIRMADA', 'DESCARTADA')
        NOT NULL DEFAULT 'PENDIENTE_CONFIRMACION',

    fecha_confirmacion DATETIME NULL,

    CONSTRAINT fk_formacion_importaciones_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Detalle de cada fila del archivo, en estado "staging" -- se
--    revisa en la previsualización ANTES de escribir nada en
--    formacion_registros (Fase 7, punto 6: no modificar la BD sin
--    que el usuario confirme).
CREATE TABLE formacion_importacion_filas (

    id INT AUTO_INCREMENT PRIMARY KEY,

    importacion_id INT NOT NULL,

    fila_numero INT NOT NULL,

    joven_id INT NULL,

    -- Datos normalizados de la fila tal como llegaron del archivo,
    -- según el contrato (ver formacionService.php::CONTRATO_FORMACION).
    -- JSON para no atar la estructura de la tabla a columnas que
    -- todavia no conocemos con certeza del archivo real.
    datos_json TEXT NOT NULL,

    estado_fila ENUM(
        'NUEVO',
        'MODIFICADO',
        'DUPLICADO',
        'SIN_CORRESPONDENCIA',
        'SIN_CAMBIOS'
    ) NOT NULL,

    motivo VARCHAR(255) NULL,

    confirmada TINYINT(1) NOT NULL DEFAULT 0,

    CONSTRAINT fk_formacion_filas_importacion
        FOREIGN KEY (importacion_id) REFERENCES formacion_importaciones(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_formacion_filas_joven
        FOREIGN KEY (joven_id) REFERENCES jovenes(id)
        ON DELETE SET NULL,

    INDEX idx_formacion_filas_importacion (importacion_id),
    INDEX idx_formacion_filas_joven (joven_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Estado "actual" de Formación por joven -- lo que confirma una
--    importación termina aquí (upsert). No duplica nombre/telefono/
--    fecha_nacimiento de jovenes: solo datos propios de Formación,
--    relacionados por joven_id.
CREATE TABLE formacion_registros (

    id INT AUTO_INCREMENT PRIMARY KEY,

    joven_id INT NOT NULL,

    -- Texto libre tal como lo reporta el archivo externo -- NO es
    -- necesariamente el mismo concepto que ciclos_discipulado.nombre
    -- hasta que se confirme con el archivo real.
    ciclo_formacion VARCHAR(150) NULL,

    -- Vinculo MANUAL opcional (nunca automatico) a un ciclo real de
    -- discipulado, para cuando se confirme la correspondencia.
    ciclo_discipulado_id INT NULL,

    leccion_actual VARCHAR(150) NULL,

    progreso_porcentaje TINYINT UNSIGNED NULL,

    observaciones TEXT NULL,

    fecha_dato DATE NULL,

    importacion_id INT NULL,

    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_formacion_registros_joven
        FOREIGN KEY (joven_id) REFERENCES jovenes(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_formacion_registros_ciclo_discipulado
        FOREIGN KEY (ciclo_discipulado_id) REFERENCES ciclos_discipulado(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_formacion_registros_importacion
        FOREIGN KEY (importacion_id) REFERENCES formacion_importaciones(id)
        ON DELETE SET NULL,

    UNIQUE KEY uq_formacion_registros_joven (joven_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
