import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
import assert from 'node:assert/strict';
let ctor,header; const sent=[],timers=new Map(); let id=0;
const data={enabled:true};
const server={inFlight:0,addHeaders(fn){header=fn},sendCall(payload,url){const reply={payload,url,done(fn){this.success=fn},fail(fn){this.failure=fn}};sent.push(reply);return reply}};
server.call=function(payload){this.payload=payload};server.call.prototype.send=function(url){return server.sendCall(this.payload,url)};
const context={sys:{singletons:{relay:{__data:data}},lib:{add:(s,c)=>ctor=c},core:{server},http:{renderedUrl:()=>'/pages/?id=9'}},p(){},location:{protocol:'https:',host:'example.test'},window:{crypto:null,addEventListener(){}},document:{visibilityState:'visible',hasFocus:()=>true,querySelectorAll:()=>[],addEventListener(){}},setTimeout(fn){timers.set(++id,fn);return id},clearTimeout(i){timers.delete(i)},console,Map,Set};
vm.runInNewContext(await readFile(new URL('../src/relay.js',import.meta.url),'utf8'),context);const relay={};ctor.call(relay);
relay.heard('data:pages');assert.equal(header('put')['X-Changed'],undefined,'action-only request cannot consume pending updates');relay.refresh(true);assert.equal(sent.length,1);assert.equal(sent[0].payload.changed[0],'data:pages');
relay.heard('data:pages');sent[0].success();relay.refresh(true);assert.equal(sent.length,2,'same-channel update during a request survives its response');
sent[1].failure();relay.refresh(true);assert.equal(sent.length,3,'failed batch retries');assert.equal(sent[2].payload.changed[0],'data:pages');sent[2].success();relay.refresh(true);assert.equal(sent.length,3,'successful batch is not repeated');
relay.heard('data:settings');server.inFlight=1;server.onIdle=fn=>server.idle=fn;relay.refresh(true);assert.equal(sent.length,3,'save in flight is allowed to finish');server.inFlight=0;server.idle();relay.refresh(true);assert.equal(sent.length,4);assert.equal(sent[3].payload.changed[0],'data:settings');
console.log('ok: action-only requests, concurrent signals, failure retry and in-flight save preserve updates');

// No fail hook in older clients: timeout must put the batch back in the queue.
server.inFlight=0;sent.at(-1).success();
server.sendCall=(payload,url)=>{const reply={payload,url,done(fn){this.success=fn}};sent.push(reply);return reply};
relay.heard('data:legacy');relay.refresh(true);const beforeLegacy=sent.length;
// Earlier successful/idle gather timers are allowed to be superseded; run pending callbacks.
for(const [timerId,fn] of [...timers]){timers.delete(timerId);fn();}
relay.refresh(true);
assert.ok(sent.length>beforeLegacy,'watchdog retries without a fail hook');
assert.ok(sent.at(-1).payload.changed.includes('data:legacy'));
console.log('ok: non-chainable callbacks and missing legacy fail hook retain delivery/retry');

// Ephemeral UI and a held selection must survive remote invalidation.
const oldQuery=context.document.querySelectorAll;
context.document.querySelectorAll=()=>[{hidden:false,getClientRects:()=>[{}]}];
const beforeDialog=sent.length;
relay.heard('data:dialog');relay.refresh(true);
assert.equal(sent.length,beforeDialog,'open modal defers a render');
context.document.querySelectorAll=oldQuery;
context.document.activeElement={matches:()=>true};relay.refresh(true);
assert.equal(sent.length,beforeDialog,'active input defers a render');
context.document.activeElement=null;
context.window.getSelection=()=>({isCollapsed:false});relay.refresh(true);
assert.equal(sent.length,beforeDialog,'text selection defers a render');
context.window.getSelection=()=>({isCollapsed:true});relay.refresh(true);
assert.equal(sent.length,beforeDialog+1,'closing interaction delivers queued changes');
assert.ok(sent.at(-1).payload.changed.includes('data:dialog'));
console.log('ok: modals, inputs and text selections retain queued updates until interaction ends');
