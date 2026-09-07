/**
 * Versandkostenretter — progressive enhancement only.
 * The form works fully without JavaScript (server-side POST + redirect).
 */
(function () {
  'use strict';

  var form = document.querySelector('.search-form');
  if (!form) return;

  var shopSelect = document.getElementById('shop_id');
  var cartInput = document.getElementById('cart_value');

  // Client-side validation hints (server still validates everything).
  form.addEventListener('submit', function (ev) {
    var problems = [];
    if (!shopSelect || !shopSelect.value) problems.push('Bitte wähle einen Shop aus.');
    if (!cartInput || cartInput.value.trim() === '') problems.push('Bitte gib deinen Warenkorbwert ein.');

    if (problems.length) {
      ev.preventDefault();
      var existing = document.querySelector('.js-error');
      if (existing) existing.remove();
      var box = document.createElement('div');
      box.className = 'notice notice-error js-error';
      box.setAttribute('role', 'alert');
      var ul = document.createElement('ul');
      problems.forEach(function (p) {
        var li = document.createElement('li');
        li.textContent = p; // textContent = XSS-safe
        ul.appendChild(li);
      });
      box.appendChild(ul);
      form.parentNode.insertBefore(box, form);
    }
  });

  // Friendly decimal separator support: "," -> "." while typing.
  if (cartInput) {
    cartInput.addEventListener('blur', function () {
      cartInput.value = cartInput.value.replace(',', '.');
    });
  }

  // Category filter: auto-submit on change (progressive enhancement —
  // works without JS via the noscript button). No cookies, no storage.
  document.querySelectorAll('select[data-autosubmit="1"]').forEach(function (sel) {
    sel.addEventListener('change', function () {
      if (sel.form) sel.form.submit();
    });
  });
})();
