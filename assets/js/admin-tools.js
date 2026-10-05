(function (ER) {
  "use strict";

  ER.bindDatabaseTools = function (root) {
    if (root.dataset.toolsBound) return;
    root.dataset.toolsBound = "1";

    var options = root.querySelector("[data-tools-options]");
    var scanButton = root.querySelector("[data-tools-scan]");
    var previewButton = root.querySelector("[data-tools-preview]");
    var status = root.querySelector("[data-tools-status]");
    var dialog = root.querySelector("[data-tools-dialog]");
    var acknowledge = root.querySelector("[data-tools-acknowledge]");
    var confirmButton = root.querySelector("[data-tools-confirm]");
    var cancelButton = root.querySelector("[data-tools-cancel]");
    var progress = root.querySelector("[data-tools-progress]");
    var progressBar = root.querySelector("[data-tools-progress-bar]");
    var stopButton = root.querySelector("[data-tools-stop]");
    var results = root.querySelector("[data-tools-results]");
    var token = "";
    var busy = false;
    var stopRequested = false;
    var selectionInitialized = false;
    var i18n = {};
    root.querySelectorAll("[data-text]").forEach(function (node) {
      i18n[node.dataset.text] = node.textContent;
    });

    function message(text, error) {
      status.textContent = text;
      status.classList.toggle("is-error", !!error);
    }

    function selected() {
      return Array.from(options.querySelectorAll("[data-tools-category]:checked")).map(function (input) {
        return input.value;
      });
    }

    function updateButtons() {
      previewButton.disabled = busy || !selected().length;
      scanButton.disabled = busy;
      options.disabled = busy;
      root.querySelector("[data-tools-selection]").textContent = i18n.selected + " " + selected().length;
      root.setAttribute("aria-busy", busy ? "true" : "false");
    }

    async function request(mode, fields) {
      var body = new URLSearchParams({ action: "erankly_database_tools", mode: mode, nonce: root.dataset.nonce });
      Object.keys(fields || {}).forEach(function (key) {
        var value = fields[key];
        if (Array.isArray(value)) value.forEach(function (item) { body.append(key + "[]", item); });
        else body.append(key, String(value));
      });
      var response = await fetch(root.dataset.url, {
        method: "POST", credentials: "same-origin", cache: "no-store", body: body,
      });
      var json = await response.json();
      if (!response.ok || !json.success) throw new Error((json.data && json.data.message) || i18n.error);
      return json.data;
    }

    async function scan(quiet) {
      busy = true;
      updateButtons();
      if (!quiet) message(i18n.analyzing);
      try {
        var data = await request("scan");
        root.querySelectorAll("[data-tools-row]").forEach(function (row) {
          var item = data.categories[row.dataset.toolsRow];
          var input = row.querySelector("[data-tools-category]");
          input.disabled = !item.available || !item.count;
          if (input.disabled) input.checked = false;
          else if (!selectionInitialized) input.checked = input.dataset.recommended === "1";
          row.querySelector("[data-tools-count]").textContent = item.available ? item.count.toLocaleString() + (item.more ? "+" : "") : i18n.unavailable;
        });
        selectionInitialized = true;
        if (!quiet) message(i18n.analyzed);
      } catch (error) {
        message(error.message || i18n.error, true);
        // Old counts remain informational; a failed re-analysis never enables deletion.
        options.querySelectorAll("[data-tools-category]").forEach(function (input) { input.checked = false; input.disabled = true; });
      } finally {
        busy = false;
        updateButtons();
      }
    }

    function closePreview() {
      if (dialog.open) dialog.close();
      var previous = token;
      token = "";
      acknowledge.checked = false;
      confirmButton.disabled = true;
      previewButton.focus();
      if (previous) request("cancel", { token: previous }).catch(function () {});
    }

    function renderPreview(data) {
      var summary = root.querySelector("[data-tools-summary]");
      summary.replaceChildren();
      data.groups.filter(function (group) { return group.count > 0; }).forEach(function (group) {
        var details = document.createElement("details");
        var title = document.createElement("summary");
        title.textContent = group.label + " · " + group.count.toLocaleString();
        details.appendChild(title);
        if (group.metadata || group.revisions) {
          var related = document.createElement("p");
          related.textContent = i18n.related + " " + group.metadata + " / " + group.revisions;
          details.appendChild(related);
        }
        var list = document.createElement("ul");
        group.items.forEach(function (label) {
          var item = document.createElement("li");
          item.textContent = label;
          list.appendChild(item);
        });
        details.appendChild(list);
        summary.appendChild(details);
      });
      root.querySelector("[data-tools-limited]").hidden = !data.limited;
      acknowledge.checked = false;
      confirmButton.disabled = true;
      token = data.token;
      dialog.showModal();
      cancelButton.focus();
    }

    function renderResults(data) {
      var rows = root.querySelector("[data-tools-result-rows]");
      rows.replaceChildren();
      data.results.forEach(function (result) {
        var tr = document.createElement("tr");
        [result.label, result.removed, result.skipped, result.failed].forEach(function (value, index) {
          var td = document.createElement(index === 0 ? "th" : "td");
          if (index === 0) td.scope = "row";
          td.textContent = String(value);
          tr.appendChild(td);
        });
        rows.appendChild(tr);
      });
      results.hidden = false;
      progressBar.max = Math.max(1, data.total);
      progressBar.value = data.cursor;
    }

    options.addEventListener("change", updateButtons);
    scanButton.addEventListener("click", function () { scan(false); });
    previewButton.addEventListener("click", async function () {
      busy = true;
      updateButtons();
      message(i18n.previewing);
      try {
        var data = await request("preview", { categories: selected() });
        if (!data.count) message(i18n.empty);
        else { message(i18n.analyzed); renderPreview(data); }
      } catch (error) { message(error.message || i18n.error, true); }
      finally { busy = false; updateButtons(); }
    });
    acknowledge.addEventListener("change", function () { confirmButton.disabled = !acknowledge.checked || !token; });
    cancelButton.addEventListener("click", closePreview);
    dialog.addEventListener("cancel", function (event) { event.preventDefault(); closePreview(); });
    stopButton.addEventListener("click", function () {
      stopRequested = true;
      stopButton.disabled = true;
      message(i18n.stopping);
    });
    confirmButton.addEventListener("click", async function () {
      if (!token || !acknowledge.checked || busy) return;
      var cleanupToken = token;
      token = "";
      dialog.close();
      busy = true;
      stopRequested = false;
      stopButton.disabled = false;
      progress.hidden = false;
      results.hidden = true;
      progressBar.value = 0;
      updateButtons();
      message(i18n.cleaning);
      var cursor = 0;
      try {
        while (true) {
          var data = await request("clean", { token: cleanupToken, confirmed: "1", offset: cursor });
          renderResults(data);
          cursor = data.cursor;
          if (data.done || stopRequested) break;
        }
        if (stopRequested && !data.done) {
          renderResults(await request("cancel", { token: cleanupToken }));
          message(i18n.stopped);
        } else message(i18n.complete);
        results.focus();
      } catch (error) { message(error.message || i18n.error, true); }
      finally {
        progress.hidden = true;
        busy = false;
        updateButtons();
        await scan(true);
      }
    });

    // Unsupported dialog browsers can still analyze; deletion stays disabled.
    if (typeof dialog.showModal !== "function") {
      previewButton.hidden = true;
    }
    scan(false);
  };
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
