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

function statusBadge(type) {
  const t = type || 'Found it';
  const cls = t === "Didn't find it" ? 's-dnf'
    : (['Found it', 'Attended', 'Webcam Photo Taken'].includes(t) ? 's-found' : 's-other');
  return '<span class="status ' + cls + '">' + escapeHtml(LOG_TYPE_LABELS[t] || t) + '</span>';
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

/* Cache-Typ-Symbole: eigene SVGs im Stil von geocaching.com (Farbe + Form),
   damit sie offline funktionieren. Reihenfolge der Muster ist wichtig (z. B. Mega vor Event). */
const TYPE_ICONS = [
  [/mega/i, '#a4235b', 'evt', 'Mega-Event'],
  [/giga/i, '#6b2a8a', 'evt', 'Giga-Event'],
  [/cito|trash/i, '#3e8e41', 'cito', 'CITO-Event'],
  [/community celebration/i, '#d9822b', 'evt', 'Community-Celebration-Event'],
  [/event/i, '#c8312f', 'evt', 'Event'],
  [/tradi/i, '#02874d', 'box', 'Traditional'],
  [/multi/i, '#e5a00d', 'multi', 'Multi'],
  [/unknown|mystery/i, '#1f5aa6', 'q', 'Mystery'],
  [/letterbox/i, '#25407a', 'mail', 'Letterbox'],
  [/earth/i, '#1d7a87', 'globe', 'Earthcache'],
  [/virtual/i, '#3b8fd4', 'virt', 'Virtual'],
  [/webcam/i, '#5e6670', 'cam', 'Webcam'],
  [/wherigo/i, '#2b6cb0', 'w', 'Wherigo'],
  [/lab/i, '#00a0a0', 'flask', 'Adventure Lab'],
];
const ICON_GLYPHS = {
  box: '<rect x="5" y="8" width="10" height="7" rx="1" fill="#fff"/><rect x="4.5" y="6" width="11" height="3" rx="1" fill="#fff"/>',
  multi: '<rect x="3.5" y="9" width="7" height="6" rx="1" fill="#fff"/><rect x="9.5" y="5" width="7" height="6" rx="1" fill="#fff" opacity=".85"/>',
  q: '<path d="M7.3 7.6a2.7 2.7 0 1 1 3.9 2.4c-.8.4-1.2 1-1.2 1.8v.6" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round"/><circle cx="10" cy="15.2" r="1.2" fill="#fff"/>',
  mail: '<rect x="4" y="6" width="12" height="9" rx="1" fill="#fff"/><path d="M4.5 6.5 10 11l5.5-4.5" stroke="#25407a" stroke-width="1.4" fill="none"/>',
  evt: '<rect x="4" y="5.5" width="12" height="10" rx="1.2" fill="#fff"/><rect x="4" y="5.5" width="12" height="3" fill="#fff" opacity=".7"/><circle cx="7.5" cy="11.5" r="1.2" fill="currentColor"/><circle cx="12.5" cy="11.5" r="1.2" fill="currentColor"/>',
  cito: '<path d="M6 7h8l-1 9H7z" fill="#fff"/><rect x="5" y="5" width="10" height="1.8" rx=".6" fill="#fff"/>',
  globe: '<circle cx="10" cy="10" r="5.5" fill="none" stroke="#fff" stroke-width="1.5"/><path d="M4.5 10h11M10 4.5c-2.5 3-2.5 8 0 11M10 4.5c2.5 3 2.5 8 0 11" stroke="#fff" stroke-width="1.1" fill="none"/>',
  virt: '<rect x="5" y="6" width="10" height="9" rx="1" fill="none" stroke="#fff" stroke-width="1.5" stroke-dasharray="2 1.6"/>',
  cam: '<rect x="4" y="7" width="12" height="8" rx="1.5" fill="#fff"/><circle cx="10" cy="11" r="2.4" fill="#5e6670"/><rect x="7" y="5.5" width="4" height="2" rx=".5" fill="#fff"/>',
  w: '<path d="M4.5 6.5l1.8 7.5 3.7-5.5 3.7 5.5 1.8-7.5" stroke="#fff" stroke-width="1.8" fill="none" stroke-linejoin="round" stroke-linecap="round"/>',
  flask: '<path d="M8.5 5h3v3.5l3.5 6.5H5l3.5-6.5z" fill="#fff"/>',
  other: '<circle cx="10" cy="10" r="3" fill="#fff"/>',
};
function typeIcon(type) {
  const t = type || '';
  const hit = TYPE_ICONS.find(([re]) => re.test(t)) || [null, '#8a929c', 'other', t || 'Cache'];
  const [, color, glyph] = hit;
  const label = t || hit[3];
  // Tooltip/Screenreader-Text am Wrapper: kein Text im Namen (textContent, Kopieren)
  return '<span class="type-icon" role="img" aria-label="' + escapeHtml(label) + '" title="' + escapeHtml(label) + '">'
    + '<svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" style="color:' + color + '">'
    + '<rect x="0.5" y="0.5" width="19" height="19" rx="4.5" fill="' + color + '"/>' + ICON_GLYPHS[glyph] + '</svg></span>';
}

const LOG_TYPE_LABELS = {
  'Found it': 'Fund',
  "Didn't find it": 'DNF',
  'Write note': 'Notiz',
  'Attended': 'Teilgenommen',
  'Will Attend': 'Werde teilnehmen',
  'Webcam Photo Taken': 'Webcam-Foto',
};

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

// Abschnitte der Tour-Bearbeitung (nie gleichzeitig mit „Meine Touren" sichtbar)
const TOUR_SECTIONS = ['#step-setup', '#step-context', '#step-caches', '#step-save'];

function showToursView() {
  flushEdits();
  TOUR_SECTIONS.forEach((sel) => hide($(sel)));
  show($('#view-tours'));
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
      const active = t.id === state.tourId;
      li.className = 'tour-item' + (active ? ' active' : '');
      li.innerHTML =
        '<span class="tour-info">' +
          (active ? '<span class="badge-active">● geöffnet</span><br>' : '') +
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
    if (statusEl) statusEl.textContent = '';
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

    const vt = $('#view-tours'); if (vt) hide(vt);
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

const TZ = 'Europe/Berlin';

// UTC-Zeitpunkt → { date: 'YYYY-MM-DD', time: 'HH:MM' } in Ortszeit
function berlinParts(iso) {
  const p = {};
  new Intl.DateTimeFormat('en-CA', {
    timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(new Date(iso)).forEach(({ type, value }) => { p[type] = value; });
  return { date: p.year + '-' + p.month + '-' + p.day, time: p.hour + ':' + p.minute };
}

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

/* ---------- Start (am Ende: alle Konstanten sind definiert) ---------- */

// Beim Start: Tour-Datum vorbelegen, an der letzten Stelle weitermachen
state.tourDate = todayLocal();
setTourDateUI(state.tourDate);
if (document.body.dataset.authed === '1' && window.GC_STORE) {
  const last = storageGet(LAST_KEY);
  if (last && last.tourId) openTour(last.tourId, { cacheId: last.cacheId });
}
