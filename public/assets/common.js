'use strict';

/* Gemeinsame Helfer für app.js (angemeldet) und share.js (Gastseite).
   Klassisches Script ohne Build-Schritt: wird vor app.js/share.js geladen. */

/* ---------- DOM + API ---------- */

const $ = (sel) => document.querySelector(sel);

function show(el)  { el.classList.remove('hidden'); }
function hide(el)  { el.classList.add('hidden'); }

// Ziel der API-Aufrufe: app.js → api.php; Gastseite → share.php mit Token (API.extra)
const API = { url: 'api.php', extra: {} };

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
  for (const [k, v] of Object.entries(API.extra)) body.append(k, v);
  const res = await fetch(API.url, { method: 'POST', body });
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

// POST an die API, Antwort als Datei speichern. Fehler kommen als JSON zurück.
// Gibt den Dateinamen (vom Server) zurück.
async function downloadPost(action, data, fallbackName) {
  const body = new FormData();
  body.append('action', action);
  for (const [k, v] of Object.entries({ ...data, ...API.extra })) body.append(k, v);
  const res = await fetch(API.url, { method: 'POST', body });
  // Datei nur bei „attachment"; sonst ist es eine JSON-Fehlerantwort
  const disp = res.headers.get('Content-Disposition') || '';
  if (!res.ok || !disp.includes('attachment')) {
    const j = await res.json().catch(() => ({}));
    throw new Error(j.error || 'HTTP ' + res.status);
  }
  const blob = await res.blob();
  const name = (/filename="([^"]+)"/.exec(disp) || [])[1] || fallbackName;
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 10000);
  return name;
}

/* ---------- Cache-Typ, Log-Status ---------- */

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

function statusBadge(type) {
  const t = type || 'Found it';
  const cls = t === "Didn't find it" ? 's-dnf'
    : (['Found it', 'Attended', 'Webcam Photo Taken'].includes(t) ? 's-found' : 's-other');
  return '<span class="status ' + cls + '">' + escapeHtml(LOG_TYPE_LABELS[t] || t) + '</span>';
}

/* ---------- Datum, Zeit, Text ---------- */

const TZ = 'Europe/Berlin';

// UTC-Zeitpunkt → { date: 'YYYY-MM-DD', time: 'HH:MM' } in Ortszeit
function berlinParts(iso) {
  const p = {};
  new Intl.DateTimeFormat('en-CA', {
    timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(new Date(iso)).forEach(({ type, value }) => { p[type] = value; });
  return { date: p.year + '-' + p.month + '-' + p.day, time: p.hour + ':' + p.minute };
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

// „2026-11-01 16:24:04" (UTC, SQLite) → „01.11.2026 17:24" in Ortszeit
function formatUtcLocal(s) {
  const d = new Date(String(s).replace(' ', 'T') + 'Z');
  if (isNaN(d)) return s || '';
  const p = (n) => String(n).padStart(2, '0');
  return p(d.getDate()) + '.' + p(d.getMonth() + 1) + '.' + d.getFullYear() + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
}
