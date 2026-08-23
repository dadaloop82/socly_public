/** Styled in-app confirm — never uses the browser native dialog. */
let appConfirmDialog = null;

function appConfirmLabels() {
  const lang = (document.documentElement.lang || 'it').slice(0, 2);
  const map = {
    it: { confirm: 'Conferma', cancel: 'Annulla' },
    en: { confirm: 'Confirm', cancel: 'Cancel' },
    de: { confirm: 'Bestätigen', cancel: 'Abbrechen' },
  };
  return map[lang] || map.it;
}

function ensureAppConfirmDialog() {
  if (appConfirmDialog) return appConfirmDialog;
  appConfirmDialog = document.createElement('dialog');
  appConfirmDialog.className = 'setup-exit-dialog';
  appConfirmDialog.dataset.appConfirm = '1';
  appConfirmDialog.innerHTML = `
    <div class="setup-exit-shell">
      <p class="setup-exit-text" data-app-confirm-text></p>
      <div class="setup-exit-actions">
        <button type="button" class="btn btn-ghost" data-app-confirm-cancel></button>
        <button type="button" class="btn" data-app-confirm-ok></button>
      </div>
    </div>`;
  document.body.appendChild(appConfirmDialog);
  return appConfirmDialog;
}

function appConfirm(message, options = {}) {
  const labels = appConfirmLabels();
  const dialog = ensureAppConfirmDialog();
  const textEl = dialog.querySelector('[data-app-confirm-text]');
  const okBtn = dialog.querySelector('[data-app-confirm-ok]');
  const cancelBtn = dialog.querySelector('[data-app-confirm-cancel]');
  const confirmLabel = options.confirmLabel || labels.confirm;
  const cancelLabel = options.cancelLabel || labels.cancel;
  const danger = !!options.danger;

  if (textEl) {
    textEl.textContent = String(message || '').trim();
    textEl.style.whiteSpace = 'pre-line';
  }
  if (okBtn) {
    okBtn.textContent = confirmLabel;
    okBtn.className = danger ? 'btn btn-danger' : 'btn';
  }
  if (cancelBtn) {
    cancelBtn.textContent = cancelLabel;
    cancelBtn.hidden = !!options.alert;
  }

  return new Promise((resolve) => {
    let settled = false;
    const finish = (result) => {
      if (settled) return;
      settled = true;
      okBtn?.removeEventListener('click', onOk);
      cancelBtn?.removeEventListener('click', onCancel);
      dialog.removeEventListener('cancel', onCancelEvent);
      dialog.close();
      resolve(result);
    };
    const onOk = () => finish(true);
    const onCancel = () => finish(false);
    const onCancelEvent = (event) => {
      event.preventDefault();
      finish(false);
    };
    okBtn?.addEventListener('click', onOk);
    cancelBtn?.addEventListener('click', onCancel);
    dialog.addEventListener('cancel', onCancelEvent);
    if (typeof dialog.showModal === 'function') {
      dialog.showModal();
    } else {
      finish(false);
    }
  });
}

function initConfirmForms(scope = document) {
  scope.querySelectorAll('form[data-confirm]').forEach((form) => {
    if (form.dataset.confirmBound === '1') return;
    form.dataset.confirmBound = '1';
    form.addEventListener('submit', async (event) => {
      if (form.dataset.confirmed === '1') return;
      event.preventDefault();
      const ok = await appConfirm(form.dataset.confirm || '', {
        danger: form.dataset.confirmDanger === '1',
      });
      if (!ok) return;
      form.dataset.confirmed = '1';
      HTMLFormElement.prototype.submit.call(form);
    });
  });
}

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

document.addEventListener('DOMContentLoaded', () => {
  const payment = document.querySelector('[name="payment_status"]');
  const partial = document.querySelector('[data-partial-wrap]');
  if (payment && partial) {
    const sync = () => {
      partial.hidden = payment.value !== 'partial';
    };
    payment.addEventListener('change', sync);
    sync();
  }

  initPageEnter();
  initMembersFilterMobile();
  initMobileNav();
  initTopbarScroll();
  initSidebarDeadlines();
  initConfigAccordions();
  initMemberForm();
  initMemberWizard();
  initMembersList();
  initLegalDocs();
  initSetupWizard();
  initSetupSmtp(document);
  initSettingsAssociationForms();
  initSettingsAutosave();
  initLegalDocEditors(document);
  initLogoFilePickers(document);
  initPlaceSuggest(document);
  initDashboardTabs();
  initDashboardCharts();
  initTreasuryCharts();
  initAuthLoginI18nLive();
  syncBrandReadableColors();
  initSessionHeartbeat();
  initPlatformConsents(document);
  initResetUserData();
  initPasswordToggles(document);
  initPasswordGenerators(document);
  initPermissionTemplates(document);
  initEmailTemplateEditor(document);
  initFieldsSortable(document);
  initComponentCards(document);
  initDemoLoginNotice();
  initAuthNewsWidget();
  initAuthUpdateCheck();
  initBirthDateFields(document);
  initGeoSubmitValidation(document);
  initDocumentUpload(document);
  initDocumentRowLinks(document);
  document.querySelectorAll('[data-org-person-form]').forEach((form) => {
    initPlaceSuggest(form);
    initFiscalCodeAuto(form);
    initOrgPersonForm(form);
  });
  initDeadlineCategory(document);
  initTreasuryCategory(document);
  initLeaveGuards(document);
  initConfirmForms(document);
});

/** Compact members search placeholder on small screens. */
function initMembersFilterMobile() {
  const input = document.querySelector('.members-filter [name="q"]');
  if (!(input instanceof HTMLInputElement)) return;
  const desktop = input.dataset.placeholderDesktop || input.placeholder;
  const mobile = input.dataset.placeholderMobile || desktop;
  const sync = () => {
    input.placeholder = window.matchMedia('(max-width: 640px)').matches ? mobile : desktop;
  };
  sync();
  window.matchMedia('(max-width: 640px)').addEventListener('change', sync);
}

/**
 * Soft staggered entrance for page blocks (and as they scroll into view).
 * Content stays visible without JS / with reduced motion.
 */
function initPageEnter() {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  const roots = [
    ...document.querySelectorAll('.main'),
    ...document.querySelectorAll('.auth-shell'),
    ...document.querySelectorAll('.auth-layout, .auth-wrap, .install-wrap, .setup-layout, .setup-shell'),
  ];
  if (!roots.length) return;

  document.documentElement.classList.add('enter-anim');
  roots.forEach((root) => enterScope(root));
}

/** @type {IntersectionObserver | null} */
let pageEnterObserver = null;
let pageEnterSeq = 0;

function getPageEnterObserver() {
  if (pageEnterObserver) return pageEnterObserver;
  if (!('IntersectionObserver' in window)) return null;
  pageEnterObserver = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-entered');
        pageEnterObserver?.unobserve(entry.target);
      });
    },
    { root: null, rootMargin: '0px 0px -6% 0px', threshold: 0.06 }
  );
  return pageEnterObserver;
}

function isEnterableVisible(el) {
  if (!(el instanceof HTMLElement)) return false;
  if (el.closest('[hidden]')) return false;
  if (el.getAttribute('aria-hidden') === 'true') return false;
  const style = window.getComputedStyle(el);
  if (style.display === 'none' || style.visibility === 'hidden') return false;
  return true;
}

/**
 * Mark and reveal enterable blocks inside a scope (page load, tab change, wizard step).
 * @param {ParentNode} scope
 * @param {{ reset?: boolean }} [opts]
 */
function enterScope(scope, opts = {}) {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  if (!document.documentElement.classList.contains('enter-anim')) {
    document.documentElement.classList.add('enter-anim');
  }

  const selector = [
    '.page-header',
    '.alert',
    '.dashboard-tabs-wrap',
    '.dashboard-tablist',
    '.stats > .stat',
    '.charts > .panel',
    '.panel',
    '.form-card',
    '.table-wrap',
    '.filter-bar',
    '.empty-state',
    '.config-accordion',
    '.wizard-steps',
    '.wizard-panel.is-active',
    '.wizard-panel.is-active .field-block',
    '.member-profile-grid > *',
    '.tessera-step-layout > *',
    '.setup-fields-step',
    '.setup-membership-card',
    '.auth-mark',
    '.auth-assoc-logo',
    '.auth-logo',
    '.auth-product',
    '.auth-motto',
    '.auth-desc',
    '.auth-news-slot',
    '.auth-brand-meta',
    '.auth-lede',
    '.auth-panel',
    '.auth-card',
    '.auth-footer',
    '.auth-lang',
    '.auth-form > *',
    '.auth-card > *',
  ].join(',');

  const seen = new Set();
  const items = [];
  scope.querySelectorAll(selector).forEach((el) => {
    if (seen.has(el)) return;
    if (!isEnterableVisible(el)) return;
    seen.add(el);
    items.push(el);
  });
  if (!items.length) return;

  if (opts.reset) {
    items.forEach((el) => {
      el.classList.remove('is-entered');
      el.classList.remove('will-enter');
    });
    // Force reflow so re-adding classes retriggers animation.
    void (scope instanceof HTMLElement ? scope.offsetWidth : document.body.offsetWidth);
  }

  const base = opts.reset ? 0 : pageEnterSeq;
  if (!opts.reset) {
    pageEnterSeq += items.length;
  }

  const io = getPageEnterObserver();
  items.forEach((el, i) => {
    el.classList.add('will-enter');
    el.style.setProperty('--enter-delay', `${Math.min((base + i) * 40, 480)}ms`);
    if (!io) {
      el.classList.add('is-entered');
      return;
    }
    // Re-observe after reset
    io.unobserve(el);
    io.observe(el);
  });
}
const PASSWORD_TOGGLE_ICON_SHOW =
  '<svg class="password-toggle-icon password-toggle-icon--show" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>';
const PASSWORD_TOGGLE_ICON_HIDE =
  '<svg class="password-toggle-icon password-toggle-icon--hide" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15"/></svg>';

function passwordToggleLabels() {
  return {
    show: document.querySelector('meta[name="password-show-label"]')?.content || 'Show password',
    hide: document.querySelector('meta[name="password-hide-label"]')?.content || 'Hide password',
  };
}

function bindPasswordToggle(btn, input) {
  if (!btn || !input || btn.dataset.passwordToggleBound === '1') return;
  btn.dataset.passwordToggleBound = '1';
  const labels = {
    show: btn.dataset.showLabel || passwordToggleLabels().show,
    hide: btn.dataset.hideLabel || passwordToggleLabels().hide,
  };
  btn.addEventListener('click', () => {
    const visible = input.type === 'text';
    input.type = visible ? 'password' : 'text';
    btn.classList.toggle('is-visible', !visible);
    btn.setAttribute('aria-pressed', visible ? 'false' : 'true');
    btn.setAttribute('aria-label', visible ? labels.show : labels.hide);
  });
}

function initPasswordToggles(scope = document) {
  scope.querySelectorAll('[data-password-toggle]').forEach((btn) => {
    const field = btn.closest('[data-password-field]');
    const input = field?.querySelector('input') || btn.parentElement?.querySelector('input');
    if (input) bindPasswordToggle(btn, input);
  });

  const labels = passwordToggleLabels();
  scope.querySelectorAll('input[type="password"]').forEach((input) => {
    if (input.closest('.password-input-wrap')) return;

    const wrap = document.createElement('span');
    wrap.className = 'password-input-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'password-toggle';
    btn.setAttribute('data-password-toggle', '');
    btn.setAttribute('aria-label', labels.show);
    btn.setAttribute('aria-pressed', 'false');
    btn.dataset.showLabel = labels.show;
    btn.dataset.hideLabel = labels.hide;
    btn.innerHTML = PASSWORD_TOGGLE_ICON_SHOW + PASSWORD_TOGGLE_ICON_HIDE;
    wrap.appendChild(btn);
    bindPasswordToggle(btn, input);
  });
}

function initPasswordGenerators(scope = document) {
  scope.querySelectorAll('[data-password-complexity]').forEach((meter) => {
    const input = meter.closest('form')?.querySelector('[name="password"]');
    if (!input || meter.dataset.passwordComplexityBound === '1') return;
    meter.dataset.passwordComplexityBound = '1';
    const sync = () => {
      const value = input.value;
      const score = [
        value.length >= 8,
        /[a-z]/.test(value) && /[A-Z]/.test(value),
        /\d/.test(value),
        /[^A-Za-z0-9]/.test(value),
      ].filter(Boolean).length;
      meter.dataset.score = String(score);
      meter.querySelectorAll('span').forEach((bar, index) => {
        bar.classList.toggle('is-active', index < score);
      });
    };
    input.addEventListener('input', sync);
    sync();
  });

  scope.querySelectorAll('[data-password-generate]').forEach((button) => {
    if (button.dataset.passwordGenerateBound === '1') return;
    button.dataset.passwordGenerateBound = '1';
    button.addEventListener('click', () => {
      const form = button.closest('form');
      const password = form?.querySelector('[name="password"]');
      const confirmation = form?.querySelector('[name="password_confirmation"]');
      if (!password) return;
      const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
      const lower = 'abcdefghijkmnopqrstuvwxyz';
      const digits = '23456789';
      const symbols = '!@#$%*-_';
      const all = upper + lower + digits + symbols;
      const pick = (chars) => chars[crypto.getRandomValues(new Uint32Array(1))[0] % chars.length];
      const chars = [pick(upper), pick(lower), pick(digits), pick(symbols)];
      while (chars.length < 16) chars.push(pick(all));
      for (let i = chars.length - 1; i > 0; i -= 1) {
        const j = crypto.getRandomValues(new Uint32Array(1))[0] % (i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
      }
      password.value = chars.join('');
      if (confirmation) confirmation.value = password.value;
      password.type = 'text';
      password.dispatchEvent(new Event('input', { bubbles: true }));
      confirmation?.dispatchEvent(new Event('input', { bubbles: true }));
      password.focus();
      password.select();
    });
  });
}

function initPermissionTemplates(scope = document) {
  scope.querySelectorAll('[data-user-permissions-editor]').forEach((editor) => {
    if (editor.dataset.permissionTemplatesBound === '1') return;
    editor.dataset.permissionTemplatesBound = '1';
    editor.querySelectorAll('[data-permission-template]').forEach((button) => {
      button.addEventListener('click', () => {
        let keys = [];
        try {
          keys = JSON.parse(button.dataset.permissionKeys || '[]');
        } catch {
          keys = [];
        }
        if (!Array.isArray(keys)) keys = [];
        const keySet = new Set(keys.map(String));
        editor.querySelectorAll('[data-permission-key]').forEach((input) => {
          if (input instanceof HTMLInputElement && input.type === 'checkbox' && !input.disabled) {
            input.checked = keySet.has(input.value);
          }
        });
        editor.querySelectorAll('[data-permission-template]').forEach((btn) => {
          btn.classList.toggle('is-active', btn === button);
        });
      });
    });
  });
}

function initEmailTemplateEditor(scope = document) {
  const form = scope.querySelector('[data-email-template-form]');
  const previewRoot = scope.querySelector('[data-email-template-preview]');
  if (!form || !previewRoot || form.dataset.emailTemplateBound === '1') return;
  form.dataset.emailTemplateBound = '1';
  let samples = {};
  try {
    samples = JSON.parse(previewRoot.dataset.samples || '{}');
  } catch {
    samples = {};
  }
  const langs = ['it', 'en', 'de'];
  let activeLang = 'it';
  const formatSelect = form.querySelector('[data-tpl-body-format]');
  const actionField = form.querySelector('[data-tpl-action-field]');
  const htmlViewMode = { it: 'source', en: 'source', de: 'source' };

  const field = (lang, kind) => form.querySelector(`[data-tpl-${kind}="${lang}"]`);
  const sourceField = (lang) => form.querySelector(`[data-tpl-body-source="${lang}"]`);

  const truthy = (v) => {
    if (v === true || v === 1) return true;
    const s = String(v ?? '').trim().toLowerCase();
    if (!s || s === '0') return false;
    return !['no', 'nein', 'false', 'off', 'n'].includes(s);
  };

  const renderConditionals = (tpl, vars) => {
    let out = String(tpl || '');
    const positive = /\{\{#\s*([a-z0-9_]+)\s*\}\}([\s\S]*?)\{\{\/\s*\1\s*\}\}/gi;
    const negative = /\{\{\^\s*([a-z0-9_]+)\s*\}\}([\s\S]*?)\{\{\/\s*\1\s*\}\}/gi;
    for (let pass = 0; pass < 12; pass += 1) {
      const prev = out;
      out = out.replace(positive, (_, key, inner) => (truthy(vars[String(key).toLowerCase()]) ? inner : ''));
      out = out.replace(negative, (_, key, inner) => (truthy(vars[String(key).toLowerCase()]) ? '' : inner));
      if (out === prev) break;
    }
    return out;
  };

  const render = (tpl, vars) => renderConditionals(tpl, vars).replace(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi, (_, key) => {
    const k = String(key).toLowerCase();
    return Object.prototype.hasOwnProperty.call(vars, k) ? String(vars[k]) : '';
  });

  const isHtmlMode = () => formatSelect?.value === 'html';

  const syncSourceToHidden = (lang) => {
    const ta = field(lang, 'body');
    const source = sourceField(lang);
    if (ta && source && isHtmlMode()) ta.value = source.value;
  };

  const loadSourceFromHidden = (lang) => {
    const ta = field(lang, 'body');
    const source = sourceField(lang);
    if (ta && source) source.value = ta.value;
  };

  const bodyValue = (lang) => {
    if (isHtmlMode()) syncSourceToHidden(lang);
    return field(lang, 'body')?.value || '';
  };

  const updatePreview = () => {
    const vars = samples[activeLang] || samples.it || {};
    const subject = field(activeLang, 'subject')?.value || '';
    const body = bodyValue(activeLang);
    previewRoot.querySelector('[data-preview-lang]')?.replaceChildren(document.createTextNode(activeLang.toUpperCase()));
    previewRoot.querySelector('[data-preview-format]')?.replaceChildren(document.createTextNode(isHtmlMode() ? 'HTML' : 'TESTO'));
    previewRoot.querySelector('[data-preview-subject]')?.replaceChildren(document.createTextNode(render(subject, vars) || '—'));
    const rendered = render(body, vars) || '—';
    const textEl = previewRoot.querySelector('[data-preview-body-text]');
    const htmlEl = previewRoot.querySelector('[data-preview-body-html]');
    if (isHtmlMode()) {
      if (textEl) textEl.hidden = true;
      if (htmlEl) {
        htmlEl.hidden = false;
        htmlEl.innerHTML = rendered === '—' ? '<span class="muted">—</span>' : rendered;
      }
    } else if (textEl) {
      textEl.hidden = false;
      textEl.textContent = rendered;
      if (htmlEl) htmlEl.hidden = true;
    }
  };

  const setHtmlViewMode = (lang, mode) => {
    htmlViewMode[lang] = mode;
    const sourceWrap = form.querySelector(`[data-tpl-html-wrap="${lang}"] [data-tpl-body-source="${lang}"]`)?.parentElement;
    const preview = form.querySelector(`[data-tpl-inline-preview="${lang}"]`);
    form.querySelectorAll(`[data-html-mode][data-lang="${lang}"]`).forEach((btn) => {
      btn.classList.toggle('is-active', btn.getAttribute('data-html-mode') === mode);
    });
    if (mode === 'preview') {
      syncSourceToHidden(lang);
      if (preview) {
        const vars = samples[lang] || samples.it || {};
        preview.innerHTML = render(sourceField(lang)?.value || '', vars) || '<span class="muted">—</span>';
        preview.hidden = false;
      }
      if (sourceField(lang)) sourceField(lang).hidden = true;
    } else {
      loadSourceFromHidden(lang);
      if (sourceField(lang)) sourceField(lang).hidden = false;
      if (preview) preview.hidden = true;
    }
    updatePreview();
  };

  const applyFormatMode = () => {
    langs.forEach((lang) => {
      const ta = field(lang, 'body');
      const wrap = form.querySelector(`[data-tpl-html-wrap="${lang}"]`);
      if (!ta || !wrap) return;
      if (isHtmlMode()) {
        wrap.hidden = false;
        ta.hidden = true;
        ta.required = false;
        setHtmlViewMode(lang, htmlViewMode[lang] || 'source');
      } else {
        syncSourceToHidden(lang);
        wrap.hidden = true;
        ta.hidden = false;
        if (lang === 'it') ta.required = true;
      }
    });
    updatePreview();
  };

  form.querySelectorAll('[data-lang-tab]').forEach((tab) => {
    tab.addEventListener('click', () => {
      activeLang = tab.getAttribute('data-lang-tab') || 'it';
      form.querySelectorAll('[data-lang-tab]').forEach((t) => t.classList.toggle('is-active', t === tab));
      form.querySelectorAll('[data-lang-pane]').forEach((pane) => {
        pane.classList.toggle('is-active', pane.getAttribute('data-lang-pane') === activeLang);
      });
      updatePreview();
    });
  });

  form.querySelectorAll('[data-html-mode]').forEach((btn) => {
    btn.addEventListener('click', () => setHtmlViewMode(btn.getAttribute('data-lang') || 'it', btn.getAttribute('data-html-mode') || 'source'));
  });

  formatSelect?.addEventListener('change', applyFormatMode);
  form.querySelectorAll('[data-tpl-subject], [data-tpl-body], [data-tpl-body-source]').forEach((el) => {
    el.addEventListener('input', updatePreview);
  });

  form.querySelectorAll('[data-insert-ph]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const key = btn.getAttribute('data-insert-ph') || '';
      const token = `{{${key}}}`;
      const target = isHtmlMode() && htmlViewMode[activeLang] === 'source'
        ? sourceField(activeLang)
        : field(activeLang, 'body');
      if (!target) return;
      const start = target.selectionStart ?? target.value.length;
      const end = target.selectionEnd ?? target.value.length;
      target.value = target.value.slice(0, start) + token + target.value.slice(end);
      target.dispatchEvent(new Event('input', { bubbles: true }));
      target.focus();
    });
  });

  form.querySelectorAll('[data-tpl-submit]').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (actionField) actionField.value = btn.getAttribute('data-tpl-submit') || 'save';
      if (isHtmlMode()) langs.forEach(syncSourceToHidden);
    });
  });

  applyFormatMode();
}

function initResetUserData() {
  const root = document.querySelector('[data-reset-user-data]');
  if (!root) return;

  const form = root.querySelector('[data-reset-user-form]');
  const openBtn = root.querySelector('[data-reset-user-open]');
  const confirm1 = form?.querySelector('[data-reset-confirm-1]');
  const confirm2 = form?.querySelector('[data-reset-confirm-2]');
  const dialog1 = document.querySelector('[data-reset-dialog-1]');
  const dialog2 = document.querySelector('[data-reset-dialog-2]');
  if (!form || !openBtn || !confirm1 || !confirm2 || !dialog1 || !dialog2) return;

  const closeAll = () => {
    dialog1.close();
    dialog2.close();
  };

  const resetFlags = () => {
    confirm1.value = '0';
    confirm2.value = '0';
  };

  openBtn.addEventListener('click', async () => {
    resetFlags();
    if (typeof dialog1.showModal === 'function') {
      dialog1.showModal();
      return;
    }
    const text1 = dialog1.querySelector('.config-reset-text')?.textContent?.trim() || '';
    if (!(await appConfirm(text1, { danger: true }))) {
      resetFlags();
      return;
    }
    confirm1.value = '1';
    const text2 = dialog2.querySelector('.config-reset-text')?.textContent?.trim() || '';
    if (await appConfirm(text2, { danger: true })) {
      confirm2.value = '1';
      form.submit();
    } else {
      resetFlags();
    }
  });

  dialog1.querySelectorAll('[data-reset-cancel]').forEach((btn) => {
    btn.addEventListener('click', () => {
      resetFlags();
      closeAll();
    });
  });
  dialog2.querySelectorAll('[data-reset-cancel]').forEach((btn) => {
    btn.addEventListener('click', () => {
      resetFlags();
      closeAll();
    });
  });

  dialog1.querySelector('[data-reset-next]')?.addEventListener('click', () => {
    confirm1.value = '1';
    dialog1.close();
    dialog2.showModal();
  });

  dialog2.querySelector('[data-reset-final]')?.addEventListener('click', () => {
    confirm1.value = '1';
    confirm2.value = '1';
    dialog2.close();
    form.submit();
  });

  dialog1.addEventListener('cancel', (e) => {
    e.preventDefault();
    resetFlags();
    dialog1.close();
  });
  dialog2.addEventListener('cancel', (e) => {
    e.preventDefault();
    resetFlags();
    dialog2.close();
  });
}

function initPlatformConsents(scope) {
  const roots = [...(scope.querySelectorAll ? scope.querySelectorAll('[data-platform-consents]') : [])];
  if (scope.matches?.('[data-platform-consents]')) {
    roots.push(scope);
  }
  roots.forEach((root) => {
    if (root.dataset.platformBound === '1') return;
    root.dataset.platformBound = '1';
    const opts = [...root.querySelectorAll('[data-platform-opt]')];
    const confirm = root.querySelector('[data-platform-confirm]');
    const inputs = [...root.querySelectorAll('[data-platform-confirm-input]')];
    const sync = () => {
      const any = opts.some((el) => el.checked);
      if (confirm) confirm.hidden = !any;
      inputs.forEach((el) => {
        el.required = any;
        if (!any) el.value = '';
      });
    };
    opts.forEach((el) => el.addEventListener('change', sync));
    sync();
  });
}

function initSessionHeartbeat() {
  const pingUrl = document.querySelector('meta[name="session-ping-url"]')?.content;
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  if (!pingUrl || !csrf) return;

  let lastBump = 0;
  const bump = () => {
    const now = Date.now();
    if (now - lastBump < 15000) return;
    lastBump = now;
    fetch(pingUrl, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-TOKEN': csrf,
      },
      body: '_token=' + encodeURIComponent(csrf),
      credentials: 'same-origin',
    }).then((res) => {
      if (res.status === 401) {
        const login = document.querySelector('meta[name="login-url"]')?.content || '/login';
        window.location.href = login + (login.includes('?') ? '&' : '?') + 'expired=1';
      }
    }).catch(() => {});
  };

  setInterval(bump, 60000);
  ['pointerdown', 'keydown', 'scroll', 'visibilitychange'].forEach((evt) => {
    document.addEventListener(evt, () => {
      if (document.visibilityState === 'hidden') return;
      bump();
    }, { passive: true });
  });
}

function brandNormalizeHex(value, fallback = '') {
  const raw = String(value || '').trim();
  if (/^#[0-9A-Fa-f]{6}$/.test(raw)) return raw.toUpperCase();
  if (/^#[0-9A-Fa-f]{3}$/.test(raw)) {
    return `#${raw[1]}${raw[1]}${raw[2]}${raw[2]}${raw[3]}${raw[3]}`.toUpperCase();
  }
  const rgb = String(value || '').match(/^rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)/i);
  if (rgb) {
    const toHex = (n) => Math.max(0, Math.min(255, Math.round(Number(n)))).toString(16).padStart(2, '0');
    return `#${toHex(rgb[1])}${toHex(rgb[2])}${toHex(rgb[3])}`.toUpperCase();
  }
  return fallback;
}

function brandMixHex(from, to, amount) {
  const a = brandNormalizeHex(from, '#000000');
  const b = brandNormalizeHex(to, '#000000');
  const t = Math.max(0, Math.min(1, Number(amount) || 0));
  const parse = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
  const [r1, g1, b1] = parse(a);
  const [r2, g2, b2] = parse(b);
  const ch = (x, y) => Math.round(x + (y - x) * t).toString(16).padStart(2, '0');
  return `#${ch(r1, r2)}${ch(g1, g2)}${ch(b1, b2)}`.toUpperCase();
}

function brandRelativeLuminance(hex) {
  const channel = (value) => {
    const c = value / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  };
  const normalized = brandNormalizeHex(hex, '#000000');
  const r = parseInt(normalized.slice(1, 3), 16);
  const g = parseInt(normalized.slice(3, 5), 16);
  const b = parseInt(normalized.slice(5, 7), 16);
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

function brandContrastRatio(a, b) {
  const l1 = brandRelativeLuminance(a);
  const l2 = brandRelativeLuminance(b);
  const hi = Math.max(l1, l2);
  const lo = Math.min(l1, l2);
  return (hi + 0.05) / (lo + 0.05);
}

function brandReadableHex(fg, bg = '#FFFFFF', minRatio = 4.5) {
  let color = brandNormalizeHex(fg, '#000000');
  const surface = brandNormalizeHex(bg, '#FFFFFF');
  if (brandContrastRatio(color, surface) >= minRatio) return color;
  const toward = brandRelativeLuminance(surface) > 0.45 ? '#000000' : '#FFFFFF';
  let best = color;
  let bestRatio = brandContrastRatio(color, surface);
  for (let i = 1; i <= 48; i += 1) {
    const candidate = brandMixHex(color, toward, i / 48);
    const ratio = brandContrastRatio(candidate, surface);
    if (ratio > bestRatio) {
      best = candidate;
      bestRatio = ratio;
    }
    if (ratio >= minRatio) return candidate;
  }
  return best;
}

function applyReadableBrandVars(primary, accent, target = document.documentElement) {
  const p = brandNormalizeHex(primary, '#0D6E66');
  const a = brandNormalizeHex(accent, '#B84A1B');
  const paper = '#FFFFFF';
  const deep = brandMixHex(p, '#000000', 0.72);
  const accentInk = brandReadableHex(a, paper, 4.5);
  target.style.setProperty('--brand-primary-ink', brandReadableHex(p, paper, 4.5));
  target.style.setProperty('--brand-accent-ink', accentInk);
  target.style.setProperty(
    '--brand-accent-muted',
    brandReadableHex(brandMixHex(accentInk, paper, 0.28), paper, 3.0)
  );
  target.style.setProperty('--brand-accent-on-dark', brandReadableHex(a, deep, 4.5));
}

function brandColorsTooClose(a, b) {
  const left = brandNormalizeHex(a, '');
  const right = brandNormalizeHex(b, '');
  if (!left || !right) return false;
  if (left === right) return true;
  const parse = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
  const [r1, g1, b1] = parse(left);
  const [r2, g2, b2] = parse(right);
  const dr = r1 - r2;
  const dg = g1 - g2;
  const db = b1 - b2;
  return (dr * dr + dg * dg + db * db) < (55 * 55);
}

function brandEnsureDistinctColors(primary, accent) {
  let p = brandNormalizeHex(primary, '#0D6E66');
  let a = brandNormalizeHex(accent, '#B84A1B');
  if (!brandColorsTooClose(p, a)) return [p, a];
  const candidates = ['#B84A1B', '#0D6E66', '#C45C26', '#1F6F8B', '#8B3A16', '#2A8F85'];
  for (const candidate of candidates) {
    if (!brandColorsTooClose(p, candidate)) return [p, candidate];
  }
  const parse = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
  const [r, g, b] = parse(p);
  const fallback = `#${[((r + 140) % 256), ((g + 90) % 256), ((b + 40) % 256)]
    .map((n) => n.toString(16).padStart(2, '0'))
    .join('')}`.toUpperCase();
  return [p, brandColorsTooClose(p, fallback) ? (p === '#0D6E66' ? '#B84A1B' : '#0D6E66') : fallback];
}

function applyBrandColors(primary, accent, target = document.documentElement) {
  const styles = getComputedStyle(target);
  let nextPrimary = brandNormalizeHex(primary, '')
    || brandNormalizeHex(styles.getPropertyValue('--brand-primary'), '#0D6E66');
  let nextAccent = brandNormalizeHex(accent, '')
    || brandNormalizeHex(styles.getPropertyValue('--brand-accent'), '#B84A1B');
  [nextPrimary, nextAccent] = brandEnsureDistinctColors(nextPrimary, nextAccent);
  target.style.setProperty('--brand-primary', nextPrimary);
  target.style.setProperty('--brand-primary-deep', `color-mix(in srgb, ${nextPrimary} 72%, black)`);
  target.style.setProperty('--brand-accent', nextAccent);
  applyReadableBrandVars(nextPrimary, nextAccent, target);
}

function syncBrandReadableColors(target = document.documentElement) {
  const styles = getComputedStyle(target);
  applyReadableBrandVars(
    brandNormalizeHex(styles.getPropertyValue('--brand-primary'), '#0D6E66'),
    brandNormalizeHex(styles.getPropertyValue('--brand-accent'), '#B84A1B'),
    target
  );
}

function initAuthLoginI18nLive() {
  const authLang = document.querySelector('.auth-lang[data-i18n-endpoint]');
  const select = document.querySelector('[data-lang-select]');
  if (!authLang || !select || select.tagName !== 'SELECT') return;

  const endpointBase = String(authLang.dataset.i18nEndpoint || '');
  if (!endpointBase) return;

  const loginLang = document.querySelector('[data-login-lang]');
  const setupLink = document.querySelector('[data-setup-lang-link]');

  // Rotazione “Scegli la lingua” (fade).
  const rotator = document.querySelector('.auth-lang-rotate');
  const rotateItems = rotator ? [...rotator.querySelectorAll('[data-rotate-lang]')] : [];
  let rotateIndex = rotateItems.findIndex((x) => x.classList.contains('is-active'));
  if (rotateIndex < 0) rotateIndex = 0;
  const showRotate = (idx) => {
    if (rotateItems.length === 0) return;
    rotateIndex = ((idx % rotateItems.length) + rotateItems.length) % rotateItems.length;
    rotateItems.forEach((el, n) => el.classList.toggle('is-active', n === rotateIndex));
  };
  if (rotateItems.length > 0) {
    const selectedIdx = rotateItems.findIndex((el) => el.dataset.rotateLang === select.value);
    showRotate(selectedIdx >= 0 ? selectedIdx : rotateIndex);
    setInterval(() => showRotate(rotateIndex + 1), 2600);
  }

  const getByPath = (obj, path) => path.split('.').reduce(
    (acc, key) => (acc && acc[key] !== undefined) ? acc[key] : undefined,
    obj
  );

  const escapeHtml = (s) => String(s)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');

  const applyMessages = (messages, lang) => {
    document.querySelectorAll('[data-i18n]').forEach((el) => {
      if (el.closest('[data-lang-select]')) return;
      const key = el.dataset.i18n;
      const v = getByPath(messages, key);
      if (typeof v !== 'string') return;
      el.textContent = v;
    });

    document.querySelectorAll('[data-i18n-html]').forEach((el) => {
      const key = el.dataset.i18nHtml;
      const v = getByPath(messages, key);
      if (typeof v !== 'string') return;

      if (key === 'auth.product_description' || key === 'auth.motto' || key === 'auth.license_note') {
        let html = escapeHtml(v).replace(/\*/g, '<span class="auth-asterisk" aria-hidden="true">*</span>');
        if (key === 'auth.product_description') {
          html = html.replace(/Socly/gi, '<span class="socly-word">SOCLY</span>');
        }
        el.innerHTML = html;
        return;
      }
      if (key === 'auth.footer_tagline') {
        el.innerHTML = escapeHtml(v).replace(':heart', '<span class="heart" aria-hidden="true">❤</span>');
        return;
      }
      el.innerHTML = escapeHtml(v);
    });

    document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
      const key = el.dataset.i18nPlaceholder;
      const v = getByPath(messages, key);
      if (typeof v === 'string') el.setAttribute('placeholder', v);
    });

    const chooseLabel = getByPath(messages, 'auth.choose_language');
    if (typeof chooseLabel === 'string') {
      select.setAttribute('aria-label', chooseLabel);
    }

    // Keep option labels as flag + translated language name.
    const labels = {
      it: getByPath(messages, 'members.lang_it') || 'Italiano',
      de: getByPath(messages, 'members.lang_de') || 'Deutsch',
      en: getByPath(messages, 'members.lang_en') || 'English',
    };
    const flags = { it: '🇮🇹', de: '🇩🇪', en: '🇬🇧' };
    [...select.options].forEach((opt) => {
      const code = opt.value;
      if (labels[code]) {
        opt.textContent = `${flags[code] || ''} ${labels[code]}`.trim();
      }
    });

    document.documentElement.setAttribute('lang', lang);
  };

  const syncLinks = (lang) => {
    if (loginLang) loginLang.value = lang;
    if (setupLink) {
      try {
        const u = new URL(setupLink.getAttribute('href') || '/setup', window.location.origin);
        u.searchParams.set('lang', lang);
        setupLink.setAttribute('href', u.pathname + u.search);
      } catch (e) {
        // ignore
      }
    }
  };
  syncLinks(select.value);

  const load = async (lang) => {
    syncLinks(lang);
    try {
      const res = await fetch(`${endpointBase}?lang=${encodeURIComponent(lang)}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      if (!res.ok) return;
      const data = await res.json();
      if (!data?.ok) return;
      // Live text swap; the same request also persists locale in session via LocaleMiddleware.
      applyMessages(data.messages || {}, lang);
      if (rotateItems.length > 0) {
        const idx = rotateItems.findIndex((el) => el.dataset.rotateLang === lang);
        if (idx >= 0) showRotate(idx);
      }
    } catch (err) {
      // ignore network/json errors on login language preview
    }
  };

  select.addEventListener('change', () => load(select.value));
}

function initSetupWizard() {
  const root = document.querySelector('[data-setup-wizard]');
  if (!root) return;
  const panel = root.querySelector('[data-setup-panel]');
  if (!panel) return;

  const lines = [...panel.querySelectorAll('[data-setup-line]')];
  const setupForm = root.querySelector('[data-setup-form]');
  const logoutForm = root.querySelector('[data-setup-logout]');
  const discardForm = root.querySelector('[data-setup-discard]');
  const exitBtn = root.querySelector('[data-setup-exit]');
  const exitDialog = root.querySelector('[data-setup-exit-dialog]');
  const exitFlag = setupForm?.querySelector('[data-setup-exit-flag]');
  const progressFill = panel.querySelector('.setup-progress span');
  const hasProgress = root.dataset.hasProgress === '1';
  const isIncremental = root.dataset.setupIncremental === '1';

  if (setupForm) {
    initPlaceSuggest(setupForm);
    initFiscalCodeAuto(setupForm);
    initBirthDateFields(setupForm);
    initGeoSubmitValidation(setupForm);
  }
  initSetupPeopleList(root);
  initSetupMemberTypes(root);
  initSetupMembershipPeriods(root);
  initSetupEnrollment(root);
  initSetupWebsiteScrape(root);
  initSetupRuntsLookup(root);
  initSetupBrandingPalettes(root);
  initLogoFilePickers(root);
  initSetupNamePairPreview(root);
  initPlatformConsents(root);

  setupForm?.querySelectorAll('[data-setup-defer-step]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const flag = setupForm?.querySelector('[data-setup-defer-flag]');
      if (flag) flag.value = '1';
      setupForm?.requestSubmit();
    });
  });

  const fadeInKeyframes = [
    { opacity: 0, transform: 'translateY(28px)' },
    { opacity: 1, transform: 'translateY(0px)' },
  ];
  const fadeOutKeyframes = [
    { opacity: 1, transform: 'translateY(0px)' },
    { opacity: 0, transform: 'translateY(-14px)' },
  ];

  // Already hidden via critical CSS in <head>; keep inline lock until WAAPI takes over
  panel.style.opacity = '0';
  panel.style.transform = 'translateY(28px)';
  lines.forEach((el) => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(28px)';
  });
  if (progressFill) {
    progressFill.style.transform = 'scaleX(0)';
    progressFill.style.transformOrigin = 'left center';
  }

  const playEnter = () => {
    root.classList.add('is-animating');
    panel.classList.add('is-enter');

    if (typeof panel.animate === 'function') {
      const cardAnim = panel.animate(fadeInKeyframes, {
        duration: 1000,
        easing: 'ease',
        fill: 'forwards',
      });
      cardAnim.finished.then(() => {
        panel.style.opacity = '1';
        panel.style.transform = 'translateY(0)';
      }).catch(() => {});

      lines.forEach((el, i) => {
        const lineAnim = el.animate(fadeInKeyframes, {
          duration: 1100,
          delay: 180 + i * 260,
          easing: 'ease',
          fill: 'forwards',
        });
        lineAnim.finished.then(() => {
          el.style.opacity = '1';
          el.style.transform = 'translateY(0)';
        }).catch(() => {});
      });
      if (progressFill) {
        const barAnim = progressFill.animate(
          [{ transform: 'scaleX(0)' }, { transform: 'scaleX(1)' }],
          {
            duration: 1100,
            delay: 320,
            easing: 'ease',
            fill: 'forwards',
          }
        );
        barAnim.finished.then(() => {
          progressFill.style.transform = 'scaleX(1)';
        }).catch(() => {});
      }
      return;
    }

    // Fallback without WAAPI
    panel.style.transition = 'opacity 1s ease, transform 1s ease';
    panel.style.opacity = '1';
    panel.style.transform = 'translateY(0)';
    lines.forEach((el, i) => {
      el.style.transition = 'opacity 1.1s ease, transform 1.1s ease';
      el.style.transitionDelay = `${180 + i * 260}ms`;
      el.style.opacity = '1';
      el.style.transform = 'translateY(0)';
    });
  };

  requestAnimationFrame(() => {
    requestAnimationFrame(playEnter);
  });

  const fieldSnapshot = (form) => {
    const state = {};
    form.querySelectorAll('input[name], select[name], textarea[name]').forEach((el) => {
      const name = el.name;
      if (!name || ['_token', 'setup_exit', 'step_index'].includes(name)) return;
      if (el.type === 'checkbox' || el.type === 'radio') {
        if (el.type === 'radio' && !el.checked) return;
        state[name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
        return;
      }
      state[name] = el.value;
    });
    return JSON.stringify(state);
  };

  const initialSnapshot = setupForm ? fieldSnapshot(setupForm) : '';
  let dirty = false;
  const isDirty = () => {
    if (!setupForm) return false;
    return dirty || fieldSnapshot(setupForm) !== initialSnapshot;
  };
  const shouldConfirmExit = () => isDirty() || hasProgress;

  if (setupForm) {
    const markDirty = () => {
      dirty = fieldSnapshot(setupForm) !== initialSnapshot;
    };
    setupForm.addEventListener('input', markDirty);
    setupForm.addEventListener('change', markDirty);
    setupForm.addEventListener('keyup', markDirty);
  }

  const leaveThen = (submitForm) => {
    if (submitForm.dataset.leaving === '1') return;
    submitForm.dataset.leaving = '1';
    panel.classList.remove('is-enter');
    panel.classList.add('is-leave');

    let finished = false;
    const finish = () => {
      if (finished) return;
      finished = true;
      HTMLFormElement.prototype.submit.call(submitForm);
    };

    if (typeof panel.animate === 'function') {
      panel.animate(fadeOutKeyframes, {
        duration: 380,
        easing: 'ease',
        fill: 'forwards',
      });
      lines.forEach((el) => {
        el.animate(fadeOutKeyframes, {
          duration: 320,
          easing: 'ease',
          fill: 'forwards',
        });
      });
    }

    window.setTimeout(finish, 420);
  };

  const logoutNow = () => {
    if (!logoutForm) return;
    leaveThen(logoutForm);
  };

  const discardAndExit = () => {
    if (discardForm) {
      leaveThen(discardForm);
      return;
    }
    logoutNow();
  };

  const keepAndExit = () => {
    if (isDirty()) {
      if (!setupForm || !exitFlag) {
        logoutNow();
        return;
      }
      if (typeof setupForm.reportValidity === 'function' && !setupForm.reportValidity()) {
        return;
      }
      exitFlag.value = '1';
      leaveThen(setupForm);
      return;
    }
    logoutNow();
  };

  exitBtn?.addEventListener('click', async (event) => {
    event.preventDefault();
    event.stopPropagation();
    if (!shouldConfirmExit()) {
      logoutNow();
      return;
    }
    if (exitDialog && typeof exitDialog.showModal === 'function') {
      exitDialog.showModal();
      return;
    }
    const text = exitDialog?.querySelector('.setup-exit-text')?.textContent?.trim() || '';
    if (await appConfirm(text)) {
      keepAndExit();
    } else {
      logoutNow();
    }
  });

  exitDialog?.querySelector('[data-setup-exit-discard]')?.addEventListener('click', async () => {
    const confirmMsg =
      exitDialog.querySelector('[data-setup-exit-discard]')?.dataset.confirm ||
      '';
    if (confirmMsg && !(await appConfirm(confirmMsg, { danger: true }))) {
      return;
    }
    exitDialog.close();
    discardAndExit();
  });

  exitDialog?.querySelector('[data-setup-exit-keep]')?.addEventListener('click', () => {
    exitDialog.close();
    keepAndExit();
  });

  exitDialog?.querySelector('[data-setup-exit-cancel]')?.addEventListener('click', () => {
    exitDialog.close();
  });

  root.querySelectorAll('form').forEach((form) => {
    if (form.matches('[data-setup-logout], [data-setup-discard]')) return;
    form.addEventListener('submit', (event) => {
      if (form.dataset.leaving === '1') return;
      if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;

      const scrapeBox = form.querySelector('[data-setup-scrape]');
      const websiteInput = form.querySelector('[data-setup-website-input]');
      const askDialog = root.querySelector('[data-setup-scrape-ask-dialog]');
      if (
        scrapeBox
        && websiteInput
        && askDialog
        && form.dataset.scrapeSkipAsk !== '1'
        && scrapeBox.dataset.scrapeAttempted !== '1'
        && !scrapeBox.classList.contains('is-scraping')
      ) {
        const website = (typeof normalizeWebsiteInput === 'function'
          ? normalizeWebsiteInput(websiteInput.value)
          : websiteInput.value.trim()) || websiteInput.value.trim();
        if (website) {
          event.preventDefault();
          if (typeof normalizeWebsiteInput === 'function') {
            const normalized = normalizeWebsiteInput(websiteInput.value);
            if (normalized) websiteInput.value = normalized;
          }
          const askText = askDialog.querySelector('[data-setup-scrape-ask-text]');
          if (askText) {
            askText.textContent = scrapeBox.dataset.msgAsk || askText.textContent;
          }
          const yesBtn = askDialog.querySelector('[data-setup-scrape-ask-yes]');
          const noBtn = askDialog.querySelector('[data-setup-scrape-ask-no]');
          if (yesBtn && scrapeBox.dataset.msgAskYes) yesBtn.textContent = scrapeBox.dataset.msgAskYes;
          if (noBtn && scrapeBox.dataset.msgAskNo) noBtn.textContent = scrapeBox.dataset.msgAskNo;
          if (typeof askDialog.showModal === 'function') {
            askDialog.showModal();
          }
          return;
        }
      }

      event.preventDefault();
      leaveThen(form);
    });
  });

  const scrapeAskDialog = root.querySelector('[data-setup-scrape-ask-dialog]');
  scrapeAskDialog?.querySelector('[data-setup-scrape-ask-no]')?.addEventListener('click', () => {
    scrapeAskDialog.close();
    if (!setupForm) return;
    setupForm.dataset.scrapeSkipAsk = '1';
    if (typeof setupForm.requestSubmit === 'function') {
      setupForm.requestSubmit();
    } else {
      setupForm.submit();
    }
  });
  scrapeAskDialog?.querySelector('[data-setup-scrape-ask-yes]')?.addEventListener('click', () => {
    scrapeAskDialog.close();
    const scrapeBtn = root.querySelector('[data-setup-scrape-btn]');
    scrapeBtn?.click();
    // Focus results area so the action is visible.
    root.querySelector('[data-setup-scrape]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
}

function initLegalDocs() {
  document.querySelectorAll('[data-ack-item]').forEach((item) => {
    const openBtn = item.querySelector('[data-doc-open]');
    const dialog = item.querySelector('[data-doc-dialog]');
    const checkbox = item.querySelector('[data-ack-checkbox]');
    if (!openBtn || !dialog) return;

    const close = () => dialog.close();
    openBtn.addEventListener('click', () => dialog.showModal());
    dialog.querySelectorAll('[data-doc-close]').forEach((btn) => btn.addEventListener('click', close));
    dialog.querySelector('[data-doc-accept]')?.addEventListener('click', () => {
      if (checkbox) {
        checkbox.checked = true;
        checkbox.removeAttribute('data-requires-read');
        checkbox.classList.remove('input-invalid');
      }
      close();
    });

    checkbox?.addEventListener('click', (e) => {
      if (checkbox.dataset.requiresRead === '1') {
        e.preventDefault();
        dialog.showModal();
      }
    });
  });
}

function initMemberWizard() {
  const form = document.querySelector('[data-wizard]');
  if (!form) {
    return;
  }

  const total = Number(form.dataset.totalSteps || 3);
  let step = 1;
  const panels = [...form.querySelectorAll('[data-wizard-panel]')];
  const indicators = [...form.querySelectorAll('[data-step-indicator]')];
  const progress = form.querySelector('[data-wizard-progress]');
  const prevBtn = form.querySelector('[data-wizard-prev]');
  const nextBtn = form.querySelector('[data-wizard-next]');
  const submitBtn = form.querySelector('[data-wizard-submit]');
  const errorBox = form.querySelector('[data-wizard-error]');
  const nameTarget = form.querySelector('[data-tessera-name]');
  const numberTarget = form.querySelector('[data-tessera-number]');
  const typeTarget = form.querySelector('[data-tessera-type]');
  const photoTarget = form.querySelector('[data-tessera-photo]');
  const photoPlaceholder = form.querySelector('[data-tessera-photo-placeholder]');
  const photoWrap = form.querySelector('[data-tessera-photo-wrap]');
  const numberInput = form.querySelector('[data-member-number]');
  const typeSelect = form.querySelector('[data-member-type]');
  const paymentTypeLabel = form.querySelector('[data-payment-type-label]');
  const paymentAmount = form.querySelector('[data-payment-amount]');
  const paymentDue = form.querySelector('[data-payment-due]');
  const paymentStatus = form.querySelector('[name="payment_status"]');
  const partialAmountInput = form.querySelector('[name="partial_amount"]');
  const money = (n) =>
    `${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;

  const syncTesseraPhoto = (src) => {
    if (!photoTarget) return;
    const url = (src || '').trim();
    const usable =
      url !== '' &&
      !url.endsWith('/') &&
      (url.startsWith('blob:') ||
        url.startsWith('data:') ||
        /^https?:\/\//i.test(url) ||
        url.includes('/photo'));
    if (usable) {
      photoTarget.src = url;
      photoTarget.hidden = false;
      if (photoPlaceholder) photoPlaceholder.hidden = true;
      photoWrap?.classList.remove('is-empty');
      return;
    }
    photoTarget.removeAttribute('src');
    photoTarget.hidden = true;
    if (photoPlaceholder) photoPlaceholder.hidden = false;
    photoWrap?.classList.add('is-empty');
  };

  const syncCard = () => {
    const first = form.querySelector('[data-first-name]')?.value?.trim() || '';
    const last = form.querySelector('[data-last-name]')?.value?.trim() || '';
    if (nameTarget) {
      nameTarget.textContent = [first, last].filter(Boolean).join(' ') || nameTarget.dataset.fallback || '—';
    }
    if (numberTarget && numberInput) {
      numberTarget.textContent = `#${numberInput.value || '—'}`;
    }
    if (typeSelect) {
      const opt = typeSelect.selectedOptions[0];
      const typeLabel = (opt?.dataset.typeName || opt?.textContent.replace(/\s*\([^)]*€[^)]*\)\s*$/, '').replace(/\s*\(.+\)$/, '') || '').trim();
      if (typeTarget) {
        const role = typeTarget.dataset.role || 'Socio';
        typeTarget.replaceChildren();
        const strong = document.createElement('strong');
        strong.textContent = typeLabel ? `${role} ${typeLabel}` : role;
        typeTarget.appendChild(strong);
      }
      if (paymentTypeLabel) paymentTypeLabel.textContent = typeLabel || '—';
      const price = Number(opt?.dataset.price || 0);
      if (paymentAmount) {
        paymentAmount.textContent = money(price);
      }
      if (paymentDue) {
        const status = paymentStatus?.value || 'unpaid';
        let due = price;
        if (status === 'paid') {
          due = 0;
        } else if (status === 'partial') {
          const paid = Math.max(0, Number(partialAmountInput?.value || 0));
          due = Math.max(0, price - paid);
        }
        paymentDue.textContent = money(due);
        paymentDue.classList.toggle('stat-negative', due > 0);
        paymentDue.classList.toggle('stat-positive', due <= 0);
      }
    }
    const livePhoto = form.querySelector('.photo-upload .photo-preview, .photo-field .photo-preview');
    const liveSrc = (livePhoto?.currentSrc || livePhoto?.src || '').trim();
    if (liveSrc) {
      syncTesseraPhoto(liveSrc);
    } else if (!photoTarget?.getAttribute('src')) {
      syncTesseraPhoto('');
    }
  };

  paymentStatus?.addEventListener('change', syncCard);
  partialAmountInput?.addEventListener('input', syncCard);

  const showStep = (n, { animateEnter = true } = {}) => {
    step = Math.min(Math.max(n, 1), total);
    panels.forEach((panel) => {
      const id = Number(panel.dataset.wizardPanel);
      const active = id === step;
      panel.hidden = !active;
      panel.classList.toggle('is-active', active);
    });
    indicators.forEach((item) => {
      const id = Number(item.dataset.stepIndicator);
      item.classList.toggle('is-active', id === step);
      item.classList.toggle('is-done', id < step);
    });
    if (progress) {
      progress.style.width = `${(step / total) * 100}%`;
    }
    if (prevBtn) prevBtn.hidden = step === 1;
    if (nextBtn) {
      nextBtn.hidden = step === total;
      nextBtn.disabled = step === total;
    }
    if (submitBtn) {
      const last = step === total;
      submitBtn.hidden = !last;
      submitBtn.disabled = !last;
    }
    if (errorBox) errorBox.hidden = true;
    syncCard();
    form.dispatchEvent(new CustomEvent('wizard:step', { detail: { step } }));
    window.scrollTo({ top: 0, behavior: 'smooth' });
    if (animateEnter) {
      const activePanel = panels.find((panel) => !panel.hidden);
      if (activePanel) {
        window.requestAnimationFrame(() => enterScope(activePanel, { reset: true }));
      }
    }
  };

  const validateStep = () => {
    const panel = form.querySelector(`.wizard-panel[data-wizard-panel="${step}"]`);
    if (!panel) return true;
    const fields = [...panel.querySelectorAll('input, select, textarea')].filter((el) => {
      if (el.disabled || el.type === 'hidden') return false;
      if (el.type === 'file' && !el.matches('[data-photo-input]')) return false;
      if (el.closest('[hidden]')) return false;
      return true;
    });
    let ok = true;
    let firstInvalid = null;
    fields.forEach((el) => {
      if (el.matches('[data-photo-input]')) {
        const file = el.files && el.files[0];
        if (file) {
          const maxBytes = Number(el.dataset.maxBytes || 3 * 1024 * 1024);
          const type = String(file.type || '').toLowerCase();
          const allowed = ['image/jpeg', 'image/png', 'image/webp'];
          const invalidMsg = el.dataset.invalid || 'Foto non valida';
          const badType = type ? !allowed.includes(type) : !/\.(jpe?g|png|webp)$/i.test(file.name || '');
          if (file.size > maxBytes || badType) {
            el.setCustomValidity(invalidMsg);
            ok = false;
            el.classList.add('input-invalid');
            firstInvalid ||= el;
            const err = panel.querySelector('[data-camera-error]');
            if (err) {
              err.textContent = invalidMsg;
              err.hidden = false;
              err.classList.add('is-error');
            }
            return;
          }
        }
        el.setCustomValidity('');
        el.classList.remove('input-invalid');
        return;
      }
      if (el.matches('[data-phone-input]')) {
        const field = el.closest('[data-phone-field]');
        const dial = field?.querySelector('[data-phone-dial]')?.value || el.dataset.defaultDial || '39';
        el.value = stripDialFromNational(el.value, dial);
        el.value = formatNationalPhone(dial, el.value);
        const value = el.value.trim();
        const phoneOk = value === '' || isValidPhone(dial, value);
        if (value !== '' && !phoneOk) {
          el.setCustomValidity(el.dataset.invalid || 'Invalid phone');
        } else if (!el.required || value !== '') {
          el.setCustomValidity('');
        }
        if (field && value !== '') {
          el.value = `+${dial} ${value}`;
        }
      }
      if (el.matches('[data-email-input]')) {
        const value = el.value.trim();
        const emailOk = value === '' || /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value);
        if (value !== '' && !emailOk) {
          el.setCustomValidity('Email non valida');
        } else {
          el.setCustomValidity('');
        }
      }
      const mustFill = el.required || el.getAttribute('aria-required') === 'true';
      if (el.type === 'checkbox') {
        if (mustFill && !el.checked) {
          ok = false;
          el.classList.add('input-invalid');
          firstInvalid ||= el;
        }
        return;
      }
      if (!mustFill && !(el.matches('[data-phone-input], [data-email-input]') && el.value.trim() !== '')) {
        el.classList.remove('input-invalid');
        return;
      }
      if (!el.checkValidity() || (mustFill && typeof el.value === 'string' && el.value.trim() === '')) {
        ok = false;
        el.classList.add('input-invalid');
        firstInvalid ||= el;
      } else {
        el.classList.remove('input-invalid');
      }
    });
    if (!ok) {
      firstInvalid?.reportValidity?.();
      if (errorBox) errorBox.hidden = false;
    }
    return ok;
  };

  nextBtn?.addEventListener('click', () => {
    if (!validateStep()) return;
    showStep(step + 1);
  });
  prevBtn?.addEventListener('click', () => showStep(step - 1));
  indicators.forEach((item) => {
    item.addEventListener('click', () => {
      const target = Number(item.dataset.stepIndicator);
      if (target < step) {
        showStep(target);
        return;
      }
      if (target === step) return;
      // advance only if every intermediate step validates
      let ok = true;
      const current = step;
      for (let s = current; s < target; s += 1) {
        step = s;
        if (!validateStep()) {
          ok = false;
          break;
        }
      }
      if (ok) showStep(target);
      else showStep(step);
    });
  });
  numberInput?.addEventListener('input', syncCard);
  typeSelect?.addEventListener('change', syncCard);
  form.querySelector('[data-first-name]')?.addEventListener('input', syncCard);
  form.querySelector('[data-last-name]')?.addEventListener('input', syncCard);

  form.addEventListener('submit', (e) => {
    if (step !== total) {
      e.preventDefault();
      if (validateStep()) showStep(step + 1);
      return;
    }
    // Re-validate all steps before final submit (hidden panels included via temporarily revealing).
    for (let s = 1; s <= total; s += 1) {
      step = s;
      if (!validateStep()) {
        e.preventDefault();
        showStep(s);
        return;
      }
    }
    step = total;

    if (form.dataset.memberLeaveGuard === 'create' && form.dataset.treasuryEnabled === '1' && !form.dataset.treasuryResolved) {
      const status = paymentStatus?.value || 'unpaid';
      const opt = typeSelect?.selectedOptions?.[0];
      const price = Number(opt?.dataset.price || 0);
      let paid = 0;
      if (status === 'paid') paid = price;
      else if (status === 'partial') paid = Math.max(0, Number(partialAmountInput?.value || 0));
      if (paid > 0) {
        e.preventDefault();
        form.dataset.treasuryPendingAmount = String(paid);
        const askDialog = document.querySelector('[data-treasury-ask-dialog]');
        askDialog?.showModal?.();
        return;
      }
    }
  });

  const resolveTreasuryAndSubmit = (mode) => {
    const skipInput = form.querySelector('[data-treasury-skip]');
    const registerInput = form.querySelector('[data-treasury-register]');
    const dateInput = form.querySelector('[data-treasury-movement-date]');
    const descInput = form.querySelector('[data-treasury-description]');
    if (mode === 'skip') {
      if (skipInput) skipInput.value = '1';
      if (registerInput) registerInput.value = '';
    } else if (mode === 'register') {
      if (skipInput) skipInput.value = '';
      if (registerInput) registerInput.value = '1';
      const detailDate = document.querySelector('[data-treasury-detail-date]');
      const detailDesc = document.querySelector('[data-treasury-detail-description]');
      if (dateInput && detailDate) dateInput.value = detailDate.value || '';
      if (descInput && detailDesc) descInput.value = detailDesc.value || '';
    }
    form.dataset.treasuryResolved = '1';
    form.requestSubmit(submitBtn || undefined);
  };

  document.querySelector('[data-treasury-ask-no]')?.addEventListener('click', () => {
    document.querySelector('[data-treasury-ask-dialog]')?.close?.();
    resolveTreasuryAndSubmit('skip');
  });
  document.querySelector('[data-treasury-ask-yes]')?.addEventListener('click', () => {
    document.querySelector('[data-treasury-ask-dialog]')?.close?.();
    const paid = Number(form.dataset.treasuryPendingAmount || 0);
    const amountField = document.querySelector('[data-treasury-detail-amount]');
    const descField = document.querySelector('[data-treasury-detail-description]');
    const first = form.querySelector('[data-first-name]')?.value?.trim() || '';
    const last = form.querySelector('[data-last-name]')?.value?.trim() || '';
    const name = [first, last].filter(Boolean).join(' ');
    if (amountField) {
      amountField.value = `${paid.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
    }
    if (descField && !descField.value) {
      descField.placeholder = name ? `Quota socio ${name}` : '';
      descField.value = name ? `Quota socio ${name}` : '';
    }
    document.querySelector('[data-treasury-detail-dialog]')?.showModal?.();
  });
  document.querySelector('[data-treasury-detail-cancel]')?.addEventListener('click', () => {
    document.querySelector('[data-treasury-detail-dialog]')?.close?.();
    delete form.dataset.treasuryResolved;
  });
  document.querySelector('[data-treasury-detail-confirm]')?.addEventListener('click', () => {
    document.querySelector('[data-treasury-detail-dialog]')?.close?.();
    resolveTreasuryAndSubmit('register');
  });

  showStep(1, { animateEnter: false });
}

function initMembersList() {
  const root = document.querySelector('[data-members-list]');
  if (!root) return;

  const bulkBar = root.querySelector('[data-members-bulk-bar]');
  const bulkCount = root.querySelector('[data-members-bulk-count]');
  const bulkForm = document.querySelector('[data-members-bulk-form]');
  const bulkActionInput = bulkForm?.querySelector('[data-members-bulk-action-input]');
  const bulkIdHost = bulkForm?.querySelector('[data-members-bulk-id-host]');
  const selectAll = root.querySelector('[data-members-select-all]');
  const checkboxes = [...root.querySelectorAll('[data-member-select]')];
  const collectDialog = document.querySelector('[data-member-collect-dialog]');
  const collectForm = collectDialog?.querySelector('[data-member-collect-form]');
  const collectName = collectDialog?.querySelector('[data-member-collect-name]');
  const collectAmount = collectDialog?.querySelector('[data-member-collect-amount]');
  const emailDialog = document.querySelector('[data-members-bulk-email-dialog]');
  const emailSubject = emailDialog?.querySelector('[data-members-bulk-email-subject]');
  const emailBody = emailDialog?.querySelector('[data-members-bulk-email-body]');

  const selectedIds = () =>
    checkboxes.filter((cb) => cb.checked).map((cb) => cb.value);

  const syncBulkBar = () => {
    const ids = selectedIds();
    if (bulkBar) bulkBar.hidden = ids.length === 0;
    if (bulkCount) bulkCount.textContent = String(ids.length);
    if (selectAll) {
      selectAll.indeterminate = ids.length > 0 && ids.length < checkboxes.length;
      selectAll.checked = checkboxes.length > 0 && ids.length === checkboxes.length;
    }
  };

  checkboxes.forEach((cb) => cb.addEventListener('change', syncBulkBar));
  selectAll?.addEventListener('change', () => {
    const on = selectAll.checked;
    checkboxes.forEach((cb) => {
      cb.checked = on;
    });
    syncBulkBar();
  });

  const submitBulk = (action, extra = {}) => {
    if (!bulkForm || !bulkActionInput || !bulkIdHost) return;
    const ids = selectedIds();
    if (ids.length === 0) return;
    bulkActionInput.value = action;
    bulkIdHost.replaceChildren();
    ids.forEach((id) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'member_ids[]';
      input.value = id;
      bulkIdHost.appendChild(input);
    });
    if (extra.subject !== undefined && bulkForm.querySelector('[data-members-bulk-subject]')) {
      bulkForm.querySelector('[data-members-bulk-subject]').value = extra.subject;
    }
    if (extra.body !== undefined && bulkForm.querySelector('[data-members-bulk-body]')) {
      bulkForm.querySelector('[data-members-bulk-body]').value = extra.body;
    }
    bulkForm.hidden = false;
    bulkForm.requestSubmit();
  };

  root.querySelectorAll('[data-members-bulk-action]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const action = btn.getAttribute('data-members-bulk-action') || '';
      if (action === 'group_email') {
        emailDialog?.showModal?.();
        return;
      }
      if (action === 'mass_renewal') {
        const ok = window.confirm(btn.dataset.confirm || '');
        if (!ok) return;
      }
      submitBulk(action);
    });
  });

  emailDialog?.querySelector('[data-members-bulk-email-cancel]')?.addEventListener('click', () => emailDialog.close());
  emailDialog?.querySelector('[data-members-bulk-email-send]')?.addEventListener('click', () => {
    const subject = (emailSubject?.value || '').trim();
    const body = (emailBody?.value || '').trim();
    if (!subject || !body) return;
    emailDialog.close();
    submitBulk('group_email', { subject, body });
  });

  root.querySelectorAll('[data-member-collect]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-member-id') || '';
      const name = btn.getAttribute('data-member-name') || '';
      const balance = btn.getAttribute('data-member-balance') || '0';
      const base = root.getAttribute('data-members-base') || '/members';
      if (!collectForm || !collectDialog) return;
      collectForm.action = `${base}/${id}/payments`;
      if (collectName) collectName.textContent = name;
      if (collectAmount) collectAmount.value = balance;
      collectDialog.showModal();
    });
  });

  collectDialog?.querySelector('[data-member-collect-cancel]')?.addEventListener('click', () => collectDialog.close());

  syncBulkBar();
}

function initMemberForm() {
  const form = document.querySelector('[data-member-form]');
  if (!form) {
    return;
  }

  initPhoneInputs(form);
  initEmailValidation(form);
  initPhotoCapture(form);
  initPlaceSuggest(form);
  initFiscalCodeAuto(form);
  initMemberLeaveGuard(form);
  initEnrollmentPad(form);
  initEnrollmentOtp(form);
}

function initEnrollmentPad(form) {
  const pad = form.querySelector('[data-enrollment-pad]');
  if (!pad) return;
  const canvas = pad.querySelector('[data-sign-canvas]');
  const input = pad.querySelector('[data-sign-input]');
  const clearBtn = pad.querySelector('[data-sign-clear]');
  if (!canvas || !input) return;

  const ctx = canvas.getContext('2d');
  let drawing = false;
  let dirty = false;
  let lastCssW = 0;
  let lastCssH = 0;

  const paintBlank = (cssW, cssH) => {
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    const ratio = window.devicePixelRatio || 1;
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#1a1a1a';
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, cssW, cssH);
  };

  const resize = ({ force = false } = {}) => {
    const rect = canvas.getBoundingClientRect();
    const cssW = Math.floor(rect.width);
    const cssH = Math.floor(rect.height);
    // Panel still hidden / not laid out — wait until visible.
    if (cssW < 20 || cssH < 20) {
      return false;
    }
    if (!force && cssW === lastCssW && cssH === lastCssH && canvas.width > 0) {
      return true;
    }
    const ratio = window.devicePixelRatio || 1;
    const snapshot = dirty ? canvas.toDataURL('image/png') : '';
    canvas.width = Math.max(1, Math.floor(cssW * ratio));
    canvas.height = Math.max(1, Math.floor(cssH * ratio));
    lastCssW = cssW;
    lastCssH = cssH;
    paintBlank(cssW, cssH);
    if (snapshot) {
      const img = new Image();
      img.onload = () => {
        ctx.drawImage(img, 0, 0, cssW, cssH);
        input.value = canvas.toDataURL('image/png');
      };
      img.src = snapshot;
    } else {
      input.value = '';
      dirty = false;
    }
    return true;
  };

  resize();
  window.addEventListener('resize', () => resize());
  if (typeof ResizeObserver !== 'undefined') {
    const ro = new ResizeObserver(() => resize());
    ro.observe(pad);
  }
  form.addEventListener('wizard:step', () => {
    requestAnimationFrame(() => {
      requestAnimationFrame(() => resize({ force: true }));
    });
  });

  const pos = (e) => {
    const r = canvas.getBoundingClientRect();
    const t = e.touches ? e.touches[0] : e;
    const scaleX = r.width ? (lastCssW || r.width) / r.width : 1;
    const scaleY = r.height ? (lastCssH || r.height) / r.height : 1;
    return {
      x: (t.clientX - r.left) * scaleX,
      y: (t.clientY - r.top) * scaleY,
    };
  };
  const start = (e) => {
    if (!resize()) return;
    drawing = true;
    const p = pos(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
    e.preventDefault();
  };
  const move = (e) => {
    if (!drawing) return;
    const p = pos(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    dirty = true;
    e.preventDefault();
  };
  const end = () => {
    if (!drawing) return;
    drawing = false;
    if (dirty) {
      input.value = canvas.toDataURL('image/png');
      input.setCustomValidity('');
    }
  };

  canvas.addEventListener('mousedown', start);
  canvas.addEventListener('mousemove', move);
  window.addEventListener('mouseup', end);
  canvas.addEventListener('touchstart', start, { passive: false });
  canvas.addEventListener('touchmove', move, { passive: false });
  canvas.addEventListener('touchend', end);
  clearBtn?.addEventListener('click', () => {
    dirty = false;
    input.value = '';
    resize({ force: true });
  });

  form.addEventListener('submit', () => {
    if (!dirty || !input.value) {
      input.setCustomValidity(input.validationMessage || 'required');
    }
  });
}

function initEnrollmentOtp(form) {
  const wrap = form.querySelector('[data-enrollment-otp]');
  if (!wrap) return;
  const sendBtn = wrap.querySelector('[data-otp-send]');
  const status = wrap.querySelector('[data-otp-status]');
  const url = form.dataset.otpUrl;
  const csrf = form.dataset.csrf;
  sendBtn?.addEventListener('click', async () => {
    const email = form.querySelector('[name="fields[email]"]')?.value?.trim()
      || form.querySelector('input[type="email"]')?.value?.trim()
      || '';
    if (!url || !csrf) return;
    sendBtn.disabled = true;
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-TOKEN': csrf,
        },
        body: new URLSearchParams({ _token: csrf, email }),
        credentials: 'same-origin',
      });
      const data = await res.json();
      if (status) {
        status.hidden = false;
        status.textContent = data.message || data.error || '';
      }
    } catch (e) {
      if (status) {
        status.hidden = false;
        status.textContent = 'Error';
      }
    } finally {
      sendBtn.disabled = false;
    }
  });
}

function initMemberLeaveGuard(form) {
  const dialog = document.querySelector('[data-member-leave-dialog]');
  const yesBtn = dialog?.querySelector('[data-member-leave-yes]');
  const noBtn = dialog?.querySelector('[data-member-leave-no]');
  let dirty = false;
  let ready = false;
  let allowLeave = false;
  let pendingHref = '';

  window.setTimeout(() => { ready = true; }, 400);

  const markDirty = () => {
    if (ready) dirty = true;
  };

  form.addEventListener('input', markDirty, true);
  form.addEventListener('change', markDirty, true);
  form.querySelector('[data-photo-input]')?.addEventListener('change', markDirty);

  form.addEventListener('submit', () => {
    allowLeave = true;
    dirty = false;
  });

  const askLeave = async (href) => {
    pendingHref = href || '';
    if (dialog && typeof dialog.showModal === 'function') {
      dialog.showModal();
      return;
    }
    const text = dialog?.querySelector('.member-leave-text')?.textContent?.trim()
      || 'Stai per abbandonare l’inserimento. Sei sicuro?';
    if (await appConfirm(text)) {
      allowLeave = true;
      if (pendingHref) window.location.href = pendingHref;
    }
  };

  document.querySelectorAll('[data-member-leave]').forEach((link) => {
    link.addEventListener('click', (e) => {
      if (allowLeave || !dirty) return;
      e.preventDefault();
      askLeave(link.getAttribute('href') || '');
    });
  });

  // Also catch sidebar / other in-app links while the form is open.
  document.addEventListener('click', (e) => {
    if (allowLeave || !dirty) return;
    const link = e.target.closest?.('a[href]');
    if (!link) return;
    if (link.matches('[data-member-leave]')) return; // handled above
    if (link.hasAttribute('download') || link.getAttribute('target') === '_blank') return;
    if (link.closest('dialog')) return;
    const href = link.getAttribute('href') || '';
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
    // Same-page anchors / wizard internals: ignore
    try {
      const url = new URL(href, window.location.origin);
      if (url.pathname === window.location.pathname && url.search === window.location.search) {
        return;
      }
    } catch (_) {
      return;
    }
    e.preventDefault();
    askLeave(href);
  });

  yesBtn?.addEventListener('click', () => {
    allowLeave = true;
    dirty = false;
    dialog?.close();
    if (pendingHref) {
      window.location.href = pendingHref;
    }
  });

  noBtn?.addEventListener('click', () => {
    pendingHref = '';
    dialog?.close();
  });

  dialog?.addEventListener('cancel', (e) => {
    e.preventDefault();
    pendingHref = '';
    dialog.close();
  });
}

function initLeaveGuards(scope = document) {
  scope.querySelectorAll('form[data-leave-guard]').forEach((form) => {
    if (form.dataset.leaveGuardBound === '1' || form.hasAttribute('data-member-leave-guard') || form.hasAttribute('data-settings-autosave')) return;
    form.dataset.leaveGuardBound = '1';

    let dirty = false;
    let ready = false;
    let allowLeave = false;
    let pendingHref = '';
    window.setTimeout(() => { ready = true; }, 400);
    const markDirty = () => {
      if (ready) dirty = true;
    };
    form.addEventListener('input', markDirty, true);
    form.addEventListener('change', markDirty, true);
    const isDirty = () => dirty;

    const labels = {
      it: {
        title: 'Modifiche non salvate',
        text: 'Vuoi salvare le modifiche prima di uscire?',
        save: 'Salva',
        discard: 'Esci senza salvare',
        stay: 'Resta',
      },
      de: {
        title: 'Nicht gespeicherte Änderungen',
        text: 'Möchten Sie die Änderungen vor dem Verlassen speichern?',
        save: 'Speichern',
        discard: 'Ohne Speichern verlassen',
        stay: 'Bleiben',
      },
      en: {
        title: 'Unsaved changes',
        text: 'Would you like to save your changes before leaving?',
        save: 'Save',
        discard: 'Leave without saving',
        stay: 'Stay',
      },
    };
    const lang = (document.documentElement.lang || 'it').slice(0, 2);
    const copy = labels[lang] || labels.it;
    const dialog = document.createElement('dialog');
    dialog.className = 'member-leave-dialog';
    dialog.innerHTML = `
      <div class="member-leave-shell">
        <h3 class="section-title">${copy.title}</h3>
        <p class="member-leave-text">${copy.text}</p>
        <div class="member-leave-actions">
          <button type="button" class="btn btn-ghost" data-leave-stay>${copy.stay}</button>
          <button type="button" class="btn btn-ghost" data-leave-discard>${copy.discard}</button>
          <button type="button" class="btn" data-leave-save>${copy.save}</button>
        </div>
      </div>`;
    document.body.appendChild(dialog);

    const askLeave = async (href) => {
      pendingHref = href;
      if (typeof dialog.showModal === 'function') {
        dialog.showModal();
        return;
      }
      if (await appConfirm(copy.text)) {
        allowLeave = true;
        form.requestSubmit();
      }
    };

    form.addEventListener('submit', () => { allowLeave = true; });
    document.addEventListener('click', (event) => {
      if (allowLeave || !isDirty()) return;
      const link = event.target.closest?.('a[href]');
      if (!link || link.closest('dialog') || link.target === '_blank' || link.hasAttribute('download')) return;
      const href = link.getAttribute('href') || '';
      if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
      try {
        const url = new URL(href, window.location.href);
        if (url.href === window.location.href) return;
      } catch (_) {
        return;
      }
      event.preventDefault();
      askLeave(href);
    });
    dialog.querySelector('[data-leave-save]')?.addEventListener('click', () => {
      allowLeave = true;
      form.requestSubmit();
    });
    dialog.querySelector('[data-leave-discard]')?.addEventListener('click', () => {
      allowLeave = true;
      dialog.close();
      if (pendingHref) window.location.href = pendingHref;
    });
    dialog.querySelector('[data-leave-stay]')?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      dialog.close();
    });
  });
}

function initPhoneInputs(scope = document) {
  scope.querySelectorAll('[data-phone-field]').forEach((field) => {
    if (field.dataset.phoneBound === '1') return;
    field.dataset.phoneBound = '1';

    const dialSelect = field.querySelector('[data-phone-dial]');
    const input = field.querySelector('[data-phone-input]');
    if (!input) return;

    const invalidMsg = input.dataset.invalid || '';
    const defaultDial = (input.dataset.defaultDial || '39').replace(/\D/g, '') || '39';

    const decorateDial = () => {
      if (!dialSelect) return;
      const opt = dialSelect.options[dialSelect.selectedIndex];
      const flag = opt?.dataset?.flag || '';
      dialSelect.style.backgroundImage = flag ? `url("${flag}")` : '';
    };

    dialSelect?.addEventListener('change', () => {
      decorateDial();
      validate();
    });
    decorateDial();

    const validate = () => {
      const dial = dialSelect?.value || defaultDial;
      let national = stripDialFromNational(input.value, dial);
      national = formatNationalPhone(dial, national);
      input.value = national;
      const ok = national === '' || isValidPhone(dial, national);
      input.classList.toggle('input-valid', national !== '' && ok);
      input.classList.toggle('input-invalid', national !== '' && !ok);
      if (national !== '' && !ok) {
        input.setCustomValidity(invalidMsg || 'Invalid phone');
      } else {
        input.setCustomValidity('');
      }
    };

    const syncCombined = () => {
      const dial = dialSelect?.value || defaultDial;
      const national = stripDialFromNational(input.value, dial);
      if (national === '') {
        input.dataset.combinedPhone = '';
        return;
      }
      input.dataset.combinedPhone = `+${dial} ${formatNationalPhone(dial, national)}`;
    };

    input.addEventListener('blur', () => {
      validate();
      syncCombined();
    });
    input.addEventListener('change', () => {
      validate();
      syncCombined();
    });
    input.addEventListener('input', () => {
      input.setCustomValidity('');
      input.classList.remove('input-valid', 'input-invalid');
    });

    field.closest('form')?.addEventListener('submit', () => {
      validate();
      const combined = input.dataset.combinedPhone
        || (input.value.trim() !== ''
          ? `+${dialSelect?.value || defaultDial} ${formatNationalPhone(dialSelect?.value || defaultDial, stripDialFromNational(input.value, dialSelect?.value || defaultDial))}`
          : '');
      input.value = combined;
    }, { capture: true });

    if (input.value.trim() !== '') {
      validate();
    }
  });

  // Legacy bare inputs (no dial wrapper)
  scope.querySelectorAll('[data-phone-input]').forEach((input) => {
    if (input.closest('[data-phone-field]')) return;
    if (input.dataset.phoneLegacyBound === '1') return;
    input.dataset.phoneLegacyBound = '1';
    const invalidMsg = input.dataset.invalid || '';
    const validate = () => {
      input.value = formatNationalPhone('39', stripDialFromNational(input.value, '39'));
      const value = input.value.trim();
      const ok = value === '' || isValidPhone('39', value);
      input.classList.toggle('input-valid', value !== '' && ok);
      input.classList.toggle('input-invalid', value !== '' && !ok);
      input.setCustomValidity(value !== '' && !ok ? (invalidMsg || 'Invalid phone') : '');
    };
    input.addEventListener('blur', validate);
    input.addEventListener('change', validate);
    if (input.value.trim() !== '') validate();
  });
}

const PHONE_DIAL_CODES = [
  '351', '353', '358', '359', '370', '371', '372', '373', '375', '380', '385', '386', '387', '389', '420', '421',
  '49', '43', '41', '33', '34', '44', '39', '32', '31', '48', '36', '40', '30', '381', '382', '355', '46', '47',
  '45', '354', '90', '7', '1', '52', '55', '54', '56', '57', '51', '61', '64', '81', '86', '82', '91', '65', '852',
  '886', '971', '966', '972', '20', '27', '212', '216',
];

function stripDialFromNational(raw, dial) {
  let value = String(raw || '').trim();
  if (!value) return '';
  const code = String(dial || '39').replace(/\D/g, '');
  value = value.replace(/[^\d+\s]/g, '');
  if (value.startsWith('+')) {
    const digits = value.replace(/\D/g, '');
    for (const prefix of PHONE_DIAL_CODES) {
      if (digits.startsWith(prefix) && digits.length > prefix.length + 3) {
        return digits.slice(prefix.length).replace(/(\d{3,4})(?=\d)/g, '$1 ').trim();
      }
    }
  }
  if (value.startsWith('00')) {
    const digits = value.replace(/\D/g, '').slice(2);
    for (const prefix of PHONE_DIAL_CODES) {
      if (digits.startsWith(prefix) && digits.length > prefix.length + 3) {
        return digits.slice(prefix.length).replace(/(\d{3,4})(?=\d)/g, '$1 ').trim();
      }
    }
  }
  const digits = value.replace(/\D/g, '');
  if (code && digits.startsWith(code) && digits.length > code.length + 3) {
    return digits.slice(code.length).replace(/(\d{3,4})(?=\d)/g, '$1 ').trim();
  }
  return value.replace(/\D/g, '').replace(/(\d{3,4})(?=\d)/g, '$1 ').trim();
}

function formatNationalPhone(dial, raw) {
  const code = String(dial || '39').replace(/\D/g, '') || '39';
  let digits = stripDialFromNational(raw, code).replace(/\D/g, '');
  if (!digits) return '';
  if (code === '39') {
    if (digits.startsWith('3') && digits.length >= 9) {
      return digits.replace(/^(\d{3})(\d{3})(\d+)$/, '$1 $2 $3');
    }
    if (digits.length >= 6) {
      return digits.replace(/^(\d{2,4})(\d{0,3})(\d*)$/, (_, a, b, c) => [a, b, c].filter(Boolean).join(' '));
    }
  }
  return digits.replace(/(\d{3,4})(?=\d)/g, '$1 ').trim();
}

function isValidPhone(dial, raw) {
  const code = String(dial || '39').replace(/\D/g, '') || '39';
  const digits = stripDialFromNational(raw, code).replace(/\D/g, '');
  if (!digits || digits.length < 4 || digits.length > 14) return false;
  if (code === '39') {
    return /^(?:3\d{8,9}|0\d{5,10})$/.test(digits);
  }
  return /^\d{4,14}$/.test(digits);
}

function isValidItalianPhone(raw) {
  return isValidPhone('39', raw);
}

function formatItalianPhone(raw) {
  const national = stripDialFromNational(raw, '39');
  return national === '' ? '' : `+39 ${formatNationalPhone('39', national)}`;
}

function uploadLogoWithProgress(url, formData, csrf, onProgress) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable && typeof onProgress === 'function') {
        onProgress(event.loaded / event.total);
      }
    };
    xhr.onload = () => {
      let data = {};
      try {
        data = JSON.parse(xhr.responseText || '{}');
      } catch (err) {
        reject(err);
        return;
      }
      if (xhr.status >= 200 && xhr.status < 300 && data.ok) {
        resolve(data);
        return;
      }
      reject(new Error(typeof data.error === 'string' ? data.error : 'upload failed'));
    };
    xhr.onerror = () => reject(new Error('upload failed'));
    xhr.send(formData);
  });
}

function initEmailValidation(scope) {
  scope.querySelectorAll('[data-email-input]').forEach((input) => {
    const hint = scope.querySelector('[data-email-hint]');
    const validate = () => {
      const value = input.value.trim();
      const ok = value === '' || /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value);
      input.classList.toggle('input-valid', value !== '' && ok);
      input.classList.toggle('input-invalid', value !== '' && !ok);
      if (hint) {
        hint.textContent = value && !ok ? 'Email non valida' : '';
      }
    };
    input.addEventListener('blur', validate);
    input.addEventListener('input', () => input.classList.remove('input-valid', 'input-invalid'));
  });
}

function initPhotoCapture(form) {
  const input = form.querySelector('[data-photo-input]');
  const captureInput = form.querySelector('[data-camera-capture]');
  const dialog = document.querySelector('[data-camera-dialog]');
  const openBtn = form.querySelector('[data-camera-open]');
  const closeBtn = document.querySelector('[data-camera-close]');
  const shotBtn = document.querySelector('[data-camera-shot]');
  const video = document.querySelector('[data-camera-video]');
  const canvas = document.querySelector('[data-camera-canvas]');
  const errorBox = form.querySelector('[data-camera-error]');
  const hint = form.querySelector('[data-photo-hint]');
  const invalidMsg = (input?.dataset.invalid || hint?.dataset.invalid || '').trim()
    || 'Foto non valida (JPG, PNG o WEBP, max 3 MB).';
  const maxBytes = Number(input?.dataset.maxBytes || 3 * 1024 * 1024);
  const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
  let stream = null;

  const isMobileLike = () => {
    const ua = navigator.userAgent || '';
    return /Android|iPhone|iPad|iPod|Mobile/i.test(ua)
      || ((navigator.maxTouchPoints || 0) > 1 && /Macintosh/i.test(ua));
  };

  const canUseLiveCamera = () => !!(
    window.isSecureContext
    && navigator.mediaDevices?.getUserMedia
    && dialog
    && video
  );

  const canUseNativeCapture = () => !!(captureInput && isMobileLike());

  const cameraAvailable = canUseLiveCamera() || canUseNativeCapture();
  if (openBtn) {
    openBtn.hidden = !cameraAvailable;
  }
  if (!cameraAvailable) {
    captureInput?.remove();
    if (hint && hint.dataset.uploadOnly) {
      hint.textContent = hint.dataset.uploadOnly;
    }
  }

  const showError = (msg) => {
    if (!errorBox) return;
    errorBox.textContent = msg || '';
    errorBox.hidden = !msg;
    errorBox.classList.toggle('is-error', !!msg);
  };

  const clearPhotoPreview = () => {
    const img = form.querySelector('.photo-upload .photo-preview, .photo-field .photo-preview');
    if (img) {
      const ph = document.createElement('div');
      ph.className = 'photo-placeholder';
      ph.setAttribute('aria-hidden', 'true');
      img.replaceWith(ph);
    }
    const tesseraPhoto = form.querySelector('[data-tessera-photo]');
    const tesseraPh = form.querySelector('[data-tessera-photo-placeholder]');
    const tesseraWrap = form.querySelector('[data-tessera-photo-wrap]');
    if (tesseraPhoto) {
      tesseraPhoto.removeAttribute('src');
      tesseraPhoto.hidden = true;
    }
    if (tesseraPh) tesseraPh.hidden = false;
    tesseraWrap?.classList.add('is-empty');
  };

  const validatePhotoFile = (file) => {
    if (!file) return '';
    if ((file.size || 0) > maxBytes) {
      return invalidMsg;
    }
    const type = String(file.type || '').toLowerCase();
    if (type && !allowedTypes.includes(type)) {
      return invalidMsg;
    }
    if (!type) {
      const name = String(file.name || '').toLowerCase();
      if (!/\.(jpe?g|png|webp)$/.test(name)) {
        return invalidMsg;
      }
    }
    return '';
  };

  const acceptPhotoFile = (file) => {
    if (!input || !file) return false;
    const err = validatePhotoFile(file);
    if (err) {
      input.value = '';
      try {
        const dt = new DataTransfer();
        input.files = dt.files;
      } catch (_) {}
      clearPhotoPreview();
      showError(err);
      input.setCustomValidity(err);
      input.classList.add('input-invalid');
      return false;
    }
    input.setCustomValidity('');
    input.classList.remove('input-invalid');
    showError('');
    return true;
  };

  const assignFile = (file) => {
    if (!input || !file) return;
    if (!acceptPhotoFile(file)) return;
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    showPreview(file);
  };

  const showPreview = (file) => {
    if (!file) return;
    const url = URL.createObjectURL(file);
    let img = form.querySelector('.photo-preview');
    const placeholder = form.querySelector('.photo-placeholder');
    if (!img) {
      img = document.createElement('img');
      img.className = 'photo-preview';
      img.alt = '';
      if (placeholder) placeholder.replaceWith(img);
      else form.querySelector('.photo-upload')?.prepend(img);
    }
    img.src = url;
    const tesseraPhoto = form.querySelector('[data-tessera-photo]');
    const tesseraPh = form.querySelector('[data-tessera-photo-placeholder]');
    const tesseraWrap = form.querySelector('[data-tessera-photo-wrap]');
    if (tesseraPhoto) {
      tesseraPhoto.src = url;
      tesseraPhoto.hidden = false;
      if (tesseraPh) tesseraPh.hidden = true;
      tesseraWrap?.classList.remove('is-empty');
    }
  };

  input?.addEventListener('change', () => {
    const file = input.files && input.files[0];
    if (!file) {
      showError('');
      input.setCustomValidity('');
      return;
    }
    if (!acceptPhotoFile(file)) return;
    showPreview(file);
  });

  captureInput?.addEventListener('change', () => {
    const file = captureInput.files && captureInput.files[0];
    if (!file) return;
    assignFile(file);
    captureInput.value = '';
  });

  const stopCamera = () => {
    if (stream) {
      stream.getTracks().forEach((t) => t.stop());
      stream = null;
    }
    if (video) video.srcObject = null;
  };

  const openNativeCamera = () => {
    if (!captureInput) return false;
    captureInput.click();
    return true;
  };

  const startLiveCamera = async () => {
    if (!canUseLiveCamera()) {
      return false;
    }
    const attempts = [
      { video: { facingMode: { ideal: 'user' } }, audio: false },
      { video: { facingMode: 'environment' }, audio: false },
      { video: true, audio: false },
    ];
    for (const constraints of attempts) {
      try {
        stream = await navigator.mediaDevices.getUserMedia(constraints);
        video.srcObject = stream;
        await video.play().catch(() => {});
        dialog.showModal();
        showError('');
        return true;
      } catch (err) {
        stopCamera();
      }
    }
    return false;
  };

  if (!cameraAvailable) {
    return;
  }

  openBtn?.addEventListener('click', async (e) => {
    e.preventDefault();
    e.stopPropagation();
    showError('');
    const live = await startLiveCamera();
    if (live) return;
    if (canUseNativeCapture()) {
      openNativeCamera();
      return;
    }
    // API presente ma dispositivo senza fotocamera: nascondi il bottone
    if (openBtn) openBtn.hidden = true;
    captureInput?.remove();
  });

  closeBtn?.addEventListener('click', () => {
    stopCamera();
    dialog?.close();
  });

  dialog?.addEventListener('close', stopCamera);

  shotBtn?.addEventListener('click', async () => {
    if (!video || !canvas || !input) return;
    const w = video.videoWidth || 1280;
    const h = video.videoHeight || 720;
    if (!w || !h) {
      showError(errorBox?.dataset?.notReady || '');
      return;
    }
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, w, h);
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.92));
    if (!blob) return;
    assignFile(new File([blob], 'camera-photo.jpg', { type: 'image/jpeg' }));
    stopCamera();
    dialog.close();
  });
}

function debounce(fn, wait = 280) {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), wait);
  };
}

function initLogoFilePickers(scope = document) {
  scope.querySelectorAll('[data-setup-logo]').forEach((root) => {
    const input = root.querySelector('[data-setup-logo-input]');
    if (!input || input.dataset.logoPickerBound === '1') return;
    input.dataset.logoPickerBound = '1';

    const uploadUrl = root.dataset.logoUploadUrl || '';
    const csrf = root.dataset.csrf || '';
    const msgFail = root.dataset.msgFail || '';
    const msgUploading = root.dataset.msgUploading || '';
    const preview = root.querySelector('[data-setup-logo-preview]');
    const img = root.querySelector('[data-setup-logo-img]');
    const removeBtn = root.querySelector('[data-setup-logo-remove]');
    const statusEl = root.querySelector('[data-setup-logo-status]');
    const progressWrap = root.querySelector('[data-setup-logo-progress]');
    const progressBar = root.querySelector('[data-setup-logo-progress-bar]');
    let uploading = false;

    const setProgress = (ratio) => {
      if (!progressWrap || !progressBar) return;
      if (ratio == null || ratio < 0) {
        progressWrap.hidden = true;
        progressBar.style.setProperty('--progress', '0%');
        return;
      }
      progressWrap.hidden = false;
      progressBar.style.setProperty('--progress', `${Math.max(0, Math.min(100, Math.round(ratio * 100)))}%`);
    };

    const setStatus = (text, isError = false) => {
      if (!statusEl) return;
      if (!text) {
        statusEl.hidden = true;
        statusEl.textContent = '';
        statusEl.classList.remove('is-error');
        return;
      }
      statusEl.hidden = false;
      statusEl.textContent = text;
      statusEl.classList.toggle('is-error', isError);
    };

    const hidePreview = () => {
      if (preview) preview.hidden = true;
      if (img) {
        img.hidden = true;
        img.removeAttribute('src');
      }
      removeBtn?.setAttribute('hidden', '');
    };

    const showPreview = (url) => {
      if (!img || !preview || !url) return;
      preview.classList.add('is-loading');
      preview.hidden = false;
      img.hidden = true;
      img.onload = () => {
        img.hidden = false;
        preview.hidden = false;
        preview.classList.remove('is-loading');
        removeBtn?.removeAttribute('hidden');
      };
      img.onerror = () => {
        preview.classList.remove('is-loading');
        hidePreview();
      };
      img.src = url;
    };

    const applyLogoResponse = (data) => {
      const url = data.logo_url ? `${data.logo_url}?v=${Date.now()}` : '';
      if (url) {
        showPreview(url);
      } else {
        hidePreview();
      }
      if (data.primary || data.accent) {
        applyBrandColors(data.primary, data.accent);
      }
    };

    input.addEventListener('change', async () => {
      const file = input.files && input.files[0] ? input.files[0] : null;
      if (!file) {
        hidePreview();
        return;
      }
      if (!file.type.startsWith('image/') && !/\.svg$/i.test(file.name)) {
        setStatus(msgFail, true);
        hidePreview();
        input.value = '';
        return;
      }

      setStatus('');
      const localUrl = URL.createObjectURL(file);
      showPreview(localUrl);

      if (!uploadUrl) {
        return;
      }

      uploading = true;
      preview?.classList.add('is-uploading');
      setStatus(msgUploading);
      setProgress(0);
      try {
        const body = new FormData();
        body.set('_token', csrf);
        body.set('logo', file);
        const data = await uploadLogoWithProgress(uploadUrl, body, csrf, setProgress);
        URL.revokeObjectURL(localUrl);
        setProgress(null);
        setStatus('');
        applyLogoResponse(data);
      } catch (err) {
        URL.revokeObjectURL(localUrl);
        setProgress(null);
        setStatus(typeof err?.message === 'string' && err.message !== 'upload failed' ? err.message : msgFail, true);
        hidePreview();
      } finally {
        uploading = false;
        preview?.classList.remove('is-uploading');
        input.value = '';
      }
    });

    removeBtn?.addEventListener('click', async () => {
      if (uploading || !uploadUrl) return;
      uploading = true;
      removeBtn.disabled = true;
      setStatus(msgUploading);
      try {
        const body = new URLSearchParams();
        body.set('_token', csrf);
        body.set('remove', '1');
        const res = await fetch(uploadUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf,
          },
          body,
          credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
          setStatus(typeof data.error === 'string' ? data.error : msgFail, true);
          return;
        }
        if (filename) filename.textContent = noFileText;
        input.value = '';
        setStatus('');
        applyLogoResponse(data);
      } catch (err) {
        setStatus(msgFail, true);
      } finally {
        uploading = false;
        removeBtn.disabled = false;
      }
    });
  });
}

function initSettingsAssociationForms() {
  document.querySelectorAll('[data-settings-geo]').forEach((form) => {
    initPlaceSuggest(form);
    initSetupNamePairPreview(form);
  });
  document.querySelectorAll('[data-settings-brand]').forEach((scope) => {
    initSetupBrandingPalettes(scope.closest('form') || scope);
  });
  document.querySelectorAll('form[data-settings-autosave]').forEach((form) => {
    initSetupBrandingPalettes(form);
  });
  document.querySelectorAll('[data-people-list]').forEach((list) => {
    initPeopleList(list);
  });
  initSetupMemberTypes(document);
  initSetupMembershipPeriods(document);
  initSetupEnrollment(document);
}

function initLegalDocEditors(scope = document) {
  scope.querySelectorAll('[data-legal-doc-editor]').forEach((root) => {
    if (root.dataset.legalDocBound === '1') {
      return;
    }
    root.dataset.legalDocBound = '1';

    const tabs = Array.from(root.querySelectorAll('[data-legal-tab]'));
    const panels = Array.from(root.querySelectorAll('[data-legal-panel]'));
    const activate = (code) => {
      tabs.forEach((tab) => {
        const active = tab.dataset.legalTab === code;
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.tabIndex = active ? 0 : -1;
      });
      panels.forEach((panel) => {
        panel.hidden = panel.dataset.legalPanel !== code;
      });
    };
    tabs.forEach((tab) => {
      tab.addEventListener('click', () => activate(tab.dataset.legalTab || 'it'));
    });

    const translateBtn = root.querySelector('[data-legal-translate]');
    const source = root.querySelector('[data-legal-textarea="it"]');
    const targetDe = root.querySelector('[data-legal-textarea="de"]');
    const targetEn = root.querySelector('[data-legal-textarea="en"]');
    if (!translateBtn || !source || !targetDe || !targetEn) {
      return;
    }

    const url = translateBtn.dataset.translateUrl || '';
    const busyMsg = translateBtn.dataset.msgBusy || '…';
    const emptyMsg = translateBtn.dataset.msgEmpty || '';
    const failMsg = translateBtn.dataset.msgFail || '';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    translateBtn.addEventListener('click', async () => {
      const text = String(source.value || '').trim();
      if (!text) {
        window.alert(emptyMsg || failMsg);
        return;
      }
      const original = translateBtn.textContent;
      translateBtn.disabled = true;
      translateBtn.textContent = busyMsg;
      try {
        for (const [target, el] of [['de', targetDe], ['en', targetEn]]) {
          const res = await fetch(url, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-Token': csrf,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ text, target }),
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok || !data.ok || !data.text) {
            throw new Error(data.message || failMsg);
          }
          el.value = data.text;
          el.dispatchEvent(new Event('input', { bubbles: true }));
        }
      } catch {
        window.alert(failMsg);
      } finally {
        translateBtn.disabled = false;
        translateBtn.textContent = original;
      }
    });
  });
}

function initSettingsAutosave(scope = document) {
  const root = scope.querySelector('[data-config-accordions]');
  const busyMsg = root?.dataset.autosaveBusy || 'Saving…';
  const okMsg = root?.dataset.autosaveOk || 'Saved';
  const failMsg = root?.dataset.autosaveFail || 'Could not save';
  const agoTemplate = root?.dataset.autosaveAgo || 'Saved :time ago';

  const formatAgo = (seconds) => {
    if (seconds < 60) {
      return `${seconds}s`;
    }
    const mins = Math.floor(seconds / 60);
    return `${mins} min`;
  };

  scope.querySelectorAll('form[data-settings-autosave]').forEach((form) => {
    if (form.dataset.settingsAutosaveBound === '1') {
      return;
    }
    form.dataset.settingsAutosaveBound = '1';

    const statusEl = form.querySelector('[data-settings-autosave-status]');
    let timer = null;
    let agoTimer = null;
    let lastSavedAt = 0;

    const setStatus = (kind, text) => {
      if (!statusEl) {
        return;
      }
      statusEl.textContent = text;
      statusEl.classList.remove('is-ok', 'is-error', 'is-busy');
      if (kind) {
        statusEl.classList.add(`is-${kind}`);
      }
    };

    const updateAgo = () => {
      if (!lastSavedAt || !statusEl) {
        return;
      }
      const secs = Math.max(1, Math.floor((Date.now() - lastSavedAt) / 1000));
      setStatus('ok', agoTemplate.replace(':time', formatAgo(secs)));
    };

    const saveNow = async () => {
      window.clearTimeout(timer);
      setStatus('busy', busyMsg);
      const fd = new FormData(form);
      try {
        const res = await fetch(form.action, {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
          setStatus('error', data.message || failMsg);
          return;
        }
        lastSavedAt = Date.now();
        setStatus('ok', okMsg);
        window.clearInterval(agoTimer);
        agoTimer = window.setInterval(updateAgo, 5000);
        updateAgo();
      } catch {
        setStatus('error', failMsg);
      }
    };

    const scheduleSave = () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(saveNow, 800);
    };

    form.addEventListener('input', scheduleSave, true);
    form.addEventListener('change', scheduleSave, true);
  });
}

function initSetupNamePairPreview(scope = document) {
  scope.querySelectorAll('[data-setup-name-pair]').forEach((root) => {
    if (root.dataset.namePairBound === '1') return;
    root.dataset.namePairBound = '1';

    const nameInput = root.querySelector('[data-setup-assoc-name]');
    const legalSelect = root.querySelector('[data-setup-legal-name]');
    const preview = root.querySelector('[data-setup-full-name-preview]');
    const legalMeaning = root.querySelector('[data-setup-legal-meaning]');
    const template = root.dataset.previewTemplate || '';
    if (!nameInput || !legalSelect || !preview || !template) return;

    const capitalizeName = (raw) => {
      const name = String(raw || '').trim();
      if (!name) return '';
      return name.charAt(0).toLocaleUpperCase('it-IT') + name.slice(1);
    };

    const syncLegalMeaning = () => {
      if (!legalMeaning) return;
      const opt = legalSelect.selectedOptions[0];
      const raw = opt ? String(opt.textContent || '') : '';
      const parts = raw.split('—');
      const meaning = parts.length > 1 ? parts.slice(1).join('—').trim() : '';
      if (!legalSelect.value || meaning === '') {
        legalMeaning.hidden = true;
        legalMeaning.textContent = '';
        return;
      }
      legalMeaning.textContent = meaning;
      legalMeaning.hidden = false;
    };

    const sync = () => {
      const name = capitalizeName(nameInput.value);
      const legal = String(legalSelect.value || '').trim().toUpperCase();
      if (!name || !legal) {
        preview.hidden = true;
        preview.textContent = '';
        return;
      }
      const full = nameContainsLegal(name, legal) ? name : `${name} ${legal}`;
      const safeName = full
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
      preview.innerHTML = template.replaceAll(
        ':name',
        `<strong class="setup-full-name-value">${safeName}</strong>`
      );
      preview.hidden = false;
    };

    nameInput.addEventListener('input', sync);
    nameInput.addEventListener('change', sync);
    legalSelect.addEventListener('change', () => {
      sync();
      syncLegalMeaning();
    });
    sync();
    syncLegalMeaning();
  });
}

function initSetupBrandingPalettes(root) {
  const preview = root.querySelector('[data-brand-preview]');
  const primaryInput = root.querySelector('[data-brand-color="primary"]');
  const accentInput = root.querySelector('[data-brand-color="accent"]');
  const syncPreview = () => {
    if (!primaryInput?.value && !accentInput?.value) return;
    let primary = primaryInput?.value || '';
    let accent = accentInput?.value || '';
    [primary, accent] = brandEnsureDistinctColors(primary, accent);
    if (primaryInput && primaryInput.value.toUpperCase() !== primary) {
      primaryInput.value = primary;
    }
    if (accentInput && accentInput.value.toUpperCase() !== accent) {
      accentInput.value = accent;
    }
    primaryInput?.closest('.setup-color-picker-control')?.querySelector('code')?.replaceChildren(primary);
    accentInput?.closest('.setup-color-picker-control')?.querySelector('code')?.replaceChildren(accent);
    applyBrandColors(primary, accent);
    if (preview) {
      preview.style.setProperty('--brand-primary', primary);
      preview.style.setProperty('--brand-accent', accent);
      applyReadableBrandVars(primary, accent, preview);
    }
  };
  const markSelected = () => {
    const primary = (primaryInput?.value || '').toUpperCase();
    const accent = (accentInput?.value || '').toUpperCase();
    root.querySelectorAll('[data-palette-pick]').forEach((el) => {
      const match = (el.dataset.primary || '').toUpperCase() === primary
        && (el.dataset.accent || '').toUpperCase() === accent;
      el.classList.toggle('is-selected', match);
    });
  };
  primaryInput?.addEventListener('input', () => {
    syncPreview();
    markSelected();
  });
  accentInput?.addEventListener('input', () => {
    syncPreview();
    markSelected();
  });
  root.querySelectorAll('[data-palette-pick]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const primary = btn.dataset.primary || '';
      const accent = btn.dataset.accent || '';
      if (primaryInput && primary) primaryInput.value = primary;
      if (accentInput && accent) accentInput.value = accent;
      syncPreview();
      markSelected();
    });
  });
  syncPreview();
  markSelected();
}

function nameContainsAps(name) {
  const raw = String(name || '').trim();
  if (!raw) return false;
  const upper = raw.toLocaleUpperCase('it-IT');
  if (/(?:^|[\s\-–,.;:\/(])APS(?:$|[\s\-–,.;:\/)])/.test(upper)) return true;
  return /associazione\s+di\s+promozione\s+sociale/i.test(raw);
}

function nameContainsLegal(name, legal) {
  const raw = String(name || '').trim();
  const code = String(legal || '').trim().toUpperCase();
  if (!raw || !code) return false;
  if (code === 'APS') return nameContainsAps(raw);
  const upper = raw.toLocaleUpperCase('it-IT');
  const escaped = code.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(`(?:^|[\\s\\-–,.;:\\/(])${escaped}(?:$|[\\s\\-–,.;:\\/)])`).test(upper);
}

function normalizeWebsiteInput(value) {
  let raw = String(value || '').trim();
  if (/\s/.test(raw)) return '';
  raw = raw.replace(/\s+/g, '').replace(/[.,;]+$/, '');
  if (!raw) return '';
  raw = raw.replace(/^(?:URL|Sito|Website)\s*[:=]\s*/i, '');
  if (!/^https?:\/\//i.test(raw)) {
    raw = raw.replace(/^\/\//, '');
    raw = `https://${raw}`;
  }
  try {
    const u = new URL(raw);
    if (!u.hostname || !u.hostname.includes('.')) return '';
    const host = u.hostname.replace(/^www\./i, '').toLowerCase();
    if (host.length > 253) return '';
    let out = `${u.protocol}//${u.hostname.toLowerCase()}`;
    if (u.pathname && u.pathname !== '/') out += u.pathname;
    if (u.search) out += u.search;
    return out;
  } catch (_) {
    return '';
  }
}

function isWebsiteScrapeAllowed(value) {
  const raw = String(value || '').trim();
  if (raw === '' || /\s/.test(raw)) return false;
  const normalized = normalizeWebsiteInput(raw);
  if (!normalized) return false;
  try {
    const host = new URL(normalized).hostname.replace(/^www\./i, '');
    if (host.length > 80) return false;
    const label = host.split('.')[0] || '';
    if (label.length > 63) return false;
    return true;
  } catch {
    return false;
  }
}

function initSetupSmtp(root) {
  const box = root.querySelector('[data-setup-smtp]');
  if (!box || box.dataset.smtpReady === '1') return;
  box.dataset.smtpReady = '1';

  const form = box.closest('form') || root.querySelector('[data-setup-form]');
  const skip = box.querySelector('[data-smtp-skip]');
  const skipHint = box.querySelector('[data-smtp-skip-hint]');
  const fields = box.querySelector('[data-smtp-fields]');
  const discoverRow = box.querySelector('[data-smtp-discover-row]');
  const smtpLive = box.querySelector('[data-smtp-live]');
  const discoverBtn = box.querySelector('[data-smtp-discover-btn]');
  const verifyBtn = box.querySelector('[data-smtp-verify-btn]');
  const testBtn = box.querySelector('[data-smtp-test-btn]');
  const testSection = box.querySelector('[data-smtp-test-section]');
  const discoverStatus = box.querySelector('[data-smtp-discover-status]');
  const verifyStatus = box.querySelector('[data-smtp-verify-status]');
  const testStatus = box.querySelector('[data-smtp-test-status]');
  const manual = box.querySelector('[data-smtp-manual]');
  const fromInput = box.querySelector('[data-smtp-from]');
  const passwordInput = box.querySelector('[data-smtp-password]');
  const fromHint = box.querySelector('[data-smtp-from-hint]');
  const passwordHint = box.querySelector('[data-smtp-password-hint]');
  const testToInput = box.querySelector('[data-smtp-test-to]');
  const hostInput = box.querySelector('[data-smtp-host]');
  const portInput = box.querySelector('[data-smtp-port]');
  const encryptionInput = box.querySelector('[data-smtp-encryption]');
  const usernameInput = box.querySelector('[data-smtp-username]');
  const fromNameInput = box.querySelector('[data-smtp-from-name]');
  let connectionOk = !!(testSection && !testSection.hasAttribute('hidden'));
  const discoverUrl = box.dataset.discoverUrl || '';
  const testUrl = box.dataset.testUrl || '';
  const discoverOkTpl = box.dataset.discoverOk || '';
  const verifyOkTpl = box.dataset.verifyOk || discoverOkTpl;
  const discoverFail = box.dataset.discoverFail || '';
  const discoverBusy = box.dataset.discoverBusy || '';
  const testBusy = box.dataset.testBusy || '';
  const testOkMsg = box.dataset.testOk || '';
  const verifyBusy = box.dataset.verifyBusy || discoverBusy;
  const csrf =
    box.dataset.csrf ||
    form?.dataset.csrf ||
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
    form?.querySelector('input[name="_token"]')?.value ||
    '';

  const requiredInputs = fields ? [...fields.querySelectorAll('[required]')] : [];
  let savedConnectionOk = connectionOk;
  const initialManual = box.dataset.smtpInitialManual === '1';
  let manualRevealedByFailure = false;
  const wizardRoot = root.matches?.('[data-setup-wizard]') ? root : root.querySelector?.('[data-setup-wizard]');

  const setSmtpBusy = (busy) => {
    box.classList.toggle('is-smtp-busy', !!busy);
    wizardRoot?.classList.toggle('is-smtp-busy', !!busy);
    if (smtpLive) smtpLive.hidden = !busy;
    const disable = !!busy || !!skip?.checked;
    box.querySelectorAll('input, select, textarea, button').forEach((el) => {
      if (el === skip) return;
      if (busy) {
        el.dataset.smtpBusyWasDisabled = el.disabled ? '1' : '';
        el.disabled = true;
      } else if (el.dataset.smtpBusyWasDisabled !== undefined) {
        el.disabled = el.dataset.smtpBusyWasDisabled === '1';
        delete el.dataset.smtpBusyWasDisabled;
      }
    });
    if (!busy && discoverBtn && !skip?.checked && !manual?.hidden) {
      discoverBtn.disabled = false;
    }
    if (!busy && verifyBtn && !skip?.checked) {
      verifyBtn.disabled = false;
    }
    if (!busy && testBtn) {
      testBtn.disabled = !connectionOk || !!skip?.checked;
    }
  };

  const setManualMode = (visible) => {
    if (manual) manual.hidden = !visible;
    if (discoverRow) discoverRow.hidden = visible;
  };

  // Never show the test block until SMTP was validated in this session / previously.
  setManualMode(initialManual);
  if (!connectionOk) {
    if (testSection) testSection.hidden = true;
    if (testToInput) {
      testToInput.disabled = true;
      testToInput.required = false;
    }
    if (testBtn) testBtn.disabled = true;
  }

  const showStatus = (el, message, isError = false) => {
    if (!el) return;
    el.textContent = message || '';
    el.hidden = !message;
    el.classList.toggle('setup-hint-warn', !!isError && !!message);
    el.classList.toggle('setup-smtp-status-ok', !isError && !!message);
    el.classList.toggle('is-error', !!isError && !!message);
    if (smtpLive && el === discoverStatus) {
      smtpLive.hidden = !message && !box.classList.contains('is-smtp-busy');
    }
  };

  const clearFieldHints = () => {
    showStatus(fromHint, '', false);
    showStatus(passwordHint, '', false);
  };

  const showFieldHint = (el, message) => {
    showStatus(el, message, !!message);
  };

  const applyFieldErrors = (data) => {
    const fromErr = data.errors?.from_address ? String(data.errors.from_address) : '';
    const passErr = data.errors?.password ? String(data.errors.password) : '';
    showFieldHint(fromHint, fromErr);
    showFieldHint(passwordHint, passErr);
    return fromErr || passErr;
  };

  const validateSimpleFields = () => {
    clearFieldHints();
    const from = (fromInput?.value || '').trim();
    const password = passwordInput?.value || '';
    const hasStoredPassword = box.dataset.hasPassword === '1';
    let ok = true;

    if (!from || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(from)) {
      showFieldHint(fromHint, box.dataset.emailInvalid || '');
      ok = false;
    }
    if (!password && !hasStoredPassword) {
      showFieldHint(passwordHint, box.dataset.passwordRequired || '');
      ok = false;
    }
    return ok;
  };

  const setConnectionOk = (ok) => {
    connectionOk = !!ok;
    if (!testSection) return;
    testSection.hidden = !connectionOk;
    if (testToInput) {
      testToInput.disabled = !connectionOk;
      testToInput.required = connectionOk;
    }
    if (testBtn) testBtn.disabled = !connectionOk || !!skip?.checked;
  };

  const invalidateConnection = () => {
    if (!connectionOk) return;
    savedConnectionOk = false;
    setConnectionOk(false);
    showStatus(discoverStatus, '', false);
  };

  const resetToAutoMode = () => {
    if (initialManual || connectionOk) return;
    if (!manualRevealedByFailure) return;
    manualRevealedByFailure = false;
    setManualMode(false);
    showStatus(verifyStatus, '', false);
    showStatus(discoverStatus, '', false);
    clearFieldHints();
  };

  const setSkipped = (skipped) => {
    if (skipHint) {
      skipHint.textContent = skipped
        ? (skipHint.dataset.hintSkip || '')
        : (skipHint.dataset.hintEnable || '');
    }
    if (!fields) return;
    fields.hidden = skipped;
    requiredInputs.forEach((input) => {
      if (skipped) {
        input.dataset.smtpWasRequired = input.required ? '1' : '';
        input.required = false;
        input.disabled = true;
      } else {
        input.required = input.dataset.smtpWasRequired === '1';
        input.disabled = false;
      }
    });
    if (discoverBtn) discoverBtn.disabled = skipped;
    if (verifyBtn) verifyBtn.disabled = skipped;
    if (skipped) {
      savedConnectionOk = connectionOk;
      setConnectionOk(false);
    } else {
      setConnectionOk(savedConnectionOk);
    }
  };

  skip?.addEventListener('change', () => {
    setSkipped(!!skip.checked);
  });
  setSkipped(!!skip?.checked);

  fromInput?.addEventListener('input', () => {
    showFieldHint(fromHint, '');
    resetToAutoMode();
    invalidateConnection();
    if (testToInput && !testToInput.dataset.touched) {
      testToInput.value = fromInput.value;
    }
  });
  passwordInput?.addEventListener('input', () => {
    showFieldHint(passwordHint, '');
    resetToAutoMode();
    invalidateConnection();
  });
  const syncEncryptionWithPort = () => {
    if (!portInput || !encryptionInput) return;
    const port = parseInt(portInput.value || '0', 10);
    if (port === 465 && encryptionInput.value !== 'ssl') {
      encryptionInput.value = 'ssl';
    } else if ((port === 587 || port === 2525) && encryptionInput.value === 'ssl') {
      encryptionInput.value = 'tls';
    }
  };

  hostInput?.addEventListener('input', invalidateConnection);
  portInput?.addEventListener('input', () => {
    syncEncryptionWithPort();
    invalidateConnection();
  });
  portInput?.addEventListener('change', syncEncryptionWithPort);
  encryptionInput?.addEventListener('change', invalidateConnection);
  usernameInput?.addEventListener('input', invalidateConnection);
  syncEncryptionWithPort();
  testToInput?.addEventListener('input', () => {
    testToInput.dataset.touched = '1';
  });

  const payloadFromForm = (options = {}) => {
    const includeHost = options.includeHost !== false;
    const body = new FormData();
    body.append('_token', csrf);
    body.append('from_address', (fromInput?.value || '').trim());
    body.append('password', passwordInput?.value || '');
    body.append('test_to', (testToInput?.value || fromInput?.value || '').trim());
    body.append('host', includeHost ? (hostInput?.value || '').trim() : '');
    body.append('port', (portInput?.value || '587').trim());
    body.append('encryption', encryptionInput?.value || 'tls');
    body.append('username', (usernameInput?.value || '').trim());
    body.append('from_name', (fromNameInput?.value || '').trim());
    return body;
  };

  const applySmtpFields = (data) => {
    if (hostInput && data.host) hostInput.value = data.host;
    if (portInput && (data.port || data.port === 0)) portInput.value = String(data.port || '587');
    if (encryptionInput && data.encryption) encryptionInput.value = data.encryption;
    if (usernameInput && data.username) usernameInput.value = data.username;
    if (fromNameInput && data.from_name) fromNameInput.value = data.from_name;
    syncEncryptionWithPort();
  };

  const connectionSuccessMessage = (data, { verified = false } = {}) => {
    const encLabel = encryptionInput?.selectedOptions?.[0]?.textContent?.trim() || data.encryption || '';
    const tpl = verified ? verifyOkTpl : discoverOkTpl;
    return tpl
      .replace(':host', data.host || hostInput?.value || '')
      .replace(':port', String(data.port || portInput?.value || ''))
      .replace(':encryption', encLabel);
  };

  const showConnectionSuccess = (data, { verified = false } = {}) => {
    clearFieldHints();
    applySmtpFields(data);
    setConnectionOk(true);
    savedConnectionOk = true;
    manualRevealedByFailure = false;
    setManualMode(false);
    // Always clear stale auto-discovery errors and show success in the simple section.
    showStatus(discoverStatus, connectionSuccessMessage(data, { verified }), false);
    showStatus(verifyStatus, '', false);
  };

  const handleDiscoverResult = (data, statusEl, { revealManualOnFail = true } = {}) => {
    if (!data.ok) {
      setConnectionOk(false);
      savedConnectionOk = false;
      const fieldError = applyFieldErrors(data);
      if (data.host || data.suggestion?.host) {
        applySmtpFields({
          host: data.host || data.suggestion?.host || '',
          port: data.port || data.suggestion?.port || 587,
          encryption: data.encryption || data.suggestion?.encryption || 'tls',
          username: data.username || data.suggestion?.username || fromInput?.value || '',
        });
      }
      if (revealManualOnFail && (data.needs_manual || data.host || data.suggestion?.host)) {
        manualRevealedByFailure = true;
        setManualMode(true);
      } else if (revealManualOnFail && fieldError) {
        setManualMode(false);
      } else if (revealManualOnFail) {
        manualRevealedByFailure = true;
        setManualMode(true);
      }
      const err =
        fieldError ||
        data.error ||
        data.errors?.host ||
        discoverFail.replace(':tried', String(data.tried || 0));
      if (fieldError) {
        showStatus(statusEl, '', false);
      } else {
        showStatus(statusEl, err, true);
      }
      return false;
    }

    const verified = statusEl === verifyStatus;
    showConnectionSuccess(data, { verified });
    return true;
  };

  let discovering = false;

  discoverBtn?.addEventListener('click', async (event) => {
    event.preventDefault();
    event.stopPropagation();
    if (discovering || !discoverUrl || !fromInput || !passwordInput) return;
    if (!validateSimpleFields()) return;

    discovering = true;
    setSmtpBusy(true);
    discoverBtn.disabled = true;
    discoverBtn.setAttribute('aria-busy', 'true');
    discoverBtn.setAttribute('aria-disabled', 'true');
    showStatus(discoverStatus, discoverBusy, false);

    try {
      // Force full auto-search: do not reuse a previously suggested host.
      const response = await fetch(discoverUrl, {
        method: 'POST',
        body: payloadFromForm({ includeHost: false }),
        headers: { Accept: 'application/json', 'X-CSRF-Token': csrf },
        credentials: 'same-origin',
      });
      const data = await response.json().catch(() => ({}));
      handleDiscoverResult(data, discoverStatus, { revealManualOnFail: true });
    } catch {
      setConnectionOk(false);
      savedConnectionOk = false;
      manualRevealedByFailure = true;
      setManualMode(true);
      showStatus(discoverStatus, discoverFail.replace(':tried', '0'), true);
    } finally {
      discovering = false;
      setSmtpBusy(false);
      setSkipped(!!skip?.checked);
      discoverBtn.removeAttribute('aria-busy');
      discoverBtn.removeAttribute('aria-disabled');
      if (!manual?.hidden || !!skip?.checked) {
        discoverBtn.disabled = true;
      } else {
        discoverBtn.disabled = false;
      }
    }
  });

  let verifying = false;

  verifyBtn?.addEventListener('click', async (event) => {
    event.preventDefault();
    event.stopPropagation();
    if (verifying || !discoverUrl || !fromInput || !passwordInput) return;
    if (!validateSimpleFields()) return;
    if (!(hostInput?.value || '').trim()) {
      manualRevealedByFailure = true;
      setManualMode(true);
      showStatus(verifyStatus, discoverFail.replace(':tried', '0'), true);
      return;
    }

    verifying = true;
    setSmtpBusy(true);
    verifyBtn.disabled = true;
    verifyBtn.setAttribute('aria-busy', 'true');
    verifyBtn.setAttribute('aria-disabled', 'true');
    showStatus(discoverStatus, '', false);
    showStatus(verifyStatus, verifyBusy, false);

    try {
      const response = await fetch(discoverUrl, {
        method: 'POST',
        body: payloadFromForm({ includeHost: true }),
        headers: { Accept: 'application/json', 'X-CSRF-Token': csrf },
        credentials: 'same-origin',
      });
      const data = await response.json().catch(() => ({}));
      handleDiscoverResult(data, verifyStatus, { revealManualOnFail: true });
    } catch {
      setConnectionOk(false);
      savedConnectionOk = false;
      manualRevealedByFailure = true;
      setManualMode(true);
      showStatus(verifyStatus, discoverFail.replace(':tried', '0'), true);
    } finally {
      verifying = false;
      setSmtpBusy(false);
      setSkipped(!!skip?.checked);
      verifyBtn.removeAttribute('aria-busy');
      verifyBtn.removeAttribute('aria-disabled');
      verifyBtn.disabled = !!skip?.checked;
    }
  });

  testBtn?.addEventListener('click', async (event) => {
    event.preventDefault();
    event.stopPropagation();
    if (!testUrl || !fromInput || !connectionOk) return;

    showStatus(testStatus, testBusy, false);
    testBtn.disabled = true;
    testBtn.setAttribute('aria-busy', 'true');

    try {
      const response = await fetch(testUrl, {
        method: 'POST',
        body: payloadFromForm(),
        headers: { Accept: 'application/json', 'X-CSRF-Token': csrf },
        credentials: 'same-origin',
      });
      const data = await response.json().catch(() => ({}));

      if (!response.ok || !data.ok) {
        applyFieldErrors(data);
        if (data.needs_manual) {
          manualRevealedByFailure = true;
          setManualMode(true);
        }
        const fieldError = data.errors?.password || data.errors?.from_address;
        showStatus(
          testStatus,
          fieldError ? String(fieldError) : (data.error || data.errors?.test || discoverFail),
          true
        );
        return;
      }

      clearFieldHints();
      applySmtpFields(data);
      showStatus(testStatus, data.message || testOkMsg, false);
      if (discoverStatus) {
        showStatus(discoverStatus, connectionSuccessMessage(data, { verified: true }), false);
      }
    } catch {
      showStatus(testStatus, discoverFail, true);
    } finally {
      testBtn.disabled = !!skip?.checked;
      testBtn.removeAttribute('aria-busy');
    }
  });
}

function initSetupRuntsLookup(root) {
  const box = root.querySelector('[data-setup-runts]');
  const input = root.querySelector('[data-setup-runts-input]');
  const btn = box?.querySelector('[data-setup-runts-btn]');
  const labelEl = btn?.querySelector('[data-setup-runts-label]') || btn;
  const live = box?.querySelector('[data-setup-runts-live]');
  const status = box?.querySelector('[data-setup-runts-status]');
  const elapsedEl = box?.querySelector('[data-setup-runts-elapsed]');
  const progress = box?.querySelector('[data-setup-runts-progress]');
  const progressBar = box?.querySelector('[data-setup-runts-progress-bar]');
  if (!box || !input || !btn || !labelEl) return;

  const pair = root.querySelector('[data-setup-name-pair]') || input.closest('[data-setup-name-pair]');
  const nameInput = pair?.querySelector('[data-setup-assoc-name]');
  const legalSelect = pair?.querySelector('[data-setup-legal-name]');
  const currencySelect = pair?.querySelector('select[name="currency"]');
  const hintEl = box.querySelector('[data-setup-runts-hint]');
  const form = root.querySelector('[data-setup-form]');
  const backLink = form?.querySelector('.setup-back');
  const nextBtn = form?.querySelector('.setup-cta');
  const exitBtn = root.querySelector('[data-setup-exit]');

  const digits = () => String(input.value || '').replace(/\D+/g, '');
  let lookingUp = false;
  const TIMEOUT_MS = 120000;

  const hideBtn = () => {
    btn.hidden = true;
    btn.disabled = true;
    btn.setAttribute('aria-hidden', 'true');
    if (hintEl) hintEl.hidden = false;
  };
  const showBtn = () => {
    btn.hidden = false;
    btn.removeAttribute('aria-hidden');
    btn.disabled = lookingUp || digits() === '';
    if (hintEl) hintEl.hidden = true;
  };

  const syncLabel = () => {
    const tpl = box.dataset.labelTemplate || '';
    const name = (nameInput?.value || '').trim() || 'associazione';
    labelEl.textContent = tpl.includes(':name') ? tpl.replaceAll(':name', name) : (tpl || labelEl.textContent);
  };

  const escapeHtml = (s) => String(s)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');

  const showStatus = (text, kind, html) => {
    if (!status) return;
    const msg = String(text || '').trim();
    const isError = kind === 'error';
    const isWarn = kind === 'warn';
    status.hidden = msg === '' && !html;
    if (html) status.innerHTML = html;
    else status.textContent = msg;
    status.classList.toggle('is-error', isError);
    status.classList.toggle('is-warn', isWarn);
    status.classList.toggle('muted', !isError && !isWarn);
    status.classList.toggle('alert', isError || isWarn);
    status.classList.toggle('alert-error', isError);
  };

  const foundLabel = (fields) => {
    const name = String(fields?.name || '').trim();
    const legal = String(fields?.legal_name || '').trim();
    if (name === '') return legal;
    if (legal === '') return name;
    if (name.toUpperCase().includes(legal.toUpperCase())) return name;
    return `${name} ${legal}`;
  };

  const foundHtml = (fields) => {
    const display = foundLabel(fields);
    const tpl = box.dataset.msgOk || 'Trovato :name.';
    return escapeHtml(tpl).replaceAll(':name', `<strong>${escapeHtml(display)}</strong>`);
  };

  const setElapsed = (sec) => {
    if (!elapsedEl) return;
    elapsedEl.hidden = false;
    elapsedEl.textContent = (box.dataset.msgElapsed || ':sec s').replaceAll(':sec', String(sec));
  };

  const setProgress = (percent) => {
    if (!progress || !progressBar) return;
    progress.hidden = false;
    const n = Number(percent);
    if (!Number.isFinite(n) || n <= 0) {
      progress.classList.add('is-indeterminate');
      progressBar.style.width = '';
      return;
    }
    progress.classList.remove('is-indeterminate');
    progressBar.style.width = `${Math.max(4, Math.min(100, n))}%`;
  };

  const phaseText = (phase, number) => {
    const n = String(number || digits() || '');
    const map = {
      connect: box.dataset.msgPhaseConnect,
      download_active: box.dataset.msgPhaseDownloadActive,
      download_cancelled: box.dataset.msgPhaseDownloadCancelled,
      lists_ready: box.dataset.msgPhaseSearchActive,
      search_active: box.dataset.msgPhaseSearchActive,
      search_cancelled: box.dataset.msgPhaseSearchCancelled,
      apply: box.dataset.msgPhaseApply,
    };
    const tpl = map[phase] || box.dataset.msgLoading || '…';
    return String(tpl).replaceAll(':number', n);
  };

  const setLookupBusy = (busy) => {
    lookingUp = !!busy;
    box.classList.toggle('is-looking', lookingUp);
    root.classList.toggle('is-runts-busy', lookingUp);
    form?.classList.toggle('is-runts-busy', lookingUp);
    if (backLink) {
      backLink.setAttribute('aria-disabled', lookingUp ? 'true' : 'false');
      if (lookingUp) backLink.setAttribute('tabindex', '-1');
      else backLink.removeAttribute('tabindex');
    }
    if (nextBtn) nextBtn.disabled = lookingUp;
    if (exitBtn) exitBtn.disabled = lookingUp;
    input.readOnly = lookingUp;
    if (nameInput) {
      nameInput.readOnly = lookingUp;
      nameInput.disabled = lookingUp;
    }
    if (legalSelect) legalSelect.disabled = lookingUp;
    if (currencySelect) currencySelect.disabled = lookingUp;
    btn.disabled = true;
    if (lookingUp) {
      btn.setAttribute('aria-busy', 'true');
      if (live) live.hidden = false;
    } else {
      btn.removeAttribute('aria-busy');
      btn.disabled = digits() === '';
    }
  };

  const blockNavWhileLooking = (event) => {
    if (!lookingUp) return;
    const target = event.target;
    if (!(target instanceof Element)) return;
    if (target.closest('.setup-back, .setup-cta, [data-setup-exit]')) {
      event.preventDefault();
      event.stopPropagation();
    }
  };
  root.addEventListener('click', blockNavWhileLooking, true);
  form?.addEventListener('submit', (event) => {
    if (!lookingUp) return;
    event.preventDefault();
    event.stopPropagation();
  }, true);

  const applyFields = (fields) => {
    if (fields.runts && input) {
      input.value = fields.runts;
    }
    if (fields.name && nameInput) {
      nameInput.value = fields.name;
      nameInput.dispatchEvent(new Event('input', { bubbles: true }));
      nameInput.dispatchEvent(new Event('change', { bubbles: true }));
    }
    if (fields.legal_name && legalSelect) {
      const opt = [...legalSelect.options].find((o) => o.value === fields.legal_name);
      if (opt) {
        legalSelect.value = fields.legal_name;
        legalSelect.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
    syncLabel();
  };

  const sync = () => {
    syncLabel();
    if (lookingUp) {
      showBtn();
      return;
    }
    if (digits() === '') hideBtn();
    else showBtn();
  };

  input.addEventListener('input', sync);
  input.addEventListener('change', sync);
  nameInput?.addEventListener('input', syncLabel);
  sync();

  btn.addEventListener('click', async () => {
    if (lookingUp) return;
    const number = digits();
    if (!number) {
      if (live) live.hidden = false;
      showStatus(box.dataset.msgNeed || '', 'error');
      return;
    }
    setLookupBusy(true);
    showBtn();
    setProgress(4);
    showStatus(phaseText('connect', number), '');
    setElapsed(0);

    const startedAt = Date.now();
    const tick = setInterval(() => {
      setElapsed(Math.floor((Date.now() - startedAt) / 1000));
    }, 250);

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
    let donePayload = null;
    let streamError = null;

    try {
      const body = new URLSearchParams();
      body.set('_token', box.dataset.csrf || '');
      body.set('runts', number);
      const res = await fetch(box.dataset.runtsUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          Accept: 'application/x-ndjson, application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': box.dataset.csrf || '',
        },
        body,
        credentials: 'same-origin',
        signal: controller.signal,
      });
      if (!res.ok) {
        throw new Error('fail');
      }

      const contentType = res.headers.get('content-type') || '';
      if (contentType.includes('ndjson') && res.body && res.body.getReader) {
        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        while (true) {
          const { value, done } = await reader.read();
          if (done) break;
          buffer += decoder.decode(value, { stream: true });
          const lines = buffer.split('\n');
          buffer = lines.pop() || '';
          for (const line of lines) {
            const trimmed = line.trim();
            if (!trimmed) continue;
            let event;
            try {
              event = JSON.parse(trimmed);
            } catch {
              continue;
            }
            if (event.type === 'progress') {
              if (event.percent != null) setProgress(event.percent);
              showStatus(phaseText(String(event.phase || ''), event.number || number), '');
            } else if (event.type === 'start') {
              showStatus(phaseText('connect', number), '');
            } else if (event.type === 'error') {
              streamError = String(event.error || box.dataset.msgFail || '');
            } else if (event.type === 'done') {
              donePayload = event;
            }
          }
        }
      } else {
        donePayload = await res.json();
      }

      if (donePayload?.ok) {
        applyFields(donePayload.fields || {});
        box.classList.add('is-found');
        if (progress) progress.hidden = true;
        if (elapsedEl) elapsedEl.hidden = true;
        const spinner = live?.querySelector('.setup-scrape-spinner');
        if (spinner instanceof HTMLElement) spinner.hidden = true;
        const warning = String(donePayload.warning || '').trim();
        if (warning) {
          showStatus(warning, donePayload.cancelled ? 'warn' : '');
        } else {
          showStatus('', '', foundHtml(donePayload.fields || {}));
        }
      } else {
        showStatus(streamError || donePayload?.error || box.dataset.msgFail || '', 'error');
      }
    } catch (err) {
      const timedOut = err && (err.name === 'AbortError' || controller.signal.aborted);
      showStatus(
        timedOut ? (box.dataset.msgTimeout || box.dataset.msgFail || '') : (box.dataset.msgFail || ''),
        'error'
      );
    } finally {
      clearInterval(tick);
      clearTimeout(timer);
      if (!box.classList.contains('is-found')) {
        setElapsed(Math.max(1, Math.floor((Date.now() - startedAt) / 1000)));
      }
      if (progress && status?.classList.contains('is-error')) {
        progress.hidden = true;
      }
      setLookupBusy(false);
    }
  });
}

function initSetupWebsiteScrape(root) {
  const box = root.querySelector('[data-setup-scrape]');
  if (!box) return;
  const input = root.querySelector('[data-setup-website-input]');
  const btn = box.querySelector('[data-setup-scrape-btn]');
  const labelEl = btn?.querySelector('[data-setup-scrape-label]') || btn;
  const live = box.querySelector('[data-setup-scrape-live]');
  const status = box.querySelector('[data-setup-scrape-status]');
  const elapsedEl = box.querySelector('[data-setup-scrape-elapsed]');
  const results = box.querySelector('[data-setup-scrape-results]');
  const resultsTitle = box.querySelector('[data-setup-scrape-results-title]');
  const resultsStatus = box.querySelector('[data-setup-scrape-results-status]');
  const brandPanel = box.querySelector('[data-setup-scrape-brand]');
  const logoCard = box.querySelector('[data-setup-scrape-logo]');
  const logoImg = box.querySelector('[data-setup-scrape-logo-img]');
  const logoPickBtn = box.querySelector('[data-setup-scrape-logo-pick]');
  const logoFileInput = box.querySelector('[data-setup-scrape-logo-input]');
  const logoPicks = box.querySelector('[data-setup-scrape-logo-picks]');
  const logoPickGrid = box.querySelector('[data-setup-scrape-logo-pick-grid]');
  const logoNoneBtn = box.querySelector('[data-setup-scrape-logo-none]');
  const retryBtn = box.querySelector('[data-setup-scrape-retry]');
  const form = root.querySelector('[data-setup-form]');
  if (!input || !btn || !labelEl) return;

  const backLink = form?.querySelector('.setup-back');
  const nextBtn = form?.querySelector('.setup-cta');
  const exitBtn = root.querySelector('[data-setup-exit]');

  const hideScrapeButton = () => {
    btn.hidden = true;
    btn.setAttribute('aria-hidden', 'true');
    btn.disabled = true;
  };

  const showScrapeButton = () => {
    btn.hidden = false;
    btn.removeAttribute('aria-hidden');
  };

  const hasValidWebsite = () => isWebsiteScrapeAllowed(input.value);

  const setScrapeNavLocked = (locked) => {
    root.classList.toggle('is-scrape-busy', locked);
    form?.classList.toggle('is-scrape-busy', locked);
    box.classList.toggle('is-scraping', locked);
    if (backLink) {
      backLink.setAttribute('aria-disabled', locked ? 'true' : 'false');
      if (locked) backLink.setAttribute('tabindex', '-1');
      else backLink.removeAttribute('tabindex');
    }
    if (nextBtn) nextBtn.disabled = locked;
    if (exitBtn) exitBtn.disabled = locked;
    input.readOnly = locked;
    if (!btn.hidden) {
      btn.disabled = locked || !hasValidWebsite();
    }
  };

  const blockNavWhileScraping = (event) => {
    if (!box.classList.contains('is-scraping')) return;
    const target = event.target;
    if (!(target instanceof Element)) return;
    if (target.closest('.setup-back, .setup-cta, [data-setup-exit]')) {
      event.preventDefault();
      event.stopPropagation();
    }
  };
  root.addEventListener('click', blockNavWhileScraping, true);
  form?.addEventListener('submit', (event) => {
    if (!box.classList.contains('is-scraping')) return;
    event.preventDefault();
    event.stopPropagation();
  }, true);

  const labelTpl = box.dataset.labelTemplate || '';
  const name = (box.dataset.assocName || '').trim();
  const legal = (box.dataset.assocLegal || '').trim();
  const displayName = (() => {
    if (!name && !legal) return 'associazione';
    if (!name) return legal;
    if (!legal || nameContainsLegal(name, legal)) return name;
    return `${name} ${legal}`;
  })();
  const seatParts = { address: '', house_number: '', postal_code: '', city: '' };
  const escapeHtml = (value) => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');

  const groupForKey = (key) => {
    if (['email', 'pec', 'phone'].includes(key)) return 'contact';
    if (['address', 'house_number', 'postal_code', 'city'].includes(key)) return 'seat';
    if (['fiscal_code', 'vat_number', 'runts'].includes(key)) return 'ids';
    if (['president_name', 'vice_president_name', 'secretary_name', 'treasurer_name', 'board_names'].includes(key)) {
      return 'people';
    }
    return 'other';
  };

  const clearFoundGroups = () => {
    box.querySelectorAll('[data-scrape-group-list]').forEach((list) => {
      list.innerHTML = '';
    });
    box.querySelectorAll('[data-scrape-group]').forEach((group) => {
      group.hidden = true;
    });
    seatParts.address = '';
    seatParts.house_number = '';
    seatParts.postal_code = '';
    seatParts.city = '';
    hideLogoPicks();
  };

  const showBrandPanel = () => {
    if (!results || !brandPanel) return;
    results.hidden = false;
    brandPanel.hidden = false;
  };

  const showLogoPreview = (url) => {
    if (!logoCard || !logoImg || !url) return;
    hideLogoPicks();
    showBrandPanel();
    logoCard.hidden = false;
    logoImg.src = url;
    logoImg.onload = () => {
      logoImg.style.opacity = '1';
    };
    logoImg.style.opacity = '0.35';
    logoImg.style.transition = 'opacity 0.45s ease';
  };

  const hideLogoPicks = () => {
    if (logoPicks) logoPicks.hidden = true;
    if (logoPickGrid) logoPickGrid.innerHTML = '';
  };

  const showLogoPicks = (urls) => {
    const list = Array.isArray(urls) ? urls.map((u) => String(u || '').trim()).filter(Boolean).slice(0, 3) : [];
    if (!logoPicks || !logoPickGrid || list.length === 0) return;
    if (logoCard) logoCard.hidden = true;
    showBrandPanel();
    logoPicks.hidden = false;
    logoPickGrid.innerHTML = '';
    list.forEach((url, index) => {
      const btnEl = document.createElement('button');
      btnEl.type = 'button';
      btnEl.className = 'setup-scrape-logo-pick';
      btnEl.dataset.logoUrl = url;
      btnEl.setAttribute('aria-label', `${box.dataset.msgLogoGuess || ''} ${index + 1}`);
      const img = document.createElement('img');
      img.alt = '';
      img.loading = 'lazy';
      img.referrerPolicy = 'no-referrer';
      img.src = url;
      img.onerror = () => {
        btnEl.hidden = true;
      };
      btnEl.appendChild(img);
      btnEl.addEventListener('click', () => pickScrapedLogo(url, btnEl));
      logoPickGrid.appendChild(btnEl);
    });
  };

  const pickScrapedLogo = async (url, btnEl) => {
    const uploadUrl = box.dataset.logoUploadUrl || '';
    if (!uploadUrl || !url) {
      showLogoReplaceError();
      return;
    }
    logoPickGrid?.querySelectorAll('.setup-scrape-logo-pick').forEach((el) => el.classList.add('is-busy'));
    if (btnEl) btnEl.classList.add('is-busy');
    try {
      const body = new URLSearchParams();
      body.set('_token', box.dataset.csrf || '');
      body.set('logo_url', url);
      const res = await fetch(uploadUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': box.dataset.csrf || '',
        },
        body,
        credentials: 'same-origin',
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        showLogoReplaceError(typeof data.error === 'string' ? data.error : '');
        logoPickGrid?.querySelectorAll('.setup-scrape-logo-pick').forEach((el) => el.classList.remove('is-busy'));
        return;
      }
      hideLogoPicks();
      applyReplacedLogo(data);
      if (results) results.hidden = false;
    } catch (err) {
      showLogoReplaceError();
      logoPickGrid?.querySelectorAll('.setup-scrape-logo-pick').forEach((el) => el.classList.remove('is-busy'));
    }
  };

  logoNoneBtn?.addEventListener('click', () => {
    hideLogoPicks();
    if (brandPanel && logoCard?.hidden) {
      brandPanel.hidden = true;
    }
  });

  const applyReplacedLogo = (data) => {
    const url = data.logo_url ? `${data.logo_url}?v=${Date.now()}` : '';
    if (url) showLogoPreview(url);
    if (data.primary || data.accent) {
      applyBrandColors(data.primary, data.accent);
    }
  };

  const showLogoReplaceError = (message) => {
    const text = message || box.dataset.msgLogoFail || box.dataset.msgFail || '';
    if (live) live.hidden = false;
    setPhase(text);
    if (resultsStatus) {
      resultsStatus.hidden = false;
      resultsStatus.textContent = text;
    }
  };

  logoPickBtn?.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    if (box.classList.contains('is-scraping') || logoCard?.classList.contains('is-uploading')) return;
    if (!logoFileInput) return;
    logoFileInput.click();
  });

  logoFileInput?.addEventListener('change', async () => {
    const file = logoFileInput.files && logoFileInput.files[0] ? logoFileInput.files[0] : null;
    if (!file) return;
    const uploadUrl = box.dataset.logoUploadUrl || '';
    if (!uploadUrl) {
      showLogoReplaceError();
      logoFileInput.value = '';
      return;
    }

    // Instant local preview while uploading.
    const localUrl = URL.createObjectURL(file);
    showLogoPreview(localUrl);

    logoCard?.classList.add('is-uploading');
    try {
      const body = new FormData();
      body.set('_token', box.dataset.csrf || '');
      body.set('logo', file);
      const res = await fetch(uploadUrl, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': box.dataset.csrf || '',
        },
        body,
      });
      const data = await res.json().catch(() => ({}));
      URL.revokeObjectURL(localUrl);
      if (!res.ok || !data.ok) {
        showLogoReplaceError(typeof data.error === 'string' ? data.error : '');
        return;
      }
      applyReplacedLogo(data);
      if (results) results.hidden = false;
    } catch (err) {
      URL.revokeObjectURL(localUrl);
      showLogoReplaceError();
    } finally {
      logoCard?.classList.remove('is-uploading');
      logoFileInput.value = '';
    }
  });

  const applyNormalized = () => {
    const normalized = normalizeWebsiteInput(input.value);
    if (normalized) input.value = normalized;
    return normalized;
  };

  const syncLabel = () => {
    const scraping = box.classList.contains('is-scraping');
    const attempted = box.dataset.scrapeAttempted === '1';
    const failed = box.dataset.scrapeFailed === '1';
    const valid = hasValidWebsite();
    const liveOpen = !!(live && !live.hidden);
    const resultsOpen = !!(results && !results.hidden);
    const retryOpen = failed && !scraping;

    if (failed && !scraping) {
      hideScrapeButton();
    } else if (attempted && !scraping) {
      hideScrapeButton();
    } else if (!valid && !scraping) {
      hideScrapeButton();
    } else {
      showScrapeButton();
      const site = (normalizeWebsiteInput(input.value) || input.value.trim() || '…');
      const html = labelTpl
        .replaceAll(':name', `<span class="scrape-name">${escapeHtml(displayName)}</span>`)
        .replaceAll(':site', `<span class="scrape-site">${escapeHtml(site)}</span>`);
      labelEl.innerHTML = html || escapeHtml(box.dataset.msgNeedUrl || '');
      btn.disabled = scraping || !valid;
    }

    const showShell = !btn.hidden || scraping || liveOpen || resultsOpen || retryOpen;
    box.hidden = !showShell;
    if (showShell) box.removeAttribute('aria-hidden');
    else box.setAttribute('aria-hidden', 'true');
  };
  input.addEventListener('input', () => {
    if (box.dataset.scrapeFailed === '1') {
      clearScrapeRetry();
      box.dataset.scrapeAttempted = '0';
      if (live) live.hidden = true;
      setPhase('');
      setElapsed('');
    }
    syncLabel();
  });
  input.addEventListener('blur', () => {
    applyNormalized();
    syncLabel();
  });
  syncLabel();

  if (form) {
    form.addEventListener('submit', () => {
      applyNormalized();
    });
  }

  const setPhase = (text) => {
    if (status) status.textContent = text || '';
  };

  const setElapsed = (sec) => {
    if (!elapsedEl) return;
    if (sec === '' || sec === null || sec === undefined) {
      elapsedEl.textContent = '';
      return;
    }
    elapsedEl.textContent = (box.dataset.msgElapsed || ':sec s').replaceAll(':sec', String(sec));
  };

  const showScrapeRetry = () => {
    box.dataset.scrapeFailed = '1';
    if (retryBtn) retryBtn.hidden = false;
    if (elapsedEl) elapsedEl.hidden = true;
    hideScrapeButton();
  };

  const clearScrapeRetry = () => {
    delete box.dataset.scrapeFailed;
    if (retryBtn) retryBtn.hidden = true;
    if (elapsedEl) elapsedEl.hidden = false;
  };

  const upsertRow = (list, key, label, value) => {
    let row = list.querySelector(`[data-found-key="${String(key).replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"]`);
    if (!row) {
      row = document.createElement('li');
      row.className = 'setup-scrape-found-item';
      row.dataset.foundKey = key;
      row.innerHTML = `<span class="setup-scrape-found-label"></span><span class="setup-scrape-found-value"></span>`;
      list.appendChild(row);
      requestAnimationFrame(() => row.classList.add('is-in'));
    }
    const labelNode = row.querySelector('.setup-scrape-found-label');
    const valueNode = row.querySelector('.setup-scrape-found-value');
    if (labelNode) labelNode.textContent = label || key;
    if (valueNode) valueNode.textContent = value;
  };

  const renderSeatSummary = () => {
    const group = box.querySelector('[data-scrape-group="seat"]');
    const list = box.querySelector('[data-scrape-group-list="seat"]');
    if (!group || !list) return;
    const addr = String(seatParts.address || '').trim();
    const city = String(seatParts.city || '').trim();
    const cap = String(seatParts.postal_code || '').trim();
    if (addr && /^\d{5}$/.test(addr)) {
      seatParts.address = '';
    }
    if (cap && !/^\d{5}$/.test(cap)) {
      seatParts.postal_code = '';
    }
    const validAddr = String(seatParts.address || '').trim();
    if (validAddr.length > 0 && validAddr.length < 4) {
      seatParts.address = '';
    }
    const line = [
      [seatParts.address, seatParts.house_number].filter(Boolean).join(' '),
      [seatParts.postal_code, seatParts.city].filter(Boolean).join(' '),
    ].filter(Boolean).join(', ');
    if (!line || (!validAddr && !city)) {
      group.hidden = true;
      list.innerHTML = '';
      return;
    }
    group.hidden = false;
    list.innerHTML = '';
    upsertRow(list, 'seat_line', list.dataset.seatLabel || 'Indirizzo', line);
  };

  const showFoundItem = (key, label, value) => {
    if (!results) return;
    results.hidden = false;
    if (resultsTitle) {
      resultsTitle.textContent = box.dataset.msgFoundTitle || '';
    }

    if (key === 'logo_url') {
      showLogoPreview(value);
      return;
    }
    if (key === 'theme_primary' || key === 'theme_accent') {
      return;
    }
    if (key === 'website') {
      // Already shown in the URL field.
      return;
    }

    if (Object.prototype.hasOwnProperty.call(seatParts, key)) {
      seatParts[key] = value;
      renderSeatSummary();
      return;
    }

    const groupName = groupForKey(key);
    const group = box.querySelector(`[data-scrape-group="${groupName}"]`);
    const list = box.querySelector(`[data-scrape-group-list="${groupName}"]`);
    if (!group || !list) return;
    group.hidden = false;
    upsertRow(list, key, label, value);
  };

  const handleEvent = (event) => {
    if (!event || typeof event !== 'object') return null;
    if (event.type === 'start') {
      setPhase(box.dataset.msgPhaseConnect || box.dataset.msgLoading || '…');
      return null;
    }
    if (event.type === 'progress') {
      const phase = event.phase || '';
      if (phase === 'fetch') {
        setPhase(box.dataset.msgPhaseFetch || box.dataset.msgLoading || '…');
      } else if (phase === 'pages') {
        setPhase((box.dataset.msgPhasePages || '').replaceAll(':pages', String(event.pages || 0)));
      } else if (phase === 'extract') {
        setPhase(box.dataset.msgPhaseExtract || '…');
      } else if (phase === 'apply') {
        setPhase(box.dataset.msgPhaseApply || '…');
      }
      return null;
    }
    if (event.type === 'found') {
      showFoundItem(String(event.key || ''), String(event.label || event.key || ''), String(event.value || ''));
      return event;
    }
    if (event.type === 'error') {
      // Do not throw: keep streaming / UI findings; finalize() decides the message.
      return event;
    }
    if (event.type === 'done') {
      return event;
    }
    return null;
  };

  const hasVisibleFindings = () => {
    const hasRows = !!box.querySelector('[data-scrape-group-list] [data-found-key]');
    const hasLogo = !!(logoCard && !logoCard.hidden);
    const hasLogoGuess = !!(logoPicks && !logoPicks.hidden);
    return hasRows || hasLogo || hasLogoGuess;
  };

  const finishSuccess = (data, foundCountHint = 0, startedAtMs = Date.now()) => {
    if (data.website) {
      input.value = data.website;
      syncLabel();
    }
    if (data.logo_url) {
      showLogoPreview(`${data.logo_url}?v=${Date.now()}`);
    } else if (Array.isArray(data.logo_candidates) && data.logo_candidates.length) {
      showLogoPicks(data.logo_candidates);
    }
    const foundKeys = Object.keys(data.found || {}).filter(
      (key) => !['logo_url', 'theme_primary', 'theme_accent', 'website'].includes(key)
    );
    const visualCount = (logoCard && !logoCard.hidden ? 1 : 0)
      + (logoPicks && !logoPicks.hidden ? 1 : 0)
      + foundKeys.length;
    const liveCount = box.querySelectorAll('[data-scrape-group-list] [data-found-key]').length;
    const sec = Math.max(1, Math.round((data.elapsed_ms || (Date.now() - startedAtMs)) / 1000));
    const count = Number(data.applied_count || foundKeys.length || foundCountHint || liveCount || visualCount);
    if (count === 0 && !hasVisibleFindings()) {
      setPhase(box.dataset.msgEmpty || '');
      if (results) results.hidden = true;
      showScrapeRetry();
      return false;
    }
    clearScrapeRetry();
    if (live) live.hidden = true;
    if (resultsTitle) {
      resultsTitle.textContent = box.dataset.msgFoundTitle || '';
    }
    if (resultsStatus) {
      resultsStatus.hidden = true;
      resultsStatus.textContent = '';
    }
    if (results) results.hidden = false;
    return true;
  };

  const runScrape = async () => {
    const website = applyNormalized() || input.value.trim();
    if (!website) {
      if (live) live.hidden = false;
      setPhase(box.dataset.msgNeedUrl || '');
      return;
    }
    syncLabel();
    box.dataset.scrapeAttempted = '1';
    clearScrapeRetry();
    setScrapeNavLocked(true);
    btn.disabled = true;
    if (retryBtn) retryBtn.disabled = true;
    if (live) live.hidden = false;
    if (results) results.hidden = true;
    if (resultsStatus) {
      resultsStatus.hidden = true;
      resultsStatus.textContent = '';
    }
    clearFoundGroups();
    if (brandPanel) brandPanel.hidden = true;
    if (logoCard) logoCard.hidden = true;
    if (logoImg) logoImg.removeAttribute('src');
    setPhase(box.dataset.msgPhaseConnect || box.dataset.msgLoading || '…');
    setElapsed(0);
    if (elapsedEl) elapsedEl.hidden = false;
    syncLabel();

    const startedAt = Date.now();
    const tick = setInterval(() => {
      setElapsed(Math.floor((Date.now() - startedAt) / 1000));
    }, 250);

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 65000);
    let donePayload = null;
    let foundCount = 0;
    let streamError = null;
    let succeeded = false;
    try {
      const body = new URLSearchParams();
      body.set('_token', box.dataset.csrf || '');
      body.set('website', website);
      body.set('name', name);
      const res = await fetch(box.dataset.scrapeUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          Accept: 'application/x-ndjson, application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': box.dataset.csrf || '',
        },
        body,
        signal: controller.signal,
      });
      if (!res.ok) {
        throw new Error('fail');
      }

      const contentType = res.headers.get('content-type') || '';
      if (contentType.includes('ndjson') && res.body && res.body.getReader) {
        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        while (true) {
          const { value, done } = await reader.read();
          if (done) break;
          buffer += decoder.decode(value, { stream: true });
          const lines = buffer.split('\n');
          buffer = lines.pop() || '';
          for (const line of lines) {
            const trimmed = line.trim();
            if (!trimmed) continue;
            let event;
            try {
              event = JSON.parse(trimmed);
            } catch (_) {
              continue;
            }
            const handled = handleEvent(event);
            if (handled?.type === 'found') {
              foundCount += 1;
            } else if (handled?.type === 'error') {
              streamError = handled.error || 'fail';
            } else if (handled?.type === 'done') {
              donePayload = handled;
            }
          }
        }
        const tail = buffer.trim();
        if (tail) {
          try {
            const event = JSON.parse(tail);
            const handled = handleEvent(event);
            if (handled?.type === 'found') {
              foundCount += 1;
            } else if (handled?.type === 'error') {
              streamError = handled.error || 'fail';
            } else if (handled?.type === 'done') {
              donePayload = handled;
            }
          } catch (_) {
            /* ignore */
          }
        }
      } else {
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'fail');
        donePayload = { type: 'done', ...data };
        const found = data.found || {};
        const labels = data.labels || {};
        Object.keys(found).forEach((key) => {
          showFoundItem(key, labels[key] || key, found[key]);
          foundCount += 1;
        });
      }

      if (donePayload && donePayload.ok) {
        succeeded = finishSuccess(donePayload, foundCount, startedAt);
      } else if (foundCount > 0 || hasVisibleFindings()) {
        succeeded = finishSuccess({
          ok: true,
          found: donePayload?.found || {},
          applied_count: foundCount,
          elapsed_ms: donePayload?.elapsed_ms || (Date.now() - startedAt),
          website: donePayload?.website || website,
          logo_url: donePayload?.logo_url,
          logo_candidates: donePayload?.logo_candidates || [],
        }, foundCount, startedAt);
      } else {
        throw new Error(streamError || (donePayload && donePayload.error) || 'fail');
      }
    } catch (err) {
      if (foundCount > 0 || hasVisibleFindings()) {
        succeeded = finishSuccess({
          ok: true,
          found: {},
          applied_count: foundCount,
          elapsed_ms: Date.now() - startedAt,
          website,
        }, foundCount, startedAt);
      } else {
        setPhase(box.dataset.msgFail || '');
        if (results && !hasVisibleFindings()) {
          results.hidden = true;
        }
        showScrapeRetry();
      }
    } finally {
      clearTimeout(timer);
      clearInterval(tick);
      setScrapeNavLocked(false);
      if (retryBtn) retryBtn.disabled = false;
      if (succeeded) {
        hideScrapeButton();
      }
      syncLabel();
    }
  };

  btn.addEventListener('click', () => {
    runScrape();
  });
  retryBtn?.addEventListener('click', () => {
    runScrape();
  });
};

function initSetupPeopleList(root) {
  root.querySelectorAll('[data-people-list]').forEach((list) => initPeopleList(list));
}

const MEMBER_TYPE_I18N = {
  ordinaria: { de: 'Ordentlich', en: 'Ordinary' },
  ordinario: { de: 'Ordentlich', en: 'Ordinary' },
  sostenitore: { de: 'Fördernd', en: 'Supporting' },
  sostenitrice: { de: 'Fördernd', en: 'Supporting' },
  onorario: { de: 'Ehrenmitglied', en: 'Honorary' },
  onoraria: { de: 'Ehrenmitglied', en: 'Honorary' },
  fondatore: { de: 'Gründungsmitglied', en: 'Founding member' },
  fondatrice: { de: 'Gründungsmitglied', en: 'Founding member' },
  junior: { de: 'Junior', en: 'Junior' },
  familiare: { de: 'Familienmitglied', en: 'Family member' },
};

function suggestMemberTypeTranslations(nameIt) {
  const key = String(nameIt || '').trim().toLowerCase();
  if (!key) return null;
  if (MEMBER_TYPE_I18N[key]) return MEMBER_TYPE_I18N[key];
  const normalized = key.normalize('NFD').replace(/\p{M}/gu, '');
  if (MEMBER_TYPE_I18N[normalized]) return MEMBER_TYPE_I18N[normalized];
  return { de: nameIt.trim(), en: nameIt.trim() };
}

function initSetupMemberTypes(root = document) {
  root.querySelectorAll('[data-setup-member-types]').forEach((block) => {
    if (block.dataset.memberTypesBound === '1') return;
    block.dataset.memberTypesBound = '1';

    const translateUrl = block.dataset.translateUrl || '';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const fetchTranslation = async (text, target) => {
      if (!translateUrl || !text) return null;
      try {
        const res = await fetch(translateUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrf,
          },
          credentials: 'same-origin',
          body: JSON.stringify({ text, target, source: 'it' }),
        });
        const data = await res.json().catch(() => ({}));
        return res.ok && data.ok && data.text ? String(data.text) : null;
      } catch {
        return null;
      }
    };

    block.querySelectorAll('[data-type-name-it]').forEach((itInput) => {
      if (itInput.dataset.typeI18nBound === '1') return;
      itInput.dataset.typeI18nBound = '1';
      const row = itInput.closest('.setup-membership-card, .setup-langs-row, [data-setup-member-types]');
      const deInput = row?.querySelector('[data-type-name-de]');
      const enInput = row?.querySelector('[data-type-name-en]');
      if (!deInput || !enInput) return;

      itInput.addEventListener('blur', async () => {
        const value = itInput.value.trim();
        if (!value) return;
        const touched = (deInput.dataset.userTouched === '1' && deInput.value.trim() !== '')
          || (enInput.dataset.userTouched === '1' && enInput.value.trim() !== '');
        if (touched) return;

        const tr = suggestMemberTypeTranslations(value);
        const dictHit = tr && (tr.de !== value || tr.en !== value);
        if (dictHit) {
          if (deInput.value.trim() === '') deInput.value = tr.de;
          if (enInput.value.trim() === '') enInput.value = tr.en;
          deInput.dispatchEvent(new Event('input', { bubbles: true }));
          enInput.dispatchEvent(new Event('input', { bubbles: true }));
          return;
        }

        for (const [target, el] of [['de', deInput], ['en', enInput]]) {
          if (el.value.trim() !== '') continue;
          const translated = await fetchTranslation(value, target);
          if (translated) {
            el.value = translated;
            el.dispatchEvent(new Event('input', { bubbles: true }));
          } else if (tr) {
            el.value = target === 'de' ? tr.de : tr.en;
            el.dispatchEvent(new Event('input', { bubbles: true }));
          }
        }
      });

      deInput.addEventListener('input', () => { deInput.dataset.userTouched = '1'; });
      enInput.addEventListener('input', () => { enInput.dataset.userTouched = '1'; });
    });
  });
}

function initSetupEnrollment(root = document) {
  const box = root.querySelector('[data-setup-enrollment]');
  if (!box || box.dataset.enrollmentReady === '1') return;
  box.dataset.enrollmentReady = '1';
  const select = box.querySelector('[data-enrollment-select]');
  const detail = box.querySelector('[data-enrollment-detail]');
  const sync = () => {
    const val = select?.value || 'none';
    const tpl = box.querySelector(`[data-enrollment-detail-for="${val}"]`);
    if (detail) {
      detail.textContent = tpl?.textContent?.trim() || '';
      detail.hidden = detail.textContent === '';
    }
  };
  select?.addEventListener('change', sync);
  sync();
}

function initSetupMembershipPeriods(root = document) {
  const msg = document.body?.dataset?.msgPeriodEndBeforeStart
    || 'La data di fine deve essere uguale o successiva alla data di inizio.';

  root.querySelectorAll('[data-setup-membership-periods]').forEach((block) => {
    if (block.dataset.periodsBound === '1') return;
    block.dataset.periodsBound = '1';

    const bindPair = (startInput, endInput) => {
      if (!startInput || !endInput) return;
      const validate = () => {
        const start = startInput.value;
        const end = endInput.value;
        endInput.setCustomValidity('');
        if (start && end && end < start) {
          endInput.setCustomValidity(msg);
        }
      };
      startInput.addEventListener('change', validate);
      endInput.addEventListener('change', validate);
      startInput.addEventListener('input', validate);
      endInput.addEventListener('input', validate);
    };

    block.querySelectorAll('.setup-membership-card').forEach((card) => {
      bindPair(card.querySelector('[data-period-start]'), card.querySelector('[data-period-end]'));
    });
  });
}

function initPeopleList(list) {
  if (list.dataset.peopleListBound === '1') return;
  list.dataset.peopleListBound = '1';

  const rows = list.querySelector('[data-people-rows]');
  const template = list.querySelector('[data-people-template]');
  const addBtn = list.querySelector('[data-people-add]');
  if (!rows || !template || !addBtn) return;

  const reindex = () => {
    [...rows.querySelectorAll('[data-people-row]')].forEach((row, i) => {
      row.querySelectorAll('input[name]').forEach((input) => {
        input.name = input.name.replace(/\[(?:\d+|__i__)\]/, `[${i}]`);
      });
    });
  };

  addBtn.addEventListener('click', () => {
    const html = template.innerHTML.replaceAll('__i__', String(rows.children.length));
    rows.insertAdjacentHTML('beforeend', html);
    reindex();
    initPlaceSuggest(list.closest('form') || list || document);
  });

  rows.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-people-remove]');
    if (!btn) return;
    const row = btn.closest('[data-people-row]');
    if (!row) return;
    if (rows.children.length <= 1) {
      row.querySelectorAll('input').forEach((input) => { input.value = ''; });
      return;
    }
    row.remove();
    reindex();
  });
}

function resolveGeoUrls(root) {
  const from = (el) => {
    if (!el || !el.dataset) return null;
    const citiesUrl = el.dataset.citiesUrl || '';
    const addressesUrl = el.dataset.addressesUrl || '';
    if (citiesUrl && addressesUrl) {
      return { citiesUrl, addressesUrl };
    }
    return null;
  };

  return from(root)
    || from(root?.closest?.('[data-cities-url][data-addresses-url]'))
    || from(document.querySelector('[data-cities-url][data-addresses-url]'))
    || from(document.body)
    || null;
}

function geoScopeFor(cityInput) {
  return cityInput.closest('[data-geo-scope], [data-people-row], .setup-address, .setup-president, [data-member-form], form')
    || cityInput.parentElement
    || document;
}

function geoConfirmMessage(template, suggestion) {
  const label = String(suggestion || '').trim();
  const tpl = String(template || 'Intendevi :suggestion?');
  return tpl.replaceAll(':suggestion', label);
}

function geoConfirmLabelsFromBody() {
  return {
    yes: document.body?.dataset?.msgGeoConfirmYes || 'Sì, usa questo',
    no: document.body?.dataset?.msgGeoConfirmNo || 'No, lascio così',
  };
}

async function resolveGeoQuery(url, params) {
  const qs = new URLSearchParams({ resolve: '1', ...params });
  const res = await fetch(`${url}?${qs.toString()}`);
  if (!res.ok) return null;
  return res.json().catch(() => null);
}

function geoCityNotFoundMessage(template, city) {
  const label = String(city || '').trim();
  const tpl = String(template || 'La città ":city" non è stata trovata.');
  return tpl.replaceAll(':city', label);
}

function clearGeoCityError(input) {
  if (!input) return;
  input.classList.remove('input-invalid');
  input.setCustomValidity('');
}

async function showGeoCityNotFound(input, template) {
  if (!input) return;
  const message = geoCityNotFoundMessage(template, input.value.trim());
  input.dataset.geoPicked = '0';
  input.classList.add('input-invalid');
  input.setCustomValidity(message);
  const okLabel = document.body?.dataset?.msgGeoCityNotFoundOk || 'Ok, la correggo';
  await appConfirm(message, { alert: true, confirmLabel: okLabel });
  input.value = '';
  clearGeoCityError(input);
  if (input.matches('[data-birth-place-input]')) {
    input.closest('form')?.dispatchEvent(new Event('cf:refresh'));
  }
  input.focus();
}

async function handleGeoResolveResult(data, input, applyItem, confirmTemplate, options = {}) {
  if (!data || !input || typeof applyItem !== 'function') return;
  if (data.action === 'none') {
    clearGeoCityError(input);
    return;
  }
  if (data.action === 'not_found') {
    await showGeoCityNotFound(input, options.notFoundTemplate);
    return;
  }
  if (data.action === 'apply' && data.item) {
    applyItem(data.item);
    input.dataset.geoPicked = '1';
    clearGeoCityError(input);
    return;
  }
  if (data.action !== 'confirm' || !data.item) return;
  const suggestion = data.label || data.item.label || data.item.city || data.item.address || '';
  const labels = geoConfirmLabelsFromBody();
  const confirmed = await appConfirm(
    geoConfirmMessage(confirmTemplate, suggestion),
    { confirmLabel: labels.yes, cancelLabel: labels.no }
  );
  if (confirmed) {
    applyItem(data.item);
    input.dataset.geoPicked = '1';
    clearGeoCityError(input);
  } else {
    await showGeoCityNotFound(input, options.notFoundTemplate);
  }
}

function initPlaceSuggest(root = document) {
  const urls = resolveGeoUrls(root);
  if (!urls) return;
  const { citiesUrl, addressesUrl } = urls;
  const scopeRoot = root && root.querySelectorAll ? root : document;
  const confirmCityTpl = document.body?.dataset?.msgGeoConfirmCity || 'Intendevi :suggestion?';
  const confirmAddressTpl = document.body?.dataset?.msgGeoConfirmAddress || 'Intendevi :suggestion?';
  const confirmBirthTpl = document.body?.dataset?.msgGeoConfirmBirth || confirmCityTpl;
  const cityNotFoundTpl = document.body?.dataset?.msgGeoCityNotFound || 'La città ":city" non è stata trovata.';

  scopeRoot.querySelectorAll('[data-birth-place-input]').forEach((birthInput) => {
    if (birthInput.dataset.suggestBound === '1') return;
    birthInput.dataset.suggestBound = '1';
    const birthList = birthInput.closest('.suggest-wrap, .suggest-field, label, .field-block')
      ?.querySelector('[data-birth-place-suggest]')
      || birthInput.closest('form')?.querySelector('[data-birth-place-suggest]');
    bindSuggest({
      input: birthInput,
      list: birthList,
      fetchItems: async (q) => {
        const res = await fetch(`${citiesUrl}?q=${encodeURIComponent(q)}`);
        const data = await res.json();
        return (data.items || []).map((item) => ({
          label: item.label,
          apply: () => { birthInput.value = item.city; },
        }));
      },
      onPick: () => birthInput.closest('form')?.dispatchEvent(new Event('cf:refresh')),
      resolve: {
        minChars: 2,
        run: async (raw) => {
          const data = await resolveGeoQuery(citiesUrl, { q: raw, foreign: '1' });
          await handleGeoResolveResult(data, birthInput, (item) => {
            birthInput.value = item.city || item.label || raw;
            birthInput.closest('form')?.dispatchEvent(new Event('cf:refresh'));
          }, confirmBirthTpl, { notFoundTemplate: cityNotFoundTpl });
        },
      },
    });
  });

  const scopes = [];
  scopeRoot.querySelectorAll('[data-city-input]').forEach((cityInput) => {
    const scope = geoScopeFor(cityInput);
    if (!scopes.includes(scope)) scopes.push(scope);
  });

  // Address fields without a city sibling still need binding when city exists elsewhere in form
  scopeRoot.querySelectorAll('[data-address-input]').forEach((addressInput) => {
    if (addressInput.dataset.suggestBound === '1') return;
    const scope = addressInput.closest('[data-geo-scope], [data-people-row], .setup-address, .setup-president, [data-member-form], form')
      || addressInput.parentElement;
    if (scope && !scopes.includes(scope)) scopes.push(scope);
  });

  scopes.forEach((scope) => {
    const cityInput = scope.querySelector('[data-city-input]');
    const addressInput = scope.querySelector('[data-address-input]');
    const houseNumberInput = scope.querySelector('[data-house-number]');
    const postalInput = scope.querySelector('[data-postal-code]');
    const provinceInput = scope.querySelector('[data-province-input]');
    const form = (cityInput || addressInput)?.closest('form') || scope;

    const applyProvince = (item) => {
      if (!provinceInput || !item?.provincia) return;
      const raw = String(item.provincia).trim();
      if (!raw) return;
      const code = raw.length <= 3 ? raw.toUpperCase() : raw.slice(0, 2).toUpperCase();
      if (!provinceInput.value) {
        provinceInput.value = code;
      }
    };

    if (cityInput && cityInput.dataset.suggestBound !== '1') {
      cityInput.dataset.suggestBound = '1';
      const cityList = cityInput.closest('.suggest-wrap, .suggest-field, label, .field-block')?.querySelector('[data-city-suggest]')
        || scope.querySelector('[data-city-suggest]');
      bindSuggest({
        input: cityInput,
        list: cityList,
        fetchItems: async (q) => {
          const res = await fetch(`${citiesUrl}?q=${encodeURIComponent(q)}`);
          const data = await res.json();
          return (data.items || []).map((item) => ({
            label: item.label,
            apply: () => {
              cityInput.value = item.city;
              if (postalInput && item.cap && !postalInput.value) {
                postalInput.value = item.cap;
              }
              applyProvince(item);
              addressInput?.focus();
            },
          }));
        },
        onPick: () => {
          if (addressInput) {
            addressInput.dispatchEvent(new Event('input', { bubbles: true }));
          }
        },
        resolve: {
          minChars: 2,
          run: async (raw) => {
            const data = await resolveGeoQuery(citiesUrl, { q: raw });
            await handleGeoResolveResult(data, cityInput, (item) => {
              cityInput.value = item.city || item.label || raw;
              if (postalInput && item.cap) {
                postalInput.value = item.cap;
              }
              applyProvince(item);
              if (addressInput) {
                addressInput.dispatchEvent(new Event('input', { bubbles: true }));
              }
            }, confirmCityTpl, { notFoundTemplate: cityNotFoundTpl });
          },
        },
      });
      cityInput.addEventListener('change', () => {
        if (addressInput?.dataset.suggestBound === '1') {
          addressInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
      });
    }

    if (addressInput && addressInput.dataset.suggestBound !== '1') {
      addressInput.dataset.suggestBound = '1';
      const addressList = addressInput.closest('.suggest-wrap, .suggest-field, label, .field-block')?.querySelector('[data-address-suggest]')
        || scope.querySelector('[data-address-suggest]');
      const pairedCity = cityInput || form.querySelector?.('[data-city-input]') || null;
      const cityFirstMsg = document.body?.dataset?.msgCityFirst
        || 'Seleziona prima la città';
      bindSuggest({
        input: addressInput,
        list: addressList,
        minChars: 3,
        fetchItems: async (q) => {
          const city = pairedCity?.value?.trim() || '';
          if (!city) {
            return [{ label: cityFirstMsg, apply: () => pairedCity?.focus() }];
          }
          const res = await fetch(`${addressesUrl}?q=${encodeURIComponent(q)}&city=${encodeURIComponent(city)}`);
          const data = await res.json();
          const seen = new Set();
          return (data.items || []).flatMap((item) => {
            const key = [
              String(item.address || '').trim().toLowerCase(),
              String(item.house_number || '').trim().toLowerCase(),
              String(item.city || city).trim().toLowerCase(),
              String(item.postal_code || '').trim().toLowerCase(),
            ].join('|');
            if (seen.has(key)) return [];
            seen.add(key);
            return [{
              label: item.label,
              apply: () => {
                addressInput.value = item.address || item.label;
                if (houseNumberInput && item.house_number) {
                  houseNumberInput.value = item.house_number;
                }
                if (postalInput && item.postal_code) postalInput.value = item.postal_code;
                if (houseNumberInput && !item.house_number) {
                  houseNumberInput.focus();
                }
              },
            }];
          });
        },
        resolve: {
          minChars: 3,
          skipIf: () => !(pairedCity?.value?.trim() || ''),
          run: async (raw) => {
            const city = pairedCity?.value?.trim() || '';
            if (!city) return;
            const data = await resolveGeoQuery(addressesUrl, {
              q: raw,
              city,
              house_number: houseNumberInput?.value?.trim() || '',
            });
            await handleGeoResolveResult(data, addressInput, (item) => {
              addressInput.value = item.address || item.label || raw;
              if (houseNumberInput && item.house_number) {
                houseNumberInput.value = item.house_number;
              }
              if (postalInput && item.postal_code) {
                postalInput.value = item.postal_code;
              }
            }, confirmAddressTpl);
          },
        },
      });
    }
  });
}

function bindSuggest({ input, list, fetchItems, minChars = 2, onPick, resolve = null }) {
  if (!input || !list) return;
  let active = -1;
  let items = [];
  let pickedFromList = false;
  const field = input.closest('.suggest-field');
  input.dataset.geoPicked = input.dataset.geoPicked || '0';

  const hide = () => {
    list.hidden = true;
    list.innerHTML = '';
    active = -1;
    items = [];
    field?.classList.remove('is-open');
  };

  const render = () => {
    list.innerHTML = '';
    items.forEach((item, idx) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = item.label;
      if (idx === active) btn.classList.add('active');
      btn.addEventListener('mousedown', (e) => {
        e.preventDefault();
        item.apply();
        pickedFromList = true;
        input.dataset.geoPicked = '1';
        clearGeoCityError(input);
        hide();
        onPick?.();
      });
      list.appendChild(btn);
    });
    list.hidden = items.length === 0;
    field?.classList.toggle('is-open', !list.hidden);
  };

  const run = debounce(async () => {
    const q = input.value.trim();
    if (q.length < minChars) {
      hide();
      return;
    }
    try {
      items = await fetchItems(q);
      active = -1;
      render();
    } catch (err) {
      hide();
    }
  }, 300);

  input.addEventListener('input', () => {
    pickedFromList = false;
    input.dataset.geoPicked = '0';
    clearGeoCityError(input);
    run();
  });
  input.addEventListener('keydown', (e) => {
    if (list.hidden) return;
    const buttons = [...list.querySelectorAll('button')];
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      active = Math.min(active + 1, buttons.length - 1);
      render();
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      active = Math.max(active - 1, 0);
      render();
    } else if (e.key === 'Enter' && active >= 0 && items[active]) {
      e.preventDefault();
      items[active].apply();
      pickedFromList = true;
      input.dataset.geoPicked = '1';
      clearGeoCityError(input);
      hide();
      onPick?.();
    } else if (e.key === 'Escape') {
      hide();
    }
  });
  input.addEventListener('blur', () => {
    setTimeout(async () => {
      hide();
      if (pickedFromList || input.dataset.geoPicked === '1' || !resolve) return;
      const raw = input.value.trim();
      const resolveMin = resolve.minChars ?? minChars;
      if (raw.length < resolveMin) return;
      if (typeof resolve.skipIf === 'function' && resolve.skipIf(raw)) return;
      try {
        await resolve.run(raw);
      } catch (err) {
        /* ignore resolve errors on blur */
      }
    }, 180);
  });
}

function initFiscalCodeAuto(form) {
  const cfInput = form.querySelector('[data-fiscal-code]');
  const status = form.querySelector('[data-cf-status]');
  const button = form.querySelector('[data-cf-generate]');
  if (!cfInput) return;

  const fields = () => ({
    first_name: form.querySelector('[data-first-name]')?.value || '',
    last_name: form.querySelector('[data-last-name]')?.value || '',
    birth_date: form.querySelector('[data-birth-date]')?.value || '',
    gender: form.querySelector('[data-gender-input]')?.value || '',
    birth_place: form.querySelector('[data-birth-place-input]')?.value || '',
  });

  const setStatus = (msg) => {
    if (!status) return;
    const text = String(msg || '').trim();
    status.textContent = text;
    status.hidden = text === '';
  };

  const generate = async (force = false) => {
    const payload = fields();
    if (!payload.first_name || !payload.last_name || !payload.birth_date || !payload.gender || !payload.birth_place) {
      if (force) setStatus(status?.dataset.incomplete || '');
      return;
    }
    if (payload.gender === 'X') {
      if (force) setStatus(status?.dataset.genderOther || '');
      return;
    }
    if (!force && cfInput.dataset.manual === '1' && cfInput.value.trim() !== '') {
      return;
    }
    try {
      const body = new URLSearchParams(payload);
      body.set('_token', form.dataset.csrf || '');
      const res = await fetch(form.dataset.cfUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': form.dataset.csrf || '',
        },
        body,
      });
      const data = await res.json();
      if (data.ok && data.fiscal_code) {
        cfInput.value = data.fiscal_code;
        cfInput.dataset.manual = '0';
        cfInput.classList.add('input-valid');
        setStatus(status?.dataset.ready || 'OK');
      } else if (force) {
        setStatus(data.error || status?.dataset.incomplete || '');
      }
    } catch (err) {
      if (force) setStatus('Errore calcolo CF');
    }
  };

  ['data-first-name', 'data-last-name', 'data-birth-date', 'data-gender-input', 'data-birth-place-input']
    .forEach((sel) => {
      form.querySelector(`[${sel}]`)?.addEventListener('change', () => generate(false));
      form.querySelector(`[${sel}]`)?.addEventListener('blur', () => generate(false));
    });
  form.addEventListener('cf:refresh', () => generate(false));
  button?.addEventListener('click', () => generate(true));
  cfInput.addEventListener('input', () => {
    cfInput.dataset.manual = '1';
    cfInput.value = cfInput.value.toUpperCase();
    cfInput.classList.remove('input-valid');
    if (/^[A-Z0-9]{16}$/.test(cfInput.value.trim())) {
      cfInput.setCustomValidity('');
      setStatus('');
    }
  });
}

function initSidebarDeadlines() {
  const box = document.querySelector('[data-sidebar-deadlines]');
  if (!box) {
    return;
  }

  const list = box.querySelector('[data-sidebar-deadlines-list]');
  const more = box.querySelector('[data-sidebar-deadlines-more]');
  const items = Array.from(box.querySelectorAll('[data-sidebar-deadline-item]'));
  if (!list || items.length === 0) {
    return;
  }

  const moreTemplate = box.getAttribute('data-more-template') || '+:count';
  let current = 0;
  const show = (index) => {
    items.forEach((item, i) => {
      item.hidden = i !== index;
      item.classList.toggle('is-active', i === index);
    });
  };

  show(current);
  if (more && items.length > 1) {
    more.hidden = false;
    more.textContent = moreTemplate.replace(':count', String(items.length - 1));
  }
  if (items.length > 1 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    window.setInterval(() => {
      const active = items[current];
      active.classList.add('is-fading');
      window.setTimeout(() => {
        current = (current + 1) % items.length;
        show(current);
        active.classList.remove('is-fading');
      }, 220);
    }, 5000);
  }
}

function initMobileNav() {
  const shell = document.querySelector('[data-app-shell]');
  if (!shell) {
    return;
  }

  const openBtn = shell.querySelector('[data-sidebar-open]');
  const closeTargets = shell.querySelectorAll('[data-sidebar-close]');
  const navLinks = shell.querySelectorAll('.nav a');

  const setOpen = (open) => {
    shell.classList.toggle('is-nav-open', open);
    document.body.classList.toggle('nav-open', open);
    if (openBtn) {
      openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
  };

  openBtn?.addEventListener('click', () => setOpen(true));
  closeTargets.forEach((el) => {
    el.addEventListener('click', () => setOpen(false));
  });
  navLinks.forEach((link) => {
    link.addEventListener('click', () => setOpen(false));
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      setOpen(false);
    }
  });

  window.addEventListener('resize', () => {
    if (window.matchMedia('(min-width: 961px)').matches) {
      setOpen(false);
    }
  });
}

function initTopbarScroll() {
  const topbar = document.querySelector('[data-topbar]');
  const configBtn = topbar?.querySelector('[data-topbar-config]');
  if (!topbar || !configBtn) {
    return;
  }

  let lastY = window.scrollY || 0;
  let ticking = false;

  const update = () => {
    ticking = false;
    const y = window.scrollY || 0;
    const goingDown = y > lastY;
    const hide = goingDown && y > 24;
    topbar.classList.toggle('is-config-hidden', hide);
    lastY = y;
  };

  window.addEventListener('scroll', () => {
    if (ticking) {
      return;
    }
    ticking = true;
    window.requestAnimationFrame(update);
  }, { passive: true });

  update();
}

function initDeadlineCategory(root = document) {
  root.querySelectorAll('[data-deadline-form]').forEach((form) => {
    const categorySelect = form.querySelector('[data-deadline-category]');
    const newCategoryWrap = form.querySelector('[data-deadline-new-category]');
    const newCategoryInput = form.querySelector('[data-deadline-new-category-input]');
    if (!categorySelect) {
      return;
    }
    const sync = () => {
      const isNew = categorySelect.value === '__new__';
      categorySelect.hidden = isNew;
      if (categorySelect.previousElementSibling?.tagName === 'LABEL') {
        categorySelect.previousElementSibling.hidden = isNew;
      }
      if (newCategoryWrap) {
        newCategoryWrap.hidden = !isNew;
      }
      if (newCategoryInput) {
        newCategoryInput.required = !!isNew;
        if (!isNew) {
          newCategoryInput.value = '';
        }
      }
      if (isNew) {
        newCategoryInput?.classList.remove('category-attention');
        requestAnimationFrame(() => {
          newCategoryInput?.classList.add('category-attention');
          newCategoryInput?.focus();
        });
      }
    };
    categorySelect.addEventListener('change', sync);
    sync();
    form.addEventListener('submit', async (event) => {
      const template = form.getAttribute('data-confirm-template') || '';
      if (!template) {
        return;
      }
      if (form.dataset.confirmed === '1') {
        return;
      }
      event.preventDefault();
      const title = form.querySelector('[name="title"]')?.value?.trim() || '—';
      const due = form.querySelector('[name="due_date"]')?.value || '—';
      const category = categorySelect.value === '__new__'
        ? (newCategoryInput?.value?.trim() || '—')
        : (categorySelect.options[categorySelect.selectedIndex]?.text || '—');
      const summary = template
        .replace(':title', title)
        .replace(':date', due)
        .replace(':category', category);
      if (!(await appConfirm(summary))) {
        return;
      }
      form.dataset.confirmed = '1';
      form.requestSubmit();
    });
  });
}

function formatFileSize(bytes) {
  if (!Number.isFinite(bytes) || bytes <= 0) {
    return '';
  }
  if (bytes < 1024) {
    return `${bytes} B`;
  }
  if (bytes < 1024 * 1024) {
    return `${Math.round(bytes / 1024)} KB`;
  }
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function initTreasuryFileField(form) {
  const field = form.querySelector('[data-treasury-doc-field]');
  const input = form.querySelector('[data-treasury-doc-input]');
  if (!field || !input) {
    return;
  }

  const nameEl = form.querySelector('[data-treasury-doc-name]');
  const statusEl = form.querySelector('[data-treasury-doc-status]');
  const preview = form.querySelector('[data-treasury-doc-preview]');
  const pickLabel = form.querySelector('[data-treasury-doc-pick-label]');
  const detachBtn = form.querySelector('[data-treasury-doc-detach]');
  const detachInput = form.querySelector('[data-treasury-doc-detach-input]');
  const msgLoaded = form.dataset.msgDocLoaded || 'Documento caricato (:size)';
  const msgIdle = form.dataset.msgDocIdle || '';
  const msgChange = form.dataset.msgDocChange || '';
  const chooseLabel = pickLabel?.textContent?.trim() || '';

  let objectUrl = '';

  const clearObjectUrl = () => {
    if (objectUrl) {
      URL.revokeObjectURL(objectUrl);
      objectUrl = '';
    }
  };

  const setPreview = (url) => {
    if (!preview) {
      return;
    }
    if (!url) {
      preview.hidden = true;
      preview.innerHTML = '';
      return;
    }
    preview.hidden = false;
    preview.innerHTML = `<iframe src="${url}#toolbar=0" title="PDF" loading="lazy"></iframe>`;
  };

  const setUploaded = (name, size, previewUrl) => {
    field.classList.add('is-uploaded');
    field.classList.remove('is-error');
    if (nameEl) {
      nameEl.textContent = name;
      nameEl.hidden = name === '';
    }
    if (statusEl) {
      const sizeLabel = formatFileSize(size);
      statusEl.textContent = sizeLabel !== ''
        ? msgLoaded.replace(':size', sizeLabel)
        : msgLoaded.replace(/\s*\(:size\)/, '').replace(':size', '');
    }
    if (pickLabel && msgChange) {
      pickLabel.textContent = msgChange;
    }
    if (detachInput) {
      detachInput.value = '';
    }
    setPreview(previewUrl);
  };

  const setIdle = () => {
    field.classList.remove('is-uploaded', 'is-error');
    if (nameEl) {
      nameEl.textContent = '';
      nameEl.hidden = true;
    }
    if (statusEl && msgIdle) {
      statusEl.textContent = msgIdle;
    }
    if (pickLabel) {
      pickLabel.textContent = chooseLabel;
    }
    clearObjectUrl();
    setPreview('');
    input.value = '';
  };

  input.addEventListener('change', () => {
    const file = input.files?.[0];
    if (!file) {
      return;
    }
    clearObjectUrl();
    objectUrl = URL.createObjectURL(file);
    setUploaded(file.name, file.size, objectUrl);
  });

  detachBtn?.addEventListener('click', () => {
    if (detachInput) {
      detachInput.value = '1';
    }
    setIdle();
    field.classList.remove('is-uploaded');
  });
}

function formatTreasuryConfirmMessage(form, template) {
  const directionHidden = form.querySelector('[data-treasury-direction]');
  const signSelect = form.querySelector('[data-treasury-sign]');
  const kindSelect = form.querySelector('[data-treasury-kind]');
  const categorySelect = form.querySelector('[data-treasury-category]');
  const newCategoryInput = form.querySelector('[data-treasury-new-category-input]');
  const amountRaw = String(form.querySelector('[name="amount"]')?.value || '').trim();
  const dateRaw = String(form.querySelector('[name="movement_date"]')?.value || '').trim();

  const directionValue = directionHidden?.value || signSelect?.value || 'income';
  const directionLabel = signSelect?.selectedOptions?.[0]?.textContent?.trim()
    || (directionValue === 'expense' ? 'Uscita' : 'Entrata');
  const kindLabel = kindSelect?.selectedOptions?.[0]?.textContent?.trim() || '';
  let categoryLabel = '—';
  if (categorySelect?.value === '__new__') {
    categoryLabel = String(newCategoryInput?.value || '').trim() || '—';
  } else {
    categoryLabel = categorySelect?.selectedOptions?.[0]?.textContent?.trim() || '—';
  }

  let amountLabel = amountRaw;
  const amountNum = Number(amountRaw.replace(',', '.'));
  if (Number.isFinite(amountNum)) {
    amountLabel = amountNum.toLocaleString(document.documentElement.lang || 'it-IT', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  const currencySelect = form.querySelector('[name="amount_currency"]');
  const currencyCode = String(currencySelect?.value || form.dataset.baseCurrency || 'EUR').toUpperCase();
  const currencySymbols = { EUR: '€', USD: '$', GBP: '£', CHF: 'CHF' };
  const currencySym = currencySymbols[currencyCode] || currencyCode;
  const amountDisplay = `${currencySym} ${amountLabel}`;

  let dateLabel = dateRaw;
  if (/^\d{4}-\d{2}-\d{2}$/.test(dateRaw)) {
    const [y, m, d] = dateRaw.split('-');
    dateLabel = `${d}/${m}/${y}`;
  }

  const tpl = String(template || '').trim();
  if (!tpl) {
    return `Confermi l'inserimento di un movimento di tipo ${directionLabel} di ${amountDisplay} per ${categoryLabel} con valuta il ${dateLabel}?`;
  }

  return tpl
    .replace(':type', directionLabel)
    .replace(':kind', kindLabel)
    .replace(':amount', amountDisplay)
    .replace(':date', dateLabel)
    .replace(':category', categoryLabel);
}

function initOrgPersonForm(form) {
  const roleSelect = form.querySelector('[data-org-role-select]');
  const residenceWrap = form.querySelector('[data-org-residence-fields]');
  const replaceInput = form.querySelector('[data-org-replace-unique]');

  const syncResidence = () => {
    const option = roleSelect?.selectedOptions?.[0];
    const needs = option?.dataset?.requiresResidence === '1';
    if (residenceWrap) {
      residenceWrap.hidden = !needs;
      residenceWrap.querySelectorAll('input, select, textarea').forEach((el) => {
        if (needs) {
          el.removeAttribute('disabled');
        } else {
          el.setAttribute('disabled', 'disabled');
        }
      });
    }
  };
  roleSelect?.addEventListener('change', syncResidence);
  syncResidence();

  let conflictRaw = form.dataset.roleConflict || '';
  if (conflictRaw) {
    try {
      const conflict = JSON.parse(conflictRaw);
      const tpl = form.dataset.msgRoleConflict || '';
      const message = tpl
        .replace(':existing', conflict.existing_name || '—')
        .replace(':new', conflict.new_name || '—')
        .replace(':role', conflict.role_label || conflict.role_key || '—');
      window.setTimeout(async () => {
        if (await appConfirm(message, { confirmLabel: form.dataset.msgRoleReplace || 'Sostituisci' })) {
          if (replaceInput) {
            replaceInput.value = '1';
          }
          form.requestSubmit();
        }
      }, 80);
    } catch {
      /* ignore malformed conflict payload */
    }
    delete form.dataset.roleConflict;
  }

  form.addEventListener('submit', (event) => {
    if (replaceInput?.value === '1') {
      return;
    }
    const option = roleSelect?.selectedOptions?.[0];
    if (option?.dataset?.requiresResidence !== '1' && residenceWrap) {
      residenceWrap.querySelectorAll('input, select, textarea').forEach((el) => {
        el.removeAttribute('disabled');
      });
    }
  });
}

function initDemoLoginNotice() {
  const body = document.body;
  if (!body || body.dataset.demoLoginShown === '1' || body.dataset.demoLoginNotice !== '1') {
    return;
  }
  body.dataset.demoLoginShown = '1';
  const tpl = body.dataset.demoLoginNoticeText || '';
  const expires = body.dataset.demoExpires || '';
  const message = tpl.replaceAll(':expires', expires).trim();
  const okLabel = body.dataset.demoLoginNoticeOk || 'Ho capito';
  window.setTimeout(() => {
    appConfirm(message, { alert: true, confirmLabel: okLabel });
  }, 120);
}

function formatNewsDate(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  try {
    return d.toLocaleDateString(document.documentElement.lang || 'it-IT', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
    });
  } catch {
    return iso;
  }
}

function initAuthNewsWidget() {
  const slot = document.querySelector('[data-auth-news]');
  if (!(slot instanceof HTMLElement)) return;
  const api = slot.dataset.newsApi || '';
  if (!api) return;

  fetch(api + (api.includes('?') ? '&' : '?') + 'limit=1', { credentials: 'omit' })
    .then((res) => (res.ok ? res.json() : null))
    .then((data) => {
      const item = data?.items?.[0];
      if (!item || !item.title) return;
      const heroStyle = item.image ? `background-image:url('${String(item.image).replace(/'/g, "\\'")}')` : '';
      const excerpt = item.excerpt || item.body || '';
      const url = item.url || '#';
      slot.innerHTML = `
        <article class="auth-news-card will-enter">
          ${heroStyle ? `<div class="auth-news-hero" style="${heroStyle}"></div>` : ''}
          <div class="auth-news-body">
            ${item.published_at ? `<div class="auth-news-date">${formatNewsDate(item.published_at)}</div>` : ''}
            <h2 class="auth-news-title">${escapeHtml(String(item.title))}</h2>
            ${excerpt ? `<p class="auth-news-excerpt">${escapeHtml(String(excerpt))}</p>` : ''}
            <a class="auth-news-link" href="${escapeHtml(String(url))}" target="_blank" rel="noopener noreferrer">Leggi tutta →</a>
          </div>
        </article>`;
      slot.hidden = false;
      enterScope(slot, { reset: true });
    })
    .catch(() => {});
}

function initBirthDateFields(root = document) {
  const scope = root && root.querySelectorAll ? root : document;
  const today = new Date();
  const maxAdult = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate());
  const maxStr = maxAdult.toISOString().slice(0, 10);
  const todayStr = today.toISOString().slice(0, 10);
  const msgFuture = document.body?.dataset?.msgBirthFuture || 'La data di nascita non può essere nel futuro.';
  const msgMinor = document.body?.dataset?.msgBirthMinor || 'La persona deve avere almeno 18 anni.';

  scope.querySelectorAll('[data-birth-date]').forEach((input) => {
    if (!(input instanceof HTMLInputElement) || input.dataset.birthBound === '1') return;
    input.dataset.birthBound = '1';
    input.max = maxStr;
    const validate = () => {
      const v = input.value;
      if (!v) {
        input.setCustomValidity('');
        return true;
      }
      if (v > todayStr) {
        input.setCustomValidity(msgFuture);
        return false;
      }
      if (v > maxStr) {
        input.setCustomValidity(msgMinor);
        return false;
      }
      input.setCustomValidity('');
      return true;
    };
    input.addEventListener('change', validate);
    input.addEventListener('input', validate);
  });

  const msgAppointedFuture = document.body?.dataset?.msgAppointedFuture || 'La data di nomina non può essere nel futuro.';
  const msgMandatePast = document.body?.dataset?.msgMandatePast || 'La scadenza del mandato deve essere una data futura.';
  const msgMandateOrder = document.body?.dataset?.msgMandateOrder || 'La scadenza del mandato deve essere successiva alla data di nomina.';

  scope.querySelectorAll('[data-appointed-date]').forEach((appointedInput) => {
    if (!(appointedInput instanceof HTMLInputElement) || appointedInput.dataset.mandateBound === '1') return;
    appointedInput.dataset.mandateBound = '1';
    const form = appointedInput.closest('form');
    const mandateInput = form?.querySelector('[data-mandate-ends-date]');
    if (!(mandateInput instanceof HTMLInputElement)) return;

    const validateMandate = () => {
      const appointed = appointedInput.value;
      const mandate = mandateInput.value;
      appointedInput.setCustomValidity('');
      mandateInput.setCustomValidity('');
      if (appointed && appointed > todayStr) {
        appointedInput.setCustomValidity(msgAppointedFuture);
      }
      if (mandate && mandate <= todayStr) {
        mandateInput.setCustomValidity(msgMandatePast);
      }
      if (appointed && mandate && appointed >= mandate) {
        mandateInput.setCustomValidity(msgMandateOrder);
      }
    };

    appointedInput.addEventListener('change', validateMandate);
    appointedInput.addEventListener('input', validateMandate);
    mandateInput.addEventListener('change', validateMandate);
    mandateInput.addEventListener('input', validateMandate);
  });
}

async function resolvePendingGeoFields(form) {
  const urls = resolveGeoUrls(form);
  if (!urls) return;
  const { citiesUrl, addressesUrl } = urls;
  const confirmCityTpl = document.body?.dataset?.msgGeoConfirmCity || '';
  const confirmAddressTpl = document.body?.dataset?.msgGeoConfirmAddress || '';
  const cityNotFoundTpl = document.body?.dataset?.msgGeoCityNotFound || '';

  for (const cityInput of form.querySelectorAll('[data-city-input]')) {
    if (!(cityInput instanceof HTMLInputElement) || cityInput.dataset.geoPicked === '1') continue;
    const raw = cityInput.value.trim();
    if (raw.length < 2) continue;
    const data = await resolveGeoQuery(citiesUrl, { q: raw });
    await handleGeoResolveResult(data, cityInput, (item) => {
      cityInput.value = item.city || item.label || raw;
      const postalInput = cityInput.closest('form')?.querySelector('[data-postal-code]');
      if (postalInput instanceof HTMLInputElement && item.cap) {
        postalInput.value = item.cap;
      }
    }, confirmCityTpl, { notFoundTemplate: cityNotFoundTpl });
  }

  for (const addressInput of form.querySelectorAll('[data-address-input]')) {
    if (!(addressInput instanceof HTMLInputElement) || addressInput.dataset.geoPicked === '1') continue;
    const raw = addressInput.value.trim();
    if (raw.length < 3) continue;
    const scope = addressInput.closest('[data-geo-scope], [data-people-row], .setup-address, .setup-president, [data-member-form], form') || form;
    const city = scope.querySelector('[data-city-input]')?.value?.trim() || '';
    if (!city) continue;
    const houseNumberInput = scope.querySelector('[data-house-number]');
    const postalInput = scope.querySelector('[data-postal-code]');
    const data = await resolveGeoQuery(addressesUrl, {
      q: raw,
      city,
      house_number: houseNumberInput instanceof HTMLInputElement ? houseNumberInput.value.trim() : '',
    });
    await handleGeoResolveResult(data, addressInput, (item) => {
      addressInput.value = item.address || item.label || raw;
      if (houseNumberInput instanceof HTMLInputElement && item.house_number) {
        houseNumberInput.value = item.house_number;
      }
      if (postalInput instanceof HTMLInputElement && item.postal_code) {
        postalInput.value = item.postal_code;
      }
    }, confirmAddressTpl);
  }
}

function initGeoSubmitValidation(root = document) {
  const scope = root && root.querySelectorAll ? root : document;
  scope.querySelectorAll('form').forEach((form) => {
    if (!(form instanceof HTMLFormElement) || form.dataset.geoSubmitBound === '1') return;
    if (!form.querySelector('[data-address-input], [data-city-input]')) return;
    form.dataset.geoSubmitBound = '1';
    form.addEventListener('submit', async (event) => {
      if (form.dataset.geoResolved === '1') {
        delete form.dataset.geoResolved;
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      try {
        await resolvePendingGeoFields(form);
        form.dataset.geoResolved = '1';
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          HTMLFormElement.prototype.submit.call(form);
        }
      } catch {
        delete form.dataset.geoResolved;
      }
    }, { capture: true });
  });
}

function initAuthUpdateCheck() {
  const btn = document.querySelector('[data-auth-check-updates]');
  const dialog = document.querySelector('[data-auth-updates-dialog]');
  if (!(btn instanceof HTMLButtonElement) || !(dialog instanceof HTMLDialogElement)) return;

  const titleEl = dialog.querySelector('[data-auth-updates-title]');
  const textEl = dialog.querySelector('[data-auth-updates-text]');
  const actionsEl = dialog.querySelector('[data-auth-updates-actions]');
  const closeBtn = dialog.querySelector('[data-auth-updates-close]');

  const tpl = (key, fallback = '') => dialog.dataset[key] || fallback;

  const openDialog = () => {
    if (typeof dialog.showModal === 'function') dialog.showModal();
  };

  const closeDialog = () => {
    if (dialog.open) dialog.close();
  };

  closeBtn?.addEventListener('click', closeDialog);
  dialog.addEventListener('cancel', (e) => {
    e.preventDefault();
    closeDialog();
  });

  btn.addEventListener('click', async () => {
    const endpoint = btn.dataset.updatesEndpoint || '/api/updates/check';
    if (titleEl) titleEl.textContent = tpl('i18nChecking', 'Verifica in corso…');
    if (textEl) textEl.textContent = '';
    if (actionsEl) {
      actionsEl.innerHTML = '';
      actionsEl.hidden = true;
    }
    openDialog();
    btn.disabled = true;

    try {
      const res = await fetch(endpoint + '?force=1', { credentials: 'same-origin' });
      const data = await res.json();
      if (!data?.ok) throw new Error(data?.error || 'check failed');

      if (data.available) {
        if (titleEl) titleEl.textContent = tpl('i18nAvailableTitle', 'Aggiornamento disponibile');
        const textTemplate = tpl('i18nAvailableText', 'Versione attuale v:current · nuova v:remote');
        if (textEl) {
          textEl.textContent = textTemplate
            .replace(':current', data.current || '')
            .replace(':remote', data.remote || '');
        }
        if (actionsEl) {
          actionsEl.hidden = false;
          const links = [];
          if (data.notes_url) {
            links.push(`<a class="btn btn-sm btn-ghost" href="${escapeHtml(data.notes_url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(tpl('i18nNotes', 'Novità'))}</a>`);
          }
          if (data.download_url) {
            links.push(`<a class="btn btn-sm btn-ghost" href="${escapeHtml(data.download_url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(tpl('i18nDownload', 'Scarica'))}</a>`);
          }
          if (data.install_guide_url) {
            links.push(`<a class="btn btn-sm btn-ghost" href="${escapeHtml(data.install_guide_url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(tpl('i18nGuide', 'Come aggiornare'))}</a>`);
          }
          if (!data.install_available && links.length === 0) {
            if (textEl) {
              textEl.textContent += '\n\n' + tpl('i18nManualHint', '');
            }
          }
          actionsEl.innerHTML = links.join('');
        }
      } else {
        if (titleEl) titleEl.textContent = tpl('i18nNoneTitle', 'Nessun aggiornamento');
        const noneText = tpl('i18nNoneText', 'Stai usando l’ultima versione (v:version).').replace(':version', data.current || '');
        if (textEl) textEl.textContent = noneText;
      }
    } catch {
      if (titleEl) titleEl.textContent = tpl('i18nErrorTitle', 'Verifica non riuscita');
      if (textEl) textEl.textContent = tpl('i18nErrorText', 'Riprova tra poco.');
    } finally {
      btn.disabled = false;
    }
  });
}

function initTreasuryCategory(root = document) {
  root.querySelectorAll('[data-treasury-form]').forEach((form) => {
    const categorySelect = form.querySelector('[data-treasury-category]');
    const newCategoryWrap = form.querySelector('[data-treasury-new-category]');
    const newCategoryInput = form.querySelector('[data-treasury-new-category-input]');
    const directionHidden = form.querySelector('[data-treasury-direction]');
    const kindSelect = form.querySelector('[data-treasury-kind]');
    const signSelect = form.querySelector('[data-treasury-sign]');
    const signWrap = form.querySelector('[data-treasury-sign-wrap]');
    const invoiceToggle = form.querySelector('[data-treasury-invoice-toggle]');
    const memberToggle = form.querySelector('[data-treasury-member-toggle]');
    const memberFields = form.querySelector('[data-treasury-member-fields]');
    const memberSelect = form.querySelector('[data-treasury-member-select]');
    const amountCurrency = form.querySelector('[data-treasury-amount-currency]');
    const currencyHint = form.querySelector('[data-treasury-currency-hint]');
    const baseCurrency = String(form.dataset.baseCurrency || 'EUR').toUpperCase();
    if (!categorySelect) {
      return;
    }

    const syncKindSign = () => {
      if (!kindSelect || !directionHidden) {
        return;
      }
      const option = kindSelect.selectedOptions?.[0];
      const directions = option?.dataset?.directions || 'both';
      const defaultDir = option?.dataset?.defaultDirection || 'income';
      if (signSelect) {
        if (directions === 'income') {
          signSelect.value = 'income';
          signSelect.disabled = true;
          if (signWrap) signWrap.hidden = true;
        } else if (directions === 'expense') {
          signSelect.value = 'expense';
          signSelect.disabled = true;
          if (signWrap) signWrap.hidden = true;
        } else {
          signSelect.disabled = false;
          if (signWrap) signWrap.hidden = false;
          if (!signSelect.value || (signSelect.value !== 'income' && signSelect.value !== 'expense')) {
            signSelect.value = defaultDir;
          }
        }
      }
      directionHidden.value = signSelect?.value || defaultDir;
    };
    kindSelect?.addEventListener('change', syncKindSign);
    signSelect?.addEventListener('change', () => {
      if (directionHidden && signSelect) {
        directionHidden.value = signSelect.value;
      }
      syncDirection();
    });
    syncKindSign();

    const syncMember = () => {
      const involved = !!memberToggle?.checked;
      if (memberFields) {
        memberFields.hidden = !involved;
      }
      if (memberSelect) {
        memberSelect.disabled = !involved;
        if (!involved) {
          memberSelect.value = '';
        }
      }
    };
    memberToggle?.addEventListener('change', syncMember);
    syncMember();

    const syncCurrencyHint = () => {
      if (!currencyHint || !amountCurrency) {
        return;
      }
      const selected = String(amountCurrency.value || baseCurrency).toUpperCase();
      if (selected !== baseCurrency) {
        currencyHint.hidden = false;
        const tpl = currencyHint.dataset.template || '';
        currencyHint.textContent = tpl.replace(':currency', selected).replace(':base', baseCurrency);
      } else {
        currencyHint.hidden = true;
        currencyHint.textContent = '';
      }
    };
    amountCurrency?.addEventListener('change', syncCurrencyHint);
    syncCurrencyHint();

    initTreasuryFileField(form);

    const sync = () => {
      const isNew = categorySelect.value === '__new__';
      categorySelect.hidden = isNew;
      if (categorySelect.previousElementSibling?.tagName === 'LABEL') {
        categorySelect.previousElementSibling.hidden = isNew;
      }
      if (newCategoryWrap) {
        newCategoryWrap.hidden = !isNew;
      }
      if (newCategoryInput) {
        newCategoryInput.required = !!isNew;
        if (!isNew) {
          newCategoryInput.value = '';
        }
      }
      if (isNew) {
        newCategoryInput?.classList.remove('category-attention');
        requestAnimationFrame(() => {
          newCategoryInput?.classList.add('category-attention');
          newCategoryInput?.focus();
        });
      }
    };
    categorySelect.addEventListener('change', sync);
    sync();

    const syncDirection = () => {
      const dir = directionHidden?.value || signSelect?.value || 'income';
      const isExpense = dir === 'expense';
      form.querySelectorAll('[data-treasury-expense-fields]').forEach((field) => {
        field.hidden = !isExpense;
        field.querySelectorAll('input, select, textarea').forEach((input) => {
          input.disabled = !isExpense;
        });
      });
      const isInvoice = isExpense && !!invoiceToggle?.checked;
      form.querySelectorAll('[data-treasury-invoice-fields]').forEach((field) => {
        field.hidden = !isInvoice;
        field.querySelectorAll('input, select, textarea').forEach((input) => {
          input.disabled = !isInvoice;
        });
      });
    };
    directionHidden?.addEventListener('change', syncDirection);
    invoiceToggle?.addEventListener('change', syncDirection);
    syncDirection();

    form.addEventListener('submit', async (event) => {
      if (form.dataset.confirmed === '1') {
        return;
      }
      const template = form.dataset.confirmTemplate || '';
      if (!template) {
        return;
      }
      event.preventDefault();

      const pdfInput = form.querySelector('input[name="invoice_pdf"]');
      const pdfFile = pdfInput?.files?.[0];
      const maxBytes = Number(form.dataset.maxUploadBytes || bodyMaxUploadBytes() || 0);
      const tooLargeMsg = form.dataset.msgUploadTooLarge || 'File troppo grande.';
      if (pdfFile && maxBytes > 0 && pdfFile.size > maxBytes) {
        await appConfirm(tooLargeMsg.replace(':max', String(Math.ceil(maxBytes / (1024 * 1024)))), { alert: true });
        return;
      }

      const message = formatTreasuryConfirmMessage(form, template);
      if (!(await appConfirm(message))) {
        return;
      }
      form.dataset.confirmed = '1';
      form.requestSubmit();
    });
  });
}

function bodyMaxUploadBytes() {
  const raw = document.body?.dataset?.maxUploadBytes || '';
  const n = Number(raw);
  return Number.isFinite(n) && n > 0 ? n : 0;
}

function initDocumentRowLinks(root = document) {
  root.querySelectorAll('tr.doc-row-editable[data-href]').forEach((row) => {
    const href = row.getAttribute('data-href');
    if (!href) {
      return;
    }
    row.addEventListener('click', (event) => {
      const target = event.target;
      if (target instanceof Element && target.closest('a, button, input, label, select, textarea')) {
        return;
      }
      window.location.href = href;
    });
    row.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') {
        return;
      }
      event.preventDefault();
      window.location.href = href;
    });
  });
}

function initDocumentUpload(root = document) {
  const form = root.querySelector('[data-document-form]');
  if (!form) {
    return;
  }

  const input = form.querySelector('[data-doc-file-input]');
  const pathInput = form.querySelector('[data-doc-uploaded-path]');
  const mimeInput = form.querySelector('[data-doc-uploaded-mime]');
  const nameEl = form.querySelector('[data-doc-file-name]');
  const statusEl = form.querySelector('[data-doc-file-status]');
  const progressWrap = form.querySelector('[data-doc-file-progress]');
  const progressBar = form.querySelector('[data-doc-file-progress-bar]');
  const progressPct = form.querySelector('[data-doc-file-progress-pct]');
  const pickLabel = form.querySelector('[data-doc-pick-label]');
  const field = form.querySelector('[data-doc-file-field]');
  const uploadUrl = form.getAttribute('data-upload-url') || '';
  const msgIdle = form.getAttribute('data-msg-idle') || '';
  const msgBusy = form.getAttribute('data-msg-uploading') || '';
  const msgOk = form.getAttribute('data-msg-ok') || '';
  const msgFail = form.getAttribute('data-msg-fail') || '';
  const msgChange = form.getAttribute('data-msg-change') || '';
  const chooseLabel = pickLabel?.textContent || '';
  const csrf = form.querySelector('input[name="_token"]')?.value || '';

  if (!input || !uploadUrl) {
    return;
  }

  const categorySelect = form.querySelector('[data-doc-category]');
  const newCategoryWrap = form.querySelector('[data-doc-new-category]');
  const newCategoryInput = form.querySelector('[data-doc-new-category-input]');
  const syncCategory = () => {
    const isNew = categorySelect?.value === '__new__';
    if (categorySelect) categorySelect.hidden = isNew;
    if (categorySelect?.previousElementSibling?.tagName === 'LABEL') {
      categorySelect.previousElementSibling.hidden = isNew;
    }
    if (newCategoryWrap) {
      newCategoryWrap.hidden = !isNew;
    }
    if (newCategoryInput) {
      newCategoryInput.required = !!isNew;
      if (!isNew) {
        newCategoryInput.value = '';
      }
    }
    if (isNew) {
      newCategoryInput?.classList.remove('category-attention');
      requestAnimationFrame(() => {
        newCategoryInput?.classList.add('category-attention');
        newCategoryInput?.focus();
      });
    }
  };
  categorySelect?.addEventListener('change', syncCategory);
  syncCategory();

  const setProgress = (pct, visible) => {
    if (progressWrap) {
      progressWrap.hidden = !visible;
    }
    const clamped = Math.max(0, Math.min(100, Math.round(pct)));
    if (progressBar) {
      progressBar.style.width = `${clamped}%`;
    }
    if (progressPct) {
      progressPct.textContent = `${clamped}%`;
    }
  };

  const setState = (state, name = '', statusOverride = '') => {
    field?.classList.remove('is-idle', 'is-uploading', 'is-uploaded', 'is-error');
    field?.classList.add(`is-${state}`);
    if (nameEl) {
      nameEl.textContent = name;
      nameEl.hidden = name === '';
    }
    if (statusEl) {
      if (statusOverride) statusEl.textContent = statusOverride;
      else if (state === 'uploading') statusEl.textContent = msgBusy;
      else if (state === 'uploaded') statusEl.textContent = msgOk;
      else if (state === 'error') statusEl.textContent = msgFail;
      else statusEl.textContent = msgIdle;
    }
    if (pickLabel) {
      pickLabel.textContent = state === 'uploaded' && msgChange ? msgChange : chooseLabel;
    }
    if (state !== 'uploading') {
      setProgress(state === 'uploaded' ? 100 : 0, false);
    }
  };

  const uploadWithProgress = (body) => new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', uploadUrl, true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.upload.addEventListener('progress', (event) => {
      if (!event.lengthComputable) {
        return;
      }
      const pct = (event.loaded / event.total) * 100;
      setProgress(pct, true);
      if (statusEl) {
        statusEl.textContent = `${msgBusy} ${Math.round(pct)}%`;
      }
    });
    xhr.addEventListener('load', () => {
      let data = {};
      try {
        data = JSON.parse(xhr.responseText || '{}');
      } catch (_err) {
        data = {};
      }
      resolve({ ok: xhr.status >= 200 && xhr.status < 300, status: xhr.status, data });
    });
    xhr.addEventListener('error', () => reject(new Error('network')));
    xhr.addEventListener('abort', () => reject(new Error('abort')));
    xhr.send(body);
  });

  input.addEventListener('change', async () => {
    const file = input.files && input.files[0];
    if (!file) {
      if (pathInput) pathInput.value = '';
      if (mimeInput) mimeInput.value = '';
      setState('idle');
      return;
    }

    setState('uploading', file.name);
    setProgress(0, true);
    const body = new FormData();
    body.append('document_file', file);
    if (csrf) {
      body.append('_token', csrf);
    }

    try {
      const res = await uploadWithProgress(body);
      if (!res.ok || !res.data.ok) {
        if (pathInput) pathInput.value = '';
        if (mimeInput) mimeInput.value = '';
        let errMsg = res.data && res.data.error ? String(res.data.error) : msgFail;
        if (res.status === 413) {
          errMsg = form.getAttribute('data-msg-too-large') || errMsg;
        }
        setState('error', file.name, errMsg);
        return;
      }
      if (pathInput) pathInput.value = String(res.data.path || '');
      if (mimeInput) mimeInput.value = String(res.data.mime || '');
      setProgress(100, true);
      setState('uploaded', String(res.data.name || file.name));
      // Clear native input so a re-submit does not re-upload; path is already stored.
      input.value = '';
    } catch (_err) {
      if (pathInput) pathInput.value = '';
      if (mimeInput) mimeInput.value = '';
      setState('error', file.name);
    }
  });
}

function initComponentCards(root = document) {
  const rows = Array.from(root.querySelectorAll('.setup-component-row'));
  if (rows.length === 0) {
    return;
  }

  const sync = (row) => {
    const input = row.querySelector('input[type="checkbox"]');
    if (!input) {
      return;
    }
    row.classList.toggle('is-selected', !!input.checked);
  };

  rows.forEach((row) => {
    const input = row.querySelector('input[type="checkbox"]');
    if (!input) {
      return;
    }
    sync(row);
    input.addEventListener('change', () => sync(row));
    row.addEventListener('click', () => {
      window.setTimeout(() => sync(row), 0);
    });
  });
}

function initConfigAccordions() {
  const root = document.querySelector('[data-config-accordions]');
  if (!root) {
    return;
  }

  const panels = Array.from(root.querySelectorAll('[data-config-accordion]'));
  if (panels.length === 0) {
    return;
  }

  const openFromHash = () => {
    let hash = (location.hash || '').replace(/^#/, '');
    if (!hash) {
      panels.forEach((el) => {
        el.open = false;
      });
      return;
    }
    if (hash === 'plugins') {
      hash = 'components';
      if (location.hash !== '#components') {
        history.replaceState(null, '', '#components');
      }
    }
    const target = panels.find((el) => el.id === hash);
    panels.forEach((el) => {
      el.open = el === target;
    });
    if (target) {
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  };

  openFromHash();
  window.addEventListener('hashchange', openFromHash);

  panels.forEach((panel) => {
    panel.addEventListener('toggle', () => {
      if (!panel.open) {
        return;
      }
      panels.forEach((other) => {
        if (other !== panel) {
          other.open = false;
        }
      });
      if (panel.id && location.hash !== `#${panel.id}`) {
        history.replaceState(null, '', `#${panel.id}`);
      }
    });
  });
}

function initDashboardTabs() {
  const root = document.querySelector('[data-dashboard-tabs]');
  if (!root) {
    return;
  }
  const tabs = Array.from(root.querySelectorAll('[data-dashboard-tab]'));
  const panels = Array.from(root.querySelectorAll('[data-dashboard-panel]'));
  if (tabs.length === 0 || panels.length === 0) {
    return;
  }

  const validIds = new Set(tabs.map((tab) => tab.getAttribute('data-dashboard-tab')));
  const defaultTab = root.getAttribute('data-default-tab') || tabs[0].getAttribute('data-dashboard-tab');
  const tablist = root.querySelector('[data-dashboard-tablist]') || root.querySelector('.dashboard-tablist');
  const tablistWrap = root.querySelector('[data-dashboard-tablist-wrap]');
  const prevBtn = root.querySelector('[data-dashboard-tablist-prev]');
  const nextBtn = root.querySelector('[data-dashboard-tablist-next]');

  const syncTablistOverflow = () => {
    if (!tablist || !tablistWrap) {
      return;
    }
    const maxScroll = Math.max(0, tablist.scrollWidth - tablist.clientWidth);
    const canScroll = maxScroll > 4;
    const atStart = tablist.scrollLeft <= 2;
    const atEnd = tablist.scrollLeft >= maxScroll - 2;
    tablistWrap.classList.toggle('is-scrollable', canScroll);
    tablistWrap.classList.toggle('is-fade-start', canScroll && !atStart);
    tablistWrap.classList.toggle('is-fade-end', canScroll && !atEnd);
    if (prevBtn) {
      prevBtn.hidden = !(canScroll && !atStart);
    }
    if (nextBtn) {
      nextBtn.hidden = !(canScroll && !atEnd);
    }
  };

  const scrollTablistBy = (direction) => {
    if (!tablist) {
      return;
    }
    const amount = Math.max(120, Math.round(tablist.clientWidth * 0.55)) * direction;
    tablist.scrollBy({ left: amount, behavior: 'smooth' });
  };

  if (tablist) {
    tablist.addEventListener('scroll', () => {
      window.requestAnimationFrame(syncTablistOverflow);
    }, { passive: true });
  }
  prevBtn?.addEventListener('click', () => scrollTablistBy(-1));
  nextBtn?.addEventListener('click', () => scrollTablistBy(1));
  window.addEventListener('resize', () => {
    window.requestAnimationFrame(syncTablistOverflow);
  }, { passive: true });

  const activate = (id, { updateHash = true, animateEnter = true } = {}) => {
    if (!validIds.has(id)) {
      id = defaultTab;
    }
    tabs.forEach((tab) => {
      const selected = tab.getAttribute('data-dashboard-tab') === id;
      tab.setAttribute('aria-selected', selected ? 'true' : 'false');
      tab.tabIndex = selected ? 0 : -1;
      if (selected && typeof tab.scrollIntoView === 'function') {
        tab.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
      }
    });
    panels.forEach((panel) => {
      const match = panel.getAttribute('data-dashboard-panel') === id;
      panel.hidden = !match;
    });
    if (updateHash) {
      const nextHash = `#tab-${id}`;
      if (location.hash !== nextHash) {
        history.replaceState(null, '', nextHash);
      }
    }
    window.requestAnimationFrame(syncTablistOverflow);
    if (id === 'members') {
      window.requestAnimationFrame(() => initDashboardCharts());
    }
    if (id === 'treasury') {
      window.requestAnimationFrame(() => initTreasuryCharts());
    }
    if (animateEnter) {
      const activePanel = panels.find((panel) => !panel.hidden);
      if (activePanel) {
        window.requestAnimationFrame(() => enterScope(activePanel, { reset: true }));
      }
    }
  };

  const idFromHash = () => {
    const hash = (location.hash || '').replace(/^#/, '');
    if (hash.startsWith('tab-')) {
      return hash.slice(4);
    }
    return '';
  };

  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      activate(tab.getAttribute('data-dashboard-tab') || defaultTab);
    });
    tab.addEventListener('keydown', (event) => {
      const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
      if (!keys.includes(event.key)) {
        return;
      }
      event.preventDefault();
      const idx = tabs.indexOf(tab);
      let next = idx;
      if (event.key === 'ArrowRight') {
        next = (idx + 1) % tabs.length;
      } else if (event.key === 'ArrowLeft') {
        next = (idx - 1 + tabs.length) % tabs.length;
      } else if (event.key === 'Home') {
        next = 0;
      } else if (event.key === 'End') {
        next = tabs.length - 1;
      }
      tabs[next].focus();
      activate(tabs[next].getAttribute('data-dashboard-tab') || defaultTab);
    });
  });

  window.addEventListener('hashchange', () => {
    const fromHash = idFromHash();
    if (fromHash && validIds.has(fromHash)) {
      activate(fromHash, { updateHash: false });
    }
  });

  const initial = idFromHash();
  activate(initial && validIds.has(initial) ? initial : defaultTab, {
    updateHash: !initial,
    animateEnter: false,
  });
  window.requestAnimationFrame(syncTablistOverflow);
}

function initDashboardCharts() {
  const root = document.querySelector('[data-dashboard-charts]');
  if (!root || typeof Chart === 'undefined') {
    return;
  }
  const panel = root.closest('[data-dashboard-panel]');
  if (panel && panel.hidden) {
    return;
  }
  if (root.dataset.chartsReady === '1') {
    root.querySelectorAll('canvas').forEach((canvas) => {
      const chart = Chart.getChart(canvas);
      if (chart) {
        chart.resize();
      }
    });
    return;
  }

  let data;
  let i18n;
  try {
    data = JSON.parse(root.getAttribute('data-dashboard-charts') || '{}');
    i18n = JSON.parse(root.getAttribute('data-chart-i18n') || '{}');
  } catch (err) {
    return;
  }

  const brand = '#0D6E66';
  const brandDeep = '#084B46';
  const accent = '#B84A1B';
  const mist = '#D7E8E4';
  const ink = '#0B1F1C';
  const muted = 'rgba(11, 31, 28, 0.55)';
  const palette = [brand, accent, '#2A8F85', '#C56A3A', '#4A6B66', '#8B3A16', '#6FA8A1', '#E08A5C'];

  Chart.defaults.font.family = '"Manrope", "Avenir Next", "Segoe UI", sans-serif';
  Chart.defaults.color = muted;
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.boxWidth = 8;
  Chart.defaults.plugins.legend.labels.padding = 16;

  const collectionsEl = document.getElementById('chart-collections');
  if (collectionsEl) {
    new Chart(collectionsEl, {
      type: 'bar',
      data: {
        labels: data.collections?.labels || [],
        datasets: [
          {
            type: 'bar',
            label: i18n.collected || 'Collected',
            data: data.collections?.values || [],
            backgroundColor: 'rgba(13, 110, 102, 0.78)',
            hoverBackgroundColor: brandDeep,
            borderRadius: 8,
            borderSkipped: false,
            maxBarThickness: 28,
            yAxisID: 'y',
            order: 2,
          },
          {
            type: 'line',
            label: i18n.newMembers || 'New members',
            data: data.new_members?.values || [],
            borderColor: accent,
            backgroundColor: 'rgba(184, 74, 27, 0.12)',
            pointBackgroundColor: accent,
            pointBorderColor: '#fff',
            pointRadius: 4,
            pointHoverRadius: 6,
            tension: 0.35,
            fill: false,
            yAxisID: 'y1',
            order: 1,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'top', align: 'end' },
          tooltip: {
            backgroundColor: brandDeep,
            titleColor: '#F4FBF9',
            bodyColor: mist,
            padding: 12,
            cornerRadius: 10,
            callbacks: {
              label(ctx) {
                const label = ctx.dataset.label || '';
                const value = ctx.parsed.y ?? 0;
                if (ctx.dataset.yAxisID === 'y') {
                  return `${label}: ${formatMoney(value, i18n.currency || '€')}`;
                }
                return `${label}: ${value}`;
              },
            },
          },
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { maxRotation: 0, autoSkipPadding: 8 },
            border: { display: false },
          },
          y: {
            position: 'left',
            beginAtZero: true,
            grid: { color: 'rgba(13, 110, 102, 0.08)' },
            border: { display: false },
            ticks: {
              callback(value) {
                return formatMoney(value, i18n.currency || '€', true);
              },
            },
          },
          y1: {
            position: 'right',
            beginAtZero: true,
            grid: { drawOnChartArea: false },
            border: { display: false },
            ticks: { precision: 0 },
          },
        },
      },
    });
  }

  const typesEl = document.getElementById('chart-types');
  if (typesEl) {
    const values = data.by_type?.values || [];
    new Chart(typesEl, {
      type: 'doughnut',
      data: {
        labels: data.by_type?.labels || [],
        datasets: [{
          data: values,
          backgroundColor: values.map((_, i) => palette[i % palette.length]),
          borderColor: '#fff',
          borderWidth: 3,
          hoverOffset: 6,
        }],
      },
      options: donutOptions(),
    });
  }

  const standingEl = document.getElementById('chart-standing');
  if (standingEl) {
    new Chart(standingEl, {
      type: 'doughnut',
      data: {
        labels: data.payment_standing?.labels || [],
        datasets: [{
          data: data.payment_standing?.values || [],
          backgroundColor: [brand, accent],
          borderColor: '#fff',
          borderWidth: 3,
          hoverOffset: 6,
        }],
      },
      options: donutOptions(),
    });
  }

  root.dataset.chartsReady = '1';
}

function initTreasuryCharts() {
  const root = document.querySelector('[data-treasury-charts]');
  if (!root || typeof Chart === 'undefined') {
    return;
  }
  const panel = root.closest('[data-dashboard-panel]');
  if (panel && panel.hidden) {
    return;
  }
  if (root.dataset.chartsReady === '1') {
    root.querySelectorAll('canvas').forEach((canvas) => {
      const chart = Chart.getChart(canvas);
      if (chart) {
        chart.resize();
      }
    });
    return;
  }

  let data;
  let i18n;
  try {
    data = JSON.parse(root.getAttribute('data-treasury-charts') || '{}');
    i18n = JSON.parse(root.getAttribute('data-treasury-chart-i18n') || '{}');
  } catch (err) {
    return;
  }

  const brand = '#0D6E66';
  const accent = '#B84A1B';
  const palette = [brand, accent, '#2A8F85', '#C56A3A', '#4A6B66', '#8B3A16', '#6FA8A1', '#E08A5C'];
  const currency = i18n.currency || '€';

  const moneyTooltip = {
    backgroundColor: '#084B46',
    titleColor: '#F4FBF9',
    bodyColor: '#D7E8E4',
    padding: 12,
    cornerRadius: 10,
    callbacks: {
      label(ctx) {
        const label = ctx.label || '';
        const value = ctx.parsed || 0;
        return `${label}: ${formatMoney(value, currency)}`;
      },
    },
  };

  const flowEl = document.getElementById('chart-treasury-flow');
  if (flowEl) {
    const values = data.flow?.values || [];
    new Chart(flowEl, {
      type: 'doughnut',
      data: {
        labels: data.flow?.labels || [i18n.income || 'Income', i18n.expense || 'Expense'],
        datasets: [{
          data: values,
          backgroundColor: [brand, accent],
          borderColor: '#fff',
          borderWidth: 3,
          hoverOffset: 6,
        }],
      },
      options: {
        ...donutOptions(),
        plugins: {
          ...donutOptions().plugins,
          tooltip: moneyTooltip,
        },
      },
    });
  }

  const expenseEl = document.getElementById('chart-treasury-expense');
  if (expenseEl) {
    const values = data.expense_by_category?.values || [];
    new Chart(expenseEl, {
      type: 'doughnut',
      data: {
        labels: data.expense_by_category?.labels || [],
        datasets: [{
          data: values,
          backgroundColor: values.map((_, i) => palette[i % palette.length]),
          borderColor: '#fff',
          borderWidth: 3,
          hoverOffset: 6,
        }],
      },
      options: {
        ...donutOptions(),
        plugins: {
          ...donutOptions().plugins,
          tooltip: moneyTooltip,
        },
      },
    });
  }

  const incomeEl = document.getElementById('chart-treasury-income');
  if (incomeEl) {
    const values = data.income_by_category?.values || [];
    new Chart(incomeEl, {
      type: 'doughnut',
      data: {
        labels: data.income_by_category?.labels || [],
        datasets: [{
          data: values,
          backgroundColor: values.map((_, i) => palette[(i + 2) % palette.length]),
          borderColor: '#fff',
          borderWidth: 3,
          hoverOffset: 6,
        }],
      },
      options: {
        ...donutOptions(),
        plugins: {
          ...donutOptions().plugins,
          tooltip: moneyTooltip,
        },
      },
    });
  }

  root.dataset.chartsReady = '1';
}

function donutOptions() {
  return {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '62%',
    plugins: {
      legend: { position: 'bottom' },
      tooltip: {
        backgroundColor: '#084B46',
        titleColor: '#F4FBF9',
        bodyColor: '#D7E8E4',
        padding: 12,
        cornerRadius: 10,
      },
    },
  };
}

function formatMoney(value, currency, compact = false) {
  const n = Number(value) || 0;
  if (compact && Math.abs(n) >= 1000) {
    return `${currency}${Math.round(n / 100) / 10}k`;
  }
  return `${currency}${n.toLocaleString(undefined, {
    minimumFractionDigits: compact ? 0 : 2,
    maximumFractionDigits: compact ? 0 : 2,
  })}`;
}

function syncFieldOrderInputs(scope = document) {
  const editor = scope.closest?.('[data-fields-editor]') || scope.querySelector?.('[data-fields-editor]') || scope;
  const root = editor?.matches?.('[data-fields-editor]') ? editor : document;
  root.querySelectorAll('[data-fields-step-body]').forEach((tbody) => {
    const step = tbody.closest('[data-fields-step]');
    const stepKey = step?.dataset.stepKey || '';
    tbody.querySelectorAll('tr[data-field-key]').forEach((row) => {
      const key = row.dataset.fieldKey || '';
      const orderInput = row.querySelector('input[name="field_order[]"]');
      const stepInput = row.querySelector('[data-field-step-input]');
      if (orderInput && key) orderInput.value = key;
      if (stepInput && stepKey) {
        stepInput.value = stepKey;
        row.dataset.fieldStep = stepKey;
      }
    });
    const empty = tbody.querySelector('[data-fields-empty-row]');
    const hasFields = tbody.querySelector('tr[data-field-key]');
    if (empty) empty.hidden = !!hasFields;
    if (!hasFields && !tbody.querySelector('.setup-fields-system-row') && !empty) {
      const tr = document.createElement('tr');
      tr.className = 'setup-fields-empty-row';
      tr.setAttribute('data-fields-empty-row', '');
      const td = document.createElement('td');
      td.colSpan = root.dataset.fieldsSetupMode === '1' ? 4 : 5;
      td.className = 'muted';
      td.textContent = root.dataset.stepEmpty || '';
      tr.appendChild(td);
      tbody.appendChild(tr);
    }
  });
}

function reindexFieldSteps(editor) {
  const steps = [...editor.querySelectorAll('[data-fields-step]')];
  const customSteps = steps.filter((step) => !step.hasAttribute('data-fields-step-system'));
  steps.forEach((step, index) => {
    const badge = step.querySelector('[data-step-index]');
    if (badge) badge.textContent = String(index + 1);
    const removeBtn = step.querySelector('[data-fields-step-remove]');
    if (removeBtn) {
      removeBtn.hidden = customSteps.length <= 1 || step.hasAttribute('data-fields-step-system');
    }
  });
}

function bindFieldStepTitleSync(step) {
  const it = step.querySelector('[data-step-title="it"]');
  const display = step.querySelector('[data-step-display-name]');
  if (!it || !display || it.dataset.titleSyncBound === '1') return;
  it.dataset.titleSyncBound = '1';
  const sync = () => {
    display.textContent = it.value.trim() || step.dataset.stepKey || '';
  };
  it.addEventListener('input', sync);
  sync();
}

function setFieldsAutosaveStatus(editor, state, message) {
  const el = editor.querySelector('[data-fields-autosave-status]');
  if (!el) return;
  el.textContent = message || '';
  el.dataset.state = state || '';
  el.classList.toggle('is-busy', state === 'busy');
  el.classList.toggle('is-ok', state === 'ok');
  el.classList.toggle('is-error', state === 'error');
}

function queueFieldsAutosave(editor, { immediate = false } = {}) {
  const url = (editor.dataset.autosaveUrl || '').trim();
  if (!url) return;
  const form = editor.closest('form');
  if (!form) return;

  const run = async () => {
    syncFieldOrderInputs(editor);
    if (editor._fieldsAutosaveAbort) {
      try {
        editor._fieldsAutosaveAbort.abort();
      } catch (_) {}
    }
    const controller = new AbortController();
    editor._fieldsAutosaveAbort = controller;
    editor._fieldsAutosaveSeq = (editor._fieldsAutosaveSeq || 0) + 1;
    const seq = editor._fieldsAutosaveSeq;

    setFieldsAutosaveStatus(editor, 'busy', editor.dataset.autosaveBusy || '');

    const fd = new FormData(form);
    ['new_label', 'new_key', 'new_type', 'new_step', 'new_enabled', 'new_required'].forEach((name) => {
      fd.delete(name);
    });
    if (!fd.get('_token') && editor.dataset.csrf) {
      fd.set('_token', editor.dataset.csrf);
    }

    try {
      const res = await fetch(url, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        signal: controller.signal,
      });
      const data = await res.json().catch(() => ({}));
      if (seq !== editor._fieldsAutosaveSeq) return;
      if (res.ok && data.ok) {
        setFieldsAutosaveStatus(editor, 'ok', data.message || editor.dataset.autosaveOk || '');
      } else {
        setFieldsAutosaveStatus(
          editor,
          'error',
          data.message || editor.dataset.autosaveFail || ''
        );
      }
    } catch (err) {
      if (err?.name === 'AbortError') return;
      if (seq !== editor._fieldsAutosaveSeq) return;
      setFieldsAutosaveStatus(editor, 'error', editor.dataset.autosaveFail || '');
    }
  };

  clearTimeout(editor._fieldsAutosaveTimer);
  if (immediate) {
    run();
    return;
  }
  editor._fieldsAutosaveTimer = setTimeout(run, 550);
}

function initFieldsSortable(scope = document) {
  scope.querySelectorAll('[data-fields-editor]').forEach((editor) => {
    if (editor.dataset.fieldsEditorBound === '1') return;
    editor.dataset.fieldsEditorBound = '1';

    editor.querySelectorAll('[data-fields-step]').forEach(bindFieldStepTitleSync);
    reindexFieldSteps(editor);

    let dragRow = null;

    editor.addEventListener('pointerdown', (event) => {
      const handle = event.target.closest('[data-field-drag-handle]');
      if (!handle || !editor.contains(handle)) return;
      const row = handle.closest('tr[data-field-key]');
      if (row && !row.hasAttribute('data-field-locked')) {
        row.draggable = true;
      }
    });

    editor.addEventListener('click', (event) => {
      if (event.target.closest('[data-field-drag-handle]')) {
        event.preventDefault();
      }
    });

    editor.addEventListener('dragstart', (event) => {
      const row = event.target.closest('tr[data-field-key]');
      if (!row || !editor.contains(row) || !row.draggable) {
        event.preventDefault();
        return;
      }
      dragRow = row;
      row.classList.add('is-dragging');
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', row.dataset.fieldKey || '');
      }
    });

    editor.addEventListener('dragend', () => {
      if (dragRow) {
        dragRow.classList.remove('is-dragging');
        dragRow.draggable = false;
      }
      editor.querySelectorAll('tr.is-drag-over, [data-fields-step].is-drag-over').forEach((el) => {
        el.classList.remove('is-drag-over');
      });
      dragRow = null;
      syncFieldOrderInputs(editor);
      queueFieldsAutosave(editor, { immediate: true });
    });

    editor.addEventListener('dragover', (event) => {
      if (!dragRow) return;
      event.preventDefault();
      if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';

      const step = event.target.closest('[data-fields-step]');
      const tbody = step?.querySelector('[data-fields-step-body]');
      if (!tbody) return;

      editor.querySelectorAll('[data-fields-step].is-drag-over').forEach((el) => {
        if (el !== step) el.classList.remove('is-drag-over');
      });
      step.classList.add('is-drag-over');

      const target = event.target.closest('tr[data-field-key], tr.setup-fields-system-row');
      tbody.querySelectorAll('tr.is-drag-over').forEach((row) => {
        if (row !== target) row.classList.remove('is-drag-over');
      });

      const empty = tbody.querySelector('[data-fields-empty-row]');
      if (empty) empty.remove();

      const lastSystem = [...tbody.querySelectorAll('.setup-fields-system-row')].pop() || null;
      if (target && target.classList.contains('setup-fields-system-row')) {
        tbody.insertBefore(dragRow, lastSystem ? lastSystem.nextSibling : tbody.firstChild);
      } else if (target && target !== dragRow && tbody.contains(target) && target.hasAttribute('data-field-key')) {
        target.classList.add('is-drag-over');
        const rect = target.getBoundingClientRect();
        const before = event.clientY < rect.top + rect.height / 2;
        tbody.insertBefore(dragRow, before ? target : target.nextSibling);
      } else if (!tbody.contains(dragRow)) {
        tbody.appendChild(dragRow);
      }
    });

    editor.addEventListener('drop', (event) => {
      if (!dragRow) return;
      event.preventDefault();
      editor.querySelectorAll('tr.is-drag-over, [data-fields-step].is-drag-over').forEach((el) => {
        el.classList.remove('is-drag-over');
      });
      syncFieldOrderInputs(editor);
      queueFieldsAutosave(editor, { immediate: true });
    });

    editor.addEventListener('change', (event) => {
      const t = event.target;
      if (!(t instanceof HTMLElement) || !editor.contains(t)) return;
      if (t instanceof HTMLInputElement && t.type === 'checkbox') {
        const row = t.closest('tr[data-field-key]');
        const enabled = row?.querySelector('input[name="fields[]"]');
        const required = row?.querySelector('input[name="required[]"]');
        if (enabled && required) {
          if (t === required && required.checked) enabled.checked = true;
          if (t === enabled && !enabled.checked) required.checked = false;
          required.disabled = !enabled.checked;
        }
      }
      if (t.matches('input[type="checkbox"], select[name^="field_types"]')) {
        queueFieldsAutosave(editor, { immediate: true });
      }
    });

    editor.querySelectorAll('tr[data-field-key]').forEach((row) => {
      const enabled = row.querySelector('input[name="fields[]"]');
      const required = row.querySelector('input[name="required[]"]');
      if (enabled && required) required.disabled = !enabled.checked;
    });

    editor.addEventListener('input', (event) => {
      const t = event.target;
      if (!(t instanceof HTMLElement) || !editor.contains(t)) return;
      if (t.matches('[data-step-title]')) {
        queueFieldsAutosave(editor);
      }
    });

    editor.querySelector('[data-fields-step-add]')?.addEventListener('click', () => {
      const tpl = editor.querySelector('[data-fields-step-template]');
      const host = editor.querySelector('[data-fields-steps]');
      if (!tpl || !host) return;
      const key = `${editor.dataset.stepPrefix || 'step_'}${Date.now().toString(36)}`;
      const html = tpl.innerHTML.replaceAll('__KEY__', key);
      const wrap = document.createElement('div');
      wrap.innerHTML = html.trim();
      const section = wrap.firstElementChild;
      if (!section) return;
      section.dataset.stepKey = key;
      const defaultName = editor.dataset.addStepLabel || 'Step';
      const it = section.querySelector('[data-step-title="it"]');
      if (it) it.value = defaultName;
      const firstSystem = host.querySelector('[data-fields-step-system]');
      const actions = host.querySelector('.setup-fields-step-actions');
      if (firstSystem) {
        host.insertBefore(section, firstSystem);
      } else if (actions) {
        host.insertBefore(section, actions);
      } else {
        host.appendChild(section);
      }
      // Keep "add step" button just before system steps.
      if (actions && firstSystem) {
        host.insertBefore(actions, firstSystem);
      }
      bindFieldStepTitleSync(section);
      reindexFieldSteps(editor);
      it?.focus();
      queueFieldsAutosave(editor, { immediate: true });
    });

    editor.addEventListener('click', (event) => {
      const btn = event.target.closest('[data-fields-step-remove]');
      if (!btn || !editor.contains(btn)) return;
      const step = btn.closest('[data-fields-step]');
      if (!step || step.hasAttribute('data-fields-step-system')) return;
      const customSteps = [...editor.querySelectorAll('[data-fields-step]:not([data-fields-step-system])')];
      if (customSteps.length <= 1) return;
      const fallback = customSteps.find((s) => s !== step);
      const body = fallback?.querySelector('[data-fields-step-body]');
      if (body) {
        step.querySelectorAll('tr[data-field-key]').forEach((row) => body.appendChild(row));
        body.querySelector('[data-fields-empty-row]')?.remove();
      }
      step.remove();
      reindexFieldSteps(editor);
      syncFieldOrderInputs(editor);
      queueFieldsAutosave(editor, { immediate: true });
    });
  });

  scope.querySelectorAll('form').forEach((form) => {
    const enabled = form.querySelector('[data-new-field-enabled]');
    const required = form.querySelector('[data-new-field-required]');
    if (!enabled || !required || enabled.dataset.requiredSyncBound === '1') return;
    enabled.dataset.requiredSyncBound = '1';
    const sync = () => {
      if (!enabled.checked) required.checked = false;
      required.disabled = !enabled.checked;
    };
    enabled.addEventListener('change', sync);
    required.addEventListener('change', () => {
      if (required.checked) enabled.checked = true;
      sync();
    });
    sync();
  });

  // Legacy single-table sortable (if any remain outside editor)
  scope.querySelectorAll('[data-fields-sortable]').forEach((table) => {
    if (table.closest('[data-fields-editor]') || table.dataset.fieldsSortableBound === '1') return;
    table.dataset.fieldsSortableBound = '1';
    const tbody = table.tBodies[0];
    if (!tbody) return;
    let dragRow = null;
    tbody.querySelectorAll('[data-field-drag-handle]').forEach((handle) => {
      handle.addEventListener('pointerdown', () => {
        const row = handle.closest('tr');
        if (row) row.draggable = true;
      });
    });
    tbody.addEventListener('dragstart', (event) => {
      const row = event.target.closest('tr');
      if (!row || !row.draggable) {
        event.preventDefault();
        return;
      }
      dragRow = row;
      row.classList.add('is-dragging');
    });
    tbody.addEventListener('dragend', () => {
      if (dragRow) {
        dragRow.classList.remove('is-dragging');
        dragRow.draggable = false;
      }
      dragRow = null;
      syncFieldOrderInputs(tbody);
    });
    tbody.addEventListener('dragover', (event) => {
      if (!dragRow) return;
      event.preventDefault();
      const target = event.target.closest('tr');
      if (!target || target === dragRow) return;
      const rect = target.getBoundingClientRect();
      const before = event.clientY < rect.top + rect.height / 2;
      tbody.insertBefore(dragRow, before ? target : target.nextSibling);
    });
  });
}
