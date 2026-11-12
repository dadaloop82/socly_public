/**
 * Toolbar helper for the standalone printable sheets (balance sheet, Model D, member registry,
 * enrolment form, org chart/PDF export).
 *
 * The Content-Security-Policy allows scripts from 'self' only, so the old
 * `onclick="window.print()"` handlers were blocked by the browser and the “Print / Save as PDF”
 * button did nothing. Buttons now declare `data-print` (or `data-close-window`) and this file
 * binds them with event delegation.
 */
(() => {
  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-print], [data-close-window]') : null;
    if (!target) {
      return;
    }
    if (target.hasAttribute('data-print')) {
      event.preventDefault();
      window.print();
      return;
    }
    event.preventDefault();
    // Works only for windows opened by script; a regular tab simply stays open.
    window.close();
  });
})();
