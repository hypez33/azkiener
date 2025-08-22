/* Autozentrum Kiener – Frontend Core
 * - Fahrzeuge laden & rendern (mit Bild-Proxy /img.php)
 * - Filter/Suche/Sortierung + "Mehr laden"
 * - UI: Reveal, Dark-Mode, Mobile-Menü, Back-to-Top, Cookie-Banner
 */

(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  // ---------------------------------------------
  // UTIL
  // ---------------------------------------------
  const fmt = new Intl.NumberFormat('de-DE');
  const clamp = (n, min, max) => Math.max(min, Math.min(max, n));

  const mapFuel = (val) => {
    const t = String(val || '').toUpperCase();
    if (t.includes('PETROL') || t === 'BENZIN') return 'Benziner';
    if (t.includes('DIESEL')) return 'Diesel';
    if (t.includes('ELECTRIC')) return 'Elektrisch';
    if (t.includes('HYBRID')) return 'Hybrid';
    if (t.includes('CNG')) return 'CNG';
    if (t.includes('LPG')) return 'LPG';
    return val || '';
  };
  const mapGearFromSpecs = (specs) => {
    const s = String(specs || '');
    if (s.includes('Automatic_gear')) return 'Automatik';
    if (s.includes('Manual_gear')) return 'Manuell';
    return '';
  };

  const buildImgSrc = (urlOrProxy) => {
    if (!urlOrProxy) return '';
    // API liefert bereits "/img.php?u=..."
    if (/^\/img\.php\?/.test(urlOrProxy)) return urlOrProxy;
    return `/img.php?src=${encodeURIComponent(urlOrProxy)}`;
  };

  // ---------------------------------------------
  // UI BASICS
  // ---------------------------------------------
  // Jahr im Footer
  const yearEl = $('#year');
  if (yearEl) yearEl.textContent = new Date().getFullYear();

  // Theme Toggle
  const root = document.documentElement;
  const applyTheme = (t) => {
    if (t === 'dark') root.classList.add('dark');
    else root.classList.remove('dark');
  };
  const storedTheme = localStorage.getItem('azk_theme');
  applyTheme(storedTheme || 'light');

  const setupThemeToggle = (btn) => {
    if (!btn) return;
    btn.addEventListener('click', () => {
      const next = root.classList.contains('dark') ? 'light' : 'dark';
      localStorage.setItem('azk_theme', next);
      applyTheme(next);
      const pressed = next === 'dark';
      btn.setAttribute('aria-pressed', String(pressed));
      const icon = $('#themeIcon', btn) || btn;
      icon.textContent = pressed ? '☀️' : '🌙';
    });
  };
  setupThemeToggle($('#themeToggle'));
  setupThemeToggle($('#themeToggleMobile'));

  // Mobile Menü
  const mobileBtn = $('#mobileMenuBtn');
  const mobileMenu = $('#mobileMenu');
  if (mobileBtn && mobileMenu) {
    mobileBtn.addEventListener('click', () => {
      const open = !mobileMenu.classList.contains('open');
      mobileMenu.classList.toggle('open', open);
      mobileBtn.setAttribute('aria-expanded', String(open));
    });
    // Close on link click
    mobileMenu.addEventListener('click', (e) => {
      if (e.target.tagName === 'A') {
        mobileMenu.classList.remove('open');
        mobileBtn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // Back to top
  const back = $('#backToTop');
  if (back) {
    window.addEventListener('scroll', () => {
      back.classList.toggle('show', window.scrollY > 400);
    });
    back.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
  }

  // Reveal Animation
  const revealer = () => {
    const items = $$('.reveal');
    if (!('IntersectionObserver' in window) || !items.length) {
      items.forEach((el) => el.classList.add('is-visible'));
      return;
    }
    const io = new IntersectionObserver((ents) => {
      ents.forEach((e) => {
        if (e.isIntersecting) {
          const d = parseInt(e.target.getAttribute('data-reveal-delay') || '0', 10);
          setTimeout(() => e.target.classList.add('is-visible'), clamp(d, 0, 2000));
          if (e.target.getAttribute('data-reveal-once') !== 'false') io.unobserve(e.target);
        }
      });
    }, { rootMargin: '0px 0px -10% 0px', threshold: 0.1 });
    items.forEach((el) => io.observe(el));
  };
  revealer();

  // Cookie Banner (schlank)
  const cookieBanner = $('#cookieBanner');
  const cookieAccept = $('#cookieAcceptAll');
  const cookieReject = $('#cookieReject');
  const cookieSettingsBtn = $('#cookieSettingsBtn');

  const enableDeferredScripts = (mode) => {
    // Beispiel: <script type="text/plain" data-consent="analytics" data-src="..."></script>
    $$('script[type="text/plain"][data-src]').forEach((s) => {
      const needs = s.getAttribute('data-consent') || 'analytics';
      if (mode === 'all' || needs === 'necessary') {
        const real = document.createElement('script');
        real.src = s.getAttribute('data-src');
        document.body.appendChild(real);
      }
    });
  };

  const cons = localStorage.getItem('azk_cookie');
  if (!cons && cookieBanner) cookieBanner.style.display = 'block';

  const setConsent = (mode) => {
    localStorage.setItem('azk_cookie', mode);
    if (cookieBanner) cookieBanner.style.display = 'none';
    enableDeferredScripts(mode);
  };
  cookieAccept && cookieAccept.addEventListener('click', () => setConsent('all'));
  cookieReject && cookieReject.addEventListener('click', () => setConsent('necessary'));
  cookieSettingsBtn && cookieSettingsBtn.addEventListener('click', () => {
    // Minimal: Banner erneut zeigen
    if (cookieBanner) cookieBanner.style.display = 'block';
  });

  // Newsletter Dummy
  const newsletter = $('#newsletterForm');
  if (newsletter) {
    newsletter.addEventListener('submit', (e) => {
      e.preventDefault();
      const email = $('#newsletterEmail').value.trim();
      const ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
      $('#newsletterStatus').textContent = ok ? 'Danke! Wir melden uns.' : 'Bitte eine gültige E-Mail eingeben.';
      if (ok) newsletter.reset();
    });
  }

  // ---------------------------------------------
  // FAHRZEUGE
  // ---------------------------------------------
  const grid = $('#vehiclesGrid');
  if (!grid) return;

  const searchInput = $('#searchInput');
  const fuelFilter = $('#fuelFilter');
  const sortSelect = $('#sortSelect');
  const resetBtn = $('#resetFilters');
  const moreBtn = $('#loadMoreBtn');

  let allCars = [];
  let viewCars = [];
  let shown = 0;
  const PAGE = 9;

  const showSkeletons = (n = 6) => {
    grid.innerHTML = '';
    for (let i = 0; i < n; i++) {
      const sk = document.createElement('div');
      sk.className = 'card-skeleton';
      grid.appendChild(sk);
    }
  };

  const fetchCars = async () => {
    showSkeletons(9);
    try {
      const res = await fetch('/api/vehicles.php?limit=60', { cache: 'no-store' });
      const json = await res.json();
      const arr = Array.isArray(json?.data) ? json.data : [];
      // Normalize
      allCars = arr.map((c) => ({
        id: c.adId,
        url: c.url,
        title: String(c.title || '').replace(/_/g, ' ').trim(),
        price: Number(c.price || 0),
        priceLabel: c.priceLabel || '',
        km: Number(c.km || 0),
        year: Number(c.year || 0),
        fuel: mapFuel(c.fuel || ''),
        gear: mapGearFromSpecs(c.specs),
        img: buildImgSrc(c.img || '')
      }));
      applyFilters();
    } catch (e) {
      grid.innerHTML = '<div class="card">Fahrzeuge konnten nicht geladen werden. Bitte später erneut versuchen.</div>';
      console.error(e);
    }
  };

  const applyFilters = () => {
    const q = (searchInput?.value || '').toLowerCase().trim();
    const ff = (fuelFilter?.value || '').toLowerCase();

    viewCars = allCars.filter((c) => {
      const okFuel = !ff || c.fuel.toLowerCase() === ff;
      const okQ = !q || c.title.toLowerCase().includes(q);
      return okFuel && okQ;
    });

    // Sortierung
    const s = sortSelect?.value || 'price-asc';
    const dir = s.endsWith('desc') ? -1 : 1;
    if (s.startsWith('price')) viewCars.sort((a, b) => (a.price - b.price) * dir);
    else if (s.startsWith('km')) viewCars.sort((a, b) => (a.km - b.km) * dir);
    else if (s.startsWith('year')) viewCars.sort((a, b) => (a.year - b.year) * dir);

    // Render zurücksetzen
    shown = 0;
    grid.innerHTML = '';
    renderMore();
  };

  const renderCard = (c) => {
    const el = document.createElement('article');
    el.className = 'vehicle-card';

    // Media
    const media = document.createElement('div');
    media.className = 'thumb';
    const img = document.createElement('img');
    img.src = c.img || '';
    img.alt = c.title || 'Fahrzeug';
    img.loading = 'lazy';
    img.decoding = 'async';
    img.onerror = () => {
      img.remove();
      const ph = document.createElement('div');
      ph.className = 'vehicle-card__ph';
      ph.innerHTML = '<span>Foto folgt</span>';
      media.appendChild(ph);
    };
    media.appendChild(img);

    // Body
    const body = document.createElement('div');
    body.className = 'body';

    const header = document.createElement('div');
    header.className = 'header';

    const title = document.createElement('h3');
    title.className = 'title';
    title.textContent = c.title;

    const price = document.createElement('div');
    price.className = 'pricePill';
    price.textContent = c.priceLabel || (c.price ? fmt.format(c.price) + ' €' : '');

    const meta = document.createElement('div');
    meta.className = 'meta';
    const kmNice = c.km ? (c.km >= 1000 ? `${Math.round(c.km / 1000)} Tsd. km` : `${fmt.format(c.km)} km`) : '';
    const parts = [c.year || '', kmNice, c.fuel || '', c.gear || ''].filter(Boolean);
    meta.textContent = parts.join(' · ');

    const footer = document.createElement('div');
    footer.className = 'footer';
    const tags = document.createElement('div');
    tags.className = 'meta';
    tags.textContent = 'Scheckheft · Garantie';

    const btn = document.createElement('a');
    btn.className = 'details';
    btn.href = c.url;
    btn.target = '_blank';
    btn.rel = 'noopener';
    btn.textContent = 'Details';

    header.appendChild(title);
    header.appendChild(price);
    footer.appendChild(tags);
    footer.appendChild(btn);

    body.appendChild(header);
    body.appendChild(meta);
    body.appendChild(footer);

    el.appendChild(media);
    el.appendChild(body);
    return el;
  };

  const renderMore = () => {
    const next = viewCars.slice(shown, shown + PAGE);
    next.forEach((c) => grid.appendChild(renderCard(c)));
    shown += next.length;
    if (moreBtn) moreBtn.style.display = shown < viewCars.length ? '' : 'none';
    if (shown === 0) grid.innerHTML = '<div class="card">Keine Fahrzeuge gefunden.</div>';
  };

  // Events
  searchInput && searchInput.addEventListener('input', applyFilters);
  fuelFilter && fuelFilter.addEventListener('change', applyFilters);
  sortSelect && sortSelect.addEventListener('change', applyFilters);
  resetBtn && resetBtn.addEventListener('click', () => {
    if (searchInput) searchInput.value = '';
    if (fuelFilter) fuelFilter.value = '';
    if (sortSelect) sortSelect.value = 'price-asc';
    applyFilters();
  });
  moreBtn && moreBtn.addEventListener('click', renderMore);

  // GO
  fetchCars();
})();
