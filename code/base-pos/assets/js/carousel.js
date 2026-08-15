/* Carousel — scroll-snap slides for long pages (financial-summary, reports)
 * - Auto-generates prev/next buttons + dot navigation
 * - Keyboard: ← → when carousel or a descendant has focus
 * - Touch/scroll-snap native on the track
 * - Print: all slides expanded (override in CSS)
 * Usage: <div class="carousel"><div class="carousel-track">
 *          <div class="carousel-slide">…</div> …
 *        </div></div>
 */
(function () {
  'use strict';

  function initCarousel(root) {
    const track = root.querySelector('.carousel-track');
    if (!track) return;

    // Build nav chrome
    const prevBtn = document.createElement('button');
    prevBtn.type = 'button';
    prevBtn.className = 'carousel-btn carousel-prev';
    prevBtn.setAttribute('aria-label', 'ก่อนหน้า');
    prevBtn.innerHTML = '<i class="icon-back"></i>';

    const nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'carousel-btn carousel-next';
    nextBtn.setAttribute('aria-label', 'ถัดไป');
    nextBtn.innerHTML = '<i class="icon-forward"></i>';

    const dotsWrap = document.createElement('div');
    dotsWrap.className = 'carousel-dots';
    dotsWrap.setAttribute('role', 'tablist');

    root.insertBefore(prevBtn, track);
    root.insertBefore(nextBtn, track);
    root.appendChild(dotsWrap);

    const slides = Array.prototype.slice.call(track.children);
    const dots = slides.map(function (slide, i) {
      const d = document.createElement('button');
      d.type = 'button';
      d.className = 'carousel-dot' + (i === 0 ? ' active' : '');
      d.setAttribute('role', 'tab');
      d.setAttribute('aria-label', 'สไลด์ ' + (i + 1));
      d.addEventListener('click', function () {
        track.scrollTo({ left: i * track.clientWidth, behavior: 'smooth' });
      });
      dotsWrap.appendChild(d);
      return d;
    });

    function currentIndex() {
      if (!track.clientWidth) return 0;
      return Math.round(track.scrollLeft / track.clientWidth);
    }

    function updateDots() {
      const idx = currentIndex();
      dots.forEach(function (d, i) {
        d.classList.toggle('active', i === idx);
      });
      prevBtn.disabled = idx <= 0;
      nextBtn.disabled = idx >= slides.length - 1;
    }

    function step(dir) {
      const idx = Math.min(slides.length - 1, Math.max(0, currentIndex() + dir));
      track.scrollTo({ left: idx * track.clientWidth, behavior: 'smooth' });
    }

    prevBtn.addEventListener('click', function () { step(-1); });
    nextBtn.addEventListener('click', function () { step(1); });

    let ticking = false;
    track.addEventListener('scroll', function () {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(function () {
        updateDots();
        ticking = false;
      });
    }, { passive: true });

    window.addEventListener('resize', updateDots);
    updateDots();

    root.tabIndex = -1;
    root.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { step(-1); e.preventDefault(); }
      if (e.key === 'ArrowRight') { step(1); e.preventDefault(); }
    });

    // Keep Chart.js canvases sized after hidden-slide layout settles
    if (window.Chart) {
      window.setTimeout(function () {
        window.dispatchEvent(new Event('resize'));
      }, 300);
    }
  }

  function initAll() {
    document.querySelectorAll('.carousel').forEach(initCarousel);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
