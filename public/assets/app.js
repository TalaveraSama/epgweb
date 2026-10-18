/* EPGWeb — interacción del panel */
(function () {
  'use strict';

  // menú lateral móvil
  var burger = document.getElementById('burger');
  var sidebar = document.getElementById('sidebar');
  var backdrop = document.getElementById('backdrop');
  if (burger && sidebar && backdrop) {
    burger.addEventListener('click', function () {
      sidebar.classList.toggle('open');
      backdrop.classList.toggle('show');
    });
    backdrop.addEventListener('click', function () {
      sidebar.classList.remove('open');
      backdrop.classList.remove('show');
    });
  }

  // confirmación en formularios peligrosos
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (f && f.getAttribute && f.getAttribute('data-confirm')) {
      if (!window.confirm(f.getAttribute('data-confirm'))) {
        ev.preventDefault();
      }
    }
  });

  // botones copiar
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('.copy-btn') : null;
    if (!btn) return;
    var el = document.querySelector(btn.getAttribute('data-copy'));
    if (!el) return;
    var text = el.textContent;
    function done(ok) {
      var prev = btn.textContent;
      btn.textContent = ok ? '✔ Copiado' : '✘ Error';
      setTimeout(function () { btn.textContent = prev; }, 1600);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { done(true); }, function () { fallback(); });
    } else { fallback(); }
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text; document.body.appendChild(ta); ta.select();
      try { done(document.execCommand('copy')); } catch (e) { done(false); }
      document.body.removeChild(ta);
    }
  });

  // auto-ocultar avisos
  document.querySelectorAll('.flash').forEach(function (el) {
    setTimeout(function () {
      el.style.transition = 'opacity .6s';
      el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 700);
    }, 6000);
  });
})();

/** Filtra canales en el selector de usuarios. */
function epgFilterChannels(q) {
  q = (q || '').toLowerCase().trim();
  document.querySelectorAll('#chpicker .ch-item').forEach(function (el) {
    el.style.display = (!q || (el.getAttribute('data-name') || '').indexOf(q) !== -1) ? '' : 'none';
  });
}
