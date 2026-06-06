// === Scroll-Driven Animations (taste-skill) ===
// Uses IntersectionObserver for better performance than scroll listeners

document.addEventListener('DOMContentLoaded', () => {
  // Configuration
  const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -50px 0px'
  };

  // Create observer
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        // Optionally unobserve after animation (performance)
        // observer.unobserve(entry.target);
      }
    });
  }, observerOptions);

  // Observe elements with animation classes
  const animatedElements = document.querySelectorAll(
    '.animate-on-scroll, .stagger-item, .scale-in'
  );

  animatedElements.forEach(el => observer.observe(el));

  // Auto-apply to common elements if not already marked
  const autoAnimateSelectors = [
    '.card',
    '.stat-card',
    '.page-header'
  ];

  autoAnimateSelectors.forEach(selector => {
    document.querySelectorAll(selector).forEach(el => {
      if (!el.classList.contains('animate-on-scroll') &&
          !el.classList.contains('stagger-item') &&
          !el.classList.contains('scale-in')) {
        el.classList.add('animate-on-scroll');
        observer.observe(el);
      }
    });
  });

  // Stagger items in grids
  const grids = document.querySelectorAll('.stats-grid, .po-grid, [class*="grid"]');
  grids.forEach(grid => {
    const items = grid.children;
    Array.from(items).forEach((item, index) => {
      if (!item.classList.contains('stagger-item')) {
        item.classList.add('stagger-item');
        observer.observe(item);
      }
    });
  });
});
