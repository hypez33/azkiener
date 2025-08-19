// Helpers
const $  = (s, c=document)=>c.querySelector(s);
const $$ = (s, c=document)=>Array.from(c.querySelectorAll(s));

/* Jahr */
$("#year") && ($("#year").textContent = new Date().getFullYear());

/* Mobile-Menü (animiertes Panel) */
const mobileBtn=$("#mobileMenuBtn"), mobileMenu=$("#mobileMenu");
if(mobileBtn&&mobileMenu){
  mobileBtn.addEventListener("click",()=>{
    const isOpen = mobileMenu.classList.toggle("open");
    mobileBtn.setAttribute("aria-expanded", String(isOpen));
  });
  $$("#mobileMenu a").forEach(a=>a.addEventListener("click",()=>{
    mobileMenu.classList.remove("open");
    mobileBtn.setAttribute("aria-expanded","false");
  }));
}

/* Dark Mode */
const root=document.documentElement, themeBtn=$("#themeToggle"), themeBtnMobile=$("#themeToggleMobile"), themeIcon=$("#themeIcon"), THEME_KEY="ak-theme";
const applyTheme=(m)=>{m==="dark"?root.classList.add("dark"):root.classList.remove("dark");
  themeBtn&&themeBtn.setAttribute("aria-pressed",m==="dark"?"true":"false");
  themeBtnMobile&&themeBtnMobile.setAttribute("aria-pressed",m==="dark"?"true":"false");
  themeIcon&&(themeIcon.textContent=m==="dark"?"☀️":"🌙");
  try{localStorage.setItem(THEME_KEY,m)}catch(e){}};
(()=>{let s=null; try{s=localStorage.getItem(THEME_KEY)}catch(e){}; if(s) applyTheme(s); else if(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches) applyTheme("dark");})();
[themeBtn,themeBtnMobile].forEach(b=>b&&b.addEventListener("click",()=>{applyTheme(root.classList.contains("dark")?"light":"dark");}));

/* Back-to-top */
const backToTop=$("#backToTop"); if(backToTop){
  window.addEventListener("scroll",()=>{window.scrollY>600?backToTop.classList.add("show"):backToTop.classList.remove("show")});
  backToTop.addEventListener("click",()=>window.scrollTo({top:0,behavior:"smooth"}));
}

/* Cookie Banner */
const cookieBanner=$("#cookieBanner"), cookieAccept=$("#cookieAcceptAll"), cookieReject=$("#cookieReject"), cookieSettingsBtn=$("#cookieSettingsBtn"), COOKIE_KEY="ak-cookie-consent";
const showCookie=()=>{cookieBanner&&(cookieBanner.style.display="block")}, hideCookie=()=>{cookieBanner&&(cookieBanner.style.display="none")};
try{
  const c=localStorage.getItem(COOKIE_KEY);
  if(!c) showCookie();
  cookieAccept&&cookieAccept.addEventListener("click",()=>{localStorage.setItem(COOKIE_KEY,JSON.stringify({necessary:true,prefs:true,analytics:true})); hideCookie();});
  cookieReject&&cookieReject.addEventListener("click",()=>{localStorage.setItem(COOKIE_KEY,JSON.stringify({necessary:true,prefs:false,analytics:false})); hideCookie();});
  cookieSettingsBtn&&cookieSettingsBtn.addEventListener("click",showCookie);
}catch(e){}

/* ===== Scroll-Reveal ===== */
function applyGroupDelays(ctx=document){
  // Elemente mit data-reveal-group="step(ms)" verteilen automatisch Verzögerungen an Kinder
  $("[data-reveal-group]", ctx) && $$( "[data-reveal-group]", ctx ).forEach(group=>{
    const step = parseInt(group.dataset.revealGroup || "100", 10);
    let i=0;
    $$(".reveal", group).forEach(el=>{
      if(!el.dataset.revealDelay){ el.dataset.revealDelay = String(i*step); }
      i++;
    });
  });
}
function revealify(ctx=document){
  applyGroupDelays(ctx);
  const els=$$('.reveal', ctx);
  if(!els.length){return;}
  if(!('IntersectionObserver' in window)){
    els.forEach(el=>el.classList.add('is-visible'));
    return;
  }
  const io=new IntersectionObserver((entries,obs)=>{
    entries.forEach(entry=>{
      const el=entry.target;
      const once = el.dataset.revealOnce !== 'false';
      if(entry.isIntersecting){
        const d=parseInt(el.dataset.revealDelay||'0',10);
        if(d) el.style.transitionDelay = `${d}ms`;
        el.classList.add('is-visible');
        if(once) obs.unobserve(el);
      }else if(!once){
        el.classList.remove('is-visible');
        el.style.transitionDelay = '';
      }
    });
  }, {threshold:0.18});
  els.forEach(el=>io.observe(el));
}

/* ===== VEHICLES ===== */
const grid=$("#vehiclesGrid"), loadMoreBtn=$("#loadMoreBtn");
let allVehicles=[], visible=0, PAGE=6;

const PLACEHOLDER =
  'data:image/svg+xml;utf8,' +
  encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="800" height="450">
  <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
  <stop offset="0" stop-color="#e5e7eb"/><stop offset="1" stop-color="#f3f4f6"/></linearGradient></defs>
  <rect width="100%" height="100%" fill="url(#g)"/>
  <g fill="#9ca3af" font-family="Arial,Helvetica,sans-serif" text-anchor="middle">
    <text x="50%" y="50%" font-size="28">Bild wird geladen …</text>
  </g></svg>`);

function viaProxy(u) {
  if (!u) return "";
  if (u.startsWith("img.php?u=") || u.startsWith("api/")) return u;
  if (/^https?:\/\//i.test(u)) return "img.php?u=" + encodeURIComponent(u);
  return u;
}
function safeImg(u) { const p = viaProxy(u); return p && p.length ? p : PLACEHOLDER; }

/* Lokalisierung */
const EUR = new Intl.NumberFormat('de-DE', {style:'currency', currency:'EUR', maximumFractionDigits:0});
function formatPrice(v){ if(typeof v!=='number' || isNaN(v) || v<=0) return "Preis auf Anfrage"; return EUR.format(v); }
function localizeFuel(v){
  const m = String(v||"").toLowerCase();
  if(m.includes("petrol") || m.includes("benzin")) return "Benziner";
  if(m.includes("diesel")) return "Diesel";
  if(m.includes("hybrid")) return "Hybrid";
  if(m.includes("electric") || m.includes("elektro")) return "Elektrisch";
  return v||"";
}
function localizeGear(v){
  const s = String(v||"");
  if(/manual[_\s-]*gear/i.test(s) || /manual/i.test(s)) return "Manuell";
  if(/automatic[_\s-]*gear/i.test(s) || /auto(matik)?/i.test(s)) return "Automatik";
  return v||"";
}

/* Normalisierung */
function num(val){
  if(typeof val === "number") return val;
  if(typeof val === "string"){
    const cleaned = val.replace(/\./g,'').replace(',', '.');
    const m = cleaned.match(/-?\d+(?:\.\d+)?/);
    return m ? Number(m[0]) : NaN;
  }
  return NaN;
}
function toVehicle(r){
  const v = {};
  v.title = r.title || r.name || r.model || r.headline || "Fahrzeug";
  v.url   = r.url || r.link || r.href || "#";
  v.img   = r.image || r.imageUrl || r.photo || r.img || r.thumbnail || "";
  v.year  = r.year || (r.firstRegistration && Number(String(r.firstRegistration).match(/\d{4}/)?.[0])) || undefined;
  v.km    = Number.isFinite(r.km) ? r.km : (num(r.mileage) || num(r.kilometerstand) || NaN);
  const ps = num(r.ps) || (num(r.power) && num(r.power)) || (num(r.kw) ? Math.round(num(r.kw) * 1.35962) : NaN);
  v.power = Number.isFinite(ps) ? ps : undefined;
  v.fuel  = localizeFuel(r.fuel || r.fuelType || r.kraftstoff);
  v.gear  = localizeGear(r.gear || r.gearbox || r.transmission || r.getriebe);
  const priceN = num(r.price) || num(r.priceEur) || num(r.preis) || num(r.sellingPrice);
  v.price = Number.isFinite(priceN) ? priceN : 0;
  v.priceLabel = formatPrice(v.price);
  v.specs = [v.year, (Number.isFinite(v.km)? (v.km/1000).toFixed(0).replace('.',',') + " Tsd. km" : null), v.fuel, (v.power? v.power + " PS": null), (v.gear||null)]
              .filter(Boolean).join(" · ");
  return v;
}

/* Karte (mit Reveal) */
function card(v, delay=0) {
  const el = document.createElement("article");
  el.className = "vehicle-card group reveal";
  el.setAttribute('data-reveal','up');
  if(delay) el.setAttribute('data-reveal-delay', String(delay));
  const src = safeImg(v.img);
  el.innerHTML = `
    <div class="thumb">
      <img src="${src}" alt="${v.title}" loading="lazy"
           onerror="this.onerror=null;this.src='assets/placeholder/car.jpg';"/>
    </div>
    <div class="body">
      <div class="header">
        <h3 class="title">${v.title}</h3>
        <span class="pricePill">${v.priceLabel || ""}</span>
      </div>
      <p class="meta">${v.specs || ""}</p>
      <div class="footer">
        <span class="text-sm text-gray-500">Scheckheft · Garantie</span>
        <a class="details font-medium transition-colors" href="${v.url || "#"}" target="_blank" rel="noopener">Details</a>
      </div>
    </div>`;
  return el;
}

/* Filter/Render */
function applyFilters(list){
  const q=(($("#searchInput")||{}).value||"").toLowerCase().trim();
  const fuelSel=$("#fuelFilter"); const fuel=fuelSel?fuelSel.value:"";
  let out=list.filter(v=>v.title?.toLowerCase().includes(q)||(v.specs||"").toLowerCase().includes(q));
  if(fuel) out=out.filter(v=>v.fuel===fuel);
  const sortSel=$("#sortSelect"); const sort=sortSel?sortSel.value:"price-asc";
  const map={
    "price-asc":(a,b)=> (a.price||Infinity)-(b.price||Infinity),
    "price-desc":(a,b)=> (b.price||-1)-(a.price||-1),
    "km-asc":(a,b)=> (a.km||Infinity)-(b.km||Infinity),
    "km-desc":(a,b)=> (b.km||-1)-(a.km||-1),
    "year-asc":(a,b)=> (a.year||0)-(b.year||0),
    "year-desc":(a,b)=> (b.year||0)-(a.year||0)
  };
  out.sort(map[sort]||map["price-asc"]);
  return out;
}
function render(reset=false){
  if(!grid) return;
  if(reset){grid.innerHTML=""; visible=0;}
  const src=applyFilters(allVehicles);
  const slice=src.slice(visible,visible+PAGE);
  // Stagger für neue Karten
  slice.forEach((v,i)=>grid.appendChild(card(v, i*80)));
  visible+=slice.length;
  loadMoreBtn&&(loadMoreBtn.style.display=(visible<src.length)?"inline-flex":"none");
  if(visible===0) grid.innerHTML='<div class="text-sm text-gray-600">Keine Fahrzeuge gefunden.</div>';
  revealify(grid);
}

/* Filter-Events */
["searchInput","fuelFilter","sortSelect"].forEach(id=>{
  const el=document.getElementById(id);
  el&&el.addEventListener("input",()=>{persistFilters(); render(true)});
});
const resetBtn = $("#resetFilters");
resetBtn&&resetBtn.addEventListener("click",()=>{
  const s=$("#searchInput"), f=$("#fuelFilter"), o=$("#sortSelect");
  s&&(s.value=""); f&&(f.value=""); o&&(o.value="price-asc");
  persistFilters(); render(true);
});

/* Persistenz in URL */
function persistFilters(){
  const s=$("#searchInput")?.value||"";
  const f=$("#fuelFilter")?.value||"";
  const o=$("#sortSelect")?.value||"price-asc";
  const url=new URL(location.href);
  url.searchParams.set("q",s); url.searchParams.set("fuel",f); url.searchParams.set("sort",o);
  history.replaceState(null,"",url.toString());
}
function restoreFilters(){
  const url=new URL(location.href);
  const s=url.searchParams.get("q")||"";
  const f=url.searchParams.get("fuel")||"";
  const o=url.searchParams.get("sort")||"price-asc";
  $("#searchInput")&&(document.getElementById("searchInput").value=s);
  $("#fuelFilter")&&(document.getElementById("fuelFilter").value=f);
  $("#sortSelect")&&(document.getElementById("sortSelect").value=o);
}

/* Laden */
document.addEventListener("DOMContentLoaded",()=>{
  revealify(); // initial
  if(!grid) return;
  restoreFilters();
  fetch("api/vehicles.php")
    .then(r=>r.json())
    .then(j=>{
      const list = Array.isArray(j) ? j : (j?.vehicles || j?.items || []);
      allVehicles = list.map(toVehicle);
      render(true);
    })
    .catch(()=>{ grid.innerHTML='<div class="text-sm text-gray-600">Fehler beim Laden der Fahrzeuge.</div>'; });
});

/* Mehr laden */
loadMoreBtn&&loadMoreBtn.addEventListener("click",()=>{ render(false); });

/* Newsletter */
const newsletterForm=$("#newsletterForm");
const newsletterEmail=$("#newsletterEmail");
const newsletterStatus=$("#newsletterStatus");
newsletterForm&&newsletterForm.addEventListener("submit",(e)=>{
  e.preventDefault();
  const v = newsletterEmail?.value?.trim()||"";
  if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)){
    newsletterStatus.textContent="Bitte gültige E-Mail eingeben.";
    return;
  }
  newsletterStatus.textContent="Vielen Dank! Sie erhalten in Kürze eine Bestätigung.";
  newsletterForm.reset();
});

/* Kontaktformular (falls kontakt.html vorhanden) */
(function(){
  const form = document.getElementById('contactForm');
  const status = document.getElementById('formStatus');
  if(!form) return;
  form.addEventListener('submit', function(e){
    e.preventDefault();
    status && (status.textContent = 'Sende …');
    const data = new FormData(form);
    fetch(form.action || 'api/contact.php', { method:'POST', body:data })
      .then(async res => {
        const json = await res.json().catch(()=>({}));
        if(res.ok && json.ok){
          status.textContent = 'Vielen Dank! Wir melden uns zeitnah.';
          document.querySelectorAll('.helper').forEach(h=>h.textContent='');
          form.reset();
        } else {
          if(json.errors){
            status.textContent = 'Bitte prüfen Sie die Eingaben.';
            document.querySelectorAll('.helper').forEach(h=>h.textContent='');
            for(const [k,msg] of Object.entries(json.errors)){
              const helper = document.querySelector(`.helper[data-for="${k}"]`);
              if(helper) helper.textContent = msg;
            }
          } else {
            status.textContent = 'Versand fehlgeschlagen. Bitte später erneut versuchen.';
          }
        }
      })
      .catch(()=>{ status.textContent = 'Netzwerkfehler. Bitte später erneut versuchen.'; });
  });
})();


/* ===== Cookie Consent Manager (GDPR/TTDSG) =====
   Categories: necessary (always on), preferences, analytics, marketing.
   How to use for third-party scripts:
     <script type="text/plain" data-consent="analytics" data-src="https://example.com/analytics.js"></script>
   Or inline:
     <script type="text/plain" data-consent="marketing">console.log('ads ready');</script>
*/
(function(){
  const STORAGE_KEY = 'azkConsent.v1';
  const $ = (sel, ctx=document) => ctx.querySelector(sel);
  const $$ = (sel, ctx=document) => Array.from(ctx.querySelectorAll(sel));
  const body = document.body;

  const defaultState = { given:false, ts:null, preferences:false, analytics:false, marketing:false };

  function loadState(){
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {...defaultState}; }
    catch(e){ return {...defaultState}; }
  }
  function saveState(state){
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  }

  function applyConsent(state){
    // Enable scripts of granted categories
    const allowed = new Set(['necessary']);
    if(state.preferences) allowed.add('preferences');
    if(state.analytics) allowed.add('analytics');
    if(state.marketing) allowed.add('marketing');

    $$('script[type="text/plain"][data-consent]').forEach(node => {
      const cat = (node.getAttribute('data-consent')||'').toLowerCase();
      if(allowed.has(cat) && !node.dataset.executed){
        const newScript = document.createElement('script');
        // copy attributes except type/data-consent
        for (const {name, value} of Array.from(node.attributes)){
          if(name === 'type' || name === 'data-consent' || name === 'data-executed') continue;
          if(name === 'data-src'){ newScript.src = value; continue; }
          newScript.setAttribute(name, value);
        }
        if(node.textContent.trim()){
          newScript.textContent = node.textContent;
        }
        node.replaceWith(newScript);
      }
      node.dataset.executed = '1';
    });
    // Add a class on <html> for developers to branch on
    const root = document.documentElement;
    root.classList.toggle('consent-analytics', !!state.analytics);
    root.classList.toggle('consent-marketing', !!state.marketing);
    root.classList.toggle('consent-preferences', !!state.preferences);
  }

  function buildBanner(){
    const wrapper = document.createElement('div');
    wrapper.id = 'cookieBanner';
    wrapper.className = 'cookie-banner';
    wrapper.setAttribute('role','dialog');
    wrapper.setAttribute('aria-live','polite');
    wrapper.setAttribute('aria-label','Cookie Hinweis');
    wrapper.innerHTML = `
      <div class="container">
        <p>Wir verwenden Cookies, um die Website zuverlässig zu betreiben. Optionale Cookies (Präferenzen, Analyse, Marketing) setzen wir nur mit Ihrer Einwilligung.
          <a href="datenschutz.html#cookies" class="link">Details</a>
        </p>
        <div class="actions">
          <button id="cookieSettingsOpen" class="btn-outline">Einstellungen</button>
          <button id="cookieReject" class="btn-outline">Nur notwendige</button>
          <button id="cookieAcceptAll" class="btn-primary">Alle akzeptieren</button>
        </div>
      </div>`;
    return wrapper;
  }

  function buildModal(state){
    const overlay = document.createElement('div');
    overlay.id = 'cookieModal';
    overlay.className = 'cookie-modal hidden';
    overlay.setAttribute('role','dialog');
    overlay.setAttribute('aria-modal','true');
    overlay.innerHTML = `
      <div class="cookie-modal__backdrop" data-close></div>
      <div class="cookie-modal__dialog" role="document">
        <div class="cookie-modal__header">
          <h2 class="cookie-modal__title">Cookie-Einstellungen</h2>
          <button class="cookie-modal__close btn-icon" aria-label="Schließen" data-close>✕</button>
        </div>
        <div class="cookie-modal__body">
          <div class="cookie-option">
            <div>
              <div class="cookie-option__title">Technisch notwendige Cookies</div>
              <div class="cookie-option__desc">Erforderlich für grundlegende Funktionen (z. B. Dark-Mode-Speicherung, CSRF-Schutz).</div>
            </div>
            <div><input type="checkbox" checked disabled></div>
          </div>
          <div class="cookie-option">
            <div>
              <label class="cookie-option__title" for="consentPreferences">Präferenzen</label>
              <div class="cookie-option__desc">z. B. Merken Ihrer Auswahl (Sprache, Ansicht).</div>
            </div>
            <div><input id="consentPreferences" type="checkbox" ${state.preferences?'checked':''}></div>
          </div>
          <div class="cookie-option">
            <div>
              <label class="cookie-option__title" for="consentAnalytics">Analyse</label>
              <div class="cookie-option__desc">anonyme Nutzungsstatistik (z. B. Matomo/GA). Nur mit Opt‑In.</div>
            </div>
            <div><input id="consentAnalytics" type="checkbox" ${state.analytics?'checked':''}></div>
          </div>
          <div class="cookie-option">
            <div>
              <label class="cookie-option__title" for="consentMarketing">Marketing</label>
              <div class="cookie-option__desc">z. B. Karten/Video‑Einbettungen mit Tracking.</div>
            </div>
            <div><input id="consentMarketing" type="checkbox" ${state.marketing?'checked':''}></div>
          </div>
        </div>
        <div class="cookie-modal__footer">
          <button class="btn-outline" data-action="reject">Nur notwendige</button>
          <button class="btn-outline" data-action="save">Speichern</button>
          <button class="btn-primary" data-action="accept">Alle akzeptieren</button>
        </div>
      </div>`;
    return overlay;
  }

  function openModal(){ $('#cookieModal')?.classList.remove('hidden'); document.body.classList.add('no-scroll'); }
  function closeModal(){ $('#cookieModal')?.classList.add('hidden'); document.body.classList.remove('no-scroll'); }

  // Mount UI
  const state = loadState();
  const banner = buildBanner();
  const modal = buildModal(state);
  document.body.appendChild(modal);
  if(!state.given){ document.body.appendChild(banner); banner.style.display='block'; }

  // Buttons
  function giveConsent(next){
    const newState = {
      given:true,
      ts: new Date().toISOString(),
      preferences: !!next.preferences,
      analytics: !!next.analytics,
      marketing: !!next.marketing
    };
    saveState(newState);
    applyConsent(newState);
    $('#cookieBanner')?.remove();
    closeModal();
  }

  document.addEventListener('click', (e)=>{
    const t = e.target;
    if(!(t instanceof HTMLElement)) return;
    if(t.id === 'cookieAcceptAll' || (t.dataset.action === 'accept')){
      e.preventDefault();
      giveConsent({preferences:true, analytics:true, marketing:true});
    } else if(t.id === 'cookieReject' || (t.dataset.action === 'reject')){
      e.preventDefault();
      giveConsent({preferences:false, analytics:false, marketing:false});
    } else if(t.id === 'cookieSettingsOpen'){
      e.preventDefault(); openModal();
    } else if(t.dataset.action === 'save'){
      e.preventDefault();
      const prefs = $('#consentPreferences')?.checked;
      const ana = $('#consentAnalytics')?.checked;
      const mkt = $('#consentMarketing')?.checked;
      giveConsent({preferences:prefs, analytics:ana, marketing:mkt});
    } else if(t.hasAttribute('data-close')){
      e.preventDefault(); closeModal();
    }
  });

  // Footer "Cookie-Einstellungen" buttons
  $$('#cookieSettingsBtn').forEach(btn => btn.addEventListener('click', openModal));

  // On load, apply consent (in case user previously opted in)
  applyConsent(state);

  // Helper: expose for debugging
  window.azkConsent = { loadState, saveState, applyConsent, openModal, closeModal };
})();
