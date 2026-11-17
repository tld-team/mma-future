(function () {
  'use strict';
  // Fighter pages are page-cached, so the fan rank is fetched here instead of being printed into the page.
  var cfg = window.mmafCommunityFan || {};
  function fill() {
    var chips = Array.prototype.slice.call(document.querySelectorAll('[data-mmafc-fan-chip]'));
    if (!chips.length || !cfg.scoresUrl) return;
    var ids = chips.map(function (chip) { return chip.getAttribute('data-mmafc-fan-chip'); }).filter(function (id, index, all) { return /^\d+$/.test(id) && all.indexOf(id) === index; });
    if (!ids.length) return;
    fetch(cfg.scoresUrl + (cfg.scoresUrl.indexOf('?') < 0 ? '?' : '&') + 'fighter_ids=' + ids.join(','))
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.scores) return;
        chips.forEach(function (chip) {
          var score = data.scores[chip.getAttribute('data-mmafc-fan-chip')];
          if (!score || !score.rank) return;
          chip.textContent = 'Fan rank #' + score.rank + ' · in ' + score.picks + (score.picks === 1 ? ' Top 10' : ' Top 10s');
          chip.className = chip.getAttribute('data-mmafc-class') || '';
          chip.hidden = false;
        });
      })
      .catch(function () { /* the tag is an extra; the page is complete without it */ });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fill); else fill();
})();
