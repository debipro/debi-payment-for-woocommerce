/**
 * Debi card element integration for the classic WooCommerce checkout.
 *
 * The card is tokenised in the browser by js.debi.pro (loaded from the CDN), so
 * the PAN never reaches the server. On "Place order" we intercept the WooCommerce
 * submit, call `confirmPaymentMethod` (strict mode), write the resulting token id
 * into a hidden field, and let the checkout proceed — `process_payment()` then
 * charges the token via the Debi PHP SDK.
 *
 * Config is injected by wp_localize_script as `debiproCheckout`:
 *   { sdkUrl, publishableKey, locale, i18n: { ... } }
 *
 * Installment options and financing data are embedded per-render in
 * #debipro-installment-ui (data-installment-options, data-financing,
 * data-cart-total), so they stay fresh after WooCommerce's updated_checkout AJAX.
 */
(function ($) {
  "use strict";

  if (
    typeof debiproCheckout === "undefined" ||
    !debiproCheckout.publishableKey
  ) {
    return;
  }

  var cfg = debiproCheckout;
  var i18n = cfg.i18n || {};

  var sdkPromise = null;
  var client = null;
  var element = null;
  var mountedNode = null;
  var token = "";

  // ---------------------------------------------------------------------------
  // Installment UI
  // ---------------------------------------------------------------------------

  /**
   * Format a number as Argentine locale currency (comma decimal, space thousands).
   * Matches PHP's number_format($x, 2, ',', ' ').
   */
  function formatAmount(amount) {
    var parts = amount.toFixed(2).split(".");
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, " ");
    return parts[0] + "," + parts[1];
  }

  /**
   * Render the installment/plan UI below the description and above the card.
   * Uses the same server-built options as the Blocks checkout so labels stay
   * in sync; data attributes are refreshed on updated_checkout.
   */
  function renderInstallmentUI() {
    var container = document.getElementById("debipro-installment-ui");
    if (!container) {
      return;
    }

    var f, cartTotal, options;
    try {
      f = JSON.parse(container.getAttribute("data-financing") || "{}");
      cartTotal = parseFloat(container.getAttribute("data-cart-total") || "0");
      options = JSON.parse(
        container.getAttribute("data-installment-options") || "[]"
      );
    } catch (e) {
      return;
    }

    var $cuotas = $("#debipro-cuotas");
    var previous = $cuotas.val();
    var surcharge = f.surcharge || 0;
    var base = cartTotal * (1 + surcharge / 100);

    if (f.type === "subscription") {
      container.innerHTML =
        '<p class="debipro-plan-info">' +
        "<strong>" +
        (i18n.recurringPayment || "Pago recurrente mensual") +
        ":</strong> $ " +
        formatAmount(base) +
        " / " +
        (i18n.perMonth || "mes") +
        "</p>";
      $cuotas.val("");
      return;
    }

    if (!options.length) {
      container.innerHTML = "";
      $cuotas.val("");
      return;
    }

    var opts =
      '<option value="" disabled' +
      (previous ? "" : " selected") +
      ">" +
      (i18n.selectInstallments || "Seleccioná el número de cuotas") +
      "</option>";

    options.forEach(function (opt) {
      var selected = String(previous) === String(opt.value) ? " selected" : "";
      opts +=
        '<option value="' +
        opt.value +
        '"' +
        selected +
        ">" +
        opt.label +
        "</option>";
    });

    container.innerHTML =
      '<p class="form-row form-row-wide">' +
      '<label for="debipro-cuotas-sel">' +
      (i18n.installmentsLabel || "Cuotas") +
      ' <span class="required">*</span></label>' +
      '<select id="debipro-cuotas-sel" style="width:100%;margin-top:4px;box-sizing:border-box;">' +
      opts +
      "</select></p>";

    var $select = $("#debipro-cuotas-sel");
    $select.on("change", function () {
      $cuotas.val(this.value);
    });

    if ($select.val()) {
      $cuotas.val($select.val());
    }
  }

  function installmentsRequired() {
    var container = document.getElementById("debipro-installment-ui");
    if (!container) {
      return false;
    }
    try {
      var f = JSON.parse(container.getAttribute("data-financing") || "{}");
      if (f.type === "subscription") {
        return false;
      }
      var options = JSON.parse(
        container.getAttribute("data-installment-options") || "[]"
      );
      return options.length > 0;
    } catch (e) {
      return false;
    }
  }

  // ---------------------------------------------------------------------------
  // SDK / card element
  // ---------------------------------------------------------------------------

  /** Inject the js.debi.pro CDN script once and resolve `window.Debi`. */
  function loadSdk() {
    if (window.Debi) {
      return Promise.resolve(window.Debi);
    }
    if (sdkPromise) {
      return sdkPromise;
    }
    sdkPromise = new Promise(function (resolve, reject) {
      var existing = document.querySelector('script[src="' + cfg.sdkUrl + '"]');
      var onReady = function () {
        if (window.Debi) {
          resolve(window.Debi);
        } else {
          reject(
            new Error(i18n.loadError || "The card form could not be loaded.")
          );
        }
      };
      var onError = function () {
        reject(
          new Error(i18n.loadError || "The card form could not be loaded.")
        );
      };
      if (existing) {
        existing.addEventListener("load", onReady, { once: true });
        existing.addEventListener("error", onError, { once: true });
        return;
      }
      var script = document.createElement("script");
      script.src = cfg.sdkUrl;
      script.async = true;
      script.addEventListener("load", onReady, { once: true });
      script.addEventListener("error", onError, { once: true });
      document.head.appendChild(script);
    });
    return sdkPromise;
  }

  /** Reset any previously-tokenised card (tokens are single-use). */
  function clearToken() {
    token = "";
    $("#debipro-payment-method-token").val("");
  }

  function showError(message) {
    $("#debipro-card-errors").text(message || "");
  }

  /**
   * Mount the card element into the payment box if it is present and not yet
   * mounted. WooCommerce re-renders the payment box on `updated_checkout`, so
   * this runs again each time to (re)mount into the fresh node.
   */
  function mountIfNeeded() {
    var node = document.getElementById("debipro-card-element");
    if (!node) {
      return;
    }
    if (node === mountedNode && element) {
      return;
    }

    destroyElement();
    clearToken();
    mountedNode = node;

    loadSdk()
      .then(function (Debi) {
        if (mountedNode !== node || !document.body.contains(node)) {
          return;
        }
        if (!client) {
          client = new Debi(cfg.publishableKey);
        }
        var elements = client.elements({
          locale: cfg.locale || "es-AR",
        });
        // Capture cards for recurring debit: expiration and CVV are not needed
        // and would otherwise be auto-detected as optional. Force them hidden.
        element = elements.create("payment-method", {
          defaultType: "card",
          strict: true,
          methods: {
            card: {
              expiration: "hidden",
              securityCode: "hidden",
            },
          },
        });
        element.on("change", function (state) {
          clearToken();
          showError(state && state.error ? state.error.message : "");
        });
        element.mount(node);
      })
      .catch(function (err) {
        showError(err && err.message ? err.message : i18n.loadError);
      });
  }

  function destroyElement() {
    if (element) {
      try {
        element.unmount();
        element.destroy();
      } catch (e) {
        /* already gone */
      }
    }
    element = null;
  }

  /** Whether the Debi gateway is the one currently selected. */
  function isDebiSelected() {
    var $checked = $('input[name="payment_method"]:checked');
    return $checked.length ? $checked.val() === "debipro" : false;
  }

  /**
   * Flatten Debi's tokenization error into a human message.
   */
  function describeError(error) {
    if (!error) {
      return i18n.genericError || "The card could not be validated.";
    }
    var raw = error.raw;
    if (raw && (raw.status === 429 || raw.statusCode === 429)) {
      return i18n.rateLimitError || "The payment service is temporarily busy. Please wait a moment and try again.";
    }
    var messages = [];
    var collect = function (value) {
      if (typeof value === "string") {
        if (value.trim()) {
          messages.push(value.trim());
        }
      } else if (Array.isArray(value)) {
        value.forEach(collect);
      } else if (value && typeof value === "object") {
        var m =
          value.message || value.detail || value.description || value.title;
        if (typeof m === "string" && m.trim()) {
          messages.push(m.trim());
        }
      }
    };
    if (raw && typeof raw === "object") {
      var source =
        raw.errors && typeof raw.errors === "object" ? raw.errors : raw;
      Object.keys(source).forEach(function (k) {
        collect(source[k]);
      });
    }
    if (!messages.length && error.message) {
      messages.push(error.message);
    }
    return messages.length
      ? messages.join(" ")
      : i18n.genericError || "The card could not be validated.";
  }

  /**
   * Tokenise the card and, on success, submit the checkout form with the token.
   */
  function tokenizeThenSubmit($form) {
    if (!client || !element) {
      showError(i18n.notReady || "The card form is not ready yet.");
      unblock($form);
      return;
    }
    client
      .confirmPaymentMethod(element, { strict: true })
      .then(function (result) {
        if (result && result.token && result.token.id) {
          token = result.token.id;
          $("#debipro-payment-method-token").val(token);
          var lastFour =
            result.token.card && result.token.card.last_four_digits;
          $("#debipro_card_last_4_digits").val(lastFour || "");
          showError("");
          unblock($form);
          $form.trigger("submit");
        } else {
          showError(describeError(result && result.error));
          unblock($form);
        }
      })
      .catch(function (err) {
        var raw = (err && err.raw) || {};
        var isRateLimit = (err && (err.status === 429 || err.statusCode === 429)) ||
          raw.status === 429 || raw.statusCode === 429;
        showError(isRateLimit
          ? (i18n.rateLimitError || "The payment service is temporarily busy. Please wait a moment and try again.")
          : (err && err.message ? err.message : i18n.genericError));
        unblock($form);
      });
  }

  function block($form) {
    $form.addClass("processing").block({
      message: null,
      overlayCSS: { background: "#fff", opacity: 0.6 },
    });
  }

  function unblock($form) {
    $form.removeClass("processing").unblock();
  }

  // ---------------------------------------------------------------------------
  // Bootstrap
  // ---------------------------------------------------------------------------

  $(function () {
    renderInstallmentUI();
    mountIfNeeded();

    $(document.body).on("updated_checkout", function () {
      renderInstallmentUI();
      mountIfNeeded();
    });

    $(document.body).on("payment_method_selected", function () {
      if (isDebiSelected()) {
        renderInstallmentUI();
        mountIfNeeded();
      }
    });

    $("form.checkout").on("checkout_place_order_debipro", function () {
      if (token) {
        return true;
      }
      var $form = $(this);

      if (installmentsRequired() && !$("#debipro-cuotas").val()) {
        showError(
          i18n.installmentsRequired ||
            "Por favor seleccioná el número de cuotas."
        );
        return false;
      }

      block($form);
      tokenizeThenSubmit($form);
      return false;
    });
  });
})(jQuery);
