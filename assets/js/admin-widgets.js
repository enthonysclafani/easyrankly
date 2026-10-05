(function (ER) {
  "use strict";

  function bindLocalBusiness(container) {
    var toggle = container.querySelector(
      "[data-erankly-local-business-toggle]",
    );
    var fields = container.querySelector(
      "[data-erankly-local-business-fields]",
    );
    var type = container.querySelector("[data-erankly-local-business-type]");
    var foodFields = container.querySelector(
      "[data-erankly-food-business-fields]",
    );
    var foodTypes = [
      "Restaurant",
      "CafeOrCoffeeShop",
      "BarOrPub",
      "Bakery",
      "FoodEstablishment",
    ];
    var siteRoot = container.querySelector(
      "[data-erankly-local-business-sites]",
    );
    var config = window.eranklyLocalBusiness;
    var i18n = (config && config.i18n) || {};
    var sitesSeq = 0;
    var sitesInFlight = false;

    if (!toggle || !fields) {
      return;
    }

    function pageLabel(page) {
      var title = page && page.title ? String(page.title) : "";
      var path = page && page.path ? String(page.path) : "";
      var id = page && page.id ? String(page.id) : "";
      return (title + " (" + path + ") [#" + id + "]").trim();
    }

    function pageWindowOffset(pages) {
      var limit = config.pageLimit || 50;
      return Math.min((pages || []).length, limit);
    }

    function joinUrl(url, query) {
      var joiner = url.indexOf("?") === -1 ? "?" : "&";
      return url + joiner + query;
    }

    function setStatus(node, message, retryFn) {
      if (!node) {
        return;
      }
      node.textContent = message || "";
      if (typeof retryFn === "function" && message) {
        node.appendChild(document.createTextNode(" "));
        var retry = document.createElement("button");
        retry.type = "button";
        retry.className = "button-link";
        retry.textContent = i18n.retry || "Retry";
        retry.addEventListener("click", retryFn);
        node.appendChild(retry);
      }
    }

    function selectedValue(select) {
      return select ? String(select.value || "") : "";
    }

    function ensureOption(select, page, selected) {
      if (!select || !page || !page.id) {
        return;
      }
      var value = String(page.id);
      var existing = select.querySelector('option[value="' + value + '"]');
      if (existing) {
        existing.textContent = pageLabel(page);
        if (selected && value === selected) {
          existing.selected = true;
        }
        return;
      }
      var option = document.createElement("option");
      option.value = value;
      option.textContent = pageLabel(page);
      if (selected && value === selected) {
        option.selected = true;
      }
      select.appendChild(option);
    }

    function fillSelect(select, pages, append) {
      var current = selectedValue(select);
      var currentOption = current
        ? select.querySelector('option[value="' + current + '"]')
        : null;
      var kept = currentOption
        ? { id: current, title: "", path: "", label: currentOption.textContent }
        : null;

      if (!append) {
        select.innerHTML = "";
        var empty = document.createElement("option");
        empty.value = "";
        empty.textContent = i18n.selectPage || "";
        select.appendChild(empty);
        if (kept) {
          var retained = document.createElement("option");
          retained.value = kept.id;
          retained.textContent = kept.label;
          retained.selected = true;
          select.appendChild(retained);
        }
      }

      (pages || []).forEach(function (page) {
        ensureOption(select, page, current);
      });

      if (current) {
        select.value = current;
      }
    }

    function bindPagePicker(wrap) {
      if (!wrap || wrap.getAttribute("data-erankly-page-picker-bound") === "true") {
        return;
      }
      wrap.setAttribute("data-erankly-page-picker-bound", "true");

      var select = wrap.querySelector("[data-erankly-local-business-page-select]");
      var search = wrap.querySelector("[data-erankly-local-business-page-search]");
      var loadMore = wrap.querySelector(
        "[data-erankly-local-business-load-more-pages]",
      );
      var status = wrap.querySelector("[data-erankly-local-business-page-status]");
      var seq = 0;
      var debounceTimer = null;

      if (!select) {
        return;
      }

      function currentQuery() {
        return search ? search.value.trim() : "";
      }

      function fetchPages(opts) {
        opts = opts || {};
        if (!config || !config.pagesUrl || !config.nonce) {
          return;
        }
        var blogId = wrap.getAttribute("data-erankly-local-business-site") || "";
        if (!blogId) {
          return;
        }
        var requestSeq = ++seq;
        var offset = opts.append
          ? parseInt(wrap.getAttribute("data-erankly-page-offset") || "0", 10)
          : 0;
        if (!offset || offset < 0) {
          offset = 0;
        }
        var query = currentQuery();
        var url = joinUrl(
          config.pagesUrl,
          "blog_id=" +
            encodeURIComponent(blogId) +
            "&offset=" +
            encodeURIComponent(String(offset)) +
            "&q=" +
            encodeURIComponent(query),
        );

        if (loadMore) {
          loadMore.disabled = true;
        }
        setStatus(status, i18n.loading || "");

        fetch(url, {
          credentials: "same-origin",
          headers: { "X-WP-Nonce": config.nonce },
        })
          .then(function (response) {
            if (!response.ok) {
              throw new Error("request failed");
            }
            return response.json();
          })
          .then(function (payload) {
            if (requestSeq !== seq) {
              return;
            }
            if (
              payload &&
              payload.blog_id &&
              String(payload.blog_id) !== String(blogId)
            ) {
              return;
            }
            var pages = payload && payload.pages ? payload.pages : [];
            fillSelect(select, pages, !!opts.append);
            wrap.setAttribute(
              "data-erankly-page-offset",
              String(
                payload && payload.nextOffset
                  ? payload.nextOffset
                  : offset + pageWindowOffset(pages),
              ),
            );
            if (loadMore) {
              loadMore.hidden = !payload || !payload.hasMore;
              loadMore.disabled = false;
            }
            if (!pages.length && !opts.append) {
              setStatus(status, i18n.noResults || "");
            } else {
              setStatus(status, "");
            }
            select.dispatchEvent(new Event("input", { bubbles: true }));
          })
          .catch(function () {
            if (requestSeq !== seq) {
              return;
            }
            if (loadMore) {
              loadMore.disabled = false;
            }
            setStatus(status, i18n.pagesFailed || i18n.requestFailed || "", function () {
              fetchPages(opts);
            });
          });
      }

      if (search) {
        search.addEventListener("input", function () {
          clearTimeout(debounceTimer);
          debounceTimer = setTimeout(function () {
            wrap.setAttribute("data-erankly-page-offset", "0");
            fetchPages({ append: false });
          }, 300);
        });
        search.addEventListener("keydown", function (e) {
          if (e.key === "Escape") {
            search.value = "";
            wrap.setAttribute("data-erankly-page-offset", "0");
            fetchPages({ append: false });
          }
        });
      }

      if (loadMore) {
        loadMore.addEventListener("click", function () {
          fetchPages({ append: true });
        });
      }
    }

    function appendSite(list, site) {
      var blogId = String(site.blog_id || "");
      if (!blogId || list.querySelector('[data-erankly-local-business-site="' + blogId + '"]')) {
        return;
      }

      var hidden = siteRoot.querySelector(
        'input[type="hidden"][name="' +
          config.option +
          "[local_business_pages][" +
          blogId +
          ']"]',
      );
      var selected = hidden ? hidden.value : "";
      if (hidden) {
        hidden.parentNode.removeChild(hidden);
      }

      var wrap = document.createElement("div");
      wrap.className = "erankly-field";
      wrap.setAttribute("data-erankly-local-business-site", blogId);
      wrap.setAttribute(
        "data-erankly-page-offset",
        String(pageWindowOffset(site.pages)),
      );

      var fieldId = "erankly-local-business-page-" + blogId;
      var label = document.createElement("label");
      label.setAttribute("for", fieldId);
      label.textContent =
        String(site.name || "") +
        " (" +
        String(site.language || "") +
        ") — " +
        String(site.path || "/");

      var picker = document.createElement("div");
      picker.className = "erankly-local-business-page-picker";

      var searchLabel = document.createElement("label");
      searchLabel.className = "screen-reader-text";
      searchLabel.setAttribute("for", fieldId + "-search");
      searchLabel.textContent = i18n.searchPages || "";

      var search = document.createElement("input");
      search.id = fieldId + "-search";
      search.type = "search";
      search.className = "widefat";
      search.setAttribute("data-erankly-local-business-page-search", "");
      search.setAttribute("autocomplete", "off");

      var select = document.createElement("select");
      select.id = fieldId;
      select.className = "widefat erankly-field-full-width";
      select.name = config.option + "[local_business_pages][" + blogId + "]";
      select.setAttribute("data-erankly-local-business-page-select", "");

      var empty = document.createElement("option");
      empty.value = "";
      empty.textContent = i18n.selectPage || "";
      select.appendChild(empty);

      (site.pages || []).forEach(function (page) {
        ensureOption(select, page, selected);
      });

      var loadMorePages = document.createElement("button");
      loadMorePages.type = "button";
      loadMorePages.className = "button";
      loadMorePages.setAttribute("data-erankly-local-business-load-more-pages", "");
      loadMorePages.textContent = i18n.loadMorePages || "";
      loadMorePages.hidden = (site.pages || []).length < (config.pageLimit || 50);

      var status = document.createElement("p");
      status.className = "description";
      status.setAttribute("data-erankly-local-business-page-status", "");
      status.setAttribute("role", "status");

      picker.appendChild(searchLabel);
      picker.appendChild(search);
      picker.appendChild(select);
      picker.appendChild(loadMorePages);
      picker.appendChild(status);
      wrap.appendChild(label);
      wrap.appendChild(picker);
      list.appendChild(wrap);
      bindPagePicker(wrap);
    }

    function bindAllPagePickers() {
      if (!siteRoot) {
        return;
      }
      siteRoot
        .querySelectorAll("[data-erankly-local-business-site]")
        .forEach(bindPagePicker);
    }

    function loadSites(after, options) {
      options = options || {};
      if (!siteRoot || !config || !config.sitesUrl || !config.nonce) {
        return;
      }
      if (sitesInFlight && !options.retry) {
        return;
      }

      var list = siteRoot.querySelector(
        "[data-erankly-local-business-site-list]",
      );
      var loadMore = siteRoot.querySelector(
        "[data-erankly-local-business-load-more]",
      );
      var status = siteRoot.querySelector(
        "[data-erankly-local-business-sites-status]",
      );
      var requestSeq = ++sitesSeq;
      var url = joinUrl(
        config.sitesUrl,
        "after=" + encodeURIComponent(after || "0"),
      );

      sitesInFlight = true;
      if (loadMore) {
        loadMore.disabled = true;
      }
      setStatus(status, i18n.loading || "");

      fetch(url, {
        credentials: "same-origin",
        headers: { "X-WP-Nonce": config.nonce },
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error("request failed");
          }
          return response.json();
        })
        .then(function (payload) {
          if (requestSeq !== sitesSeq) {
            return;
          }
          var sites = payload && payload.sites ? payload.sites : [];
          sites.forEach(function (site) {
            appendSite(list, site);
          });
          if (sites.length) {
            siteRoot.setAttribute(
              "data-erankly-after",
              String(sites[sites.length - 1].blog_id || after || "0"),
            );
          }
          siteRoot.setAttribute("data-erankly-sites-initialized", "1");
          if (loadMore) {
            loadMore.hidden = !payload || !payload.hasMore;
            loadMore.disabled = false;
          }
          setStatus(status, "");
        })
        .catch(function () {
          if (requestSeq !== sitesSeq) {
            return;
          }
          if (loadMore) {
            loadMore.disabled = false;
            loadMore.hidden = false;
          }
          setStatus(status, i18n.requestFailed || "", function () {
            loadSites(after, { retry: true });
          });
        })
        .then(function () {
          if (requestSeq === sitesSeq) {
            sitesInFlight = false;
          }
        });
    }

    function ensureSitesLoaded() {
      if (!toggle.checked || !siteRoot) {
        return;
      }
      bindAllPagePickers();
      if (siteRoot.getAttribute("data-erankly-sites-initialized") === "1") {
        return;
      }
      loadSites(siteRoot.getAttribute("data-erankly-after") || "0");
    }

    function syncVisibility() {
      fields.hidden = !toggle.checked;
      var root = container.closest(".erankly-settings");
      if (root) {
        if (toggle.checked) {
          root.setAttribute("data-erankly-local-business-enabled", "1");
        } else {
          root.removeAttribute("data-erankly-local-business-enabled");
        }
      }
      if (typeof ER.syncOrganizationFieldsVisibility === "function") {
        ER.syncOrganizationFieldsVisibility(root);
      }

      if (type && foodFields) {
        foodFields.hidden = foodTypes.indexOf(type.value) === -1;
      }

      if (toggle.checked) {
        ensureSitesLoaded();
      }
    }

    toggle.addEventListener("change", syncVisibility);

    if (type) {
      type.addEventListener("change", syncVisibility);
    }

    container
      .querySelectorAll("[data-erankly-opening-day]")
      .forEach(function (day) {
        var closed = day.querySelector("[data-erankly-day-closed]");
        var intervals = day.querySelectorAll("[data-erankly-opening-intervals]");

        if (!closed || !intervals.length) {
          return;
        }

        function syncDay() {
          day.classList.toggle("is-closed", closed.checked);
          intervals.forEach(function (cell) {
            if (closed.checked) {
              cell.setAttribute("aria-hidden", "true");
            } else {
              cell.removeAttribute("aria-hidden");
            }
          });
        }

        closed.addEventListener("change", syncDay);
        syncDay();
      });

    if (siteRoot) {
      var loadMoreSites = siteRoot.querySelector(
        "[data-erankly-local-business-load-more]",
      );
      if (loadMoreSites) {
        loadMoreSites.addEventListener("click", function () {
          loadSites(siteRoot.getAttribute("data-erankly-after") || "0");
        });
      }
    }

    bindAllPagePickers();
    syncVisibility();
  }

  ER.bindLocalBusiness = bindLocalBusiness;
})(window.ERanklyAdmin = window.ERanklyAdmin || {});
