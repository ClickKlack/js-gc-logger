'use strict';

/* Gastseite für Freigabe-Links (spec-v3) — Begleiter wählt Caches, passt die Vorlage
   an und lädt die Field-Notes-Datei herunter. Nutzt common.js (api, typeIcon, …). */

API.url = 'share.php';
API.extra = { t: new URLSearchParams(location.search).get('t') || '' };

const TEMPLATE_SOURCES = {
  companion: 'deine Vorlage', default: 'Standardvorlage', builtin: 'Standardvorlage', edited: 'nur für diesen Download geändert',
};

let view = null;                 // letzte Antwort von „load"
const manual = new Map();        // von Hand gesetzte Haken (cache-id → bool)
let templateTimer = null;

function allCaches() {
  return view ? view.tours.flatMap((t) => t.caches) : [];
}

function selected() {
  return allCaches().filter((c) => c.selected);
}

async function load(template) {
  const data = template !== undefined ? { template } : {};
  try {
    const res = await api('load', data);
    view = res;
    allCaches().forEach((c) => { if (manual.has(c.id)) c.selected = manual.get(c.id); });
    if (template === undefined) $('#share-template').value = res.template;
    renderHeader();
    renderTemplateInfo();
    renderGroups();
  } catch (ex) {
    $('#share-summary').textContent = 'Fehler: ' + ex.message;
  }
}

function renderHeader() {
  const d = view.days_left;
  $('#share-valid').textContent = 'Link gültig bis ' + formatUtcLocal(view.expires_at)
    + (d <= 1 ? ' (läuft heute/morgen ab)' : ' (noch ' + d + ' Tage)');
}

function renderTemplateInfo() {
  $('#template-source').textContent = TEMPLATE_SOURCES[view.template_source] || '';
  const unk = $('#template-unknown');
  unk.textContent = view.unknown.length
    ? '⚠ Unbekannte Platzhalter: ' + view.unknown.map((u) => '{' + u + '}').join(' ') + ' – Tippfehler?' : '';
  unk.classList.toggle('hidden', !view.unknown.length);
}

function renderGroups() {
  const box = $('#share-groups');
  box.innerHTML = '';
  view.tours.forEach((t) => {
    const sec = document.createElement('div');
    sec.className = 'share-group';
    sec.innerHTML = '<h3>' + escapeHtml(formatDate(t.tour_date)) + (t.title ? ' · ' + escapeHtml(t.title) : '') + '</h3>'
      + '<p class="muted">{mitcacher} = ' + escapeHtml(t.mitcacher || '(niemand)') + '</p>'
      + '<div class="row group-actions">'
        + '<button type="button" class="ghost btn-all">Alle</button>'
        + '<button type="button" class="ghost btn-none">Keine</button></div>';
    const ul = document.createElement('ul');
    ul.className = 'export-list';
    t.caches.forEach((c) => ul.appendChild(cacheItem(c)));
    sec.appendChild(ul);
    const setAll = (on) => {
      t.caches.forEach((c) => { c.selected = on; manual.set(c.id, on); });
      renderGroups();
    };
    sec.querySelector('.btn-all')?.addEventListener('click', () => setAll(true));
    sec.querySelector('.btn-none')?.addEventListener('click', () => setAll(false));
    box.appendChild(sec);
  });
  if (!view.tours.length) box.innerHTML = '<p class="muted">Keine Touren (mehr) in diesem Link.</p>';
  renderSummary();
}

function cacheItem(c) {
  const li = document.createElement('li');
  li.className = 'export-item clickable' + (c.exported_at ? ' exported' : '');
  const t = berlinParts(c.ts);
  const parts = [
    '<span class="' + (c.estimated ? 'estimated' : '') + '">' + (c.estimated ? '~' : '') + '<b>' + escapeHtml(t.time) + '</b> '
      + escapeHtml(formatDate(t.date).slice(0, 6)) + (c.estimated ? ' (geschätzt)' : '') + '</span>',
    statusBadge(c.log_type),
  ];
  if (c.exported_at) parts.push('✓ exportiert am ' + escapeHtml(formatUtcLocal(c.exported_at)));
  if (c.companion_logged) parts.push('👥 schon geloggt');
  li.innerHTML = '<input type="checkbox"' + (c.selected ? ' checked' : '')
      + ' aria-label="' + escapeHtml(c.name) + ' exportieren">'
    + '<span class="x-main">' + typeIcon(c.type) + '<b>' + escapeHtml(c.name) + '</b> <span class="muted">' + escapeHtml(c.code) + '</span>'
    + '<span class="x-sub">' + parts.map((p) => '<span class="part">' + p + '</span>').join(' · ') + '</span></span>';
  const cb = li.querySelector('input');
  cb.addEventListener('change', () => {
    c.selected = cb.checked;
    manual.set(c.id, c.selected);
    renderSummary();
  });
  // ganze Karte anklickbar, nicht nur die Checkbox
  li.addEventListener('click', (e) => {
    if (e.target === cb) return;
    cb.checked = !cb.checked;
    cb.dispatchEvent(new Event('change'));
  });
  return li;
}

function renderSummary() {
  const all = allCaches();
  const sel = selected();
  const done = all.filter((c) => c.exported_at).length;
  $('#share-summary').textContent = sel.length + ' von ' + all.length + ' Caches ausgewählt'
    + (done ? ' · ' + done + ' schon exportiert' : '');
  const btn = $('#btn-download');
  btn.disabled = sel.length === 0;
  btn.textContent = '⬇ Field Notes herunterladen' + (sel.length ? ' (' + sel.length + ')' : '');
  // Vorschau: wie in der Datei (neuester zuerst), die ersten zwei
  const first = [...sel].sort((a, b) => b.ts.localeCompare(a.ts)).slice(0, 2);
  $('#share-preview').textContent = first.length
    ? first.map((c) => c.code + ' · ' + c.name + '\n' + c.text).join('\n\n———\n\n') : '(nichts ausgewählt)';
}

$('#share-template').addEventListener('input', () => {
  clearTimeout(templateTimer);
  $('#template-status').textContent = '';
  templateTimer = setTimeout(() => load($('#share-template').value), 400);
});

document.querySelectorAll('.ph-chip').forEach((b) => b.addEventListener('click', () => {
  const ta = $('#share-template');
  const pos = ta.selectionStart ?? ta.value.length;
  ta.value = ta.value.slice(0, pos) + b.dataset.ph + ta.value.slice(ta.selectionEnd ?? pos);
  ta.focus();
  ta.selectionStart = ta.selectionEnd = pos + b.dataset.ph.length;
  ta.dispatchEvent(new Event('input'));
}));

$('#btn-template-save').addEventListener('click', async () => {
  const status = $('#template-status');
  try {
    await api('save_template', { template: $('#share-template').value });
    status.textContent = '✓ Als deine Vorlage gespeichert.';
    $('#template-source').textContent = TEMPLATE_SOURCES.companion;
    if (view) view.template_source = 'companion';
  } catch (ex) {
    status.textContent = 'Fehler: ' + ex.message;
  }
});

$('#btn-download').addEventListener('click', async () => {
  const ids = selected().map((c) => c.id);
  const status = $('#download-status');
  const btn = $('#btn-download');
  btn.disabled = true;
  status.textContent = 'Erzeuge Datei …';
  try {
    const name = await downloadPost('download', { ids: JSON.stringify(ids), template: $('#share-template').value }, 'geocache_visits.txt');
    status.textContent = '✓ ' + ids.length + ' Einträge in ' + name + '. Jetzt bei geocaching.com unter Drafts → Upload hochladen.';
    manual.clear();
    await load($('#share-template').value);
  } catch (ex) {
    status.textContent = 'Fehler: ' + ex.message;
    renderSummary();
  }
});

load();
