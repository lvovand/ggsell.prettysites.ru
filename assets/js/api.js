/*
   Общая обёртка над fetch. Все страницы ходят в API одинаково: JSON туда,
   JSON обратно, а ошибку бэка превращаем в исключение — чтобы в вызывающем
   коде был один catch, а не разбор поля status на каждом шагу.
*/

window.api = function (method, path, body) {
  const options = { method: method, headers: {} };

  if (body) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(body);
  }

  // Админские ручки закрыты токеном. Он выставляется один раз при входе,
  // чтобы не таскать заголовок через каждый вызов.
  if (window.api.adminToken) {
    options.headers['X-Admin-Token'] = window.api.adminToken;
  }

  return fetch('/api' + path, options).then(function (response) {
    return response.text().then(function (raw) {
      let data;
      try {
        data = JSON.parse(raw);
      } catch (e) {
        // 500 от php-fpm прилетает html-страницей, а не json
        throw new Error('bad_response');
      }

      if (!response.ok || data.status === 'error') {
        throw new Error(data.reason || 'request_failed');
      }
      return data;
    });
  });
};

window.api.adminToken = '';
