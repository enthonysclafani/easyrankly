(function () {
  "use strict";

  var marker = document.querySelector("[data-erankly-migration-autoreload]");
  if (!marker) {
    return;
  }

  var delay = parseInt(marker.getAttribute("data-erankly-migration-autoreload"), 10);
  if (!delay || delay < 1) {
    delay = 15000;
  }

  window.setTimeout(function () {
    window.location.reload();
  }, delay);
})();
