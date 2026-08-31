/*
   Админка: склад поставщиков и заказы. Всё, что она делает, уже умеет API —
   здесь только экран для тех ручек, которыми чинят застрявшие заказы.
*/

(function () {
  'use strict';

  const STATUSES = {
    created: 'ждёт оплаты',
    paid: 'оплачен',
    delivering: 'выдаётся',
    delivered: 'выдан',
    payment_failed: 'оплата не прошла',
    out_of_stock: 'нет кодов',
    delivery_failed: 'сбой выдачи'
  };

  // Заказ, который оплачен, но ключа не получил, — его и чиним кнопкой
  const RECOVERABLE = ['paid', 'delivering', 'out_of_stock', 'delivery_failed'];

  const TOKEN_KEY = 'ggsell_admin_token';

  const authPanel = document.getElementById('authPanel');
  const authNote = document.getElementById('authNote');
  const tokenInput = document.getElementById('tokenInput');
  const workspace = document.getElementById('workspace');
  const stockBody = document.getElementById('stockBody');
  const stockNote = document.getElementById('stockNote');
  const ordersBody = document.getElementById('ordersBody');
  const ordersNote = document.getElementById('ordersNote');
  const filters = document.getElementById('filters');

  let status = 'stuck';

  const saved = localStorage.getItem(TOKEN_KEY);
  if (saved) {
    tokenInput.value = saved;
    login();
  }

  document.getElementById('loginBtn').addEventListener('click', login);
  tokenInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') login();
  });

  document.getElementById('reloadStock').addEventListener('click', loadStock);
  document.getElementById('reloadOrders').addEventListener('click', loadOrders);
  document.getElementById('refillBtn').addEventListener('click', refill);
  document.getElementById('replayBtn').addEventListener('click', replay);

  filters.addEventListener('click', function (e) {
    const btn = e.target.closest('.filter');
    if (!btn) return;

    filters.querySelectorAll('.filter').forEach(function (b) {
      b.classList.toggle('is-active', b === btn);
    });
    status = btn.dataset.status;
    loadOrders();
  });

  function login() {
    const token = tokenInput.value.trim();
    if (token === '') return;

    // В заголовок уходит только ASCII: иначе fetch упадёт ещё до сервера,
    // и человек увидит «сервер не ответил» вместо честного «токен не тот»
    if (!/^[\x21-\x7e]+$/.test(token)) {
      note(authNote, 'Токен не подошёл', true);
      return;
    }

    api.adminToken = token;
    note(authNote, 'Проверяем токен…');

    // Отдельной ручки «проверить токен» нет, поэтому проверяем самой лёгкой
    api('GET', '/admin/stock').then(function (data) {
      localStorage.setItem(TOKEN_KEY, token);
      authPanel.hidden = true;
      workspace.hidden = false;
      drawStock(data.stock);
      loadOrders();
    }).catch(function (e) {
      api.adminToken = '';
      localStorage.removeItem(TOKEN_KEY);
      note(authNote, e.message === 'forbidden' ? 'Токен не подошёл' : 'Сервер не ответил', true);
    });
  }

  function loadStock() {
    return api('GET', '/admin/stock')
      .then(function (data) { drawStock(data.stock); })
      .catch(function () { note(stockNote, 'Не удалось получить склад', true); });
  }

  function drawStock(rows) {
    stockBody.textContent = '';

    rows.forEach(function (row) {
      const free = Number(row.free);
      const tr = document.createElement('tr');
      cell(tr, 'Поставщик ' + row.supplier);
      cell(tr, row.total);
      // Ноль свободных кодов — причина, по которой заказы висят в out_of_stock
      cell(tr, free, free === 0 ? 'tbl__cell--bad' : '');
      stockBody.appendChild(tr);
    });
  }

  function loadOrders() {
    return api('GET', '/admin/orders' + (status ? '?status=' + status : ''))
      .then(function (data) {
        drawOrders(data.orders);
        note(ordersNote, data.orders.length ? '' : 'Таких заказов сейчас нет');
      })
      .catch(function () { note(ordersNote, 'Не удалось получить заказы', true); });
  }

  function drawOrders(rows) {
    ordersBody.textContent = '';

    rows.forEach(function (order) {
      const tr = document.createElement('tr');

      cell(tr, order.public_id);
      cell(tr, order.sku);
      cell(tr, order.amount + ' ₽');
      cell(tr, STATUSES[order.status] || order.status, 'tbl__cell--' + tone(order.status));
      cell(tr, order.delivered_code || '—', order.delivered_code ? 'tbl__cell--code' : '');
      cell(tr, order.created_at);

      const actions = document.createElement('td');
      if (RECOVERABLE.indexOf(order.status) !== -1) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'adm-btn adm-btn--small';
        btn.textContent = 'Выдать ключ';
        btn.addEventListener('click', function () { redeliver(order.public_id, btn); });
        actions.appendChild(btn);
      }
      tr.appendChild(actions);

      ordersBody.appendChild(tr);
    });
  }

  function redeliver(publicId, btn) {
    btn.disabled = true;
    btn.textContent = 'Выдаём…';

    // Повторная выдача идемпотентна: если код уже привязан, сервер вернёт его же
    api('POST', '/admin/orders/' + publicId + '/redeliver')
      .then(function () {
        return Promise.all([loadOrders(), loadStock()]);
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Не вышло, ещё раз';
      });
  }

  function refill() {
    const supplier = document.getElementById('refillSupplier').value;
    const count = Number(document.getElementById('refillCount').value) || 10;

    api('POST', '/admin/stock/refill', { supplier: supplier, count: count })
      .then(function (data) {
        note(stockNote, 'Добавлено кодов: ' + data.added);
        return loadStock();
      })
      .catch(function () { note(stockNote, 'Пополнить не удалось', true); });
  }

  function replay() {
    api('POST', '/admin/webhooks/replay')
      .then(function (data) {
        note(stockNote, data.checked ? 'Догнали событий: ' + data.checked : 'Неприменённых событий нет');
        return loadOrders();
      })
      .catch(function () { note(stockNote, 'Не получилось', true); });
  }

  function tone(status) {
    if (status === 'delivered') return 'ok';
    if (status === 'created') return 'wait';
    return RECOVERABLE.indexOf(status) !== -1 ? 'bad' : 'wait';
  }

  function cell(row, text, className) {
    const td = document.createElement('td');
    td.textContent = text;
    if (className) td.className = className;
    row.appendChild(td);
    return td;
  }

  function note(box, text, bad) {
    box.textContent = text;
    box.hidden = text === '';
    box.classList.toggle('panel__note--bad', Boolean(bad));
  }
})();
