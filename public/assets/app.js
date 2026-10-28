'use strict';

/* Geocaching-Log-Helfer — Frontend-Logik (Vanilla JS, kein Build-Schritt) */

const state = {
  caches: [],     // je Eintrag zusätzlich: notes, log, done
  tourInfo: '',
  companions: [],
  context: '',
  current: -1,    // Index des aktuell bearbeiteten Caches
  tourId: null,   // gesetzt nach erstem Speichern
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
  if (!json.ok) throw new Error(json.error || 'Unbekannter Fehler.');
  return json;
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
    const { caches } = await api('upload_gpx', fd, true);
    state.caches = caches.map((c) => ({ ...c, notes: '', log: '', done: false }));
    state.current = -1;
    state.tourId = null;
    status.textContent = caches.length + ' Cache(s) geladen.';
    $('#btn-context').disabled = false;
    renderCacheList();
  } catch (ex) {
    status.textContent = 'Fehler: ' + ex.message;
    $('#btn-context').disabled = true;
  }
});

/* ---------- Begleiter einsammeln ---------- */

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
  state.companions = collectCompanions();
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

$('#context-text')?.addEventListener('input', (e) => { state.context = e.target.value; });

/* ---------- Sammel-Modus: alle offenen Logs in EINER Anfrage ---------- */

$('#btn-generate-all')?.addEventListener('click', async () => {
  const status = $('#generate-all-status');
  const open = state.caches.filter((c) => !(c.log || '').trim());
  if (!open.length) {
    status.textContent = 'Alle Caches haben bereits ein Log.';
    return;
  }
  status.textContent = 'Generiere ' + open.length + ' Log(s) in einer Anfrage …';
  $('#btn-generate-all').disabled = true;
  try {
    const { logs } = await api('generate_all', {
      context: state.context,
      caches: JSON.stringify(open),
      companions: JSON.stringify(state.companions),
    });
    let applied = 0;
    let missing = [];
    state.caches.forEach((c) => {
      if (!(c.log || '').trim()) {
        if (logs[c.code] != null) { c.log = logs[c.code]; applied++; }
        else missing.push(c.code);
      }
    });
    if (state.current >= 0) $('#log-text').value = state.caches[state.current].log || '';
    renderCacheList();
    status.textContent = '✓ ' + applied + ' Log(s) erzeugt.'
      + (missing.length ? ' Ohne Ergebnis: ' + missing.join(', ') + ' (einzeln nachgenerieren).' : '');
  } catch (ex) {
    status.textContent = 'Fehler: ' + ex.message;
  } finally {
    $('#btn-generate-all').disabled = false;
  }
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
  renderCacheList();
}

function renderMeta(c) {
  const parts = [];
  if (c.type)       parts.push(meta('Typ', c.type));
  if (c.difficulty) parts.push(meta('D', c.difficulty));
  if (c.terrain)    parts.push(meta('T', c.terrain));
  if (c.container)  parts.push(meta('Behälter', c.container));
  if (c.placed_by)  parts.push(meta('Owner', c.placed_by));
  if (c.code)       parts.push(meta('Code', c.code));
  let html = parts.join('');
  if (c.hint) html += '<div class="hint">Hinweis: ' + escapeHtml(c.hint) + '</div>';
  return html;
}

function meta(label, val) {
  return '<span class="metaitem"><b>' + escapeHtml(label) + ':</b> ' + escapeHtml(String(val)) + '</span>';
}

$('#cache-notes')?.addEventListener('input', (e) => {
  if (state.current >= 0) state.caches[state.current].notes = e.target.value;
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
    $('#log-text').value = log;
    status.textContent = 'Fertig – bei Bedarf anpassen, dann kopieren.';
  } catch (ex) {
    status.textContent = 'Fehler: ' + ex.message;
  } finally {
    $('#btn-log').disabled = false;
  }
});

$('#log-text')?.addEventListener('input', (e) => {
  if (state.current >= 0) state.caches[state.current].log = e.target.value;
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
  if (state.current >= 0) {
    state.caches[state.current].done = true;
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

/* ---------- Schritt 4: Speichern & Touren ---------- */

$('#btn-save')?.addEventListener('click', async () => {
  const status = $('#save-status');
  status.textContent = 'Speichere …';
  try {
    const payload = {
      id: state.tourId,
      title: state.caches.length ? (state.caches[0].name + ' …') : 'Tour',
      tour_info: state.tourInfo,
      companions: state.companions,
      context: state.context,
      caches: state.caches,
    };
    const { id } = await api('save', { tour: JSON.stringify(payload) });
    state.tourId = id;
    status.textContent = '✓ Gespeichert (Tour #' + id + ').';
  } catch (ex) {
    status.textContent = 'Fehler: ' + ex.message;
  }
});

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

async function openTour(id) {
  try {
    const { tour } = await api('get_tour', { id: String(id) });
    state.tourId = tour.id;
    state.tourInfo = tour.tour_info || '';
    state.companions = tour.companions || [];
    state.context = tour.context || '';
    state.caches = (tour.caches || []).map((c) => ({
      ...c, notes: c.notes || '', log: c.log || '', done: !!c.done,
    }));
    $('#tour-info').value = state.tourInfo;
    $('#context-text').value = state.context;
    // Start-Boxen ausblenden, Bearbeitungsschritte zeigen
    hide($('#step-setup'));
    const sl = $('#step-load'); if (sl) hide(sl);
    show($('#step-context'));
    show($('#step-caches'));
    show($('#step-save'));
    hide($('#tour-list'));
    hide($('#tour-list-top'));
    selectCache(nextOpenIndex(-1) === -1 ? 0 : nextOpenIndex(-1));
    $('#save-status').textContent = 'Tour #' + id + ' geladen.';
  } catch (ex) {
    $('#save-status').textContent = 'Fehler: ' + ex.message;
  }
}

$('#btn-new')?.addEventListener('click', () => location.reload());

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

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
