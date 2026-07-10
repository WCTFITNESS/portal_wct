/**
 * Dashboard Atual — Metabase via proxy same-origin (Metabase bloqueia CORS no browser).
 */
(function (global) {
  'use strict';

  var PALETTE = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0891b2', '#ca8a04', '#4f46e5'];
  var channelChart = null;
  var config = global.WCT_LEXOS_DASHBOARD_CONFIG || {};

  function fmtCurrency(v) {
    return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function normalizeMarketplaces(rawData) {
    var map = {
      'Mercado Livre - WCT': 'MercadoLivre',
      'Mercado Livre Full - WCT': 'MercadoLivre',
      'Amazon - WCT (DBA)': 'Amazon',
      'Casas Bahia - WCT': 'Casas Bahia',
      'Decathlon - WCT': 'Decathlon',
      'Tray - WCT': 'Site Próprio',
      'Olist - WCT': 'Olist',
      'WCT - MAGALU Marketplace': 'Magalu',
      'Netshoes - WCT': 'Netshoes',
      'Shopee - WCT': 'Shopee',
    };
    var grouped = {};
    (rawData || []).forEach(function (item) {
      if (!item || item.length < 2) return;
      var name = map[item[0]] || String(item[0] || '');
      grouped[name] = (grouped[name] || 0) + Number(item[1] || 0);
    });
    return Object.keys(grouped).map(function (k) { return [k, grouped[k]]; })
      .sort(function (a, b) { return b[1] - a[1]; });
  }

  function showError(msg) {
    var el = document.getElementById('lexos-dashboard-metabase-error');
    if (el) {
      el.textContent = msg;
      el.style.display = msg ? 'block' : 'none';
    }
  }

  function setMetric(id, text) {
    var el = document.getElementById(id);
    if (el) el.textContent = text;
  }

  function renderChannelsTable(rows) {
    var tbody = document.getElementById('lexos-channels-tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    if (!rows || !rows.length) {
      tbody.innerHTML = '<tr><td colspan="2">Sem dados de canais no período.</td></tr>';
      return;
    }
    rows.forEach(function (row) {
      var tr = document.createElement('tr');
      tr.innerHTML = '<td>' + row[0] + '</td><td>' + fmtCurrency(row[1]) + '</td>';
      tbody.appendChild(tr);
    });
  }

  function renderChannelsChart(rows) {
    var wrap = document.getElementById('lexos-channels-chart-wrap');
    var list = document.getElementById('lexos-channels-pie-list');
    var canvas = document.getElementById('lexos-channel-chart');
    if (!wrap || !canvas) return;

    if (!rows || !rows.length) {
      wrap.style.display = 'none';
      if (channelChart) {
        channelChart.destroy();
        channelChart = null;
      }
      return;
    }

    wrap.style.display = 'block';
    var labels = rows.map(function (r) { return r[0]; });
    var values = rows.map(function (r) { return r[1]; });
    var total = values.reduce(function (a, b) { return a + b; }, 0);

    if (list) {
      list.innerHTML = '';
      rows.forEach(function (row, i) {
        var pct = total > 0 ? ((row[1] / total) * 100).toFixed(1).replace('.', ',') : '0';
        var li = document.createElement('li');
        li.innerHTML = '<span><span class="lexos-dot" style="background:' + PALETTE[i % PALETTE.length] + '"></span>'
          + row[0] + '</span><strong>' + pct + '%</strong>';
        list.appendChild(li);
      });
    }

    if (typeof Chart === 'undefined') return;
    if (channelChart) channelChart.destroy();
    channelChart = new Chart(canvas.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: labels,
        datasets: [{
          data: values,
          backgroundColor: labels.map(function (_, i) { return PALETTE[i % PALETTE.length]; }),
          borderWidth: 1,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '58%',
        plugins: { legend: { display: false } },
      },
    });
  }

  function loadDashboardMetabase(startDate, endDate) {
    if (!config.metricsApiUrl) {
      showError('Configuração do dashboard indisponível.');
      return Promise.resolve();
    }

    showError('');
    setMetric('lexos-m-faturamento', 'Carregando…');
    setMetric('lexos-m-pedidos', 'Carregando…');
    setMetric('lexos-m-ticket', 'Carregando…');

    var url = config.metricsApiUrl
      + '&lexos_start=' + encodeURIComponent(startDate)
      + '&lexos_end=' + encodeURIComponent(endDate);

    return fetch(url, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) {
        return r.json().then(function (body) {
          return { ok: r.ok, body: body };
        });
      })
      .then(function (pack) {
        if (!pack.ok || !pack.body.ok) {
          throw new Error((pack.body && pack.body.message) || 'Falha ao carregar dashboard');
        }
        var metrics = pack.body.metrics || {};
        var canais = normalizeMarketplaces(metrics.canais || []);

        setMetric('lexos-m-faturamento', fmtCurrency(metrics.faturamento));
        setMetric('lexos-m-pedidos', String(Number(metrics.pedidos || 0).toLocaleString('pt-BR')));
        setMetric('lexos-m-ticket', fmtCurrency(metrics.ticket_medio));
        renderChannelsTable(canais);
        renderChannelsChart(canais);
      })
      .catch(function (err) {
        console.error('Metabase dashboard:', err);
        showError('Não foi possível carregar os dados do dashboard. Tente novamente em instantes.');
        setMetric('lexos-m-faturamento', '—');
        setMetric('lexos-m-pedidos', '—');
        setMetric('lexos-m-ticket', '—');
        renderChannelsTable([]);
        renderChannelsChart([]);
      });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var tab = document.querySelector('.lexos-tab-content[data-content="dashboard"]');
    if (!tab || !tab.classList.contains('active')) return;
    var startEl = document.getElementById('lexos-start');
    var endEl = document.querySelector('.lexos-tab-content[data-content="dashboard"] input[name="lexos_end"]');
    if (!startEl || !endEl) return;
    loadDashboardMetabase(startEl.value, endEl.value);
  });

  global.WctLexosDashboardMetabase = { load: loadDashboardMetabase };
})(window);
