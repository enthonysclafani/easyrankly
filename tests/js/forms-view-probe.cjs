'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const code = fs.readFileSync(path.join(__dirname, '../../blocks/contact-form/view.js'), 'utf8').replace(/^import[^\n]+\n/, '');
async function scenario({response, failure, token = 'token', turnstile = true}) {
	let config, payload, reset = 0, widgetReset = 0, focused = 0;
	const input = {setAttribute(name, value) {this[name] = value;}, focus() {focused++;}};
	const error = {textContent: ''};
	const wrapper = {dataset: {eranklyField:'email'}, tagName:'DIV', querySelector(selector) {return selector === '.erankly-form-error' ? error : null;}, querySelectorAll() {return [input];}};
	const form = {isConnected:true, reportValidity() {return true;}, reset() {reset++;}, querySelector() {return {};}, querySelectorAll(selector) {return selector === '[data-erankly-field]' ? [wrapper] : selector === '.erankly-form-error' ? [error] : [];}};
	const context = {sending:false, status:'', turnstile, siteKey:'key', action:'erankly_form_9', endpoint:'/forms/9/submit'};
	class FakeFormData extends Map {constructor() {super([['email','visitor@example.org'],['erankly_hp_9','']]);} getAll(name) {return this.has(name)?[this.get(name)]:[];}}
	vm.runInNewContext(code, {store(namespace, value) {assert.equal(namespace,'easyrankly/forms'); config = value; return {state:{sending:'Sending',error:'Failed',verification:'Verify'}};}, getContext:()=>context, getElement:()=>({ref:form}), window:{turnstile:{render:()=>0,getResponse:()=>token,reset(){widgetReset++;}}}, WeakMap, FormData:FakeFormData, location:{origin:'https://example.org',pathname:'/contact',search:'?private=value'}, fetch:async (endpoint, options) => {payload = JSON.parse(options.body); if (failure) throw new Error('offline'); return {ok:response.success,json:async()=>response};}, setTimeout});
	config.callbacks.init();
	const generator = config.actions.submit({preventDefault(){}});
	let step = generator.next();
	while (!step.done) {try {step = generator.next(await step.value);} catch (error) {step = generator.throw(error);}}
	assert.equal(context.sending,false);
	return {context,payload,reset,widgetReset,focused,error,input};
}
(async()=>{
	let r = await scenario({response:{success:true,message:'Sent'}});
	assert.equal(r.context.status,'Sent'); assert.equal(r.reset,1); assert.equal(r.widgetReset,1); assert.equal(r.payload.source,'https://example.org/contact'); assert.equal(r.payload.erankly_hp_9,''); assert.equal(r.payload.token,'token');
	r = await scenario({response:{success:false,message:'Invalid',errors:{email:'Bad email'}}});
	assert.equal(r.context.status,'Invalid'); assert.equal(r.reset,0); assert.equal(r.focused,1); assert.equal(r.error.textContent,'Bad email'); assert.equal(r.input['aria-invalid'],'true'); assert.equal(r.widgetReset,1);
	r = await scenario({response:{success:false,message:'Delivery failed'}}); assert.equal(r.reset,0); assert.equal(r.context.status,'Delivery failed');
	r = await scenario({failure:true}); assert.equal(r.context.status,'Failed'); assert.equal(r.reset,0); assert.equal(r.widgetReset,1);
	r = await scenario({token:''}); assert.equal(r.context.status,'Verify'); assert.equal(r.payload,undefined); assert.equal(r.widgetReset,1);
	r = await scenario({turnstile:false,response:{success:true,message:'Sent'}}); assert.equal(r.payload.token,''); assert.equal(r.widgetReset,0);
	console.log('Forms view: success, field errors/focus, failure, network error, missing token, reset and source privacy passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
