/**
 * Versandkostenretter — progressive enhancement only.
 * The forms work fully without JavaScript (server-side GET/POST).
 */
(function () {
  'use strict';

  // Suchformular-spezifische Logik: nur wenn .search-form existiert
  // (Startseite). KEIN globaler early-return — der Kategorie-Autosubmit
  // unten muss auch auf der Ergebnisseite registriert werden.
  var form = document.querySelector('.search-form');
  if (form) {
    var shopSelect = document.getElementById('shop');
    var cartInput = document.getElementById('cart');

    // Client-side validation hints (server still validates everything).
    form.addEventListener('submit', function (ev) {
      var problems = [];
      if (!shopSelect || !shopSelect.value) problems.push('Bitte wähle einen Shop aus.');
      if (!cartInput || cartInput.value.trim() === '') problems.push('Bitte gib deinen aktuellen Warenkorbwert ein.');

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
  }

  // Category filter: auto-submit on change (progressive enhancement —
  // works without JS via the noscript button). No cookies, no storage.
  document.querySelectorAll('select[data-autosubmit="1"]').forEach(function (sel) {
    sel.addEventListener('change', function () {
      if (sel.form) sel.form.submit();
    });
  });
})();
