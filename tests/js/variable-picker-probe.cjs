#!/usr/bin/env node
"use strict";
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

// Small DOM fixture for the picker contract; real layout is checked in the browser.
class Node {
  constructor(tag = "div") {
    this.tag = tag; this.children = []; this.attrs = {}; this.listeners = {};
    this.hidden = false; this.value = ""; this.textContent = ""; this.classes = new Set();
    this.classList = {add: x => this.classes.add(x), remove: x => this.classes.delete(x), contains: x => this.classes.has(x)};
  }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return this.attrs[k] ?? null; }
  removeAttribute(k) { delete this.attrs[k]; }
  get id() { return this.attrs.id || ""; }
  set id(v) { this.attrs.id = v; }
  appendChild(n) {
    if (n.parent) n.parent.children = n.parent.children.filter(c => c !== n);
    n.parent = this; this.children.push(n); return n;
  }
  contains(n) { return n === this || this.children.some(c => c.contains(n)); }
  matches(selector) {
    if (selector === 'input:not([type="search"]), textarea') return this.tag === "textarea" || (this.tag === "input" && this.getAttribute("type") !== "search");
    return [...selector.matchAll(/\[([^=\]]+)(?:="([^"]*)")?\]/g)].every(([,k,v]) => v === undefined ? this.getAttribute(k) !== null : this.getAttribute(k) === v);
  }
  querySelectorAll(selector) { return this.children.flatMap(c => [...(c.matches(selector) ? [c] : []), ...c.querySelectorAll(selector)]); }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  closest(selector) { return this.matches(selector) ? this : this.parent?.closest(selector); }
  addEventListener(k, f) { (this.listeners[k] ||= []).push(f); }
  dispatchEvent(e) { e.target ||= this; for (const f of this.listeners[e.type] || []) f(e); if (e.bubbles && this.parent) this.parent.dispatchEvent(e); }
  focus() { if (document.activeElement !== this) { document.activeElement?.dispatchEvent({type:"blur"}); document.activeElement = this; this.dispatchEvent({type:"focus"}); this.dispatchEvent({type:"focusin",bubbles:true}); } }
  setSelectionRange(a, b) { this.selectionStart = a; this.selectionEnd = b; }
  scrollIntoView() {}
}
const document = new Node("document");
document.createElement = tag => new Node(tag);
document.createTextNode = text => { const n = new Node("text"); n.textContent = text; return n; };
function element(tag, attrs, parent) { const n = new Node(tag); for (const [k,v] of Object.entries(attrs)) n.setAttribute(k,v); parent.appendChild(n); return n; }
const menu = element("div", {id:"erankly-variable-listbox", "data-erankly-variable-menu":""}, document);
menu.hidden = true;
function option(group, token, search) { const parent = element("div", {"data-erankly-variable-group":group},menu); return element("button", {"data-erankly-variable":token,"data-erankly-variable-search-text":search},parent); }
const siteOption = option("site","{{site_name}}","site name site_name");
const postOption = option("content","{{post_title}}","post title post_title");
const pageOption = option("pagination","{{page_number}}","page number page_number");
function field(groups, examples, tag = "input") {
  const wrap = element("div", {"data-erankly-variable-field":""}, document);
  const input = element(tag, {}, wrap);
  const preview = element("span", {"data-erankly-variable-preview":"","data-erankly-variable-groups":JSON.stringify(groups),"data-erankly-variable-examples":JSON.stringify(examples)},wrap);
  return {wrap,input,preview};
}
const a = field(["site","content"],{site_name:"Site A",post_title:"Post A"});
const b = field(["pagination"],{page_number:"2"},"textarea");
const c = field(["content"],{post_title:"Post C"});
const context = {document, Event:class {constructor(type, opts = {}) {this.type=type;Object.assign(this,opts);}},window:{eranklyVariablePreview:{siteName:"Site",unavailableLabel:"Unavailable"}},console};
vm.createContext(context);
vm.runInContext(fs.readFileSync(require("node:path").join(__dirname,"../../assets/js/admin-variables.js"),"utf8"),context);
const ER = context.window.ERanklyAdmin;
ER.bindVariablePickers(document);
ER.bindVariablePickers(document);
assert.equal(a.input.listeners.focus.length,2,"preview and picker are bound only once");
assert.equal(menu.listeners.click.length,1);
a.input.value = "before site after"; a.input.setSelectionRange(11,11); a.input.focus();
assert.equal(menu.parent,a.wrap); assert.equal(menu.hidden,false);
assert.equal(a.input.getAttribute("aria-controls"),menu.id);
assert.equal(siteOption.hidden,false); assert.equal(postOption.hidden,true); assert.equal(pageOption.hidden,true);
function key(input, value) { const event = {type:"keydown",key:value,preventDefault(){this.prevented=true;}}; input.dispatchEvent(event); return event; }
key(a.input,"ArrowDown"); assert.equal(a.input.getAttribute("aria-activedescendant"),siteOption.id);
let inputs = 0, changes = 0;
a.input.addEventListener("input",()=>inputs++); a.input.addEventListener("change",()=>changes++);
assert.equal(key(a.input,"Enter").prevented,true);
assert.equal(a.input.value,"before {{site_name}} after"); assert.equal(a.input.selectionStart,20);
assert.equal(inputs,1); assert.equal(changes,1); assert.equal(menu.hidden,true);
assert.equal(a.input.getAttribute("aria-expanded"),"false"); assert.equal(a.input.getAttribute("aria-activedescendant"),null);
b.input.value=""; b.input.focus(); assert.equal(menu.parent,b.wrap);
assert.equal(a.input.getAttribute("aria-expanded"),"false"); assert.equal(pageOption.hidden,false); assert.equal(siteOption.hidden,true);
assert.equal(key(b.input,"Enter").prevented,undefined,"Enter without a selection keeps textarea newlines");
key(b.input,"ArrowUp"); assert.equal(b.input.getAttribute("aria-activedescendant"),pageOption.id);
key(b.input,"Escape"); assert.equal(menu.hidden,true);
b.input.dispatchEvent({type:"click"}); key(b.input,"Tab"); assert.equal(menu.hidden,true);
c.input.value="post"; c.input.setSelectionRange(4,4); c.input.focus();
postOption.dispatchEvent({type:"click",bubbles:true}); assert.equal(c.input.value,"{{post_title}}"); assert.equal(menu.hidden,true);
// Context-specific previews never change the serialized value.
a.input.value="{{post_title}} {{unknown}}"; a.input.focus(); b.input.focus();
assert.equal(a.input.value,"{{post_title}} {{unknown}}");
assert.equal(a.preview.children.at(-1).children[0].textContent,"Post A");
c.input.focus(); b.input.focus(); assert.equal(c.preview.children.at(-1).children[0].textContent,"Post C");
// No matches, outside focus, and a newly added builder field.
b.input.value="nomatch"; b.input.setSelectionRange(7,7); b.input.dispatchEvent({type:"input"}); assert.equal(menu.hidden,true);
b.input.value=""; b.input.dispatchEvent({type:"input"}); document.dispatchEvent({type:"click",target:document}); assert.equal(menu.hidden,true);
const d = field(["site"],{}); ER.bindVariablePickers(d.wrap.parent); d.input.focus();
assert.equal(menu.parent,d.wrap); assert.equal(document.querySelectorAll("[data-erankly-variable-menu]").length,1);
// Removing a builder block or replacing an autosaved panel must not lose the catalog.
document.children = document.children.filter(n => n !== d.wrap);
const e = field(["content"],{post_title:"Replacement"}); ER.bindVariablePickers(document); e.input.focus();
assert.equal(menu.parent,e.wrap); assert.equal(document.querySelectorAll("[data-erankly-variable-menu]").length,1);
assert.equal(d.input.getAttribute("aria-expanded"),"false");
const outside = element("input",{},document); outside.focus(); assert.equal(menu.hidden,true);
assert.equal(ER.resolveVariablePreviewText("{{unknown}}",{},"", ""),"Unavailable");
console.log("Shared variable menu: filtering, caret insertion, events, keyboard, focus, previews and dynamic binding passed.");
