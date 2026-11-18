const {test}=require('node:test');
const assert=require('node:assert/strict');
const {readFileSync}=require('node:fs');
const path=require('node:path');
const {JSDOM}=require('jsdom');
const asset=name=>readFileSync(path.join(__dirname,'../../wp-content/plugins/mma-future-data-engine/assets/'+name),'utf8');
const scripts=[asset('community.js'),asset('community-editor.js')];
const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const flush=async()=>{await wait(80);await new Promise(setImmediate);};

const PAGE=`<div data-mmafc-editor data-size="10">
  <p data-mmafc-editor-note hidden></p>
  <p data-mmafc-total></p><ol data-mmafc-picks></ol><button type="button" data-mmafc-prefill hidden>Start from the official top 10</button>
  <section data-mmafc-field-zone><input type="search" data-mmafc-field-search><ul data-mmafc-field></ul><p data-mmafc-field-status></p><button type="button" data-mmafc-field-more hidden>Show more fighters</button></section>
  <p data-mmafc-live></p>
  <p data-mmafc-save-status></p><button type="button" data-mmafc-reset hidden>Reset</button><button type="button" data-mmafc-save>Save Top 10</button>
</div>`;
const POINTS=[25,18,15,12,10,8,6,4,2,1];
const ranked=n=>({fighter_id:n,display_name:'Fighter '+n,permalink:'https://site.test/fighters/'+n+'/',image:null,weight_class:'light_heavyweight',record:{wins:5,losses:1,draws:0},rank:n});
const listed=(n,extra={})=>({fighter_id:n,display_name:'Fighter '+n,permalink:'https://site.test/fighters/'+n+'/',image_url:'',weight_class:'light_heavyweight',record:'5-1',official_rank:n,eligible:true,note:'',...extra});
const list=(ids,revision=1,extra={})=>({revision,size:10,points:POINTS,counts:true,items:ids.map(id=>typeof id==='number'?listed(id):id),...extra});
const reply=(status,body)=>({ok:status>=200&&status<300,status,text:async()=>typeof body==='string'?body:JSON.stringify(body),json:async()=>body});
const field=(page=1,count=15,pages=1)=>reply(200,{items:Array.from({length:count},(_,i)=>ranked((page-1)*count+i+1)),page,total_pages:pages});

function setup(handler,before){
  const dom=new JSDOM(PAGE,{url:'https://site.test/account/top-10/',runScripts:'outside-only',pretendToBeVisual:true});const win=dom.window;const calls=[];
  win.mmafCommunity={ajaxUrl:'https://site.test/wp-admin/admin-ajax.php',restUrl:'https://site.test/wp-json/mma-future/v1/community/',rankingsUrl:'https://site.test/wp-json/mma-future/v1/rankings'};
  win.fetch=async(url,options={})=>{calls.push({url:String(url),options});return handler(String(url),options,calls);};
  if(before)before(win);
  scripts.forEach(script=>win.eval(script));
  const doc=win.document;
  return{dom,win,doc,calls,
    picks:()=>[...doc.querySelectorAll('[data-mmafc-picks] [data-id]')].map(row=>Number(row.getAttribute('data-id'))),
    fieldIds:()=>[...doc.querySelectorAll('[data-mmafc-field] [data-id]')].map(row=>Number(row.getAttribute('data-id'))),
    click:(id,act)=>doc.querySelector('[data-id="'+id+'"] [data-act="'+act+'"]').click(),
    status:()=>doc.querySelector('[data-mmafc-save-status]').textContent,
    live:()=>doc.querySelector('[data-mmafc-live]').textContent};
}
const routes=(saved,extra)=>(url,options,calls)=>{
  if(url.includes('admin-ajax'))return reply(200,{success:true,data:{loggedIn:true,nonce:'nonce-1',handle:'nikola',key:'reader-key'}});
  const custom=extra&&extra(url,options,calls);if(custom)return custom;
  if(url.endsWith('/top-10')&&(!options.method||options.method==='GET'))return reply(200,typeof saved==='function'?saved():saved);
  if(url.includes('/rankings?'))return field();
  throw Error('Unexpected request '+url);
};

test('shows the saved picks in ten numbered places and keeps them out of the field',async()=>{
  const t=setup(routes(list([3,1,7])));
  try{
    await flush();
    assert.deepEqual(t.picks(),[3,1,7]);
    const rows=[...t.doc.querySelectorAll('[data-mmafc-picks] > li')];assert.equal(rows.length,10);assert.equal(rows.filter(r=>r.classList.contains('mmafc-row--empty')).length,7);
    assert.deepEqual(rows.slice(0,4).map(r=>r.querySelector('.mmafc-row__points').textContent),['+25','+18','+15','+12']);
    assert.ok(!t.fieldIds().some(id=>[3,1,7].includes(id)));assert.equal(t.fieldIds().length,12);
    assert.equal(t.doc.querySelector('[data-mmafc-total]').textContent,'Adds 58 points to the fan ranking');
    assert.match(rows[0].querySelector('.mmafc-row__meta').textContent,/Light Heavyweight · 5-1 · Official #3/);
    assert.equal(t.doc.querySelector('[data-mmafc-prefill]').hidden,true);assert.equal(t.status(),'');
    const ranking=t.calls.find(c=>c.url.includes('/rankings?'));assert.match(ranking.url,/board=overall&per_page=50&page=1$/);
  }finally{t.dom.window.close();}
});

test('buttons reorder, add and remove, and the change is announced',async()=>{
  const t=setup(routes(list([3,1,7])));
  try{
    await flush();
    t.click(7,'up');assert.deepEqual(t.picks(),[3,7,1]);await wait(60);assert.equal(t.live(),'Fighter 7 moved to position 2.');
    assert.equal(t.doc.activeElement.getAttribute('data-act'),'up','focus stays on the control that was used');
    t.click(7,'up');assert.deepEqual(t.picks(),[7,3,1]);assert.equal(t.doc.activeElement.getAttribute('data-act'),'drag','at the top, focus moves to the handle because Up is unavailable');
    assert.equal(t.doc.querySelector('[data-id="7"] [data-act="up"]').disabled,true);assert.equal(t.doc.querySelector('[data-id="1"] [data-act="down"]').disabled,true);
    t.click(7,'down');assert.deepEqual(t.picks(),[3,7,1]);
    t.click(5,'add');assert.deepEqual(t.picks(),[3,7,1,5]);assert.ok(!t.fieldIds().includes(5));
    t.click(3,'remove');assert.deepEqual(t.picks(),[7,1,5]);assert.ok(t.fieldIds().includes(3));await wait(60);assert.equal(t.live(),'Fighter 3 removed from your Top 10.');
    assert.equal(t.status(),'Unsaved changes');assert.equal(t.doc.querySelector('[data-mmafc-reset]').hidden,false);
    t.click(5,'remove');t.click(7,'down');t.click(1,'down');t.click(3,'add');t.click(3,'up');t.click(3,'up');t.click(7,'down');
    assert.deepEqual(t.picks(),[3,1,7]);assert.equal(t.status(),'','returning to the saved order is not a change');assert.equal(t.doc.querySelector('[data-mmafc-reset]').hidden,true);
  }finally{t.dom.window.close();}
});

test('adding to a full list drops the tenth fighter back into the field',async()=>{
  const t=setup(routes(list([1,2,3,4,5,6,7,8,9,10])));
  try{
    await flush();
    t.click(12,'add');
    assert.deepEqual(t.picks(),[1,2,3,4,5,6,7,8,9,12]);assert.ok(t.fieldIds().includes(10));await wait(60);
    assert.equal(t.live(),'Fighter 12 added at position 10. Fighter 10 dropped out of your Top 10.');
  }finally{t.dom.window.close();}
});

test('the arrow keys move a fighter from its handle',async()=>{
  const t=setup(routes(list([3,1,7])));
  const press=(id,key)=>{const handle=t.doc.querySelector('[data-id="'+id+'"] [data-act="drag"]');handle.focus();const event=new t.win.KeyboardEvent('keydown',{key,bubbles:true,cancelable:true});handle.dispatchEvent(event);return event;};
  try{
    await flush();
    assert.equal(press(1,'ArrowUp').defaultPrevented,true);assert.deepEqual(t.picks(),[1,3,7]);
    assert.equal(t.doc.activeElement,t.doc.querySelector('[data-id="1"] [data-act="drag"]'));
    press(1,'ArrowDown');press(1,'ArrowDown');press(1,'ArrowDown');assert.deepEqual(t.picks(),[3,7,1],'a fighter cannot be moved past the last filled place');
    assert.equal(press(4,'ArrowUp').defaultPrevented,false,'a fighter in the field is added with its button, not the arrow keys');assert.deepEqual(t.picks(),[3,7,1]);
  }finally{t.dom.window.close();}
});

test('saving sends the order with the revision it started from',async()=>{
  const t=setup(routes(list([3,1,7],4),(url,options)=>url.endsWith('/top-10')&&options.method==='POST'?reply(200,list([1,3,7],5)):null));
  try{
    await flush();
    t.click(1,'up');assert.ok(t.win.localStorage.getItem('mmafc-top10:reader-key'),'an unsaved order is kept in the browser');
    t.doc.querySelector('[data-mmafc-save]').click();await flush();
    const save=t.calls.find(c=>c.options.method==='POST');assert.deepEqual(JSON.parse(save.options.body),{fighter_ids:[1,3,7],revision:4});assert.equal(save.options.headers['X-WP-Nonce'],'nonce-1');
    assert.equal(t.status(),'Top 10 saved');assert.equal(t.win.localStorage.getItem('mmafc-top10:reader-key'),null);assert.equal(t.doc.querySelector('[data-mmafc-reset]').hidden,true);
    t.click(7,'up');t.doc.querySelector('[data-mmafc-save]').click();await flush();
    assert.equal(JSON.parse(t.calls.filter(c=>c.options.method==='POST')[1].options.body).revision,5,'the next save starts from the new revision');
  }finally{t.dom.window.close();}
});

test('a save from a stale copy explains itself, and Reset loads what is stored',async()=>{
  let stored=list([3,1,7],4);
  const t=setup(routes(()=>stored,(url,options)=>url.endsWith('/top-10')&&options.method==='POST'?reply(409,{code:'mmafc_conflict',message:'Your Top 10 was changed in another tab or on another device. Reload to see the latest version.',data:{status:409}}):null));
  try{
    await flush();
    t.click(1,'up');t.doc.querySelector('[data-mmafc-save]').click();await flush();
    assert.match(t.status(),/changed in another tab/);assert.deepEqual(t.picks(),[1,3,7],'the reader’s order stays on screen');
    stored=list([9,8],6);t.doc.querySelector('[data-mmafc-reset]').click();await flush();
    assert.deepEqual(t.picks(),[9,8]);assert.equal(t.status(),'');assert.equal(t.win.localStorage.getItem('mmafc-top10:reader-key'),null);
  }finally{t.dom.window.close();}
});

test('an unsaved order is restored after a reload, unless the stored list has moved on',async()=>{
  const draft=revision=>win=>win.localStorage.setItem('mmafc-top10:reader-key',JSON.stringify({revision,picks:[{id:7,name:'Fighter 7',url:'',image:'',division:'',record:'',official:7,eligible:true,note:''},{id:3,name:'Fighter 3',url:'',image:'',division:'',record:'',official:3,eligible:true,note:''}]}));
  const same=setup(routes(list([3,1,7],4)),draft(4));
  const moved=setup(routes(list([3,1,7],5)),draft(4));
  const broken=setup(routes(list([3,1,7],4)),win=>win.localStorage.setItem('mmafc-top10:reader-key','{"revision":4,"picks":[{"id":"<img>"}]}'));
  try{
    await flush();
    assert.deepEqual(same.picks(),[7,3]);assert.equal(same.status(),'Restored your unsaved changes');assert.equal(same.doc.querySelector('[data-mmafc-reset]').hidden,false);
    assert.deepEqual(moved.picks(),[3,1,7]);assert.equal(moved.win.localStorage.getItem('mmafc-top10:reader-key'),null);
    assert.deepEqual(broken.picks(),[3,1,7],'a draft that is not a list of fighters is discarded');
  }finally{same.dom.window.close();moved.dom.window.close();broken.dom.window.close();}
});

test('an empty list can start from the official top 10, and a pick that no longer scores says why',async()=>{
  const empty=setup(routes(list([],0)));
  const signed=setup(routes(list([listed(2,{eligible:false,official_rank:null,note:'Signed with the UFC'}),1],3,{counts:false})));
  try{
    await flush();
    const prefill=empty.doc.querySelector('[data-mmafc-prefill]');assert.equal(prefill.hidden,false);prefill.click();
    assert.deepEqual(empty.picks(),[1,2,3,4,5,6,7,8,9,10]);assert.equal(prefill.hidden,true);assert.equal(empty.status(),'Unsaved changes');
    const row=signed.doc.querySelector('[data-id="2"]');assert.equal(row.querySelector('.mmafc-row__note').textContent,'Signed with the UFC');assert.equal(row.querySelector('.mmafc-row__points'),null);assert.ok(row.classList.contains('mmafc-row--muted'));
    assert.equal(signed.doc.querySelector('[data-mmafc-total]').textContent,'2 of 10 picked');assert.equal(signed.doc.querySelector('[data-mmafc-editor-note]').hidden,false);
  }finally{empty.dom.window.close();signed.dom.window.close();}
});

test('a new account is told when its picks start to count, and an older one sees no note',async()=>{
  const fresh=setup(routes(list([1,2],1,{counts_from:'2030-01-02T10:00:00+00:00'})));
  const settled=setup(routes(list([1,2],1,{counts_from:null})));
  try{
    await flush();
    const note=fresh.doc.querySelector('[data-mmafc-editor-note]');
    assert.equal(note.hidden,false);assert.match(note.textContent,/^Your picks join the fan ranking on .*2030.*You can build and save your Top 10 now\.$/);
    assert.equal(fresh.doc.querySelector('[data-mmafc-total]').textContent,'Adds 43 points to the fan ranking');
    assert.equal(settled.doc.querySelector('[data-mmafc-editor-note]').hidden,true);
  }finally{fresh.dom.window.close();settled.dom.window.close();}
});

test('the field searches after a pause and loads further pages on request',async()=>{
  const handler=(url)=>url.includes('q=won')?reply(200,{items:[ranked(40)],page:1,total_pages:1}):(url.includes('page=2')?field(2,15,2):field(1,15,2));
  const paged=setup((url,options,calls)=>url.includes('/rankings?')?handler(url):routes(list([1]))(url,options,calls));
  try{
    await flush();
    const more=paged.doc.querySelector('[data-mmafc-field-more]');assert.equal(more.hidden,false);assert.equal(paged.fieldIds().length,14);
    more.click();await flush();assert.equal(paged.fieldIds().length,29);assert.equal(more.hidden,true);
    const search=paged.doc.querySelector('[data-mmafc-field-search]');search.value=' won ';search.dispatchEvent(new paged.win.Event('input',{bubbles:true}));
    await wait(380);await flush();
    assert.deepEqual(paged.fieldIds(),[40]);assert.match(paged.calls[paged.calls.length-1].url,/page=1&q=won$/);
  }finally{paged.dom.window.close();}
});
