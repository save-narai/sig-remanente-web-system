-- MATERIAL DE CLASE EN WORD ADEMÁS DE PDF (ronda de mejoras
-- posterior a la reunión)
--
-- Antes solo existía un material por clase (PDF, reemplazaba al
-- anterior). Ahora puede haber HASTA DOS por clase: uno PDF y uno
-- Word (.docx), cada uno independiente -- subir el Word no borra
-- el PDF ni viceversa.
--
-- No destructiva: la columna nueva queda en 'PDF' para las filas
-- que ya existen (son, de hecho, todas PDF), así que no cambia
-- ningún material ya guardado.

ALTER TABLE materiales_discipulado
    ADD COLUMN tipo ENUM('PDF','WORD') NOT NULL DEFAULT 'PDF' AFTER clase_base_id;

ALTER TABLE materiales_discipulado
    DROP INDEX uq_material_clase_base;

ALTER TABLE materiales_discipulado
    ADD UNIQUE KEY uq_material_clase_tipo (clase_base_id, tipo);
