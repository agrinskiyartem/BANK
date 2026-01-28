(function () {
  const config = window.APP_CONFIG || {};
  const timeoutSeconds = Number(config.timeoutSeconds || 120);
  const warningSeconds = Number(config.warningSeconds || 30);
  const lastActivity = Number(config.lastActivity || Date.now() / 1000);

  const warningElement = document.getElementById('session-warning');

  function showWarning() {
    if (warningElement) {
      warningElement.classList.add('session-warning--active');
    }
  }

  function hideWarning() {
    if (warningElement) {
      warningElement.classList.remove('session-warning--active');
    }
  }

  function scheduleSessionTimers() {
    const now = Math.floor(Date.now() / 1000);
    const elapsed = now - lastActivity;
    const warningAt = Math.max(timeoutSeconds - warningSeconds, 0);

    const warningDelay = Math.max(warningAt - elapsed, 0) * 1000;
    const logoutDelay = Math.max(timeoutSeconds - elapsed, 0) * 1000;

    if (warningDelay > 0) {
      setTimeout(showWarning, warningDelay);
    } else if (config.warningActive) {
      showWarning();
    }

    if (logoutDelay > 0) {
      setTimeout(() => {
        hideWarning();
        window.location.href = '/index.php?session=expired';
      }, logoutDelay);
    }
  }

  scheduleSessionTimers();

  function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  async function fetchJson(url, options = {}) {
    const headers = Object.assign(
      {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrfToken(),
      },
      options.headers || {}
    );

    const response = await fetch(url, Object.assign({}, options, { headers }));
    const isJson = response.headers.get('content-type')?.includes('application/json');
    const data = isJson ? await response.json() : await response.text();

    if (!response.ok) {
      const error = new Error('Request failed');
      error.status = response.status;
      error.data = data;
      throw error;
    }

    return data;
  }

  async function postJson(url, body = {}, options = {}) {
    return fetchJson(url, Object.assign({ method: 'POST', body: JSON.stringify(body) }, options));
  }

  window.App = {
    fetchJson,
    postJson,
    showWarning,
    hideWarning,
  };
})();
