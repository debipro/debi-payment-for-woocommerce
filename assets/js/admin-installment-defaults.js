/**
 * Toggle default installment fields on the Debi gateway settings screen.
 */
(function ($) {
	"use strict";

	var cfg = window.debiproInstallmentDefaults || {};
	var i18n = cfg.i18n || {};

	if (!cfg.typeId) {
		return;
	}

	var typeId = cfg.typeId;
	var interestId = cfg.interestId;
	var fixedId = cfg.fixedId;
	var maxId = cfg.maxId;
	var installMsg =
		i18n.installMsg ||
		'When the default product type is Installment, set either "Fixed installments" or "Maximum installments".';

	function toggleInstallmentFields() {
		var isInstallment = $(typeId).val() === "installment";
		$(interestId).closest("tr").toggle(isInstallment);
		$(fixedId + ", " + maxId)
			.closest("tr")
			.toggle(isInstallment);
	}

	function fieldHasValue(selector) {
		var value = $(selector).val();
		return value !== "" && value !== null && parseInt(value, 10) >= 1;
	}

	function syncInstallmentFields(changedSelector) {
		if (!fieldHasValue(changedSelector)) {
			return;
		}
		if (changedSelector === fixedId) {
			$(maxId).val("");
		} else if (changedSelector === maxId) {
			$(fixedId).val("");
		}
	}

	$(document).on("change", typeId, toggleInstallmentFields);
	toggleInstallmentFields();

	if (fieldHasValue(fixedId) && fieldHasValue(maxId)) {
		$(maxId).val("");
	}

	$(document).on("input change", fixedId + ", " + maxId, function () {
		syncInstallmentFields("#" + this.id);
	});

	$(typeId)
		.closest("form")
		.on("submit", function (e) {
			if ($(typeId).val() !== "installment") {
				return;
			}
			if (!fieldHasValue(fixedId) && !fieldHasValue(maxId)) {
				e.preventDefault();
				window.alert(installMsg);
			}
		});
})(jQuery);
