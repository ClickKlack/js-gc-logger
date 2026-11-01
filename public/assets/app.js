'use strict';

/* Geocaching-Log-Helfer — Frontend-Logik (Vanilla JS, kein Build-Schritt) */

const state = {
  caches: [],     // je Eintrag zusätzlich: notes, log, done
  tourInfo: '',
  tourDate: '',   // YYYY-MM-DD, Default-Datum der Caches
  companions: [],
  context: '',
  current: -1,    // Index des aktuell bearbeiteten Caches
  tourId: null,   // jede Tour ist gespeichert (create_tour beim GPX-Upload)
  fieldnotesAt: null,       // Zeitpunkt des letzten Feldnotizen-Imports
  pendingFieldnotes: null,  // vor dem GPX-Upload gewählte Feldnotizen-Datei
};

/* ---------- Autosave: lokal sofort, Server verzögert ---------- */

// Jede Änderung landet sofort als "Edit" im localStorage-Entwurf der Tour
// (überlebt Reload/Funkloch) und wird nach SAVE_DELAY feldweise an die API
// geschickt. Bestätigte Edits fliegen aus dem Entwurf.
//   Schlüssel: 'tour:<feld>' | 'cache:<id>:<feld>' | 'order'
const SAVE_DELAY = 1500;
const RETRY_DELAY = 10000;
const LAST_KEY = 'gc_last';
const draftKey = (tourId) => 'gc_draft_' + tourId;

const saver = { timer: null, flushing: false, failed: false };

function storageGet(key) {
  try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; }
}
function storageSet(key, val) {
  try { localStorage.setItem(key, JSON.stringify(val)); } catch { /* voll/privat: nur Server */ }
}
function storageDel(key) {
  try { localStorage.removeItem(key); } catch { /* egal */ }
}

function loadEdits(tourId) {
  return (storageGet(draftKey(tourId)) || {}).edits || {};
}
function saveEdits(tourId, edits) {
  if (Object.keys(edits).length) storageSet(draftKey(tourId), { edits });
  else storageDel(draftKey(tourId));
}

// Aktuellen Wert eines Edit-Schlüssels aus dem State lesen
function editValue(key) {
  if (key === 'order') return state.caches.map((c) => c.id);
  const [kind, a, b] = key.split(':');
  if (kind === 'tour') {
    return { tour_info: state.tourInfo, companions: state.companions, context: state.context, tour_date: state.tourDate }[a];
  }
  const c = state.caches.find((x) => String(x.id) === a);
  return c ? c[b] : undefined;
}

function markTour(field) { markEdit('tour:' + field); }
function markCache(c, field) { if (c && c.id) markEdit('cache:' + c.id + ':' + field); }
function markOrder() { markEdit('order'); }

function markEdit(key) {
  if (!state.tourId || !window.GC_STORE) return;
  const edits = loadEdits(state.tourId);
  edits[key] = { v: editValue(key), ts: Date.now() };
  saveEdits(state.tourId, edits);
  clearTimeout(saver.timer);
  saver.timer = setTimeout(flushEdits, SAVE_DELAY);
  renderSaveIndicator();
}

// Edits zu API-Aufrufen bündeln: je Cache ein update_cache, ein update_tour, ein reorder
function editRequests(edits) {
  const tourFields = {};
  const cacheFields = {};
  const reqs = [];
  for (const [key, e] of Object.entries(edits)) {
    if (key === 'order') {
      reqs.push({ keys: [key], action: 'reorder', data: { tour_id: String(state.tourId), ids: JSON.stringify(e.v) } });
      continue;
    }
    const [kind, a, b] = key.split(':');
    if (kind === 'tour') tourFields[a] = e.v;
    else (cacheFields[a] ||= {})[b] = e.v;
  }
  if (Object.keys(tourFields).length) {
    reqs.push({
      keys: Object.keys(tourFields).map((f) => 'tour:' + f),
      action: 'update_tour',
      data: { id: String(state.tourId), fields: JSON.stringify(tourFields) },
    });
  }
  for (const [id, fields] of Object.entries(cacheFields)) {
    reqs.push({
      keys: Object.keys(fields).map((f) => 'cache:' + id + ':' + f),
      action: 'update_cache',
      data: { id, fields: JSON.stringify(fields) },
    });
  }
  return reqs;
}

async function flushEdits() {
  clearTimeout(saver.timer);
  saver.timer = null;
  if (!state.tourId || saver.flushing) return;
  const tourId = state.tourId;
  const sent = loadEdits(tourId);
  if (!Object.keys(sent).length) { saver.failed = false; renderSaveIndicator(); return; }

  saver.flushing = true;
  renderSaveIndicator();
  let failed = false;
  for (const req of editRequests(sent)) {
    try {
      await api(req.action, req.data);
      dropEdits(tourId, req.keys, sent);
    } catch (ex) {
      if (ex.status === 404 || ex.status === 400) {
        dropEdits(tourId, req.keys, sent);   // Ziel gelöscht/ungültig: nicht endlos wiederholen
      } else {
        failed = true;                         // Netz weg, 401, 5xx: lokal behalten
        saver.error = ex.status === 401 ? 'abgemeldet' : 'offline';
      }
    }
  }
  saver.flushing = false;
  saver.failed = failed;
  const rest = loadEdits(tourId);
  if (Object.keys(rest).length && !saver.timer) {
    saver.timer = setTimeout(flushEdits, failed ? RETRY_DELAY : SAVE_DELAY);
  }
  renderSaveIndicator();
}

// Nur Edits entfernen, die seit dem Senden nicht erneut geändert wurden
function dropEdits(tourId, keys, sent) {
  const edits = loadEdits(tourId);
  keys.forEach((k) => { if (edits[k] && edits[k].ts === sent[k].ts) delete edits[k]; });
  saveEdits(tourId, edits);
}

// Beim Verlassen/Verstecken der Seite: per sendBeacon losschicken (kein Ergebnis;
// der Entwurf bleibt bis zur nächsten Bestätigung erhalten, Wiederholen ist harmlos).
function beaconEdits() {
  if (!state.tourId || !navigator.sendBeacon) return;
  for (const req of editRequests(loadEdits(state.tourId))) {
    const fd = new FormData();
    fd.append('action', req.action);
    for (const [k, v] of Object.entries(req.data)) fd.append(k, v);
    navigator.sendBeacon('api.php', fd);
  }
}

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden') beaconEdits();
  else flushEdits();
});
window.addEventListener('pagehide', beaconEdits);
window.addEventListener('online', flushEdits);

function renderSaveIndicator() {
  const el = $('#save-indicator');
  if (!el) return;
  if (!state.tourId || !window.GC_STORE) { hide(el); return; }
  const pending = Object.keys(loadEdits(state.tourId)).length > 0;
  el.classList.toggle('warn', pending && saver.failed);
  if (!pending) el.textContent = '✓ gespeichert';
  else if (saver.failed) el.textContent = saver.error === 'abgemeldet' ? '⚠ abgemeldet, lokal gesichert' : '⚠ offline, lokal gesichert';
  else el.textContent = '⏳ speichert…';
  show(el);
}

// Position merken, damit ein Reload an derselben Stelle landet
function rememberPosition() {
  if (!state.tourId) return;
  const c = state.caches[state.current];
  storageSet(LAST_KEY, { tourId: state.tourId, cacheId: c ? c.id : null });
}

// Server-Zeit 'YYYY-MM-DD HH:MM:SS' (UTC) → ms
function serverTime(s) {
  return s ? Date.parse(String(s).replace(' ', 'T') + 'Z') || 0 : 0;
}

// Lokale, noch nicht bestätigte Edits über den Server-Stand legen — nur wenn sie
// neuer sind als die letzte Server-Änderung der Zeile (sonst gewinnt der Server,
// z. B. weil auf einem anderen Gerät weitergearbeitet wurde).
function applyLocalEdits(tour) {
  const edits = loadEdits(tour.id);
  const keep = {};
  for (const [key, e] of Object.entries(edits)) {
    if (key === 'order') {
      if (e.ts > serverTime(tour.updated_at)) {
        const byId = new Map(state.caches.map((c) => [c.id, c]));
        if (e.v.length === byId.size && e.v.every((id) => byId.has(id))) {
          state.caches = e.v.map((id) => byId.get(id));
          keep[key] = e;
        }
      }
      continue;
    }
    const [kind, a, b] = key.split(':');
    if (kind === 'tour') {
      if (e.ts > serverTime(tour.updated_at)) {
        if (a === 'tour_info') state.tourInfo = e.v;
        if (a === 'companions') state.companions = e.v;
        if (a === 'context') state.context = e.v;
        if (a === 'tour_date') state.tourDate = e.v;
        keep[key] = e;
      }
    } else {
      const c = state.caches.find((x) => String(x.id) === a);
      if (c && e.ts > serverTime(c.updated_at)) { c[b] = e.v; keep[key] = e; }
    }
  }
  saveEdits(tour.id, keep);
  return Object.keys(keep).length;
}

/* ---------- Login ---------- */

$('#form-login')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const err = $('#login-error');
  err.textContent = '';
  try {
    await api('login', { password: $('#login-password').value });
    hide($('#view-login'));
    show($('#view-app'));
    location.reload(); // sauberer Auth-Zustand + Logout-Button
  } catch (ex) {
    err.textContent = ex.message;
  }
});

$('#btn-logout')?.addEventListener('click', async () => {
  await api('logout');
  location.reload();
});

/* ---------- Schritt 1: GPX-Upload ---------- */

$('#gpx-file')?.addEventListener('change', async (e) => {
  const file = e.target.files[0];
  const status = $('#gpx-status');
  if (!file) return;
  status.textContent = 'Lese GPX …';
  try {
    const fd = new FormData();
    fd.append('gpx', file);
    const { caches, match } = await api('upload_gpx', fd, true);

    if (!window.GC_STORE) {
      // Ohne Speicherung: nur im Browser arbeiten
      state.caches = caches.map((c) => ({ ...c, notes: '', log: '', done: false }));
      state.current = -1;
      status.textContent = caches.length + ' Cache(s) geladen.';
      $('#btn-context').disabled = false;
      renderCacheList();
      return;
    }

    // Bekannte Tour? (Wiedererkennung über GC-Codes)
    let mode = 'new';
    const label = match && match.tour_id
      ? 'Tour #' + match.tour_id + ' „' + (match.title || 'Tour') + '“ vom ' + formatDate(match.tour_date) : '';
    if (match && match.type === 'exact') {
      mode = confirm('Diese Caches sind bereits in ' + label + '.\n\n'
        + 'OK = diese Tour öffnen (GPX-Daten wie Land werden aufgefrischt, Notizen/Logs bleiben)\n'
        + 'Abbrechen = trotzdem neue Tour anlegen') ? 'refresh' : 'new';
    } else if (match && match.type === 'partial') {
      mode = confirm(match.known + ' von ' + match.total + ' Caches sind bereits in ' + label + '.\n\n'
        + 'OK = die ' + (match.total - match.known) + ' neuen Caches zu dieser Tour hinzufügen\n'
        + 'Abbrechen = neue Tour anlegen') ? 'add' : 'new';
    }

    status.textContent = mode === 'new' ? 'Lege Tour an …' : 'Öffne Tour …';
    const fd2 = new FormData();
    fd2.append('gpx', file);
    fd2.append('mode', mode);
    if (mode === 'new') {
      syncCompanionsFromUI(false);
      fd2.append('meta', JSON.stringify({
        tour_info: $('#tour-info').value, companions: state.companions, tour_date: $('#tour-date').value,
      }));
    } else {
      fd2.append('tour_id', String(match.tour_id));
    }
    const { tour, added } = await api('create_tour', fd2, true);

    if (mode === 'new') {
      loadTourIntoState(tour);
      state.current = -1;
      rememberPosition();
      renderCacheList();
      renderSaveIndicator();
      $('#btn-context').disabled = false;
      status.textContent = '✓ Tour #' + tour.id + ' angelegt (' + tour.caches.length + ' Caches) – wird automatisch gespeichert.'
        + (dupSummary() ? ' ' + dupSummary() : '');
      setDupBanner(dupSummary());
    } else {
      status.textContent = '';
      await openTour(tour.id, { tour });
      $('#save-status').textContent = mode === 'add'
        ? '✓ ' + added + ' Cache(s) zu Tour #' + tour.id + ' hinzugefügt.'
        : '✓ Tour #' + tour.id + ' geöffnet, GPX-Daten aufgefrischt.';
    }
    if (state.pendingFieldnotes) {
      const fnFile = state.pendingFieldnotes;
      state.pendingFieldnotes = null;
      await importFieldNotes(fnFile);
    }
  } catch (ex) {
    status.textContent = 'Fehler: ' + ex.message;
    $('#btn-context').disabled = true;
  } finally {
    e.target.value = '';   // gleiche Datei erneut wählbar
  }
});

/* ---------- c:geo-Feldnotizen ---------- */

function setFieldnotesStatus(text) {
  document.querySelectorAll('.fieldnotes-status').forEach((el) => { el.textContent = text; });
}

document.querySelectorAll('.fieldnotes-file').forEach((inp) => inp.addEventListener('change', async (e) => {
  const file = e.target.files[0];
  e.target.value = '';
  if (!file) return;
  if (!state.tourId) {
    // noch keine Tour: nach dem GPX-Upload übernehmen
    state.pendingFieldnotes = file;
    setFieldnotesStatus('„' + file.name + '“ vorgemerkt – wird nach dem GPX-Upload übernommen.');
    return;
  }
  await importFieldNotes(file);
}));

async function importFieldNotes(file) {
  setFieldnotesStatus('Lese Feldnotizen …');
  try {
    await flushEdits();
    const fd = new FormData();
    fd.append('tour_id', String(state.tourId));
    fd.append('fieldnotes', file);
    const res = await api('upload_fieldnotes', fd, true);
    let tour = res.tour;
    let sorted = res.sorted;
    if (res.needs_confirm && confirm('Die Reihenfolge wurde von Hand geändert.\n\nJetzt nach Fundzeit neu sortieren?')) {
      tour = (await api('sort_by_time', { tour_id: String(state.tourId) })).tour;
      sorted = true;
    }
    refreshTour(tour);

    const s = res.stats;
    const msg = ['✓ ' + s.matched + ' Feldnotiz(en) übernommen'];
    if (sorted) msg.push('nach Fundzeit sortiert');
    if (s.missing) msg.push(s.missing + ' Cache(s) ohne Feldnotiz (stehen am Ende)');
    if (s.kept_manual) msg.push(s.kept_manual + ' von Hand gesetzte(r) Wert(e) behalten');
    if (s.unknown.length) msg.push('nicht in dieser Tour: ' + s.unknown.join(', '));
    setFieldnotesStatus(msg.join(' · ') + '.');
  } catch (ex) {
    setFieldnotesStatus('Fehler: ' + ex.message);
  }
}

// ⏱ Nach Fundzeit sortieren (Datum, Uhrzeit, Fundnummer; ohne Uhrzeit ans Tagesende)
$('#btn-sort-time')?.addEventListener('click', async () => {
  if (!state.tourId) return;
  const btn = $('#btn-sort-time');
  btn.disabled = true;
  try {
    await flushEdits();
    refreshTour((await api('sort_by_time', { tour_id: String(state.tourId) })).tour);
    setFieldnotesStatus('✓ Nach Fundzeit sortiert.');
  } catch (ex) {
    setFieldnotesStatus('Fehler beim Sortieren: ' + ex.message);
  } finally {
    btn.disabled = false;
  }
});

// Server-Stand der offenen Tour übernehmen, ausgewählten Cache beibehalten
function refreshTour(tour) {
  const cur = state.caches[state.current];
  loadTourIntoState(tour);
  state.current = cur ? state.caches.findIndex((c) => c.id === cur.id) : -1;
  if (state.current >= 0) selectCache(state.current);
  else renderCacheList();
}

// Duplikat-Hinweis oben in der Caches-Karte
function setDupBanner(text) {
  const el = $('#dup-banner');
  if (!el) return;
  el.textContent = text;
  el.classList.toggle('hidden', !text);
  // Farbe des schwersten Falls: rot bei möglichem Doppelfund, sonst blau
  const warn = state.caches.some((c) => dupLevel(c) === 'warn');
  el.classList.toggle('warn', warn);
  el.classList.toggle('info', !warn);
}

/* ---------- Begleiter einsammeln ---------- */

// Begleiter aus dem Formular in den State übernehmen (und ggf. speichern)
function syncCompanionsFromUI(mark = true) {
  const list = collectCompanions();
  if (JSON.stringify(list) === JSON.stringify(state.companions)) return;
  state.companions = list;
  if (mark) markTour('companions');
}

function setCompanionsUI(list) {
  const known = new Set();
  document.querySelectorAll('.companion').forEach((cb) => {
    cb.checked = list.includes(cb.value);
    if (cb.checked) known.add(cb.value);
  });
  $('#companions-extra').value = list.filter((n) => !known.has(n)).join(', ');
}

document.querySelectorAll('.companion').forEach((cb) => cb.addEventListener('change', () => syncCompanionsFromUI()));
$('#companions-extra')?.addEventListener('input', () => syncCompanionsFromUI());
$('#tour-info')?.addEventListener('input', (e) => { state.tourInfo = e.target.value; markTour('tour_info'); });

// Tour-Datum (zwei Eingabefelder: Einrichtung + Kontext-Karte)
document.querySelectorAll('.tour-date').forEach((inp) => inp.addEventListener('change', (e) => {
  const v = e.target.value;
  if (!v) { e.target.value = state.tourDate; return; }
  setTourDateUI(v);
  if (!state.tourId || v === state.tourDate) { state.tourDate = v; return; }
  // wie der Server: Caches mit altem Tour-Datum ohne Uhrzeit/Handeingabe ziehen mit
  const old = state.tourDate;
  state.caches.forEach((c) => {
    if (c.visited_at === old && !c.visited_ts && !(c.manual_fields || []).includes('visited_at')) c.visited_at = v;
  });
  state.tourDate = v;
  markTour('tour_date');
  renderCacheList();
  if (state.current >= 0) renderVisit(state.caches[state.current]);
}));

function setTourDateUI(v) {
  document.querySelectorAll('.tour-date').forEach((inp) => { inp.value = v; });
}

function collectCompanions() {
  const list = [];
  document.querySelectorAll('.companion:checked').forEach((c) => list.push(c.value));
  const extra = $('#companions-extra').value.trim();
  if (extra) {
    // nur am Komma trennen – Accountnamen dürfen „und", „&" usw. enthalten
    extra.split(',').map((s) => s.trim()).filter(Boolean)
      .forEach((s) => { if (!list.includes(s)) list.push(s); });
  }
  return list;
}

/* ---------- Schritt 2: Kontext erzeugen ---------- */

$('#btn-context')?.addEventListener('click', async () => {
  const status = $('#context-status');
  state.tourInfo = $('#tour-info').value;
  syncCompanionsFromUI();
  status.textContent = 'Erzeuge Tour-Kontext …';
  $('#btn-context').disabled = true;
  try {
    const overview = state.caches.map((c) => ({ name: c.name, type: c.type }));
    const { context } = await api('generate_context', {
      tour_info: state.tourInfo,
      companions: JSON.stringify(state.companions),
      caches: JSON.stringify(overview),
    });
    state.context = context;
    markTour('context');
    $('#context-text').value = context;
    placeTourFields(true);
    show($('#step-context'));
    show($('#step-caches'));
    show($('#step-save'));
    status.textContent = 'Fertig.';
    // ersten offenen Cache öffnen
    selectCache(nextOpenIndex(-1));
  } catch (ex) {
    status.textContent = 'Fehler: ' + ex.message;
  } finally {
    $('#btn-context').disabled = false;
  }
});

$('#context-text')?.addEventListener('input', (e) => { state.context = e.target.value; markTour('context'); });

/* ---------- Sammel-Modus: offene Logs in wenigen Sammel-Anfragen ---------- */

// Caches außerhalb Deutschlands bekommen zweisprachige Logs -> zählen doppelt.
function isForeign(c) {
  const country = (c.country || '').trim().toLowerCase();
  return country !== '' && country !== 'germany' && country !== 'deutschland';
}

// Offene Caches in Gruppen aufteilen, deren Gewicht batch_size nicht übersteigt.
function chunkCaches(list, size) {
  const chunks = [];
  let cur = [];
  let weight = 0;
  list.forEach((c) => {
    const w = isForeign(c) ? 2 : 1;
    if (cur.length && weight + w > size) { chunks.push(cur); cur = []; weight = 0; }
    cur.push(c);
    weight += w;
  });
  if (cur.length) chunks.push(cur);
  return chunks;
}

$('#btn-generate-all')?.addEventListener('click', async () => {
  const status = $('#generate-all-status');
  const open = state.caches.filter((c) => !(c.log || '').trim());
  if (!open.length) {
    status.textContent = 'Alle Caches haben bereits ein Log.';
    return;
  }
  const chunks = chunkCaches(open, window.GC_BATCH_SIZE || 5);
  $('#btn-generate-all').disabled = true;
  let applied = 0;
  const missing = [];
  const errors = [];
  try {
    for (let k = 0; k < chunks.length; k++) {
      const chunk = chunks[k];
      status.textContent = 'Gruppe ' + (k + 1) + '/' + chunks.length + ': generiere '
        + chunk.length + ' Log(s) …' + (applied ? ' (' + applied + ' fertig)' : '');
      try {
        const { logs } = await api('generate_all', {
          context: state.context,
          caches: JSON.stringify(chunk),
          companions: JSON.stringify(state.companions),
        });
        chunk.forEach((c) => {
          // nur übernehmen, falls zwischenzeitlich nicht manuell befüllt
          if (logs[c.code] != null && !(c.log || '').trim()) { c.log = logs[c.code]; markCache(c, 'log'); applied++; }
          else if (!(c.log || '').trim()) missing.push(c.code);
        });
      } catch (ex) {
        // Fehler in einer Gruppe: Rest trotzdem versuchen, Caches bleiben leer
        errors.push(ex.message);
        chunk.forEach((c) => missing.push(c.code));
      }
      if (state.current >= 0) $('#log-text').value = state.caches[state.current].log || '';
      renderCacheList();
    }
  } finally {
    $('#btn-generate-all').disabled = false;
  }
  status.textContent = '✓ ' + applied + ' Log(s) erzeugt.'
    + (missing.length ? ' Leer geblieben: ' + missing.join(', ') + ' – nochmal klicken oder einzeln generieren.' : '')
    + (errors.length ? ' Fehler: ' + [...new Set(errors)].join(' | ') : '');
});

/* ---------- Schritt 3: Cache-Liste ---------- */

function renderCacheList() {
  const ul = $('#cache-list');
  ul.innerHTML = '';
  state.caches.forEach((c, i) => {
    const li = document.createElement('li');
    li.className = 'cache-item' + (i === state.current ? ' active' : '') + (c.done ? ' done' : '')
      + (dupLevel(c) ? ' dup-' + dupLevel(c) : '');
    const last = state.caches.length - 1;
    li.innerHTML =
      '<button type="button" class="check" aria-label="' + (c.done ? 'als offen markieren' : 'als erledigt markieren')
        + '" title="erledigt umschalten">' + (c.done ? '✓' : '○') + '</button>' +
      '<span class="cmain"><span class="cname">' + typeIcon(c.type) + escapeHtml(c.name) + '</span>' +
        '<span class="csub">' + cacheSubline(c) + '</span></span>' +
      '<span class="moves">' +
        '<button type="button" class="move" data-dir="up" aria-label="nach oben"' + (i === 0 ? ' disabled' : '') + '>▲</button>' +
        '<button type="button" class="move" data-dir="down" aria-label="nach unten"' + (i === last ? ' disabled' : '') + '>▼</button>' +
      '</span>';
    li.addEventListener('click', (e) => {
      if (e.target.closest('.check')) {
        e.stopPropagation();
        c.done = !c.done;
        markCache(c, 'done');
        renderCacheList();
        return;
      }
      const btn = e.target.closest('.move');
      if (btn) {
        e.stopPropagation();
        if (!btn.disabled) moveCache(i, btn.dataset.dir === 'up' ? -1 : 1);
        return;
      }
      selectCache(i);
    });
    ul.appendChild(li);
  });
  updateProgress();
}

// Unterzeile: **Uhrzeit** Datum · Fundnummer · Land · Status (Teile brechen nie in sich um)
function cacheSubline(c) {
  const parts = [];
  const date = c.visited_at || state.tourDate;
  if (c.visited_ts || date) {
    parts.push((c.visited_ts ? '<b>' + escapeHtml(berlinParts(c.visited_ts).time) + '</b> ' : '')
      + escapeHtml(date ? formatDate(date).slice(0, 6) : ''));
  }
  if (c.find_no) parts.push('#' + escapeHtml(String(c.find_no)));
  const cc = countryCode(c.country);
  if (cc) parts.push('<span class="cc" title="' + escapeHtml(c.country) + '">' + escapeHtml(cc) + '</span>');
  parts.push(statusBadge(c.log_type));
  if (state.fieldnotesAt && !c.fieldnote_ts) parts.push('<span class="no-note">⚠ keine Feldnotiz</span>');
  const dl = dupLevel(c);
  if (dl === 'warn') parts.push('<span class="dup-label warn">⚠ doppelt</span>');
  else if (dl === 'info') parts.push('<span class="dup-label info">ℹ schon besucht</span>');
  return parts.map((p) => '<span class="part">' + p + '</span>').join(' · ');
}

// Schwerste Duplikat-Stufe eines Caches: 'warn' | 'info' | ''
function dupLevel(c) {
  const d = c.duplicates || [];
  return d.some((x) => x.level === 'warn') ? 'warn' : (d.length ? 'info' : '');
}

// Wie der Server: rot nur, wenn beide Einträge als Fund zählen (oder Code doppelt in der Tour)
const FOUND_TYPES = ['Found it', 'Attended', 'Webcam Photo Taken'];
function updateDupLevels(c) {
  (c.duplicates || []).forEach((d) => {
    d.level = d.same_tour || (FOUND_TYPES.includes(d.log_type) && FOUND_TYPES.includes(c.log_type)) ? 'warn' : 'info';
  });
}

function dupSummary() {
  const warn = state.caches.filter((c) => dupLevel(c) === 'warn').length;
  const info = state.caches.filter((c) => dupLevel(c) === 'info').length;
  const parts = [];
  if (warn) parts.push('⚠ ' + warn + ' möglicher Doppelfund / doppelter Code');
  if (info) parts.push('ℹ ' + info + ' Cache(s) auch in anderer Tour (DNF/Notiz)');
  return parts.join(' · ');
}

function moveCache(i, delta) {
  const j = i + delta;
  if (j < 0 || j >= state.caches.length) return;
  const arr = state.caches;
  [arr[i], arr[j]] = [arr[j], arr[i]];
  // aktuell ausgewählten Cache mitführen
  if (state.current === i) state.current = j;
  else if (state.current === j) state.current = i;
  markOrder();
  renderCacheList();
}

function updateProgress() {
  const done = state.caches.filter((c) => c.done).length;
  $('#progress').textContent = done + ' / ' + state.caches.length;
}

function selectCache(i) {
  if (i < 0 || i >= state.caches.length) {
    // alle erledigt
    hide($('#cache-detail'));
    renderCacheList();
    return;
  }
  state.current = i;
  const c = state.caches[i];
  $('#cache-title').textContent = c.name;
  $('#cache-meta').innerHTML = renderMeta(c);
  renderVisit(c);
  const listing = (c.listing || '').trim();
  $('#cache-listing').textContent = listing || '(kein Listing in der GPX vorhanden)';
  $('#cache-listing-len').textContent = listing ? listing.length + ' Zeichen' : 'leer';
  $('#cache-notes').value = c.notes || '';
  $('#log-text').value = c.log || '';
  $('#log-status').textContent = '';
  show($('#cache-detail'));
  rememberPosition();
  renderCacheList();
}

/* ---------- Log-Status, Datum, Uhrzeit ---------- */

// Event-/Webcam-Typen nur bei passenden Caches (dort gibt es kein „Found it")
function logTypeOptions(c) {
  const type = (c.type || '').toLowerCase();
  let opts;
  if (type.includes('event')) opts = ['Attended', 'Will Attend', 'Write note'];
  else if (type.includes('webcam')) opts = ['Webcam Photo Taken', "Didn't find it", 'Write note'];
  else opts = ['Found it', "Didn't find it", 'Write note'];
  if (c.log_type && !opts.includes(c.log_type)) opts.unshift(c.log_type);
  return opts;
}

function renderVisit(c) {
  const sel = $('#cache-status');
  sel.innerHTML = logTypeOptions(c)
    .map((t) => '<option value="' + escapeHtml(t) + '">' + escapeHtml(LOG_TYPE_LABELS[t] || t) + '</option>').join('');
  sel.value = c.log_type || logTypeOptions(c)[0];
  $('#cache-date').value = c.visited_at || state.tourDate || '';
  $('#cache-time').value = c.visited_ts ? berlinParts(c.visited_ts).time : '';
}

function markManual(c, field) {
  c.manual_fields = [...new Set([...(c.manual_fields || []), field])];
}

$('#cache-meta')?.addEventListener('click', (e) => {
  const btn = e.target.closest('.open-tour');
  if (btn) openTour(Number(btn.dataset.tour));
});

$('#cache-status')?.addEventListener('change', (e) => {
  const c = state.caches[state.current];
  if (!c) return;
  c.log_type = e.target.value;
  markManual(c, 'log_type');
  markCache(c, 'log_type');
  updateDupLevels(c);
  $('#cache-meta').innerHTML = renderMeta(c);
  setDupBanner(dupSummary());
  renderCacheList();
});

$('#cache-date')?.addEventListener('change', (e) => {
  const c = state.caches[state.current];
  if (!c) return;
  if (!e.target.value) { e.target.value = c.visited_at || state.tourDate; return; }
  c.visited_at = e.target.value;
  markManual(c, 'visited_at');
  markCache(c, 'visited_at');
  if (c.visited_ts) {            // Uhrzeit behalten, Zeitpunkt aufs neue Datum legen
    c.visited_ts = berlinToUtcIso(c.visited_at, berlinParts(c.visited_ts).time);
    markManual(c, 'visited_ts');
    markCache(c, 'visited_ts');
  }
  renderCacheList();
});

$('#cache-time')?.addEventListener('change', (e) => {
  const c = state.caches[state.current];
  if (!c) return;
  c.visited_ts = e.target.value ? berlinToUtcIso(c.visited_at || state.tourDate, e.target.value) : null;
  markManual(c, 'visited_ts');
  markCache(c, 'visited_ts');
  renderCacheList();
});

function renderMeta(c) {
  const parts = [];
  if (c.type)       parts.push(meta('Typ', c.type));
  if (c.difficulty) parts.push(meta('D', c.difficulty));
  if (c.terrain)    parts.push(meta('T', c.terrain));
  if (c.container)  parts.push(meta('Behälter', c.container));
  const owner = c.owner || c.placed_by;
  if (owner)        parts.push(meta('Owner', owner));
  if (c.placed_by && c.placed_by.toLowerCase() !== (owner || '').toLowerCase()) parts.push(meta('Gelegt von', c.placed_by));
  if (c.fav_points != null) parts.push(meta('Favoriten', '★ ' + c.fav_points));
  if (c.country)    parts.push(meta('Land', c.country + (c.state ? ' (' + c.state + ')' : '')));
  if (c.code)       parts.push(meta('Code', c.code));
  let html = parts.join('');
  if (c.attributes && c.attributes.length) {
    html += '<div class="attrs">' + c.attributes.map((a) => '<span class="attr">' + escapeHtml(a) + '</span>').join('') + '</div>';
  }
  (c.duplicates || []).forEach((d) => {
    const icon = d.level === 'warn' ? '⚠' : 'ℹ';
    html += '<div class="dup-box ' + d.level + '">' + icon + ' ' + (d.same_tour
      ? 'Dieser GC-Code kommt in dieser Tour mehrfach vor.'
      : 'Bereits in Tour #' + d.tour_id + ' „' + escapeHtml(d.title || 'Tour') + '“ vom ' + escapeHtml(formatDate(d.date))
        + ' als <b>' + escapeHtml(LOG_TYPE_LABELS[d.log_type] || d.log_type) + '</b>'
        + (d.level === 'warn' ? ' – möglicher Doppelfund.' : '.')
        + ' <button type="button" class="ghost open-tour" data-tour="' + d.tour_id + '">Tour #' + d.tour_id + ' öffnen ▸</button>')
      + '</div>';
  });
  if (c.own_log) {
    const when = c.own_log.ts ? formatLocalDateTime(c.own_log.ts) : formatDate(c.own_log.date);
    html += '<div class="own-log">✍️ Du hast bereits geloggt (' + escapeHtml(c.own_log.type)
      + (when ? ', ' + escapeHtml(when) : '') + ')</div>';
  }
  (c.companion_logs || []).forEach((l) => {
    html += '<div class="companion-log">👥 ' + escapeHtml(l.name) + ' hat schon geloggt (' + escapeHtml(l.type)
      + (l.date ? ', ' + formatDate(l.date) : '') + ')</div>';
  });
  if (c.hint) html += '<div class="hint">Hinweis: ' + escapeHtml(c.hint) + '</div>';
  return html;
}

function meta(label, val) {
  return '<span class="metaitem"><b>' + escapeHtml(label) + ':</b> ' + escapeHtml(String(val)) + '</span>';
}

$('#cache-notes')?.addEventListener('input', (e) => {
  if (state.current >= 0) {
    state.caches[state.current].notes = e.target.value;
    markCache(state.caches[state.current], 'notes');
  }
});

/* ---------- Log generieren / kopieren / weiter ---------- */

$('#btn-log')?.addEventListener('click', async () => {
  if (state.current < 0) return;
  const c = state.caches[state.current];
  const status = $('#log-status');
  status.textContent = 'Generiere Log …';
  $('#btn-log').disabled = true;
  try {
    const { log } = await api('generate_log', {
      context: state.context,
      cache: JSON.stringify(c),
      notes: c.notes || '',
      companions: JSON.stringify(state.companions),
    });
    c.log = log;
    markCache(c, 'log');
    $('#log-text').value = log;
    status.textContent = 'Fertig – bei Bedarf anpassen, dann kopieren.';
  } catch (ex) {
    status.textContent = 'Fehler: ' + ex.message;
  } finally {
    $('#btn-log').disabled = false;
  }
});

$('#log-text')?.addEventListener('input', (e) => {
  if (state.current >= 0) {
    state.caches[state.current].log = e.target.value;
    markCache(state.caches[state.current], 'log');
  }
});

$('#btn-copy')?.addEventListener('click', async () => {
  const text = $('#log-text').value;
  const status = $('#log-status');
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
    } else {
      const ta = $('#log-text');
      ta.select();
      document.execCommand('copy');
    }
    status.textContent = '✓ In die Zwischenablage kopiert.';
  } catch {
    status.textContent = 'Kopieren nicht möglich – bitte manuell markieren.';
  }
});

$('#btn-next')?.addEventListener('click', () => {
  if (state.current >= 0 && !state.caches[state.current].done) {
    state.caches[state.current].done = true;
    markCache(state.caches[state.current], 'done');
  }
  updateProgress();
  selectCache(nextOpenIndex(state.current));
});

function nextOpenIndex(from) {
  for (let i = from + 1; i < state.caches.length; i++) {
    if (!state.caches[i].done) return i;
  }
  // notfalls von vorne nach offenem suchen
  for (let i = 0; i < state.caches.length; i++) {
    if (!state.caches[i].done) return i;
  }
  return -1;
}

/* ---------- Schritt 4: Touren ---------- */

// Tour-Daten (Datum, Infos, Begleiter): vor dem Kontext in der Einrichtung, danach in der Kontext-Karte
function placeTourFields(inContext) {
  const fields = $('#tour-fields');
  const target = inContext ? $('#tour-fields-slot') : $('#btn-context');
  if (!fields || !target) return;
  if (inContext) target.appendChild(fields);
  else target.before(fields);
}

// Abschnitte der Tour-Bearbeitung (nie gleichzeitig mit „Meine Touren" sichtbar)
const TOUR_SECTIONS = ['#step-setup', '#step-context', '#step-caches', '#step-save', '#step-export'];

function showToursView() {
  flushEdits();
  TOUR_SECTIONS.forEach((sel) => hide($(sel)));
  show($('#view-tours'));
  show($('#view-share'));
  show($('#view-backup'));
  tourPage = 1;
  loadShares();
  window.scrollTo(0, 0);
  fetchAndRenderTours($('#tour-list'), $('#load-status'));
}

// Tour schließen: Zustand, gemerkte Position und lokalen Entwurf verwerfen
function closeTour() {
  clearTimeout(saver.timer);
  saver.timer = null;
  saver.failed = false;
  if (state.tourId) storageDel(draftKey(state.tourId));
  storageDel(LAST_KEY);
  Object.assign(state, {
    caches: [], tourInfo: '', tourDate: todayLocal(), companions: [], context: '', current: -1, tourId: null, fieldnotesAt: null,
  });
  renderSaveIndicator();
}

function startNewTour() {
  flushEdits();
  storageDel(LAST_KEY);
  location.reload();
}

/* Suche/Filter in „Meine Touren" */
function tourFilters() {
  const f = {
    code: $('#f-code').value.trim(),
    date: $('#f-day').value || parsePeriod($('#f-month').value),
    companion: $('#f-companion').value,
    q: $('#f-q').value.trim(),
  };
  Object.keys(f).forEach((k) => { if (!f[k]) delete f[k]; });
  return f;
}

let filterTimer = null;
let tourPage = 1;
function onFilterChange() {
  clearTimeout(filterTimer);
  tourPage = 1;   // neue Filter → zurück auf Seite 1
  filterTimer = setTimeout(() => fetchAndRenderTours($('#tour-list'), $('#load-status')), 300);
}

function goToPage(p) {
  tourPage = p;
  fetchAndRenderTours($('#tour-list'), $('#load-status')).then(() => $('#view-tours').scrollIntoView());
}
$('#btn-page-prev')?.addEventListener('click', () => goToPage(tourPage - 1));
$('#btn-page-next')?.addEventListener('click', () => goToPage(tourPage + 1));

function renderPager(page, pages, total) {
  tourPage = page;
  $('#tour-pager').classList.toggle('hidden', pages <= 1);
  $('#page-info').textContent = 'Seite ' + page + ' / ' + pages + ' · ' + total + ' Touren';
  $('#btn-page-prev').disabled = page <= 1;
  $('#btn-page-next').disabled = page >= pages;
}
['#f-code', '#f-q'].forEach((sel) => $(sel)?.addEventListener('input', onFilterChange));
$('#f-month')?.addEventListener('input', () => {
  const v = $('#f-month').value.trim();
  setPeriodInvalid(v !== '' && !parsePeriod(v));
  if (parsePeriod(v)) $('#f-day').value = '';
  onFilterChange();
});
$('#f-day')?.addEventListener('change', () => {
  if ($('#f-day').value) { $('#f-month').value = ''; setPeriodInvalid(false); }
  onFilterChange();
});

function setPeriodInvalid(bad) {
  $('#f-month').classList.toggle('invalid', bad);
  $('#f-month').setAttribute('aria-invalid', bad ? 'true' : 'false');
  $('#f-month-hint').classList.toggle('hidden', !bad);
}

// „2025" → 2025 · „10.2026" / „10/2026" / „2026-10" → 2026-10 · sonst ''
function parsePeriod(v) {
  const s = (v || '').trim();
  let m;
  if (/^\d{4}$/.test(s)) return s;
  if ((m = /^(\d{4})-(\d{1,2})$/.exec(s)) && +m[2] >= 1 && +m[2] <= 12) return m[1] + '-' + m[2].padStart(2, '0');
  if ((m = /^(\d{1,2})[./](\d{4})$/.exec(s)) && +m[1] >= 1 && +m[1] <= 12) return m[2] + '-' + m[1].padStart(2, '0');
  return '';
}
$('#f-companion')?.addEventListener('change', onFilterChange);
$('#btn-filter-reset')?.addEventListener('click', () => {
  ['#f-code', '#f-month', '#f-day', '#f-companion', '#f-q'].forEach((sel) => { $(sel).value = ''; });
  setPeriodInvalid(false);
  onFilterChange();
});

// Feste Begleiter (config) vorn, übrige alphabetisch – als zwei Gruppen
function fillCompanionFilter(companions) {
  fillCompanionSelect($('#f-companion'), '– alle –', companions);
  const rec = $('#share-recipient');
  if (rec) fillCompanionSelect(rec, '– Begleiter wählen –', companions);
}

function fillCompanionSelect(sel, emptyLabel, { fixed = [], others = [] }) {
  const cur = sel.value;
  const opts = (names) => names.map((n) => '<option value="' + escapeHtml(n) + '">' + escapeHtml(n) + '</option>').join('');
  sel.innerHTML = '<option value="">' + escapeHtml(emptyLabel) + '</option>'
    + (fixed.length ? '<optgroup label="Feste Begleiter">' + opts(fixed) + '</optgroup>' : '')
    + (others.length ? '<optgroup label="Weitere">' + opts(others) + '</optgroup>' : '');
  sel.value = [...fixed, ...others].includes(cur) ? cur : '';
}

// Zeitraum kompakt: 31.10.2026 | 28.–31.10.2026 | 28.09.–02.10.2026
function formatRange(a, b) {
  if (!a) return '';
  if (!b || a === b) return formatDate(a);
  const [ya, ma, da] = a.split('-');
  const [yb, mb, db] = b.split('-');
  if (ya === yb && ma === mb) return da + '.–' + db + '.' + mb + '.' + yb;
  if (ya === yb) return da + '.' + ma + '.–' + db + '.' + mb + '.' + yb;
  return formatDate(a) + '–' + formatDate(b);
}

function tourMeta(t) {
  const parts = [escapeHtml(formatRange(t.date_min || t.tour_date, t.date_max || t.tour_date))];
  parts.push(t.done_count + '/' + t.cache_count + ' erledigt');
  if (t.countries.length) parts.push(t.countries.map((c) => '<b>' + escapeHtml(countryCode(c)) + '</b>').join(' '));
  const html = parts.map((p) => '<span class="part">' + p + '</span>').join(' · ');
  // Begleiter dürfen umbrechen (lange Listen würden die Seite sonst verbreitern)
  return html + (t.companions.length ? ' · <span class="part-wrap">👥 ' + escapeHtml(t.companions.join(', ')) + '</span>' : '');
}

async function fetchAndRenderTours(ul, statusEl) {
  if (statusEl) statusEl.textContent = 'Lade Touren …';
  const filters = tourFilters();
  const active = Object.keys(filters).length;
  $('#filter-count').textContent = active ? active + ' aktiv' : '';
  try {
    const { tours, companions, total, page, pages } = await api('list_tours', { ...filters, page: String(tourPage) });
    fillCompanionFilter(companions || {});
    renderPager(page, pages, total);
    renderBackupHint(active, total);
    ul.innerHTML = '';
    if (!tours.length) {
      ul.innerHTML = '<li class="muted">' + (active ? 'Keine Tour passt zu den Filtern.' : 'Noch keine gespeicherten Touren.') + '</li>';
    }
    tours.forEach((t) => {
      const li = document.createElement('li');
      const active = t.id === state.tourId;
      li.className = 'tour-item' + (active ? ' active' : '');
      li.innerHTML =
        '<span class="tour-info">' +
          (active ? '<span class="badge-active">● geöffnet</span><br>' : '') +
          '<b>#' + t.id + '</b> ' + escapeHtml(t.title || 'Tour') +
          '<span class="tour-meta">' + tourMeta(t) + '</span>' +
          (t.hits && t.hits.length ? '<span class="tour-hits">🔎 '
            + t.hits.map((h) => escapeHtml(h.name) + ' (' + escapeHtml(h.code) + ')').join(', ') + '</span>' : '') +
        '</span>' +
        '<button type="button" class="tour-del" aria-label="Tour löschen" title="Tour löschen">🗑</button>';
      li.addEventListener('click', async (e) => {
        if (e.target.closest('.tour-del')) {
          e.stopPropagation();
          if (!confirm('Tour #' + t.id + ' "' + (t.title || 'Tour') + '" wirklich löschen?')) return;
          try {
            await api('delete_tour', { id: String(t.id) });
            if (t.id === state.tourId) closeTour();
            else storageDel(draftKey(t.id));
            fetchAndRenderTours(ul, statusEl); // Liste neu laden
          } catch (ex) {
            if (statusEl) statusEl.textContent = 'Fehler: ' + ex.message;
          }
          return;
        }
        openTour(t.id);
      });
      ul.appendChild(li);
    });
    show(ul);
    if (statusEl) statusEl.textContent = active ? total + ' Tour(en) gefunden.' : '';
  } catch (ex) {
    if (statusEl) statusEl.textContent = 'Fehler: ' + ex.message;
  }
}

$('#btn-nav-tours')?.addEventListener('click', showToursView);
$('#btn-tours')?.addEventListener('click', showToursView);
$('#btn-tours-new')?.addEventListener('click', startNewTour);

function loadTourIntoState(tour) {
  if (state.tourId && state.tourId !== tour.id) flushEdits();   // offene Edits der alten Tour noch senden
  state.tourId = tour.id;
  state.tourInfo = tour.tour_info || '';
  state.tourDate = tour.tour_date || todayLocal();
  state.fieldnotesAt = tour.fieldnotes_at || null;
  state.companions = tour.companions || [];
  state.context = tour.context || '';
  state.caches = (tour.caches || []).map((c) => ({
    ...c, notes: c.notes || '', log: c.log || '', done: !!c.done,
  }));
  $('#tour-info').value = state.tourInfo;
  setCompanionsUI(state.companions);
  $('#context-text').value = state.context;
}

// opts.tour: bereits geladene Tour; opts.cacheId: diesen Cache auswählen
async function openTour(id, opts = {}) {
  try {
    const tour = opts.tour || (await api('get_tour', { id: String(id) })).tour;
    loadTourIntoState(tour);
    const restored = applyLocalEdits(tour);
    $('#tour-info').value = state.tourInfo;
    setCompanionsUI(state.companions);
    setTourDateUI(state.tourDate);
    $('#context-text').value = state.context;

    ['#view-tours', '#view-share', '#view-backup'].forEach((sel) => { const el = $(sel); if (el) hide(el); });
    if (state.context.trim()) {
      // Kontext steht schon: direkt zu den Caches
      placeTourFields(true);
      hide($('#step-setup'));
      show($('#step-context'));
      show($('#step-caches'));
      show($('#step-save'));
      let idx = state.caches.findIndex((c) => opts.cacheId != null && c.id === opts.cacheId);
      if (idx < 0) idx = nextOpenIndex(-1) === -1 ? 0 : nextOpenIndex(-1);
      selectCache(idx);
    } else {
      // Tour noch in der Einrichtung (vor „Kontext erzeugen")
      placeTourFields(false);
      TOUR_SECTIONS.forEach((sel) => hide($(sel)));
      show($('#step-setup'));
      $('#btn-context').disabled = false;
      $('#gpx-status').textContent = 'Tour #' + tour.id + ' (' + state.caches.length + ' Caches) – Tour-Infos ergänzen, dann Kontext erzeugen.';
      state.current = -1;
      rememberPosition();
      renderCacheList();
    }
    $('#save-status').textContent = 'Tour #' + tour.id + ' geladen.'
      + (restored ? ' ' + restored + ' lokal gesicherte Änderung(en) wiederhergestellt.' : '');
    setDupBanner(dupSummary());
    renderSaveIndicator();
    if (restored) flushEdits();
  } catch (ex) {
    if (ex.status === 404) storageDel(LAST_KEY);
    $('#save-status').textContent = 'Fehler: ' + ex.message;
    $('#load-status') && ($('#load-status').textContent = 'Fehler: ' + ex.message);
  }
}

$('#btn-new')?.addEventListener('click', startNewTour);

/* ---------- Field-Notes-Export (Draft-Datei für geocaching.com) ---------- */

let exportCaches = [];
let exportRecipient = 'me';
const exportManual = new Map();   // von Hand gesetzte Haken (id → bool), je Empfänger
let templateTimer = null;

$('#btn-export')?.addEventListener('click', openExport);
$('#btn-export-close')?.addEventListener('click', () => hide($('#step-export')));

async function openExport() {
  if (!state.tourId) return;
  exportRecipient = 'me';
  exportManual.clear();
  $('#export-recipient').innerHTML = '<option value="me">Ich (KI-Logs)</option>';
  show($('#step-export'));
  $('#step-export').scrollIntoView();
  await loadExport();
}

// Kandidaten laden; template === undefined → gespeicherte Vorlage des Empfängers
async function loadExport(template) {
  // alte Liste (ggf. andere Tour/anderer Empfänger) sofort leeren
  if (template === undefined) {
    exportCaches = [];
    $('#export-list').innerHTML = '';
    $('#export-summary').textContent = '';
    $('#export-preview').textContent = '';
  }
  $('#export-status').textContent = 'Lade …';
  try {
    await flushEdits();
    const data = { tour_id: String(state.tourId), recipient: exportRecipient };
    if (template !== undefined) data.template = template;
    const res = await api('export_preview', data);
    exportCaches = res.caches;
    exportCaches.forEach((c) => { if (exportManual.has(c.id)) c.selected = exportManual.get(c.id); });
    fillRecipients(res.recipients);
    renderTemplateArea(res, template === undefined);
    renderExport();
    $('#export-status').textContent = res.me ? '' : '⚠ gc_username fehlt in config.php – du fehlst in {mitcacher}.';
  } catch (ex) {
    $('#export-status').textContent = 'Fehler: ' + ex.message;
  }
}

function fillRecipients(names) {
  const sel = $('#export-recipient');
  sel.innerHTML = '<option value="me">Ich (KI-Logs)</option>'
    + names.map((n) => '<option value="' + escapeHtml(n) + '">' + escapeHtml(n) + ' (Vorlage)</option>').join('');
  sel.value = exportRecipient;
}

const TEMPLATE_SOURCES = { companion: 'eigene Vorlage', default: 'Standardvorlage', builtin: 'eingebaute Vorlage', edited: 'nur für diesen Export geändert' };

function renderTemplateArea(res, fillText) {
  const wrap = $('#export-template-wrap');
  if (!res.recipient) { hide(wrap); return; }
  show(wrap);
  if (fillText) $('#export-template').value = res.template;
  $('#template-source').textContent = TEMPLATE_SOURCES[res.template_source] || '';
  $('#export-mitcacher').textContent = '{mitcacher} = ' + (res.mitcacher || '(niemand)');
  $('#btn-tpl-save-companion').textContent = 'Als Vorlage für ' + res.recipient + ' speichern';
  $('#btn-export-share').classList.toggle('hidden', !window.GC_STORE);
  // Hinweis, wenn der Begleiter seine Vorlage zuletzt selbst über seinen Link gespeichert hat
  const changed = $('#template-changed');
  const bySharer = res.template_source === 'companion' && res.template_changed_by === 'share' && res.template_changed_at;
  changed.textContent = bySharer ? '✏️ Geändert von ' + res.recipient + ' über Link am ' + formatUtcLocal(res.template_changed_at) : '';
  changed.classList.toggle('hidden', !bySharer);
  const unk = $('#template-unknown');
  unk.textContent = res.unknown.length ? '⚠ Unbekannte Platzhalter: ' + res.unknown.map((u) => '{' + u + '}').join(' ') + ' – Tippfehler?' : '';
  unk.classList.toggle('hidden', !res.unknown.length);
}

$('#export-recipient')?.addEventListener('change', (e) => {
  exportRecipient = e.target.value;
  exportManual.clear();
  $('#template-status').textContent = '';
  loadExport();
});

$('#export-template')?.addEventListener('input', () => {
  clearTimeout(templateTimer);
  templateTimer = setTimeout(() => loadExport($('#export-template').value), 400);
});

document.querySelectorAll('.ph-chip').forEach((b) => b.addEventListener('click', () => {
  const ta = $('#export-template');
  const pos = ta.selectionStart ?? ta.value.length;
  ta.value = ta.value.slice(0, pos) + b.dataset.ph + ta.value.slice(ta.selectionEnd ?? pos);
  ta.focus();
  ta.selectionStart = ta.selectionEnd = pos + b.dataset.ph.length;
  ta.dispatchEvent(new Event('input'));
}));

async function saveTemplate(name, label) {
  try {
    await api('save_template', { name, template: $('#export-template').value });
    $('#template-status').textContent = '✓ ' + label + ' gespeichert.';
    $('#template-source').textContent = name ? TEMPLATE_SOURCES.companion : TEMPLATE_SOURCES.default;
    if (name) hide($('#template-changed'));
  } catch (ex) {
    $('#template-status').textContent = 'Fehler: ' + ex.message;
  }
}
$('#btn-tpl-save-companion')?.addEventListener('click', () => saveTemplate(exportRecipient, 'Vorlage für ' + exportRecipient));
$('#btn-tpl-save-default')?.addEventListener('click', () => saveTemplate('', 'Standardvorlage'));
$('#btn-export-share')?.addEventListener('click', () => {
  const recipient = exportRecipient;
  const tourId = state.tourId;
  showToursView();
  openShareNew(recipient, tourId);
});

/* ---------- Freigabe-Links für Begleiter (spec-v3) ---------- */

let shareTours = [];   // Touren des gewählten Empfängers: { id, title, tour_date, date_min, date_max, cache_count }
const shareChecked = new Set();

async function loadShares(highlightId) {
  const ul = $('#share-list');
  if (!ul) return;
  try {
    const { shares } = await api('share_list');
    ul.innerHTML = shares.length ? '' : '<li class="muted">Keine aktiven Links.</li>';
    shares.forEach((s) => ul.appendChild(shareItem(s, s.id === highlightId)));
    $('#share-status').textContent = '';
  } catch (ex) {
    $('#share-status').textContent = 'Fehler: ' + ex.message;
  }
}

function shareItem(s, isNew) {
  const li = document.createElement('li');
  li.className = 'share-item' + (isNew ? ' new' : '');
  const tours = s.tours.map((t) => '#' + t.id + ' ' + escapeHtml(t.title || 'Tour') + ' (' + escapeHtml(formatDate(t.tour_date)) + ')').join(', ');
  const meta = [
    'gültig bis ' + escapeHtml(formatUtcLocal(s.expires_at)),
    s.last_used_at ? 'zuletzt geöffnet ' + escapeHtml(formatUtcLocal(s.last_used_at)) : 'noch nicht geöffnet',
    s.downloads + ' Download' + (s.downloads === 1 ? '' : 's'),
    s.exported + ' Cache' + (s.exported === 1 ? '' : 's') + ' exportiert',
  ];
  li.innerHTML = '<span class="x-main"><b>👤 ' + escapeHtml(s.recipient) + '</b>' + (isNew ? ' <span class="badge">neu</span>' : '')
    + '<span class="x-sub">' + tours + '</span>'
    + '<span class="x-sub">' + meta.map((m) => '<span class="part">' + m + '</span>').join(' · ') + '</span>'
    + '<input type="text" class="share-url" readonly aria-label="Link für ' + escapeHtml(s.recipient) + '" value="' + escapeHtml(s.url) + '">'
    + '<span class="row">'
    + '<button type="button" class="ghost btn-copy">📋 Kopieren</button>'
    + (navigator.share ? '<button type="button" class="ghost btn-share">📤 Teilen</button>' : '')
    + '<button type="button" class="ghost btn-revoke">🗑 Widerrufen</button>'
    + '</span><span class="x-sub share-msg" aria-live="polite"></span></span>';
  const msg = li.querySelector('.share-msg');
  const input = li.querySelector('.share-url');
  input.addEventListener('focus', () => input.select());
  li.querySelector('.btn-copy').addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(s.url);
    } catch {
      input.select();
      document.execCommand('copy');
    }
    msg.textContent = '✓ Link kopiert.';
  });
  li.querySelector('.btn-share')?.addEventListener('click', () => {
    navigator.share({ title: 'Field Notes', text: 'Hallo ' + s.recipient + ', hier deine Field Notes zum Hochladen bei geocaching.com:', url: s.url })
      .catch(() => {});   // Abbruch durch Nutzer
  });
  li.querySelector('.btn-revoke').addEventListener('click', async () => {
    if (!confirm('Link für ' + s.recipient + ' widerrufen? Er funktioniert danach nicht mehr.')) return;
    try {
      await api('share_delete', { id: String(s.id) });
      loadShares();
    } catch (ex) {
      msg.textContent = 'Fehler: ' + ex.message;
    }
  });
  return li;
}

// Alle Touren mit diesem Begleiter (über alle Seiten der Tourliste)
async function loadShareTours(recipient) {
  const ul = $('#share-tours');
  const status = $('#share-tours-status');
  shareTours = [];
  ul.innerHTML = '';
  renderShareCreate();
  if (!recipient) { status.textContent = ''; return; }
  status.textContent = 'Lade Touren …';
  try {
    for (let page = 1, pages = 1; page <= pages; page++) {
      const res = await api('list_tours', { companion: recipient, page: String(page) });
      shareTours.push(...res.tours);
      pages = res.pages;
    }
    status.textContent = shareTours.length ? 'Touren mit ' + recipient + ':' : 'Keine Touren mit ' + recipient + '.';
    renderShareTours();
  } catch (ex) {
    status.textContent = 'Fehler: ' + ex.message;
  }
}

function renderShareTours() {
  const ul = $('#share-tours');
  ul.innerHTML = '';
  shareTours.forEach((t) => {
    const li = document.createElement('li');
    li.className = 'export-item clickable';
    li.innerHTML = '<input type="checkbox"' + (shareChecked.has(t.id) ? ' checked' : '') + ' aria-label="Tour ' + t.id + ' freigeben">'
      + '<span class="x-main"><b>#' + t.id + '</b> ' + escapeHtml(t.title || 'Tour')
      + '<span class="x-sub"><span class="part">' + escapeHtml(formatRange(t.date_min || t.tour_date, t.date_max || t.tour_date)) + '</span>'
      + ' · <span class="part">' + t.cache_count + ' Caches</span></span></span>';
    const cb = li.querySelector('input');
    cb.addEventListener('change', () => {
      if (cb.checked) shareChecked.add(t.id); else shareChecked.delete(t.id);
      renderShareCreate();
    });
    // ganze Karte anklickbar, nicht nur die Checkbox
    li.addEventListener('click', (e) => {
      if (e.target === cb) return;
      cb.checked = !cb.checked;
      cb.dispatchEvent(new Event('change'));
    });
    ul.appendChild(li);
  });
  renderShareCreate();
}

function renderShareCreate() {
  const n = shareTours.filter((t) => shareChecked.has(t.id)).length;
  const btn = $('#btn-share-create');
  btn.disabled = n === 0;
  btn.textContent = '🔗 Link erzeugen' + (n ? ' (' + n + ' Tour' + (n === 1 ? '' : 'en') + ')' : '');
}

// Aus dem Export-Dialog: Empfänger + aktuelle Tour vorbelegen
async function openShareNew(recipient, tourId) {
  const det = $('#share-new');
  det.open = true;
  shareChecked.clear();
  if (tourId) shareChecked.add(tourId);
  const sel = $('#share-recipient');
  if (![...sel.options].some((o) => o.value === recipient)) sel.add(new Option(recipient, recipient));
  sel.value = recipient;
  await loadShareTours(recipient);
  $('#view-share').scrollIntoView();
}

$('#share-recipient')?.addEventListener('change', (e) => {
  shareChecked.clear();
  $('#share-create-status').textContent = '';
  loadShareTours(e.target.value);
});

$('#btn-share-create')?.addEventListener('click', async () => {
  const recipient = $('#share-recipient').value;
  const ids = shareTours.filter((t) => shareChecked.has(t.id)).map((t) => t.id);
  const status = $('#share-create-status');
  status.textContent = 'Erzeuge Link …';
  try {
    const { share } = await api('share_create', { recipient, tour_ids: JSON.stringify(ids) });
    status.textContent = '✓ Link für ' + recipient + ' erzeugt – unten kopieren oder teilen.';
    shareChecked.clear();
    renderShareTours();
    $('#share-new').open = false;
    await loadShares(share.id);
    $('#share-list .share-item.new')?.scrollIntoView({ block: 'nearest' });
  } catch (ex) {
    status.textContent = 'Fehler: ' + ex.message;
  }
});

function renderExport() {
  const ul = $('#export-list');
  ul.innerHTML = '';
  exportCaches.forEach((c) => {
    const li = document.createElement('li');
    li.className = 'export-item' + (c.available ? '' : ' unavailable');
    const t = berlinParts(c.ts);
    const parts = [
      '<span class="' + (c.estimated ? 'estimated' : '') + '">' + (c.estimated ? '~' : '') + '<b>' + escapeHtml(t.time) + '</b> '
        + escapeHtml(formatDate(t.date).slice(0, 6)) + (c.estimated ? ' (geschätzt)' : '') + '</span>',
      statusBadge(c.log_type),
    ];
    if (!c.available) parts.push('<span class="no-note">kein Log</span>');
    if (c.already) parts.push('✍️ schon geloggt');
    if (c.companion_logged) parts.push('👥 ' + escapeHtml(exportRecipient) + ' hat schon geloggt');
    if (c.not_found) parts.push('<span class="muted">kein Fund</span>');
    if (c.double_find) parts.push('<span class="dup-label warn">⚠ Doppelfund?</span>');
    li.innerHTML = '<input type="checkbox"' + (c.selected ? ' checked' : '') + (c.available ? '' : ' disabled')
        + ' aria-label="' + escapeHtml(c.name) + ' exportieren">'
      + '<span class="x-main"><b>' + escapeHtml(c.name) + '</b> <span class="muted">' + escapeHtml(c.code) + '</span>'
      + '<span class="x-sub">' + parts.map((p) => '<span class="part">' + p + '</span>').join(' · ') + '</span></span>';
    li.querySelector('input').addEventListener('change', (e) => {
      c.selected = e.target.checked;
      exportManual.set(c.id, c.selected);
      renderExportSummary();
    });
    ul.appendChild(li);
  });
  renderExportSummary();
}

function renderExportSummary() {
  const sel = exportCaches.filter((c) => c.selected && c.available);
  const est = sel.filter((c) => c.estimated).length;
  $('#export-summary').textContent = sel.length + ' von ' + exportCaches.length + ' Caches ausgewählt'
    + (est ? ' · ' + est + ' ohne Uhrzeit (geschätzt ab 10:00, je 10 Min.)' : '');
  $('#btn-export-download').disabled = sel.length === 0;
  // Vorschau: so wie in der Datei (neuester zuerst), die ersten zwei
  const lines = [...sel].sort((a, b) => b.ts.localeCompare(a.ts)).slice(0, 2)
    .map((c) => c.code + ',' + c.ts + ',' + c.log_type + ',"' + c.text.replace(/"/g, '""') + '"');
  $('#export-preview').textContent = lines.length ? lines.join('\n\n') : '(nichts ausgewählt)';
}

$('#btn-export-download')?.addEventListener('click', async () => {
  const ids = exportCaches.filter((c) => c.selected && c.available).map((c) => c.id);
  const status = $('#export-status');
  status.textContent = 'Erzeuge Datei …';
  try {
    const data = { tour_id: String(state.tourId), recipient: exportRecipient, ids: JSON.stringify(ids) };
    if (exportRecipient !== 'me') data.template = $('#export-template').value;
    const name = await downloadPost('export_fieldnotes', data, 'geocache_visits.txt');
    status.textContent = '✓ ' + ids.length + ' Einträge exportiert (' + name + '). Jetzt bei geocaching.com unter Drafts → Upload hochladen.';
  } catch (ex) {
    status.textContent = 'Fehler: ' + ex.message;
  }
});

/* ---------- Downloads (Field Notes, Backup) ---------- */

// JSON und GPX folgen den aktiven Filtern; SQLite ist immer komplett
async function backup(action, fallbackName, filtered) {
  const status = $('#backup-status');
  status.textContent = 'Erzeuge Backup …';
  try {
    await flushEdits();
    status.textContent = '✓ ' + await downloadPost(action, filtered ? tourFilters() : {}, fallbackName) + ' heruntergeladen.';
  } catch (ex) {
    status.textContent = 'Fehler: ' + ex.message;
  }
}
$('#btn-backup-json')?.addEventListener('click', () => backup('backup_json', 'gc_logger-backup.json', true));
$('#btn-backup-gpx')?.addEventListener('click', () => backup('backup_gpx', 'gc_logger-caches.gpx', true));
$('#btn-backup-sqlite')?.addEventListener('click', () => backup('backup_sqlite', 'gc_logger-backup.sqlite', false));

function renderBackupHint(active, total) {
  const el = $('#backup-filter-hint');
  if (!el) return;
  el.textContent = active ? 'ℹ Filter aktiv: JSON und GPX enthalten nur die ' + total + ' gefilterte(n) Tour(en). SQLite bleibt komplett.' : '';
  el.classList.toggle('hidden', !active);
}

/* ---------- Debug (nur wenn in config.php aktiviert) ---------- */

if (window.GC_DEBUG) {
  $('#btn-debuglog')?.addEventListener('click', async () => {
    const out = $('#debug-out');
    out.textContent = 'Lade …';
    try {
      const { log } = await api('debug_log');
      out.textContent = log && log.trim() ? log : '(Log ist leer)';
      out.scrollTop = out.scrollHeight;
    } catch (ex) {
      out.textContent = 'Fehler: ' + ex.message;
    }
  });
  $('#btn-debugclear')?.addEventListener('click', async () => {
    try {
      await api('debug_clear');
      $('#debug-out').textContent = '(geleert)';
    } catch (ex) {
      $('#debug-out').textContent = 'Fehler: ' + ex.message;
    }
  });
}

/* ---------- Utility ---------- */

// Ortszeit (Datum + 'HH:MM') → UTC-ISO 'YYYY-MM-DDTHH:MM:SSZ' (Sommer-/Winterzeit beachtet)
function berlinToUtcIso(date, time) {
  const [y, mo, d] = date.split('-').map(Number);
  const [h, mi] = time.split(':').map(Number);
  const target = Date.UTC(y, mo - 1, d, h, mi);
  let utc = target;
  for (let k = 0; k < 2; k++) {
    const p = berlinParts(new Date(utc).toISOString());
    const [py, pmo, pd] = p.date.split('-').map(Number);
    const [ph, pmi] = p.time.split(':').map(Number);
    utc += target - Date.UTC(py, pmo - 1, pd, ph, pmi);
  }
  return new Date(utc).toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function todayLocal() {
  return berlinParts(new Date().toISOString()).date;
}

// Ländername (GPX, englisch) → ISO-Kürzel für die kompakte Liste
const COUNTRY_CODES = {
  germany: 'DE', deutschland: 'DE', denmark: 'DK', netherlands: 'NL', belgium: 'BE', luxembourg: 'LU',
  france: 'FR', switzerland: 'CH', austria: 'AT', liechtenstein: 'LI', italy: 'IT', spain: 'ES', portugal: 'PT',
  poland: 'PL', czechia: 'CZ', 'czech republic': 'CZ', slovakia: 'SK', hungary: 'HU', slovenia: 'SI', croatia: 'HR',
  sweden: 'SE', norway: 'NO', finland: 'FI', iceland: 'IS', 'united kingdom': 'GB', ireland: 'IE',
  greece: 'GR', 'united states': 'US', canada: 'CA',
};
function countryCode(country) {
  const n = (country || '').trim();
  if (!n) return '';
  return COUNTRY_CODES[n.toLowerCase()] || n.slice(0, 2).toUpperCase();
}

// UTC-ISO → 'TT.MM.JJJJ HH:MM' in Ortszeit (Europe/Berlin)
function formatLocalDateTime(iso) {
  const d = new Date(iso);
  if (isNaN(d)) return '';
  return d.toLocaleString('de-DE', {
    timeZone: 'Europe/Berlin', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
  }).replace(',', '');
}

/* ---------- Start (am Ende: alle Konstanten sind definiert) ---------- */

// Beim Start: Tour-Datum vorbelegen, an der letzten Stelle weitermachen
state.tourDate = todayLocal();
setTourDateUI(state.tourDate);
if (document.body.dataset.authed === '1' && window.GC_STORE) {
  const last = storageGet(LAST_KEY);
  if (last && last.tourId) openTour(last.tourId, { cacheId: last.cacheId });
}
