-- FASE 7 -- Corrección: el permiso "gestionar_formacion" se usa en
-- formacionController.php y las 3 vistas de views/formacion/, pero
-- nunca se sembró en la tabla `permisos`. tienePermiso() lo resuelve
-- consultando permisos/rol_permiso (ADMIN se salta esa tabla vía
-- esAdmin(), así que el módulo ya funcionaba para el Administrador
-- Principal) -- sin esta fila, ningún OTRO rol podría recibir el
-- permiso desde la UI de Roles, porque nunca aparecería como opción.
--
-- No destructiva, no rompe nada existente: solo agrega una fila si
-- no existe ya (por si alguien ya la creó manualmente desde la UI).

INSERT INTO permisos (nombre, descripcion)
SELECT 'gestionar_formacion',
       'Acceder al módulo de Formación: importar archivos de Formación (.xlsx), revisar la previsualización y confirmar o descartar importaciones.'
WHERE NOT EXISTS (
    SELECT 1 FROM permisos WHERE nombre = 'gestionar_formacion'
);
