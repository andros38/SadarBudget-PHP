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
    const navId = toggle ? (toggle.getAttribute('aria-controls') || 'primary-navigation') : 'primary-navigation';
    const nav = document.getElementById(navId);
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

  function initInlineFormValidation() {
    const forms = Array.from(document.querySelectorAll('[data-inline-validation]'));
    if (!forms.length) return;

    function errorElement(input) {
      const targetId = input.getAttribute('data-error-target');
      return targetId ? document.getElementById(targetId) : null;
    }

    function confirmationContainer(input) {
      return input.closest('.import-confirmation-check, .clean-confirmation-check, .auto-backup-confirmation');
    }

    function showError(input, message) {
      const feedback = errorElement(input);
      input.setAttribute('aria-invalid', 'true');
      const container = confirmationContainer(input);
      if (container) container.classList.add('has-error');
      if (feedback) {
        feedback.textContent = message;
        feedback.hidden = false;
      }
    }

    function clearError(input) {
      input.removeAttribute('aria-invalid');
      const container = confirmationContainer(input);
      if (container) container.classList.remove('has-error');
      const feedback = errorElement(input);
      if (feedback) {
        feedback.textContent = '';
        feedback.hidden = true;
      }
    }

    function isEmpty(input) {
      if (input.type === 'checkbox') return !input.checked;
      if (input.type === 'file') return !input.files || input.files.length === 0;
      return String(input.value || '').trim() === '';
    }

    forms.forEach(function (form) {
      const inputs = Array.from(form.querySelectorAll('[data-required-message], [data-exact-value]'));

      inputs.forEach(function (input) {
        const eventName = input.type === 'checkbox' || input.type === 'file' || input.tagName === 'SELECT'
          ? 'change'
          : 'input';
        input.addEventListener(eventName, function () {
          clearError(input);
        });
      });

      form.addEventListener('submit', function (event) {
        let firstInvalid = null;

        inputs.forEach(function (input) {
          clearError(input);

          const requiredMessage = input.getAttribute('data-required-message');
          const exactValue = input.getAttribute('data-exact-value');
          let message = '';

          if (requiredMessage && isEmpty(input)) {
            message = requiredMessage;
          } else if (exactValue !== null && String(input.value || '').trim() !== exactValue) {
            message = input.getAttribute('data-exact-message') || ('Tulisan harus sama persis: ' + exactValue + '.');
          }

          if (message !== '') {
            showError(input, message);
            if (!firstInvalid) firstInvalid = input;
          }
        });

        if (firstInvalid) {
          event.preventDefault();
          firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
          window.setTimeout(function () { firstInvalid.focus(); }, 180);
          return;
        }

        const confirmationMessage = form.getAttribute('data-confirm-message');
        if (confirmationMessage && !window.confirm(confirmationMessage)) {
          event.preventDefault();
        }
      });
    });

    const serverInvalid = document.querySelector('[data-inline-validation] [aria-invalid="true"]');
    if (serverInvalid) {
      window.requestAnimationFrame(function () {
        serverInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        window.setTimeout(function () { serverInvalid.focus(); }, 220);
      });
    }
  }

  function initProfilePhotoCrop() {
    const form = document.querySelector('[data-profile-photo-form]');
    const input = document.querySelector('[data-profile-photo-input]');
    const croppedField = document.querySelector('[data-profile-photo-cropped]');
    const submitButton = document.querySelector('[data-profile-photo-submit]');
    const readyNotice = document.querySelector('[data-profile-crop-ready]');
    const errorNotice = document.querySelector('[data-profile-crop-error]');
    const modal = document.querySelector('[data-photo-crop-modal]');
    const card = modal ? modal.querySelector('.photo-crop-card') : null;
    const stage = modal ? modal.querySelector('[data-photo-crop-stage]') : null;
    const image = modal ? modal.querySelector('[data-photo-crop-image]') : null;
    const zoomInput = modal ? modal.querySelector('[data-photo-crop-zoom]') : null;
    const applyButton = modal ? modal.querySelector('[data-photo-crop-apply]') : null;
    const cancelButtons = modal ? Array.from(modal.querySelectorAll('[data-photo-crop-cancel]')) : [];

    if (!form || !input || !croppedField || !submitButton || !modal || !card || !stage || !image || !zoomInput || !applyButton) {
      return;
    }

    let objectUrl = '';
    let naturalWidth = 0;
    let naturalHeight = 0;
    let minimumScale = 1;
    let scale = 1;
    let offsetX = 0;
    let offsetY = 0;
    let dragging = false;
    let dragStartX = 0;
    let dragStartY = 0;
    let dragOriginX = 0;
    let dragOriginY = 0;
    let previousFocus = null;

    function showError(message) {
      if (!errorNotice) return;
      errorNotice.textContent = message;
      errorNotice.hidden = false;
    }

    function clearError() {
      if (!errorNotice) return;
      errorNotice.textContent = '';
      errorNotice.hidden = true;
    }

    function setReady(isReady) {
      submitButton.disabled = !isReady;
      if (readyNotice) readyNotice.hidden = !isReady;
    }

    function revokeObjectUrl() {
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
        objectUrl = '';
      }
    }

    function stageSize() {
      const rect = stage.getBoundingClientRect();
      return Math.max(1, Math.min(rect.width, rect.height));
    }

    function clampOffsets() {
      const size = stageSize();
      const scaledWidth = naturalWidth * scale;
      const scaledHeight = naturalHeight * scale;
      const minimumX = Math.min(0, size - scaledWidth);
      const minimumY = Math.min(0, size - scaledHeight);

      offsetX = Math.min(0, Math.max(minimumX, offsetX));
      offsetY = Math.min(0, Math.max(minimumY, offsetY));
    }

    function renderImage() {
      clampOffsets();
      image.style.width = naturalWidth + 'px';
      image.style.height = naturalHeight + 'px';
      image.style.left = offsetX + 'px';
      image.style.top = offsetY + 'px';
      image.style.transform = 'scale(' + scale + ')';
    }

    function resetCropPosition() {
      const size = stageSize();
      minimumScale = Math.max(size / naturalWidth, size / naturalHeight);
      scale = minimumScale;
      zoomInput.value = '1';
      offsetX = (size - naturalWidth * scale) / 2;
      offsetY = (size - naturalHeight * scale) / 2;
      renderImage();
    }

    function closeModal(options) {
      const settings = Object.assign({ clearSelection: false, restoreFocus: true }, options || {});
      modal.classList.remove('is-visible');
      document.body.classList.remove('photo-crop-open');

      window.setTimeout(function () {
        modal.hidden = true;
        if (settings.clearSelection) {
          input.value = '';
          croppedField.value = '';
          setReady(false);
          revokeObjectUrl();
        }
        if (settings.restoreFocus && previousFocus && typeof previousFocus.focus === 'function') {
          previousFocus.focus();
        }
      }, 170);
    }

    function openModal() {
      previousFocus = document.activeElement;
      modal.hidden = false;
      document.body.classList.add('photo-crop-open');
      window.requestAnimationFrame(function () {
        modal.classList.add('is-visible');
        resetCropPosition();
        card.focus();
      });
    }

    function loadSelectedFile(file) {
      clearError();
      croppedField.value = '';
      setReady(false);

      if (!file) return;
      if (!/^image\/(jpeg|png|webp)$/i.test(file.type || '')) {
        input.value = '';
        showError('Format gambar harus JPG, PNG, atau WebP.');
        return;
      }
      if (file.size > 12 * 1024 * 1024) {
        input.value = '';
        showError('Ukuran gambar sumber maksimal 12 MB.');
        return;
      }

      revokeObjectUrl();
      objectUrl = URL.createObjectURL(file);
      image.onload = function () {
        naturalWidth = image.naturalWidth;
        naturalHeight = image.naturalHeight;
        if (naturalWidth < 80 || naturalHeight < 80) {
          input.value = '';
          revokeObjectUrl();
          showError('Resolusi gambar minimal 80 × 80 piksel.');
          return;
        }
        openModal();
      };
      image.onerror = function () {
        input.value = '';
        revokeObjectUrl();
        showError('Gambar tidak dapat dibaca oleh browser.');
      };
      image.src = objectUrl;
    }

    input.addEventListener('change', function () {
      loadSelectedFile(input.files && input.files[0] ? input.files[0] : null);
    });

    zoomInput.addEventListener('input', function () {
      if (!naturalWidth || !naturalHeight) return;
      const size = stageSize();
      const oldScale = scale;
      const centerImageX = (size / 2 - offsetX) / oldScale;
      const centerImageY = (size / 2 - offsetY) / oldScale;
      scale = minimumScale * Number(zoomInput.value || 1);
      offsetX = size / 2 - centerImageX * scale;
      offsetY = size / 2 - centerImageY * scale;
      renderImage();
    });

    stage.addEventListener('pointerdown', function (event) {
      if (!naturalWidth || !naturalHeight) return;
      dragging = true;
      dragStartX = event.clientX;
      dragStartY = event.clientY;
      dragOriginX = offsetX;
      dragOriginY = offsetY;
      stage.classList.add('is-dragging');
      stage.setPointerCapture(event.pointerId);
    });

    stage.addEventListener('pointermove', function (event) {
      if (!dragging) return;
      offsetX = dragOriginX + (event.clientX - dragStartX);
      offsetY = dragOriginY + (event.clientY - dragStartY);
      renderImage();
    });

    function finishDrag(event) {
      if (!dragging) return;
      dragging = false;
      stage.classList.remove('is-dragging');
      if (event && stage.hasPointerCapture(event.pointerId)) {
        stage.releasePointerCapture(event.pointerId);
      }
    }

    stage.addEventListener('pointerup', finishDrag);
    stage.addEventListener('pointercancel', finishDrag);

    applyButton.addEventListener('click', function () {
      if (!naturalWidth || !naturalHeight) return;
      const size = stageSize();
      const sourceX = Math.max(0, -offsetX / scale);
      const sourceY = Math.max(0, -offsetY / scale);
      const sourceSize = Math.min(naturalWidth - sourceX, naturalHeight - sourceY, size / scale);
      const canvas = document.createElement('canvas');
      canvas.width = 512;
      canvas.height = 512;
      const context = canvas.getContext('2d');

      if (!context) {
        showError('Browser tidak dapat memproses crop gambar.');
        closeModal({ clearSelection: true });
        return;
      }

      context.imageSmoothingEnabled = true;
      context.imageSmoothingQuality = 'high';
      context.drawImage(image, sourceX, sourceY, sourceSize, sourceSize, 0, 0, 512, 512);
      croppedField.value = canvas.toDataURL('image/jpeg', 0.9);
      input.value = '';
      setReady(true);
      clearError();
      closeModal({ clearSelection: false });
    });

    cancelButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        closeModal({ clearSelection: true });
      });
    });

    modal.addEventListener('click', function (event) {
      if (event.target === modal) closeModal({ clearSelection: true });
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !modal.hidden) {
        closeModal({ clearSelection: true });
      }
    });

    window.addEventListener('resize', function () {
      if (!modal.hidden && naturalWidth && naturalHeight) resetCropPosition();
    });

    form.addEventListener('submit', function (event) {
      if (!croppedField.value) {
        event.preventDefault();
        showError('Pilih gambar lalu gunakan potongan 1:1 sebelum mengunggah.');
        input.focus();
      }
    });
  }

  function initCompactDataPanels() {
    const panels = Array.from(document.querySelectorAll('.page-data .data-action-panel, .page-data .auto-backup-panel'));
    if (!panels.length) return;

    const media = window.matchMedia('(max-width: 700px)');

    panels.forEach(function (panel, index) {
      const head = panel.querySelector('.data-action-head, .auto-backup-head');
      if (!head) return;

      const contentId = 'compact-data-panel-' + (index + 1);
      const collapsibleChildren = Array.from(panel.children).filter(function (child) {
        return child !== head;
      });

      collapsibleChildren.forEach(function (child) {
        child.setAttribute('data-compact-panel-content', '');
        if (!child.id && collapsibleChildren.length === 1) child.id = contentId;
      });

      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'compact-panel-toggle';
      toggle.setAttribute('aria-expanded', 'true');
      toggle.innerHTML = '<span data-compact-panel-label>Sembunyikan</span><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="m7 9 5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      head.appendChild(toggle);

      function setCollapsed(collapsed) {
        panel.classList.toggle('is-compact-collapsed', collapsed);
        toggle.setAttribute('aria-expanded', String(!collapsed));
        const label = toggle.querySelector('[data-compact-panel-label]');
        if (label) label.textContent = collapsed ? 'Buka' : 'Sembunyikan';
      }

      function applyViewportState(event) {
        const isMobile = event.matches;
        panel.classList.toggle('is-compact-panel', isMobile);
        if (!isMobile) {
          setCollapsed(false);
          return;
        }

        const hasError = panel.classList.contains('has-form-error') || !!panel.querySelector('[aria-invalid="true"], .form-inline-notice.error, .auto-backup-warning');
        setCollapsed(!hasError);
      }

      toggle.addEventListener('click', function () {
        setCollapsed(!panel.classList.contains('is-compact-collapsed'));
      });

      applyViewportState(media);
      if (typeof media.addEventListener === 'function') {
        media.addEventListener('change', applyViewportState);
      } else if (typeof media.addListener === 'function') {
        media.addListener(applyViewportState);
      }
    });
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
  initInlineFormValidation();
  initProfilePhotoCrop();
  initCompactDataPanels();
  initMobileSheets();
  initLogoutModal();
  initCurrencyInputs();
  initResponsiveDisclosures();
  initBalanceChart();
  initAnnualChart();
}());
