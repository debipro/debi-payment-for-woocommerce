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
 *   { sdkUrl, publishableKey, i18n: { ... } }
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
            new Error(i18n.loadError || "The card form could not be loaded."),
          );
        }
      };
      var onError = function () {
        reject(
          new Error(i18n.loadError || "The card form could not be loaded."),
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
    // Already mounted into this exact node: nothing to do.
    if (node === mountedNode && element) {
      return;
    }

    // The node was replaced by a checkout refresh: drop the stale element.
    destroyElement();
    clearToken();
    mountedNode = node;

    loadSdk()
      .then(function (Debi) {
        // The node may have been replaced again while the SDK loaded.
        if (mountedNode !== node || !document.body.contains(node)) {
          return;
        }
        if (!client) {
          client = new Debi(cfg.publishableKey);
        }
        var elements = client.elements({
          locale: cfg.locale || "es-AR",
        });
        element = elements.create("payment-method", {
          allowedTypes: ["card"],
          defaultType: "card",
          strict: true,
          methods: {
            card: {
              expiration: "required",
              securityCode: "required",
            },
          },
        });
        element.on("change", function (state) {
          // Any edit invalidates a previously-issued token.
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
   * Flatten Debi's tokenization error into a human message. Field-level reasons
   * live in `error.raw.errors` (a { field: string[] } map or array of { message }).
   */
  function describeError(error) {
    if (!error) {
      return i18n.genericError || "The card could not be validated.";
    }
    var raw = error.raw;
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
          $("#debipro-card-last-four").val(lastFour || "");
          showError("");
          $form.trigger("submit");
        } else {
          showError(describeError(result && result.error));
          unblock($form);
        }
      })
      .catch(function (err) {
        showError(err && err.message ? err.message : i18n.genericError);
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

  $(function () {
    mountIfNeeded();

    $(document.body).on("updated_checkout", function () {
      mountIfNeeded();
    });

    $(document.body).on("payment_method_selected", function () {
      if (isDebiSelected()) {
        mountIfNeeded();
      }
    });

    // Gate the classic checkout submit: tokenise first, then let it through.
    $("form.checkout").on("checkout_place_order_debipro", function () {
      if (token) {
        return true;
      }
      var $form = $(this);
      block($form);
      tokenizeThenSubmit($form);
      return false;
    });
  });
})(jQuery);
