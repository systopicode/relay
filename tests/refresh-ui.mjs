import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createServer} from 'node:http';
const {chromium}=await import(process.env.FLUXO_PLAYWRIGHT_MODULE);
const received=[];let failNext=true;
const server=createServer(async(req,res)=>{
 if(req.url==='/refresh'){
  let raw='';for await(const chunk of req)raw+=chunk;const body=JSON.parse(raw);received.push(body.changed);
  res.setHeader('Content-Type','application/json');if(failNext){failNext=false;res.writeHead(503);res.end('{"error":"fixture failure"}');}else res.end('{}');return;
 }
 res.setHeader('Content-Type','text/html');res.end('<!doctype html><html lang="en"><title>Relay retry regression</title><button id="signal">Signal change</button><p id="state" role="status">Ready</p>');
});
await new Promise(done=>server.listen(0,'127.0.0.1',done));const origin='http://127.0.0.1:'+server.address().port;
const browser=await chromium.launch({channel:'chrome',headless:true});
try{
 const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto(origin);
 await page.evaluate(()=>{
  window.p=()=>{};window.sys={singletons:{relay:{__data:{enabled:true}}},http:{renderedUrl:()=>'/refresh'},lib:{add:(_setup,ctor)=>window.Relay=ctor},core:{server:{inFlight:0,addHeaders(){},onIdle(fn){queueMicrotask(fn);}}}};
  window.successes=0;window.failures=0;
  sys.core.server.call=class{constructor(data){this.data=data;}send(){
   const hooks={done(fn){hooks.success=fn},fail(fn){hooks.failure=fn}}; // Intentionally no chaining.
   sys.core.server.inFlight++;
   fetch('/refresh',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(this.data)})
    .then(async r=>{if(!r.ok)throw Error('HTTP '+r.status);return r.json();})
    .then(value=>{sys.core.server.inFlight--;successes++;hooks.success?.(value);document.querySelector('#state').textContent='Updated';})
    .catch(error=>{sys.core.server.inFlight--;failures++;hooks.failure?.(error);document.querySelector('#state').textContent='Retrying';});
   return hooks;
  }};
 });
 await page.addScriptTag({content:await readFile(new URL('../src/relay.js',import.meta.url),'utf8')});
 await page.evaluate(()=>{window.relay={};Relay.call(relay);document.querySelector('#signal').onclick=()=>relay.heard('data:pages');});
 await page.getByRole('button',{name:'Signal change'}).click();
 await page.waitForFunction(()=>successes===1,{},{timeout:5000});
 assert.equal(await page.evaluate(()=>failures),1);assert.deepEqual(received,[['data:pages'],['data:pages']]);
 await page.getByRole('button',{name:'Signal change'}).click();await page.waitForFunction(()=>successes===2);
 assert.equal(received.length,3);assert.deepEqual(errors,[]);
 console.log('PASS: actual Relay in browser survives non-chainable callbacks, HTTP failure and retry, delivers the next update and throws no JS errors.');
}finally{await browser.close();await new Promise(done=>server.close(done));}
