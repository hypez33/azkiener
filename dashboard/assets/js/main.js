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
  if (/^https?:\\/\\//i.test(u)) return "img.php?u=" + encodeURIComponent(u);
  return u;
}
function safeImg(u) { const p = viaProxy(u); return p && p.length ? p : PLACEHOLDER; }

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
  if(/manual[_\\s-]*gear/i.test(s) || /manual/i.test(s)) return "Manuell";
  if(/automatic[_\\s-]*gear/i.test(s) || /auto(matik)?/i.test(s)) return "Automatik";
  return v||"";
}

function num(val){
  if(typeof val === "number") return val;
  if(typeof val === "string"){
    const cleaned = val.replace(/\\./g,'').replace(',', '.');
    const m = cleaned.match(/-?\\d+(?:\\.\\d+)?/);
    return m ? Number(m[0]) : NaN;
  }
  return NaN;
}
function toVehicle(r){
  const v = {};
  v.title = r.title || r.name || r.model || r.headline || "Fahrzeug";
  v.url   = r.url || r.link || r.href || "#";
  v.img   = r.image || r.imageUrl || r.photo || r.img || r.thumbnail || "";
  v.year  = r.year || (r.firstRegistration && Number(String(r.firstRegistration).match(/\\d{4}/)?.[0])) || undefined;
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
  slice.forEach((v,i)=>grid.appendChild(card(v, i*80)));
  visible+=slice.length;
  loadMoreBtn&&(loadMoreBtn.style.display=(visible<src.length)?"inline-flex":"none");
  if(visible===0) grid.innerHTML='<div class="text-sm text-gray-600">Keine Fahrzeuge gefunden.</div>';
  revealify(grid);
}

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

  const API_VEHICLES = "api/vehicles.php?limit=60";
  const API_REFRESH  = "api/refresh.php?limit=60";

  function pickList(j){
    if(!j) return [];
    if (Array.isArray(j)) return j;
    if (Array.isArray(j.data) && 'ts' in j) return j.data;           // legacy {ts,data}
    if (j.status === 'ok' && Array.isArray(j.data)) return j.data;   // generic {status,data}
    if (Array.isArray(j.vehicles)) return j.vehicles;
    if (Array.isArray(j.items)) return j.items;
    return [];
  }

  async function fetchJSON(url){
    const res = await fetch(url, { cache: 'no-store' });
    const text = await res.text();
    try { return JSON.parse(text); }
    catch(e){ console.error("JSON parse failed for", url, " → raw response:", text); throw e; }
  }

  (async ()=>{
    try{
      let payload = await fetchJSON(API_VEHICLES);
      let list = pickList(payload);
      if(!list.length){
        console.warn("vehicles.php returned 0 items → trying refresh.php");
        payload = await fetchJSON(API_REFRESH);
        list = pickList(payload);
      }
      allVehicles = list.map(toVehicle);
      render(true);
    }catch(err){
      console.error("Load failed:", err);
      grid.innerHTML='<div class="text-sm text-gray-600">Fehler beim Laden der Fahrzeuge.</div>';
    }
  })();
});
/* Mehr laden */

loadMoreBtn&&loadMoreBtn.addEventListener("click",()=>{ render(false); });
