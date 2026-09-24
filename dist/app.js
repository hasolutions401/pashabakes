/* Pashabakess — menu, box builder, checkout and enquiries.
   The live menu, prices and pickup times come from api/menu.php (managed in /admin).
   If the server can't be reached, the built-in menu below is shown and online
   ordering is paused with a clear message. */

// Keep in step with the static cards in index.html and menu.html (shown without JavaScript).
const FALLBACK_COOKIES = [
  {id:1,name:'Chocolate Chunk',type:'signature',desc:'Brown butter base with semi-sweet chocolate chips, dark chocolate chunks, topped with sea salt flakes.',img:'https://images.unsplash.com/photo-1673551490160-3f2e712b9373?auto=format&fit=crop&w=900&q=80'},
  {id:2,name:'Biscoff',type:'signature',desc:'Brown butter base with Biscoff cookie pieces, white chocolate chips, drizzled with Biscoff spread.',img:'https://assets-eu-01.kc-usercontent.com/21d2ecef-fb9b-01b1-9022-cf60b52c2c77/d79e9c4f-6fbe-4c62-8ca7-fe295e3169b3/Biscoff-Cookies-WEB-RES-1.jpg?auto=format&lossless=1&q=85&w=900'},
  {id:3,name:'Chocolate Sea Salt Toffee',type:'signature',desc:'Rich brown butter cookie with toffee bits, semi-sweet chocolate chips, topped with sea salt flakes.',img:'https://scientificallysweet.com/wp-content/uploads/2022/09/IMG_3198-salted-toffee-chocolate-chip-cookies-feature2.jpg'},
  {id:4,name:'Red Velvet',type:'signature',desc:'Red cookie base with cocoa powder, white chocolate chips and white chocolate drizzle.',img:'https://sallysbakingaddiction.com/wp-content/uploads/2013/12/red-velvet-white-chocolate-chip-cookies-2.jpg'},
  {id:5,name:'Pumpkin Chocolate Chip',type:'seasonal',desc:'Brown butter base with pumpkin purée, cinnamon, and chocolate chips.',img:'https://sallysbakingaddiction.com/wp-content/uploads/2013/09/chewy-pumpkin-chocolate-chip-cookies-3.jpg'},
  {id:6,name:'Maple Pecan',type:'seasonal',desc:'Brown butter base with cinnamon, maple syrup, pecans.',img:'https://confessionsofabakingqueen.com/wp-content/uploads/2020/11/plate-of-maple-pecan-cookies-1-of-1-1024x1536-1.jpg'}
];

// Credits for the temporary reference photos (not needed for Pasha's own uploads).
const PHOTO_CREDITS = {
  'images.unsplash.com/photo-1673551490160': ['American Heritage Chocolate / Unsplash', 'https://unsplash.com/photos/chocolate-chip-cookies-and-a-glass-of-milk-tqKB97R-eOw'],
  'Biscoff-Cookies-WEB-RES-1': ['Silver Spoon', 'https://www.silverspoon.co.uk/recipes/biscoff-cookies'],
  'salted-toffee-chocolate-chip-cookies': ['Scientifically Sweet', 'https://scientificallysweet.com/salted-toffee-chocolate-chip-cookies/'],
  'red-velvet-white-chocolate-chip-cookies': ['Sally’s Baking', 'https://sallysbakingaddiction.com/red-velvet-chocolate-chip-cookies/'],
  'chewy-pumpkin-chocolate-chip-cookies': ['Sally’s Baking', 'https://sallysbakingaddiction.com/2013/09/04/pumpkin-chocolate-chip-cookies/'],
  'plate-of-maple-pecan-cookies': ['Confessions of a Baking Queen', 'https://confessionsofabakingqueen.com/maple-pecan-cookies/']
};

const $ = s => document.querySelector(s);
const $$ = s => Array.from(document.querySelectorAll(s));
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const icon = (id, cls = '') => `<svg class="icon ${cls}" aria-hidden="true"><use href="#i-${id}"/></svg>`;
const money = cents => `$${cents % 100 === 0 ? cents / 100 : (cents / 100).toFixed(2)}`;
const store = {
  get(key) { try { return JSON.parse(sessionStorage.getItem(key)); } catch { return null; } },
  set(key, value) { try { sessionStorage.setItem(key, JSON.stringify(value)); } catch {} },
  remove(key) { try { sessionStorage.removeItem(key); } catch {} }
};

let cookies = FALLBACK_COOKIES.slice();
let pricesCents = {4:1400, 6:2000, 12:3800, 24:7600, 36:11400};
let settings = null;          // from api/menu.php; null = server not reachable (yet)
let box = 4;
const qty = {};               // cookie id -> quantity
let submitting = false;
const clientToken = (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`).replace(/[^A-Za-z0-9-]/g, '') + 'x';

const total = () => cookies.reduce((sum, c) => sum + (qty[c.id] || 0), 0);
const cookieById = id => cookies.find(c => c.id === Number(id));
const cardImg = c => c.imgMd || c.img || 'logo-192.jpg';
const thumbImg = c => c.imgSm || c.img || 'logo-192.jpg';

/* ——— Seamless announcement strips, with keyboard/touch pause controls ——— */
$$('.announcement, .ribbon').forEach(strip => {
  const label = strip.classList.contains('announcement') ? 'announcements' : 'cookie ribbon';
  strip.removeAttribute('aria-hidden');
  const track = document.createElement('div');
  track.className = 'marquee-track';
  const group = document.createElement('div');
  group.className = 'marquee-group';
  while (strip.firstChild) group.append(strip.firstChild);
  const separator = document.createElement('span');
  separator.textContent = '✦';
  separator.setAttribute('aria-hidden', 'true');
  group.append(separator);
  const duplicate = group.cloneNode(true);
  duplicate.setAttribute('aria-hidden', 'true');
  track.append(group, duplicate);
  const pause = document.createElement('button');
  pause.type = 'button';
  pause.className = 'marquee-pause';
  pause.textContent = 'Ⅱ';
  pause.setAttribute('aria-label', `Pause ${label}`);
  pause.setAttribute('aria-pressed', 'false');
  pause.addEventListener('click', () => {
    const paused = strip.classList.toggle('is-paused');
    pause.textContent = paused ? '▶' : 'Ⅱ';
    pause.setAttribute('aria-pressed', String(paused));
    pause.setAttribute('aria-label', `${paused ? 'Resume' : 'Pause'} ${label}`);
  });
  strip.classList.add('marquee');
  strip.append(track, pause);
});

/* ——— Dates (pickup rules are in Eastern time) ——— */
const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const isoDate = v => /^\d{4}-\d{2}-\d{2}$/.test(v || '') ? v : '';
function nyToday() {
  const parts = new Intl.DateTimeFormat('en-CA', {timeZone:'America/New_York', year:'numeric', month:'2-digit', day:'2-digit'}).formatToParts(new Date());
  const v = t => parts.find(p => p.type === t).value;
  return `${v('year')}-${v('month')}-${v('day')}`;
}
function addDays(iso, days) {
  const d = new Date(`${iso}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}
const prettyDate = iso => new Date(`${iso}T12:00:00Z`).toLocaleDateString('en-US', {weekday:'long', month:'long', day:'numeric', timeZone:'UTC'});
const shortDate = iso => new Date(`${iso}T12:00:00Z`).toLocaleDateString('en-US', {month:'short', day:'numeric', timeZone:'UTC'});
function minimumDate() { return settings?.minDate || addDays(nyToday(), 7); }
function maximumDate() { return settings?.maxDate || addDays(nyToday(), 90); }

/* ——— Flavor pickup windows (monthly specials) ——— */
const availableOn = (c, iso) => (!c.from || iso >= c.from) && (!c.until || iso <= c.until);
// Month name when a flavor's window is exactly one calendar month, e.g. "October".
function windowMonth(c) {
  if (!c.from || !c.until) return '';
  const [fy, fm, fd] = c.from.split('-').map(Number);
  const [uy, um, ud] = c.until.split('-').map(Number);
  const lastDay = new Date(Date.UTC(uy, um, 0)).getUTCDate();
  return fy === uy && fm === um && fd === 1 && ud === lastDay ? MONTHS[fm - 1] : '';
}
function windowText(c) {
  const month = windowMonth(c);
  if (month) return `${month} pickups only`;
  if (c.from && c.until) return `Pickups ${shortDate(c.from)} – ${shortDate(c.until)}`;
  if (c.from) return `Pickups from ${shortDate(c.from)}`;
  if (c.until) return `Pickups until ${shortDate(c.until)}`;
  return '';
}
// The month shared by every seasonal flavor, or '' (then pages say "seasonal").
function seasonMonth() {
  const months = [...new Set(cookies.filter(c => c.type === 'seasonal').map(windowMonth))];
  return months.length === 1 ? months[0] : '';
}
function renderSeason() {
  const month = seasonMonth();
  $$('[data-season]').forEach(el => {
    if (!el.dataset.fallback) el.dataset.fallback = el.textContent;
    const fallback = el.dataset.fallback;
    el.textContent = month ? (fallback === fallback.toUpperCase() ? month.toUpperCase() : month) : fallback;
  });
}

/* ——— Rendering ——— */
function renderMenu() {
  const grid = $('#cookie-grid');
  if (!grid) return;
  let list = cookies;
  if (grid.classList.contains('home-picks')) {
    // Home page sampler: two signatures and one monthly special.
    const sig = cookies.filter(c => c.type !== 'seasonal').slice(0, 2);
    const sea = cookies.filter(c => c.type === 'seasonal').slice(0, 1);
    list = [...sig, ...sea].length ? [...sig, ...sea] : cookies.slice(0, 3);
  }
  const month = seasonMonth();
  grid.innerHTML = list.map(c => {
    const seasonal = c.type === 'seasonal';
    const own = c.img.startsWith('uploads/');
    return `
    <article class="cookie-card" data-type="${seasonal ? 'seasonal' : 'signature'}">
      <div class="cookie-image">
        <img src="${esc(cardImg(c))}" alt="${own ? '' : 'Illustrative photograph of '}${esc(c.name)} cookies" width="900" height="760" loading="lazy" decoding="async">
        <span class="cookie-tag ${seasonal ? 'seasonal' : 'signature'}">${seasonal ? `${esc((windowMonth(c) || month || 'Seasonal').toUpperCase())} SPECIAL` : 'SIGNATURE'}</span>
      </div>
      <h3>${esc(c.name)}</h3>
      <p>${esc(c.desc)}</p>
      <div class="cookie-bottom">
        <span>${esc(windowText(c) || (seasonal ? 'A seasonal favorite' : 'A forever favorite'))}</span>
        <a class="add-cookie" href="order.html?flavor=${c.id}" data-add="${c.id}" aria-label="Add ${esc(c.name)} to your box">
          ${icon('plus')}<span class="add-label">Add</span>
        </a>
      </div>
    </article>`;
  }).join('');
  const counts = {all: cookies.length, signature: cookies.filter(c => c.type !== 'seasonal').length, seasonal: cookies.filter(c => c.type === 'seasonal').length};
  $$('[data-filter]').forEach(b => { const n = b.querySelector('.filter-count'); if (n) n.textContent = counts[b.dataset.filter] ?? ''; });
  $$('[data-flavor-count]').forEach(el => { el.textContent = cookies.length; });
  applyFilter(false);
}

function renderFlavorChoices() {
  const wrap = $('#flavor-choices');
  if (!wrap) return;
  wrap.innerHTML = cookies.map(c => `
    <div class="flavor-choice" data-flavor="${c.id}">
      <img src="${esc(thumbImg(c))}" alt="" width="56" height="56" loading="lazy" decoding="async">
      <span class="flavor-name" id="flavor-name-${c.id}">${esc(c.name)}<small id="flavor-note-${c.id}"></small></span>
      <div class="stepper" role="group" aria-labelledby="flavor-name-${c.id}">
        <button type="button" class="step" id="step-minus-${c.id}" data-step="-1" data-id="${c.id}" aria-label="One fewer ${esc(c.name)}">${icon('minus')}</button>
        <output id="qty-${c.id}" class="qty" aria-label="${esc(c.name)} quantity">0</output>
        <button type="button" class="step" id="step-plus-${c.id}" data-step="1" data-id="${c.id}" aria-label="One more ${esc(c.name)}">${icon('plus')}</button>
      </div>
    </div>`).join('');
  const hint = $('.flavor-dates-hint');
  if (hint) hint.hidden = !cookies.some(c => c.from || c.until);
}

// Credits for any borrowed photo on the page (menu cards, hero, about photo).
function renderCredits() {
  const el = $('#photo-credits');
  if (!el) return;
  const seen = new Set();
  const sources = [
    ...cookies.map(c => [c.img, c.name]),
    ...$$('img[data-credit-label]').map(img => [img.currentSrc || img.src, img.dataset.creditLabel])
  ];
  el.innerHTML = sources.map(([src, label]) => {
    const key = Object.keys(PHOTO_CREDITS).find(k => String(src).includes(k));
    if (!key || seen.has(key + label)) return '';
    seen.add(key + label);
    const [credit, source] = PHOTO_CREDITS[key];
    return `<a href="${source}" target="_blank" rel="noreferrer">${esc(label)} — ${esc(credit)} ${icon('external')}<span class="sr-only">(opens in a new tab)</span></a>`;
  }).join('') || '<p>All photos are Pashabakess’s own.</p>';
}

function renderPrices() {
  $$('[data-price-for]').forEach(el => {
    const cents = pricesCents[el.dataset.priceFor];
    if (cents) el.textContent = money(cents);
    const option = el.closest('.size-option');
    if (option) option.hidden = !cents;
  });
}

/* ——— Box state ——— */
const chosenDate = () => isoDate($('#pickup-date')?.value);

function update() {
  $$('[data-add]').forEach(btn => {
    const c = cookieById(btn.dataset.add);
    const q = qty[btn.dataset.add] || 0;
    btn.classList.toggle('in-box', q > 0);
    btn.querySelector('.add-label').textContent = q > 0 ? `${q} in box` : 'Add';
    if (c) btn.setAttribute('aria-label', q > 0 ? `Add another ${c.name} (${q} in your box)` : `Add ${c.name} to your box`);
  });
  if (!$('#order-form')) return;

  store.set('pashabakess-box', {size: box, qty});
  const count = total();
  const full = count >= box;
  const price = pricesCents[box] || 0;
  const date = chosenDate();

  $('#selection-count').textContent = `${count} of ${box} cookies chosen`;
  const progress = $('.selection-progress');
  progress.setAttribute('aria-valuemax', box);
  progress.setAttribute('aria-valuenow', Math.min(count, box));
  const pct = `${Math.min(count / box, 1) * 100}%`;
  $('#selection-progress-fill').style.width = pct;
  $('#bar-fill').style.width = pct;
  $('#bar-count').textContent = `${count} / ${box}`;
  $('#bar-price').textContent = money(price);
  $('.box-bar').classList.toggle('is-complete', count === box);
  $('.selection-status').classList.toggle('is-complete', count === box);
  $('#clear-box').disabled = count === 0;
  $('#summary-total').textContent = money(price);
  $('#summary-size').textContent = `${box} cookies · Mix & match`;
  $('#pay-total').textContent = money(price);
  $('#pay-total-size').textContent = `${box} cookies`;
  $$('[data-pay-amount]').forEach(el => { el.textContent = money(price); });

  cookies.forEach(c => {
    const q = qty[c.id] || 0;
    const out = $(`#qty-${c.id}`);
    if (!out) return;
    const okForDate = !date || availableOn(c, date);
    out.textContent = q;
    $(`#step-minus-${c.id}`).disabled = q === 0;
    $(`#step-plus-${c.id}`).disabled = full || !okForDate;
    const row = $(`.flavor-choice[data-flavor="${c.id}"]`);
    row.classList.toggle('has-qty', q > 0);
    row.classList.toggle('is-unavailable', !okForDate);
    const label = c.type === 'seasonal' ? 'Monthly special' : 'Signature';
    const when = windowText(c);
    $(`#flavor-note-${c.id}`).textContent = okForDate
      ? [label, when].filter(Boolean).join(' · ')
      : `Not available for ${shortDate(date)} · ${when}`;
  });

  const items = $('#summary-items');
  items.replaceChildren();
  cookies.forEach(c => {
    if (!qty[c.id]) return;
    const row = document.createElement('div');
    row.className = 'summary-line';
    const name = document.createElement('span');
    name.textContent = c.name;
    const q = document.createElement('strong');
    q.textContent = `× ${qty[c.id]}`;
    row.append(name, q);
    items.append(row);
  });
  if (!count) items.innerHTML = '<p>Your favorites go here.<br>Pick a flavor to get started.</p>';

  $('#flavor-error').textContent = count > box
    ? `Your box has ${count} cookies. Remove ${count - box} to fit your selected size.`
    : '';
}

// Takes flavors that can't be picked up on the chosen date out of the box, and says so.
function enforceAvailability() {
  const date = chosenDate();
  const notice = $('#pickup-notice');
  if (!notice) return;
  const removed = date ? cookies.filter(c => qty[c.id] > 0 && !availableOn(c, date)) : [];
  removed.forEach(c => { delete qty[c.id]; });
  if (removed.length) {
    const names = removed.map(c => c.name).join(' and ');
    const one = removed.length === 1;
    notice.innerHTML = `${esc(names)} ${one ? 'isn’t' : 'aren’t'} available for pickup on ${esc(prettyDate(date))} (${esc(removed.map(windowText).join('; '))}), so ${one ? 'it was' : 'they were'} taken out of your box. <a href="#flavor-choices">Choose ${one ? 'another flavor' : 'other flavors'}</a>.`;
    notice.hidden = false;
  } else if (!date || notice.dataset.date !== date) {
    notice.hidden = true;
    notice.textContent = '';
  }
  notice.dataset.date = date;
  update();
}

document.addEventListener('click', e => {
  const step = e.target.closest('.step');
  if (step) {
    const id = Number(step.dataset.id);
    const delta = Number(step.dataset.step);
    const next = (qty[id] || 0) + delta;
    if (next < 0 || (delta > 0 && total() >= box)) return;
    qty[id] = next;
    update();
    if (step.disabled) step.closest('.stepper').querySelector('.step:not(:disabled)')?.focus();
    return;
  }
  const add = e.target.closest('[data-add]');
  if (add && $('#order-form')) {
    e.preventDefault();
    const id = Number(add.dataset.add);
    if (total() < box) {
      qty[id] = (qty[id] || 0) + 1;
      update();
      boxFeedback(`${cookieById(id)?.name || 'Cookie'} added · ${total()} of ${box} cookies`);
    } else {
      boxFeedback('Your box is full. Choose a bigger box or change flavors.', false);
    }
  }
  // Elsewhere the Add link opens the order page with that flavor in the box.
});

$$('input[name="box"]').forEach(r => r.addEventListener('change', () => {
  box = Number(r.value);
  update();
}));

$('#clear-box')?.addEventListener('click', () => {
  Object.keys(qty).forEach(k => delete qty[k]);
  update();
  $('.size-option input:checked')?.focus();
});

/* ——— Toast: short confirmation, auto-dismisses, pauses while hovered/focused ——— */
const toast = $('#box-feedback');
let toastTimer;
function hideToast() { if (toast) toast.hidden = true; }
function scheduleToast() { clearTimeout(toastTimer); toastTimer = setTimeout(hideToast, 4500); }
function boxFeedback(message, ok = true) {
  if (!toast) return;
  toast.querySelector('.toast-text').textContent = message;
  toast.classList.toggle('is-warning', !ok);
  toast.hidden = false;
  scheduleToast();
}
if (toast) {
  toast.addEventListener('mouseenter', () => clearTimeout(toastTimer));
  toast.addEventListener('mouseleave', scheduleToast);
  toast.addEventListener('focusin', () => clearTimeout(toastTimer));
  toast.addEventListener('focusout', scheduleToast);
  toast.querySelector('.toast-close').addEventListener('click', hideToast);
  toast.querySelector('a').addEventListener('click', hideToast);
}

/* ——— Menu filters ——— */
let activeFilter = 'all';
function applyFilter(announce) {
  let shown = 0;
  $$('#cookie-grid .cookie-card').forEach(c => {
    c.hidden = activeFilter !== 'all' && c.dataset.type !== activeFilter;
    if (!c.hidden) shown++;
  });
  const status = $('#filter-status');
  // Announce every filter change, including "All cookies".
  if (status && announce) status.textContent = `Showing ${shown} ${shown === 1 ? 'cookie' : 'cookies'}`;
}
$$('[data-filter]').forEach(b => b.addEventListener('click', () => {
  activeFilter = b.dataset.filter;
  $$('[data-filter]').forEach(x => {
    x.classList.toggle('active', x === b);
    x.setAttribute('aria-pressed', String(x === b));
  });
  applyFilter(true);
}));

/* ——— Header: sticky shadow + accessible mobile menu ——— */
const header = $('#site-header');
const navToggle = $('#nav-toggle');
const nav = $('#navigation');
function setNav(open, { focusToggle = false } = {}) {
  nav.classList.toggle('open', open);
  header.classList.toggle('nav-open', open);
  navToggle.setAttribute('aria-expanded', String(open));
  $('#nav-toggle-label').textContent = open ? 'Close menu' : 'Open menu';
  if (open) nav.querySelector('a')?.focus();
  else if (focusToggle) navToggle.focus();
}
navToggle.addEventListener('click', () => setNav(navToggle.getAttribute('aria-expanded') !== 'true'));
nav.querySelectorAll('a').forEach(a => a.addEventListener('click', () => setNav(false)));
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && nav.classList.contains('open')) setNav(false, { focusToggle: true });
});
document.addEventListener('click', e => {
  if (nav.classList.contains('open') && !header.contains(e.target)) setNav(false);
});
const onScroll = () => header.classList.toggle('is-scrolled', window.scrollY > 10);
window.addEventListener('scroll', onScroll, { passive: true });
onScroll();

// Links that point at a closed FAQ item open it
$$('[data-open-details]').forEach(a => a.addEventListener('click', () => {
  const url = new URL(a.href);
  const target = url.pathname === location.pathname && url.hash ? document.getElementById(url.hash.slice(1)) : null;
  if (target?.tagName === 'DETAILS') target.open = true;
}));

/* ——— Pickup date & time ——— */
function refreshDates() {
  const input = $('#pickup-date');
  if (!input) return;
  input.min = minimumDate();
  input.max = maximumDate();
  $('#pickup-date-hint').textContent = `Earliest date: ${prettyDate(minimumDate())}.`;
}
function renderSlots(slots) {
  const select = $('#pickup-time');
  if (!select) return;
  const current = select.value || draft.time;
  select.innerHTML = '<option value="">Choose a time</option>' + slots.map(s => `<option>${esc(s)}</option>`).join('');
  if (slots.includes(current)) select.value = current;
}
$('#pickup-date')?.addEventListener('focus', refreshDates);
$('#pickup-date')?.addEventListener('change', enforceAvailability);

/* ——— Payment method (Venmo / Cash App) ——— */
function payInfo(method) {
  const venmo = settings?.venmo || {handle: 'Palosha-Rashid', url: 'https://venmo.com/u/Palosha-Rashid'};
  const cashapp = settings?.cashapp || {handle: 'Pashabakess', url: 'https://cash.app/$Pashabakess'};
  return method === 'cashapp'
    ? {app: 'Cash App', handle: `$${cashapp.handle}`, url: cashapp.url, raw: cashapp.handle}
    : {app: 'Venmo', handle: `@${venmo.handle}`, url: venmo.url, raw: venmo.handle};
}
function updatePayment() {
  const method = $('input[name="payment"]:checked')?.value || 'venmo';
  const info = payInfo(method);
  $('#payer-label').textContent = `Name or username you’ll pay from on ${info.app}`;
  $('#o-payer').placeholder = method === 'cashapp' ? '$your-cashtag' : '@your-username';
  $('#pay-method-name').textContent = info.app;
  $$('[data-handle="venmo"]').forEach(el => { el.textContent = payInfo('venmo').raw; });
  $$('[data-handle="cashapp"]').forEach(el => { el.textContent = payInfo('cashapp').raw; });
}
$$('input[name="payment"]').forEach(r => r.addEventListener('change', updatePayment));

/* ——— Form validation: inline errors on blur, summary on submit ——— */
function fieldMessage(el) {
  if (el.type === 'checkbox') return el.checked ? '' : el.dataset.error;
  const value = el.value.trim();
  if (el.required && !value) return el.dataset.error || 'Please complete this field.';
  if (!value) return '';
  if (el.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) return 'Please enter a valid email address, like name@example.com.';
  if (el.type === 'tel' && value.replace(/\D/g, '').length < 10) return 'Please enter a phone number with area code.';
  if (el.id === 'pickup-date') {
    if (value < minimumDate()) return `Orders need one week’s notice. The earliest pickup date is ${prettyDate(minimumDate())}. For anything sooner, please email pashabakess@gmail.com.`;
    if (value > maximumDate()) return `Please choose a date before ${prettyDate(maximumDate())}.`;
    if (settings?.unavailableDates?.includes(value)) return 'Pasha isn’t available for pickups on that date. Please choose another day.';
  } else if (el.type === 'date' && el.min && value < el.min) {
    return 'That date has already passed. Please check the date.';
  }
  return '';
}
function showFieldError(el, message) {
  const err = document.getElementById(`${el.id}-error`);
  if (message) el.setAttribute('aria-invalid', 'true');
  else el.removeAttribute('aria-invalid');
  if (err) { err.textContent = message; err.hidden = !message; }
}
function showSummary(form, problems) {
  const summary = form.querySelector('.error-summary');
  if (!problems.length) { summary.hidden = true; return; }
  const list = summary.querySelector('ul');
  list.replaceChildren(...problems.map(p => {
    const li = document.createElement('li');
    li.textContent = p.msg;
    if (p.id) {
      const a = document.createElement('a');
      a.href = `#${p.id}`;
      a.textContent = p.msg;
      a.addEventListener('click', e => { e.preventDefault(); document.getElementById(p.id)?.focus(); });
      li.replaceChildren(a);
    } else if (p.href) {
      const a = document.createElement('a');
      a.href = p.href;
      a.textContent = p.linkText;
      li.append(' ', a);
    }
    return li;
  }));
  summary.hidden = false;
  summary.focus();
}
function validateForm(form, extra = []) {
  const problems = [...extra];
  form.querySelectorAll('[data-error]').forEach(el => {
    if (el.disabled) return;
    const msg = fieldMessage(el);
    showFieldError(el, msg);
    if (msg) problems.push({id: el.id, msg});
  });
  showSummary(form, problems);
  return !problems.length;
}
$$('form [data-error]').forEach(el => {
  el.addEventListener('blur', () => { if (el.value || el.getAttribute('aria-invalid')) showFieldError(el, fieldMessage(el)); });
  el.addEventListener(el.type === 'checkbox' || el.tagName === 'SELECT' ? 'change' : 'input', () => {
    if (el.getAttribute('aria-invalid')) showFieldError(el, fieldMessage(el));
  });
});

// Spam trap: added by script so it never shows up in the page text. People never fill it in.
function addHoneypot(form) {
  const wrap = document.createElement('div');
  wrap.className = 'hp-field';
  wrap.setAttribute('aria-hidden', 'true');
  wrap.innerHTML = '<label>Leave this empty <input name="website" tabindex="-1" autocomplete="off"></label>';
  form.prepend(wrap);
}

/* ——— Dialogs ——— */
$$('.dialog-close').forEach(b => b.addEventListener('click', () => b.closest('dialog').close()));
$$('dialog').forEach(d => d.addEventListener('click', e => {
  if (e.target !== d) return;
  const r = d.getBoundingClientRect();
  if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) d.close();
}));
$('#credits-open')?.addEventListener('click', () => { renderCredits(); $('#credits-dialog').showModal(); });

/* ——— Checkout draft: kept in this browser tab so a refresh doesn't lose it ——— */
const DRAFT_KEY = 'pashabakess-checkout';
const DRAFT_FIELDS = ['name', 'email', 'phone', 'occasion', 'date', 'time', 'payment', 'payer_ref', 'notes'];
let draft = {};
function saveDraft() {
  const form = $('#order-form');
  if (!form || form.hidden) return;
  const d = new FormData(form);
  draft = {};
  DRAFT_FIELDS.forEach(k => { const v = d.get(k); if (v) draft[k] = String(v); });
  store.set(DRAFT_KEY, draft);
}
function applyDraft(names = DRAFT_FIELDS) {
  const form = $('#order-form');
  names.forEach(k => {
    const v = draft[k];
    const el = form.elements[k];
    if (typeof v !== 'string' || !el) return;
    if (el instanceof RadioNodeList) {
      const radio = [...el].find(r => r.value === v);
      if (radio) radio.checked = true;
    } else {
      el.value = v;
      if (el.value !== v && el.tagName === 'SELECT') el.selectedIndex = 0;
    }
  });
}

/* ——— Checkout: place the order, then show how to pay ——— */
const LAST_ORDER_KEY = 'pashabakess-last-order';
const SERVER_FIELDS = {
  box_size: 'box-sizes', name: 'o-name', email: 'o-email', phone: 'o-phone',
  pickup_date: 'pickup-date', pickup_slot: 'pickup-time', payment_method: 'pay-methods', payer_ref: 'o-payer', agree: 'o-allergy'
};

function setSubmitting(on) {
  submitting = on;
  const btn = $('#place-order');
  btn.disabled = on || (settings && !settings.accepting);
  btn.setAttribute('aria-busy', String(on));
  $('#place-order-label').innerHTML = on ? 'Placing your order…' : `Place order · <span data-pay-amount>${money(pricesCents[box] || 0)}</span>`;
}

function showSuccess(order) {
  const method = order.method === 'cashapp' ? 'cashapp' : 'venmo';
  const info = payInfo(method);
  const payTo = order.payTo || info.handle;
  $('#success-code').textContent = order.code;
  $$('[data-order-code]').forEach(el => { el.textContent = order.code; });
  $('#success-amount').textContent = order.total;
  $('#success-to').textContent = payTo;
  $$('[data-success-app]').forEach(el => { el.textContent = order.payment || info.app; });
  $('#success-open').href = order.payUrl || info.url;
  $('#success-qr').src = `api/qr.php?m=${method}`;
  $('#success-qr').alt = `QR code for ${order.payment || info.app} ${payTo}`;
  $('#success-email').textContent = order.email;
  const lines = order.items.map(i => `<li><span>${esc(i.name)}</span><strong>× ${i.qty}</strong></li>`).join('');
  $('#success-summary').innerHTML = `<ul>${lines}</ul>
    <p class="success-total"><span>${order.boxSize} cookies</span><strong>${esc(order.total)}</strong></p>
    <p class="success-pickup"><strong>Pickup:</strong> ${esc(order.pickupDate)}, ${esc(order.pickupSlot)} (Eastern)</p>`;
  $('#order-form').hidden = true;
  $('.order-summary').hidden = true;
  $('.order-steps').hidden = true;
  $('.order-callout').hidden = true;
  $('#order-unavailable').hidden = true;
  $('#order-success').hidden = false;
  $('#copy-code').onclick = async () => {
    try {
      await navigator.clipboard.writeText(order.code);
      $('#copy-status').textContent = `Copied ${order.code}.`;
    } catch {
      $('#copy-status').textContent = `Copy unavailable — type ${order.code} in the payment note.`;
    }
  };
}

$('#new-order')?.addEventListener('click', () => { store.remove(LAST_ORDER_KEY); });

$('#order-form')?.addEventListener('submit', async e => {
  e.preventDefault();
  if (submitting) return;
  const form = e.target;
  const count = total();
  const extra = [];
  if (count !== box) {
    const msg = count < box
      ? `Please choose ${box - count} more cookie${box - count === 1 ? '' : 's'} to fill your box of ${box}.`
      : `Please remove ${count - box} cookie${count - box === 1 ? '' : 's'} to fit your box of ${box}.`;
    $('#flavor-error').textContent = msg;
    const first = cookies.find(c => !$(`#step-plus-${c.id}`)?.disabled) || cookies[0];
    extra.push({id: count < box ? `step-plus-${first?.id}` : `step-minus-${cookies.find(c => qty[c.id] > 0)?.id}`, msg});
  }
  if (!validateForm(form, extra)) return;
  if (!settings) {
    showSummary(form, [{msg: 'Online ordering is temporarily unavailable. Please email pashabakess@gmail.com with your order.'}]);
    return;
  }

  const d = new FormData(form);
  const payload = {
    client_token: clientToken,
    website: d.get('website') || '',
    name: d.get('name'), email: d.get('email'), phone: d.get('phone'), occasion: d.get('occasion') || '',
    notes: d.get('notes') || '', box_size: box,
    items: cookies.filter(c => qty[c.id] > 0).map(c => ({id: c.id, qty: qty[c.id]})),
    pickup_date: d.get('date'), pickup_slot: d.get('time'),
    payment_method: d.get('payment'), payer_ref: d.get('payer_ref') || '', agree: !!d.get('agree'),
    expected_total_cents: pricesCents[box]
  };

  setSubmitting(true);
  try {
    const res = await fetch('api/order.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)});
    let data = null;
    try { data = await res.json(); } catch {}
    if (res.ok && data?.ok) {
      // The order is saved: forget the draft, but keep the payment screen if the page reloads.
      store.remove(DRAFT_KEY);
      store.remove('pashabakess-box');
      store.set(LAST_ORDER_KEY, {order: data.order, savedAt: Date.now()});
      showSuccess(data.order);
      window.scrollTo({top: $('#order-success').getBoundingClientRect().top + window.scrollY - 120, behavior: 'smooth'});
      $('#order-success').focus({preventScroll: true});
      return;
    }
    const problems = [];
    if (data?.errors) {
      Object.entries(data.errors).forEach(([key, msg]) => {
        let id = SERVER_FIELDS[key];
        if (key === 'items') { id = `step-plus-${cookies[0]?.id}`; $('#flavor-error').textContent = msg; }
        const el = id && document.getElementById(id);
        if (el && el.matches('input, select, textarea')) showFieldError(el, msg);
        problems.push({id: el ? id : null, msg});
      });
      if (data.errors.box_size || data.errors.items) loadMenu(); // prices or flavors changed — refresh them
    } else {
      problems.push({msg: data?.message || 'Sorry, something went wrong and your order was not placed. Please try again.'});
    }
    showSummary(form, problems);
  } catch {
    showSummary(form, [{msg: 'We couldn’t reach the server, so your order was not placed. Please check your connection and try again, or email pashabakess@gmail.com.'}]);
  } finally {
    setSubmitting(false);
  }
});

/* ——— Enquiries (Contact & Celebrations pages) — sent to Pasha through the server ——— */
const ENQUIRY_FIELDS = {name: 'e-name', email: 'e-email', date: 'e-date', message: 'e-message'};
function setupEnquiry(form) {
  const type = form.elements.type;
  const eventFields = form.querySelector('.event-fields');
  const preset = new URLSearchParams(location.search).get('type');
  if (preset && [...type.options].some(o => o.value === preset)) type.value = preset;
  const syncType = () => {
    const general = type.value === 'General question';
    eventFields.hidden = general;
    eventFields.querySelectorAll('input, select').forEach(el => { el.disabled = general; });
  };
  type.addEventListener('change', syncType);
  syncType();
  const date = form.elements.date;
  if (date) date.min = nyToday();
  addHoneypot(form);

  const success = form.parentElement.querySelector('.enquiry-success');
  success.querySelector('.enquiry-again').addEventListener('click', () => {
    form.reset();
    syncType();
    success.hidden = true;
    form.hidden = false;
    form.elements.name.focus();
  });

  let sending = false;
  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (sending || !validateForm(form)) return;
    const d = new FormData(form);
    const payload = Object.fromEntries(d.entries());
    const button = form.querySelector('[type="submit"]');
    sending = true;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    try {
      const res = await fetch(form.action, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)});
      let data = null;
      try { data = await res.json(); } catch {}
      if (res.ok && data?.ok) {
        success.querySelector('.enquiry-success-text').textContent = data.message;
        form.hidden = true;
        success.hidden = false;
        success.focus();
        return;
      }
      if (data?.errors) {
        showSummary(form, Object.entries(data.errors).map(([key, msg]) => {
          const el = document.getElementById(ENQUIRY_FIELDS[key]);
          if (el) showFieldError(el, msg);
          return {id: el ? el.id : null, msg};
        }));
        return;
      }
      throw new Error(data?.message || 'not sent');
    } catch {
      // Server unreachable (or a static copy of the site): offer the same message as an email.
      const body = `Hello Pasha!\n\nName: ${d.get('name')}\nEmail: ${d.get('email')}\nType: ${d.get('type')}`
        + (d.get('date') ? `\nEvent date: ${d.get('date')}` : '') + (d.get('quantity') ? `\nQuantity: ${d.get('quantity')}` : '')
        + `\n\n${d.get('message')}`;
      showSummary(form, [{
        msg: 'Sorry, we couldn’t send your message just now. Please try again in a moment, or',
        href: `mailto:pashabakess@gmail.com?subject=${encodeURIComponent(`${d.get('type')} enquiry`)}&body=${encodeURIComponent(body)}`,
        linkText: 'send it by email instead.'
      }]);
    } finally {
      sending = false;
      button.disabled = false;
      button.removeAttribute('aria-busy');
    }
  });
}
$$('.enquiry-form').forEach(setupEnquiry);

/* ——— Live menu from the server ——— */
function applySettings(data) {
  settings = data;
  if (Array.isArray(data.cookies) && data.cookies.length) {
    cookies = data.cookies.map(c => ({
      id: Number(c.id), name: String(c.name), desc: String(c.desc || ''), type: c.type === 'seasonal' ? 'seasonal' : 'signature',
      img: String(c.img || ''), imgMd: String(c.imgMd || c.img || ''), imgSm: String(c.imgSm || c.img || ''),
      from: isoDate(c.from), until: isoDate(c.until)
    }));
    // Drop quantities for flavors that are no longer on the menu.
    Object.keys(qty).forEach(id => { if (!cookieById(id)) delete qty[id]; });
  }
  if (data.prices && Object.keys(data.prices).length) {
    pricesCents = Object.fromEntries(Object.entries(data.prices).map(([k, v]) => [k, Number(v)]));
    if (!pricesCents[box]) box = Number(Object.keys(pricesCents)[0]);
  }
  $$('[data-pickup-area]').forEach(el => { el.textContent = data.pickupArea || 'Tyngsboro, MA'; });
  if (Array.isArray(data.slots)) renderSlots(data.slots);
  if (Array.isArray(data.occasions) && data.occasions.length && $('#o-occasion')) {
    $('#o-occasion').innerHTML = data.occasions.map(o => `<option>${esc(o)}</option>`).join('');
    applyDraft(['occasion']);
  }
  const note = $('#order-unavailable');
  if (note && $('#order-success').hidden) {
    note.hidden = !!data.accepting;
    note.textContent = data.accepting ? '' : (data.closedMessage || 'Online ordering is paused for now.');
    $('#place-order').disabled = !data.accepting;
  }
}

function orderingUnavailable() {
  const note = $('#order-unavailable');
  if (!note || !$('#order-success').hidden) return;
  note.hidden = false;
  note.textContent = 'Online ordering is temporarily unavailable. Please try again soon, or email pashabakess@gmail.com with your order.';
}

async function loadMenu() {
  try {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 8000);
    const res = await fetch('api/menu.php', {cache: 'no-store', signal: controller.signal});
    clearTimeout(timer);
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error('menu unavailable');
    applySettings(data);
  } catch {
    if (!settings) orderingUnavailable();
  }
  renderAll();
}

function renderAll() {
  renderSeason();
  renderMenu();
  renderFlavorChoices();
  renderCredits();
  renderPrices();
  refreshDates();
  if ($('#order-form')) {
    const radio = document.querySelector(`input[name="box"][value="${box}"]`);
    if (radio) radio.checked = true;
    updatePayment();
    enforceAvailability();
  }
  update();
}

/* ——— Order page start-up: payment screen after a reload, saved draft and box, ?flavor= from the menu ——— */
if ($('#order-form')) {
  const form = $('#order-form');
  addHoneypot(form);
  const params = new URLSearchParams(location.search);
  const chosen = Number(params.get('flavor'));
  const last = store.get(LAST_ORDER_KEY);
  if (chosen > 0) store.remove(LAST_ORDER_KEY);
  else if (last?.order?.code && Date.now() - last.savedAt < 6 * 3600e3) showSuccess(last.order);

  draft = store.get(DRAFT_KEY) || {};
  applyDraft();
  form.addEventListener('input', saveDraft);
  form.addEventListener('change', saveDraft);

  const saved = store.get('pashabakess-box');
  if (saved && pricesCents[saved.size] && saved.qty && typeof saved.qty === 'object') {
    box = Number(saved.size);
    Object.entries(saved.qty).forEach(([id, q]) => { if (Number.isInteger(q) && q > 0 && q <= 36) qty[id] = q; });
    if (total() > box) Object.keys(qty).forEach(k => delete qty[k]);
  }
  if (chosen > 0) {
    if (total() < box) qty[chosen] = (qty[chosen] || 0) + 1;
    else boxFeedback('Your box is full. Choose a bigger box or change flavors.', false);
    params.delete('flavor');
    history.replaceState(null, '', location.pathname + (params.size ? '?' + params.toString() : '') + location.hash);
  }
}

const legacyRoutes = {menu:'menu.html',about:'about.html',order:'order.html',events:'celebrations.html',faq:'faq.html',contact:'contact.html','faq-allergies':'faq.html#faq-allergies'};
if ($('#main').dataset.page === 'home' && legacyRoutes[location.hash.slice(1)]) location.replace(legacyRoutes[location.hash.slice(1)]);
if (location.hash.startsWith('#faq-') && document.getElementById(location.hash.slice(1))?.tagName === 'DETAILS') document.getElementById(location.hash.slice(1)).open = true;

renderAll();
loadMenu();

/* ——— WebMCP: lets an assistant configure (never submit) the visible box ——— */
if ($('#order-form') && document.modelContext?.registerTool) {
  try {
    Promise.resolve(document.modelContext.registerTool({
      name: 'configure_cookie_box',
      title: 'Configure a cookie box',
      description: 'Set the visible box size and flavor quantities (by cookie id). Does not place an order or collect payment.',
      inputSchema: {type:'object', properties:{size:{type:'integer', enum:[4,6,12,24,36]}, quantities:{type:'object', additionalProperties:{type:'integer', minimum:0, maximum:36}}}, required:['size','quantities'], additionalProperties:false},
      annotations: {readOnlyHint:false},
      execute(input) {
        const entries = Object.entries(input?.quantities || {});
        const sum = entries.reduce((a, [, v]) => a + v, 0);
        if (!pricesCents[input?.size] || entries.some(([id, v]) => !cookieById(id) || !Number.isInteger(v) || v < 0) || sum > input.size)
          throw new Error('Use an offered box size and flavor ids from the menu, within the box capacity.');
        box = input.size;
        Object.keys(qty).forEach(k => delete qty[k]);
        entries.forEach(([id, v]) => { if (v > 0) qty[id] = v; });
        const radio = document.querySelector(`input[name="box"][value="${box}"]`);
        if (radio) radio.checked = true;
        update();
        return {size: box, quantities: {...qty}, price: money(pricesCents[box]), status: 'configured; not submitted'};
      }
    })).catch(() => {});
  } catch {}
}
