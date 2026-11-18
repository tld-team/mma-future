const {test}=require('node:test');
const assert=require('node:assert/strict');
const {readFileSync}=require('node:fs');
const path=require('node:path');
const {JSDOM}=require('jsdom');
const script=readFileSync(path.join(__dirname,'../../wp-content/plugins/mma-future-data-engine/assets/community.js'),'utf8');
const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const flush=async()=>{await wait(60);await new Promise(setImmediate);};

const FORM=`<form data-mmafc-profile data-has-profile="1" novalidate>
  <img data-mmafc-avatar src="https://site.test/placeholder.svg">
  <input type="file" data-mmafc-avatar-input><button type="button" data-mmafc-avatar-remove hidden>Remove</button><p data-mmafc-error="avatar" hidden></p>
  <input name="display_name" value="Nikola"><p data-mmafc-error="display_name" hidden></p>
  <input name="handle" data-mmafc-handle data-saved="nikola" value="nikola"><strong data-mmafc-handle-preview>nikola</strong><p data-mmafc-handle-status></p><p data-mmafc-error="handle" hidden></p>
  <span data-mmafc-bio-count></span><textarea name="bio" data-mmafc-bio maxlength="280">Hello</textarea><p data-mmafc-error="bio" hidden></p>
  <input name="socials[instagram]" data-mmafc-social="instagram" value=""><p data-mmafc-error="socials.instagram" hidden></p>
  <p data-mmafc-save-status></p><a data-mmafc-public-link hidden href="">View public profile</a><button type="submit" data-mmafc-save>Save profile</button>
</form>`;
const PROFILE={has_profile:true,handle:'nikola',display_name:'Nikola',bio:'Hello',socials:{},avatar_url:'https://site.test/placeholder.svg',has_avatar:false,url:'https://site.test/fan/nikola/'};

function setup(html,handler){
  const dom=new JSDOM(html,{url:'https://site.test/account/profile/',runScripts:'outside-only'});const win=dom.window;const calls=[];
  win.mmafCommunity={ajaxUrl:'https://site.test/wp-admin/admin-ajax.php',restUrl:'https://site.test/wp-json/mma-future/v1/community/',maxAvatarBytes:2097152};
  win.fetch=async(url,options={})=>{calls.push({url:String(url),options});return handler(String(url),options,calls);};win.eval(script);return{dom,win,doc:win.document,calls};
}
const reply=(status,body)=>({ok:status>=200&&status<300,status,text:async()=>typeof body==='string'?body:JSON.stringify(body),json:async()=>body});
const sessionReply=(nonce='nonce-1',handle='nikola')=>reply(200,{success:true,data:{loggedIn:true,nonce,handle}});
const type=(t,selector,value)=>{const el=t.doc.querySelector(selector);el.value=value;el.dispatchEvent(new t.win.Event('input',{bubbles:true}));return el;};
const submit=t=>t.doc.querySelector('form').dispatchEvent(new t.win.Event('submit',{bubbles:true,cancelable:true}));
const status=t=>t.doc.querySelector('[data-mmafc-save-status]');

test('saving reads the session with a GET and posts the normalised profile with the nonce',async()=>{
  const t=setup(FORM,(url,options)=>{
    if(url.includes('admin-ajax'))return sessionReply();
    if(url.endsWith('/profile')&&options.method==='POST')return reply(200,{...PROFILE,handle:'nikola_g',display_name:'Nikola G',bio:'Calling prospects early.',socials:{instagram:'nikola.g'},url:'https://site.test/fan/nikola_g/'});
    if(url.includes('/profile/handle'))return reply(200,{available:true,message:''});
    throw Error('Unexpected request '+url);
  });
  try{
    await flush();
    type(t,'[name=display_name]','Nikola G');type(t,'[data-mmafc-handle]','  @Nikola_G ');type(t,'[data-mmafc-bio]','Calling prospects early.');type(t,'[data-mmafc-social=instagram]','https://www.instagram.com/nikola.g/');
    assert.equal(status(t).textContent,'Unsaved changes');assert.equal(t.doc.querySelector('[data-mmafc-bio-count]').textContent,'24 / 280');
    submit(t);await flush();
    const session=t.calls.find(c=>c.url.includes('admin-ajax'));assert.equal(session.options.method,'GET');assert.match(session.url,/[?&]action=mmafc_session/);assert.equal(session.options.body,undefined);
    assert.ok(t.calls.every(c=>!c.url.includes('admin-ajax')||c.options.method==='GET'),'the session is never requested with a write');
    const save=t.calls.find(c=>c.url.endsWith('/profile'));assert.equal(save.options.headers['X-WP-Nonce'],'nonce-1');
    assert.deepEqual(JSON.parse(save.options.body),{handle:'nikola_g',display_name:'Nikola G',bio:'Calling prospects early.',socials:{instagram:'https://www.instagram.com/nikola.g/'}});
    assert.equal(status(t).textContent,'Profile saved');assert.equal(t.doc.querySelector('[data-mmafc-social=instagram]').value,'nikola.g');
    const link=t.doc.querySelector('[data-mmafc-public-link]');assert.equal(link.hidden,false);assert.equal(link.href,'https://site.test/fan/nikola_g/');
    assert.equal(t.doc.querySelector('[data-mmafc-handle]').getAttribute('data-saved'),'nikola_g');
  }finally{t.dom.window.close();}
});

test('a rejected save shows each message beside its field and keeps what was typed',async()=>{
  const t=setup(FORM,(url,options)=>{
    if(url.includes('admin-ajax'))return sessionReply();
    if(url.endsWith('/profile')&&options.method==='POST')return reply(400,{code:'mmafc_invalid',message:'Keep your bio to 280 characters.',data:{status:400,fields:{bio:'Keep your bio to 280 characters.','socials.instagram':'Enter your Instagram username.'}}});
    throw Error('Unexpected request '+url);
  });
  try{
    await flush();
    type(t,'[data-mmafc-bio]','Too long');type(t,'[data-mmafc-social=instagram]','not a name');submit(t);await flush();
    const bioError=t.doc.querySelector('[data-mmafc-error=bio]');assert.equal(bioError.hidden,false);assert.equal(bioError.textContent,'Keep your bio to 280 characters.');
    assert.equal(t.doc.querySelector('[data-mmafc-bio]').getAttribute('aria-invalid'),'true');assert.equal(t.doc.querySelector('[data-mmafc-social=instagram]').getAttribute('aria-invalid'),'true');
    assert.equal(t.doc.querySelector('[data-mmafc-error="socials.instagram"]').textContent,'Enter your Instagram username.');
    assert.equal(status(t).textContent,'Check the highlighted fields.');assert.equal(t.doc.querySelector('[data-mmafc-bio]').value,'Too long');
    type(t,'[data-mmafc-bio]','Shorter');assert.equal(bioError.hidden,true);assert.equal(t.doc.querySelector('[data-mmafc-bio]').hasAttribute('aria-invalid'),false);
  }finally{t.dom.window.close();}
});

test('a plain-text maintenance response is explained and nothing typed is lost',async()=>{
  const t=setup(FORM,(url,options)=>{
    if(url.includes('admin-ajax'))return sessionReply();
    if(options.method==='POST')return reply(503,'MMA Future is briefly paused for maintenance.');
    throw Error('Unexpected request '+url);
  });
  try{
    await flush();
    type(t,'[name=display_name]','Still Here');submit(t);await flush();
    assert.match(status(t).textContent,/paused for a moment/);assert.ok(status(t).classList.contains('is-error'));assert.equal(t.doc.querySelector('[name=display_name]').value,'Still Here');
    const event=new t.win.Event('beforeunload',{cancelable:true});t.win.dispatchEvent(event);assert.equal(event.defaultPrevented,true,'leaving with unsaved changes is still guarded');
  }finally{t.dom.window.close();}
});

test('an expired nonce is refreshed once and the save is retried',async()=>{
  let sessions=0;
  const t=setup(FORM,(url,options)=>{
    if(url.includes('admin-ajax')){sessions++;return sessionReply('nonce-'+sessions);}
    if(options.method==='POST')return options.headers['X-WP-Nonce']==='nonce-1'?reply(403,{code:'rest_cookie_invalid_nonce',message:'Cookie check failed',data:{status:403}}):reply(200,PROFILE);
    throw Error('Unexpected request '+url);
  });
  try{
    await flush();
    type(t,'[name=display_name]','Nikola');submit(t);await flush();
    const saves=t.calls.filter(c=>c.options.method==='POST');assert.deepEqual(saves.map(c=>c.options.headers['X-WP-Nonce']),['nonce-1','nonce-2']);assert.equal(status(t).textContent,'Profile saved');
  }finally{t.dom.window.close();}
});

test('the handle is checked after a pause and only the latest answer is shown',async()=>{
  const t=setup(FORM,(url)=>{
    if(url.includes('admin-ajax'))return sessionReply();
    if(url.includes('/profile/handle'))return url.endsWith('value=taken_one')?reply(200,{available:false,message:'That handle’s already taken. Try another.'}):reply(200,{available:true,message:''});
    throw Error('Unexpected request '+url);
  });
  try{
    await flush();
    type(t,'[data-mmafc-handle]','tak');type(t,'[data-mmafc-handle]','Taken_One');
    assert.equal(t.doc.querySelector('[data-mmafc-handle-preview]').textContent,'nikola','the preview waits for the pause too');
    await wait(450);await flush();
    const checks=t.calls.filter(c=>c.url.includes('/profile/handle'));assert.equal(checks.length,1);assert.match(checks[0].url,/value=taken_one$/);
    assert.equal(t.doc.querySelector('[data-mmafc-handle-status]').textContent,'That handle’s already taken. Try another.');assert.equal(t.doc.querySelector('[data-mmafc-handle-preview]').textContent,'taken_one');
    type(t,'[data-mmafc-handle]','free_one');await wait(450);await flush();
    const state=t.doc.querySelector('[data-mmafc-handle-status]');assert.equal(state.textContent,'@free_one is available');assert.ok(state.classList.contains('is-ok'));
    type(t,'[data-mmafc-handle]','nikola');await wait(450);await flush();
    assert.equal(state.textContent,'');assert.equal(t.calls.filter(c=>c.url.includes('/profile/handle')).length,2,'the saved handle is not checked against itself');
  }finally{t.dom.window.close();}
});

test('a first photo saves the profile before it is uploaded, and a wrong file never leaves the page',async()=>{
  const t=setup(FORM.replace('data-has-profile="1"','data-has-profile="0"'),(url,options)=>{
    if(url.includes('admin-ajax'))return sessionReply('nonce-1',null);
    if(url.endsWith('/profile')&&options.method==='POST')return reply(200,PROFILE);
    if(url.endsWith('/profile/avatar')&&options.method==='POST')return reply(200,{...PROFILE,avatar_url:'https://site.test/uploads/mmaf-avatars/abc.webp',has_avatar:true});
    throw Error('Unexpected request '+url);
  });
  const choose=file=>{const input=t.doc.querySelector('[data-mmafc-avatar-input]');Object.defineProperty(input,'files',{configurable:true,value:[file]});input.dispatchEvent(new t.win.Event('change',{bubbles:true}));};
  try{
    await flush();
    choose(new t.win.File(['%PDF'],'cv.pdf',{type:'application/pdf'}));await flush();
    assert.equal(t.doc.querySelector('[data-mmafc-error=avatar]').textContent,'Use a JPG, PNG or WebP image.');assert.equal(t.calls.length,0);
    choose(new t.win.File(['png'],'me.png',{type:'image/png'}));await flush();
    const writes=t.calls.filter(c=>c.options.method==='POST').map(c=>c.url.replace(/^.*\/community\//,''));assert.deepEqual(writes,['profile','profile/avatar']);
    const upload=t.calls.find(c=>c.url.endsWith('/profile/avatar'));assert.ok(upload.options.body instanceof t.win.FormData);assert.equal(upload.options.headers['Content-Type'],undefined,'the browser sets the multipart boundary');
    assert.equal(t.doc.querySelector('[data-mmafc-avatar]').src,'https://site.test/uploads/mmaf-avatars/abc.webp');assert.equal(t.doc.querySelector('[data-mmafc-avatar-remove]').hidden,false);
    assert.equal(t.doc.querySelector('[data-mmafc-error=avatar]').hidden,true);
  }finally{t.dom.window.close();}
});

test('only the owner of a public profile is shown the edit link',async()=>{
  const page=handle=>`<article data-mmafc-public-profile="${handle}"><p data-mmafc-owner hidden><a href="/account/profile/">Edit profile</a></p></article>`;
  const own=setup(page('nikola'),url=>{if(url.includes('admin-ajax'))return sessionReply();throw Error('Unexpected request '+url);});
  const other=setup(page('someone_else'),url=>{if(url.includes('admin-ajax'))return sessionReply();throw Error('Unexpected request '+url);});
  const guest=setup(page('nikola'),url=>{if(url.includes('admin-ajax'))return reply(200,{success:true,data:{loggedIn:false,nonce:null,handle:null}});throw Error('Unexpected request '+url);});
  try{
    await flush();
    assert.equal(own.doc.querySelector('[data-mmafc-owner]').hidden,false);assert.equal(other.doc.querySelector('[data-mmafc-owner]').hidden,true);assert.equal(guest.doc.querySelector('[data-mmafc-owner]').hidden,true);
    assert.ok([own,other,guest].every(t=>t.calls.length===1&&t.calls[0].options.method==='GET'));
  }finally{own.dom.window.close();other.dom.window.close();guest.dom.window.close();}
});

const PROPS=`<article data-mmafc-public-profile="ana"><p data-mmafc-owner hidden><a href="#">Edit profile</a></p></article>
<div data-mmafc-props="ana"><p data-mmafc-props-totals>No props yet.</p>
  <div data-mmafc-props-give hidden><button type="button" data-mmafc-props-value="1" aria-pressed="false">Solid</button><button type="button" data-mmafc-props-value="2" aria-pressed="false">Sharp</button><button type="button" data-mmafc-props-value="3" aria-pressed="false">Elite</button></div>
  <p data-mmafc-props-reason hidden></p><p data-mmafc-props-status></p></div>`;
const LABELS={1:'Solid',2:'Sharp',3:'Elite'};
const propsState=(extra={})=>({totals:{1:0,2:1,3:0,count:1},mine:null,can_rate:true,reason:'',labels:LABELS,...extra});

test('props show the viewer’s own rating, can be changed, and pressing the chosen one withdraws it',async()=>{
  const t=setup(PROPS,(url,options)=>{
    if(url.includes('admin-ajax'))return sessionReply('nonce-1','nikola');
    if(url.includes('/props?handle=ana')&&options.method==='DELETE')return reply(200,propsState());
    if(url.includes('/props?handle=ana'))return reply(200,propsState({mine:2}));
    if(url.endsWith('/props')&&options.method==='POST')return reply(200,propsState({totals:{1:0,2:0,3:1,count:1},mine:JSON.parse(options.body).value}));
    throw Error('Unexpected request '+url);
  });
  try{
    await flush();
    const give=t.doc.querySelector('[data-mmafc-props-give]'),pressed=()=>[...t.doc.querySelectorAll('[data-mmafc-props-value]')].filter(b=>b.getAttribute('aria-pressed')==='true').map(b=>b.textContent);
    assert.equal(give.hidden,false);assert.deepEqual(pressed(),['Sharp']);assert.equal(t.doc.querySelector('[data-mmafc-props-totals]').textContent,'Solid 0 · Sharp 1 · Elite 0');
    t.doc.querySelector('[data-mmafc-props-value="3"]').click();await flush();
    const post=t.calls.find(c=>c.options.method==='POST');assert.deepEqual(JSON.parse(post.options.body),{handle:'ana',value:3});assert.equal(post.options.headers['X-WP-Nonce'],'nonce-1');
    assert.deepEqual(pressed(),['Elite']);assert.equal(t.doc.querySelector('[data-mmafc-props-status]').textContent,'Props given.');
    t.doc.querySelector('[data-mmafc-props-value="3"]').click();await flush();
    assert.ok(t.calls.some(c=>c.options.method==='DELETE'));assert.deepEqual(pressed(),[]);assert.equal(t.doc.querySelector('[data-mmafc-props-status]').textContent,'Props withdrawn.');
  }finally{t.dom.window.close();}
});

test('a reader who cannot give props is told why, and the owner of the list is not',async()=>{
  const visitor=setup(PROPS,url=>url.includes('admin-ajax')?reply(200,{success:true,data:{loggedIn:false,nonce:null,handle:null}}):reply(200,propsState({can_rate:false,reason:'Sign in to give props.'})));
  const owner=setup(PROPS,url=>url.includes('admin-ajax')?sessionReply('nonce-1','ana'):reply(200,propsState({can_rate:false,reason:'You can’t give props to your own Top 10.'})));
  try{
    await flush();
    assert.equal(visitor.doc.querySelector('[data-mmafc-props-give]').hidden,true);
    const reason=visitor.doc.querySelector('[data-mmafc-props-reason]');assert.equal(reason.hidden,false);assert.equal(reason.textContent,'Sign in to give props.');
    assert.equal(owner.doc.querySelector('[data-mmafc-props-give]').hidden,true);assert.equal(owner.doc.querySelector('[data-mmafc-props-reason]').hidden,true);assert.equal(owner.doc.querySelector('[data-mmafc-owner]').hidden,false);
  }finally{visitor.dom.window.close();owner.dom.window.close();}
});
