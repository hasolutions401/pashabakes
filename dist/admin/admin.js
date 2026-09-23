// Confirm risky actions, and stop accidental double-submits.
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-confirm]');
  if (btn && !window.confirm(btn.dataset.confirm)) e.preventDefault();
});

document.addEventListener('submit', (e) => {
  const form = e.target;
  if (e.defaultPrevented) return;
  if (form.dataset.submitting) { e.preventDefault(); return; }
  form.dataset.submitting = '1';
  // Disable after the submit event so the clicked button's value is still sent.
  setTimeout(() => form.querySelectorAll('button[type="submit"]').forEach((b) => { b.disabled = true; }), 0);
});

// If the page is restored from the back/forward cache, re-enable its forms.
window.addEventListener('pageshow', () => {
  document.querySelectorAll('form[data-submitting]').forEach((f) => {
    delete f.dataset.submitting;
    f.querySelectorAll('button[type="submit"]').forEach((b) => { b.disabled = false; });
  });
});
