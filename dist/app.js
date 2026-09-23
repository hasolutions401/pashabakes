/* Pashabakess — menu, box builder, order/enquiry email drafts.
   Nothing is sent automatically and no payment is processed. */

const cookies = [
  {name:'Chocolate Chunk',type:'signature',desc:'Brown butter base with semi-sweet chocolate chips, dark chocolate chunks, topped with sea salt flakes.',img:'https://images.unsplash.com/photo-1673551490160-3f2e712b9373?auto=format&fit=crop&w=900&q=80',credit:'American Heritage Chocolate / Unsplash',source:'https://unsplash.com/photos/chocolate-chip-cookies-and-a-glass-of-milk-tqKB97R-eOw'},
  {name:'Biscoff',type:'signature',desc:'Brown butter base with Biscoff cookie pieces, white chocolate chips, drizzled with Biscoff spread.',img:'https://assets-eu-01.kc-usercontent.com/21d2ecef-fb9b-01b1-9022-cf60b52c2c77/d79e9c4f-6fbe-4c62-8ca7-fe295e3169b3/Biscoff-Cookies-WEB-RES-1.jpg?auto=format&lossless=1&q=85&w=900',credit:'Silver Spoon',source:'https://www.silverspoon.co.uk/recipes/biscoff-cookies'},
  {name:'Chocolate Sea Salt Toffee',type:'signature',desc:'Rich brown butter cookie with toffee bits, semi-sweet chocolate chips, topped with sea salt flakes.',img:'https://scientificallysweet.com/wp-content/uploads/2022/09/IMG_3198-salted-toffee-chocolate-chip-cookies-feature2.jpg',credit:'Scientifically Sweet',source:'https://scientificallysweet.com/salted-toffee-chocolate-chip-cookies/'},
  {name:'Red Velvet',type:'signature',desc:'Red cookie base with cocoa powder, white chocolate chips and white chocolate drizzle.',img:'https://sallysbakingaddiction.com/wp-content/uploads/2013/12/red-velvet-white-chocolate-chip-cookies-2.jpg',credit:'Sally’s Baking',source:'https://sallysbakingaddiction.com/red-velvet-chocolate-chip-cookies/'},
  {name:'Pumpkin Chocolate Chip',type:'seasonal',desc:'Brown butter base with pumpkin purée, cinnamon, and chocolate chips.',img:'https://sallysbakingaddiction.com/wp-content/uploads/2013/09/chewy-pumpkin-chocolate-chip-cookies-3.jpg',credit:'Sally’s Baking',source:'https://sallysbakingaddiction.com/2013/09/04/pumpkin-chocolate-chip-cookies/'},
  {name:'Maple Pecan',type:'seasonal',desc:'Brown butter base with cinnamon, maple syrup, pecans.',img:'https://confessionsofabakingqueen.com/wp-content/uploads/2020/11/plate-of-maple-pecan-cookies-1-of-1-1024x1536-1.jpg',credit:'Confessions of a Baking Queen',source:'https://confessionsofabakingqueen.com/maple-pecan-cookies/'}
];

const prices = {4:14, 6:20, 12:38, 24:76, 36:114};
const $ = s => document.querySelector(s);
const $$ = s => Array.from(document.querySelectorAll(s));
const quantities = cookies.map(() => 0);
let box = 4;
let requestText = '';

const icon = (id, cls = '') => `<svg class="icon ${cls}" aria-hidden="true"><use href="#i-${id}"/></svg>`;
const total = () => quantities.reduce((a, b) => a + b, 0);
const money = n => `$${n}`;

/* Seamless announcement strips, with keyboard/touch pause controls. */
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

/* ——— Render menu, flavor steppers and credits ——— */
$('#cookie-grid').innerHTML = cookies.map((c, i) => `
  <article class="cookie-card" data-type="${c.type}">
    <div class="cookie-image">
      <img src="${c.img}" alt="Illustrative photograph of ${c.name} cookies" width="900" height="760" loading="lazy" decoding="async">
      <span class="cookie-tag ${c.type}">${c.type === 'seasonal' ? 'OCTOBER SPECIAL' : 'SIGNATURE'}</span>
    </div>
    <h3>${c.name}</h3>
    <p>${c.desc}</p>
    <div class="cookie-bottom">
      <span>${c.type === 'seasonal' ? 'A little taste of fall' : 'A forever favorite'}</span>
      <button type="button" class="add-cookie" data-add="${i}" aria-label="Add ${c.name} to your box">
        ${icon('plus')}<span class="add-label">Add</span>
      </button>
    </div>
  </article>`).join('');

$('#flavor-choices').innerHTML = cookies.map((c, i) => `
  <div class="flavor-choice" data-flavor="${i}">
    <img src="${c.img}" alt="" width="56" height="56" loading="lazy" decoding="async">
    <span class="flavor-name" id="flavor-name-${i}">${c.name}<small>${c.type === 'seasonal' ? 'October special' : 'Signature'}</small></span>
    <div class="stepper" role="group" aria-labelledby="flavor-name-${i}">
      <button type="button" class="step" id="step-minus-${i}" data-step="-1" data-i="${i}" aria-label="One fewer ${c.name}">${icon('minus')}</button>
      <output id="qty-${i}" class="qty" aria-label="${c.name} quantity">0</output>
      <button type="button" class="step" id="step-plus-${i}" data-step="1" data-i="${i}" aria-label="One more ${c.name}">${icon('plus')}</button>
    </div>
  </div>`).join('');

$('#photo-credits').innerHTML = cookies.map(c =>
  `<a href="${c.source}" target="_blank" rel="noreferrer">${c.name} — ${c.credit} ${icon('external')}<span class="sr-only">(opens in a new tab)</span></a>`).join('');

/* ——— Box state ——— */
function update() {
  const count = total();
  const full = count >= box;

  $('#selection-count').textContent = `${count} of ${box} cookies chosen`;
  const progress = $('.selection-progress');
  progress.setAttribute('aria-valuemax', box);
  progress.setAttribute('aria-valuenow', Math.min(count, box));
  const pct = `${Math.min(count / box, 1) * 100}%`;
  $('#selection-progress-fill').style.width = pct;
  $('#bar-fill').style.width = pct;
  $('#bar-count').textContent = `${count} / ${box}`;
  $('#bar-price').textContent = money(prices[box]);
  $('.box-bar').classList.toggle('is-complete', count === box);
  $('.selection-status').classList.toggle('is-complete', count === box);

  $('#clear-box').disabled = count === 0;
  $('#summary-total').textContent = money(prices[box]);
  $('#summary-size').textContent = `${box} cookies · Mix & match`;

  // Steppers
  cookies.forEach((c, i) => {
    $(`#qty-${i}`).textContent = quantities[i];
    $(`.step[data-i="${i}"][data-step="-1"]`).disabled = quantities[i] === 0;
    $(`.step[data-i="${i}"][data-step="1"]`).disabled = full;
    $(`.flavor-choice[data-flavor="${i}"]`).classList.toggle('has-qty', quantities[i] > 0);
  });

  // Menu card buttons reflect what's already in the box
  $$('[data-add]').forEach(btn => {
    const i = Number(btn.dataset.add);
    const q = quantities[i];
    btn.classList.toggle('in-box', q > 0);
    btn.querySelector('.add-label').textContent = q > 0 ? `${q} in box` : 'Add';
    btn.setAttribute('aria-label', q > 0 ? `Add another ${cookies[i].name} (${q} in your box)` : `Add ${cookies[i].name} to your box`);
  });

  // Summary list
  const items = $('#summary-items');
  items.replaceChildren();
  cookies.forEach((c, i) => {
    if (!quantities[i]) return;
    const row = document.createElement('div');
    row.className = 'summary-line';
    const name = document.createElement('span');
    name.textContent = c.name;
    const qty = document.createElement('strong');
    qty.textContent = `× ${quantities[i]}`;
    row.append(name, qty);
    items.append(row);
  });
  if (!count) items.innerHTML = '<p>Your favorites go here.<br>Pick a flavor to get started.</p>';

  $('#flavor-error').textContent = count > box
    ? `Your box has ${count} cookies. Remove ${count - box} to fit your selected size.`
    : '';
}

$$('.step').forEach(btn => btn.addEventListener('click', () => {
  const i = Number(btn.dataset.i);
  const next = quantities[i] + Number(btn.dataset.step);
  if (next < 0 || (Number(btn.dataset.step) > 0 && total() >= box)) return;
  quantities[i] = next;
  update();
  if (btn.disabled) btn.closest('.stepper').querySelector('.step:not(:disabled)')?.focus();
}));

$$('input[name="box"]').forEach(r => r.addEventListener('change', () => {
  box = Number(r.value);
  update();
}));

$('#clear-box').addEventListener('click', () => {
  quantities.fill(0);
  update();
  $('.size-option input:checked')?.focus();
});

/* ——— Toast: short confirmation, auto-dismisses, pauses while hovered/focused ——— */
const toast = $('#box-feedback');
let toastTimer;
function hideToast() { toast.hidden = true; }
function scheduleToast() { clearTimeout(toastTimer); toastTimer = setTimeout(hideToast, 4500); }
function boxFeedback(message, ok = true) {
  toast.querySelector('.toast-text').textContent = message;
  toast.classList.toggle('is-warning', !ok);
  toast.hidden = false;
  scheduleToast();
}
toast.addEventListener('mouseenter', () => clearTimeout(toastTimer));
toast.addEventListener('mouseleave', scheduleToast);
toast.addEventListener('focusin', () => clearTimeout(toastTimer));
toast.addEventListener('focusout', scheduleToast);
toast.querySelector('.toast-close').addEventListener('click', hideToast);
toast.querySelector('a').addEventListener('click', hideToast);

$$('[data-add]').forEach(b => b.addEventListener('click', () => {
  const i = Number(b.dataset.add);
  if (total() < box) {
    quantities[i]++;
    update();
    boxFeedback(`${cookies[i].name} added · ${total()} of ${box} cookies`);
  } else {
    boxFeedback('Your box is full. Choose a bigger box or change flavors.', false);
  }
}));

/* ——— Menu filters ——— */
$$('[data-filter]').forEach(b => b.addEventListener('click', () => {
  $$('[data-filter]').forEach(x => {
    x.classList.toggle('active', x === b);
    x.setAttribute('aria-pressed', String(x === b));
  });
  let shown = 0;
  $$('.cookie-card').forEach(c => {
    c.hidden = b.dataset.filter !== 'all' && c.dataset.type !== b.dataset.filter;
    if (!c.hidden) shown++;
  });
  $('#filter-status').textContent = `Showing ${shown} cookies`;
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
  const target = document.querySelector(a.getAttribute('href'));
  if (target?.tagName === 'DETAILS') target.open = true;
}));

/* ——— Pickup date & time ——— */
function minimumDate() {
  const parts = new Intl.DateTimeFormat('en-CA', {timeZone:'America/New_York', year:'numeric', month:'2-digit', day:'2-digit'}).formatToParts(new Date());
  const v = t => parts.find(p => p.type === t).value;
  const d = new Date(`${v('year')}-${v('month')}-${v('day')}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + 7);
  return d.toISOString().slice(0, 10);
}
function prettyDate(iso) {
  return new Date(`${iso}T12:00:00Z`).toLocaleDateString('en-US', {weekday:'long', month:'long', day:'numeric', timeZone:'UTC'});
}
$('#pickup-time').insertAdjacentHTML('beforeend', Array.from({length: 19}, (_, i) => {
  const h = 10 + Math.floor(i / 2), m = i % 2 ? '30' : '00';
  const label = `${h > 12 ? h - 12 : h}:${m} ${h >= 12 ? 'PM' : 'AM'}`;
  return `<option value="${String(h).padStart(2, '0')}:${m}">${label}</option>`;
}).join(''));
function refreshMinDate() {
  const min = minimumDate();
  $('#pickup-date').min = min;
  $('#pickup-date-hint').textContent = `Earliest date: ${prettyDate(min)}.`;
}
refreshMinDate();
$('#pickup-date').addEventListener('focus', refreshMinDate);
$('#e-date').min = new Date().toISOString().slice(0, 10);

/* ——— Form validation: inline errors on blur, summary on submit ——— */
function fieldMessage(el) {
  if (el.type === 'checkbox') return el.checked ? '' : el.dataset.error;
  const value = el.value.trim();
  if (el.required && !value) return el.dataset.error || 'Please complete this field.';
  if (!value) return '';
  if (el.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) return 'Please enter a valid email address, like name@example.com.';
  if (el.type === 'tel' && value.replace(/\D/g, '').length < 10) return 'Please enter a phone number with area code.';
  if (el.id === 'pickup-date' && value < minimumDate()) return `Please allow one week’s notice. The earliest pickup date is ${prettyDate(minimumDate())}.`;
  return '';
}
function showFieldError(el, message) {
  const err = document.getElementById(`${el.id}-error`);
  if (message) el.setAttribute('aria-invalid', 'true');
  else el.removeAttribute('aria-invalid');
  if (err) { err.textContent = message; err.hidden = !message; }
}
function validateForm(form, extra = []) {
  const problems = [...extra];
  form.querySelectorAll('[data-error]').forEach(el => {
    const msg = fieldMessage(el);
    showFieldError(el, msg);
    if (msg) problems.push({id: el.id, msg});
  });
  const summary = form.querySelector('.error-summary');
  if (!problems.length) { summary.hidden = true; return true; }
  const list = summary.querySelector('ul');
  list.replaceChildren(...problems.map(p => {
    const li = document.createElement('li');
    const a = document.createElement('a');
    a.href = `#${p.id}`;
    a.textContent = p.msg;
    a.addEventListener('click', e => { e.preventDefault(); document.getElementById(p.id)?.focus(); });
    li.append(a);
    return li;
  }));
  summary.hidden = false;
  summary.focus();
  return false;
}
$$('form [data-error]').forEach(el => {
  el.addEventListener('blur', () => { if (el.value || el.getAttribute('aria-invalid')) showFieldError(el, fieldMessage(el)); });
  el.addEventListener(el.type === 'checkbox' || el.tagName === 'SELECT' ? 'change' : 'input', () => {
    if (el.getAttribute('aria-invalid')) showFieldError(el, fieldMessage(el));
  });
});

/* ——— Dialogs ——— */
$$('.dialog-close').forEach(b => b.addEventListener('click', () => b.closest('dialog').close()));
$$('dialog').forEach(d => d.addEventListener('click', e => {
  if (e.target !== d) return;
  const r = d.getBoundingClientRect();
  if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) d.close();
}));
$('#credits-open').addEventListener('click', () => $('#credits-dialog').showModal());

function openReview(title, subject) {
  $('#review-title').textContent = title;
  $('#review-content').textContent = requestText;
  $('#send-email').href = `mailto:pashabakess@gmail.com?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(requestText)}`;
  $('#copy-status').textContent = '';
  $('#review-dialog').showModal();
}

/* ——— Order request ——— */
$('#order-form').addEventListener('submit', e => {
  e.preventDefault();
  const count = total();
  const extra = [];
  if (count !== box) {
    const msg = count < box
      ? `Please choose ${box - count} more cookie${box - count === 1 ? '' : 's'} to fill your box of ${box}.`
      : `Please remove ${count - box} cookie${count - box === 1 ? '' : 's'} to fit your box of ${box}.`;
    $('#flavor-error').textContent = msg;
    const target = count < box ? 'step-plus-0' : `step-minus-${quantities.findIndex(q => q > 0)}`;
    extra.push({id: target, msg});
  }
  if (!validateForm(e.target, extra)) return;

  const d = new FormData(e.target);
  requestText = `Hello Pasha! I’d like to request a cookie box.\n\n${box} cookies — $${prices[box]}\n${cookies.map((c, i) => quantities[i] ? `${quantities[i]} × ${c.name}` : '').filter(Boolean).join('\n')}\n\nName: ${d.get('name')}\nEmail: ${d.get('email')}\nPhone: ${d.get('phone')}\nOccasion: ${d.get('occasion')}\nPreferred pickup: ${d.get('date')} (${d.get('time')} Eastern time)\nPayment preference: ${d.get('payment')}\nNotes: ${d.get('notes') || 'None'}\n\nI have read the allergy and cancellation notices. I understand availability and pickup are subject to confirmation, and the address will be sent on pickup day. Please confirm the total and payment instructions. Thank you!`;
  openReview('Your cookie request', 'Cookie order request — ' + d.get('name'));
});

$('#copy-request').addEventListener('click', async () => {
  try {
    await navigator.clipboard.writeText(requestText);
    $('#copy-status').textContent = 'Copied. Paste into an email to pashabakess@gmail.com.';
  } catch {
    $('#copy-status').textContent = 'Copy unavailable. Select and copy the request above.';
  }
});

/* ——— Enquiries ——— */
function enquiry(general) {
  $('#enquiry-title').textContent = general ? 'Say hello to Pasha' : 'Plan a celebration';
  $('#enquiry-type').value = general ? 'General question' : 'Birthday / celebration';
  $('#enquiry-errors').hidden = true;
  $('#enquiry-dialog').showModal();
}
$('#event-open').addEventListener('click', () => enquiry(false));
$('#contact-open').addEventListener('click', () => enquiry(true));
$('#large-order-open').addEventListener('click', () => { enquiry(false); $('#enquiry-type').value = 'Large order'; });

$('#enquiry-form').addEventListener('submit', e => {
  e.preventDefault();
  if (!validateForm(e.target)) return;
  const d = new FormData(e.target);
  requestText = `Hello Pasha!\n\nName: ${d.get('name')}\nEmail: ${d.get('email')}\nType: ${d.get('type')}\nDate: ${d.get('date') || 'Not decided'}\nQuantity: ${d.get('quantity')}\n\n${d.get('message')}`;
  $('#enquiry-dialog').close();
  openReview('Your enquiry', d.get('type') + ' enquiry');
});

update();

/* ——— WebMCP: lets an assistant configure (never submit) the visible box ——— */
if (document.modelContext?.registerTool) {
  try {
    Promise.resolve(document.modelContext.registerTool({
      name: 'configure_cookie_box',
      title: 'Configure a cookie box',
      description: 'Set the visible box size and flavor quantities. Does not send an order or collect payment.',
      inputSchema: {type:'object', properties:{size:{type:'integer', enum:[4,6,12,24,36]}, quantities:{type:'array', items:{type:'integer', minimum:0, maximum:36}, minItems:6, maxItems:6}}, required:['size','quantities'], additionalProperties:false},
      annotations: {readOnlyHint:false},
      execute(input) {
        if (!input || ![4,6,12,24,36].includes(input.size) || !Array.isArray(input.quantities) || input.quantities.length !== 6 || input.quantities.some(v => !Number.isInteger(v) || v < 0 || v > 36) || input.quantities.reduce((a, b) => a + b, 0) > input.size)
          throw new Error('Use a box of 4, 6, 12, 24, or 36 and six nonnegative flavor counts within capacity.');
        box = input.size;
        input.quantities.forEach((v, i) => quantities[i] = v);
        const radio = document.querySelector(`input[name="box"][value="${box}"]`);
        if (radio) radio.checked = true;
        update();
        return {size: box, quantities: [...quantities], price: prices[box], status: 'configured; not submitted'};
      }
    })).catch(() => {});
  } catch {}
}
