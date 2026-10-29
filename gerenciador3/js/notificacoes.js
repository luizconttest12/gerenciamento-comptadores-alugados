function initializeGlobalNotifications() {
  const header = document.querySelector('header');
  if (header?.classList.contains('login-card') || document.getElementById('loginForm')) return;
  if (!header) return;

  let wrapper = header.querySelector('.global-notifications');
  if (!wrapper) {
    wrapper = document.createElement('div');
    wrapper.className = 'global-notifications';
    const profile = header.querySelector('.foto-perfil');
    if (profile) header.insertBefore(wrapper, profile);
    else header.append(wrapper);
  }
  wrapper.innerHTML = `
    <button type="button" class="global-notifications-toggle" aria-label="Notificações de suprimentos" aria-expanded="false">
      <i class="bx bx-bell" aria-hidden="true"></i>
      <span class="global-notifications-badge" hidden>0</span>
    </button>
    <section class="global-notifications-panel" aria-label="Avisos de tinta e toner" hidden>
      <h2>Avisos de suprimentos</h2>
      <ul class="global-notifications-list"><li>Carregando estoque...</li></ul>
    </section>`;

  const toggle = wrapper.querySelector('.global-notifications-toggle');
  const badge = wrapper.querySelector('.global-notifications-badge');
  const panel = wrapper.querySelector('.global-notifications-panel');
  const list = wrapper.querySelector('.global-notifications-list');
  let stockAlerts = [];
  let printerAlerts = [];
  let scanInProgress = false;

  async function requestJson(url) {
    const response = await fetch(url, { credentials: 'same-origin' });
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); }
    catch { throw new Error('Resposta inválida do servidor.'); }
    if (!response.ok) throw new Error(data.message || 'Falha na consulta.');
    return data;
  }

  function render() {
    const alerts = [...stockAlerts, ...printerAlerts];
    badge.textContent = String(alerts.length);
    badge.hidden = alerts.length === 0;
    toggle.setAttribute('aria-label', alerts.length
      ? `${alerts.length} notificações de tinta ou toner`
      : 'Nenhuma notificação de tinta ou toner');
    list.replaceChildren();

    if (!alerts.length) {
      const empty = document.createElement('li');
      empty.textContent = scanInProgress ? 'Verificando impressoras...' : 'Nenhum suprimento abaixo do limite.';
      list.append(empty);
      return;
    }

    alerts.forEach(alert => {
      const item = document.createElement('li');
      const title = document.createElement('strong');
      title.textContent = alert.title;
      const detail = document.createElement('span');
      detail.textContent = alert.detail;
      item.append(title, detail);
      list.append(item);
    });

    if (scanInProgress) {
      const scanning = document.createElement('li');
      scanning.className = 'global-notifications-scanning';
      scanning.textContent = 'Verificando níveis nas impressoras...';
      list.append(scanning);
    }
  }

  async function loadStockAlerts() {
    try {
      const materials = await requestJson('../php/componentes_api.php');
      stockAlerts = Array.isArray(materials)
        ? materials
          .filter(item => ['Tinta', 'Toner'].includes(item.category)
            && Number(item.quantity) <= Number(item.minimum_quantity))
          .map(item => ({
            title: `${item.category}: ${item.name}${item.color ? ` (${item.color})` : ''}`,
            detail: `${item.quantity} un. restantes · mínimo ${item.minimum_quantity} · ${item.location || 'localização não informada'}`
          }))
        : [];
      render();
    } catch {
      stockAlerts = [];
      render();
    }
  }

  async function loadPrinterAlerts(force = false) {
    if (scanInProgress) return;
    if (!force) {
      try {
        const cached = JSON.parse(sessionStorage.getItem('globalPrinterAlerts') || 'null');
        if (cached && Date.now() - cached.checkedAt < 90000) {
          printerAlerts = cached.alerts || [];
          render();
          return;
        }
      } catch {
        sessionStorage.removeItem('globalPrinterAlerts');
      }
    }
    scanInProgress = true;
    printerAlerts = [];
    render();
    try {
      const printers = await requestJson('../php/impressoras_api.php');
      if (!Array.isArray(printers)) return;
      let nextIndex = 0;
      const worker = async () => {
        while (nextIndex < printers.length) {
          const printer = printers[nextIndex++];
          try {
            const result = await requestJson(`../php/impressoras_api.php?action=supplies&id=${encodeURIComponent(printer.id)}`);
            (result.supplies || []).forEach(supply => {
              if (supply.percentage === null || supply.percentage === undefined || supply.percentage === '') return;
              const percentage = Number(supply.percentage);
              if (Number.isFinite(percentage) && percentage <= 30) {
                printerAlerts.push({
                  title: printer.modelo,
                  detail: `${supply.description || 'Suprimento'}: ${percentage}%`
                });
              }
            });
          } catch {
            // Equipamentos sem SNMP disponível não geram alerta de nível.
          }
          render();
        }
      };
      await Promise.all(Array.from({ length: Math.min(3, printers.length) }, worker));
      sessionStorage.setItem('globalPrinterAlerts', JSON.stringify({
        checkedAt: Date.now(),
        alerts: printerAlerts
      }));
    } catch {
      // O aviso de estoque continua disponível mesmo se a consulta SNMP falhar.
    } finally {
      scanInProgress = false;
      render();
    }
  }

  toggle.addEventListener('click', () => {
    const opening = panel.hidden;
    panel.hidden = !opening;
    toggle.setAttribute('aria-expanded', String(opening));
    if (opening) void loadPrinterAlerts(true);
  });
  document.addEventListener('click', event => {
    if (!event.target.closest('.global-notifications')) {
      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
    }
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
    }
  });

  void loadStockAlerts();
  void loadPrinterAlerts();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeGlobalNotifications, { once: true });
} else {
  initializeGlobalNotifications();
}
