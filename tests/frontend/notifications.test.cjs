const {test}=require('node:test');
const assert=require('node:assert/strict');
const {readFileSync}=require('node:fs');
const path=require('node:path');
const {JSDOM}=require('jsdom');
const script=readFileSync(path.join(__dirname,'../../wp-content/plugins/mma-future-data-engine/assets/notifications.js'),'utf8');
const flush=async()=>{await new Promise(resolve=>setTimeout(resolve,100));await new Promise(setImmediate);};

function setup(html,handler){
  const dom=new JSDOM(html,{url:'https://site.test/fighters/example/',runScripts:'outside-only'});const win=dom.window;const calls=[];
  win.mmafNotifications={ajaxUrl:'https://site.test/wp-admin/admin-ajax.php',restUrl:'https://site.test/wp-json/mma-future/v1/notifications/',loginUrl:'https://site.test/login/',accountUrl:'https://site.test/account/',featureEnabled:true};
  win.fetch=async(url,options={})=>{calls.push({url:String(url),options});return handler(String(url),options);};win.eval(script);return{dom,win,doc:win.document,calls};
}
const json=data=>({ok:true,json:async()=>data});

test('loads grouped follow state and keeps duplicate fighter buttons synchronized',async()=>{
  const t=setup('<button data-mmaf-follow="17"><span data-mmaf-follow-label>Follow fighter</span></button><button data-mmaf-follow="17"><span data-mmaf-follow-label>Follow fighter</span></button>',(url,options)=>{
    if(url.includes('admin-ajax'))return json({success:true,data:{loggedIn:true,nonce:'nonce'}});
    if(url.includes('/state'))return json({following:{17:true}});
    if(options.method==='DELETE')return json({fighter_id:17,following:false});
    throw Error('Unexpected request '+url);
  });
  try{await flush();const buttons=[...t.doc.querySelectorAll('[data-mmaf-follow]')];assert.ok(buttons.every(b=>b.getAttribute('aria-pressed')==='true'));buttons[0].click();await flush();assert.ok(buttons.every(b=>b.getAttribute('aria-pressed')==='false'));assert.equal(t.calls.filter(c=>c.url.includes('/state')).length,1);assert.equal(t.calls.filter(c=>c.options.method==='DELETE').length,1);}finally{t.dom.window.close();}
});

test('email login uses a neutral response and reminder activation is idempotent in the UI',async()=>{
  const t=setup('<form data-mmaf-email-login><input name="email" value="reader@example.test"><input name="fighter_id" value="8"><input name="return_to" value="https://site.test/fighters/a"><p data-mmaf-auth-message></p></form><button data-mmaf-reminder="44">Remind me</button>',(url,options)=>{
    if(url.includes('admin-ajax'))return json({success:true,data:{loggedIn:true,nonce:'nonce'}});
    if(url.endsWith('/auth/email'))return json({message:'If the address can use this sign-in method, a secure link is on its way.'});
    if(url.endsWith('/reminders'))return json({bout_id:44,active:true});
    if(url.includes('/state'))return json({following:{}});
    throw Error('Unexpected request '+url);
  });
  try{await flush();t.doc.querySelector('form').dispatchEvent(new t.win.Event('submit',{bubbles:true,cancelable:true}));await flush();assert.match(t.doc.querySelector('[data-mmaf-auth-message]').textContent,/secure link/i);t.doc.querySelector('[data-mmaf-reminder]').click();await flush();assert.equal(t.doc.querySelector('[data-mmaf-reminder]').textContent,'Reminder set');}finally{t.dom.window.close();}
});

test('new AJAX-rendered fighter buttons receive state without embedding a cached user value',async()=>{
  let states=0;const t=setup('<div id="results"></div>',(url)=>{if(url.includes('admin-ajax'))return json({success:true,data:{loggedIn:true,nonce:'nonce'}});if(url.includes('/state')){states++;return json({following:{99:true}});}throw Error('Unexpected request '+url);});
  try{await flush();t.doc.querySelector('#results').innerHTML='<button data-mmaf-follow="99"><span data-mmaf-follow-label>Follow fighter</span></button>';await flush();assert.equal(t.doc.querySelector('[data-mmaf-follow]').getAttribute('aria-pressed'),'true');assert.equal(states,1);}finally{t.dom.window.close();}
});
