/**
 * Debi gateway settings screen enhancements.
 *
 * - Shows a live environment badge next to each key, inferred from its prefix.
 * - Gives each key its own "Test" button that pings Debi (secret -> customers,
 *   publishable -> account/payment_methods) via the wp_ajax probe.
 * - Blocks the form submit when the keys are malformed or mismatched (the server
 *   validates again in process_admin_options()).
 *
 * Loaded only on WooCommerce > Settings > Payments > Debi.
 */
(function ($) {
  "use strict";

  var cfg = window.debiproAdmin || {};
  var i18n = cfg.i18n || {};

  var $secret = $("#woocommerce_debipro_secret_key");
  var $publishable = $("#woocommerce_debipro_publishable_key");

  if (!$secret.length && !$publishable.length) {
    return;
  }

  /** Infer 'test' | 'live' | '' from a key prefix. */
  function environment(key) {
    key = (key || "").trim();
    if (key.indexOf("sk_test_") === 0 || key.indexOf("pk_test_") === 0) {
      return "test";
    }
    if (key.indexOf("sk_live_") === 0 || key.indexOf("pk_live_") === 0) {
      return "live";
    }
    return "";
  }

  /** 'secret' | 'publishable' | ''. */
  function kind(key) {
    key = (key || "").trim();
    if (key.indexOf("sk_") === 0) {
      return "secret";
    }
    if (key.indexOf("pk_") === 0) {
      return "publishable";
    }
    return "";
  }

  function inputFor(target) {
    return target === "secret" ? $secret : $publishable;
  }
  function badgeFor(target) {
    return $('.debipro-env-badge[data-env-for="' + target + '"]');
  }
  function resultFor(target) {
    return $('.debipro-test-result[data-result-for="' + target + '"]');
  }

  function setBadge($badge, text, cls) {
    $badge
      .text(text)
      .removeClass(
        "debipro-env-test debipro-env-live debipro-env-unknown debipro-env-error",
      )
      .addClass(cls);
  }

  /** Update one field's badge from its current value. */
  function refreshBadge(target) {
    var $input = inputFor(target);
    if (!$input.length) {
      return;
    }
    var val = ($input.val() || "").trim();
    var $badge = badgeFor(target);
    var expected = target === "secret" ? "secret" : "publishable";

    if (!val) {
      setBadge(
        $badge,
        i18n.envUnknown || "Not configured",
        "debipro-env-unknown",
      );
      return;
    }
    if (kind(val) !== expected) {
      setBadge(
        $badge,
        target === "secret"
          ? i18n.secretInvalid || "Invalid secret key"
          : i18n.pubInvalid || "Invalid publishable key",
        "debipro-env-error",
      );
      return;
    }
    var env = environment(val);
    if (env === "test") {
      setBadge($badge, i18n.envTest || "TEST (sandbox)", "debipro-env-test");
    } else if (env === "live") {
      setBadge($badge, i18n.envLive || "PRODUCTION", "debipro-env-live");
    } else {
      setBadge(
        $badge,
        i18n.envUnknown || "Not configured",
        "debipro-env-unknown",
      );
    }
  }

  $secret.on("input change", function () {
    refreshBadge("secret");
  });
  $publishable.on("input change", function () {
    refreshBadge("publishable");
  });
  refreshBadge("secret");
  refreshBadge("publishable");

  // Block the WooCommerce settings save on a clearly invalid/mismatched pair.
  $secret
    .add($publishable)
    .closest("form")
    .on("submit", function (e) {
      var secret = ($secret.val() || "").trim();
      var publishable = ($publishable.val() || "").trim();
      var error = "";

      if (secret && kind(secret) !== "secret") {
        error = i18n.secretInvalid || "Invalid secret key";
      } else if (publishable && kind(publishable) !== "publishable") {
        error = i18n.pubInvalid || "Invalid publishable key";
      } else if (
        secret &&
        publishable &&
        environment(secret) !== environment(publishable)
      ) {
        error = i18n.envMismatch || "Environment mismatch";
      }

      if (error) {
        e.preventDefault();
        window.alert(
          (i18n.blockedSave || "Fix the Debi keys before saving.") +
            "\n\n" +
            error,
        );
      }
    });

  // Per-key "Test" button.
  $(document).on("click", ".debipro-test-key", function () {
    var $btn = $(this);
    var target = $btn.data("target"); // 'secret' | 'publishable'
    var $res = resultFor(target);
    var val = (inputFor(target).val() || "").trim();
    var expected = target === "secret" ? "secret" : "publishable";

    if (!val) {
      $res.css("color", "#b32d2e").text(i18n.enterKey || "Enter a key.");
      return;
    }
    if (kind(val) !== expected) {
      $res
        .css("color", "#b32d2e")
        .text(
          target === "secret"
            ? i18n.secretInvalid || "Invalid secret key"
            : i18n.pubInvalid || "Invalid publishable key",
        );
      return;
    }

    var payload = {
      action: "debipro_test_connection",
      nonce: cfg.nonce,
      secret: "",
      publishable: "",
    };
    payload[target] = val;

    $btn.prop("disabled", true);
    $res.css("color", "").text(i18n.testing || "Testing…");

    $.post(cfg.ajaxUrl, payload)
      .done(function (response) {
        if (
          response &&
          response.success &&
          response.data &&
          response.data.results
        ) {
          var r = response.data.results[target];
          if (r && r.tested) {
            $res
              .css("color", r.ok ? "#1a7f37" : "#b32d2e")
              .text(r.message || "");
          } else {
            $res.css("color", "#b32d2e").text("Error");
          }
        } else {
          var msg =
            (response && response.data && response.data.message) || "Error";
          $res.css("color", "#b32d2e").text(msg);
        }
      })
      .fail(function () {
        $res.css("color", "#b32d2e").text("Request failed");
      })
      .always(function () {
        $btn.prop("disabled", false);
      });
  });

  // "Set up webhook automatically": create (or reuse) the Debi endpoint for
  // this site and drop the returned signing secret into its field.
  $(document).on("click", ".debipro-setup-webhook", function () {
    var $btn = $(this);
    var $res = resultFor("webhook");
    var secret = ($secret.val() || "").trim();

    if (!secret || kind(secret) !== "secret") {
      $res
        .css("color", "#b32d2e")
        .text(i18n.webhookNeedsSecret || "Enter your secret key first.");
      return;
    }

    $btn.prop("disabled", true);
    $res.css("color", "").text(i18n.webhookSetup || "Setting up…");

    $.post(cfg.ajaxUrl, {
      action: "debipro_setup_webhook",
      nonce: cfg.nonce,
      secret: secret,
    })
      .done(function (response) {
        if (response && response.success && response.data) {
          if (response.data.secret) {
            $("#woocommerce_debipro_webhook_secret").val(response.data.secret);
          }
          $res.css("color", "#1a7f37").text(response.data.message || "Done.");
        } else {
          var msg =
            (response && response.data && response.data.message) || "Error";
          $res.css("color", "#b32d2e").text(msg);
        }
      })
      .fail(function () {
        $res.css("color", "#b32d2e").text("Request failed");
      })
      .always(function () {
        $btn.prop("disabled", false);
      });
  });
})(jQuery);
