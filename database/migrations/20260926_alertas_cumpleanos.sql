-- ALERTAS DE CUMPLEAÑOS (ronda de mejoras posterior a la reunión)
--
-- Decisión acordada en la reunión: solo dos usuarios reciben las
-- alertas de cumpleaños (por correo). En vez de guardar sus correos
-- en un archivo de configuración (duplicaría datos que YA existen en
-- usuarios.correo), se marca con un indicador qué usuarios las reciben.
--
-- No destructiva: solo agrega una columna con valor por defecto 0 (nadie
-- recibe alertas hasta que se marque explícitamente desde Usuarios) y
-- una tabla de control para no enviar el mismo día dos veces.
--
-- NOTA: en MySQL los ALTER/CREATE TABLE confirman implícitamente, por
-- eso no se usa START TRANSACTION aquí (no daría atomicidad real).

ALTER TABLE usuarios
    ADD COLUMN recibe_alertas_cumpleanos TINYINT(1) NOT NULL DEFAULT 0 AFTER activo;

CREATE TABLE IF NOT EXISTS cumpleanos_alertas_enviadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    estado ENUM('PROCESANDO','ENVIADO','ERROR') NOT NULL DEFAULT 'PROCESANDO',
    destinatarios INT NOT NULL DEFAULT 0,
    cumpleaneros INT NOT NULL DEFAULT 0,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cumpleanos_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
