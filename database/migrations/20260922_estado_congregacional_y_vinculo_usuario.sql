-- FASE 6 -- Separación del antiguo "estado_espiritual" en sus
-- conceptos reales, según decisión confirmada:
--
--   1) Estado CONGREGACIONAL (queda en jovenes.estado_espiritual,
--      restringido a NUEVO/CONGREGANTE, derivado de fecha_ingreso
--      con el corte de 6 meses -- ya no es un valor manual).
--   2) Servidor del Ministerio de Jóvenes (pasa a determinarse
--      desde el módulo de Usuarios, vía la nueva relación
--      jovenes.usuario_id -- no existía NINGUNA relación joven
--      <-> usuario en el proyecto antes de esta migración).
--   3) Discipulado/Formación (se retira como valor manual; la
--      fuente de verdad ya es el módulo de discipulado con sus
--      propias tablas de ciclos/inscripciones).
--
-- IMPORTANTE -- no destructivo:
--   - No se borra ningún joven ni ningún usuario.
--   - No se borran registros: los valores SERVIDOR/LIDER/
--     DISCIPULADO que existieran se transforman a NUEVO/
--     CONGREGANTE según fecha_ingreso (única fuente confiable
--     disponible), NUNCA se descartan filas.
--   - usuario_id queda NULL para todos los jóvenes existentes:
--     no hay ninguna columna común (ej. correo) entre "jovenes"
--     y "usuarios" que permita un cruce automático seguro sin
--     arriesgar falsos positivos (dos personas con el mismo
--     nombre, etc.). Vincular manualmente desde Usuarios
--     (editar/crear usuario -> "Joven asociado") queda como
--     tarea administrativa pendiente, documentada en la entrega.
--
-- ANTES DE EJECUTAR: confirmar que "jovenes.id" y "usuarios.id"
-- son del mismo tipo (INT) y motor (InnoDB) para que la FK no
-- falle; ajustar el tipo si difiere antes de correr este script.

START TRANSACTION;

-- 1) Relación mínima joven <-> usuario (nueva, no existía).
--    Nullable: no todo joven tiene cuenta. Única: una cuenta de
--    usuario corresponde como máximo a un joven.
ALTER TABLE jovenes
    ADD COLUMN usuario_id INT NULL AFTER es_servidor;

ALTER TABLE jovenes
    ADD CONSTRAINT fk_jovenes_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE SET NULL;

ALTER TABLE jovenes
    ADD UNIQUE KEY uq_jovenes_usuario_id (usuario_id);

-- 2) Migrar los datos ANTES de restringir el ENUM (si se
--    restringe primero con SERVIDOR/LIDER/DISCIPULADO todavía
--    presentes en filas existentes, la alteración fallaría o
--    truncaría esos valores a algo indefinido). La regla nueva
--    es puramente por fecha_ingreso, sin importar el valor
--    anterior de la columna -- así no se necesita un mapeo
--    especial por cada valor viejo, y la transformación es
--    100% determinística y verificable.
UPDATE jovenes
SET estado_espiritual = CASE
    WHEN fecha_ingreso IS NULL THEN 'NUEVO'
    WHEN TIMESTAMPDIFF(MONTH, fecha_ingreso, CURDATE()) >= 6 THEN 'CONGREGANTE'
    ELSE 'NUEVO'
END;

-- 3) Restringir la columna a solo el estado congregacional.
ALTER TABLE jovenes
    MODIFY COLUMN estado_espiritual ENUM('NUEVO','CONGREGANTE') NOT NULL DEFAULT 'NUEVO';

COMMIT;

-- NOTA: esta migración NO intenta adivinar quién era servidor o
-- líder a partir del valor viejo -- eso ahora vive en Usuarios,
-- y no hay forma automática segura de vincular jovenes.usuario_id
-- sin una clave común confiable (jovenes no tiene correo). Ver el
-- informe de entrega para el detalle de qué queda pendiente de
-- vinculación manual.
