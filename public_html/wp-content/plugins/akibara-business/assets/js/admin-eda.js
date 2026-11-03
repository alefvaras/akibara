/**
 * JavaScript de Fecha de llegada en edición de producto. Muestra la fila de lanzamiento según
 * el radio _akb_eta_type seleccionado.
 */
(function ($) {
	'use strict';

	function toggleReleaseDate() {
		var type = $('input[name="_akb_eta_type"]:checked').val();
		$('.akb-eda-release-date-row').toggle('release_date' === type);
		// El plazo personalizado es de las PREVENTAS (con o sin fecha): un
		// «a pedido» se rige por su editorial/país y no debe ofrecer override.
		$('.akb-eda-manual-lead-row').toggle('release_date' === type || 'tba' === type);
	}

	$(document).on('change', 'input[name="_akb_eta_type"]', toggleReleaseDate);
	$(document).ready(toggleReleaseDate);
})(jQuery);
