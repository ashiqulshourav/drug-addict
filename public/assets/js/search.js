(() => {
  const input = document.getElementById('globalSearch');
  const dropdown = document.getElementById('globalSearchResults');
  if (!input || !dropdown) return;
  const appBase = location.pathname.includes('/reports/')
    ? location.pathname.split('/reports/')[0]
    : location.pathname.replace(/\/[^/]*$/, '');
  const labels = { division: 'বিভাগ', district: 'জেলা', upazila: 'উপজেলা / থানা', police_station: 'থানা' };
  let timer;
  const toggle = document.getElementById('globalSearchToggle');
  if (toggle) {
    toggle.addEventListener('click', () => {
      const area = document.getElementById('globalSearchArea');
      area.classList.add('search-open');
      input.focus();
    });
  }
  const escapeHtml = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
  const resultHref = (item) => {
    if (item.type === 'police_station' && item.district_slug) {
      return `${appBase}/location.html?type=district&slug=${encodeURIComponent(item.district_slug)}`;
    }
    if (item.type === 'police_station') return '#';
    return `${appBase}/location.html?type=${encodeURIComponent(item.type)}&slug=${encodeURIComponent(item.slug)}`;
  };
  const render = (results) => {
    dropdown.innerHTML = results.length ? results.map((item) => `<a class="block border-b border-slate-100 px-3 py-2 text-left last:border-0 hover:bg-slate-50" href="${resultHref(item)}"><span class="block text-xs font-bold text-[#951d1f]">${labels[item.type] || item.type}</span><span class="text-sm font-semibold text-slate-700">${escapeHtml(item.label)}</span>${item.secondary !== item.label ? `<span class="ml-2 text-xs text-slate-400">${escapeHtml(item.secondary)}</span>` : ''}</a>`).join('') : '<p class="px-3 py-3 text-xs text-slate-500">কোনো ফলাফল পাওয়া যায়নি</p>';
    dropdown.classList.remove('hidden');
  };
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const q = input.value.trim();
    if (!q) { dropdown.classList.add('hidden'); return; }
    timer = setTimeout(async () => {
      try {
        const response = await fetch(`${appBase}/api/search.php?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' }, cache: 'no-store' });
        const result = await response.json();
        render(response.ok && result.ok ? result.results : []);
      } catch { render([]); }
    }, 180);
  });
  document.addEventListener('click', (event) => {
    if (!event.target.closest('#globalSearch, #globalSearchResults')) dropdown.classList.add('hidden');
  });
})();
