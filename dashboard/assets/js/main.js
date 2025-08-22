/* Autozentrum Kiener – Frontend-Logik (ohne externe Abhängigkeiten) */
(function () {
  "use strict";

  // ------------------------------
  // Helpers
  // ------------------------------
  const $ = (sel, el = document) => el.querySelector(sel);
  const $$ = (sel, el = document) => Array.from(el.querySelectorAll(sel));
  const fmtNumber = (n) =>
    (Number.isFinite(+n) ? new Intl.NumberFormat("de-DE").format(+n) : "");
  const fmtPrice = (n) =>
    Number.isFinite(+n)
      ? new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" }).format(+n)
      : (typeof n === "string" ? n : "");
  const mapFuel = (val) => {
    if (!val) return "";
    const t = String(val).toLowerCase();
    if (t.includes("petrol") || t.includes("benzin")) return "Benziner";
    if (t.includes("diesel")) return "Diesel";
    if (t.includes("electric")) return "Elektrisch";
    if (t.includes("hybrid")) return "Hybrid";
    if (t.includes("cng")) return "CNG";
    if (t.includes("lpg")) return "LPG";
    return val;
  };
  const mapGear = (src) => {
    const t = String(src || "").toLowerCase();
    if (t.includes("automatic_gear") || t.includes("automatik")) return "Automatik";
    if (t.includes("manual_gear") || t.includes("schalt")) return "Manuell";
    return "";
  };
  const imgProxy = (urlLike) => {
    if (!urlLike) return "";
    const u = String(urlLike);
    if (u.startsWith("/img.php")) return u; // bereits proxied
    if (!/^https?:\/\//i.test(u)) return "";
    // akzeptiert sowohl ?src als auch ?u
    return `/img.php?src=${encodeURIComponent(u)}`;
  };

  // ------------------------------
  // Fahrzeuge: Laden, Rendern, Filtern
  // ------------------------------
  const state = {
    all: [],
    visible: [],
    page: 1,
    pageSize: 9
  };

  const els = {
    grid: $("#vehiclesGrid"),
    loadMore: $("#loadMoreBtn"),
    search: $("#searchInput"),
    fuel: $("#fuelFilter"),
    sort: $("#sortSelect"),
    reset: $("#resetFilters")
  };

  async function fetchVehicles() {
    try {
      const res = await fetch("/api/vehicles.php?limit=60", { cache: "no-store" });
      const json = await res.json();
      const raw = Array.isArray(json?.data) ? json.data : [];

      // Normalisieren
      state.all = raw.map((x) => {
        const title = x.title || [x.make, x.model, x.variant].filter(Boolean).join(" ").trim();
        const price = Number.isFinite(+x.price) ? +x.price : null;
        const priceLabel = x.priceLabel || (price !== null ? fmtPrice(price) : "");
        const img =
          imgProxy(x.img) ||
          imgProxy(x.image) ||
          imgProxy((Array.isArray(x.images) && x.images[0]) || "");

        const km = Number.isFinite(+x.km) ? +x.km : (Number.isFinite(+x.mileage) ? +x.mileage : null);
        const specsStr = [x.gear, x.gearbox, x.specs].filter(Boolean).join(" ");
        const url = x.url || x.link || "#";

        return {
          id: x.id || cryptoRandomId(),
          title,
          img,
          year: x.year || x.firstRegistration || "",
          km,
          fuel: mapFuel(x.fuel || x.fuelType || ""),
          gear: mapGear(specsStr),
          price,
          priceLabel,
          url
        };
      });

      applyAndRender();
    } catch (e) {
      console.error("Fahrzeuge konnten nicht geladen werden", e);
      renderError("Fahrzeuge konnten nicht geladen werden.");
    }
  }

  function cryptoRandomId() {
    try {
      return crypto.randomUUID();
    } catch {
      return "id-" + Math.random().toString(36).slice(2);
    }
  }

  function applyAndRender({ resetPage = true } = {}) {
    const q = (els.search?.value || "").trim().toLowerCase();
    const fFuel = (els.fuel?.value || "").toLowerCase();
    const sort = els.sort?.value || "price-asc";

    let arr = state.all.slice();

    // Filter
    if (q) {
      arr = arr.filter((v) => v.title.toLowerCase().includes(q));
    }
    if (fFuel) {
      arr = arr.filter((v) => v.fuel.toLowerCase().includes(fFuel));
    }

    // Sortierung
    const sorters = {
      "price-asc": (a, b) => (a.price ?? Infinity) - (b.price ?? Infinity),
      "price-desc": (a, b) => (b.price ?? -Infinity) - (a.price ?? -Infinity),
      "km-asc": (a, b) => (a.km ?? Infinity) - (b.km ?? Infinity),
      "km-desc": (a, b) => (b.km ?? -Infinity) - (a.km ?? -Infinity),
      "year-desc": (a, b) => String(b.year).localeCompare(String(a.year)),
      "year-asc": (a, b) => String(a.year).localeCompare(String(b.year))
    };
    arr.sort(sorters[sort] || sorters["price-asc"]);

    // Paging
    if (resetPage) state.page = 1;
    const slice = arr.slice(0, state.page * state.pageSize);

    state.visible = slice;
    renderCards(slice);
    toggleLoadMore(arr.length > slice.length);
  }

  function renderError(msg) {
    if (!els.grid) return;
    els.grid.innerHTML = `<div class="card" role="alert">${msg}</div>`;
  }

  function renderCards(list) {
    if (!els.grid) return;

    const frag = document.createDocumentFragment();
    list.forEach((v) => frag.appendChild(buildCard(v)));

    els.grid.innerHTML = "";
    els.grid.appendChild(frag);
  }

  function buildCard(v) {
    const art = document.createElement("article");
    art.className = "vehicle-card reveal";
    art.setAttribute("data-reveal", "up");

    // Media
    const media = document.createElement("div");
    media.className = "thumb";
    if (v.img) {
      const img = document.createElement("img");
      img.src = v.img;
      img.alt = v.title || "Fahrzeug";
      img.loading = "lazy";
      img.decoding = "async";
      img.onerror = () => {
        img.remove();
        const ph = document.createElement("div");
        ph.className = "thumb__placeholder";
        ph.innerHTML = "<span>Foto folgt</span>";
        media.appendChild(ph);
      };
      media.appendChild(img);
    } else {
      const ph = document.createElement("div");
      ph.className = "thumb__placeholder";
      ph.innerHTML = "<span>Foto folgt</span>";
      media.appendChild(ph);
    }

    // Body
    const body = document.createElement("div");
    body.className = "body";

    const header = document.createElement("div");
    header.className = "header";
    const title = document.createElement("h3");
    title.className = "title";
    title.textContent = v.title || "";

    const price = document.createElement("div");
    price.className = "pricePill";
    price.textContent = v.priceLabel || "";

    header.appendChild(title);
    header.appendChild(price);

    const meta = document.createElement("div");
    meta.className = "meta";
    const parts = [];
    if (v.year) parts.push(v.year);
    if (Number.isFinite(v.km)) parts.push(`${fmtNumber(v.km)} km`);
    if (v.fuel) parts.push(v.fuel);
    if (v.gear) parts.push(v.gear);
    meta.textContent = parts.join(" · ");

    const footer = document.createElement("div");
    footer.className = "footer";
    const tags = document.createElement("div");
    tags.className = "tags";
    tags.innerHTML = `<span>Scheckheft</span> · <span>Garantie</span>`;
    const btn = document.createElement("a");
    btn.className = "details";
    btn.href = v.url || "#";
    btn.target = "_blank";
    btn.rel = "noopener";
    btn.textContent = "Details";

    footer.appendChild(tags);
    footer.appendChild(btn);

    body.appendChild(header);
    body.appendChild(meta);
    body.appendChild(footer);

    art.appendChild(media);
    art.appendChild(body);
    return art;
  }

  function toggleLoadMore(show) {
    if (!els.loadMore) return;
    els.loadMore.style.display = show ? "" : "none";
  }

  // Events
  els.loadMore?.addEventListener("click", () => {
    state.page += 1;
    applyAndRender({ resetPage: false });
  });
  els.search?.addEventListener("input", () => applyAndRender());
  els.search?.addEventListener("keydown", (e) => {
    if (e.key === "Enter") applyAndRender();
  });
  els.fuel?.addEventListener("change", () => applyAndRender());
  els.sort?.addEventListener("change", () => applyAndRender());
  els.reset?.addEventListener("click", () => {
    if (els.search) els.search.value = "";
    if (els.fuel) els.fuel.value = "";
    if (els.sort) els.sort.value = "price-asc";
    applyAndRender();
  });

  // ------------------------------
  // Scroll-Reveal
  // ------------------------------
  const io = new IntersectionObserver(
    (entries) => {
      for (const e of entries) {
        if (e.isIntersecting) {
          e.target.classList.add("is-visible");
          if (e.target.dataset.revealOnce !== "false") io.unobserve(e.target);
        }
      }
    },
    { threshold: 0.12 }
  );
  const hookReveal = () => $$(".reveal").forEach((el) => io.observe(el));
  hookReveal();

  // ------------------------------
  // Back-to-top
  // ------------------------------
  const backToTop = $("#backToTop");
  window.addEventListener("scroll", () => {
    const show = window.scrollY > 480;
    backToTop?.classList.toggle("show", show);
  });
  backToTop?.addEventListener("click", () => {
    window.scrollTo({ top: 0, behavior: "smooth" });
  });

  // ------------------------------
  // Theme (Dark/Light)
  // ------------------------------
  const root = document.documentElement;
  const THEME_KEY = "azk-theme";
  function applyTheme(t) {
    root.classList.toggle("dark", t === "dark");
    const pressed = t === "dark";
    $("#themeToggle")?.setAttribute("aria-pressed", String(pressed));
    $("#themeToggleMobile")?.setAttribute("aria-pressed", String(pressed));
    $("#themeIcon") && ($("#themeIcon").textContent = pressed ? "☀️" : "🌙");
  }
  function getPrefTheme() {
    return localStorage.getItem(THEME_KEY) ||
      (matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
  }
  applyTheme(getPrefTheme());
  $("#themeToggle")?.addEventListener("click", () => {
    const next = root.classList.contains("dark") ? "light" : "dark";
    localStorage.setItem(THEME_KEY, next); applyTheme(next);
  });
  $("#themeToggleMobile")?.addEventListener("click", () => {
    const next = root.classList.contains("dark") ? "light" : "dark";
    localStorage.setItem(THEME_KEY, next); applyTheme(next);
  });

  // ------------------------------
  // Mobile-Menü
  // ------------------------------
  const menuBtn = $("#mobileMenuBtn");
  const menu = $("#mobileMenu");
  menuBtn?.addEventListener("click", () => {
    const open = !menu.classList.contains("open");
    menu.classList.toggle("open", open);
    menuBtn.setAttribute("aria-expanded", String(open));
    document.body.classList.toggle("no-scroll", open);
  });

  // ------------------------------
  // Cookie-Banner (essentiell)
  // ------------------------------
  const COOKIE_KEY = "azk-consent";
  const cookieBanner = $("#cookieBanner");
  const hasConsent = localStorage.getItem(COOKIE_KEY);
  if (!hasConsent) cookieBanner?.classList.add("show");
  $("#cookieAcceptAll")?.addEventListener("click", () => acceptConsent("all"));
  $("#cookieReject")?.addEventListener("click", () => acceptConsent("essential"));
  $("#cookieSettingsBtn")?.addEventListener("click", () => {
    cookieBanner?.classList.add("show");
    window.scrollTo({ top: document.body.scrollHeight, behavior: "smooth" });
  });
  function acceptConsent(level) {
    localStorage.setItem(COOKIE_KEY, level);
    cookieBanner?.classList.remove("show");
    enableDeferredScripts(level);
  }
  function enableDeferredScripts(level) {
    $$('script[type="text/plain"][data-consent]').forEach((s) => {
      const need = s.getAttribute("data-consent");
      if (level === "all" || need === "essential") {
        const n = document.createElement("script");
        if (s.dataset.src) n.src = s.dataset.src;
        n.textContent = s.textContent;
        document.body.appendChild(n);
      }
    });
  }
  if (hasConsent) enableDeferredScripts(hasConsent);

  // ------------------------------
  // Newsletter (Demo)
  // ------------------------------
  $("#year") && ($("#year").textContent = new Date().getFullYear());
  $("#newsletterForm")?.addEventListener("submit", (e) => {
    e.preventDefault();
    const email = $("#newsletterEmail")?.value.trim();
    const status = $("#newsletterStatus");
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      status.textContent = "Bitte eine gültige E-Mail eingeben.";
      return;
    }
    status.textContent = "Danke! Wir melden uns.";
    e.target.reset();
  });

  // Init
  fetchVehicles().then(hookReveal);
})();
