const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const php = process.env.MMAF_TEST_PHP || 'php';
const fixture = path.join(__dirname, 'fixture.php');
const script = readFileSync(path.join(__dirname, '../../wp-content/plugins/mma-future-data-engine/assets/event-calendar.js'), 'utf8');
const fixtureResponse = params => execFileSync(php, [fixture, String(params)], {encoding:'utf8'});
const dataFor = url => JSON.parse(fixtureResponse(new URL(url).searchParams));
const flush = async () => { await new Promise(setImmediate); await new Promise(setImmediate); };
const dateFor = day => `2027-05-${String(day).padStart(2,'0')}`;
function setup(day = 5, params = {}, beforeInit) {
  const query = new URLSearchParams({calendar_month:'2027-05',calendar_date:dateFor(day),calendar_tz:'Europe/Belgrade',...params});
  const dom = new JSDOM(fixtureResponse(query), {url:`http://127.0.0.1:8098/fixture.php?${query}`,runScripts:'outside-only'});
  const win = dom.window;
  const requests = [];
  win.fetch = async url => { requests.push(new URL(url)); return {ok:true,json:async()=>dataFor(url)}; };
  if (beforeInit) beforeInit(win);
  win.HTMLElement.prototype.scrollIntoView = function () { win.lastScrollTarget = this; };
  win.eval(script);
  return {dom,win,doc:win.document,requests,close:()=>win.close()};
}
const events = doc => [...doc.querySelectorAll('[data-calendar-event]')];
const action = (doc, name) => doc.querySelector(`[data-calendar-action="${name}"]`);
const selectDay = async (doc, day) => { doc.querySelector(`[data-calendar-date="${dateFor(day)}"]`).click(); await flush(); };

for (const [day,total] of [[1,0],[2,1],[3,3],[4,4],[5,19],[6,23]]) {
  test(`${total} events: SSR, progressive loading, totals and unique access to every event`, async () => {
    const t = setup(day);
    try {
      assert.equal(events(t.doc).length, Math.min(3,total));
      assert.equal(t.doc.querySelectorAll('details[open]').length,0);
      assert.equal(t.doc.querySelector('.mmaf-calendar__total').textContent, `${total} ${total===1?'event':'events'}`);
      let rounds = 0;
      while (action(t.doc,'more')) {
        action(t.doc,'more').click();
        // Repeated activation while loading must not duplicate the request.
        action(t.doc,'more')?.click();
        await flush();
        assert.ok(++rounds < 10);
      }
      assert.equal(events(t.doc).length,total);
      assert.equal(new Set(events(t.doc).map(el=>el.dataset.calendarEvent)).size,total);
      assert.equal(t.requests.length, Math.max(0,Math.ceil(total/3)-1));
      assert.ok(t.requests.every(url=>url.searchParams.get('per_page')==='3'));
      if (total) assert.equal(t.doc.querySelector('.mmaf-calendar__count').textContent,`Showing ${total} of ${total}`);
      if (total>3) {
        action(t.doc,'fewer').click();
        assert.equal(events(t.doc).length,3);
        assert.equal(t.doc.querySelectorAll('details[open]').length,0);
        assert.equal(t.doc.activeElement.tagName,'SUMMARY');
        assert.equal(t.win.lastScrollTarget,t.doc.querySelector('[data-calendar-day]'));
        action(t.doc,'more').click(); await flush();
        assert.equal(events(t.doc).length,6 > total ? total : 6);
      }
    } finally { t.close(); }
  });
}

test('single open detail, fighter/source links, unknown time and empty card', async()=>{
  const t=setup();
  try {
    const [first,second]=events(t.doc);
    first.open=true; first.dispatchEvent(new t.win.Event('toggle'));
    second.open=true; second.dispatchEvent(new t.win.Event('toggle'));
    assert.equal(first.open,false); assert.equal(second.open,true);
    assert.ok(first.querySelector('.mmaf-calendar__bouts a').href.endsWith('/fighters/example-a/'));
    assert.equal(first.querySelectorAll('.mmaf-calendar__bouts a[target="_blank"]').length,9);
    assert.match(second.textContent,/Time TBC/); assert.match(second.textContent,/Fight card to be confirmed/);
    assert.equal(second.querySelector('time').dateTime,dateFor(5));
    await selectDay(t.doc,4);
    assert.equal(t.doc.querySelectorAll('details[open]').length,0);
    assert.equal(events(t.doc).length,3);
  } finally {t.close();}
});

test('append failure retains entries and open details, restores retry focus and retries same page',async()=>{
  let fail=true;
  const t=setup(5,{},win=>{win.fetch=async url=>{if(fail)throw Error('offline');return{ok:true,json:async()=>dataFor(url)}}});
  try {
    events(t.doc)[0].open=true;
    action(t.doc,'more').click(); await flush();
    assert.equal(events(t.doc).length,3); assert.equal(events(t.doc)[0].open,true);
    assert.match(t.doc.querySelector('[data-calendar-feedback]').textContent,/could not be loaded/);
    assert.equal(t.doc.activeElement.dataset.calendarAction,'more');
    assert.equal(t.doc.querySelector('[data-calendar-day]').hasAttribute('aria-busy'),false);
    fail=false; action(t.doc,'more').click(); await flush();
    assert.equal(events(t.doc).length,6); assert.equal(t.doc.querySelector('[data-calendar-feedback]').textContent,'');
  }finally{t.close();}
});

test('a late day response cannot replace a newer selected day',async()=>{
  const pending=[];
  const t=setup(3,{},win=>{win.fetch=url=>new Promise(resolve=>pending.push({url,resolve}));});
  try{
    t.doc.querySelector(`[data-calendar-date="${dateFor(5)}"]`).click();
    t.doc.querySelector(`[data-calendar-date="${dateFor(6)}"]`).click();
    pending[1].resolve({ok:true,json:async()=>dataFor(pending[1].url)}); await flush();
    pending[0].resolve({ok:true,json:async()=>dataFor(pending[0].url)}); await flush();
    assert.match(t.doc.querySelector('.mmaf-calendar__day-heading h3').textContent,/May 6/);
    assert.equal(t.doc.querySelector('.mmaf-calendar__total').textContent,'23 events');
    assert.ok(events(t.doc).every(el=>Number(el.dataset.calendarEvent)>=600));
    assert.equal(new URL(t.win.location.href).searchParams.get('calendar_date'),dateFor(6));
  }finally{t.close();}
});

test('late month response and pending day cannot overwrite newer navigation',async()=>{
  const pending=[];
  const t=setup(3,{},win=>{win.fetch=url=>new Promise(resolve=>pending.push({url,resolve}));});
  try{
    t.doc.querySelector(`[data-calendar-date="${dateFor(5)}"]`).click();
    action(t.doc,'next').click(); action(t.doc,'next').click();
    pending[2].resolve({ok:true,json:async()=>dataFor(pending[2].url)}); await flush();
    pending[1].resolve({ok:true,json:async()=>dataFor(pending[1].url)});
    pending[0].resolve({ok:true,json:async()=>dataFor(pending[0].url)}); await flush();
    assert.equal(t.doc.querySelector('.mmaf-calendar__month').textContent,'July 2027');
    assert.equal(events(t.doc).length,0);
    assert.match(t.doc.querySelector('[data-calendar-day]').textContent,/Select an available day/);
    assert.equal(t.doc.querySelector('[data-calendar-grid]').inert,false);
  }finally{t.close();}
});

test('month/day errors are recoverable and pending coverage remains visible',async()=>{
  let fail=true;
  const t=setup(3,{},win=>{win.fetch=async url=>({ok:!fail,json:async()=>dataFor(url)})});
  try{
    await selectDay(t.doc,7); assert.ok(action(t.doc,'retry-day'));
    fail=false; action(t.doc,'retry-day').click(); await flush();
    assert.match(t.doc.querySelector('[data-calendar-day]').textContent,/Schedule may be updated/);
    fail=true; action(t.doc,'next').click(); await flush(); assert.ok(action(t.doc,'retry-month'));
    fail=false; action(t.doc,'retry-month').click(); await flush();
    assert.equal(t.doc.querySelector('.mmaf-calendar__month').textContent,'June 2027');
  }finally{t.close();}
});

test('six-week grid, keyboard navigation, URL preservation and pagination reset',async()=>{
  const t=setup(5,{calendar_page:'2',campaign:'test'});
  try{
    assert.equal(t.doc.querySelectorAll('.mmaf-calendar__grid tbody tr').length,6);
    assert.equal(events(t.doc)[0].dataset.calendarEvent,'503');
    assert.equal(t.doc.querySelector('.mmaf-calendar__count').textContent,'Showing 4–6 of 19');
    action(t.doc,'first').click(); await flush(); assert.equal(events(t.doc)[0].dataset.calendarEvent,'500');
    const date=t.doc.querySelector(`[data-calendar-date="${dateFor(5)}"]`);
    date.focus(); date.dispatchEvent(new t.win.KeyboardEvent('keydown',{key:'ArrowRight',bubbles:true}));
    assert.equal(t.doc.activeElement.dataset.calendarDate,dateFor(6));
    await selectDay(t.doc,6);
    const url=new URL(t.win.location.href); assert.equal(url.searchParams.get('campaign'),'test'); assert.equal(url.searchParams.has('calendar_page'),false);
  }finally{t.close();}
});

test('timezone selection resets to today and uses the selected zone in both requests',async()=>{
  const t=setup();
  try{
    const select=t.doc.querySelector('[data-calendar-timezone]'); select.value='Asia/Tokyo'; select.dispatchEvent(new t.win.Event('change')); await flush();
    assert.equal(t.requests.length,2); assert.ok(t.requests.every(url=>url.searchParams.get('timezone')==='Asia/Tokyo'));
    assert.equal(t.win.localStorage.getItem('mmafCalendarTimezone'),'Asia/Tokyo');
    assert.equal(new URL(t.win.location.href).searchParams.get('calendar_date'),new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Tokyo',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date()));
  }finally{t.close();}
});

test('blocked browser storage does not prevent enhancement',()=>{
  const t=setup(5,{},win=>Object.defineProperty(win,'localStorage',{get(){throw Error('blocked')}}));
  try{assert.ok(action(t.doc,'more'));assert.equal(events(t.doc).length,3);}finally{t.close();}
});

test('server fallback pages are linked and clamp out-of-range requests',()=>{
  const query=new URLSearchParams({calendar_month:'2027-05',calendar_date:dateFor(5),calendar_page:'2',calendar_tz:'Asia/Tokyo',nojs:'1'});
  const dom=new JSDOM(fixtureResponse(query),{url:'http://127.0.0.1:8098/fixture.php'});
  try{
    const links=[...dom.window.document.querySelectorAll('.mmaf-calendar__pagination a')];
    assert.equal(links.length,2);
    assert.deepEqual(links.map(a=>new URL(a.href).searchParams.get('calendar_page')),['1','3']);
    assert.ok(links.every(a=>new URL(a.href).searchParams.get('calendar_tz')==='Asia/Tokyo'));
    assert.equal(dom.window.document.querySelectorAll('details').length,3);
    assert.equal(dom.window.document.querySelectorAll('script').length,0);
    query.set('calendar_page','999');
    const last=new JSDOM(fixtureResponse(query));
    assert.equal(last.window.document.querySelectorAll('details').length,1);
    assert.match(last.window.document.querySelector('.mmaf-calendar__count').textContent,/19–19 of 19/);
    last.window.close();
  }finally{dom.window.close();}
});
