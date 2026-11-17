(function () {
  'use strict';
  var cfg = window.mmafCommunity || {};
  var session = null;
  var PAUSED = 'The site is paused for a moment. Your changes are still here. Try again in a minute.';

  // The nonce is fetched at runtime with a GET, so no per-reader value is printed into HTML that a page cache may keep.
  function sessionInfo(fresh) {
    if (session && !fresh) return Promise.resolve(session);
    return fetch(cfg.ajaxUrl + '?action=mmafc_session', { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (r) { session = (r && r.data) || { loggedIn: false }; return session; })
      .catch(function () { return { loggedIn: false }; });
  }

  // Backups and releases answer writes with a plain-text 503, so a response is never assumed to be JSON.
  function api(path, options, retried) {
    options = options || {};
    return sessionInfo(retried).then(function (s) {
      var headers = { 'X-WP-Nonce': s.nonce || '' };
      if (options.json) headers['Content-Type'] = 'application/json';
      return fetch(cfg.restUrl + path, { method: options.method || 'GET', credentials: 'same-origin', headers: headers, body: options.json ? JSON.stringify(options.json) : options.body })
        .then(function (r) {
          return r.text().then(function (text) {
            var data = null;
            try { data = JSON.parse(text); } catch (e) { data = null; }
            if (r.ok && data) return data;
            if (r.status === 403 && data && data.code === 'rest_cookie_invalid_nonce' && !retried) return api(path, options, true);
            var error = new Error(data && data.message ? data.message : (r.status === 503 ? PAUSED : 'Something went wrong. Try again.'));
            error.fields = (data && data.data && data.data.fields) || {};
            error.status = r.status;
            throw error;
          });
        });
    });
  }

  function profileForm(form) {
    var status = form.querySelector('[data-mmafc-save-status]');
    var saveButton = form.querySelector('[data-mmafc-save]');
    var handle = form.querySelector('[data-mmafc-handle]');
    var handleStatus = form.querySelector('[data-mmafc-handle-status]');
    var handlePreview = form.querySelector('[data-mmafc-handle-preview]');
    var bio = form.querySelector('[data-mmafc-bio]');
    var bioCount = form.querySelector('[data-mmafc-bio-count]');
    var avatar = form.querySelector('[data-mmafc-avatar]');
    var avatarInput = form.querySelector('[data-mmafc-avatar-input]');
    var avatarRemove = form.querySelector('[data-mmafc-avatar-remove]');
    var publicLink = form.querySelector('[data-mmafc-public-link]');
    var hasProfile = form.getAttribute('data-has-profile') === '1';
    var dirty = false;
    var handleTimer = null;
    var handleCheck = 0;

    function setStatus(text, kind) { status.textContent = text; status.className = 'mmafc-savebar__status' + (kind ? ' is-' + kind : ''); }
    function setDirty(on) { dirty = on; if (on) setStatus('Unsaved changes', 'dirty'); }
    function errorNode(field) { return form.querySelector('[data-mmafc-error="' + field + '"]'); }
    function fieldInput(field) { return field.indexOf('socials.') === 0 ? form.querySelector('[data-mmafc-social="' + field.slice(8) + '"]') : form.querySelector('[name="' + field + '"]'); }
    function showError(field, message) {
      var node = errorNode(field), input = fieldInput(field);
      if (node) { node.textContent = message || ''; node.hidden = !message; }
      if (input) { if (message) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid'); }
    }
    function clearErrors() { Array.prototype.forEach.call(form.querySelectorAll('[data-mmafc-error]'), function (n) { showError(n.getAttribute('data-mmafc-error'), ''); }); }
    function showErrors(fields) {
      var first = null;
      Object.keys(fields || {}).forEach(function (field) { showError(field, fields[field]); first = first || fieldInput(field); });
      if (first && first.focus) first.focus();
    }
    function normalizedHandle() { return handle.value.trim().toLowerCase().replace(/^@+/, ''); }
    function updateCount() {
      if (!bio || !bioCount) return;
      var max = Number(bio.getAttribute('maxlength')) || 280, used = Array.from(bio.value).length;
      bioCount.textContent = used + ' / ' + max;
      bioCount.classList.toggle('is-near', used > max - 20);
    }
    function payload() {
      var socials = {};
      Array.prototype.forEach.call(form.querySelectorAll('[data-mmafc-social]'), function (input) { socials[input.getAttribute('data-mmafc-social')] = input.value; });
      return { handle: normalizedHandle(), display_name: form.querySelector('[name="display_name"]').value, bio: bio ? bio.value : '', socials: socials };
    }
    function applyProfile(data) {
      hasProfile = !!data.has_profile;
      form.setAttribute('data-has-profile', hasProfile ? '1' : '0');
      if (data.handle) { handle.value = data.handle; handle.setAttribute('data-saved', data.handle); if (handlePreview) handlePreview.textContent = data.handle; }
      form.querySelector('[name="display_name"]').value = data.display_name || '';
      if (bio) { bio.value = data.bio || ''; updateCount(); }
      Array.prototype.forEach.call(form.querySelectorAll('[data-mmafc-social]'), function (input) { input.value = (data.socials && data.socials[input.getAttribute('data-mmafc-social')]) || ''; });
      if (avatar && data.avatar_url) avatar.src = data.avatar_url;
      if (avatarRemove) avatarRemove.hidden = !data.has_avatar;
      if (publicLink) { publicLink.hidden = !data.url; if (data.url) publicLink.href = data.url; }
      if (session) session.handle = data.handle || null;
    }
    function save() {
      clearErrors();
      saveButton.setAttribute('aria-busy', 'true');
      setStatus('Saving…', '');
      return api('profile', { method: 'POST', json: payload() }).then(function (data) {
        applyProfile(data); dirty = false; handleStatus.textContent = ''; handleStatus.className = 'mmafc-status';
        setStatus('Profile saved', 'saved');
        return data;
      }).catch(function (error) {
        showErrors(error.fields);
        setStatus(Object.keys(error.fields || {}).length ? 'Check the highlighted fields.' : error.message, 'error');
        throw error;
      }).finally(function () { saveButton.removeAttribute('aria-busy'); });
    }
    function checkHandle() {
      var value = normalizedHandle(), run = ++handleCheck;
      if (handlePreview) handlePreview.textContent = value;
      showError('handle', '');
      if (!value || value === handle.getAttribute('data-saved')) { handleStatus.textContent = ''; handleStatus.className = 'mmafc-status'; return; }
      handleStatus.textContent = 'Checking…'; handleStatus.className = 'mmafc-status';
      api('profile/handle?value=' + encodeURIComponent(value)).then(function (data) {
        if (run !== handleCheck) return;
        handleStatus.textContent = data.available ? '@' + value + ' is available' : data.message;
        handleStatus.className = 'mmafc-status' + (data.available ? ' is-ok' : '');
        if (!data.available) handle.setAttribute('aria-invalid', 'true');
      }).catch(function () { if (run === handleCheck) handleStatus.textContent = ''; });
    }
    function setAvatarBusy(on) { if (avatar) avatar.classList.toggle('is-busy', on); if (avatarInput) avatarInput.disabled = on; if (avatarRemove) avatarRemove.disabled = on; }
    function uploadAvatar(file) {
      showError('avatar', '');
      if (['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type) < 0) { showError('avatar', 'Use a JPG, PNG or WebP image.'); return; }
      if (cfg.maxAvatarBytes && file.size > cfg.maxAvatarBytes) { showError('avatar', 'That image is too large. Choose a smaller one.'); return; }
      var previous = avatar ? avatar.src : '';
      var preview = window.URL && URL.createObjectURL ? URL.createObjectURL(file) : '';
      if (avatar && preview) avatar.src = preview;
      setAvatarBusy(true);
      // A photo belongs to a profile, so the first upload saves the form (and the handle) before it is sent.
      (hasProfile ? Promise.resolve() : save()).then(function () {
        var body = new FormData(); body.append('avatar', file);
        return api('profile/avatar', { method: 'POST', body: body });
      }).then(function (data) {
        if (avatar && data.avatar_url) avatar.src = data.avatar_url;
        if (avatarRemove) avatarRemove.hidden = !data.has_avatar;
        if (!dirty) setStatus('Photo updated', 'saved');
      }).catch(function (error) {
        if (avatar && previous) avatar.src = previous;
        if (error.fields && error.fields.avatar) showError('avatar', error.fields.avatar);
        else if (!error.fields || !Object.keys(error.fields).length) showError('avatar', error.message);
      }).finally(function () { setAvatarBusy(false); avatarInput.value = ''; if (preview) URL.revokeObjectURL(preview); });
    }

    form.addEventListener('input', function (e) {
      if (e.target === avatarInput) return;
      setDirty(true);
      if (e.target === bio) updateCount();
      if (e.target === handle) { clearTimeout(handleTimer); handleTimer = setTimeout(checkHandle, 350); }
      var name = e.target.getAttribute('data-mmafc-social') ? 'socials.' + e.target.getAttribute('data-mmafc-social') : e.target.name;
      if (name && name !== 'handle') showError(name, '');
    });
    form.addEventListener('submit', function (e) { e.preventDefault(); save().catch(function () {}); });
    if (avatarInput) avatarInput.addEventListener('change', function () { if (avatarInput.files && avatarInput.files[0]) uploadAvatar(avatarInput.files[0]); });
    if (avatarRemove) avatarRemove.addEventListener('click', function () {
      showError('avatar', ''); setAvatarBusy(true);
      api('profile/avatar', { method: 'DELETE' }).then(function (data) { if (avatar && data.avatar_url) avatar.src = data.avatar_url; avatarRemove.hidden = true; if (!dirty) setStatus('Photo removed', 'saved'); })
        .catch(function (error) { showError('avatar', error.message); })
        .finally(function () { setAvatarBusy(false); });
    });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    updateCount();
  }

  // The public profile is the same HTML for everyone; only its owner is shown the way back to the edit form.
  function publicProfile(node) {
    var owner = node.querySelector('[data-mmafc-owner]');
    if (!owner) return;
    sessionInfo().then(function (s) { if (s.loggedIn && s.handle && s.handle === node.getAttribute('data-mmafc-public-profile')) owner.hidden = false; });
  }

  // Shared with the Top 10 editor, which loads after this file.
  window.mmafCommunityApi = { session: sessionInfo, api: api };

  function start() {
    var form = document.querySelector('[data-mmafc-profile]');
    if (form) profileForm(form);
    var profile = document.querySelector('[data-mmafc-public-profile]');
    if (profile) publicProfile(profile);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
