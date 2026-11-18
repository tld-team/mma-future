const {test}=require('node:test');
const assert=require('node:assert/strict');
const {readFileSync}=require('node:fs');
const path=require('node:path');
const {JSDOM}=require('jsdom');
const script=readFileSync(path.join(__dirname,'../../wp-content/plugins/mma-future-data-engine/assets/notifications.js'),'utf8');
const flush=async()=>{await new Promise(resolve=>setTimeout(resolve,80));await new Promise(setImmediate);};

const form=(mode,extra='')=>`<form data-mmaf-auth="${mode}" novalidate>
  <input type="email" name="email"><p data-mmaf-auth-error="email" hidden></p>
  ${mode==='forgot'?'':'<input id="pw" type="password" name="password"><button type="button" data-mmaf-password-toggle aria-controls="pw" aria-pressed="false">Show</button><p data-mmaf-auth-error="password" hidden></p>'}
  <input type="hidden" name="fighter_id" value="8"><input type="hidden" name="return_to" value="https://site.test/fighters/a/">
  <button type="submit">Go</button><p data-mmaf-auth-message></p>
</form>${extra}`;

function setup(html,handler){
  const dom=new JSDOM(html,{url:'https://site.test/login/',runScripts:'outside-only'});const win=dom.window;const calls=[];
  win.mmafNotifications={ajaxUrl:'https://site.test/wp-admin/admin-ajax.php',restUrl:'https://site.test/wp-json/mma-future/v1/notifications/',loginUrl:'https://site.test/login/',accountUrl:'https://site.test/account/',featureEnabled:true};
  win.fetch=async(url,options={})=>{calls.push({url:String(url),options});return handler(String(url),options);};win.eval(script);
  const doc=win.document;
  return{dom,win,doc,calls,
    fill:(name,value)=>{doc.querySelector('[name="'+name+'"]').value=value;},
    submit:()=>doc.querySelector('form').dispatchEvent(new win.Event('submit',{bubbles:true,cancelable:true})),
    message:()=>doc.querySelector('[data-mmaf-auth-message]').textContent,
    error:field=>doc.querySelector('[data-mmaf-auth-error="'+field+'"]'),
    posts:()=>calls.filter(c=>c.options.method==='POST'&&!c.url.includes('admin-ajax'))};
}
const reply=(status,body)=>({ok:status>=200&&status<300,status,text:async()=>typeof body==='string'?body:JSON.stringify(body),json:async()=>body});
const session=()=>({ok:true,json:async()=>({success:true,data:{loggedIn:false,nonce:null}})});

test('signing in posts the email and the password exactly as typed, then goes where the server says',async()=>{
  const t=setup(form('login'),(url)=>url.includes('admin-ajax')?session():(url.endsWith('/auth/login')?reply(200,{redirect:'https://site.test/login/#signed-in'}):reply(404,{})));
  try{
    await flush();t.fill('email','  Reader@Example.test ');t.fill('password','  two  spaces & symbols! ');t.submit();await flush();
    const [post]=t.posts();assert.match(post.url,/\/notifications\/auth\/login$/);assert.equal(post.options.headers['Content-Type'],'application/json');
    assert.deepEqual(JSON.parse(post.options.body),{email:'Reader@Example.test',password:'  two  spaces & symbols! ',fighter_id:8,return_to:'https://site.test/fighters/a/'});
    assert.equal(t.win.location.hash,'#signed-in');assert.match(t.message(),/Signed in/);
  }finally{t.dom.window.close();}
});

test('a wrong password is explained without clearing the form or naming which part was wrong',async()=>{
  const t=setup(form('login'),(url)=>url.includes('admin-ajax')?session():reply(401,{code:'mmaf_auth',message:'That email and password don’t match.',data:{status:401,field:''}}));
  try{
    await flush();t.fill('email','reader@example.test');t.fill('password','wrong one');t.submit();
    assert.equal(t.doc.querySelector('button[type=submit]').disabled,true,'the button is held while the request is out');
    await flush();
    assert.equal(t.message(),'That email and password don’t match.');assert.equal(t.doc.querySelector('[name=password]').value,'wrong one');
    assert.equal(t.error('email').hidden,true);assert.equal(t.error('password').hidden,true);assert.equal(t.doc.querySelector('button[type=submit]').disabled,false);
  }finally{t.dom.window.close();}
});

test('registration shows a field problem beside the field, then the neutral answer',async()=>{
  let attempt=0;
  const t=setup(form('register'),(url)=>{
    if(url.includes('admin-ajax'))return session();
    return ++attempt===1?reply(400,{code:'mmaf_auth',message:'Use at least 8 characters.',data:{status:400,field:'password'}}):reply(200,{message:'Check your inbox. We sent a link to reader@example.test to finish creating your account.'});
  });
  try{
    await flush();t.fill('email','reader@example.test');t.fill('password','short');t.submit();await flush();
    assert.equal(t.error('password').hidden,false);assert.equal(t.error('password').textContent,'Use at least 8 characters.');
    assert.equal(t.doc.querySelector('[name=password]').getAttribute('aria-invalid'),'true');assert.equal(t.doc.activeElement,t.doc.querySelector('[name=password]'));assert.equal(t.message(),'');
    t.fill('password','long enough now');t.submit();await flush();
    assert.match(t.posts()[1].url,/\/auth\/register$/);assert.equal(t.error('password').hidden,true);assert.equal(t.doc.querySelector('[name=password]').hasAttribute('aria-invalid'),false);
    assert.equal(t.message(),'Check your inbox. We sent a link to reader@example.test to finish creating your account.');assert.equal(t.doc.querySelector('[name=password]').value,'','the password does not stay in the page');assert.equal(t.doc.querySelector('[name=email]').value,'reader@example.test');
  }finally{t.dom.window.close();}
});

test('asking for a password link needs only an address',async()=>{
  const t=setup(form('forgot'),(url)=>url.includes('admin-ajax')?session():reply(200,{message:'If an account exists for reader@example.test, a link to set a new password is on its way.'}));
  try{
    await flush();t.fill('email','reader@example.test');t.submit();await flush();
    assert.match(t.posts()[0].url,/\/auth\/password\/forgot$/);assert.equal(JSON.parse(t.posts()[0].options.body).password,'');assert.match(t.message(),/on its way\.$/);
  }finally{t.dom.window.close();}
});

test('an empty form is caught in the page, and a maintenance answer is explained',async()=>{
  const t=setup(form('login'),(url)=>url.includes('admin-ajax')?session():reply(503,'MMA Future is briefly paused for maintenance.'));
  try{
    await flush();t.submit();await flush();
    assert.equal(t.error('email').textContent,'Enter your email address.');assert.equal(t.posts().length,0);
    t.fill('email','reader@example.test');t.submit();await flush();
    assert.equal(t.error('email').hidden,true);assert.equal(t.error('password').textContent,'Enter your password.');assert.equal(t.posts().length,0);
    t.fill('password','something long');t.submit();await flush();
    assert.equal(t.posts().length,1);assert.match(t.message(),/paused for a moment/);assert.equal(t.doc.querySelector('[name=password]').value,'something long');
  }finally{t.dom.window.close();}
});

test('the password can be shown and hidden again',async()=>{
  const t=setup(form('register'),(url)=>url.includes('admin-ajax')?session():reply(404,{}));
  try{
    await flush();const toggle=t.doc.querySelector('[data-mmaf-password-toggle]'),input=t.doc.querySelector('#pw');t.fill('password','visible?');
    toggle.click();assert.equal(input.type,'text');assert.equal(toggle.getAttribute('aria-pressed'),'true');assert.equal(toggle.textContent,'Hide');assert.equal(input.value,'visible?');
    toggle.click();assert.equal(input.type,'password');assert.equal(toggle.getAttribute('aria-pressed'),'false');assert.equal(toggle.textContent,'Show');assert.equal(t.posts().length,0);
  }finally{t.dom.window.close();}
});

test('a signed-in reader can ask for a password link from the account page',async()=>{
  const t=setup('<button type="button" data-mmaf-password-link>Email me a password link</button><p data-mmaf-password-message></p>',(url,options)=>{
    if(url.includes('admin-ajax'))return {ok:true,json:async()=>({success:true,data:{loggedIn:true,nonce:'nonce-9'}})};
    if(url.endsWith('/password-link'))return {ok:true,status:200,json:async()=>({message:'We sent a link to reader@example.test. It works for one hour.'})};
    throw Error('Unexpected request '+url);
  });
  try{
    await flush();t.doc.querySelector('[data-mmaf-password-link]').click();await flush();
    const post=t.calls.find(c=>c.url.endsWith('/password-link'));assert.equal(post.options.method,'POST');assert.equal(post.options.headers['X-WP-Nonce'],'nonce-9');
    assert.equal(t.doc.querySelector('[data-mmaf-password-message]').textContent,'We sent a link to reader@example.test. It works for one hour.');assert.equal(t.doc.querySelector('[data-mmaf-password-link]').disabled,false);
  }finally{t.dom.window.close();}
});
