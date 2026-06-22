/**
 * Debi product tab field toggles on the WooCommerce product edit screen.
 */
(function ($) {
	"use strict";

	var cfg = window.debiproProductMeta || {};

	if (!cfg.typeKey) {
		return;
	}

	var typeKey = cfg.typeKey;
	var interestId = cfg.interestId;
	var installId = cfg.installId;
	var maxInstId = cfg.maxInstId;
	var installPlaceholder = cfg.installPlaceholder || "";
	var maxPlaceholder = cfg.maxPlaceholder || "";

	function syncInstallmentPlaceholders() {
		var $install = $(installId);
		var $max = $(maxInstId);

		if ($max.val() !== "") {
			$install.attr("placeholder", "");
		} else if ($install.val() === "") {
			$install.attr("placeholder", installPlaceholder);
		}

		if ($install.val() !== "") {
			$max.attr("placeholder", "");
		} else if ($max.val() === "") {
			$max.attr("placeholder", maxPlaceholder);
		}
	}

	function toggleDebiFields() {
		var $typeSelect = $(typeKey);
		if ($typeSelect.length === 0) {
			return;
		}

		var isInstallment = $typeSelect.val() === "installment";
		var $installmentFields = $(interestId + ", " + installId + ", " + maxInstId).closest(
			".form-field",
		);

		$installmentFields.toggle(isInstallment);
	}

	$(document).on("input change", installId, function () {
		if ($(this).val() !== "") {
			$(maxInstId).val("");
		}
		syncInstallmentPlaceholders();
	});

	$(document).on("input change", maxInstId, function () {
		if ($(this).val() !== "") {
			$(installId).val("");
		}
		syncInstallmentPlaceholders();
	});

	$(document).on("change", typeKey, function () {
		toggleDebiFields();
	});

	$(document).on(
		"woocommerce_panels_saved woocommerce_product_type_changed",
		function () {
			toggleDebiFields();
		},
	);

	toggleDebiFields();
	syncInstallmentPlaceholders();
	setTimeout(toggleDebiFields, 200);
})(jQuery);
