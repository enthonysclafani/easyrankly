#!/usr/bin/env node
/**
 * DOM probe: Simplified mode immediately shows or hides the Advanced tab, and a
 * 200 autosave with incomplete:true still refreshes the settings root (cache: no-store).
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
    this.isConnected = true;
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
    if (name === "id" && this.id) {
      return this.id;
    }
    return null;
  }

  removeAttribute(name) {
    delete this.attributes[name];
  }

  appendChild(child) {
    child.parentNode = this;
    child.document = this.document;
    this.children.push(child);
    return child;
  }

  replaceWith(next) {
    if (!this.parentNode) {
      return;
    }

    const parent = this.parentNode;
    const idx = parent.children.indexOf(this);
    next.parentNode = parent;
    next.document = this.document;
    parent.children.splice(idx, 1, next);
    this.parentNode = null;
  }

  addEventListener(type, fn) {
    this.listeners[type] = this.listeners[type] || [];
    this.listeners[type].push(fn);
  }

  dispatchEvent(event) {
    (this.listeners[event.type] || []).forEach((fn) => fn.call(this, event));
    if (event.bubbles && this.parentNode) {
      this.parentNode.dispatchEvent(event);
    }
  }

  matches(selector) {
    return matchesSelector(this, selector);
  }

  querySelector(selector) {
    return this.querySelectorAll(selector)[0] || null;
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

  get classList() {
    const node = this;
    return {
      contains(name) {
        return (" " + node.className + " ").indexOf(" " + name + " ") !== -1;
      },
      add(name) {
        if (!this.contains(name)) {
          node.className = (node.className + " " + name).trim();
        }
      },
      remove(name) {
        node.className = node.className
          .split(/\s+/)
          .filter((cls) => cls && cls !== name)
          .join(" ");
      },
      toggle(name, force) {
        const should = arguments.length > 1 ? !!force : !this.contains(name);
        if (should) {
          this.add(name);
        } else {
          this.remove(name);
        }
      },
    };
  }
}

function walk(node, visit) {
  visit(node);
  node.children.forEach((child) => walk(child, visit));
}

function matchesSelector(node, selector) {
  selector = String(selector || "").trim();
  if (selector.charAt(0) === "#") {
    return node.id === selector.slice(1);
  }
  if (selector.startsWith(".")) {
    return (" " + node.className + " ").indexOf(" " + selector.slice(1) + " ") !== -1;
  }
  const attrStart = selector.match(/^\[([^=\]]+)\^="([^"]*)"\]$/);
  if (attrStart) {
    const value = node.getAttribute(attrStart[1]) || "";
    return value.indexOf(attrStart[2]) === 0;
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

function markup(document) {
  const root = document.createElement("div");
  root.className = "erankly-settings";
  const tab = document.createElement("a");
  tab.id = "erankly-settings-tab-advanced";
  tab.setAttribute("id", "erankly-settings-tab-advanced");
  tab.hidden = true;
  const status = document.createElement("span");
  status.setAttribute("data-erankly-autosave-status", "");
  const panel = document.createElement("div");
  panel.setAttribute("data-erankly-settings-panel", "settings-settings");
  const input = document.createElement("input");
  input.type = "checkbox";
  input.checked = true;
  input.name = "erankly_settings[simplified_mode]";
  input.setAttribute("name", "erankly_settings[simplified_mode]");
  panel.appendChild(input);
  root.appendChild(tab);
  root.appendChild(status);
  root.appendChild(panel);
  document.body.appendChild(root);
  return { root, tab, input, panel, status };
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

function loadSettings(document, extras) {
  const source = fs.readFileSync(
    path.join(__dirname, "../../assets/js/admin-settings.js"),
    "utf8",
  );
  const queued = [];
  let now = 0;
  let nextTimer = 1;
  const reloads = { count: 0 };

  function DOMParser() {}
  DOMParser.prototype.parseFromString = function () {
    const next = document.createElement("div");
    next.className = "erankly-settings";
    const status = document.createElement("span");
    status.setAttribute("data-erankly-autosave-status", "");
    next.appendChild(status);
    return {
      querySelector: function (sel) {
        return sel === ".erankly-settings" ? next : null;
      },
    };
  };

  const context = {
    window: {},
    document,
    console,
    Promise,
    Array,
    Object,
    String,
    Number,
    Boolean,
    Error,
    JSON,
    parseInt,
    AbortController: function AbortController() {
      this.signal = { aborted: false };
      this.abort = function () {
        this.signal.aborted = true;
      };
    },
    setTimeout: function (fn, ms) {
      const id = nextTimer++;
      queued.push({ id: id, fn: fn, at: now + (ms || 0), cleared: false });
      return id;
    },
    clearTimeout: function (id) {
      queued.forEach((timer) => {
        if (timer.id === id) {
          timer.cleared = true;
        }
      });
    },
    DOMParser: DOMParser,
    fetch: extras.fetch,
  };
  context.window = context;
  context.global = context;
  context.window.addEventListener = function () {};
  context.document.addEventListener = function () {};
  context.window.location = {
    href: "https://example.test/wp-admin/options-general.php?page=erankly&erankly_tab=settings",
    reload: function () {
      reloads.count += 1;
    },
  };
  context.window.eranklySettingsAutosave = {
    nonce: "probe",
    i18n: {
      saving: "Saving…",
      saved: "Saved",
      warning: "Saved with warnings",
      incomplete: "Saved, but the configuration is incomplete.",
      error: "Could not save. Reload the page.",
    },
    panels: {
      settings: {
        restUrl: "https://example.test/wp-json/erankly/v1/settings/settings",
        reloadOnSave: true,
        refreshKeys: ["simplified_mode"],
      },
    },
  };
  context.window.ERanklyAdmin = {};
  vm.createContext(context);
  vm.runInContext(source, context);

  async function advance(ms) {
    now += ms;
    let ran = true;
    while (ran) {
      ran = false;
      const due = queued
        .filter((timer) => !timer.cleared && timer.at <= now)
        .sort((a, b) => a.at - b.at);
      for (let i = 0; i < due.length; i++) {
        due[i].cleared = true;
        due[i].fn();
        ran = true;
        await flush();
      }
    }
    await flush();
  }

  return {
    ER: context.window.ERanklyAdmin,
    advance: advance,
    reloads: reloads,
  };
}

async function main() {
  const navDocument = new FakeDocument();
  const nav = markup(navDocument);
  const navRuntime = loadSettings(navDocument, {
    fetch: function () {
      return Promise.resolve({ ok: true, json: function () { return Promise.resolve({}); } });
    },
  });
  navRuntime.ER.bindSimplifiedModeNav(nav.root);
  assert(nav.tab.hidden === true, "Advanced starts hidden while Simplified mode is on");
  nav.input.checked = false;
  nav.input.dispatchEvent(createEvent("change"));
  assert(nav.tab.hidden === false, "unchecking Simplified mode must show Advanced immediately");
  nav.input.checked = true;
  nav.input.dispatchEvent(createEvent("change"));
  assert(nav.tab.hidden === true, "checking Simplified mode must hide Advanced immediately");
  navRuntime.ER.bindSimplifiedModeNav(nav.root);
  nav.input.checked = false;
  nav.input.dispatchEvent(createEvent("change"));
  assert(nav.tab.hidden === false, "a second bind must not stack listeners that fight the toggle");

  const calls = [];
  const saveDocument = new FakeDocument();
  const save = markup(saveDocument);
  const runtime = loadSettings(saveDocument, {
    fetch: function (url, opts) {
      const options = opts || {};
      calls.push({ url: String(url), method: options.method || "GET", cache: options.cache || "" });
      if (options.method === "POST") {
        return Promise.resolve({
          ok: true,
          status: 200,
          json: function () {
            return Promise.resolve({
              saved: true,
              incomplete: true,
              errors: ["Local business schema is incomplete."],
              warnings: [],
            });
          },
        });
      }
      return Promise.resolve({
        ok: true,
        status: 200,
        text: function () {
          return Promise.resolve('<div class="erankly-settings"></div>');
        },
      });
    },
  });
  runtime.ER.bindAllSettingsAutosave(save.root);
  save.input.checked = false;
  save.input.dispatchEvent(createEvent("change"));
  await runtime.advance(900);
  await runtime.advance(700);
  assert(runtime.reloads.count === 0, "a successful save must not fall back to location.reload");
  assert(
    calls.some((call) => call.method === "POST" && call.url.indexOf("/settings/settings") !== -1),
    "toggling Simplified mode must POST the settings panel",
  );
  assert(
    calls.some(
      (call) =>
        call.method === "GET" &&
        call.cache === "no-store" &&
        call.url.indexOf("erankly_tab=settings") !== -1,
    ),
    "an incomplete 200 must still refresh the settings root without using the cached page",
  );

  console.log("settings-autosave-probe: ok");
}

main().catch((error) => {
  console.error(error.stack || error.message);
  process.exit(1);
});
