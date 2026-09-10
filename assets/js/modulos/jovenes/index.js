document.addEventListener("DOMContentLoaded", () => {

    /* ======================================
       DATATABLE
    ====================================== */

    if (
        typeof $ !== "undefined" &&
        $.fn.DataTable &&
        document.querySelector("#tablaJovenes")
    ) {

        const tabla = $("#tablaJovenes").DataTable({

            pageLength: 8,
            ordering: true,
            searching: true,
            paging: true,
            info: true,
            lengthChange: false,
            responsive: false,
            autoWidth: false,

            dom: 'Brt<"datatable-footer"<"datatable-info"i><"datatable-pagination"p>>',

            buttons: [

                {
                    extend: "pdfHtml5",
                    className: "buttons-pdf",
                    title: "Jóvenes"
                },

                {
                    extend: "excelHtml5",
                    className: "buttons-excel",
                    title: "Jóvenes"
                },

                {
                    extend: "csvHtml5",
                    className: "buttons-csv",
                    title: "Jóvenes"
                },

                {
                    extend: "print",
                    className: "buttons-print",
                    title: "Jóvenes"
                }

            ],

            language: {

                search: "",

                info:
                    "Mostrando _START_ a _END_ de _TOTAL_ registros",

                infoEmpty:
                    "No hay registros disponibles",

                emptyTable:
                    "No hay datos disponibles",

                zeroRecords:
                    "No se encontraron resultados",

                paginate: {

                    previous: "‹",

                    next: "›"

                }

            }

        });

        /* ======================================
           BUSCADOR
        ====================================== */

        const buscador = document.getElementById("buscador");

        if (buscador) {

            buscador.addEventListener("keyup", function () {

                tabla.search(this.value).draw();

            });

        }

        /* ======================================
           EXPORTACIONES
        ====================================== */

        document.getElementById("exportPdf")
            ?.addEventListener("click", () => {

                tabla.button(".buttons-pdf").trigger();

            });

        document.getElementById("exportExcel")
            ?.addEventListener("click", () => {

                tabla.button(".buttons-excel").trigger();

            });

        document.getElementById("exportCsv")
            ?.addEventListener("click", () => {

                tabla.button(".buttons-csv").trigger();

            });

        document.getElementById("exportPrint")
            ?.addEventListener("click", () => {

                tabla.button(".buttons-print").trigger();

            });

    }

    /* ======================================
       TOOLTIPS
    ====================================== */

    document
        .querySelectorAll("[data-tooltip]")
        .forEach(btn => {

            btn.title = btn.dataset.tooltip;

        });

    /* ======================================
       EVITAR DOBLE ENVÍO
    ====================================== */

    document
        .querySelectorAll("form")
        .forEach(form => {

            form.addEventListener("submit", () => {

                const boton = form.querySelector("button");

                if (boton) {

                    boton.disabled = true;

                }

            });

        });

    /* ======================================
       PANEL DE FILTROS (multi-filtro)
       - contador en vivo por grupo, antes
         de aplicar (el servidor ya calcula
         el conteo definitivo al recargar);
       - "Eliminados" desactiva visualmente
         los demás grupos, reforzando en la
         interfaz la regla que el servidor
         ya aplica igual si se ignora esto.
    ====================================== */

    const formFiltros = document.getElementById("formFiltrosJovenes");

    if (formFiltros) {

        const actualizarContadores = () => {

            formFiltros
                .querySelectorAll(".filter-dropdown")
                .forEach(grupo => {

                    const marcados = grupo.querySelectorAll(
                        'input[type="checkbox"]:checked'
                    ).length;

                    const badge = grupo.querySelector("[data-count-badge]");

                    if (marcados > 0) {

                        if (badge) {

                            badge.textContent = marcados;

                        } else {

                            const nuevoBadge = document.createElement("span");

                            nuevoBadge.className = "filter-dropdown__badge";
                            nuevoBadge.setAttribute("data-count-badge", "");
                            nuevoBadge.textContent = marcados;

                            grupo.querySelector("summary")?.appendChild(nuevoBadge);

                        }

                    } else if (badge) {

                        badge.remove();

                    }

                });

        };

        const eliminadosRadio = formFiltros.querySelector(
            'input[name="estado"][value="eliminados"]'
        );

        const otrosGrupos = formFiltros.querySelectorAll(
            '[data-group="riesgo"], [data-group="espiritu"], [data-group="caracteristica"]'
        );

        const aplicarExclusividadEliminados = () => {

            const activo = eliminadosRadio?.checked ?? false;

            otrosGrupos.forEach(grupo => {

                grupo
                    .querySelectorAll('input[type="checkbox"]')
                    .forEach(input => {

                        input.disabled = activo;

                        if (activo) {

                            input.checked = false;

                        }

                    });

            });

            actualizarContadores();

        };

        formFiltros
            .querySelectorAll('input[type="checkbox"]')
            .forEach(input => {

                input.addEventListener("change", actualizarContadores);

            });

        formFiltros
            .querySelectorAll('input[name="estado"]')
            .forEach(input => {

                input.addEventListener("change", aplicarExclusividadEliminados);

            });

        aplicarExclusividadEliminados();

    }

});