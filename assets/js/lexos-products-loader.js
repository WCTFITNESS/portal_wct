(function (global) {
  'use strict';

  function filterProductsLikePlugin(items) {
    return (items || []).filter(function (p) {
      var sku = String(p.Sku || '');
      return sku !== '' && !/^[789]/.test(sku);
    });
  }

  function extensionFetch(url, method, payload) {
    return new Promise(function (resolve, reject) {
      var requestId = 'fetch-' + Date.now() + '-' + Math.random().toString(16).slice(2);
      var done = false;
      function cleanup() {
        done = true;
        window.removeEventListener('message', onMessage);
      }
      function onMessage(event) {
        if (event.source !== window || !event.data || event.data.type !== 'WCT_LEXOS_FETCH_RESULT') {
          return;
        }
        if (event.data.requestId !== requestId) {
          return;
        }
        cleanup();
        if (event.data.ok) {
          resolve(event.data);
        } else {
          reject(new Error(event.data.error || ('HTTP ' + (event.data.status || 0))));
        }
      }
      window.addEventListener('message', onMessage);
      window.postMessage({
        type: 'WCT_LEXOS_FETCH',
        requestId: requestId,
        url: url,
        method: method || 'POST',
        payload: payload || null,
      }, '*');
      setTimeout(function () {
        if (!done) {
          cleanup();
          reject(new Error('Conector Lexos Hub não respondeu. Instale a extensão e abra app-hub.lexos.com.br logado.'));
        }
      }, 35000);
    });
  }

  function pingExtension(timeoutMs) {
    return new Promise(function (resolve) {
      if (document.documentElement.getAttribute('data-wct-lexos-extension') === '1') {
        resolve(true);
        return;
      }
      var requestId = 'ping-' + Date.now();
      var done = false;
      function onMessage(event) {
        if (event.source !== window || !event.data || event.data.type !== 'WCT_LEXOS_PONG') {
          return;
        }
        if (event.data.requestId !== requestId) {
          return;
        }
        done = true;
        window.removeEventListener('message', onMessage);
        resolve(true);
      }
      window.addEventListener('message', onMessage);
      window.postMessage({ type: 'WCT_LEXOS_PING', requestId: requestId }, '*');
      setTimeout(function () {
        if (!done) {
          window.removeEventListener('message', onMessage);
          resolve(false);
        }
      }, timeoutMs || 1500);
    });
  }

  function loadProductsViaExtension(options) {
    var start = options.start;
    var end = options.end;
    var search = options.search || '';
    var take = options.take || 20;
    var page = options.page || 1;
    var skip = (page - 1) * take;

    var payload = {
      requiresCounts: true,
      aggregates: [{ type: 'sum', field: 'TotalVendidoItem' }],
      skip: skip,
      take: take,
      sorted: [{ name: 'Sku', direction: 'ascending' }],
    };
    if (search) {
      payload.search = [{
        fields: ['Nome', 'Sku', 'Ean'],
        operator: 'contains',
        key: search,
        ignoreCase: true,
      }];
    }

    var url = 'https://app-hub-webapi.lexos.com.br/api/RelatorioVendas/DataSourceCurvaAbc'
      + '?lojaId=-1&initialDate=' + encodeURIComponent(start + 'T00:00:00')
      + '&finalDate=' + encodeURIComponent(end + 'T23:59:59');

    return extensionFetch(url, 'POST', payload).then(function (result) {
      var body = result.body || {};
      var items = filterProductsLikePlugin(body.result || []);
      return {
        ok: true,
        items: items,
        count: Number(body.count || items.length || 0),
        source: 'extension',
      };
    });
  }

  global.WctLexosProductsLoader = {
    pingExtension: pingExtension,
    loadProductsViaExtension: loadProductsViaExtension,
    filterProductsLikePlugin: filterProductsLikePlugin,
  };
})(window);
