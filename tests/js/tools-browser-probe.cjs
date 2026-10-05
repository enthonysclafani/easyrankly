#!/usr/bin/env node
/** Browser checks. Cleanup requests are intercepted with fixtures; real site data is never deleted. */
"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { chromium } = require(process.env.ERANKLY_PLAYWRIGHT_PATH || "playwright");

async function main() {
  const base = new URL(process.argv[2]);
  assert(["localhost", "127.0.0.1"].includes(base.hostname), "Only a local Studio site may be used.");
  const output = process.env.ERANKLY_TOOLS_PROBE_OUTPUT;
  if (output) fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: "chrome" });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1050 } });
    const errors = [];
    page.on("pageerror", error => errors.push(error.message));
    const login = new URL("/studio-auto-login", base);
    login.searchParams.set("redirect_to", "/wp-admin/options-general.php?page=erankly&erankly_tab=tools");
    // Fail closed: until fixtures are installed, ONLY the read-only scan may reach the site.
    let fixtures = false;
    let failPreview = false;
    const cleanCalls = [];
    let cancelCalls = 0;
    await page.route("**/admin-ajax.php", async route => {
      const body = new URLSearchParams(route.request().postData() || "");
      if (body.get("action") !== "erankly_database_tools") return route.continue();
      const mode = body.get("mode");
      if (!fixtures) {
        assert.equal(mode, "scan", "A cleanup request must never reach the real database.");
        return route.continue();
      }
      let data;
      if (mode === "scan") {
        const keys = await page.locator("[data-tools-row]").evaluateAll(nodes => nodes.map(node => node.dataset.toolsRow));
        data = { categories: Object.fromEntries(keys.map(key => [key, {
          count: key === "orphan_postmeta" ? 25 : 0, more: false, available: true,
        }])) };
      } else if (mode === "preview") {
        if (failPreview) return route.fulfill({ status: 500, json: { success: false, data: { message: "Fixture preview failed" } } });
        assert(body.getAll("categories[]").includes("orphan_postmeta"));
        data = { token: "fixture-preview", count: 25, limited: false, groups: [{
          label: "Orphaned post metadata", count: 25, metadata: 0, revisions: 0,
          items: Array.from({ length: 25 }, (_, i) => i === 0 ? "#1 · <script>window.untrustedExecuted = true</script>" : "#" + (i + 1) + " · fixture metadata"),
        }] };
      } else if (mode === "clean") {
        assert.equal(body.get("confirmed"), "1");
        assert.equal(body.get("token"), "fixture-preview");
        cleanCalls.push(Number(body.get("offset")));
        const cursor = body.get("offset") === "0" ? 20 : 25;
        await new Promise(resolve => setTimeout(resolve, 150));
        data = { cursor, total: 25, done: cursor === 25, results: [{ label: "Orphaned post metadata", removed: cursor, skipped: 0, failed: 0 }] };
      } else if (mode === "cancel") {
        ++cancelCalls;
        data = { cursor: 20, total: 25, done: false, results: [{ label: "Orphaned post metadata", removed: 20, skipped: 0, failed: 0 }] };
      } else throw new Error("Unexpected tools request: " + mode);
      await route.fulfill({ json: { success: true, data } });
    });
    await page.goto(login.href);
    await page.waitForSelector("[data-erankly-database-tools]");
    await page.waitForFunction(() => document.querySelector("[data-erankly-database-tools]").getAttribute("aria-busy") === "false");
    assert.equal(await page.locator("[data-tools-status].is-error").count(), 0, await page.locator("[data-tools-status]").textContent());
    assert.equal(await page.locator("[data-tools-row]").count(), 9);
    assert.equal(await page.locator('[data-recommended="0"]:checked').count(), 0);
    const actualCounts = await page.locator("[data-tools-count]").allTextContents();
    if (output) await page.screenshot({ path: path.join(output, "tools-desktop.png"), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), "The mobile page must not overflow horizontally.");
    if (output) await page.screenshot({ path: path.join(output, "tools-mobile.png"), fullPage: true });
    await page.setViewportSize({ width: 1440, height: 1050 });

    fixtures = true;
    await page.locator("[data-tools-scan]").click();
    await page.waitForFunction(() => !document.querySelector("[data-tools-scan]").disabled);
    await page.locator('[data-tools-category][value="orphan_postmeta"]').check();
    await page.locator("[data-tools-preview]").click();
    await page.waitForSelector("[data-tools-dialog][open]");
    assert(await page.locator("[data-tools-confirm]").isDisabled());
    assert.equal(await page.evaluate(() => document.activeElement.hasAttribute("data-tools-cancel")), true);
    assert.equal(cleanCalls.length, 0);
    await page.keyboard.press("Escape");
    assert.equal(await page.locator("[data-tools-dialog][open]").count(), 0);
    assert.equal(cleanCalls.length, 0);

    await page.locator("[data-tools-preview]").click();
    await page.waitForSelector("[data-tools-dialog][open]");
    await page.locator("[data-tools-summary] summary").click();
    assert.equal(await page.locator("[data-tools-summary] li").count(), 25);
    assert.equal(await page.evaluate(() => !!window.untrustedExecuted), false);
    if (output) await page.screenshot({ path: path.join(output, "tools-preview.png"), fullPage: true });
    await page.locator("[data-tools-acknowledge]").check();
    await page.locator("[data-tools-confirm]").click();
    await page.waitForFunction(() => document.querySelector("[data-tools-status]").textContent.includes("Cleanup complete"));
    assert.deepEqual(cleanCalls, [0, 20]);
    assert((await page.locator("[data-tools-result-rows]").textContent()).includes("25"));
    await page.waitForFunction(() => !document.querySelector("[data-tools-scan]").disabled);

    await page.locator("[data-tools-preview]").click();
    await page.waitForSelector("[data-tools-dialog][open]");
    await page.locator("[data-tools-acknowledge]").check();
    await page.locator("[data-tools-confirm]").click();
    await page.locator("[data-tools-stop]").click();
    await page.waitForFunction(() => document.querySelector("[data-tools-status]").textContent.includes("Cleanup stopped"));
    assert.deepEqual(cleanCalls, [0, 20, 0]);
    assert(cancelCalls >= 2);
    await page.waitForFunction(() => !document.querySelector("[data-tools-scan]").disabled);
    failPreview = true;
    await page.locator("[data-tools-preview]").click();
    await page.waitForSelector("[data-tools-status].is-error");
    assert.equal(cleanCalls.length, 3);
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ actualCounts, browserChecks: "passed", cleanupRequestsSentToRealSite: 0, fixtureBatchOffsets: cleanCalls }));
  } finally { await browser.close(); }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
