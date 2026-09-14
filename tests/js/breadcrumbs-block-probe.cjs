#!/usr/bin/env node
/**
 * Behavioral probe for the legacy breadcrumbs transform: compatible attributes
 * (align, className) are copied; block-specific leftovers are not.
 */
"use strict";

const fs = require("fs");
const path = require("path");
const vm = require("vm");

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const source = fs.readFileSync(
  path.join(__dirname, "../../blocks/breadcrumbs/index.js"),
  "utf8",
);

let registered = null;
const createCalls = [];

const sandbox = {
  window: { eranklyBreadcrumbsBlock: { coreAvailable: true } },
  wp: {
    blocks: {
      getBlockType: function (name) {
        return name === "core/breadcrumbs" ? { name: name } : undefined;
      },
      createBlock: function (name, attributes) {
        const block = { name: name, attributes: attributes || {} };
        createCalls.push(block);
        return block;
      },
      registerBlockType: function (name, settings) {
        registered = { name: name, settings: settings };
      },
    },
    blockEditor: {
      useBlockProps: function (props) {
        return props || {};
      },
    },
    element: {
      createElement: function () {
        return null;
      },
    },
    i18n: {
      __: function (text) {
        return text;
      },
    },
  },
};

vm.runInNewContext(source, sandbox);

assert(registered && registered.name === "easyrankly/breadcrumbs", "legacy block must register");
assert(registered.settings.supports.inserter === false, "inserter must hide when core is available");
assert(registered.settings.supports.html === false, "html support must stay disabled");
assert(
  JSON.stringify(registered.settings.supports.align) === JSON.stringify(["wide", "full"]),
  "wide/full alignment must be preserved on the client definition",
);
assert(
  registered.settings.transforms.to[0].isMatch(),
  "transform must match when core/breadcrumbs is registered",
);

const transformed = registered.settings.transforms.to[0].transform({
  align: "wide",
  className: "custom-trail",
  separator: "/",
  showHomeItem: false,
});

assert(transformed.name === "core/breadcrumbs", "transform target must be core/breadcrumbs");
assert(transformed.attributes.align === "wide", "wide alignment must be preserved");
assert(transformed.attributes.className === "custom-trail", "custom class must be preserved");
assert(
  transformed.attributes.separator === undefined,
  "legacy-only attributes must not be copied onto the native block",
);
assert(
  transformed.attributes.showHomeItem === undefined,
  "EasyRankly-only flags must not be copied onto the native block",
);

const full = registered.settings.transforms.to[0].transform({ align: "full" });
assert(full.attributes.align === "full", "full alignment must be preserved");

const ignored = registered.settings.transforms.to[0].transform({ align: "center", className: "" });
assert(ignored.attributes.align === undefined, "unsupported alignment must not be copied");
assert(ignored.attributes.className === undefined, "empty className must not be copied");

sandbox.window.eranklyBreadcrumbsBlock = { coreAvailable: false };
let registeredWithoutCore = null;
const sandboxWithoutCore = Object.assign({}, sandbox, {
  window: { eranklyBreadcrumbsBlock: { coreAvailable: false } },
  wp: Object.assign({}, sandbox.wp, {
    blocks: Object.assign({}, sandbox.wp.blocks, {
      getBlockType: function () {
        return undefined;
      },
      registerBlockType: function (name, settings) {
        registeredWithoutCore = { name: name, settings: settings };
      },
    }),
  }),
});
vm.runInNewContext(source, sandboxWithoutCore);
assert(
  registeredWithoutCore.settings.supports.inserter === true,
  "inserter must stay available when the native block is missing",
);
assert(registeredWithoutCore.settings.supports.html === false, "html support must stay disabled without core");
assert(
  JSON.stringify(registeredWithoutCore.settings.supports.align) === JSON.stringify(["wide", "full"]),
  "wide/full alignment must be preserved when the inserter is shown",
);
assert(
  registeredWithoutCore.settings.transforms.to[0].isMatch() === false,
  "transform must not match when core/breadcrumbs is unregistered",
);

function findBlocksScript() {
  const candidates = [
    process.env.WP_BLOCKS_JS,
    path.join(__dirname, "../../../../../wp-includes/js/dist/blocks.js"),
    "/tmp/wordpress-develop-erankly-review/src/wp-includes/js/dist/blocks.js",
  ];

  for (const candidate of candidates) {
    if (candidate && fs.existsSync(candidate)) {
      return candidate;
    }
  }

  return "";
}

function assertShallowSupportsMerge(clientSettings, coreAvailable) {
  const blocksJs = findBlocksScript();
  if (!blocksJs) {
    console.log("breadcrumbs-block-probe: skipped core supports merge (blocks.js not found)");
    return;
  }

  const source = fs.readFileSync(blocksJs, "utf8");
  const start = source.indexOf("var processBlockType = (name, blockSettings)");
  const end = source.indexOf("    if (!blockType.attributes", start);
  if (start < 0 || end < 0) {
    console.log("breadcrumbs-block-probe: skipped core supports merge (processBlockType not located)");
    return;
  }

  const coreMerge = source.slice(start, end) + " return blockType; }; processBlockType;";
  const processBlockType = vm.runInNewContext(coreMerge, {
    BLOCK_ICON_DEFAULT: {},
    mergeBlockVariations: function () {
      return [];
    },
  });
  const bootstrapped = {
    apiVersion: 3,
    title: "EasyRankly Breadcrumbs",
    supports: {
      html: false,
      align: ["wide", "full"],
      inserter: !coreAvailable,
    },
  };
  const result = processBlockType("easyrankly/breadcrumbs", clientSettings)({
    select: { getBootstrappedBlockType: function () { return bootstrapped; } },
  });

  assert(result.supports.html === false, "merged supports must keep html:false");
  assert(
    JSON.stringify(result.supports.align) === JSON.stringify(["wide", "full"]),
    "merged supports must keep wide/full alignment",
  );
  assert(
    result.supports.inserter === !coreAvailable,
    "merged supports must only change inserter with core availability",
  );
}

assertShallowSupportsMerge(registered.settings, true);
assertShallowSupportsMerge(registeredWithoutCore.settings, false);

console.log("breadcrumbs-block-probe: ok");
