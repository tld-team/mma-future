(function () {
  'use strict';
  // The discussion under an article. The article HTML holds only an empty section, so a page cache never keeps one
  // reader's view; everything here comes from the community REST route.
  var lib = window.mmafCommunityApi;
  var root = document.querySelector('[data-mmafc-comments]');
  if (!root || !lib) return;

  var postId = Number(root.getAttribute('data-mmafc-comments'));
  var threadEl = root.querySelector('[data-mmafc-thread]'), composeEl = root.querySelector('[data-mmafc-comments-compose]');
  var statusEl = root.querySelector('[data-mmafc-comments-status]'), sortButtons = Array.prototype.slice.call(root.querySelectorAll('[data-mmafc-sort]'));
  var state = { sort: 'best', data: null };

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function setStatus(text) { statusEl.textContent = text || ''; }
  function message(error, field) { return (error.fields && error.fields[field]) || error.message; }

  function composer(parent, onDone) {
    var data = state.data, form = el('form', 'mmafc-compose');
    form.setAttribute('novalidate', '');
    var id = 'mmafc-compose-' + (parent || 'top');
    var label = el('label', 'mmafc-visually-hidden', parent ? 'Your reply' : 'Your comment'); label.setAttribute('for', id);
    var box = el('textarea', 'mmafc-input mmafc-compose__input'); box.id = id; box.rows = parent ? 3 : 4; box.maxLength = data.max_length; box.placeholder = parent ? 'Write a reply' : 'Add to the discussion';
    var foot = el('div', 'mmafc-compose__foot'), count = el('span', 'mmafc-counter'), error = el('p', 'mmafc-error'); error.hidden = true; error.setAttribute('role', 'alert');
    var send = el('button', 'mmafc-button', parent ? 'Reply' : 'Post comment'); send.type = 'submit';
    var update = function () { count.textContent = box.value.length + ' / ' + data.max_length; };
    box.addEventListener('input', function () { update(); error.hidden = true; });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!box.value.trim()) { error.textContent = 'Write something first.'; error.hidden = false; box.focus(); return; }
      send.disabled = true; send.setAttribute('aria-busy', 'true');
      lib.api('comments', { method: 'POST', json: { post_id: postId, content: box.value, parent: parent || 0 } })
        .then(function () { box.value = ''; update(); if (onDone) onDone(); return load(state.sort); })
        .then(function () { setStatus(parent ? 'Reply posted.' : 'Comment posted.'); })
        .catch(function (err) { error.textContent = message(err, 'content'); error.hidden = false; })
        .then(function () { send.disabled = false; send.removeAttribute('aria-busy'); });
    });
    update();
    foot.appendChild(count); foot.appendChild(send);
    form.appendChild(label); form.appendChild(box); form.appendChild(error); form.appendChild(foot);
    return form;
  }

  function drawCompose() {
    var data = state.data;
    composeEl.textContent = '';
    if (data.can_post) { composeEl.appendChild(composer(0)); return; }
    var note = el('p', 'mmafc-note');
    note.appendChild(document.createTextNode(data.reason + ' '));
    var signedOut = /^Sign in/.test(data.reason), noProfile = /^Set up your profile/.test(data.reason);
    if (signedOut || noProfile) { var link = el('a', 'mmafc-link', signedOut ? 'Sign in' : 'Set up profile'); link.href = signedOut ? data.login_url : data.profile_url; note.appendChild(link); }
    composeEl.appendChild(note);
  }

  function voteButton(c, value) {
    var b = el('button', 'mmafc-vote__btn mmafc-vote__btn--' + (value > 0 ? 'up' : 'down'));
    b.type = 'button'; b.setAttribute('data-mmafc-vote', String(value));
    b.setAttribute('aria-label', (value > 0 ? 'Vote up' : 'Vote down') + ' comment by ' + c.author.name);
    b.setAttribute('aria-pressed', c.mine === value ? 'true' : 'false');
    b.innerHTML = value > 0 ? '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>' : '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
    return b;
  }

  function drawComment(c, children) {
    var data = state.data, li = el('li', 'mmafc-comment mmafc-comment--depth-' + c.depth);
    li.setAttribute('data-id', String(c.id)); li.id = 'comment-' + c.id;
    var vote = el('div', 'mmafc-vote'), score = el('span', 'mmafc-vote__score', String(c.score));
    score.setAttribute('aria-label', 'Score ' + c.score);
    if (data.can_vote && !c.own) vote.appendChild(voteButton(c, 1));
    vote.appendChild(score);
    if (data.can_vote && data.can_down && !c.own) vote.appendChild(voteButton(c, -1));
    var body = el('div', 'mmafc-comment__body'), head = el('div', 'mmafc-comment__head');
    var avatar = el('img', 'mmafc-comment__avatar'); avatar.src = c.author.avatar; avatar.alt = ''; avatar.width = 32; avatar.height = 32; avatar.loading = 'lazy';
    head.appendChild(avatar);
    var name = c.author.url ? el('a', 'mmafc-comment__name', c.author.name) : el('span', 'mmafc-comment__name', c.author.name);
    if (c.author.url) name.href = c.author.url;
    head.appendChild(name);
    if (c.author.badge) { var badge = el('span', 'mmafc-comment__badge'); badge.innerHTML = c.author.badge; badge.title = 'Level ' + c.author.level; head.appendChild(badge); }
    if (c.author.staff) head.appendChild(el('span', 'mmafc-comment__staff', 'MMA Future'));
    var time = el('time', 'mmafc-comment__time', c.date_text); time.setAttribute('datetime', c.date); head.appendChild(time);
    var text = el('p', 'mmafc-comment__text', c.content);
    body.appendChild(head);
    var actions = el('div', 'mmafc-comment__actions');
    if (data.can_post) { var reply = el('button', 'mmafc-comment__action', 'Reply'); reply.type = 'button'; reply.setAttribute('data-mmafc-reply', ''); actions.appendChild(reply); }
    if (data.can_vote && !c.own) { var report = el('button', 'mmafc-comment__action', 'Report'); report.type = 'button'; report.setAttribute('data-mmafc-report', ''); actions.appendChild(report); }
    var replySlot = el('div', 'mmafc-comment__reply');
    if (c.collapsed) {
      var toggle = el('button', 'mmafc-comment__collapsed', 'Hidden for a low score. Show comment'); toggle.type = 'button';
      text.hidden = true; actions.hidden = true;
      toggle.addEventListener('click', function () { text.hidden = false; actions.hidden = false; toggle.remove(); });
      body.appendChild(toggle);
    }
    body.appendChild(text); body.appendChild(actions); body.appendChild(replySlot);
    li.appendChild(vote); li.appendChild(body);
    if (children.length) {
      var list = el('ol', 'mmafc-thread mmafc-thread--replies');
      children.forEach(function (child) { list.appendChild(child); });
      li.appendChild(list);
    }
    bind(li, c, score, actions, replySlot);
    return li;
  }

  function bind(li, c, score, actions, replySlot) {
    Array.prototype.slice.call(li.querySelectorAll(':scope > .mmafc-vote [data-mmafc-vote]')).forEach(function (button) {
      button.addEventListener('click', function () {
        var value = Number(button.getAttribute('data-mmafc-vote')), send = c.mine === value ? 0 : value;
        lib.api('comments/' + c.id + '/vote', { method: 'POST', json: { value: send } }).then(function (r) {
          c.mine = r.mine; c.score = r.score; score.textContent = String(r.score); score.setAttribute('aria-label', 'Score ' + r.score);
          Array.prototype.slice.call(li.querySelectorAll(':scope > .mmafc-vote [data-mmafc-vote]')).forEach(function (b) { b.setAttribute('aria-pressed', Number(b.getAttribute('data-mmafc-vote')) === r.mine ? 'true' : 'false'); });
          if (r.status !== 'approved') { li.remove(); setStatus('That comment is now waiting for a moderator.'); }
        }).catch(function (err) { setStatus(message(err, 'vote')); });
      });
    });
    var reply = actions.querySelector('[data-mmafc-reply]');
    if (reply) reply.addEventListener('click', function () {
      if (replySlot.firstChild) { replySlot.textContent = ''; return; }
      var form = composer(c.id, function () { replySlot.textContent = ''; });
      replySlot.appendChild(form); form.querySelector('textarea').focus();
    });
    var report = actions.querySelector('[data-mmafc-report]');
    if (report) report.addEventListener('click', function () {
      report.disabled = true;
      lib.api('comments/' + c.id + '/report', { method: 'POST', json: {} }).then(function (r) {
        report.textContent = 'Reported';
        if (r.held) { li.remove(); setStatus('Thanks. That comment is now waiting for a moderator.'); } else { setStatus('Thanks for the report. A moderator will take a look.'); }
      }).catch(function (err) { report.disabled = false; setStatus(message(err, 'report')); });
    });
  }

  function drawThread() {
    var data = state.data, byParent = {};
    data.comments.forEach(function (c) { if (c.parent) (byParent[c.parent] = byParent[c.parent] || []).push(c); });
    var build = function (c) {
      var kids = (byParent[c.id] || []).slice().sort(function (a, b) { return a.date < b.date ? -1 : a.date > b.date ? 1 : a.id - b.id; });
      return drawComment(c, kids.map(build));
    };
    var byId = {}; data.comments.forEach(function (c) { byId[c.id] = c; });
    threadEl.textContent = '';
    data.order.forEach(function (id) { if (byId[id]) threadEl.appendChild(build(byId[id])); });
    setStatus(data.count ? '' : 'No comments yet. Start the discussion.');
    var title = root.querySelector('#mmafc-comments-title'); if (title) title.textContent = data.count ? 'Discussion (' + data.count + ')' : 'Discussion';
  }

  function load(sort) {
    state.sort = sort;
    sortButtons.forEach(function (b) { var on = b.getAttribute('data-mmafc-sort') === sort; b.setAttribute('aria-pressed', on ? 'true' : 'false'); b.classList.toggle('is-current', on); });
    return lib.api('comments?post_id=' + postId + '&sort=' + sort).then(function (data) {
      var first = !state.data; state.data = data;
      if (first) drawCompose();
      drawThread();
    }).catch(function (err) { setStatus(err.message || 'The discussion couldn’t be loaded.'); });
  }

  sortButtons.forEach(function (b) { b.addEventListener('click', function () { if (b.getAttribute('data-mmafc-sort') !== state.sort) load(b.getAttribute('data-mmafc-sort')); }); });
  load('best');
})();
