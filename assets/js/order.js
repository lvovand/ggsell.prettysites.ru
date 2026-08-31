/*
   Страница статуса заказа. Показывает, что с заказом сейчас, даёт кнопки
   эмуляции оплаты и, когда ключ выдан, — сам ключ.

   Пока заказ в промежуточном статусе, страница переспрашивает сервер:
   выдача может упереться в зависшего поставщика и занять несколько секунд,
   а на плохой сценарий её доводит повторная попытка на стороне бэка.
*/

(function () {
  'use strict';

  const POLL_INTERVAL = 1500;
  const POLL_LIMIT = 20; // примерно полминуты; дальше предлагаем обновить руками

  // Второй элемент — модификатор плашки статуса
  const STATUSES = {
    created:        ['Ждёт оплаты', 'wait'],
    paid:           ['Оплачен, готовим ключ', 'work'],
    delivering:     ['Выдаём ключ', 'work'],
    delivered:      ['Ключ выдан', 'ok'],
    payment_failed: ['Оплата не прошла', 'bad'],
    out_of_stock:   ['Ключей временно нет', 'bad'],
    delivery_failed:['Не удалось выдать ключ', 'bad']
  };

  // Что писать под карточкой в незавершённых случаях
  const NOTES = {
    payment_failed:  'Платёж отклонён, деньги не списаны. Заказ можно оформить заново из каталога.',
    out_of_stock:    'Оплата прошла, но у поставщика кончились ключи. Заказ остался в очереди: как только склад пополнят, ключ появится на этой странице.',
    delivery_failed: 'Оплата прошла, а поставщик не ответил. Заказ не потерян — выдачу повторят, ключ появится здесь.'
  };

  const params = new URLSearchParams(location.search);
  const orderId = params.get('id') || '';

  const card = document.getElementById('orderCard');
  const errorBox = document.getElementById('orderError');
  const payBox = document.getElementById('orderPay');
  const keyBox = document.getElementById('orderKey');
  const note = document.getElementById('orderNote');
  const refreshBtn = document.getElementById('refreshBtn');
  const copyBtn = document.getElementById('copyKey');

  let polls = 0;
  let timer = null;

  if (!orderId) {
    fail('Заказ не указан. Вернитесь в каталог и нажмите «Купить».');
    return;
  }

  load();

  document.getElementById('payOk').addEventListener('click', function () { pay('success'); });
  document.getElementById('payFail').addEventListener('click', function () { pay('fail'); });
  refreshBtn.addEventListener('click', function () { polls = 0; load(); });

  copyBtn.addEventListener('click', function () {
    const code = document.getElementById('keyCode').textContent;
    navigator.clipboard.writeText(code).then(function () {
      copyBtn.textContent = 'Скопировано';
      setTimeout(function () { copyBtn.textContent = 'Скопировать'; }, 1500);
    }, function () {
      // В http без сертификата clipboard недоступен — выделяем, копирует человек
      getSelection().selectAllChildren(document.getElementById('keyCode'));
    });
  });

  function load() {
    return api('GET', '/orders/' + encodeURIComponent(orderId))
      .then(function (data) { render(data.order); })
      .catch(function (e) {
        fail(e.message === 'unknown_order'
          ? 'Заказ ' + orderId + ' не найден.'
          : 'Не удалось получить заказ. Попробуйте обновить страницу.');
      });
  }

  function pay(result) {
    setPayDisabled(true);

    api('POST', '/orders/' + encodeURIComponent(orderId) + '/pay', { result: result })
      .then(load)
      .catch(function () {
        setPayDisabled(false);
        fail('Платёжная система не ответила. Заказ цел, попробуйте ещё раз.');
      });
  }

  function setPayDisabled(disabled) {
    payBox.querySelectorAll('button').forEach(function (btn) { btn.disabled = disabled; });
  }

  function render(order) {
    const status = STATUSES[order.status] || [order.status, 'wait'];
    const product = order.product || {};

    document.getElementById('orderTitle').textContent = product.name || 'Товар';
    document.getElementById('orderId').textContent = order.order_id;

    const image = document.getElementById('orderImage');
    image.hidden = !product.image;
    if (product.image) image.src = product.image;

    const badge = document.getElementById('orderStatus');
    badge.textContent = status[0];
    badge.className = 'order-status order-status--' + status[1];

    document.getElementById('sumBase').textContent = money(order.base_amount);
    document.getElementById('sumTotal').textContent = money(order.amount);

    const hasDiscount = order.discount > 0;
    document.getElementById('sumDiscountRow').hidden = !hasDiscount;
    if (hasDiscount) {
      document.getElementById('sumPromo').textContent = 'Промокод ' + order.promo_code;
      document.getElementById('sumDiscount').textContent = '−' + money(order.discount);
    }

    payBox.hidden = order.status !== 'created';
    if (!payBox.hidden) setPayDisabled(false);

    keyBox.hidden = !order.code;
    if (order.code) document.getElementById('keyCode').textContent = order.code;

    note.hidden = !NOTES[order.status];
    note.textContent = NOTES[order.status] || '';

    card.hidden = false;
    errorBox.hidden = true;

    schedulePoll(order.status);
  }

  // Ждём только те статусы, которые сервер меняет сам
  function schedulePoll(status) {
    clearTimeout(timer);
    const waiting = status === 'paid' || status === 'delivering';
    const stuck = status === 'out_of_stock' || status === 'delivery_failed';

    // Кнопку показываем там, где ждать уже нечего: заказ завис
    // или мы устали переспрашивать
    refreshBtn.hidden = !stuck && !(waiting && polls >= POLL_LIMIT);

    if (!waiting || polls >= POLL_LIMIT) return;

    polls++;
    timer = setTimeout(load, POLL_INTERVAL);
  }

  function money(value) {
    return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ₽';
  }

  function fail(message) {
    clearTimeout(timer);
    card.hidden = true;
    errorBox.textContent = message;
    errorBox.hidden = false;
  }
})();
