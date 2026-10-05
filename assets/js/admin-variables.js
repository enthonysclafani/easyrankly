(function (ER) {
  "use strict";

  var documentClickBound = false;
  var activeField = null;
  var sharedMenu = null;
  var visibleOptions = [];
  var activeIndex = -1;

  function closeVariablePicker(field) {
    if (!field || field !== activeField || !sharedMenu) {
      return;
    }
    sharedMenu.hidden = true;
    var control = field.querySelector('input:not([type="search"]), textarea');
    if (control) {
      control.setAttribute("aria-expanded", "false");
      control.removeAttribute("aria-activedescendant");
    }
    sharedMenu.querySelectorAll('[aria-selected="true"]').forEach(function (option) {
      option.setAttribute("aria-selected", "false");
      option.classList.remove("is-active");
    });
    activeField = null;
    activeIndex = -1;
  }

  // Reads the "word" the caret currently sits in (from the previous whitespace
  // up to the caret), the fragment the suggestions filter against, mirroring
  // the Redirect rules search filter behaviour.
  function getActiveVariableToken(control) {
    var value = control.value;
    var caret =
      typeof control.selectionStart === "number"
        ? control.selectionStart
        : value.length;
    var start = caret;

    while (start > 0 && !/\s/.test(value.charAt(start - 1))) {
      start--;
    }

    return { start: start, end: caret, text: value.slice(start, caret) };
  }

  // Toggles each option's visibility against the active token and returns the
  // list of options still visible (used for keyboard navigation).
  function filterVariablePicker(field, token) {
    var query = (token || "").trim().toLowerCase();
    var visible = [];

    var preview = field.querySelector("[data-erankly-variable-preview]");
    var groups;
    try {
      groups = JSON.parse(preview && preview.getAttribute("data-erankly-variable-groups"));
    } catch (e) {
      groups = null;
    }
    var menu = field.querySelector("[data-erankly-variable-menu]");
    if (!menu) {
      return visible;
    }
    menu.querySelectorAll("[data-erankly-variable-group]").forEach(function (group) {
      var allowed = !groups || groups.indexOf(group.getAttribute("data-erankly-variable-group")) !== -1;
      var hasVisible = false;
      group.querySelectorAll("[data-erankly-variable]").forEach(function (option) {
        var haystack = option.getAttribute("data-erankly-variable-search-text") || "";
        var isVisible = allowed && (!query || haystack.indexOf(query) !== -1);
        option.hidden = !isVisible;
        option.classList.remove("is-active");
        option.setAttribute("aria-selected", "false");
        if (isVisible) {
          visible.push(option);
          hasVisible = true;
        }
      });
      group.hidden = !hasVisible;
    });

    return visible;
  }

  // Replaces the active token with the chosen {{variable}} (so a partially
  // typed "site" becomes "{{site_name}}"), then places the caret right after it.
  function insertVariable(control, variable, token) {
    var value = control.value;
    var start = token ? token.start : value.length;
    var end = token ? token.end : value.length;

    control.value = value.slice(0, start) + variable + value.slice(end);
    control.focus();

    if (typeof control.setSelectionRange === "function") {
      control.setSelectionRange(
        start + variable.length,
        start + variable.length,
      );
    }

    control.dispatchEvent(new Event("input", { bubbles: true }));
    control.dispatchEvent(new Event("change", { bubbles: true }));
  }

  var VARIABLE_TOKEN_PATTERN = /{{\s*([a-z0-9_]+)\s*}}/gi;

  // Label drawn in place of a token that has no example value to stand in for
  // it (e.g. {{term_description}} on a term with an empty description).
  function getVariableUnavailableLabel() {
    var config = window.eranklyVariablePreview;

    return (config && config.unavailableLabel) || "Preview not defined";
  }

  function buildVariablePreviewValues(examples, siteName, siteDescription) {
    var resolved = examples ? Object.assign({}, examples) : {};

    if (siteName && !Object.prototype.hasOwnProperty.call(resolved, "site_name")) {
      resolved.site_name = siteName;
    }

    if (
      siteDescription &&
      !Object.prototype.hasOwnProperty.call(resolved, "site_description")
    ) {
      resolved.site_description = siteDescription;
    }

    return resolved;
  }

  // Shows a resolved friendly value (e.g. the real site name or tagline, or
  // the first post's title as a stand-in for {{post_title}} on fields that
  // aren't tied to any single post) over a {{variable}} field while it's
  // blurred, and reveals the raw token again on focus so it stays editable.
  // Only touches the overlay text node, never control.value itself. The
  // autosave serializer (bindSettingsAutosave) reads field.value straight
  // off the DOM, so swapping the real value would risk saving the resolved
  // text instead of the token on a mistimed autosave. Any token with no
  // example (e.g. a post type with no published posts yet) previews as the
  // "Preview not defined" label instead of the literal token.
  function resolveVariablePreviewText(
    raw,
    examples,
    siteName,
    siteDescription,
  ) {
    var resolved = buildVariablePreviewValues(
      examples,
      siteName,
      siteDescription,
    );

    return raw.replace(VARIABLE_TOKEN_PATTERN, function (match, key) {
      var normalizedKey = key.toLowerCase();

      if (Object.prototype.hasOwnProperty.call(resolved, normalizedKey)) {
        return resolved[normalizedKey];
      }

      return getVariableUnavailableLabel();
    });
  }

  // Paints the overlay as DOM nodes rather than one text run so unresolvable
  // tokens can carry their own colour. Returns false when raw holds no token
  // at all, i.e. there is nothing to preview and the field shows itself.
  function renderVariablePreview(
    preview,
    raw,
    examples,
    siteName,
    siteDescription,
  ) {
    var resolved = buildVariablePreviewValues(
      examples,
      siteName,
      siteDescription,
    );
    var pattern = new RegExp(VARIABLE_TOKEN_PATTERN.source, "gi");
    var text = document.createElement("span");
    var lastIndex = 0;
    var found = false;
    var match;

    text.className = "erankly-variable-preview-text";

    while ((match = pattern.exec(raw)) !== null) {
      var normalizedKey = match[1].toLowerCase();

      if (match.index > lastIndex) {
        text.appendChild(
          document.createTextNode(raw.slice(lastIndex, match.index)),
        );
      }

      if (Object.prototype.hasOwnProperty.call(resolved, normalizedKey)) {
        text.appendChild(document.createTextNode(resolved[normalizedKey]));
      } else {
        var unavailable = document.createElement("span");

        unavailable.className = "erankly-variable-preview-unavailable";
        unavailable.textContent = getVariableUnavailableLabel();
        text.appendChild(unavailable);
      }

      found = true;
      lastIndex = pattern.lastIndex;
    }

    if (!found) {
      return false;
    }

    if (lastIndex < raw.length) {
      text.appendChild(document.createTextNode(raw.slice(lastIndex)));
    }

    preview.textContent = "";
    preview.appendChild(text);

    return true;
  }

  function bindVariablePreview(field, control) {
    var preview = field.querySelector("[data-erankly-variable-preview]");
    var config = window.eranklyVariablePreview;

    if (!preview || !control || !config) {
      return;
    }

    var examples = null;
    var rawExamples = preview.getAttribute("data-erankly-variable-examples");

    if (rawExamples) {
      try {
        examples = JSON.parse(rawExamples);
      } catch (e) {
        examples = null;
      }
    }

    function update() {
      var raw = control.value;

      if (
        raw &&
        renderVariablePreview(
          preview,
          raw,
          examples,
          config.siteName,
          config.siteDescription,
        )
      ) {
        field.classList.add("erankly-is-previewing");
      } else {
        field.classList.remove("erankly-is-previewing");
      }
    }

    control.addEventListener("focus", function () {
      field.classList.remove("erankly-is-previewing");
    });

    control.addEventListener("blur", update);

    update();
  }

  function highlightVariable(control, index) {
    if (activeIndex >= 0 && visibleOptions[activeIndex]) {
      visibleOptions[activeIndex].classList.remove("is-active");
      visibleOptions[activeIndex].setAttribute("aria-selected", "false");
    }
    activeIndex = index;
    var option = visibleOptions[index];
    if (option) {
      option.classList.add("is-active");
      option.setAttribute("aria-selected", "true");
      control.setAttribute("aria-activedescendant", option.id);
      option.scrollIntoView({ block: "nearest" });
    } else {
      control.removeAttribute("aria-activedescendant");
    }
  }

  function bindVariablePicker(field) {
    bindVariablePickerDocumentListener();
    var control = field.querySelector('input:not([type="search"]), textarea');
    if (!control || !sharedMenu || field.getAttribute("data-erankly-variable-bound") === "true") {
      return;
    }
    field.setAttribute("data-erankly-variable-bound", "true");
    bindVariablePreview(field, control);
    control.setAttribute("role", "combobox");
    control.setAttribute("aria-autocomplete", "list");
    control.setAttribute("aria-controls", sharedMenu.id);
    control.setAttribute("aria-haspopup", "listbox");
    control.setAttribute("aria-expanded", "false");

    function openMenu() {
      if (activeField !== field) {
        closeVariablePicker(activeField);
        field.appendChild(sharedMenu);
        activeField = field;
      }
      control.removeAttribute("aria-activedescendant");
      visibleOptions = filterVariablePicker(field, getActiveVariableToken(control).text);
      activeIndex = -1;
      if (!visibleOptions.length) {
        closeVariablePicker(field);
        return;
      }
      sharedMenu.hidden = false;
      control.setAttribute("aria-expanded", "true");
    }
    control.addEventListener("focus", openMenu);
    control.addEventListener("click", openMenu);
    control.addEventListener("input", openMenu);
    control.addEventListener("keydown", function (event) {
      if (activeField !== field || sharedMenu.hidden) {
        return;
      }
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        highlightVariable(control, event.key === "ArrowDown"
          ? Math.min(activeIndex + 1, visibleOptions.length - 1)
          : Math.max(activeIndex - 1, 0));
      } else if (event.key === "Enter" && visibleOptions[activeIndex]) {
        event.preventDefault();
        insertVariable(control, visibleOptions[activeIndex].getAttribute("data-erankly-variable") || "", getActiveVariableToken(control));
        closeVariablePicker(field);
      } else if (event.key === "Escape" || event.key === "Tab") {
        closeVariablePicker(field);
      }
    });
  }

  function bindVariablePickerDocumentListener() {
    if (documentClickBound) {
      return;
    }
    sharedMenu = document.querySelector("[data-erankly-variable-menu]");
    if (!sharedMenu) {
      return;
    }
    documentClickBound = true;
    sharedMenu.querySelectorAll("[data-erankly-variable]").forEach(function (option, index) {
      option.id = sharedMenu.id + "-option-" + index;
      option.setAttribute("aria-selected", "false");
    });
    sharedMenu.addEventListener("mousedown", function (event) {
      event.preventDefault(); // Preserve the control's caret when choosing an option.
    });
    sharedMenu.addEventListener("click", function (event) {
      var option = event.target.closest("[data-erankly-variable]");
      if (!option || !activeField || option.hidden) {
        return;
      }
      var field = activeField;
      var control = field.querySelector('input:not([type="search"]), textarea');
      insertVariable(control, option.getAttribute("data-erankly-variable") || "", getActiveVariableToken(control));
      closeVariablePicker(field);
    });
    function closeOutside(event) {
      if (activeField && !activeField.contains(event.target)) {
        closeVariablePicker(activeField);
      }
    }
    document.addEventListener("click", closeOutside);
    document.addEventListener("focusin", closeOutside);
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        closeVariablePicker(activeField);
      }
    });
  }

  function bindVariablePickers(container) {
    bindVariablePickerDocumentListener();
    container.querySelectorAll("[data-erankly-variable-field]").forEach(bindVariablePicker);
  }

  ER.closeVariablePicker = closeVariablePicker;
  ER.getActiveVariableToken = getActiveVariableToken;
  ER.filterVariablePicker = filterVariablePicker;
  ER.insertVariable = insertVariable;
  ER.resolveVariablePreviewText = resolveVariablePreviewText;
  ER.bindVariablePreview = bindVariablePreview;
  ER.renderVariablePreview = renderVariablePreview;
  ER.bindVariablePicker = bindVariablePicker;
  ER.bindVariablePickers = bindVariablePickers;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
