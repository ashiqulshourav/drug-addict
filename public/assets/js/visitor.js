(() => {
  const path = window.location.pathname;
  const appBase = path.includes('/reports/')
    ? path.split('/reports/')[0]
    : path.replace(/\/[^/]*$/, '') || '';

  const badge = document.createElement('aside');
  badge.id = 'siteVisitorBadge';
  badge.setAttribute('aria-label', 'সাইট ভিজিটর সংখ্যা');
  badge.className = 'fixed bottom-4 left-4 z-[1900] hidden items-center gap-2 rounded-full border border-slate-200/90 bg-white/95 px-3 py-1.5 text-xs text-slate-700 shadow-lg backdrop-blur-md transition-all duration-300 hover:shadow-xl sm:flex';
  badge.innerHTML = `
    <span class="relative flex h-2 w-2">
      <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
      <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
    </span>
    <span class="font-medium text-slate-500">মোট ভিজিটর:</span>
    <strong id="siteTotalVisitors" class="font-bold text-slate-800 tracking-tight">--</strong>
    <span class="text-slate-300">|</span>
    <span class="text-slate-500">আজকে:</span>
    <span id="siteTodayVisitors" class="font-semibold text-slate-700">--</span>
  `;

  async function trackVisitor() {
    try {
      const response = await fetch(`${appBase}/api/visitor.php?path=${encodeURIComponent(window.location.pathname)}`, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });
      if (!response.ok) return;
      const data = await response.json();
      if (!data || !data.ok) return;

      const totalEl = document.getElementById('siteTotalVisitors');
      const todayEl = document.getElementById('siteTodayVisitors');
      if (totalEl) totalEl.textContent = Number(data.totalVisits || 0).toLocaleString('en-US');
      if (todayEl) todayEl.textContent = Number(data.todayVisits || 0).toLocaleString('en-US');
      badge.classList.remove('hidden');
      badge.classList.add('flex');
    } catch (_) {
      // Non-blocking visitor stats failure
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      document.body.appendChild(badge);
      trackVisitor();
    });
  } else {
    document.body.appendChild(badge);
    trackVisitor();
  }
})();
