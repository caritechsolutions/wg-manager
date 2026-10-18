/* wg-manager UI — no dependencies. */
(function () {
  'use strict';
  var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';

  // Confirmation for destructive forms.
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); }
    });
  });

  // Copy buttons.
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-copy');
      (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () {
        var old = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = old; }, 1200);
      }).catch(function () { window.prompt('Copy:', t); });
    });
  });

  // New client: own key vs generated.
  var ownKey = document.getElementById('ownKey');
  document.querySelectorAll('[data-keymode]').forEach(function (r) {
    r.addEventListener('change', function () {
      if (ownKey) { ownKey.hidden = !(r.checked && r.value === 'own'); }
    });
  });

  if (document.body.getAttribute('data-page') !== 'clients') { return; }

  // ---------------------------------------------------------------- clients page
  var rows = Array.prototype.slice.call(document.querySelectorAll('tr[data-id]'));
  var search = document.getElementById('search');
  var filter = 'all';

  function applyFilter() {
    var q = (search && search.value || '').trim().toLowerCase();
    rows.forEach(function (tr) {
      var okF = filter === 'all' || tr.getAttribute('data-state') === filter;
      var okQ = !q || tr.getAttribute('data-search').indexOf(q) !== -1;
      tr.hidden = !(okF && okQ);
    });
  }
  if (search) { search.addEventListener('input', applyFilter); }
  document.querySelectorAll('[data-filter]').forEach(function (b) {
    b.addEventListener('click', function () {
      filter = b.getAttribute('data-filter');
      document.querySelectorAll('[data-filter]').forEach(function (x) {
        var on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      applyFilter();
    });
  });

  // Enable/disable toggles.
  document.querySelectorAll('[data-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-toggle');
      var next = btn.getAttribute('aria-checked') !== 'true';
      btn.disabled = true;
      var body = new URLSearchParams({ id: id, enabled: next ? '1' : '' });
      fetch('?r=api/toggle', { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' }, body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (typeof d.enabled === 'boolean') { btn.setAttribute('aria-checked', d.enabled ? 'true' : 'false'); }
          if (!d.ok) { alert(d.error || 'Failed'); }
          refresh();
        })
        .catch(function () { alert('Request failed'); })
        .finally(function () { btn.disabled = false; });
    });
  });

  // QR dialog.
  var dlg = document.getElementById('qrDialog');
  document.querySelectorAll('[data-qr]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.getElementById('qrTitle').textContent = b.getAttribute('data-name');
      var img = document.getElementById('qrImg');
      img.alt = 'QR code for ' + b.getAttribute('data-name');
      img.src = '?r=client-qr&id=' + encodeURIComponent(b.getAttribute('data-qr'));
      if (dlg.showModal) { dlg.showModal(); } else { window.open(img.src); }
    });
  });

  // Live stats.
  function refresh() {
    fetch('?r=api/status', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (r.status === 401) { location.reload(); } return r.json(); })
      .then(function (d) {
        if (!d.ok) { return; }
        var counts = { all: 0, on: 0, stale: 0, never: 0, off: 0 };
        rows.forEach(function (tr) {
          var c = d.clients[tr.getAttribute('data-id')];
          if (!c) { return; }
          counts.all++; counts[c.state]++;
          tr.setAttribute('data-state', c.state);
          var dot = tr.querySelector('.dot'); dot.className = 'dot st-' + c.state;
          tr.querySelector('[data-f="handshake"]').textContent = c.handshake;
          tr.querySelector('[data-f="endpoint"]').textContent = c.endpoint || '—';
          tr.querySelector('[data-f="rx"]').textContent = '↓ ' + c.rx;
          tr.querySelector('[data-f="tx"]').textContent = '↑ ' + c.tx;
        });
        Object.keys(counts).forEach(function (k) {
          document.querySelectorAll('[data-count="' + k + '"]').forEach(function (el) { el.textContent = counts[k]; });
        });
        var badge = document.getElementById('syncBadge');
        if (badge) {
          var cls = !d.up ? 'err' : (d.inSync ? 'ok' : 'warn');
          badge.className = 'sync sync-' + cls;
          badge.lastChild.textContent = !d.up ? d.interface + ' is down' : (d.inSync ? 'Config in sync' : 'Not applied');
        }
        var lu = document.getElementById('lastUpdate');
        if (lu) { lu.textContent = 'Updated ' + new Date().toLocaleTimeString(); }
        applyFilter();
      })
      .catch(function () {});
  }
  setInterval(function () { if (!document.hidden) { refresh(); } }, 5000);
})();
