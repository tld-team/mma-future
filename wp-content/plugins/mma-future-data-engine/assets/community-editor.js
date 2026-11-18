(function () {
  'use strict';
  var cfg = window.mmafCommunity || {};
  var lib = window.mmafCommunityApi;
  var root = document.querySelector('[data-mmafc-editor]');
  if (!root || !lib) return;

  var SIZE = Number(root.getAttribute('data-size')) || 10;
  var PER_PAGE = 50;
  var part = function (name) { return root.querySelector('[data-mmafc-' + name + ']'); };
  var picksEl = part('picks'), fieldEl = part('field'), fieldZone = part('field-zone'), totalEl = part('total'), noteEl = part('editor-note');
  var statusEl = part('save-status'), saveBtn = part('save'), resetBtn = part('reset'), prefillBtn = part('prefill');
  var moreBtn = part('field-more'), searchEl = part('field-search'), fieldStatus = part('field-status'), liveEl = part('live');
  var ICON = {
    up: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>',
    down: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>',
    remove: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    add: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
    drag: '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>'
  };
  var state = { picks: [], saved: [], revision: 0, points: [], counts: true, field: [], top: [], page: 0, pages: 1, query: '', dirty: false, storageKey: null, saving: false, loadingField: false };
  var fieldRequest = 0, searchTimer = null, focusAfter = null, drag = null;

  /* ---------- data ---------- */
  function pretty(value) { return String(value || '').replace(/_/g, ' ').replace(/\b[a-z]/g, function (c) { return c.toUpperCase(); }); }
  function fromList(item) {
    return { id: Number(item.fighter_id), name: item.display_name || 'Fighter no longer listed', url: item.permalink || '', image: item.image_url || '', division: pretty(item.weight_class), record: item.record || '', official: item.official_rank || null, eligible: !!item.eligible, note: item.note || '' };
  }
  function fromRanking(item) {
    var r = item.record || {}, record = r.wins == null ? '' : r.wins + '-' + r.losses + (r.draws ? '-' + r.draws : '');
    return { id: Number(item.fighter_id), name: item.display_name || '', url: item.permalink || '', image: (item.image && item.image.url) || '', division: pretty(item.weight_class), record: record, official: item.rank || null, eligible: true, note: '' };
  }
  function ids(list) { return list.map(function (f) { return f.id; }); }
  function sameOrder(a, b) { return a.length === b.length && a.every(function (f, i) { return f.id === b[i].id; }); }
  function pickIndex(id) { return ids(state.picks).indexOf(id); }
  function find(id) { var i = pickIndex(id); if (i >= 0) return state.picks[i]; return state.field.filter(function (f) { return f.id === id; })[0] || null; }
  function pointsAt(index) { return Number(state.points[index]) || 0; }

  /* ---------- drafts: an unsaved list survives a reload or a failed save ---------- */
  function draftKey() { return state.storageKey ? 'mmafc-top10:' + state.storageKey : null; }
  function storeDraft() {
    var key = draftKey(); if (!key) return;
    try { if (state.dirty) window.localStorage.setItem(key, JSON.stringify({ revision: state.revision, picks: state.picks })); else window.localStorage.removeItem(key); } catch (e) { /* storage is optional */ }
  }
  function readDraft() {
    var key = draftKey(); if (!key) return null;
    try {
      var draft = JSON.parse(window.localStorage.getItem(key) || 'null');
      var valid = draft && draft.revision === state.revision && Array.isArray(draft.picks) && draft.picks.length <= SIZE && draft.picks.every(function (f) { return f && Number.isInteger(f.id) && f.id > 0 && typeof f.name === 'string'; });
      if (!valid) { window.localStorage.removeItem(key); return null; }
      return draft.picks;
    } catch (e) { return null; }
  }

  /* ---------- rendering ---------- */
  function node(tag, className, text) { var n = document.createElement(tag); if (className) n.className = className; if (text != null) n.textContent = text; return n; }
  function button(action, label, icon) { var b = node('button', 'mmafc-iconbtn'); b.type = 'button'; b.setAttribute('data-act', action); b.setAttribute('aria-label', label); b.innerHTML = icon; return b; }
  function portrait(f) {
    if (!f.image) return node('span', 'mmafc-row__img mmafc-row__img--empty');
    var img = node('img', 'mmafc-row__img'); img.src = f.image; img.alt = ''; img.width = 40; img.height = 40; img.loading = 'lazy'; return img;
  }
  function body(f, meta) {
    var wrap = node('span', 'mmafc-row__body'), name;
    if (f.url) { name = node('a', 'mmafc-row__name', f.name); name.href = f.url; name.target = '_blank'; name.rel = 'noopener'; } else { name = node('span', 'mmafc-row__name', f.name); }
    wrap.appendChild(name); wrap.appendChild(node('span', 'mmafc-row__meta', meta.filter(Boolean).join(' · ')));
    return wrap;
  }
  function pickRow(f, index) {
    var row = node('li', 'mmafc-row mmafc-row--pick' + (f.eligible ? '' : ' mmafc-row--muted')); row.setAttribute('data-id', f.id);
    row.appendChild(node('span', 'mmafc-row__pos', String(index + 1)));
    row.appendChild(portrait(f));
    row.appendChild(body(f, [f.division, f.record, f.official ? 'Official #' + f.official : '']));
    row.appendChild(f.eligible ? node('span', 'mmafc-row__points', '+' + pointsAt(index)) : node('span', 'mmafc-row__note', f.note || 'Not ranked right now'));
    var actions = node('span', 'mmafc-row__actions');
    var up = button('up', 'Move ' + f.name + ' up', ICON.up), down = button('down', 'Move ' + f.name + ' down', ICON.down);
    up.disabled = index === 0; down.disabled = index === state.picks.length - 1;
    actions.appendChild(up); actions.appendChild(down); actions.appendChild(button('remove', 'Remove ' + f.name + ' from your Top 10', ICON.remove));
    row.appendChild(actions);
    var handle = button('drag', 'Reorder ' + f.name + ', position ' + (index + 1) + ' of ' + state.picks.length, ICON.drag); handle.className += ' mmafc-row__handle';
    row.appendChild(handle);
    return row;
  }
  function emptyRow(index) {
    var row = node('li', 'mmafc-row mmafc-row--empty');
    row.appendChild(node('span', 'mmafc-row__pos', String(index + 1)));
    row.appendChild(node('span', 'mmafc-row__body mmafc-row__open', 'Open slot'));
    row.appendChild(node('span', 'mmafc-row__points', '+' + pointsAt(index)));
    return row;
  }
  function fieldRow(f) {
    var row = node('li', 'mmafc-row mmafc-row--field'); row.setAttribute('data-id', f.id);
    row.appendChild(node('span', 'mmafc-row__pos', f.official ? '#' + f.official : '–'));
    row.appendChild(portrait(f));
    row.appendChild(body(f, [f.division, f.record]));
    var actions = node('span', 'mmafc-row__actions'); actions.appendChild(button('add', 'Add ' + f.name + ' to your Top 10', ICON.add)); row.appendChild(actions);
    var handle = button('drag', 'Drag ' + f.name + ' into your Top 10', ICON.drag); handle.className += ' mmafc-row__handle'; handle.tabIndex = -1; // The Add button is the keyboard path.
    row.appendChild(handle);
    return row;
  }
  function renderPicks() {
    picksEl.textContent = '';
    for (var i = 0; i < SIZE; i++) picksEl.appendChild(state.picks[i] ? pickRow(state.picks[i], i) : emptyRow(i));
    var total = state.picks.reduce(function (sum, f, i) { return sum + (f.eligible ? pointsAt(i) : 0); }, 0);
    totalEl.textContent = state.picks.length ? (state.counts ? 'Adds ' + total + ' points to the fan ranking' : state.picks.length + ' of ' + SIZE + ' picked') : '';
    prefillBtn.hidden = state.picks.length > 0 || state.top.length === 0;
  }
  function renderField() {
    var picked = ids(state.picks), rows = state.field.filter(function (f) { return picked.indexOf(f.id) < 0; });
    fieldEl.textContent = '';
    rows.forEach(function (f) { fieldEl.appendChild(fieldRow(f)); });
    moreBtn.hidden = state.page >= state.pages;
    if (!state.loadingField) fieldStatus.textContent = rows.length ? '' : (state.query ? 'No fighters match “' + state.query + '”.' : (state.field.length ? 'Every fighter shown is already in your Top 10.' : ''));
  }
  function setStatus(text, kind) { statusEl.textContent = text; statusEl.className = 'mmafc-savebar__status' + (kind ? ' is-' + kind : ''); }
  function announce(text) { liveEl.textContent = ''; window.setTimeout(function () { liveEl.textContent = text; }, 30); }
  function render() {
    renderPicks(); renderField();
    resetBtn.hidden = !state.dirty;
    if (state.dirty) setStatus('Unsaved changes', 'dirty');
    if (focusAfter) {
      var target = root.querySelector('[data-id="' + focusAfter.id + '"] [data-act="' + focusAfter.act + '"]');
      if (!target || target.disabled) target = root.querySelector('[data-id="' + focusAfter.id + '"] [data-act="drag"]') || root.querySelector('[data-id="' + focusAfter.id + '"] [data-act="add"]');
      if (target) target.focus();
      focusAfter = null;
    }
  }

  /* ---------- changes ---------- */
  function commit(picks, message) {
    state.picks = picks; state.dirty = !sameOrder(picks, state.saved);
    if (!state.dirty) setStatus('', '');
    render(); storeDraft();
    if (message) announce(message);
  }
  // Put a fighter at an index. A full list makes room by dropping its last fighter back into the field.
  function place(f, index, act) {
    var picks = state.picks.slice(), from = pickIndex(f.id);
    if (from >= 0) picks.splice(from, 1);
    index = Math.max(0, Math.min(index, picks.length, SIZE - 1));
    picks.splice(index, 0, f);
    var dropped = picks.length > SIZE ? picks.pop() : null;
    if (from === index && !dropped) { render(); return; }
    focusAfter = { id: f.id, act: act || 'drag' };
    commit(picks, f.name + (from >= 0 ? ' moved to position ' : ' added at position ') + (index + 1) + '.' + (dropped ? ' ' + dropped.name + ' dropped out of your Top 10.' : ''));
  }
  function removePick(f) {
    var index = pickIndex(f.id); if (index < 0) return;
    var picks = state.picks.slice(); picks.splice(index, 1);
    var next = picks[index] || picks[index - 1];
    focusAfter = next ? { id: next.id, act: 'remove' } : null;
    if (state.field.every(function (x) { return x.id !== f.id; }) && f.eligible && !state.query) state.field.push(f); // Keep it reachable until the field is reloaded.
    commit(picks, f.name + ' removed from your Top 10.');
  }

  /* ---------- server ---------- */
  function applyServer(data) {
    state.revision = Number(data.revision) || 0; state.points = data.points || []; state.counts = data.counts !== false;
    state.saved = (data.items || []).map(fromList); state.picks = state.saved.slice(); state.dirty = false;
    var from = state.counts && data.counts_from ? new Date(data.counts_from) : null;
    if (from && isNaN(from.getTime())) from = null;
    noteEl.hidden = state.counts && !from;
    if (!state.counts && data.reason === 'email') noteEl.textContent = 'Confirm your email address to have your Top 10 count toward the fan ranking. Your picks start counting as soon as you do, with no need to save again.';
    else if (!state.counts && data.reason === 'staff') noteEl.textContent = 'Staff accounts can keep a Top 10, but only readers’ lists count toward the fan ranking.';
    else if (!state.counts) noteEl.textContent = 'You can keep a Top 10 with this account, but only reader accounts with a confirmed email count toward the fan ranking.';
    else if (from) noteEl.textContent = 'Your picks join the fan ranking on ' + from.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) + '. New accounts wait a short while, so a batch of fresh accounts cannot move the ranking at once. You can build and save your Top 10 now.';
  }
  function load(discard) {
    setStatus('Loading your Top 10…', '');
    return lib.session().then(function (s) { state.storageKey = s.key || null; return lib.api('top-10'); }).then(function (data) {
      applyServer(data);
      var draft = discard ? null : readDraft();
      if (discard) storeDraft();
      if (draft && !sameOrder(draft, state.saved)) { state.picks = draft; state.dirty = true; render(); setStatus('Restored your unsaved changes', 'dirty'); }
      else { setStatus('', ''); render(); }
    }).catch(function (error) { setStatus(error.message, 'error'); render(); });
  }
  function save() {
    if (state.saving) return;
    state.saving = true; saveBtn.setAttribute('aria-busy', 'true'); setStatus('Saving…', '');
    lib.api('top-10', { method: 'POST', json: { fighter_ids: ids(state.picks), revision: state.revision } }).then(function (data) {
      applyServer(data); storeDraft(); render();
      setStatus(state.picks.length ? 'Top 10 saved' : 'Top 10 cleared', 'saved');
      if (state.picks.length) showShare();
    }).catch(function (error) {
      setStatus(error.fields && error.fields.fighters ? error.fields.fighters : error.message, 'error'); resetBtn.hidden = false;
    }).finally(function () { state.saving = false; saveBtn.removeAttribute('aria-busy'); });
  }
  // After a save: where the list now shows, and a link to pass around. Without a profile there is no page to share yet.
  function showShare() {
    var box = part('share');
    if (!box) return;
    var link = part('share-link'), copy = part('share-copy'), text = part('share-text'), status = part('share-status');
    lib.session().then(function (s) {
      var url = s && s.handle && cfg.profileBase ? cfg.profileBase + encodeURIComponent(s.handle) + '/' : '';
      if (url) {
        text.textContent = 'It’s on your public profile for other fans to see: ' + url;
        link.textContent = 'View your profile'; link.href = url;
        copy.hidden = !(window.navigator && window.navigator.clipboard);
        copy.onclick = function () { window.navigator.clipboard.writeText(url).then(function () { status.textContent = 'Link copied.'; }, function () { status.textContent = 'Copy the link from the line above.'; }); };
      } else {
        text.textContent = 'Set up your profile to show your Top 10 on a public page you can share.';
        link.textContent = 'Set up profile'; link.href = cfg.profileFormUrl || '/account/profile/';
        copy.hidden = true;
      }
      status.textContent = ''; box.hidden = false;
    });
  }
  function loadField(page) {
    var run = ++fieldRequest, url = cfg.rankingsUrl + (cfg.rankingsUrl.indexOf('?') < 0 ? '?' : '&') + 'board=overall&per_page=' + PER_PAGE + '&page=' + page + (state.query ? '&q=' + encodeURIComponent(state.query) : '');
    state.loadingField = true; fieldStatus.textContent = 'Loading fighters…';
    fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json().then(function (data) { if (!r.ok) throw new Error(data.message || 'The field couldn’t be loaded.'); return data; }); }).then(function (data) {
      if (run !== fieldRequest) return;
      var items = (data.items || []).map(fromRanking);
      state.field = page === 1 ? items : state.field.concat(items.filter(function (f) { return state.field.every(function (x) { return x.id !== f.id; }); }));
      state.page = Number(data.page) || page; state.pages = Number(data.total_pages) || 1;
      if (page === 1 && !state.query) state.top = items.slice(0, SIZE);
      state.loadingField = false; fieldStatus.textContent = ''; render();
    }).catch(function (error) { if (run === fieldRequest) { state.loadingField = false; fieldStatus.textContent = error.message + ' Try again.'; moreBtn.hidden = false; } });
  }

  /* ---------- drag and drop (pointer events; the buttons and arrow keys do the same without it) ---------- */
  function clearMarks() {
    Array.prototype.forEach.call(picksEl.querySelectorAll('.is-drop-before,.is-drop-into'), function (n) { n.classList.remove('is-drop-before', 'is-drop-into'); });
    picksEl.classList.remove('is-drop-end'); fieldZone.classList.remove('is-drop');
  }
  function trackDrag() {
    if (!drag) return;
    drag.ghost.style.top = (drag.y - drag.offset) + 'px';
    clearMarks();
    var box = picksEl.getBoundingClientRect();
    if (drag.y >= box.top - 24 && drag.y <= box.bottom + 8) {
      var rows = Array.prototype.filter.call(picksEl.children, function (r) { return r !== drag.row; }), index = 0;
      rows.forEach(function (r, i) { var b = r.getBoundingClientRect(); if (drag.y > b.top + b.height / 2) index = i + 1; });
      index = Math.min(index, drag.fromPicks ? state.picks.length - 1 : Math.min(state.picks.length, SIZE - 1));
      drag.target = { index: index };
      if (rows[index]) rows[index].classList.add(rows[index].classList.contains('mmafc-row--empty') ? 'is-drop-into' : 'is-drop-before'); else picksEl.classList.add('is-drop-end');
    } else if (drag.fromPicks && drag.y > box.bottom + 8) { drag.target = { remove: true }; fieldZone.classList.add('is-drop'); }
    else { drag.target = null; }
  }
  function scrollWhileDragging() {
    if (!drag) return;
    var edge = 72, step = drag.y < edge ? -14 : (drag.y > window.innerHeight - edge ? 14 : 0);
    if (step) { window.scrollBy(0, step); trackDrag(); }
    drag.frame = window.requestAnimationFrame(scrollWhileDragging);
  }
  function endDrag(apply) {
    if (!drag) return;
    var done = drag; drag = null;
    window.cancelAnimationFrame(done.frame);
    try { done.handle.releasePointerCapture(done.pointerId); } catch (e) { /* already released */ }
    done.ghost.remove(); done.row.classList.remove('is-dragging'); root.classList.remove('is-sorting'); clearMarks();
    if (!apply || !done.target) return;
    if (done.target.remove) removePick(done.f); else place(done.f, done.target.index, 'drag');
  }
  root.addEventListener('pointerdown', function (e) {
    var handle = e.target.closest('[data-act="drag"]');
    if (!handle || e.button > 0 || drag) return;
    var row = handle.closest('.mmafc-row'), f = row && find(Number(row.getAttribute('data-id')));
    if (!f) return;
    e.preventDefault();
    var box = row.getBoundingClientRect(), ghost = row.cloneNode(true);
    ghost.classList.add('mmafc-row--ghost'); ghost.removeAttribute('data-id'); ghost.setAttribute('aria-hidden', 'true');
    ghost.style.width = box.width + 'px'; ghost.style.left = box.left + 'px'; ghost.style.top = box.top + 'px';
    root.appendChild(ghost); row.classList.add('is-dragging'); root.classList.add('is-sorting');
    drag = { f: f, row: row, ghost: ghost, handle: handle, pointerId: e.pointerId, offset: e.clientY - box.top, y: e.clientY, fromPicks: pickIndex(f.id) >= 0, target: null, frame: 0 };
    try { handle.setPointerCapture(e.pointerId); } catch (err) { /* capture is a refinement */ }
    scrollWhileDragging();
  });
  root.addEventListener('pointermove', function (e) { if (drag && e.pointerId === drag.pointerId) { drag.y = e.clientY; trackDrag(); } });
  root.addEventListener('pointerup', function (e) { if (drag && e.pointerId === drag.pointerId) endDrag(true); });
  root.addEventListener('pointercancel', function (e) { if (drag && e.pointerId === drag.pointerId) endDrag(false); });

  /* ---------- buttons and keyboard ---------- */
  root.addEventListener('click', function (e) {
    var control = e.target.closest('[data-act]'); if (!control || control.disabled) return;
    var row = control.closest('.mmafc-row'), f = row && find(Number(row.getAttribute('data-id'))), act = control.getAttribute('data-act');
    if (!f) return;
    var index = pickIndex(f.id);
    if (act === 'up') place(f, index - 1, 'up');
    else if (act === 'down') place(f, index + 1, 'down');
    else if (act === 'remove') removePick(f);
    else if (act === 'add') place(f, SIZE - 1, 'drag');
  });
  root.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && drag) { endDrag(false); return; }
    var handle = e.target.closest && e.target.closest('[data-act="drag"]'); if (!handle) return;
    var row = handle.closest('.mmafc-row'), f = row && find(Number(row.getAttribute('data-id'))), index = f ? pickIndex(f.id) : -1;
    if (index < 0 || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
    e.preventDefault();
    place(f, index + (e.key === 'ArrowUp' ? -1 : 1), 'drag');
  });
  prefillBtn.addEventListener('click', function () { if (state.top.length) { focusAfter = { id: state.top[0].id, act: 'drag' }; commit(state.top.slice(0, SIZE), 'Filled your Top 10 with the official top ' + Math.min(SIZE, state.top.length) + '. Reorder it and save.'); } });
  saveBtn.addEventListener('click', save);
  resetBtn.addEventListener('click', function () { load(true); });
  moreBtn.addEventListener('click', function () { loadField(state.page >= state.pages ? state.page : state.page + 1); });
  searchEl.addEventListener('input', function () { window.clearTimeout(searchTimer); searchTimer = window.setTimeout(function () { state.query = searchEl.value.trim(); loadField(1); }, 300); });
  window.addEventListener('beforeunload', function (e) { if (state.dirty) { e.preventDefault(); e.returnValue = ''; } });

  load(false);
  loadField(1);
})();
