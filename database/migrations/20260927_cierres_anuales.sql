-- CIERRE ANUAL (ronda de mejoras posterior a la reunión)
--
-- Guarda una FOTOGRAFÍA de los indicadores de un año (el mismo
-- contenido del reporte anual) para poder comparar año contra año.
--
-- NO borra ni modifica jóvenes, asistencias, reuniones ni ningún otro
-- dato: cerrar un año solo AGREGA una fila aquí. El "reinicio del
-- conteo" ocurre solo, porque los reportes cuentan reuniones y
-- asistencias por rango de fechas (cada año cuenta desde cero).
--
-- Un cierre por año (UNIQUE) e inmutable: no hay UPDATE ni DELETE en el
-- código, así el historial no se sobrescribe en silencio. Se guarda el
-- nombre de quien cerró como texto (sin FK) para que el historial
-- sobreviva aunque esa cuenta se elimine después.

CREATE TABLE IF NOT EXISTS cierres_anuales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    anio SMALLINT NOT NULL,
    datos LONGTEXT NOT NULL,
    cerrado_por INT NULL,
    cerrado_por_nombre VARCHAR(150) NULL,
    cerrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cierre_anio (anio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
