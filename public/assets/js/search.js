(() => {
  const input = document.getElementById('globalSearch');
  const dropdown = document.getElementById('globalSearchResults');
  if (!input || !dropdown) return;
  const area = document.getElementById('globalSearchArea');
  const toggle = document.getElementById('globalSearchToggle');
  const appBase = location.pathname.includes('/reports/')
    ? location.pathname.split('/reports/')[0]
    : location.pathname.replace(/\/[^/]*$/, '');
  const labels = { division: 'বিভাগ', district: 'জেলা', upazila: 'উপজেলা / থানা', police_station: 'থানা' };
  let timer;
  let requestId = 0;
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

  /* On mobile the input is shown as an overlay only while `search-open` is set,
     so the state has to be collapsed again as soon as the user is done with it. */
  const isOpen = () => Boolean(area && area.classList.contains('search-open'));
  const hideResults = () => dropdown.classList.add('hidden');
  const setOpen = (open) => {
    if (!area) return;
    area.classList.toggle('search-open', open);
    if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  };
  const openSearch = () => {
    setOpen(true);
    input.focus();
  };
  const closeSearch = () => {
    const wasOpen = isOpen();
    clearTimeout(timer);
    requestId += 1; // ignore responses that are still in flight
    setOpen(false);
    hideResults();
    if (!wasOpen) return; // wide screens keep the input (and its text) visible
    input.value = '';
    dropdown.innerHTML = '';
    input.blur();
  };

  if (toggle) {
    toggle.setAttribute('aria-expanded', 'false');
    toggle.addEventListener('click', () => {
      if (isOpen()) closeSearch();
      else openSearch();
    });
  }

  input.addEventListener('input', () => {
    clearTimeout(timer);
    const q = input.value.trim();
    if (!q) { hideResults(); return; }
    const id = (requestId += 1);
    timer = setTimeout(async () => {
      try {
        const response = await fetch(`${appBase}/api/search.php?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' }, cache: 'no-store' });
        const result = await response.json();
        if (id !== requestId) return;
        render(response.ok && result.ok ? result.results : []);
      } catch {
        if (id === requestId) render([]);
      }
    }, 180);
  });

  /* Clicking/tapping anywhere outside the search area closes the overlay.
     On desktop only the result list is hidden so typed text is kept. */
  document.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('#globalSearchArea')) return;
    closeSearch();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (!isOpen() && dropdown.classList.contains('hidden')) return;
    closeSearch();
    input.blur();
  });

  /* Tapping elsewhere on a touch device usually only blurs the input, so close
     the overlay when the field is left empty (results stay while typing). */
  area?.addEventListener('focusout', (event) => {
    const next = event.relatedTarget;
    if (next instanceof Node && area.contains(next)) return;
    if (input.value.trim()) return;
    closeSearch();
  });

  /* Scrolling the page while the empty overlay is open closes it (the on-screen
     keyboard itself must not trigger this, hence the activeElement check). */
  window.addEventListener(
    'scroll',
    () => {
      if (!isOpen() || document.activeElement === input) return;
      if (input.value.trim()) return;
      closeSearch();
    },
    { passive: true }
  );

  dropdown.addEventListener('click', (event) => {
    if (!(event.target instanceof Element) || !event.target.closest('a')) return;
    /* Keep the result markup (and the tapped link) in place while the browser
       follows it, otherwise the navigation would be cancelled. */
    setOpen(false);
    hideResults();
    input.value = '';
    input.blur();
  });
})();

