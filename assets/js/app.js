/*
   Поведение витрины: то, что требует ТЗ (карусель, меню каталога,
   переключатель валют, ховеры — они на CSS), два эффекта на курсоре
   (наклон карточек и волна по иконкам сервисов) и покупка — создание
   заказа с переходом на страницу статуса.
*/

(function () {
  'use strict';

  // Всё, что двигается за курсором, отключаем, если система просит меньше движения
  const calmMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ── Карусель баннера ───────────────────────────────────────────
     Слайды переключаются сменой класса is-current, а не сдвигом трека:
     кросс-фейд проще и не ломается, если слайдов станет больше или меньше. */

  const banner = document.getElementById('banner');

  if (banner) {
    const slides = Array.from(banner.querySelectorAll('.banner__slide'));
    const dotsBox = banner.querySelector('.banner__dots');
    const AUTOPLAY = 5000;

    let current = 0;
    let timer = null;

    // Точки рисуем из JS, чтобы их количество всегда совпадало с числом слайдов
    const dots = slides.map(function (_, i) {
      const dot = document.createElement('button');
      dot.type = 'button';
      dot.className = 'banner__dot';
      dot.setAttribute('aria-label', 'Слайд ' + (i + 1));
      dot.addEventListener('click', function () {
        show(i);
        restart();
      });
      dotsBox.appendChild(dot);
      return dot;
    });

    function show(index) {
      // остаток от деления даёт бесконечную прокрутку в обе стороны
      current = (index + slides.length) % slides.length;
      slides.forEach(function (slide, i) {
        slide.classList.toggle('is-current', i === current);
      });
      dots.forEach(function (dot, i) {
        dot.classList.toggle('is-current', i === current);
        dot.setAttribute('aria-current', i === current ? 'true' : 'false');
      });
    }

    function restart() {
      clearInterval(timer);
      timer = setInterval(function () { show(current + 1); }, AUTOPLAY);
    }

    banner.querySelectorAll('.banner__arrow').forEach(function (btn) {
      btn.addEventListener('click', function () {
        show(current + Number(btn.dataset.dir));
        restart();
      });
    });

    // На наведении автопрокрутку ставим на паузу — иначе баннер «убегает»
    // из-под курсора, когда человек читает слайд
    banner.addEventListener('mouseenter', function () { clearInterval(timer); });
    banner.addEventListener('mouseleave', restart);

    // Вкладка в фоне — крутить смысла нет
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) clearInterval(timer);
      else restart();
    });

    show(0);
    restart();
  }

  /* ── Меню каталога ────────────────────────────────────────────── */

  const catalogBtn = document.getElementById('catalogBtn');
  const catalogMenu = document.getElementById('catalogMenu');

  if (catalogBtn && catalogMenu) {
    function setCatalog(open) {
      catalogMenu.hidden = !open;
      catalogBtn.setAttribute('aria-expanded', String(open));
    }

    catalogBtn.addEventListener('click', function (e) {
      e.stopPropagation(); // иначе тот же клик тут же поймает документ и закроет меню
      setCatalog(catalogMenu.hidden);
    });

    // Клик вне меню закрывает. Меню лежит внутри .header, так что достаточно
    // проверить, попал ли клик в шапку.
    document.addEventListener('click', function (e) {
      if (catalogMenu.hidden) return;
      if (!e.target.closest('.header')) setCatalog(false);
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !catalogMenu.hidden) {
        setCatalog(false);
        catalogBtn.focus();
      }
    });

    // Разделы слева: по ТЗ детальная логика меню не оценивается,
    // поэтому просто переносим подсветку активного пункта
    const sideItems = catalogMenu.querySelectorAll('.cat-side__item');
    sideItems.forEach(function (item) {
      item.addEventListener('mouseenter', function () {
        sideItems.forEach(function (i) { i.classList.remove('is-active'); });
        item.classList.add('is-active');
      });
    });
  }

  /* ── Покупка ──────────────────────────────────────────────────────
     Кнопка только создаёт заказ и уводит на страницу статуса — оплата
     и выдача ключа живут там. */

  const PROMO_ERRORS = {
    unknown_promo: 'Такого промокода нет',
    promo_limit_reached: 'Промокод уже разобрали'
  };

  function buy(button, sku, promoCode) {
    if (button.disabled) return;
    button.disabled = true;

    // Токен гасит двойной клик: по нему сервер вернёт уже созданный заказ,
    // а не сделает второй. Привязан к тому, что покупаем, — со сменой
    // промокода это уже другой заказ, и токен нужен новый.
    const key = sku + '|' + (promoCode || '');
    if (button.dataset.tokenKey !== key) {
      button.dataset.tokenKey = key;
      button.dataset.token = 'web_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    }

    api('POST', '/orders', {
      sku: sku,
      client_token: button.dataset.token,
      promo_code: promoCode || null
    }).then(function (data) {
      location.href = 'order.html?id=' + encodeURIComponent(data.order.order_id);
    }).catch(function (e) {
      const text = button.textContent;
      button.textContent = PROMO_ERRORS[e.message] || 'Не получилось, попробуйте ещё';

      setTimeout(function () {
        button.textContent = text;
        button.disabled = false;
      }, 2000);
    });
  }

  // Слушатель один на ряд: карточек много, а поведение у всех кнопок общее
  document.querySelectorAll('.grid').forEach(function (grid) {
    grid.addEventListener('click', function (e) {
      const btn = e.target.closest('.btn--buy');
      if (btn) buy(btn, btn.closest('.card').dataset.sku, '');
    });
  });

  /* ── Блок пополнения: валюта, промокод, оплата ────────────────────
     Сумму держим в переменной, а не в тексте подписи: её меняет промокод,
     и хранить одно и то же число в двух местах не хочется. */

  const topup = document.querySelector('.topup');

  if (topup) {
    const sku = topup.dataset.sku;
    const currency = document.getElementById('currency');
    const amountValue = document.getElementById('amountValue');
    const payButton = document.getElementById('payButton');
    const promoBtn = document.getElementById('promoBtn');
    const promoField = document.getElementById('promoField');
    const promoInput = document.getElementById('promoInput');
    const promoNote = document.getElementById('promoNote');

    const basePrice = 500;  // цена STEAM-TOPUP-500 из каталога
    let amount = basePrice;
    let promo = '';         // код, который сервер подтвердил

    function draw() {
      const cur = currency.querySelector('.currency__btn.is-active').dataset.cur;
      amountValue.textContent = amount + cur;
      payButton.textContent = 'Оплатить ' + amount + cur;
    }

    function note(text, bad) {
      promoNote.textContent = text;
      promoNote.hidden = text === '';
      promoNote.classList.toggle('promo-note--bad', Boolean(bad));
    }

    function checkPromo() {
      const code = promoInput.value.trim().toUpperCase();
      promoInput.value = code;

      if (code === promo) return; // этот код уже посчитан, дёргать сервер незачем

      if (code === '') {
        promo = '';
        amount = basePrice;
        note('');
        draw();
        return;
      }

      // Скидку считает сервер: свою арифметику здесь заводить нельзя,
      // иначе к оплате уйдёт одна сумма, а в заказе окажется другая
      api('POST', '/promo/quote', { code: code, sku: sku }).then(function (data) {
        promo = code;
        amount = data.amount;
        note('Скидка ' + data.discount + ' ₽');
        draw();
      }).catch(function (e) {
        promo = '';
        amount = basePrice;
        note(PROMO_ERRORS[e.message] || 'Промокод не подошёл', true);
        draw();
      });
    }

    promoBtn.addEventListener('click', function () {
      promoBtn.hidden = true;
      promoField.hidden = false;
      promoInput.focus();
    });

    promoInput.addEventListener('blur', checkPromo);
    promoInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') promoInput.blur();
    });

    currency.addEventListener('click', function (e) {
      const btn = e.target.closest('.currency__btn');
      if (!btn) return;

      currency.querySelectorAll('.currency__btn').forEach(function (b) {
        b.classList.toggle('is-active', b === btn);
      });

      // Пересчёта по курсу по ТЗ не требуется — меняем только символ
      draw();
    });

    payButton.addEventListener('click', function () {
      // Берём код из поля, а не из подтверждённого promo: на «Оплатить» могли
      // нажать раньше, чем вернулся предпросмотр. Проверит его всё равно сервер.
      buy(payButton, sku, promoField.hidden ? '' : promoInput.value.trim().toUpperCase());
    });

    draw();
  }

  /* ── Табы над рядом товаров ───────────────────────────────────── */

  document.querySelectorAll('.chips').forEach(function (group) {
    group.addEventListener('click', function (e) {
      const chip = e.target.closest('.chip');
      if (!chip) return;
      group.querySelectorAll('.chip').forEach(function (c) {
        c.classList.toggle('is-active', c === chip);
      });
      // Фильтрация появится вместе с бэкендом — сейчас переключаем только вид
    });
  });

  /* ── 3D-наклон карточек и подсветка за курсором ──────────────────
     Слушатель один на весь ряд, а не по штуке на карточку. Координаты
     пишем в CSS-переменные раз в кадр — саму анимацию считает композитор. */

  if (!calmMotion) {
    const MAX_TILT = 5; // градусов по краю; больше — и карточка «валится»

    document.querySelectorAll('.grid').forEach(function (grid) {
      let card = null;   // карточка под курсором
      let box = null;    // её геометрия, читаем один раз на вход
      let x = 0;
      let y = 0;
      let frame = 0;

      function paint() {
        frame = 0;
        if (!card || !box) return;

        const px = (x - box.left) / box.width;   // 0..1 по горизонтали
        const py = (y - box.top) / box.height;   // 0..1 по вертикали

        card.style.setProperty('--mx', (px * 100).toFixed(1) + '%');
        card.style.setProperty('--my', (py * 100).toFixed(1) + '%');
        card.style.setProperty('--ry', ((px - .5) * 2 * MAX_TILT).toFixed(2) + 'deg');
        card.style.setProperty('--rx', ((.5 - py) * 2 * MAX_TILT).toFixed(2) + 'deg');
      }

      function release(el) {
        if (!el) return;
        el.style.removeProperty('--rx');
        el.style.removeProperty('--ry');
      }

      grid.addEventListener('pointermove', function (e) {
        if (e.pointerType !== 'mouse') return; // на тач-устройствах наклон только мешает

        const hit = e.target.closest('.card');
        if (hit !== card) {
          release(card); // соседняя карточка иначе останется наклонённой
          card = hit;
          box = hit ? hit.getBoundingClientRect() : null;
        }
        if (!card) return;

        x = e.clientX;
        y = e.clientY;
        if (!frame) frame = requestAnimationFrame(paint);
      });

      grid.addEventListener('pointerleave', function () {
        release(card);
        card = null;
        box = null;
      });

      // Прокрутка сдвигает карточку под курсором — геометрию надо перечитать
      window.addEventListener('scroll', function () {
        if (card) box = card.getBoundingClientRect();
      }, { passive: true });
    });
  }

  /* ── Волна по иконкам сервисов ───────────────────────────────────
     Каждой плитке считаем близость курсора по горизонтали: 1 под курсором,
     0 дальше RANGE. Дальше всё делает CSS через --wave. */

  const services = document.querySelector('.services');

  if (services && !calmMotion) {
    const tiles = Array.from(services.querySelectorAll('.service'));
    const RANGE = 230; // px, ширина захвата волны — примерно две плитки в каждую сторону

    let centers = [];
    let x = 0;
    let frame = 0;
    let active = false;

    function measure() {
      centers = tiles.map(function (tile) {
        const r = tile.getBoundingClientRect();
        return r.left + r.width / 2;
      });
    }

    function paint() {
      frame = 0;
      tiles.forEach(function (tile, i) {
        const d = Math.abs(x - centers[i]) / RANGE;
        let f = d >= 1 ? 0 : 1 - d;
        f = f * f * (3 - 2 * f); // сглаживание, иначе волна выходит угловатой
        tile.style.setProperty('--wave', f.toFixed(3));
      });
    }

    services.addEventListener('pointerenter', function (e) {
      if (e.pointerType !== 'mouse') return;
      measure(); // мерим на входе, а не в каждом кадре
      active = true;
    });

    services.addEventListener('pointermove', function (e) {
      if (!active) return;
      x = e.clientX;
      if (!frame) frame = requestAnimationFrame(paint);
    });

    services.addEventListener('pointerleave', function () {
      active = false;
      if (frame) { cancelAnimationFrame(frame); frame = 0; }
      tiles.forEach(function (tile) { tile.style.setProperty('--wave', '0'); });
    });

    window.addEventListener('resize', function () { if (active) measure(); });
  }

})();
