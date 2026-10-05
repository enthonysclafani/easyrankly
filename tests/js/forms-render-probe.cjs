'use strict';
const fs = require('node:fs');
const assert = require('node:assert/strict');
const html = fs.readFileSync(process.argv[2], 'utf8');
const ids = [...html.matchAll(/\bid="([^"]+)"/g)].map(match=>match[1]);
assert.equal(ids.length,new Set(ids).size,'All instance IDs must be unique');
assert.ok(html.includes('autocomplete="on"'));
for (const token of ['name','email','tel','organization','url']) assert.ok(html.includes('autocomplete="'+token+'"'),token);
const honeypots = [...html.matchAll(/<input[^>]*name="(erankly_hp_[^"]+)"[^>]*>/g)];
assert.equal(honeypots.length,2);
for (const [input,name] of honeypots) {assert.ok(!/(email|website|password)/.test(name));assert.ok(input.includes('autocomplete="off"'));assert.ok(input.includes('tabindex="-1"'));assert.ok(input.includes('type="text"'));}
for (const label of html.matchAll(/<label for="([^"]+)"/g)) assert.ok(ids.includes(label[1]));
assert.ok(!/nonce/i.test(html));
console.log('Forms HTML: unique IDs, labels, semantic autocomplete and honeypot contracts passed.');
