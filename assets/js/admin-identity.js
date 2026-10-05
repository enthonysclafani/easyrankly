(function (ER) {
  "use strict";

  function bindSchemaIdentityField(field) {
    var container = field.closest(".erankly-settings");
    var personField = container
      ? container.querySelector("[data-erankly-person-reference-field]")
      : null;
    var selector = container
      ? container.querySelector("[data-erankly-identity-selector]")
      : null;
    var options = selector
      ? selector.querySelectorAll("[data-erankly-identity-option]")
      : [];

    if (!personField) {
      return;
    }

    function updatePersonField() {
      options.forEach(function (option) {
        var isActive =
          option.getAttribute("data-erankly-identity-option") === field.value;
        option.classList.toggle("is-active", isActive);
        option.setAttribute("aria-pressed", isActive ? "true" : "false");
      });
      personField.hidden = field.value !== "person";
      syncOrganizationFieldsVisibility(container);
    }

    options.forEach(function (option) {
      option.addEventListener("click", function () {
        var value = option.getAttribute("data-erankly-identity-option");
        if (field.value === value) {
          return;
        }
        field.value = value;
        field.dispatchEvent(new Event("change", { bubbles: true }));
      });
    });

    field.addEventListener("change", updatePersonField);
    updatePersonField();
  }

  function localBusinessIsEnabled(container) {
    if (!container) {
      return false;
    }

    var toggle = container.querySelector("[data-erankly-local-business-toggle]");
    if (toggle) {
      return !!toggle.checked;
    }

    return container.getAttribute("data-erankly-local-business-enabled") === "1";
  }

  function syncIdentityLabels(container, isPerson) {
    container.querySelectorAll("[data-erankly-identity-label]").forEach(function (node) {
      var label = isPerson
        ? node.getAttribute("data-erankly-label-person")
        : node.getAttribute("data-erankly-label-organization");
      if (label) {
        node.textContent = label;
      }
    });
  }

  function syncOrganizationFieldsVisibility(container) {
    var identity = container
      ? container.querySelector("[data-erankly-schema-identity]")
      : null;
    var isPerson = !!(identity && identity.value === "person");
    var showOrganizationFields = identity && !isPerson;
    var showLocationFields =
      identity && (!isPerson || localBusinessIsEnabled(container));

    if (!identity) {
      return;
    }

    container
      .querySelectorAll("[data-erankly-organization-only]")
      .forEach(function (fields) {
        fields.hidden = !showOrganizationFields;
      });
    container
      .querySelectorAll("[data-erankly-location-fields]")
      .forEach(function (fields) {
        fields.hidden = !showLocationFields;
      });
    syncIdentityLabels(container, isPerson);
  }

  ER.bindSchemaIdentityField = bindSchemaIdentityField;
  ER.syncOrganizationFieldsVisibility = syncOrganizationFieldsVisibility;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
