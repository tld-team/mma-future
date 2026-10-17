(root, selectors) => {
  const text = el => (el?.textContent || '').replace(/\s+/g, ' ').trim();
  const containers = new Map();
  for (const link of root.querySelectorAll(selectors.bout)) {
    const href = link.getAttribute('href') || '';
    const id = href.match(/\/bouts\/(\d+)/)?.[1];
    if (!id) continue;
    let el = link.closest('li,tr,article,.bout,.fight,.fightCardBout,.fight-card-bout');
    if (!el || new Set(Array.from(el.querySelectorAll(selectors.bout)).map(a => a.href.match(/\/bouts\/(\d+)/)?.[1]).filter(Boolean)).size > 1) {
      el = link.closest('[data-bout-wrapper], [id^="boutFullsize"], [id^="boutCompact"], [id^="boutVeryCompact"]') || link.parentElement;
      for (let n = 0; n < 5 && el?.parentElement && root.contains(el.parentElement) && el.querySelectorAll(selectors.fighter).length < 2; n++) el = el.parentElement;
    }
    if (!el || !root.contains(el)) continue;
    const score = (el.querySelector('[id^="boutExpandedDetails"]') ? 100 : 0) + (el.querySelector('[id$="_leftBio"]') ? 50 : 0) + text(el).length / 10000;
    if (!containers.has(id) || containers.get(id).score < score) containers.set(id, {el, href, score});
  }
  // Older cards may have fighter rows without a matchup link. Keep their uncertainty explicit.
  if (!containers.size) {
    for (const el of root.querySelectorAll('tr,li,article')) {
      if (new Set(Array.from(el.querySelectorAll(selectors.fighter)).map(a => a.getAttribute('href'))).size === 2) containers.set('row-' + containers.size, {el, href:''});
    }
  }
  return Array.from(containers, ([id, {el, href}]) => {
    const full = el.matches('[id^="boutFullsize"]') ? el : el.querySelector('[id^="boutFullsize"]');
    const details = el.querySelector('[id^="boutExpandedDetails"]');
    const explicit = text(details?.firstElementChild);
    const visible = (full || el).innerText || text(full || el);
    const classification = explicit || (visible.match(/\b(?:Professional|Pro|Amateur|Exhibition)\s+(?:MMA|Mixed Martial Arts|Kickboxing|Boxing|Muay Thai|Grappling)\b/i) || [''])[0];
    const seen = new Set();
    const fighter_links = [];
    for (const a of (full || el).querySelectorAll(selectors.fighter)) {
      const url = a.getAttribute('href') || '';
      const fighterId = url.match(/\/fighters\/(\d+)/)?.[1] || url;
      if (!url || seen.has(fighterId) || fighter_links.length === 2) continue;
      seen.add(fighterId);
      fighter_links.push({name:text(a), href:url, class_name:a.className || '', parent_class_name:a.parentElement?.className || ''});
    }
    const badges = ['left', 'right'].map(side => {
      const bio = el.querySelector('[id$="_' + side + 'Bio"]');
      return Array.from(bio?.querySelectorAll('span') || []).map(text).find(s => /^(W|L|D|N|NC)$/.test(s)) || null;
    });
    const resultSpan = Array.from((full || el).querySelectorAll('span') || []).find(s => /^(KO\/TKO|TKO|KO|Submission|Decision|DQ|Disqualification|No Contest|Draw|Cancelled|Canceled)\b/i.test(text(s)));
    const billing = Array.from((full || el).querySelectorAll(selectors.bout)).find(a => /^(main event|co-main(?: event)?|main card|prelim|postlim)$/i.test(text(a)))?.closest('div');
    const explicitRecords = {};
    for (const tr of details?.querySelectorAll('tr') || []) {
      const cells = Array.from(tr.querySelectorAll('td'));
      const label = cells.find(c => /^(?:pro|professional|amateur) record at fight$/i.test(text(c)));
      if (label && cells.length >= 3) {explicitRecords.a=text(cells[0]);explicitRecords.b=text(cells[cells.length-1]);}
    }
    return {
      source_row_version:'tapology_event_card_v2', bout_href:href,
      raw_text:visible.trim(), classification_text:classification,
      classification_evidence:explicit ? 'event_detail_label' : (classification ? 'event_row_label' : null),
      fighter_links, result_badges:badges, method_raw:resultSpan ? text(resultSpan) : null,
      result_clock:resultSpan ? text(resultSpan.nextElementSibling) : null,
      billing_text:text(billing), prefight_records:explicitRecords,
      records_are_postfight:/\b(?:up to|down to|climbed to|fell to)\b/i.test(visible),
      details_verified:!!explicit, row_identity:id
    };
  });
}
