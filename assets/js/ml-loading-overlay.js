/**
 * Overlay de carregamento — módulo Campanhas / ML (portal WCT).
 */
(function (global) {
  'use strict';

  var overlay = null;
  var textEl = null;
  var activeCount = 0;

  function ensure() {
    if (overlay) {
      return;
    }
    overlay = document.getElementById('ml-loading-overlay');
    textEl = document.getElementById('ml-loading-text');
  }

  function show(message) {
    ensure();
    if (!overlay) {
      return;
    }
    activeCount += 1;
    if (textEl && message) {
      textEl.textContent = message;
    }
    overlay.classList.add('is-open');
    overlay.setAttribute('aria-busy', 'true');
    document.body.classList.add('ml-is-loading');
  }

  function hide(force) {
    ensure();
    if (!overlay) {
      return;
    }
    if (force) {
      activeCount = 0;
    } else {
      activeCount = Math.max(0, activeCount - 1);
    }
    if (activeCount > 0) {
      return;
    }
    overlay.classList.remove('is-open');
    overlay.setAttribute('aria-busy', 'false');
    document.body.classList.remove('ml-is-loading');
  }

  function bindAuto() {
    document.addEventListener('submit', function (ev) {
      var form = ev.target;
      if (!form || form.tagName !== 'FORM') {
        return;
      }
      if (form.dataset.mlNoLoading === '1') {
        return;
      }
      if (!form.closest('.content')) {
        return;
      }
      var msg = form.dataset.mlLoadingMessage || 'Processando, aguarde…';
      show(msg);
    });

    document.addEventListener('click', function (ev) {
      var link = ev.target.closest('a.ml-trigger-loading, a.btn-export-xlsx');
      if (!link || link.dataset.mlNoLoading === '1') {
        return;
      }
      var msg = link.dataset.mlLoadingMessage || 'Gerando arquivo Excel…';
      show(msg);
      window.setTimeout(function () {
        hide(true);
      }, 120000);
    });

    global.addEventListener('pageshow', function (ev) {
      if (ev.persisted) {
        hide(true);
      }
    });

    global.addEventListener('load', function () {
      hide(true);
    });
  }

  function downloadBlob(url, options) {
    options = options || {};
    show(options.message || 'Gerando arquivo…');
    return fetch(url, options.fetchInit || {})
      .then(function (res) {
        if (!res.ok) {
          return res.text().then(function (text) {
            throw new Error(text || ('HTTP ' + res.status));
          });
        }
        return res.blob();
      })
      .then(function (blob) {
        var name = options.filename || 'download.xlsx';
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.setTimeout(function () {
          URL.revokeObjectURL(a.href);
        }, 2000);
      })
      .finally(function () {
        hide(true);
      });
  }

  bindAuto();

  global.MlLoading = {
    show: show,
    hide: hide,
    downloadBlob: downloadBlob,
  };
})(window);
