const {test}=require('node:test');
const assert=require('node:assert/strict');
const {readFileSync}=require('node:fs');
const path=require('node:path');
const {JSDOM,VirtualConsole}=require('jsdom');
const asset=name=>readFileSync(path.join(__dirname,'../../wp-content/plugins/mma-future-data-engine/assets/'+name),'utf8');
const scripts=[asset('community.js'),asset('community-comments.js')];
const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const flush=async()=>{await wait(60);await new Promise(setImmediate);};

const SHELL=`<section data-mmafc-comments="42"><h2 id="mmafc-comments-title">Discussion</h2>
  <div><button type="button" data-mmafc-sort="best" aria-pressed="true">Best</button><button type="button" data-mmafc-sort="new" aria-pressed="false">New</button></div>
  <div data-mmafc-comments-compose></div><p data-mmafc-comments-status></p><ol data-mmafc-thread></ol></section>`;
const author=(name,extra={})=>({name,handle:name.toLowerCase(),url:'https://site.test/fan/'+name.toLowerCase()+'/',avatar:'https://site.test/a.svg',badge:'<svg class="mmafc-badge"></svg>',level:'2 · Spotter',staff:false,...extra});
const comment=(id,extra={})=>({id,parent:0,author:author('Ana'),content:'Comment '+id,date:'2026-11-18T10:0'+(id%10)+':00+00:00',date_text:'1 hour ago',score:0,mine:0,own:false,collapsed:false,depth:0,...extra});
const thread=(extra={})=>({post_id:42,count:4,order:[2,1],comments:[comment(1),comment(2,{score:5}),comment(3,{parent:2,depth:1,content:'Reply 3'}),comment(4,{parent:3,depth:2,content:'Reply 4',own:true})],can_post:true,reason:'',signed_in:true,comments_open:true,can_vote:true,can_down:true,login_url:'https://site.test/login/?return_to=x',profile_url:'https://site.test/account/profile/',max_length:2000,...extra});
const reply=(status,body)=>({ok:status>=200&&status<300,status,text:async()=>JSON.stringify(body),json:async()=>body});

function setup(handler,session={loggedIn:true,nonce:'nonce-1',handle:'nikola'}){
  // jsdom cannot navigate; it reports each attempted navigation here instead.
  const virtualConsole=new VirtualConsole();const errors=[];virtualConsole.on('jsdomError',e=>errors.push(e.message));
  const dom=new JSDOM(SHELL,{url:'https://site.test/blog/article/',runScripts:'outside-only',virtualConsole});const win=dom.window;const calls=[];
  win.mmafCommunity={ajaxUrl:'https://site.test/wp-admin/admin-ajax.php',restUrl:'https://site.test/wp-json/mma-future/v1/community/'};
  win.fetch=async(url,options={})=>{calls.push({url:String(url),options});if(String(url).includes('admin-ajax'))return reply(200,{success:true,data:session});return handler(String(url),options,calls);};
  scripts.forEach(script=>win.eval(script));
  const doc=win.document;
  return{dom,win,doc,calls,errors,ids:()=>[...doc.querySelectorAll('[data-mmafc-thread] [data-id]')].map(n=>Number(n.getAttribute('data-id'))),
    top:()=>[...doc.querySelectorAll('[data-mmafc-thread] > [data-id]')].map(n=>Number(n.getAttribute('data-id'))),status:()=>doc.querySelector('[data-mmafc-comments-status]').textContent};
}

test('the discussion draws top-level comments in the order given, with replies nested under them',async()=>{
  const t=setup(url=>url.includes('comments?post_id=42&sort=best')?reply(200,thread()):reply(200,thread({order:[1,2]})));
  try{
    await flush();
    assert.deepEqual(t.top(),[2,1]);assert.deepEqual(t.ids(),[2,3,4,1]);
    assert.equal(t.doc.querySelector('#mmafc-comments-title').textContent,'Discussion (4)');
    const own=t.doc.querySelector('[data-id="4"]');assert.equal(own.querySelectorAll(':scope > .mmafc-vote [data-mmafc-vote]').length,0,'no votes on one’s own comment');assert.equal(own.querySelector('[data-mmafc-report]'),null);
    assert.equal(t.doc.querySelector('[data-id="1"] .mmafc-comment__name').getAttribute('href'),'https://site.test/fan/ana/');
    assert.ok(t.doc.querySelector('[data-id="1"] .mmafc-comment__badge svg'));
    t.doc.querySelector('[data-mmafc-sort="new"]').click();await flush();
    assert.deepEqual(t.top(),[1,2]);assert.equal(t.doc.querySelector('[data-mmafc-sort="new"]').getAttribute('aria-pressed'),'true');
  }finally{t.dom.window.close();}
});

test('posting a comment sends it with the nonce and reloads the discussion',async()=>{
  let loaded=0;
  const t=setup((url,options)=>{
    if(url.endsWith('/comments')&&options.method==='POST')return reply(200,thread());
    if(url.includes('comments?post_id=42')){loaded++;return reply(200,thread());}
    throw Error('Unexpected '+url);
  });
  try{
    await flush();
    const box=t.doc.querySelector('[data-mmafc-comments-compose] textarea');box.value='Great card this weekend.';box.dispatchEvent(new t.win.Event('input'));
    t.doc.querySelector('[data-mmafc-comments-compose] form').dispatchEvent(new t.win.Event('submit',{cancelable:true}));await flush();
    const post=t.calls.find(c=>c.options.method==='POST');assert.deepEqual(JSON.parse(post.options.body),{post_id:42,content:'Great card this weekend.',parent:0});assert.equal(post.options.headers['X-WP-Nonce'],'nonce-1');
    assert.equal(loaded,2);assert.equal(box.value,'');assert.equal(t.status(),'Comment posted.');
  }finally{t.dom.window.close();}
});

test('a rejected comment shows the reason beside the box and keeps the text',async()=>{
  const t=setup((url,options)=>options.method==='POST'?reply(400,{code:'mmafc_invalid',message:'x',data:{status:400,fields:{content:'You’re commenting quickly. Wait a moment and try again.'}}}):reply(200,thread()));
  try{
    await flush();
    const box=t.doc.querySelector('[data-mmafc-comments-compose] textarea');box.value='Again';
    t.doc.querySelector('[data-mmafc-comments-compose] form').dispatchEvent(new t.win.Event('submit',{cancelable:true}));await flush();
    const error=t.doc.querySelector('[data-mmafc-comments-compose] .mmafc-error');assert.equal(error.hidden,false);assert.match(error.textContent,/commenting quickly/);assert.equal(box.value,'Again');
  }finally{t.dom.window.close();}
});

test('a vote is toggled, and a comment that falls to the hold score leaves the page',async()=>{
  const t=setup((url,options)=>{
    if(url.endsWith('/comments/1/vote')){const v=JSON.parse(options.body).value;return reply(200,{score:v,mine:v,status:'approved'});}
    if(url.endsWith('/comments/2/vote'))return reply(200,{score:-5,mine:-1,status:'unapproved'});
    return reply(200,thread());
  });
  try{
    await flush();
    const up=t.doc.querySelector('[data-id="1"] > .mmafc-vote [data-mmafc-vote="1"]');up.click();await flush();
    assert.equal(up.getAttribute('aria-pressed'),'true');assert.equal(t.doc.querySelector('[data-id="1"] > .mmafc-vote .mmafc-vote__score').textContent,'1');
    up.click();await flush();
    assert.equal(JSON.parse(t.calls.filter(c=>c.url.endsWith('/comments/1/vote'))[1].options.body).value,0,'pressing the same vote again takes it back');assert.equal(up.getAttribute('aria-pressed'),'false');
    t.doc.querySelector('[data-id="2"] > .mmafc-vote [data-mmafc-vote="-1"]').click();await flush();
    assert.equal(t.doc.querySelector('[data-id="2"]'),null);assert.match(t.status(),/waiting for a moderator/);
  }finally{t.dom.window.close();}
});

test('a reply is posted under its comment, and a report is acknowledged',async()=>{
  const t=setup((url,options)=>{
    if(url.endsWith('/comments/1/report'))return reply(200,{reported:true,held:false});
    if(url.endsWith('/comments')&&options.method==='POST')return reply(200,thread());
    return reply(200,thread());
  });
  try{
    await flush();
    t.doc.querySelector('[data-id="1"] [data-mmafc-reply]').click();
    const box=t.doc.querySelector('[data-id="1"] .mmafc-comment__reply textarea');assert.ok(box,'the reply box opens under the comment');
    box.value='Agreed.';t.doc.querySelector('[data-id="1"] .mmafc-comment__reply form').dispatchEvent(new t.win.Event('submit',{cancelable:true}));await flush();
    assert.deepEqual(JSON.parse(t.calls.find(c=>c.options.method==='POST'&&c.url.endsWith('/comments')).options.body),{post_id:42,content:'Agreed.',parent:1});
    t.doc.querySelector('[data-id="1"] [data-mmafc-report]').click();await flush();
    assert.equal(t.doc.querySelector('[data-id="1"] [data-mmafc-report]').textContent,'Reported');assert.match(t.status(),/Thanks for the report/);
  }finally{t.dom.window.close();}
});

test('a signed-out visitor reads the discussion; votes, replies and reports open sign-in; a low-scored comment starts folded',async()=>{
  const t=setup(()=>reply(200,thread({signed_in:false,can_post:false,can_vote:false,can_down:false,reason:'Sign in to join the discussion.',comments:[comment(1,{score:-4,collapsed:true}),comment(2)],order:[2,1],count:2})),{loggedIn:false,nonce:null,handle:null});
  try{
    await flush();
    const note=t.doc.querySelector('[data-mmafc-comments-compose] .mmafc-note');assert.match(note.textContent,/^Sign in to join the discussion\./);assert.equal(note.querySelector('a').getAttribute('href'),'https://site.test/login/?return_to=x');
    assert.equal(t.doc.querySelectorAll('[data-id="2"] > .mmafc-vote [data-mmafc-vote]').length,2,'the score has both votes, visible to everyone');
    assert.equal(t.doc.querySelectorAll('[data-id="2"] [data-mmafc-reply]').length,1);assert.equal(t.doc.querySelectorAll('[data-id="2"] [data-mmafc-report]').length,1);
    t.doc.querySelector('[data-id="2"] > .mmafc-vote [data-mmafc-vote="1"]').click();await flush();
    t.doc.querySelector('[data-id="2"] [data-mmafc-reply]').click();await flush();
    t.doc.querySelector('[data-id="2"] [data-mmafc-report]').click();await flush();
    assert.equal(t.calls.filter(c=>c.options.method&&c.options.method!=='GET').length,0,'nothing is sent while signed out');
    assert.equal(t.doc.querySelector('[data-id="2"] .mmafc-comment__reply').childElementCount,0,'no reply box opens');
    assert.equal(t.errors.filter(m=>/navigation/i.test(m)).length,3,'each action goes to sign-in');
    const folded=t.doc.querySelector('[data-id="1"]');assert.equal(folded.querySelector('.mmafc-comment__text').hidden,true);
    folded.querySelector('.mmafc-comment__collapsed').click();assert.equal(folded.querySelector('.mmafc-comment__text').hidden,false);
  }finally{t.dom.window.close();}
});
