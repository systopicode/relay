import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const instances=[];const timers=new Map();let timerId=0;let ctor;
class Client {
 constructor(){this.subs=new Map();this.stopped=false;instances.push(this);}
 on(){} connect(){} disconnect(){this.stopped=true;}
 getSubscription(c){return this.subs.get(c);}
 newSubscription(c){const sub={channel:c,on(){},subscribe(){},unsubscribe(){}};this.subs.set(c,sub);return sub;}
 removeSubscription(sub){this.subs.delete(sub.channel);}
}
const data={enabled:true,client:'/connection/',token:'test',channels:['data:project/table']};
const sys={singletons:{relay:{__data:data}},lib:{add:(s,c)=>ctor=c},core:{server:{addHeaders(){},inFlight:0,sendCall(){throw Error('disabled relay must not refresh');}}}};
const context={sys,Centrifuge:Client,p(){},location:{protocol:'https:',host:'example.test'},window:{crypto:null,addEventListener(){}},document:{visibilityState:'visible',hasFocus:()=>true,addEventListener(){}},setTimeout:(fn)=>{timers.set(++timerId,fn);return timerId;},clearTimeout:id=>timers.delete(id),console,Map,Set};
vm.runInNewContext(await readFile(new URL('../src/relay.js',import.meta.url),'utf8'),context);
const relay={};ctor.call(relay);relay.sync();assert.equal(instances.length,1);
relay.heard('data:project/table');assert.equal(timers.size,1);
data.enabled=false;relay.sync();assert.equal(instances[0].stopped,true);assert.equal(timers.size,0);
relay.heard('data:project/table');relay.refresh(true);assert.equal(timers.size,0);
data.enabled=true;relay.sync();assert.equal(instances.length,2);assert.equal(instances[1].subs.size,1);
console.log('ok: project stop disconnects, discards pending refreshes and can restart independently');
