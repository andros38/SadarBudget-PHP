(function () {
  'use strict';

  const THEME_STORAGE_KEY = 'sadarbudget-theme';
  const LEGACY_THEME_STORAGE_KEY = 'keuanganku-theme';

  function readStoredTheme() {
    try {
      let storedTheme = window.localStorage.getItem(THEME_STORAGE_KEY);
      if (storedTheme !== 'light' && storedTheme !== 'dark') {
        storedTheme = window.localStorage.getItem(LEGACY_THEME_STORAGE_KEY);
        if (storedTheme === 'light' || storedTheme === 'dark') {
          window.localStorage.setItem(THEME_STORAGE_KEY, storedTheme);
        }
      }
      return storedTheme === 'light' || storedTheme === 'dark' ? storedTheme : null;
    } catch (error) {
      return null;
    }
  }

  function storeTheme(theme) {
    try {
      window.localStorage.setItem(THEME_STORAGE_KEY, theme);
      window.localStorage.removeItem(LEGACY_THEME_STORAGE_KEY);
    } catch (error) {
      // Tema tetap diterapkan untuk sesi aktif walaupun storage browser dibatasi.
    }
  }

  function systemTheme() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
      ? 'dark'
      : 'light';
  }

  function applyTheme(theme, options) {
    const selectedTheme = theme === 'dark' ? 'dark' : 'light';
    const settings = Object.assign({ persist: false, animate: false }, options || {});
    const root = document.documentElement;

    if (settings.animate) {
      root.classList.add('theme-transition');
      window.setTimeout(function () {
        root.classList.remove('theme-transition');
      }, 240);
    }

    root.setAttribute('data-theme', selectedTheme);
    root.style.colorScheme = selectedTheme;

    const themeMeta = document.getElementById('themeColor');
    if (themeMeta) {
      themeMeta.setAttribute('content', selectedTheme === 'dark' ? '#0b1120' : '#f4f7fb');
    }

    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
      const nextThemeLabel = selectedTheme === 'dark'
        ? 'Aktifkan tema terang'
        : 'Aktifkan tema gelap';
      button.setAttribute('aria-label', nextThemeLabel);
      button.setAttribute('title', nextThemeLabel);
      button.setAttribute('aria-pressed', String(selectedTheme === 'dark'));

      const accessibleLabel = button.querySelector('[data-theme-label]');
      if (accessibleLabel) accessibleLabel.textContent = nextThemeLabel;
    });

    if (settings.persist) storeTheme(selectedTheme);

    window.dispatchEvent(new CustomEvent('sadarbudget:themechange', {
      detail: { theme: selectedTheme }
    }));
  }

  function initTheme() {
    const rootTheme = document.documentElement.getAttribute('data-theme');
    applyTheme(rootTheme === 'dark' ? 'dark' : 'light');

    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
      button.addEventListener('click', function () {
        const currentTheme = document.documentElement.getAttribute('data-theme') === 'dark'
          ? 'dark'
          : 'light';
        applyTheme(currentTheme === 'dark' ? 'light' : 'dark', {
          persist: true,
          animate: true
        });
      });
    });

    if (window.matchMedia) {
      const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
      const handleSystemThemeChange = function (event) {
        if (!readStoredTheme()) {
          applyTheme(event.matches ? 'dark' : 'light', { animate: true });
        }
      };

      if (typeof mediaQuery.addEventListener === 'function') {
        mediaQuery.addEventListener('change', handleSystemThemeChange);
      } else if (typeof mediaQuery.addListener === 'function') {
        mediaQuery.addListener(handleSystemThemeChange);
      }
    }
  }

  function initNavigation() {
    const toggle = document.querySelector('.nav-toggle');
    const nav = document.getElementById('primary-navigation');
    if (!toggle || !nav) return;

    toggle.addEventListener('click', function () {
      const isOpen = nav.classList.toggle('is-open');
      toggle.classList.toggle('is-open', isOpen);
      toggle.setAttribute('aria-expanded', String(isOpen));
      toggle.setAttribute('aria-label', isOpen ? 'Tutup menu navigasi' : 'Buka menu navigasi');
    });

    nav.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        nav.classList.remove('is-open');
        toggle.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
      });
    });
  }

  function initMobileSheets() {
    const triggers = Array.from(document.querySelectorAll('[data-mobile-sheet-trigger]'));
    const sheets = Array.from(document.querySelectorAll('.mobile-sheet'));
    const backdrop = document.querySelector('[data-mobile-sheet-backdrop]');
    if (!triggers.length || !sheets.length || !backdrop) return;

    let activeSheet = null;
    let activeTrigger = null;
    let previousFocus = null;

    function setTriggerState(trigger, isOpen) {
      if (!trigger) return;
      trigger.setAttribute('aria-expanded', String(isOpen));
    }

    function closeSheet(restoreFocus) {
      if (!activeSheet) return;

      const sheetToClose = activeSheet;
      const triggerToReset = activeTrigger;
      sheetToClose.classList.remove('is-visible');
      backdrop.classList.remove('is-visible');
      document.body.classList.remove('mobile-sheet-open');
      setTriggerState(triggerToReset, false);
      activeSheet = null;
      activeTrigger = null;

      window.setTimeout(function () {
        sheetToClose.hidden = true;
        backdrop.hidden = true;
        if (restoreFocus !== false && previousFocus && typeof previousFocus.focus === 'function') {
          previousFocus.focus();
        }
      }, 180);
    }

    function openSheet(trigger) {
      const sheetId = trigger.getAttribute('data-mobile-sheet-trigger');
      const sheet = document.getElementById(sheetId);
      if (!sheet) return;

      if (activeSheet === sheet) {
        closeSheet();
        return;
      }
      if (activeSheet) closeSheet(false);

      previousFocus = document.activeElement;
      activeSheet = sheet;
      activeTrigger = trigger;
      sheet.hidden = false;
      backdrop.hidden = false;
      document.body.classList.add('mobile-sheet-open');
      setTriggerState(trigger, true);

      window.requestAnimationFrame(function () {
        backdrop.classList.add('is-visible');
        sheet.classList.add('is-visible');
        sheet.focus();
      });
    }

    triggers.forEach(function (trigger) {
      trigger.addEventListener('click', function () {
        openSheet(trigger);
      });
    });

    sheets.forEach(function (sheet) {
      sheet.querySelectorAll('[data-mobile-sheet-close]').forEach(function (button) {
        button.addEventListener('click', function () { closeSheet(); });
      });
      sheet.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () { closeSheet(false); });
      });
      sheet.querySelectorAll('[data-logout-form]').forEach(function (form) {
        form.addEventListener('submit', function () { closeSheet(false); });
      });
    });

    backdrop.addEventListener('click', function () { closeSheet(); });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && activeSheet) closeSheet();
    });

    const desktopMedia = window.matchMedia('(min-width: 901px)');
    const closeOnDesktop = function (event) {
      if (event.matches && activeSheet) closeSheet(false);
    };
    if (typeof desktopMedia.addEventListener === 'function') {
      desktopMedia.addEventListener('change', closeOnDesktop);
    } else if (typeof desktopMedia.addListener === 'function') {
      desktopMedia.addListener(closeOnDesktop);
    }
  }

  function initLogoutModal() {
    const modal = document.getElementById('logoutModal');
    const logoutForms = document.querySelectorAll('[data-logout-form]');

    // Tanpa JavaScript, form pada header tetap dikirim langsung ke logout.php.
    if (!modal || !logoutForms.length) return;

    const closeButtons = modal.querySelectorAll('[data-logout-close]');
    const dialog = modal.querySelector('[role="dialog"]');
    const confirmButton = modal.querySelector('.modal-actions button[type="submit"]');
    let previousFocus = null;

    function openModal() {
      previousFocus = document.activeElement;
      if (previousFocus && typeof previousFocus.closest === 'function' && previousFocus.closest('.mobile-sheet')) {
        previousFocus = document.querySelector('[data-mobile-sheet-trigger="mobileAccountSheet"]');
      }
      modal.hidden = false;
      document.body.classList.add('modal-open');

      window.requestAnimationFrame(function () {
        modal.classList.add('is-visible');
        if (dialog) dialog.focus();
        if (confirmButton) confirmButton.focus();
      });
    }

    function closeModal() {
      modal.classList.remove('is-visible');
      document.body.classList.remove('modal-open');

      window.setTimeout(function () {
        modal.hidden = true;
        if (previousFocus && typeof previousFocus.focus === 'function') {
          previousFocus.focus();
        }
      }, 180);
    }

    logoutForms.forEach(function (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        openModal();
      });
    });

    closeButtons.forEach(function (button) {
      button.addEventListener('click', closeModal);
    });

    modal.addEventListener('click', function (event) {
      if (event.target === modal) closeModal();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !modal.hidden) closeModal();
    });
  }

  function currencyDigits(value) {
    return String(value || '').replace(/[^0-9]/g, '').replace(/^0+(?=\d)/, '');
  }

  function formatCurrencyDigits(value) {
    const digits = currencyDigits(value);
    if (!digits) return '';
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  function validateCurrencyInput(input, digits) {
    input.setCustomValidity('');
    if (!digits) return;

    const amount = Number(digits);
    const min = Number(input.dataset.min || 0);
    const max = input.dataset.max ? Number(input.dataset.max) : null;

    if (Number.isFinite(min) && amount < min) {
      input.setCustomValidity('Nominal minimal Rp ' + formatCurrencyDigits(String(min)) + '.');
    } else if (max !== null && Number.isFinite(max) && amount > max) {
      input.setCustomValidity('Nominal maksimal Rp ' + formatCurrencyDigits(String(Math.floor(max))) + '.');
    }
  }

  function initCurrencyInputs() {
    const inputs = document.querySelectorAll('[data-currency-input]');
    if (!inputs.length) return;

    inputs.forEach(function (input) {
      const serverCurrencyValue = input.dataset.serverCurrency || '';
      let userEditedCurrency = false;

      function refresh() {
        const previousValue = input.value;
        const previousCaret = typeof input.selectionStart === 'number' ? input.selectionStart : previousValue.length;
        const digitsBeforeCaret = currencyDigits(previousValue.slice(0, previousCaret)).length;
        const digits = currencyDigits(previousValue);
        const formatted = formatCurrencyDigits(digits);
        input.value = formatted;
        validateCurrencyInput(input, digits);

        if (document.activeElement === input && typeof input.setSelectionRange === 'function') {
          let caret = 0;
          let digitCount = 0;
          while (caret < formatted.length && digitCount < digitsBeforeCaret) {
            if (/\d/.test(formatted.charAt(caret))) digitCount += 1;
            caret += 1;
          }
          input.setSelectionRange(caret, caret);
        }
      }

      function restoreServerCurrency() {
        if (!serverCurrencyValue || userEditedCurrency) return;
        input.value = serverCurrencyValue;
        refresh();
      }

      refresh();
      input.addEventListener('input', function () {
        userEditedCurrency = true;
        refresh();
      });
      input.addEventListener('blur', refresh);
      input.addEventListener('invalid', refresh);

      // Browser dapat memulihkan nilai form lama setelah HTML selesai dirender.
      // Untuk input target yang berasal dari database, paksa kembali nilai server
      // selama pengguna belum mengetik pada sesi halaman ini.
      if (serverCurrencyValue) {
        window.setTimeout(restoreServerCurrency, 0);
        window.setTimeout(restoreServerCurrency, 120);
        window.addEventListener('pageshow', restoreServerCurrency, { once: true });
      }
    });
  }

  function initBalanceChart() {
    const canvas = document.getElementById('balanceChart');
    if (!canvas || !Array.isArray(window.balanceChartData)) return;

    const ctx = canvas.getContext('2d');
    const data = window.balanceChartData;

    function cssColor(variableName, fallback) {
      const value = window.getComputedStyle(document.documentElement)
        .getPropertyValue(variableName)
        .trim();
      return value || fallback;
    }

    function chartColors() {
      return {
        grid: cssColor('--chart-grid', '#e7ecf4'),
        label: cssColor('--chart-label', '#758198'),
        line: cssColor('--chart-line', '#2563eb'),
        point: cssColor('--chart-point', '#ffffff'),
        fillStart: cssColor('--chart-fill-start', 'rgba(37, 99, 235, .24)'),
        fillEnd: cssColor('--chart-fill-end', 'rgba(37, 99, 235, .015)')
      };
    }

    function formatRp(value, compact) {
      if (compact) {
        return new Intl.NumberFormat('id-ID', {
          notation: 'compact',
          maximumFractionDigits: 1
        }).format(value);
      }
      return 'Rp ' + Math.round(value).toLocaleString('id-ID');
    }

    function draw() {
      const rect = canvas.parentElement.getBoundingClientRect();
      const dpr = Math.max(1, window.devicePixelRatio || 1);
      const width = Math.max(240, Math.floor(rect.width));
      const isSmall = width < 520;
      const height = isSmall ? 158 : 220;
      const colors = chartColors();

      canvas.style.width = width + 'px';
      canvas.style.height = height + 'px';
      canvas.width = Math.floor(width * dpr);
      canvas.height = Math.floor(height * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, width, height);

      const pad = {
        left: isSmall ? 46 : 76,
        right: isSmall ? 12 : 24,
        top: 20,
        bottom: isSmall ? 42 : 48
      };
      const plotW = width - pad.left - pad.right;
      const plotH = height - pad.top - pad.bottom;
      const values = data.map(function (item) { return Number(item.balance) || 0; });
      let min = Math.min.apply(null, [0].concat(values));
      let max = Math.max.apply(null, [0].concat(values));
      if (min === max) { max += 1; min -= 1; }
      const range = max - min;
      max += range * 0.12;
      min -= range * 0.12;

      ctx.font = (isSmall ? '10px' : '12px') + ' system-ui, sans-serif';
      ctx.textBaseline = 'middle';

      for (let i = 0; i <= 4; i += 1) {
        const y = pad.top + (plotH * i / 4);
        const value = max - ((max - min) * i / 4);
        ctx.beginPath();
        ctx.strokeStyle = colors.grid;
        ctx.lineWidth = 1;
        ctx.moveTo(pad.left, y);
        ctx.lineTo(width - pad.right, y);
        ctx.stroke();
        ctx.fillStyle = colors.label;
        ctx.textAlign = 'right';
        ctx.fillText(formatRp(value, isSmall), pad.left - 8, y);
      }

      const points = data.map(function (item, index) {
        const x = data.length === 1 ? pad.left + plotW / 2 : pad.left + (plotW * index / (data.length - 1));
        const y = pad.top + ((max - Number(item.balance)) / (max - min)) * plotH;
        return { x: x, y: y, label: item.label, value: Number(item.balance) };
      });

      if (!points.length) return;

      const gradient = ctx.createLinearGradient(0, pad.top, 0, height - pad.bottom);
      gradient.addColorStop(0, colors.fillStart);
      gradient.addColorStop(1, colors.fillEnd);
      ctx.beginPath();
      ctx.moveTo(points[0].x, height - pad.bottom);
      points.forEach(function (point) { ctx.lineTo(point.x, point.y); });
      ctx.lineTo(points[points.length - 1].x, height - pad.bottom);
      ctx.closePath();
      ctx.fillStyle = gradient;
      ctx.fill();

      ctx.beginPath();
      points.forEach(function (point, index) {
        if (index === 0) ctx.moveTo(point.x, point.y);
        else ctx.lineTo(point.x, point.y);
      });
      ctx.strokeStyle = colors.line;
      ctx.lineWidth = isSmall ? 2.5 : 3;
      ctx.lineJoin = 'round';
      ctx.lineCap = 'round';
      ctx.stroke();

      points.forEach(function (point, index) {
        ctx.beginPath();
        ctx.arc(point.x, point.y, isSmall ? 3.5 : 4.5, 0, Math.PI * 2);
        ctx.fillStyle = colors.point;
        ctx.fill();
        ctx.strokeStyle = colors.line;
        ctx.lineWidth = 2;
        ctx.stroke();

        if (!isSmall || index % 2 === 0 || index === points.length - 1) {
          ctx.fillStyle = colors.label;
          ctx.textAlign = 'center';
          const label = isSmall ? point.label.replace(/\s\d{4}$/, '') : point.label;
          ctx.fillText(label, point.x, height - 19);
        }
      });
    }

    draw();
    let resizeTimer;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(draw, 120);
    });
    window.addEventListener('sadarbudget:themechange', draw);
  }


  function initResponsiveDisclosures() {
    const disclosures = Array.from(document.querySelectorAll('[data-responsive-disclosure]'));
    if (!disclosures.length) return;

    const media = window.matchMedia('(max-width: 900px)');
    const sync = function () {
      disclosures.forEach(function (details) {
        const keepOpen = details.getAttribute('data-keep-open') === 'true';
        if (media.matches && !keepOpen && !details.dataset.mobilePrepared) {
          details.open = false;
          details.dataset.mobilePrepared = 'true';
        }
        if (!media.matches) {
          details.open = true;
          delete details.dataset.mobilePrepared;
        }
      });
    };

    sync();
    if (typeof media.addEventListener === 'function') media.addEventListener('change', sync);
    else if (typeof media.addListener === 'function') media.addListener(sync);
  }

  function initGoalActionAccordions() {
    document.querySelectorAll('.goal-action-grid').forEach(function (grid) {
      const panels = Array.from(grid.querySelectorAll(':scope > details'));
      panels.forEach(function (panel) {
        panel.addEventListener('toggle', function () {
          if (!panel.open) return;
          panels.forEach(function (other) {
            if (other !== panel) other.open = false;
          });
        });
      });
    });
  }


  function initGoalModals() {
    const modals = Array.from(document.querySelectorAll('[data-goal-modal]'));
    if (!modals.length) return;

    const openTriggers = Array.from(document.querySelectorAll('[data-goal-modal-open]'));
    let activeModal = null;
    let previousFocus = null;
    let closeTimer = null;

    function focusableElements(modal) {
      return Array.from(modal.querySelectorAll(
        'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )).filter(function (element) {
        return !element.hidden && element.offsetParent !== null;
      });
    }

    function activateTab(modal, tabName, focusTab) {
      const tabs = Array.from(modal.querySelectorAll('[data-goal-tab]'));
      const panels = Array.from(modal.querySelectorAll('[data-goal-panel]'));
      if (!tabs.length || !panels.length) return;

      const availableNames = tabs.map(function (tab) {
        return tab.getAttribute('data-goal-tab');
      });
      const selectedName = availableNames.indexOf(tabName) >= 0
        ? tabName
        : availableNames[0];

      tabs.forEach(function (tab) {
        const isActive = tab.getAttribute('data-goal-tab') === selectedName;
        tab.classList.toggle('is-active', isActive);
        tab.setAttribute('aria-selected', String(isActive));
        tab.setAttribute('tabindex', isActive ? '0' : '-1');
        if (isActive && focusTab) tab.focus();
      });

      panels.forEach(function (panel) {
        const isActive = panel.getAttribute('data-goal-panel') === selectedName;
        panel.hidden = !isActive;
        panel.classList.toggle('is-active', isActive);
      });

      modal.setAttribute('data-active-goal-tab', selectedName);
    }

    function closeModal(options) {
      if (!activeModal) return;
      const settings = Object.assign({ restoreFocus: true, immediate: false }, options || {});
      const modalToClose = activeModal;
      activeModal = null;
      window.clearTimeout(closeTimer);
      modalToClose.classList.remove('is-visible');
      document.body.classList.remove('goal-modal-open');

      const finish = function () {
        modalToClose.hidden = true;
        if (settings.restoreFocus && previousFocus && typeof previousFocus.focus === 'function') {
          previousFocus.focus();
        }
        previousFocus = null;
      };

      if (settings.immediate) finish();
      else closeTimer = window.setTimeout(finish, 180);
    }

    function openModal(modal, requestedTab, trigger) {
      if (!modal) return;
      if (activeModal && activeModal !== modal) {
        closeModal({ restoreFocus: false, immediate: true });
      }

      window.clearTimeout(closeTimer);
      previousFocus = trigger || document.activeElement;
      activeModal = modal;
      modal.hidden = false;
      document.body.classList.add('goal-modal-open');

      const defaultTab = requestedTab
        || modal.getAttribute('data-goal-default-tab')
        || 'overview';
      activateTab(modal, defaultTab, false);

      window.requestAnimationFrame(function () {
        modal.classList.add('is-visible');
        const dialog = modal.querySelector('[role="dialog"]');
        if (dialog) dialog.focus();
      });
    }

    openTriggers.forEach(function (trigger) {
      trigger.addEventListener('click', function () {
        const modalId = trigger.getAttribute('data-goal-modal-open');
        const modal = document.getElementById(modalId);
        const requestedTab = trigger.getAttribute('data-goal-tab-target') || '';
        openModal(modal, requestedTab, trigger);
      });
    });

    modals.forEach(function (modal) {
      modal.querySelectorAll('[data-goal-modal-close]').forEach(function (button) {
        button.addEventListener('click', function () { closeModal(); });
      });

      modal.querySelectorAll('[data-goal-tab]').forEach(function (tab) {
        tab.addEventListener('click', function () {
          activateTab(modal, tab.getAttribute('data-goal-tab') || 'overview', false);
        });

        tab.addEventListener('keydown', function (event) {
          if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
          const tabs = Array.from(modal.querySelectorAll('[data-goal-tab]'));
          const currentIndex = tabs.indexOf(tab);
          if (currentIndex < 0) return;
          event.preventDefault();
          const direction = event.key === 'ArrowRight' ? 1 : -1;
          const nextIndex = (currentIndex + direction + tabs.length) % tabs.length;
          const nextTab = tabs[nextIndex];
          activateTab(modal, nextTab.getAttribute('data-goal-tab') || 'overview', true);
        });
      });

      modal.querySelectorAll('[data-goal-tab-target]:not([data-goal-modal-open])').forEach(function (button) {
        button.addEventListener('click', function () {
          activateTab(modal, button.getAttribute('data-goal-tab-target') || 'overview', true);
        });
      });

      modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
      });
    });

    document.addEventListener('keydown', function (event) {
      if (!activeModal) return;

      if (event.key === 'Escape') {
        event.preventDefault();
        closeModal();
        return;
      }

      if (event.key !== 'Tab') return;
      const focusable = focusableElements(activeModal);
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      const dialog = activeModal.querySelector('[role="dialog"]');
      const activeElement = document.activeElement;

      if (event.shiftKey && (activeElement === first || activeElement === dialog)) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (activeElement === last || activeElement === dialog)) {
        event.preventDefault();
        first.focus();
      }
    });

    const autoOpenModal = modals.find(function (modal) {
      return modal.getAttribute('data-goal-modal-auto-open') === 'true';
    });
    if (autoOpenModal) {
      window.setTimeout(function () {
        openModal(autoOpenModal, autoOpenModal.getAttribute('data-goal-default-tab') || '', null);
      }, 0);
    }
  }


  function initAnnualChart() {
    const canvas = document.getElementById('annualChart');
    if (!canvas || !Array.isArray(window.annualChartData)) return;

    const ctx = canvas.getContext('2d');
    const data = window.annualChartData;

    function cssColor(variableName, fallback) {
      const value = window.getComputedStyle(document.documentElement)
        .getPropertyValue(variableName)
        .trim();
      return value || fallback;
    }

    function draw() {
      const container = canvas.parentElement;
      const rect = container.getBoundingClientRect();
      const width = Math.max(280, Math.floor(rect.width));
      const compact = width < 560;
      const height = compact ? 220 : 280;
      const dpr = Math.max(1, window.devicePixelRatio || 1);
      const incomeColor = cssColor('--success', '#16a34a');
      const expenseColor = cssColor('--danger', '#dc2626');
      const gridColor = cssColor('--chart-grid', '#e7ecf4');
      const labelColor = cssColor('--chart-label', '#758198');

      canvas.style.width = width + 'px';
      canvas.style.height = height + 'px';
      canvas.width = Math.floor(width * dpr);
      canvas.height = Math.floor(height * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, width, height);

      const padding = { left: compact ? 45 : 64, right: 12, top: 16, bottom: 38 };
      const plotWidth = width - padding.left - padding.right;
      const plotHeight = height - padding.top - padding.bottom;
      const values = data.flatMap(function (row) {
        return [Number(row.income) || 0, Number(row.expense) || 0];
      });
      const maxValue = Math.max.apply(null, [1].concat(values)) * 1.12;

      ctx.font = (compact ? '9px' : '11px') + ' system-ui, sans-serif';
      ctx.textBaseline = 'middle';

      for (let index = 0; index <= 4; index += 1) {
        const y = padding.top + (plotHeight * index / 4);
        const value = maxValue - (maxValue * index / 4);
        ctx.beginPath();
        ctx.strokeStyle = gridColor;
        ctx.lineWidth = 1;
        ctx.moveTo(padding.left, y);
        ctx.lineTo(width - padding.right, y);
        ctx.stroke();
        ctx.fillStyle = labelColor;
        ctx.textAlign = 'right';
        ctx.fillText(new Intl.NumberFormat('id-ID', {
          notation: 'compact',
          maximumFractionDigits: 1
        }).format(value), padding.left - 7, y);
      }

      const groupWidth = plotWidth / data.length;
      const barWidth = Math.max(4, Math.min(compact ? 8 : 13, groupWidth * 0.28));

      data.forEach(function (row, index) {
        const centerX = padding.left + (groupWidth * index) + (groupWidth / 2);
        const income = Number(row.income) || 0;
        const expense = Number(row.expense) || 0;
        const incomeHeight = (income / maxValue) * plotHeight;
        const expenseHeight = (expense / maxValue) * plotHeight;

        ctx.fillStyle = incomeColor;
        ctx.fillRect(centerX - barWidth - 1, padding.top + plotHeight - incomeHeight, barWidth, incomeHeight);
        ctx.fillStyle = expenseColor;
        ctx.fillRect(centerX + 1, padding.top + plotHeight - expenseHeight, barWidth, expenseHeight);

        ctx.fillStyle = labelColor;
        ctx.textAlign = 'center';
        ctx.fillText(String(row.label || '').replace(/\s\d{4}$/, ''), centerX, height - 17);
      });
    }

    draw();
    let timer;
    window.addEventListener('resize', function () {
      clearTimeout(timer);
      timer = window.setTimeout(draw, 120);
    });
    window.addEventListener('sadarbudget:themechange', draw);
  }

  function initDesktopCreateMenu() {
    const menus = Array.from(document.querySelectorAll('[data-desktop-create-menu]'));
    if (!menus.length) return;

    menus.forEach(function (menu) {
      menu.querySelectorAll('a[href]').forEach(function (link) {
        link.addEventListener('click', function () {
          menu.open = false;
        });
      });
    });

    document.addEventListener('click', function (event) {
      menus.forEach(function (menu) {
        if (menu.open && !menu.contains(event.target)) menu.open = false;
      });
    });

    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;
      menus.forEach(function (menu) {
        if (!menu.open) return;
        menu.open = false;
        const summary = menu.querySelector('summary');
        if (summary) summary.focus();
      });
    });
  }

  initTheme();
  initNavigation();
  initDesktopCreateMenu();
  initMobileSheets();
  initLogoutModal();
  initCurrencyInputs();
  initResponsiveDisclosures();
  initGoalActionAccordions();
  initGoalModals();
  initBalanceChart();
  initAnnualChart();
}());
