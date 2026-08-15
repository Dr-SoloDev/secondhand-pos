/* Tabs — section navigation for long pages (reports, financial-summary)
 * Usage:
 *   <div class="tabs">
 *     <div class="tab-nav" role="tablist">
 *       <button class="tab-btn active" type="button">Tab 1</button>
 *       ...
 *     </div>
 *     <div class="tab-panel active">…</div>
 *     ...
 *   </div>
 * - First tab active on load; window resize fired on switch so Chart.js
 *   canvases in the newly-visible panel size correctly.
 * - Print: all panels expanded via CSS (see layout.css).
 */
(function () {
  'use strict';

  function initTabs(root) {
    const btns = Array.prototype.slice.call(root.querySelectorAll('.tab-btn'));
    const panels = Array.prototype.slice.call(root.querySelectorAll('.tab-panel'));
    if (!btns.length || !panels.length) return;

    function show(i) {
      btns.forEach(function (b, k) {
        b.classList.toggle('active', k === i);
        b.setAttribute('aria-selected', k === i ? 'true' : 'false');
      });
      panels.forEach(function (p, k) {
        p.classList.toggle('active', k === i);
      });
      // Let Chart.js re-measure canvases now that the panel is visible
      window.dispatchEvent(new Event('resize'));
    }

    btns.forEach(function (b, i) {
      b.addEventListener('click', function () { show(i); });
    });
    show(0);
  }

  function initAll() {
    document.querySelectorAll('.tabs').forEach(initTabs);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
