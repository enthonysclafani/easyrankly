#!/usr/bin/env node
/**
 * DOM probe for LocalBusiness N1/N2: enable loads the first site batch, pagesUrl is consumed,
 * repeated toggles do not refetch, selected values survive search, stale responses are ignored.
 */
"use strict";

const fs = require("fs");
const path = require("path");
const vm = require("vm");

class FakeNode {
  constructor(tagName, document) {
    this.tagName = String(tagName || "").toUpperCase();
    this.document = document;
    this.children = [];
    this.attributes = {};
    this.listeners = {};
    this.parentNode = null;
    this.value = "";
    this.hidden = false;
    this.disabled = false;
    this.checked = false;
    this.textContent = "";
    this.className = "";
    this.id = "";
    this.name = "";
    this.type = String(tagName || "").toLowerCase();
    this.placeholder = "";
    this.isConnected = true;
    this._innerHTML = "";
  }

  set innerHTML(value) {
    this._innerHTML = String(value);
    if (this._innerHTML === "") {
      this.children.forEach((child) => {
        child.parentNode = null;
      });
      this.children = [];
    }
  }

  get innerHTML() {
    return this._innerHTML;
  }

  setAttribute(name, value) {
    this.attributes[name] = String(value);
    if (name === "id") {
      this.id = String(value);
    }
    if (name === "name") {
      this.name = String(value);
    }
    if (name === "class") {
      this.className = String(value);
    }
  }

  getAttribute(name) {
    if (Object.prototype.hasOwnProperty.call(this.attributes, name)) {
      return this.attributes[name];
    }
    if (name === "value") {
      return String(this.value);
    }
    if (name === "id" && this.id) {
      return this.id;
    }
    return null;
  }

  appendChild(child) {
    child.parentNode = this;
    child.document = this.document;
    this.children.push(child);
    return child;
  }

  removeChild(child) {
    this.children = this.children.filter((item) => item !== child);
    child.parentNode = null;
    return child;
  }

  addEventListener(type, fn) {
    this.listeners[type] = this.listeners[type] || [];
    this.listeners[type].push(fn);
  }

  dispatchEvent(event) {
    const type = event.type;
    (this.listeners[type] || []).forEach((fn) => fn.call(this, event));
    if (event.bubbles && this.parentNode) {
      this.parentNode.dispatchEvent(event);
    }
  }

  matches(selector) {
    return matchesSelector(this, selector);
  }

  querySelector(selector) {
    const all = this.querySelectorAll(selector);
    return all[0] || null;
  }

  querySelectorAll(selector) {
    const found = [];
    walk(this, (node) => {
      if (node !== this && node.matches(selector)) {
        found.push(node);
      }
    });
    found.forEach = Array.prototype.forEach;
    return found;
  }

  closest(selector) {
    let node = this;
    while (node) {
      if (node.matches(selector)) {
        return node;
      }
      node = node.parentNode;
    }
    return null;
  }

  contains(node) {
    if (node === this) {
      return true;
    }
    return this.children.some((child) => child.contains(node));
  }
}

function walk(node, visit) {
  visit(node);
  node.children.forEach((child) => walk(child, visit));
}

function matchesSelector(node, selector) {
  selector = String(selector || "").trim();
  const tagged = selector.match(/^([a-z][\w-]*)(\[.+\])$/i);
  if (tagged) {
    return node.tagName === tagged[1].toUpperCase() && matchesSelector(node, tagged[2]);
  }
  if (selector.startsWith(".")) {
    return (" " + node.className + " ").indexOf(" " + selector.slice(1) + " ") !== -1;
  }
  const attrEq = selector.match(/^\[([^\]]+?)="([^"]*)"\]$/);
  if (attrEq) {
    return node.getAttribute(attrEq[1]) === attrEq[2];
  }
  const attr = selector.match(/^\[([^\]]+)\]$/);
  if (attr) {
    return node.getAttribute(attr[1]) !== null;
  }
  return node.tagName === selector.toUpperCase();
}

class FakeDocument {
  constructor() {
    this.body = new FakeNode("body", this);
    this.body.document = this;
  }

  createElement(tag) {
    const node = new FakeNode(tag, this);
    node.document = this;
    return node;
  }

  createTextNode(text) {
    const node = new FakeNode("#text", this);
    node.textContent = String(text);
    return node;
  }

  querySelector(selector) {
    return this.body.querySelector(selector);
  }

  querySelectorAll(selector) {
    return this.body.querySelectorAll(selector);
  }
}

function createEvent(type) {
  return { type: type, bubbles: true, preventDefault() {} };
}

function markup() {
  const document = new FakeDocument();
  const settings = document.createElement("div");
  settings.className = "erankly-settings";
  const root = document.createElement("div");
  root.setAttribute("data-erankly-local-business", "");
  const toggle = document.createElement("input");
  toggle.type = "checkbox";
  toggle.checked = false;
  toggle.setAttribute("data-erankly-local-business-toggle", "");
  const fields = document.createElement("div");
  fields.setAttribute("data-erankly-local-business-fields", "");
  fields.hidden = true;
  const sites = document.createElement("div");
  sites.setAttribute("data-erankly-local-business-sites", "");
  sites.setAttribute("data-erankly-after", "0");
  sites.setAttribute("data-erankly-sites-initialized", "0");
  const list = document.createElement("div");
  list.setAttribute("data-erankly-local-business-site-list", "");
  const status = document.createElement("p");
  status.setAttribute("data-erankly-local-business-sites-status", "");
  const loadMore = document.createElement("button");
  loadMore.type = "button";
  loadMore.setAttribute("data-erankly-local-business-load-more", "");
  loadMore.hidden = true;
  sites.appendChild(list);
  sites.appendChild(status);
  sites.appendChild(loadMore);
  fields.appendChild(sites);
  root.appendChild(toggle);
  root.appendChild(fields);
  settings.appendChild(root);
  document.body.appendChild(settings);
  return { document, root, toggle, list, sites };
}

const fetches = [];
let failNext = false;
let delayedPages = null;

function jsonResponse(body, ok) {
  return Promise.resolve({
    ok: ok !== false,
    json() {
      return Promise.resolve(body);
    },
  });
}

function installFetch() {
  global.fetch = function (url) {
    const href = String(url);
    fetches.push(href);
    if (failNext) {
      failNext = false;
      return Promise.resolve({ ok: false, json() { return Promise.resolve({}); } });
    }
    if (href.indexOf("/local-business/sites") !== -1) {
      return jsonResponse({
        sites: [
          {
            blog_id: 1,
            name: "Main",
            language: "en",
            path: "/",
            pages: [
              { id: 10, title: "Home", path: "/" },
              { id: 11, title: "Contact", path: "/contact/" },
            ],
          },
        ],
        hasMore: false,
      });
    }
    if (href.indexOf("/local-business/pages") !== -1) {
      const offset = /offset=(\d+)/.exec(href);
      const query = /(?:^|&)q=([^&]*)/.exec(href);
      const q = query ? decodeURIComponent(query[1]) : "";
      const pages =
        q === "none"
          ? []
          : offset && offset[1] === "50"
            ? [{ id: 60, title: "Beyond", path: "/beyond/" }]
            : [
                { id: 10, title: "Home", path: "/" },
                { id: 11, title: "Contact", path: "/contact/" },
                { id: 12, title: "Sede duplicata", path: "/a/" },
                { id: 13, title: "Sede duplicata", path: "/b/" },
              ];
      const payload = {
        blog_id: 1,
        pages,
        hasMore: !q && !(offset && offset[1] === "50"),
        nextOffset: offset && offset[1] === "50" ? 100 : 50,
      };
      if (delayedPages) {
        const wait = delayedPages;
        delayedPages = null;
        return new Promise((resolve) => {
          wait.resolve = function () {
            resolve({
              ok: true,
              json() {
                return Promise.resolve({
                  blog_id: 1,
                  pages: [{ id: 99, title: "Stale", path: "/stale/" }],
                  hasMore: false,
                  nextOffset: 50,
                });
              },
            });
          };
        });
      }
      return jsonResponse(payload);
    }
    return jsonResponse({});
  };
}

function loadWidgets(document) {
  const source = fs.readFileSync(
    path.join(__dirname, "../../assets/js/admin-widgets.js"),
    "utf8",
  );
  const context = {
    window: {},
    document,
    Event: function Event(type) {
      return createEvent(type);
    },
    fetch: global.fetch,
    console,
    setTimeout,
    clearTimeout,
    Promise,
    Array,
    Object,
    String,
    Number,
    Boolean,
    Error,
    JSON,
    parseInt,
    encodeURIComponent,
    decodeURIComponent,
  };
  context.window = context;
  context.global = context;
  context.window.eranklyLocalBusiness = {
    sitesUrl: "https://example.test/wp-json/erankly/v1/local-business/sites",
    pagesUrl: "https://example.test/wp-json/erankly/v1/local-business/pages",
    nonce: "probe",
    option: "erankly_settings",
    pageLimit: 50,
    i18n: {
      selectPage: "Select",
      searchPages: "Search pages",
      loadMore: "Load more sites",
      loadMorePages: "Load more pages",
      loading: "Loading",
      noResults: "No matches",
      retry: "Retry",
      requestFailed: "Could not load more sites.",
      pagesFailed: "Could not load pages.",
    },
  };
  context.window.ERanklyAdmin = {
    syncOrganizationFieldsVisibility() {},
  };
  vm.createContext(context);
  vm.runInContext(source, context);
  return context.window.ERanklyAdmin;
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

async function flush() {
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));
}

async function main() {
  installFetch();
  const { document, root, toggle, list, sites } = markup();
  global.document = document;
  const ER = loadWidgets(document);
  ER.bindLocalBusiness(root);

  assert(fetches.length === 0, "disabled toggle must not fetch");

  toggle.checked = true;
  toggle.dispatchEvent(createEvent("change"));
  await flush();
  const afterEnable = fetches.filter((url) => url.indexOf("/sites") !== -1).length;
  assert(afterEnable === 1, "enabling must load the first site batch");
  assert(list.querySelector("[data-erankly-local-business-site]"), "first site must render");
  assert(sites.getAttribute("data-erankly-sites-initialized") === "1", "sites must be marked initialized");

  toggle.checked = false;
  toggle.dispatchEvent(createEvent("change"));
  toggle.checked = true;
  toggle.dispatchEvent(createEvent("change"));
  await flush();
  const afterToggle = fetches.filter((url) => url.indexOf("/sites") !== -1).length;
  assert(afterToggle === 1, "repeated enable must not refetch initialized sites");

  failNext = true;
  fetches.length = 0;
  sites.setAttribute("data-erankly-sites-initialized", "0");
  list.children.slice().forEach((child) => list.removeChild(child));
  toggle.dispatchEvent(createEvent("change"));
  await flush();
  assert(fetches.length === 1, "failed first load still performs one request");
  const retry = sites.querySelector("[data-erankly-local-business-sites-status]").querySelector("button");
  assert(retry, "failed site load must offer retry");
  retry.dispatchEvent(createEvent("click"));
  await flush();
  assert(fetches.filter((url) => url.indexOf("/sites") !== -1).length === 2, "retry must fetch again");
  assert(list.querySelector("[data-erankly-local-business-site]"), "retry must render sites");

  const wrap = list.querySelector("[data-erankly-local-business-site]");
  const select = wrap.querySelector("[data-erankly-local-business-page-select]");
  const search = wrap.querySelector("[data-erankly-local-business-page-search]");
  const loadMorePages = wrap.querySelector("[data-erankly-local-business-load-more-pages]");
  select.value = "11";
  search.value = "Sede";
  search.dispatchEvent(createEvent("input"));
  await new Promise((resolve) => setTimeout(resolve, 350));
  await flush();
  assert(
    fetches.some((url) => url.indexOf("/pages") !== -1 && url.indexOf("q=Sede") !== -1),
    "page search must call pagesUrl",
  );
  assert(select.value === "11", "current selection must survive search results");

  search.value = "none";
  search.dispatchEvent(createEvent("input"));
  await new Promise((resolve) => setTimeout(resolve, 350));
  await flush();
  assert(select.value === "11", "empty search results must keep the current page");

  search.value = "";
  loadMorePages.hidden = false;
  wrap.setAttribute("data-erankly-page-offset", "50");
  loadMorePages.disabled = false;
  loadMorePages.dispatchEvent(createEvent("click"));
  await flush();
  assert(
    fetches.some((url) => url.indexOf("offset=50") !== -1),
    "load more pages must paginate",
  );
  assert(
    select.querySelector('option[value="60"]'),
    "page beyond the first batch must become selectable",
  );

  delayedPages = {};
  search.value = "late";
  search.dispatchEvent(createEvent("input"));
  await new Promise((resolve) => setTimeout(resolve, 350));
  search.value = "now";
  search.dispatchEvent(createEvent("input"));
  await new Promise((resolve) => setTimeout(resolve, 350));
  await flush();
  if (delayedPages && delayedPages.resolve) {
    delayedPages.resolve();
  }
  await flush();
  assert(!select.querySelector('option[value="99"]'), "stale page responses must be ignored");

  fetches.length = 0;
  global.fetch = function (url) {
    const href = String(url);
    fetches.push(href);
    if (href.indexOf("/local-business/sites") !== -1) {
      const pages = [];
      for (let i = 1; i <= 50; i++) {
        pages.push({ id: i, title: "P" + i, path: "/p" + i + "/" });
      }
      pages.push({ id: 999, title: "Saved", path: "/saved/" });
      return jsonResponse({
        sites: [
          {
            blog_id: 7,
            name: "Extra",
            language: "en",
            path: "/extra/",
            pages,
          },
        ],
        hasMore: true,
      });
    }
    return jsonResponse({
      blog_id: 7,
      pages: [{ id: 51, title: "Window", path: "/window/" }],
      hasMore: false,
      nextOffset: 100,
    });
  };

  const extraMarkup = markup();
  global.document = extraMarkup.document;
  const extraER = loadWidgets(extraMarkup.document);
  extraER.bindLocalBusiness(extraMarkup.root);
  extraMarkup.toggle.checked = true;
  extraMarkup.toggle.dispatchEvent(createEvent("change"));
  await flush();
  const extraWrap = extraMarkup.list.querySelector(
    '[data-erankly-local-business-site="7"]',
  );
  assert(extraWrap, "dynamically loaded site must render");
  assert(
    extraWrap.getAttribute("data-erankly-page-offset") === "50",
    "included page beyond the window must not shift the page offset",
  );
  extraWrap
    .querySelector("[data-erankly-local-business-load-more-pages]")
    .dispatchEvent(createEvent("click"));
  await flush();
  assert(
    fetches.some((url) => url.indexOf("offset=50") !== -1),
    "load more on a dynamic site must continue from the window offset",
  );
  assert(
    !fetches.some((url) => url.indexOf("offset=51") !== -1),
    "load more must not skip the page at offset 50",
  );

  console.log("local-business-widget-probe: ok");
}

main().catch((error) => {
  console.error(error.stack || error.message);
  process.exit(1);
});
