(function () {
  "use strict";

  var marker = document.querySelector("[data-erankly-migration-autoreload]");
  if (marker) {
    var delay = parseInt(marker.getAttribute("data-erankly-migration-autoreload"), 10);
    if (!delay || delay < 1) {
      delay = 15000;
    }

    window.setTimeout(function () {
      window.location.reload();
    }, delay);
  }

  // Native import: apply one batch per request while this page stays open.
  var panel = document.querySelector("[data-erankly-import-progress]");
  if (!panel || !window.fetch || !window.FormData) {
    return;
  }

  var bar = panel.querySelector("[data-erankly-import-bar]");
  var status = panel.querySelector("[data-erankly-import-status]");
  var running = true;
  var __ = window.wp && wp.i18n ? wp.i18n.__ : function (text) { return text; };
  var sprintf = window.wp && wp.i18n ? wp.i18n.sprintf : function (text) { return text; };

  function warnBeforeLeaving(event) {
    if (running) {
      event.preventDefault();
      event.returnValue = "";
    }
  }

  function stop(message) {
    running = false;
    window.removeEventListener("beforeunload", warnBeforeLeaving);
    if (message && status) {
      status.textContent = message;
    }
  }

  function runBatch() {
    var body = new FormData();
    body.append("action", "erankly_import_batch");
    body.append("_ajax_nonce", panel.getAttribute("data-nonce"));
    body.append("job_id", panel.getAttribute("data-job-id"));

    window
      .fetch(panel.getAttribute("data-ajax-url"), { method: "POST", credentials: "same-origin", body: body })
      .then(function (response) {
        return response.json();
      })
      .then(function (result) {
        var data = result && result.data ? result.data : {};
        if (!result || !result.success) {
          stop(data.message || __("The import stopped. Reload this page to continue from the saved checkpoint.", "easyrankly"));
          return;
        }
        if (data.done) {
          stop();
          window.location.assign(data.redirect);
          return;
        }
        if (bar && data.total) {
          bar.value = Math.floor((100 * data.processed) / data.total);
        }
        if (status) {
          /* translators: 1: processed records, 2: total records. */
          status.textContent = sprintf(__("%1$d of %2$d records processed.", "easyrankly"), data.processed, data.total);
        }
        runBatch();
      })
      .catch(function () {
        stop(__("The connection was interrupted. Reload this page to continue from the saved checkpoint.", "easyrankly"));
      });
  }

  window.addEventListener("beforeunload", warnBeforeLeaving);
  runBatch();
})();
