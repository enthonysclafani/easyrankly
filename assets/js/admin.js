(function () {
  "use strict";

  var ER = window.ERanklyAdmin || {};

  function bindEach(selector, callbackName) {
    if (typeof ER[callbackName] !== "function") {
      return;
    }

    document.querySelectorAll(selector).forEach(ER[callbackName]);
  }

  function bindRoot(callbackName) {
    bindEach(".erankly-settings", callbackName);
  }

  document.addEventListener("DOMContentLoaded", function () {
    bindEach("[data-erankly-expandable]", "bindExpandablePanel");
    bindRoot("bindTabs");
    bindRoot("bindSettingsTabs");
    bindEach("[data-erankly-media-url-field]", "bindMediaUrlField");
    bindEach(".erankly-counted-field", "bindCharacterCounter");
    if (typeof ER.bindVariablePickers === "function") {
      ER.bindVariablePickers(document);
    }
    bindEach("[data-erankly-linked-defaults]", "bindLinkedDefaults");
    bindEach(
      "[data-erankly-schema-builder], [data-erankly-code-builder]",
      "bindSchemaBuilder",
    );
    bindEach("[data-erankly-post-schema]", "bindPostSchemaPanel");
    bindEach("[data-erankly-schema-identity]", "bindSchemaIdentityField");
    bindEach("[data-erankly-user-search-wrap]", "bindUserSearch");
    bindEach("[data-erankly-local-business]", "bindLocalBusiness");
    bindEach("[data-erankly-file-dropzone]", "bindFileDropzone");
    bindEach('[data-erankly-settings-panel="settings-sitemap"]', "bindNewsSitemapSettings");

    bindRoot("bindAllSettingsAutosave");
    bindEach("[data-erankly-database-tools]", "bindDatabaseTools");


  });

})();
