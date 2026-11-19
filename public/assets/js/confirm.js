/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */

/*
 * In-app confirmation dialog shared by the whole product: admin, guest and add-on pages.
 *
 * `app.js` used to carry it, but the public add-on layouts (board voting, forum) do not load
 * app.js: the same popup now lives here and every layout includes this file first. Never use
 * `window.confirm()` in new code — it ignores the brand styling and the “do not ask again for
 * the next 30 minutes” option.
 */

/** Styled in-app confirm — never uses the browser native dialog. */
let appConfirmDialog = null;

/** “Do not ask again” window shared by the in-app confirmations. */
const APP_CONFIRM_SNOOZE_MS = 30 * 60 * 1000;
const APP_CONFIRM_SNOOZE_STORAGE = 'socly.confirmSnoozeUntil';

function appConfirmSnoozeUntil() {
  try {
    const raw = window.localStorage.getItem(APP_CONFIRM_SNOOZE_STORAGE);
    const until = Number(raw || 0);
    if (!Number.isFinite(until) || until <= Date.now()) {
      if (raw !== null) window.localStorage.removeItem(APP_CONFIRM_SNOOZE_STORAGE);
      return 0;
    }
    return until;
  } catch (error) {
    return 0;
  }
}

function snoozeAppConfirm() {
  try {
    window.localStorage.setItem(APP_CONFIRM_SNOOZE_STORAGE, String(Date.now() + APP_CONFIRM_SNOOZE_MS));
  } catch (error) {
    /* Private mode / storage disabled: the dialog simply keeps asking. */
  }
}

function appConfirmLabels() {
  const lang = (document.documentElement.lang || 'it').slice(0, 2);
  const map = {
    it: { confirm: 'Conferma', cancel: 'Annulla', snooze: 'Non chiedere più per i prossimi 30 minuti' },
    en: { confirm: 'Confirm', cancel: 'Cancel', snooze: 'Don’t ask again for the next 30 minutes' },
    de: { confirm: 'Bestätigen', cancel: 'Abbrechen', snooze: 'Nicht mehr für die nächsten 30 Minuten fragen' },
  };
  const labels = { ...(map[lang] || map.it) };
  const meta = document.querySelector('meta[name="confirm-snooze-label"]');
  const metaLabel = meta?.getAttribute('content')?.trim();
  if (metaLabel) labels.snooze = metaLabel;
  return labels;
}

function ensureAppConfirmDialog() {
  if (appConfirmDialog) return appConfirmDialog;
  appConfirmDialog = document.createElement('dialog');
  appConfirmDialog.className = 'setup-exit-dialog';
  appConfirmDialog.dataset.appConfirm = '1';
  appConfirmDialog.innerHTML = `
    <div class="setup-exit-shell">
      <p class="setup-exit-text" data-app-confirm-text></p>
      <label class="setup-exit-snooze" data-app-confirm-snooze-wrap hidden>
        <input type="checkbox" data-app-confirm-snooze>
        <span data-app-confirm-snooze-label></span>
      </label>
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
  const snoozeWrap = dialog.querySelector('[data-app-confirm-snooze-wrap]');
  const snoozeInput = dialog.querySelector('[data-app-confirm-snooze]');
  const snoozeLabel = dialog.querySelector('[data-app-confirm-snooze-label]');
  const confirmLabel = options.confirmLabel || labels.confirm;
  const cancelLabel = options.cancelLabel || labels.cancel;
  const danger = !!options.danger;
  // Alerts only acknowledge something, they are not questions: never snoozed.
  // Callers can opt out (e.g. “leave this page with unsaved changes?”).
  const snoozeEnabled = !options.alert && options.snooze !== false;

  if (snoozeEnabled && appConfirmSnoozeUntil() > Date.now()) {
    return Promise.resolve(true);
  }

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
  if (snoozeInput instanceof HTMLInputElement) snoozeInput.checked = false;
  if (snoozeLabel) snoozeLabel.textContent = labels.snooze || '';
  if (snoozeWrap instanceof HTMLElement) snoozeWrap.hidden = !snoozeEnabled;

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
    const onOk = () => {
      if (snoozeEnabled && snoozeInput instanceof HTMLInputElement && snoozeInput.checked) {
        snoozeAppConfirm();
      }
      finish(true);
    };
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
