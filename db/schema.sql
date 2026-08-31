-- Схема магазина. Всё на InnoDB: нужны транзакции и построчные блокировки,
-- без них однократную выдачу под гонками не удержать.
--
-- Общий принцип: гарантии живут в БД (UNIQUE-индексы и условные UPDATE),
-- а не в коде на PHP. Проверка «сначала SELECT, потом решаем» под параллельными
-- запросами не работает — между чтением и записью успевает влезть сосед.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku       VARCHAR(64)  NOT NULL,
  name      VARCHAR(255) NOT NULL,
  type      VARCHAR(32)  NOT NULL,
  price     INT UNSIGNED NOT NULL COMMENT 'цена в копейках не нужна, в ТЗ целые рубли',
  currency  CHAR(3)      NOT NULL DEFAULT 'RUB',
  image     VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id      VARCHAR(32)  NOT NULL COMMENT 'ord_00123 — то, что уходит наружу и в вебхук',
  product_id     INT UNSIGNED NOT NULL,
  -- цену фиксируем в момент создания: прайс может поменяться, а заказ уже посчитан
  amount         INT UNSIGNED NOT NULL COMMENT 'к оплате, после скидки',
  base_amount    INT UNSIGNED NOT NULL COMMENT 'цена до скидки',
  promo_code     VARCHAR(64)  DEFAULT NULL,
  discount       INT UNSIGNED NOT NULL DEFAULT 0,
  status         ENUM('created','paid','delivering','delivered',
                      'payment_failed','out_of_stock','delivery_failed')
                 NOT NULL DEFAULT 'created',
  delivered_code VARCHAR(64)  DEFAULT NULL,
  -- токен приходит с клиента и гасит двойной клик по «Купить»:
  -- второй такой же запрос упирается в UNIQUE и получает тот же заказ
  client_token   VARCHAR(64)  DEFAULT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_public_id (public_id),
  UNIQUE KEY uniq_client_token (client_token),
  KEY idx_status (status),
  CONSTRAINT fk_orders_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Журнал вебхуков. Он же — механизм идемпотентности: повторная доставка
-- спотыкается об UNIQUE(event_id) и дальше не идёт.
CREATE TABLE IF NOT EXISTS webhook_events (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id     VARCHAR(64)  NOT NULL,
  order_public VARCHAR(32)  NOT NULL,
  status       VARCHAR(16)  NOT NULL,
  amount       INT UNSIGNED DEFAULT NULL,
  payload      JSON         NOT NULL,
  -- вебхук может прийти раньше, чем создан заказ: такое событие лежит
  -- необработанным и применяется в момент создания заказа
  applied_at   DATETIME     DEFAULT NULL,
  received_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_event (event_id),
  KEY idx_pending (order_public, applied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Наши обращения к поставщику. request_id детерминированный (от номера заказа
-- и номера попытки), поэтому повтор после таймаута уходит с тем же request_id
-- и поставщик обязан вернуть тот же код.
CREATE TABLE IF NOT EXISTS delivery_attempts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id  VARCHAR(64)  NOT NULL,
  order_id    INT UNSIGNED NOT NULL,
  supplier    CHAR(1)      NOT NULL COMMENT 'A — основной, B — резервный',
  outcome     ENUM('pending','ok','out_of_stock','error','timeout') NOT NULL DEFAULT 'pending',
  code        VARCHAR(64)  DEFAULT NULL,
  note        VARCHAR(255) DEFAULT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_request (request_id),
  KEY idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Склад заглушки-поставщика. Это «та сторона», наш код в него не лезет,
-- ходит только через HTTP по контракту.
CREATE TABLE IF NOT EXISTS supplier_stock (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier    CHAR(1)      NOT NULL,
  code        VARCHAR(64)  NOT NULL,
  -- занятость кода и идемпотентность выдачи — один и тот же индекс:
  -- код не может уйти в два request_id, а повтор найдёт свой прежний код
  taken_by    VARCHAR(64)  DEFAULT NULL,
  taken_at    DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_supplier_code (supplier, code),
  UNIQUE KEY uniq_taken_by (taken_by),
  KEY idx_free (supplier, taken_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS promo_codes (
  code      VARCHAR(64)  NOT NULL,
  type      ENUM('percent','amount') NOT NULL,
  value     INT UNSIGNED NOT NULL,
  max_uses  INT UNSIGNED NOT NULL,
  used      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Кто и на какой заказ применил код. UNIQUE(code, order_id) не даёт
-- накрутить лимит повторными запросами по одному заказу.
CREATE TABLE IF NOT EXISTS promo_redemptions (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(64)  NOT NULL,
  order_id   INT UNSIGNED NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_code_order (code, order_id),
  CONSTRAINT fk_redemption_code FOREIGN KEY (code) REFERENCES promo_codes (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
