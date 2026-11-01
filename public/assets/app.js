'use strict';

/* Geocaching-Log-Helfer — Frontend-Logik (Vanilla JS, kein Build-Schritt) */

const state = {
  caches: [],     // je Eintrag zusätzlich: notes, log, done
  tourInfo: '',
  companions: [],
  context: '',
  current: -1,    // Index des aktuell bearbeiteten Caches
  tourId: null,   // jede Tour ist gespeichert (create_tour beim GPX-Upload)
};

/* ---------- kleine Helfer ---------- */

const $ = (sel) => document.querySelector(sel);

function show(el)  { el.classList.remove('hidden'); }
function hide(el)  { el.classList.add('hidden'); }

async function api(action, data = {}, isForm = false) {
  let body;
  if (isForm) {
    body = data;                  // FormData (z. B. Datei-Upload)
    body.append('action', action);
  } else {
    body = new FormData();
    body.append('action', action);
    for (const [k, v] of Object.entries(data)) body.append(k, v);
  }
  const res = await fetch('api.php', { method: 'POST', body });
  const raw = await res.text();
  let json;
  try {
    json = JSON.parse(raw);
  } catch {
    const snippet = raw.trim().slice(0, 200) || '(leere Antwort)';
    throw new Error('Server-Antwort war kein JSON (HTTP ' + res.status + '): ' + snippet);
  }
  if (!json.ok) {
    const err = new Error(json.error || 'Unbekannter Fehler.');
    err.status = res.status;
    throw err;
  }
  return json;
}

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
    return { tour_info: state.tourInfo, companions: state.companions, context: state.context }[a];
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
      fd2.append('meta', JSON.stringify({ tour_info: $('#tour-info').value, companions: state.companions }));
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
      status.textContent = '✓ Tour #' + tour.id + ' angelegt (' + tour.caches.length + ' Caches) – wird automatisch gespeichert.';
    } else {
      status.textContent = '';
      await openTour(tour.id, { tour });
      $('#save-status').textContent = mode === 'add'
        ? '✓ ' + added + ' Cache(s) zu Tour #' + tour.id + ' hinzugefügt.'
        : '✓ Tour #' + tour.id + ' geöffnet, GPX-Daten aufgefrischt.';
    }
  } catch (ex) {
    status.textContent = 'Fehler: ' + ex.message;
    $('#btn-context').disabled = true;
  } finally {
    e.target.value = '';   // gleiche Datei erneut wählbar
  }
});

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

function collectCompanions() {
  const list = [];
  document.querySelectorAll('.companion:checked').forEach((c) => list.push(c.value));
  const extra = $('#companions-extra').value.trim();
  if (extra) {
    extra.split(',').map((s) => s.trim()).filter(Boolean).forEach((s) => list.push(s));
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
    li.className = 'cache-item' + (i === state.current ? ' active' : '') + (c.done ? ' done' : '');
    const last = state.caches.length - 1;
    li.innerHTML =
      '<span class="check">' + (c.done ? '✓' : '○') + '</span>' +
      '<span class="cname">' + escapeHtml(c.name) + '</span>' +
      '<span class="ctype">' + escapeHtml(c.type || '') + '</span>' +
      '<span class="moves">' +
        '<button type="button" class="move" data-dir="up" aria-label="nach oben"' + (i === 0 ? ' disabled' : '') + '>▲</button>' +
        '<button type="button" class="move" data-dir="down" aria-label="nach unten"' + (i === last ? ' disabled' : '') + '>▼</button>' +
      '</span>';
    li.addEventListener('click', (e) => {
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

function renderMeta(c) {
  const parts = [];
  if (c.type)       parts.push(meta('Typ', c.type));
  if (c.difficulty) parts.push(meta('D', c.difficulty));
  if (c.terrain)    parts.push(meta('T', c.terrain));
  if (c.container)  parts.push(meta('Behälter', c.container));
  if (c.placed_by)  parts.push(meta('Owner', c.placed_by));
  if (c.country)    parts.push(meta('Land', c.country + (c.state ? ' (' + c.state + ')' : '')));
  if (c.code)       parts.push(meta('Code', c.code));
  let html = parts.join('');
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

async function fetchAndRenderTours(ul, statusEl) {
  if (statusEl) statusEl.textContent = 'Lade Touren …';
  try {
    const { tours } = await api('list_tours');
    ul.innerHTML = '';
    if (!tours.length) {
      ul.innerHTML = '<li class="muted">Noch keine gespeicherten Touren.</li>';
    }
    tours.forEach((t) => {
      const li = document.createElement('li');
      li.className = 'tour-item';
      li.innerHTML =
        '<span class="tour-info">' +
          '<b>#' + t.id + '</b> ' + escapeHtml(t.title || 'Tour') +
          ' <span class="muted">(' + t.done_count + '/' + t.cache_count + ' · ' +
          escapeHtml(t.created_at || '') + ')</span>' +
        '</span>' +
        '<button type="button" class="tour-del" aria-label="Tour löschen" title="Tour löschen">🗑</button>';
      li.addEventListener('click', async (e) => {
        if (e.target.closest('.tour-del')) {
          e.stopPropagation();
          if (!confirm('Tour #' + t.id + ' "' + (t.title || 'Tour') + '" wirklich löschen?')) return;
          try {
            await api('delete_tour', { id: String(t.id) });
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
    if (statusEl) statusEl.textContent = '';
  } catch (ex) {
    if (statusEl) statusEl.textContent = 'Fehler: ' + ex.message;
  }
}

// Liste im Start-Screen (oben) und im Schritt 4 (unten)
$('#btn-load-tours')?.addEventListener('click', () => fetchAndRenderTours($('#tour-list-top'), $('#load-status')));
$('#btn-tours')?.addEventListener('click', () => fetchAndRenderTours($('#tour-list'), $('#save-status')));

function loadTourIntoState(tour) {
  if (state.tourId && state.tourId !== tour.id) flushEdits();   // offene Edits der alten Tour noch senden
  state.tourId = tour.id;
  state.tourInfo = tour.tour_info || '';
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
    $('#context-text').value = state.context;

    const sl = $('#step-load'); if (sl) hide(sl);
    hide($('#tour-list'));
    hide($('#tour-list-top'));
    if (state.context.trim()) {
      // Kontext steht schon: direkt zu den Caches
      hide($('#step-setup'));
      show($('#step-context'));
      show($('#step-caches'));
      show($('#step-save'));
      let idx = state.caches.findIndex((c) => opts.cacheId != null && c.id === opts.cacheId);
      if (idx < 0) idx = nextOpenIndex(-1) === -1 ? 0 : nextOpenIndex(-1);
      selectCache(idx);
    } else {
      // Tour noch in der Einrichtung (vor „Kontext erzeugen")
      show($('#step-setup'));
      $('#btn-context').disabled = false;
      $('#gpx-status').textContent = 'Tour #' + tour.id + ' (' + state.caches.length + ' Caches) – Tour-Infos ergänzen, dann Kontext erzeugen.';
      state.current = -1;
      rememberPosition();
      renderCacheList();
    }
    $('#save-status').textContent = 'Tour #' + tour.id + ' geladen.'
      + (restored ? ' ' + restored + ' lokal gesicherte Änderung(en) wiederhergestellt.' : '');
    renderSaveIndicator();
    if (restored) flushEdits();
  } catch (ex) {
    if (ex.status === 404) storageDel(LAST_KEY);
    $('#save-status').textContent = 'Fehler: ' + ex.message;
    $('#load-status') && ($('#load-status').textContent = 'Fehler: ' + ex.message);
  }
}

$('#btn-new')?.addEventListener('click', async () => {
  await flushEdits();
  storageDel(LAST_KEY);
  location.reload();
});

// Beim Start an der letzten Stelle weitermachen
if (document.body.dataset.authed === '1' && window.GC_STORE) {
  const last = storageGet(LAST_KEY);
  if (last && last.tourId) openTour(last.tourId, { cacheId: last.cacheId });
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

// 'YYYY-MM-DD' → 'TT.MM.JJJJ'
function formatDate(d) {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || '');
  return m ? m[3] + '.' + m[2] + '.' + m[1] : (d || '');
}

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
