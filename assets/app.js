/* ЦМК — Вебинары для подписчиков. Клиентская логика (работает через /api). */
"use strict";

const API = "api/index.php?action=";
const $ = (id) => document.getElementById(id);
// Безопасные помощники: не падают, если элемента нет в DOM (устойчивость к частичному деплою)
const on = (id, ev, fn) => { const el = document.getElementById(id); if (el) el.addEventListener(ev, fn); };
const setHidden = (id, v) => { const el = document.getElementById(id); if (el) el.classList.toggle("hidden", v); };
const setText = (id, t) => { const el = document.getElementById(id); if (el) el.textContent = t; };
const setHtml = (id, h) => { const el = document.getElementById(id); if (el) el.innerHTML = h; };
const qs = (sel) => document.querySelector(sel);
const esc = (s) => String(s ?? "").replace(/[&<>"']/g, m => ({ "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;" }[m]));
const TODAY = new Date(); TODAY.setHours(0,0,0,0);
const MONTHS = ["января","февраля","марта","апреля","мая","июня","июля","августа","сентября","октября","ноября","декабря"];

let CSRF = (document.querySelector('meta[name="csrf-token"]')||{}).content || "";
let currentUser = null;
let ALL = [];
let CABINET = "";
let MY_VIEWS = new Set();   // id вебинаров, которые пользователь открывал
let appSettings = { show_past: true };
let FEATURES = {};   // тумблеры функций от сервера
function feat(k){ return FEATURES[k] !== false; }   // по умолчанию включено
let COL_FONTS = { date:15, speaker:15, timer:14, title:16, price:15 };   // размеры шрифта по столбцам (px)
let COL_WIDTHS = { date:118, speaker:140, timer:110, title:0, price:130, action:240 }; // ширины столбцов (0=авто)
let VIEW = { density:"normal", theme:"light", start_hour:10, mode:"table", page_size:0, date_format:"D MMMM YYYY" };
// Конфиг колонок (порядок + видимость + подпись) — data-driven
let COLUMNS = [
  { key:"date", label:"Дата", visible:true },
  { key:"speaker", label:"Лектор", visible:true },
  { key:"timer", label:"До начала", visible:true },
  { key:"title", label:"Тема", visible:true },
  { key:"price", label:"Цена без подписки", visible:true },
  { key:"action", label:"Действия", visible:true },
];
let FIELD_MAP = {};        // сопоставление полей (применяется на сервере, тут для справки)
let CAT_OVERRIDES = {};    // переопределения цвета/подписи категорий
let PRESETS_SAVED = [];    // сохранённые пресеты фильтров
let pageLimit = 0;         // текущий лимит показа (для «показать ещё»)
const PRESETS = {
  compact:     { fonts:{date:13,speaker:13,timer:12,title:14,price:13}, density:"compact" },
  normal:      { fonts:{date:15,speaker:15,timer:14,title:16,price:15}, density:"normal" },
  large:       { fonts:{date:17,speaker:17,timer:16,title:19,price:17}, density:"comfortable" }
};
// пере-подгон шрифта тем при изменении ширины окна (с дебаунсом)
let _fitTimer=null;
window.addEventListener("resize", ()=>{ clearTimeout(_fitTimer); _fitTimer=setTimeout(()=>{ if(typeof fitTitles==="function") fitTitles(); }, 200); });

// Применяем размеры шрифта столбцов через CSS-переменные на :root
function applyColFonts(){
  const r=document.documentElement.style;
  r.setProperty('--fs-date',   (COL_FONTS.date||14)+'px');
  r.setProperty('--fs-speaker',(COL_FONTS.speaker||14)+'px');
  r.setProperty('--fs-timer',  (COL_FONTS.timer||13)+'px');
  r.setProperty('--fs-title',  (COL_FONTS.title||15)+'px');
  r.setProperty('--fs-price',  (COL_FONTS.price||14)+'px');
}
// Ширины столбцов
function applyColWidths(){
  const r=document.documentElement.style;
  const set=(k,def)=>{ const v=COL_WIDTHS[k]; r.setProperty('--w-'+k, (!v||v<=0)?(k==='title'?'auto':def):(v+'px')); };
  set('date','118px'); set('speaker','140px'); set('timer','110px'); set('title','auto'); set('price','130px'); set('action','240px');
}
// Вид: тема + плотность
function applyView(){
  const el=document.documentElement;
  el.setAttribute('data-theme', VIEW.theme==='dark'?'dark':'light');
  el.setAttribute('data-density', VIEW.density||'normal');
  el.setAttribute('data-mode', VIEW.mode==='cards'?'cards':'table');
  // иконка/подпись кнопки режима (показываем ТЕКУЩИЙ режим)
  const cards = VIEW.mode==='cards';
  const it=$("mode-icon-table"), ic2=$("mode-icon-cards"), ml=$("mode-label");
  if(it) it.style.display = cards?'none':'';
  if(ic2) ic2.style.display = cards?'':'none';
  if(ml) ml.textContent = cards?'Карточки':'Таблица';
  const ic=$("theme-icon");
  if(ic){ ic.innerHTML = VIEW.theme==='dark'
    ? '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>'  // солнце
    : '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>'; }                                                                     // луна
}
// Забрать настройки внешнего вида из ответа сервера
function ingestAppearance(o){
  if(!o) return;
  if(o.col_fonts && Object.keys(o.col_fonts).length) COL_FONTS=Object.assign(COL_FONTS,o.col_fonts);
  if(o.col_widths && Object.keys(o.col_widths).length) COL_WIDTHS=Object.assign(COL_WIDTHS,o.col_widths);
  if(o.view && Object.keys(o.view).length) VIEW=Object.assign(VIEW,o.view);
  if(Array.isArray(o.columns) && o.columns.length) COLUMNS=o.columns;
  if(o.field_map && Object.keys(o.field_map).length) FIELD_MAP=o.field_map;
  if(o.cat_overrides && Object.keys(o.cat_overrides).length) CAT_OVERRIDES=o.cat_overrides;
  if(Array.isArray(o.presets)) PRESETS_SAVED=o.presets;
}
// Итоговые данные категории с учётом переопределений цвета/подписи
function catInfo(k){
  const base = CATS[k]||CATS.other;
  const ov = CAT_OVERRIDES[k]||{};
  return { c: ov.color||base.c, bg: base.bg, label: ov.label||base.label, icon: base.icon };
}
// Применить весь внешний вид разом
function applyAllAppearance(){ applyColFonts(); applyColWidths(); applyView(); }

// Тема переносится на несколько строк (без авто-уменьшения). Функция оставлена
// пустой для обратной совместимости со старыми вызовами.
function fitTitles(){}
let sort = { key: "date", dir: "smart" };
let activeCat = "all";
let activePeriod = "all";   // all | upcoming | past | year:<YYYY>
const CUR_YEAR = new Date().getFullYear();
let searchTerm = "";
let timerInterval = null;

/* ---------- Категории ---------- */
const CATS = {
  zhkh:   {label:"ЖКХ",           c:"#1E6FA8", bg:"#E8F2FA", icon:iconBuilding},
  zdrav:  {label:"ЗДРАВ",         c:"#A8285A", bg:"#FCEAF1", icon:iconStetho},
  electro:{label:"ЭЛЕКТРО",       c:"#AA6A0F", bg:"#FDF3E0", icon:iconBolt},
  eco:    {label:"ЭКОЛОГИЯ",      c:"#2E7D32", bg:"#E8F5E9", icon:iconLeaf},
  build:  {label:"СТРОИТЕЛЬСТВО", c:"#5D4037", bg:"#EFEBE9", icon:iconHelmet},
  land:   {label:"ЗЕМЛЯ",         c:"#7A6A1F", bg:"#F5F1DC", icon:iconLand},
  goz:    {label:"ГОЗ",           c:"#3A26B5", bg:"#EDEAFE", icon:iconShield},
  gas:    {label:"ГАЗ",           c:"#0E7B7B", bg:"#E4F1F1", icon:iconFlame},
  other:  {label:"ПРОЧЕЕ",        c:"#6B7380", bg:"#EEF0F3", icon:iconDot}
};
const ALL_CAT_KEYS = ["zhkh","zdrav","electro","eco","build","land","goz","gas"];
function iconBuilding(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M4 21V8l8-5 8 5v13M9 21v-6h6v6"/></svg>';}
function iconStetho(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3v5a5 5 0 0 0 10 0V3M4 3h4M14 3h4M11 18a4 4 0 0 0 8 0v-2"/><circle cx="19" cy="14" r="2"/></svg>';}
function iconBolt(){return '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 2 4 14h6l-1 8 9-12h-6z"/></svg>';}
function iconLeaf(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 20A7 7 0 0 1 4 13c0-6 8-9 16-9 0 8-3 16-9 16zM4 20c3-3 6-5 9-6"/></svg>';}
function iconHelmet(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17a9 9 0 0 1 18 0M2 17h20v3H2zM10 3h2v5M14 4a6 6 0 0 1 4 5"/></svg>';}
function iconShield(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 5v6c0 5 3.5 9 8 11 4.5-2 8-6 8-11V5z"/><path d="m9 12 2 2 4-4"/></svg>';}
function iconLayers(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 2 9 5-9 5-9-5z"/><path d="m3 12 9 5 9-5M3 17l9 5 9-5"/></svg>';}
function iconDot(){return '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="5"/></svg>';}
function iconLand(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 20h20M4 20l4-9 4 5 3-7 5 11"/></svg>';}
function iconFlame(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2s5 4 5 9a5 5 0 0 1-10 0c0-2 1-3 1-3s2 1 2 3c0-3 2-6 2-9z"/></svg>';}
function iconPlay(){return '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';}
function iconMail(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>';}
function iconInvite(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/></svg>';}
function iconFilm(){return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 4v16M17 4v16M3 9h4M3 15h4M17 9h4M17 15h4"/></svg>';}

// Категория берётся строго из поля direction. Поддерживаем ключ (zhkh…) и
// человекочитаемое название (ЖКХ, Здравоохранение…). Неизвестное/пустое -> "other".
const DIR_ALIASES = {
  "жкх":"zhkh",
  "здрав":"zdrav","здравоохранение":"zdrav","медицина":"zdrav",
  "электро":"electro","электроэнергетика":"electro","энергетика":"electro","энерго":"electro","energy":"electro",
  "экология":"eco","эко":"eco",
  "строительство":"build","строй":"build",
  "земля":"land",
  "гоз":"goz","госрегулирование":"goz","госзакупки":"goz","гособоронзаказ":"goz",
  "газ":"gas"
};
function detectCat(w){
  const dir = (w.direction||"").toString().trim().toLowerCase();
  if (!dir) return "other";
  if (CATS[dir]) return dir;
  return DIR_ALIASES[dir] || "other";
}

/* ---------- API-помощник ---------- */
async function api(action, opts){
  opts = opts || {};
  const init = { method: opts.method || "GET", headers: {} };
  if (opts.body){
    init.method = opts.method || "POST";
    init.headers["Content-Type"] = "application/json";
    init.headers["X-CSRF-Token"] = CSRF;
    init.body = JSON.stringify(Object.assign({ csrf: CSRF }, opts.body));
  }
  const res = await fetch(API + action, init);
  let data = {};
  try { data = await res.json(); } catch(e){}
  if (!res.ok && !data.error) data.error = "Ошибка сервера (" + res.status + ")";
  return data;
}

/* ---------- Даты / формат ---------- */
function parseDate(iso){
  const d = new Date(iso + "T00:00:00");
  if (isNaN(d)) return { full: esc(iso), day:esc(iso), month:"", year:"", ts: 0 };
  const dd = String(d.getDate()).padStart(2,"0");
  const mm = MONTHS[d.getMonth()];
  const yy = d.getFullYear();
  return { full: `${dd} ${mm} ${yy} г.`, day: dd, month: mm, year: String(yy), ts: d.getTime() };
}
function fmtRuDate(iso){ const m=(iso||"").match(/^(\d{4})-(\d{2})-(\d{2})$/); return m?`${m[3]}.${m[2]}.${m[1]}`:(iso||"—"); }
function fmtPrice(p){ const n=Number(p); if(!n||n<=0) return '<span class="price price--free">Бесплатно</span>'; return `<span class="price">${n.toLocaleString("ru-RU")} <small>₽</small></span>`; }

/* ---------- Права по категориям ---------- */
function userAllowsAll(){ return !currentUser || (currentUser.categories||[]).includes("all"); }
function isCatAllowed(cat){ return userAllowsAll() || (currentUser.categories||[]).includes(cat); }
function isExpired(u){ if(!u||!u.expires) return false; const d=new Date(u.expires+"T23:59:59"); return !isNaN(d)&&d.getTime()<Date.now(); }
function daysLeft(iso){ if(!iso)return null; const d=new Date(iso+"T23:59:59"); if(isNaN(d))return null; return Math.ceil((d.getTime()-Date.now())/86400000); }
function plural(n,one,few,many){ const m10=n%10,m100=n%100; if(m10===1&&m100!==11)return one; if(m10>=2&&m10<=4&&(m100<10||m100>=20))return few; return many; }

/* ---------- Таймер обратного отсчёта ---------- */
// Смещение старта в мс: из поля time вебинара ("HH:MM") или из настройки VIEW.start_hour
function startOffsetMs(timeStr){
  if (timeStr && /^\d{1,2}:\d{2}$/.test(timeStr)){
    const [h,m]=timeStr.split(":").map(Number);
    return (h*3600 + m*60)*1000;
  }
  return (VIEW.start_hour||10)*3600*1000;
}
function countdownHtml(ts, timeStr){
  const now = Date.now();
  const start = ts + startOffsetMs(timeStr);          // час старта дня вебинара
  const end   = ts + 24*3600*1000;                   // до конца дня — «идёт»
  if (now >= start && now < end) return '<span class="timer--live">Идёт сейчас</span>';
  const diff = start - now;
  if (diff <= 0) return '<span class="timer__lbl">завершён</span>';
  const days = Math.floor(diff/86400000);
  const hrs  = Math.floor((diff%86400000)/3600000);
  const mins = Math.floor((diff%3600000)/60000);
  let big, cls = "";
  if (days >= 1){ big = `${days} ${plural(days,"день","дня","дней")} ${hrs} ч`; }
  else if (hrs >= 1){ big = `${hrs} ч ${mins} мин`; cls = "timer--soon"; }
  else { big = `${mins} мин`; cls = "timer--soon"; }
  return `<span class="timer ${cls}"><span class="timer__big">${big}</span><span class="timer__lbl">до начала</span></span>`;
}
function startTimers(){
  if (timerInterval) clearInterval(timerInterval);
  timerInterval = setInterval(() => {
    document.querySelectorAll("[data-ts]").forEach(el => {
      el.innerHTML = countdownHtml(Number(el.dataset.ts), el.dataset.time||"");
    });
  }, 30000); // обновление раз в 30 c
}

/* ---------- Рендер строк ---------- */
function catBadge(k){ const c=CATS[k]||CATS.other; return `<span class="cat-badge" style="--c:${c.c};--cbg:${c.bg}">${c.icon()}${esc(c.label)}</span>`; }

function actionsCell(w, isPast){
  const btns = [];
  // И для прошедших, и для будущих кнопка ведёт на link_participant —
  // на этой странице уже собраны все действия (смотреть онлайн, запись, материалы).
  const label = isPast ? "Смотреть запись" : "Смотреть вебинар";
  if (w.link_participant) btns.push(`<a class="btn btn--watch" href="${esc(w.link_participant)}" target="_blank" rel="noopener" data-watch="${esc(w.id)}">${iconPlay()}${label}</a>`);
  if (!isPast && feat("btn_access")) btns.push(`<button class="btn btn--soft" data-mail="access" data-id="${esc(w.id)}" title="Отправить доступ на email">${iconMail()}Отправить на email</button>`);
  if (feat("btn_invite")) btns.push(`<button class="btn btn--soft" data-mail="invite" data-id="${esc(w.id)}" title="Пригласить на вебинар">${iconInvite()}Пригласить на вебинар</button>`);
  return `<div class="actions">${btns.join("")||'<span class="no-link">—</span>'}</div>`;
}
function viewedBadge(id){
  if (!feat("viewed_badge")) return "";
  return MY_VIEWS.has(String(id)) ? `<span class="viewed-badge" title="Вы открывали этот вебинар"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>просмотрен</span>` : "";
}

// маленький цветной ярлык категории рядом с темой (само направление выбирается в чипах)
function miniCat(k){ const c=CATS[k]||CATS.other; return `<span class="mini-cat" style="--c:${c.c};--cbg:${c.bg}" title="${esc(c.label)}">${c.icon()}</span>`; }

function applySort(list){
  // Спец-режим по умолчанию: «сначала ближайшие» — предстоящие по возрастанию даты,
  // затем прошедшие по убыванию (самые свежие прошедшие выше).
  if(sort.key==="date" && sort.dir==="smart"){
    const now=TODAY.getTime();
    return [...list].sort((a,b)=>{
      const af=a._d.ts>=now, bf=b._d.ts>=now;
      if(af&&bf) return a._d.ts-b._d.ts;      // будущие: ближайший выше
      if(!af&&!bf) return b._d.ts-a._d.ts;    // прошедшие: свежие выше
      return af?-1:1;                          // будущие всегда выше прошедших
    });
  }
  const {key,dir}=sort, mul=dir==="asc"?1:-1;
  return [...list].sort((a,b)=>{
    let av,bv;
    if(key==="date"){av=a._d.ts;bv=b._d.ts;}
    else if(key==="price"){av=Number(a.price)||0;bv=Number(b.price)||0;}
    else{av=(a[key]||"").toLowerCase();bv=(b[key]||"").toLowerCase();}
    if(av<bv)return -1*mul; if(av>bv)return 1*mul; return a._d.ts-b._d.ts;
  });
}
function matchesSearch(w){ if(!searchTerm)return true; const t=searchTerm.toLowerCase(); return (w.title||"").toLowerCase().includes(t)||(w.speaker||"").toLowerCase().includes(t); }
function matchesCat(w){ if(!isCatAllowed(w._cat))return false; return activeCat==="all"||w._cat===activeCat; }

function buildChips(){
  const visible = ALL.filter(w=>isCatAllowed(w._cat));
  const counts={}; visible.forEach(w=>counts[w._cat]=(counts[w._cat]||0)+1);
  const allowed = ALL_CAT_KEYS.concat("other").filter(k=>counts[k]);
  let html="";
  if(userAllowsAll()||allowed.length>1){
    html+=`<button class="chip ${activeCat==='all'?'active':''}" data-cat="all">${iconLayers()}Все<span class="cnt">${visible.length}</span></button>`;
  }
  allowed.forEach(k=>{const c=catInfo(k); html+=`<button class="chip ${activeCat===k?'active':''}" data-cat="${k}" style="--c:${c.c}">${c.icon()}${esc(c.label)}<span class="cnt">${counts[k]}</span></button>`;});
  $("chips").innerHTML=html;
  $("chips").querySelectorAll(".chip").forEach(b=>b.addEventListener("click",()=>{activeCat=b.dataset.cat;pageLimit=0;buildChips();buildPeriods();render();}));
}

// --- Пользовательские пресеты фильтров ---
function buildPresets(){
  const box=$("presets-bar"); if(!box) return;
  const isAdmin = currentUser && currentUser.role==="admin";
  let html = PRESETS_SAVED.map((p,i)=>`<button class="preset-chip" data-preset-i="${i}" title="${esc(periodLabel(p.period))} · ${esc(catLabel(p.cat))}${p.search?(' · «'+esc(p.search)+'»'):''}">${esc(p.name)}${isAdmin?`<span class="preset-del" data-del-i="${i}" title="Удалить">×</span>`:''}</button>`).join("");
  if(isAdmin) html += `<button class="preset-chip preset-add" id="preset-add" title="Сохранить текущий фильтр как пресет">+ пресет</button>`;
  box.innerHTML = html;
  box.querySelectorAll("[data-preset-i]").forEach(b=>b.addEventListener("click",(e)=>{
    if(e.target.classList.contains("preset-del")) return;
    applyPreset(PRESETS_SAVED[+b.dataset.presetI]);
  }));
  box.querySelectorAll("[data-del-i]").forEach(b=>b.addEventListener("click",async(e)=>{
    e.stopPropagation();
    PRESETS_SAVED.splice(+b.dataset.delI,1);
    await api("presets_save",{body:{presets:PRESETS_SAVED}}); buildPresets(); toast("Пресет удалён");
  }));
  const add=$("preset-add");
  if(add) add.addEventListener("click", async()=>{
    const name=prompt("Название пресета:", catLabel(activeCat)+" · "+periodLabel(activePeriod));
    if(!name) return;
    PRESETS_SAVED.push({name:name.trim(), period:activePeriod, cat:activeCat, search:searchTerm});
    const r=await api("presets_save",{body:{presets:PRESETS_SAVED}});
    if(r.ok){ buildPresets(); toast("Пресет сохранён"); }
  });
}
function periodLabel(p){ return p==="all"?"Все":p==="upcoming"?"Предстоящие":p==="past"?"Прошедшие":(p&&p.indexOf("year:")===0?"Вебинары "+p.split(":")[1]:p); }
function catLabel(k){ return k==="all"?"Все направления":(catInfo(k).label); }
function applyPreset(p){
  if(!p) return;
  activePeriod=p.period||"all"; activeCat=p.cat||"all"; searchTerm=p.search||"";
  const si=$("search"); if(si) si.value=searchTerm;
  pageLimit=0; buildChips(); buildPeriods(); render();
  toast("Фильтр: "+esc(p.name));
}

// К какому периоду относится вебинар
function webinarPeriod(w){
  const y = new Date(w._d.ts).getFullYear();
  if (w._d.ts >= TODAY.getTime()) return "upcoming";
  if (y < CUR_YEAR) return "year:" + y;   // прошлые годы
  return "past";                            // прошедшие в этом году
}

// Построить переключатель периодов (Ближайшие / Прошедшие / Вебинары 202X)
function buildPeriods(){
  const expired = isExpired(currentUser);
  const src = expired ? [] : ALL.filter(w=>isCatAllowed(w._cat));
  const cnt = { upcoming:0, past:0 };
  const years = {};   // прошлые годы
  src.forEach(w=>{
    const p = webinarPeriod(w);
    if (p==="upcoming") cnt.upcoming++;
    else if (p==="past") cnt.past++;
    else { const y=p.split(":")[1]; years[y]=(years[y]||0)+1; }
  });
  // доступные периоды (для проверки активного)
  const avail = ["all","upcoming","past"].concat(Object.keys(years).sort().reverse().map(y=>"year:"+y));
  if (!avail.includes(activePeriod)) activePeriod = "all";

  const tab = (key,label,n,dotCls)=>`<button class="period ${activePeriod===key?'active':''}" data-period="${key}"><span class="period__dot ${dotCls}"></span>${esc(label)}<span class="period__cnt">${n}</span></button>`;
  // «Все» по умолчанию — показывает Ближайшие + Прошедшие сразу
  let html = tab("all","Все",cnt.upcoming+cnt.past,"dot-up");
  html += tab("upcoming","Предстоящие",cnt.upcoming,"dot-up");
  if (appSettings.show_past) html += tab("past","Прошедшие",cnt.past,"dot-past");
  // прошлые годы (по убыванию)
  Object.keys(years).sort().reverse().forEach(y=>{ html += tab("year:"+y, "Вебинары "+y, years[y], "dot-year"); });

  const box=$("periods"); if(!box) return;
  box.innerHTML = html;
  box.querySelectorAll(".period").forEach(b=>b.addEventListener("click",()=>{ activePeriod=b.dataset.period; pageLimit=0; buildPeriods(); render(); }));
}

// Заголовок/точка для периода

// Построить HTML одной секции (таблицы) для заданного периода
// Единая таблица (без деления на секции). isRowPast(w) решает, «прошедшая» ли строка.
// Форматирование даты по шаблону (D, DD, M, MM, MMMM, YYYY, YY)
function fmtDate(w, tpl){
  tpl = tpl || VIEW.date_format || "D MMMM YYYY";
  const d = new Date(w._d.ts); if(isNaN(d)) return esc(w._d.full||"");
  const day=d.getDate(), mon=d.getMonth(), yr=d.getFullYear();
  const map = {
    "MMMM": MONTHS[mon],
    "MM": String(mon+1).padStart(2,"0"),
    "M": String(mon+1),
    "DD": String(day).padStart(2,"0"),
    "D": String(day),
    "YYYY": String(yr),
    "YY": String(yr).slice(-2)
  };
  return tpl.replace(/MMMM|MM|M|DD|D|YYYY|YY/g, t=>map[t]);
}

// ===== Реестр колонок (data-driven). Добавить колонку = добавить сюда запись. =====
const COL_DEFS = {
  date: {
    sortable:true,
    head:(label)=>label||"Дата",
    cell:(w)=>`<span class="date-box"><span class="date-d">${w._d.day}</span><span class="date-m">${w._d.month}</span><span class="date-y">${w._d.year}</span></span>`
  },
  speaker: {
    sortable:true, cls:"speaker",
    head:(label)=>label||"Лектор",
    cell:(w)=>esc(w.speaker)
  },
  timer: {
    sortable:false,
    head:(label)=>label||"До начала",
    cell:(w,isPast)=> (isPast||!feat("timer")) ? '<span class="timer__lbl">—</span>'
      : `<span data-ts="${w._d.ts}" data-time="${esc(w.time||'')}">${countdownHtml(w._d.ts, w.time)}</span>`
  },
  title: {
    sortable:true, cls:"title",
    head:(label)=>label||"Тема",
    cell:(w)=>`<div class="title-wrap">${miniCat(w._cat)}<span class="title-text">${esc(w.title)}</span></div>`
  },
  price: {
    sortable:true,
    head:(label)=>label||"Цена без подписки",
    cell:(w)=>`<div class="price-cell">${fmtPrice(w.price)}${viewedBadge(w.id)}</div>`
  },
  action: {
    sortable:false,
    head:(label)=>label||"Действия",
    cell:(w,isPast)=>actionsCell(w,isPast)
  }
};
// Видимые колонки в заданном порядке (с учётом тумблера timer)
function visibleColumns(){
  return COLUMNS.filter(c=>{
    if(c.visible===false) return false;
    if(c.key==="timer" && !feat("timer")) return false;
    return COL_DEFS[c.key];
  });
}
function tableHead(){
  const RS='<span class="col-resizer"></span>';
  const ths = visibleColumns().map(c=>{
    const def=COL_DEFS[c.key];
    const sortAttr = def.sortable ? ` sortable" data-sort="${c.key}` : '"';
    // формируем class="col-KEY sortable" аккуратно
    const cls = `col-${c.key}${def.sortable?' sortable':''}`;
    const arrow = def.sortable ? ' <span class="arrow"></span>' : '';
    return `<th class="${cls}" data-col="${c.key}"${def.sortable?` data-sort="${c.key}"`:''}>${esc(def.head(c.label))}${arrow}${RS}</th>`;
  }).join("");
  return `<thead><tr>${ths}</tr></thead>`;
}
function rowHtml(w, isPast){
  const c = catInfo(w._cat);
  const tds = visibleColumns().map(col=>{
    const def=COL_DEFS[col.key];
    return `<td class="col-${col.key}" data-label="${esc(def.head(col.label))}">${def.cell(w,isPast)}</td>`;
  }).join("");
  return `<tr data-cat="${w._cat}" style="--rc:${c.c};--rc-bg:${c.bg}">${tds}</tr>`;
}
// ===== Карточный режим =====
function cardHtml(w, isPast){
  const c=catInfo(w._cat);
  const timer = (!isPast && feat("timer")) ? `<div class="wc__timer"><span data-ts="${w._d.ts}" data-time="${esc(w.time||'')}">${countdownHtml(w._d.ts, w.time)}</span></div>` : '';
  return `<article class="wcard" data-cat="${w._cat}" style="--rc:${c.c};--rc-bg:${c.bg}">
    <div class="wcard__top">
      <span class="wcard__cat" style="--c:${c.c};--cbg:${c.bg}">${c.icon()}${esc(c.label)}</span>
      <span class="wcard__date">${fmtDate(w)}</span>
    </div>
    <h3 class="wcard__title">${esc(w.title)}${viewedBadge(w.id)}</h3>
    <div class="wcard__meta"><span class="wcard__spk">🎓 ${esc(w.speaker)}</span> ${timer}</div>
    <div class="wcard__price">${fmtPrice(w.price)}</div>
    <div class="wcard__actions">${actionsCell(w,isPast)}</div>
  </article>`;
}

function renderPanelInner(rows){
  if(VIEW.mode==="cards"){
    const body = rows.length ? `<div class="wcards">${rows.map(w=>cardHtml(w, w._d.ts<TODAY.getTime())).join("")}</div>`
      : `<div class="state">Нет вебинаров по выбранному фильтру.</div>`;
    return body;
  }
  const colspan = visibleColumns().length || 6;
  const body = rows.length
    ? rows.map(w=>rowHtml(w, w._d.ts < TODAY.getTime())).join("")
    : `<tr><td colspan="${colspan}" class="state">Нет вебинаров по выбранному фильтру.</td></tr>`;
  return `<div class="table-scroll"><table>${tableHead()}<tbody>${body}</tbody></table></div>`;
}
function renderTable(allRows, heading, dotCls){
  // пагинация «показать ещё»
  const total = allRows.length;
  const ps = VIEW.page_size|0;
  const limit = ps>0 ? (pageLimit||ps) : total;
  const rows = ps>0 ? allRows.slice(0, limit) : allRows;
  const moreBtn = (ps>0 && total>rows.length)
    ? `<div class="more-wrap"><button class="mbtn more-btn" id="show-more">Показать ещё (${total-rows.length})</button></div>` : '';
  return `<section class="panel">
    <div class="panel__head">
      <h2 class="panel__title"><span class="dot ${dotCls}"></span>${esc(heading)}</h2>
      <span class="count">Показано: ${rows.length} из ${total}</span>
    </div>
    ${renderPanelInner(rows)}
    ${moreBtn}
  </section>`;
}

function render(){
  const box=$("panels"); if(!box) return;
  const expired = isExpired(currentUser);
  if(expired){
    box.innerHTML = `<div class="panel"><div class="state">Доступ к вебинарам закрыт: подписка истекла.</div></div>`;
    bindMailButtons(); bindSortHeaders(); bindResizers(); updateSortHeaders(); return;
  }
  // фильтр по периоду
  let source = ALL.filter(w=>matchesSearch(w)&&matchesCat(w));
  if(activePeriod!=="all") source = source.filter(w=>webinarPeriod(w)===activePeriod);

  const rows = applySort(source);
  const meta = { all:{h:"Все вебинары",dot:"dot-up"}, upcoming:{h:"Предстоящие вебинары",dot:"dot-up"}, past:{h:"Прошедшие вебинары",dot:"dot-past"} };
  let m = meta[activePeriod];
  if(!m && activePeriod.indexOf("year:")===0) m={h:"Вебинары "+activePeriod.split(":")[1],dot:"dot-year"};
  if(!m) m={h:"Все вебинары",dot:"dot-up"};

  box.innerHTML = renderTable(rows, m.h, m.dot);

  on("show-more","click",()=>{ pageLimit=(pageLimit||VIEW.page_size)+VIEW.page_size; render(); });
  bindMailButtons();
  bindSortHeaders();
  bindResizers();
  updateSortHeaders();
}

/* ---- Ресайз колонок мышью/тачем (как в Excel) ---- */
function bindResizers(){
  document.querySelectorAll("th .col-resizer").forEach(h=>{
    if(h._rsBound) return; h._rsBound=true;
    // клик по ручке не должен запускать сортировку
    h.addEventListener("click", e=>e.stopPropagation());
    const startDrag = (ev)=>{
      ev.preventDefault(); ev.stopPropagation();
      const th = h.closest("th");
      const key = th.dataset.col;
      if(!key) return;
      const startX = (ev.touches?ev.touches[0].clientX:ev.clientX);
      const startW = th.getBoundingClientRect().width;
      th.classList.add("resizing");
      document.body.classList.add("col-resizing");

      const move = (e)=>{
        const x = (e.touches?e.touches[0].clientX:e.clientX);
        let w = Math.round(startW + (x - startX));
        w = Math.max(60, Math.min(600, w));       // те же пределы, что на сервере
        COL_WIDTHS[key] = w;
        applyColWidths();
      };
      const up = ()=>{
        th.classList.remove("resizing");
        document.body.classList.remove("col-resizing");
        document.removeEventListener("mousemove", move);
        document.removeEventListener("mouseup", up);
        document.removeEventListener("touchmove", move);
        document.removeEventListener("touchend", up);
        saveColWidths();   // сохраняем как значения по умолчанию (для админа)
      };
      document.addEventListener("mousemove", move);
      document.addEventListener("mouseup", up);
      document.addEventListener("touchmove", move, {passive:false});
      document.addEventListener("touchend", up);
    };
    h.addEventListener("mousedown", startDrag);
    h.addEventListener("touchstart", startDrag, {passive:false});
  });
}
// сохранить ширины на сервере (только админ; у пользователя изменение останется визуально до перезагрузки)
let _cwSaveTimer=null;
function saveColWidths(){
  // обновим поля в настройках, если открыты
  ["date","speaker","timer","title","price","action"].forEach(k=>{ const el=$("cw-"+k); if(el) el.value=(COL_WIDTHS[k]??"")===0?0:(COL_WIDTHS[k]??""); });
  if(!currentUser || currentUser.role!=="admin") return;
  clearTimeout(_cwSaveTimer);
  _cwSaveTimer=setTimeout(async()=>{
    const r=await api("colwidths_save",{body:{col_widths:COL_WIDTHS}});
    if(r&&r.ok){ COL_WIDTHS=Object.assign(COL_WIDTHS, r.col_widths||{}); applyColWidths(); toast("Ширина колонок сохранена"); }
  }, 400);   // дебаунс, чтобы не слать запрос на каждый пиксель
}

function bindMailButtons(){
  document.querySelectorAll("[data-mail]").forEach(b=>b.addEventListener("click",()=>openMail(b.dataset.mail, b.dataset.id)));
  // Логируем просмотр при клике «Смотреть»/«Запись» (ссылка открывается в новой вкладке)
  document.querySelectorAll("[data-watch]").forEach(a=>a.addEventListener("click",()=>{
    const id=a.dataset.watch;
    api("log_view",{body:{webinar_id:id}}).then(()=>{
      if(!MY_VIEWS.has(String(id))){ MY_VIEWS.add(String(id)); render(); }
    });
  }));
}

function updateSortHeaders(){
  const sk=$("sort-key"); if(sk) sk.value=sort.key;
  const sd=$("sort-dir"); if(sd){
    sd.classList.toggle("desc", sort.dir==="desc");
    sd.title = sort.dir==="smart"?"Сначала ближайшие":(sort.dir==="asc"?"По возрастанию":"По убыванию");
  }
  document.querySelectorAll("th.sortable").forEach(th=>{
    const ar=th.querySelector(".arrow");
    if(th.dataset.sort===sort.key){th.classList.add("sorted"); if(ar) ar.textContent=sort.dir==="asc"?"▲":(sort.dir==="desc"?"▼":"★");}
    else{th.classList.remove("sorted"); if(ar) ar.textContent="↕";}
  });
}
// Навешиваем клики на заголовки таблиц (таблицы пересоздаются при каждом render)
function bindSortHeaders(){
  document.querySelectorAll("th.sortable").forEach(th=>{
    if(th._sortBound) return; th._sortBound=true;
    th.addEventListener("click",()=>{
      const k=th.dataset.sort;
      if(sort.key===k && sort.dir!=="smart")sort.dir=sort.dir==="asc"?"desc":"asc"; else{sort.key=k;sort.dir="asc";}
      render();
    });
  });
}
function setSort(k,d){if(k)sort.key=k;if(d)sort.dir=d;render();}
on("sort-key","change",e=>setSort(e.target.value,null));
on("sort-dir","click",()=>{
  if(sort.key==="date"){ sort.dir = sort.dir==="smart"?"asc":(sort.dir==="asc"?"desc":"smart"); }
  else { sort.dir = sort.dir==="asc"?"desc":"asc"; }
  render();
});
on("search","input",e=>{searchTerm=e.target.value.trim();pageLimit=0;render();});

/* ---------- Тост ---------- */
let toastTimer=null;
function toast(msg, isErr){
  const t=$("toast"); t.textContent=msg; t.classList.toggle("err",!!isErr); t.classList.add("show");
  clearTimeout(toastTimer); toastTimer=setTimeout(()=>t.classList.remove("show"),3200);
}

/* ---------- Вход/выход ---------- */
on("login-form","submit", async(e)=>{
  e.preventDefault();
  $("login-err").textContent="";
  const r=await api("login",{body:{login:$("li-login").value.trim(),password:$("li-pass").value}});
  if(!r.ok){$("login-err").textContent=r.error||"Ошибка входа.";return;}
  CSRF=r.csrf||CSRF; currentUser=r.user;
  // подтянуть актуальные тумблеры функций
  const me=await api("me");
  if(me&&me.features)FEATURES=me.features;
  ingestAppearance(me);
  if(me&&typeof me.show_past!=="undefined")appSettings.show_past=me.show_past!==false;
  applyAllAppearance();
  enterApp();
});
on("logout","click", async()=>{
  await api("logout",{body:{}});
  currentUser=null;
  $("app").classList.add("hidden");
  $("login-screen").classList.remove("hidden");
  $("li-login").value="";$("li-pass").value="";$("login-err").textContent="";
});

async function enterApp(){
  setHidden("login-screen", true);
  setHidden("app", false);
  const isAdmin = currentUser.role==="admin";
  setHidden("gear", !isAdmin);                              // пользователям — без шестерёнки
  const ub=$("userbar"); if(ub) ub.classList.toggle("at-edge", !isAdmin);
  setHidden("myviews", !(feat("my_views") || isAdmin));     // кнопка «Мои просмотры»
  const timerHead=$("th-timer"); if(timerHead) timerHead.style.display = feat("timer") ? "" : "none";
  const exp = currentUser.expires?`подписка до ${fmtRuDate(currentUser.expires)}`:"";
  const roleTxt = isAdmin?"Администратор":"Подписчик";
  setHtml("who", `${esc(currentUser.org||currentUser.login)}<small>${roleTxt}${exp?" · "+exp:""}</small>`);
  const expired=isExpired(currentUser);
  setHidden("expired-note", !expired);
  if(expired) setText("expired-text", `Срок действия подписки истёк ${fmtRuDate(currentUser.expires)}. Доступ к вебинарам закрыт — обратитесь к администратору для продления.`);
  const soon=daysLeft(currentUser.expires);
  const showSoon = feat("expiry_warn") && !expired && soon!==null && soon<=14;
  setHidden("soon-note", !showSoon);
  if(showSoon) setText("soon-text", soon<=0 ? `Подписка заканчивается сегодня (${fmtRuDate(currentUser.expires)}).` : `Ваша подписка заканчивается через ${soon} ${plural(soon,"день","дня","дней")} — ${fmtRuDate(currentUser.expires)}. Продлите её у администратора.`);
  await loadWebinars();
  if(isAdmin) await loadSettingsIntoForm();
  startTimers();
}

async function loadWebinars(fresh){
  const st=$("state-main");
  if(st){ st.hidden=false; st.classList.remove("state--error"); st.innerHTML='<span class="spinner"></span>Загружаем расписание…'; }
  const r=await api("webinars"+(fresh?"&fresh=1":""));
  if(!r.ok){ if(st){ st.hidden=false; st.classList.add("state--error"); st.innerHTML=esc(r.error||"Не удалось загрузить вебинары."); } return; }
  CABINET=r.cabinet||"";
  MY_VIEWS=new Set((r.my_views||[]).map(String));
  ALL=(r.webinars||[]).map(w=>({...w,_d:parseDate(w.date),_cat:detectCat(w)}));
  buildChips(); buildPeriods(); buildPresets(); render();
}
// Кнопка «Обновить» — сбрасывает кэш источника и перечитывает
on("refresh","click", async()=>{
  const b=$("refresh"); b.classList.add("spinning");
  await loadWebinars(true);
  setTimeout(()=>b.classList.remove("spinning"),500);
  toast("Список обновлён");
});
// Переключатель режима таблица/карточки (сохраняется у админа)
on("mode-toggle","click", async()=>{
  VIEW.mode = VIEW.mode==="cards" ? "table" : "cards";
  applyView(); render();
  if(currentUser && currentUser.role==="admin"){ await api("view_save",{body:{view:VIEW}}); }
});

/* ============================================================
   =================  МОДАЛКА НАСТРОЕК  ======================
   ============================================================ */
const overlay=$("modal-overlay");
function switchTab(name){
  document.querySelectorAll("#tabs .tab").forEach(t=>t.classList.toggle("active",t.dataset.tab===name));
  document.querySelectorAll(".tabpane").forEach(p=>p.classList.toggle("active",p.id==="pane-"+name));
  if(name==="users")renderUsersTable();
  if(name==="add")resetBulk();
  if(name==="features")loadFeaturesForm();
  if(name==="view")loadViewForm();
  if(name==="logs"){loadLogStats();loadLogTable();loadRetentionForm();}
  if(name==="help")fillHelpExample();
}
// Пример JSON для вкладки-справки
function fillHelpExample(){
  const el=$("help-json-example"); if(!el) return;
  const example=[
    {id:1, date:"2026-08-05", time:"10:00", speaker:"Кадыров Ф.Н.",
     title:"Правила оказания платных медицинских услуг: изменения 2026",
     price:15600, direction:"ЗДРАВ",
     link_participant:"https://edu.vsesem.ru/2026/9wku.html"},
    {id:2, date:"2026-08-12", speaker:"Нифонтов Д.Ю.",
     title:"Обслуживание газового оборудования в ЖКХ: новая методика",
     price:0, direction:"ГАЗ",
     link_participant:"https://edu.vsesem.ru/2026/17fv.html"}
  ];
  el.textContent = JSON.stringify(example, null, 2);
}
on("help-copy","click",()=>{
  const t=$("help-json-example"); if(!t) return;
  navigator.clipboard?.writeText(t.textContent).then(()=>toast("Пример скопирован"),()=>toast("Не удалось скопировать",true));
});
function openSettings(){ overlay.classList.add("open"); overlay.setAttribute("aria-hidden","false"); switchTab("smtp"); }
function closeSettings(){ overlay.classList.remove("open"); overlay.setAttribute("aria-hidden","true"); }
on("gear","click",openSettings);
on("modal-close","click",closeSettings);
document.querySelectorAll("#tabs .tab").forEach(t=>t.addEventListener("click",()=>switchTab(t.dataset.tab)));
overlay.addEventListener("click",e=>{if(e.target===overlay)closeSettings();});
document.addEventListener("keydown",e=>{if(e.key==="Escape"){[overlay,uOverlay,mailOverlay].forEach(o=>{o.classList.remove("open");o.setAttribute("aria-hidden","true");});}});

async function loadSettingsIntoForm(){
  const r=await api("settings_get");
  if(!r.ok)return;
  const s=r.settings||{}; const sm=s.smtp||{};
  appSettings.show_past = s.show_past!==false;
  $("sm-host").value=sm.host||""; $("sm-port").value=sm.port||465; $("sm-secure").value=sm.secure||"ssl";
  $("sm-user").value=sm.user||""; $("sm-from").value=sm.from_email||""; $("sm-name").value=sm.from_name||"ЦМК-Подписка";
  $("sm-pass").placeholder = sm.pass_set ? "•••••• (задан, оставьте пустым)" : "";
  $("src-url").value=s.source_url||""; $("show-past").checked=appSettings.show_past;
}
on("save-smtp","click", async()=>{
  $("smtp-err").textContent="";
  const body={smtp:{host:$("sm-host").value.trim(),port:$("sm-port").value,secure:$("sm-secure").value,user:$("sm-user").value.trim(),pass:$("sm-pass").value,from_email:$("sm-from").value.trim(),from_name:$("sm-name").value.trim()}};
  const r=await api("settings_save",{body});
  if(!r.ok){$("smtp-err").textContent=r.error||"Ошибка сохранения.";return;}
  $("sm-pass").value=""; toast("Настройки почты сохранены"); loadSettingsIntoForm();
});
on("save-source","click", async()=>{
  const r=await api("settings_save",{body:{source_url:$("src-url").value.trim(),show_past:$("show-past").checked}});
  if(!r.ok){toast(r.error||"Ошибка",true);return;}
  appSettings.show_past=$("show-past").checked; toast("Сохранено"); render();
});

/* ============================================================
   =================  АДМИН: ПОЛЬЗОВАТЕЛИ  ===================
   ============================================================ */
let USERS_CACHE=[]; let userFilter="";
function keyToLabel(k){return k==="all"?"Все":(CATS[k]?CATS[k].label:k);}
function catTags(cats){ if((cats||[]).includes("all"))return `<span class="t" style="background:var(--brand-soft);color:var(--brand-dark)">Все</span>`; return (cats||[]).map(k=>{const c=CATS[k]||CATS.other;return `<span class="t" style="background:${c.bg};color:${c.c}">${esc(keyToLabel(k))}</span>`;}).join(" "); }

async function renderUsersTable(){
  const r=await api("users_list");
  if(!r.ok){$("users-tbody").innerHTML=`<tr><td colspan="7">${esc(r.error||"Ошибка")}</td></tr>`;return;}
  USERS_CACHE=r.users||[];
  drawUsers();
}
function drawUsers(){
  const list=USERS_CACHE.filter(u=>{if(!userFilter)return true;const t=userFilter.toLowerCase();return (u.login||"").toLowerCase().includes(t)||(u.org||"").toLowerCase().includes(t);});
  $("users-tbody").innerHTML=list.map(u=>{
    const expired=isExpired(u);
    const roleP=u.role==="admin"?'<span class="pill pill--admin">админ</span>':'<span class="pill pill--user">польз.</span>';
    const statP=expired?'<span class="pill pill--exp">истекла</span>':'<span class="pill pill--ok">активна</span>';
    return `<tr>
      <td class="mono">${esc(u.login)}</td>
      <td>${esc(u.org||"—")}</td>
      <td>${esc(u.email||"—")}</td>
      <td>${roleP}</td>
      <td><div class="cat-tags">${catTags(u.categories)}</div></td>
      <td>${fmtRuDate(u.expires)} ${statP}</td>
      <td style="white-space:nowrap">
        <button class="icon-btn" data-uviews="${esc(u.login)}" title="Просмотры пользователя"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg></button>
        <button class="icon-btn" data-edit="${esc(u.login)}" title="Изменить"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button>
        <button class="icon-btn" data-del="${esc(u.login)}" title="Удалить"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg></button>
      </td></tr>`;
  }).join("")||`<tr><td colspan="7" style="text-align:center;color:var(--muted);padding:20px">Пользователей нет.</td></tr>`;
  $("users-tbody").querySelectorAll("[data-edit]").forEach(b=>b.addEventListener("click",()=>openUserForm(b.dataset.edit)));
  $("users-tbody").querySelectorAll("[data-del]").forEach(b=>b.addEventListener("click",()=>delUser(b.dataset.del)));
  $("users-tbody").querySelectorAll("[data-uviews]").forEach(b=>b.addEventListener("click",()=>openUserViews(b.dataset.uviews)));
}
on("user-search","input",e=>{userFilter=e.target.value.trim();drawUsers();});
async function delUser(login){
  const u=USERS_CACHE.find(x=>x.login===login);
  if(!confirm(`Удалить пользователя «${u?u.org||u.login:login}» (${login})?`))return;
  const r=await api("user_delete",{body:{login}});
  if(!r.ok){toast(r.error||"Ошибка",true);return;}
  toast("Пользователь удалён"); renderUsersTable();
}

/* Форма пользователя */
const uOverlay=$("user-overlay"); let editingLogin=null;
function genLoginLocal(){const l="abcdefghijklmnopqrstuvwxyz";let s="";for(let i=0;i<4;i++)s+=l[Math.floor(Math.random()*26)];return s;}
function genPassLocal(){const d="23456789",u="ABCDEFGHJKLMNPQRSTUVWXYZ",lo="abcdefghijkmnpqrstuvwxyz",sy="!#$%*+-?",all=d+u+lo;let c=[d[Math.floor(Math.random()*d.length)],u[Math.floor(Math.random()*u.length)],lo[Math.floor(Math.random()*lo.length)],sy[Math.floor(Math.random()*sy.length)]];while(c.length<6)c.push(all[Math.floor(Math.random()*all.length)]);for(let i=c.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[c[i],c[j]]=[c[j],c[i]];}return c.join("");}
function buildCatChecks(sel){
  sel=sel||[]; const all=sel.includes("all");
  let h=`<label><input type="checkbox" id="cc-all" ${all?"checked":""}> Все</label>`;
  h+=ALL_CAT_KEYS.map(k=>`<label><input type="checkbox" class="cc" value="${k}" ${(!all&&sel.includes(k))?"checked":""} ${all?"disabled":""}> ${esc(CATS[k].label)}</label>`).join("");
  $("ef-cats").innerHTML=h;
  on("cc-all","change",e=>{const on=e.target.checked;$("ef-cats").querySelectorAll(".cc").forEach(cb=>{cb.disabled=on;if(on)cb.checked=false;});});
}
function readCats(){ if($("cc-all").checked)return["all"]; const a=[...$("ef-cats").querySelectorAll(".cc:checked")].map(cb=>cb.value); return a.length?a:["all"]; }
function openUserForm(login){
  editingLogin=login||null; $("ef-err").textContent="";
  const u=login?USERS_CACHE.find(x=>x.login===login):null;
  $("user-modal-title").textContent=u?"Редактирование пользователя":"Новый пользователь";
  $("ef-login").value=u?u.login:genLoginLocal();
  $("ef-pass").value=u?"":genPassLocal();
  $("ef-pass").placeholder=u?"оставьте пустым, чтобы не менять":"";
  $("ef-org").value=u?(u.org||""):""; $("ef-email").value=u?(u.email||""):"";
  $("ef-role").value=u?(u.role||"user"):"user"; $("ef-expires").value=u?(u.expires||""):"";
  buildCatChecks(u?u.categories:["all"]);
  uOverlay.classList.add("open"); uOverlay.setAttribute("aria-hidden","false");
}
function closeUserForm(){uOverlay.classList.remove("open");uOverlay.setAttribute("aria-hidden","true");}
on("new-user-btn","click",()=>openUserForm(null));
on("user-modal-close","click",closeUserForm);
on("ef-cancel","click",closeUserForm);
on("ef-genpass","click",()=>$("ef-pass").value=genPassLocal());
uOverlay.addEventListener("click",e=>{if(e.target===uOverlay)closeUserForm();});
on("ef-save","click", async()=>{
  $("ef-err").textContent="";
  const body={editing:editingLogin||"",login:$("ef-login").value.trim(),password:$("ef-pass").value,org:$("ef-org").value.trim(),email:$("ef-email").value.trim(),role:$("ef-role").value,categories:readCats(),expires:$("ef-expires").value};
  const r=await api("user_save",{body});
  if(!r.ok){$("ef-err").textContent=r.error||"Ошибка сохранения.";return;}
  closeUserForm(); toast("Сохранено"); renderUsersTable();
});

/* Массовое добавление */
let bulkItems=[];
function resetBulk(){$("add-step-review").classList.add("hidden");$("add-step-input").classList.remove("hidden");$("bulk-errors").innerHTML="";}
on("bulk-check","click", async()=>{
  const r=await api("users_bulk_preview",{body:{text:$("bulk-input").value}});
  if(!r.ok){toast(r.error||"Ошибка",true);return;}
  bulkItems=r.items||[];
  $("bulk-errors").innerHTML=(r.errors&&r.errors.length)?`<div class="prev-warn" style="background:#FCEAF1;border-color:#F8D9E5;color:#A8285A">Найдены ошибки (${r.errors.length}):<br>• ${r.errors.map(esc).join("<br>• ")}</div>`:"";
  if(!bulkItems.length){ if(r.errors&&r.errors.length) return; }
  $("review-tbody").innerHTML=bulkItems.map((it,i)=>`<tr><td>${i+1}</td><td>${esc(it.org)}</td><td><div class="cat-tags">${catTags(it.categories)}</div></td><td>${fmtRuDate(it.expires)}</td><td>${esc(it.email||"—")}</td><td class="mono">${esc(it.login)}</td><td class="mono">${esc(it.password)}</td></tr>`).join("");
  $("add-step-input").classList.add("hidden"); $("add-step-review").classList.remove("hidden");
});
on("bulk-back","click",resetBulk);
on("bulk-confirm","click", async()=>{
  if(!bulkItems.length)return;
  const r=await api("users_bulk_create",{body:{items:bulkItems}});
  if(!r.ok){toast(r.error||"Ошибка",true);return;}
  toast(`Создано пользователей: ${r.created}`); $("bulk-input").value=""; bulkItems=[]; resetBulk(); switchTab("users");
});

/* ============================================================
   =================  ПИСЬМА (доступ / приглашение)  ========
   ============================================================ */
const mailOverlay=$("mail-overlay"); let mailCtx={type:"invite",id:null};
async function openMail(type,id){
  mailCtx={type,id};
  $("mail-modal-title").textContent = type==="access" ? "Отправить доступ на email" : "Отправить приглашение на вебинар";
  $("mail-to").value=""; $("mail-err").textContent=""; $("mail-ok").textContent="";
  $("mail-subject").value="Загрузка…"; $("mail-preview").srcdoc="";
  mailOverlay.classList.add("open"); mailOverlay.setAttribute("aria-hidden","false");
  const r=await api("mail_preview",{body:{type,webinar_id:id}});
  if(!r.ok){$("mail-err").textContent=r.error||"Ошибка предпросмотра.";$("mail-subject").value="";return;}
  $("mail-subject").value=r.subject||""; $("mail-preview").srcdoc=r.html||"";
  // подставим email пользователя (если есть) как подсказку
  if(currentUser&&currentUser.email) $("mail-to").value=currentUser.email;
}
function closeMail(){mailOverlay.classList.remove("open");mailOverlay.setAttribute("aria-hidden","true");}
on("mail-modal-close","click",closeMail);
mailOverlay.addEventListener("click",e=>{if(e.target===mailOverlay)closeMail();});
on("mail-send","click", async()=>{
  $("mail-err").textContent="";$("mail-ok").textContent="";
  const email=$("mail-to").value.trim();
  if(!email){$("mail-err").textContent="Укажите email получателя.";return;}
  $("mail-send").disabled=true; $("mail-send").textContent="Отправка…";
  const r=await api("mail_send",{body:{type:mailCtx.type,webinar_id:mailCtx.id,email}});
  $("mail-send").disabled=false; $("mail-send").textContent="Отправить";
  if(!r.ok){$("mail-err").textContent=r.error||"Не удалось отправить.";return;}
  $("mail-ok").textContent="Письмо отправлено ✓"; toast("Письмо отправлено");
});

/* ============================================================
   =================  МОИ ПРОСМОТРЫ / ЖУРНАЛЫ  ==============
   ============================================================ */
const viewsOverlay=$("views-overlay");
function closeViews(){viewsOverlay.classList.remove("open");viewsOverlay.setAttribute("aria-hidden","true");}
on("views-close","click",closeViews);
viewsOverlay.addEventListener("click",e=>{if(e.target===viewsOverlay)closeViews();});
on("myviews","click",openMyViews);
async function openMyViews(){
  $("views-title").textContent="Мои просмотры";
  $("views-tbody").innerHTML=`<tr><td colspan="3"><span class="spinner"></span>Загрузка…</td></tr>`;
  viewsOverlay.classList.add("open");viewsOverlay.setAttribute("aria-hidden","false");
  const r=await api("my_views");
  if(!r.ok){$("views-tbody").innerHTML=`<tr><td colspan="3">${esc(r.error||"Ошибка")}</td></tr>`;return;}
  const u=r.user||currentUser;
  const last=u.last_login?fmtDateTime(u.last_login):"—";
  $("views-meta").innerHTML=`Последний вход: <b>${esc(last)}</b>${u.last_ip?` (IP ${esc(u.last_ip)})`:""} · Всего входов: <b>${esc(String(u.login_count||1))}</b> · Уникальных вебинаров открыто: <b>${(r.views||[]).length}</b>`;
  drawViews(r.views||[]);
}
function drawViews(views){
  $("views-tbody").innerHTML = views.length ? views.map(v=>`<tr><td>${esc(v.title||("#"+v.webinar_id))}</td><td>${esc(String(v.count))}</td><td>${esc(fmtDateTime(v.last))}</td></tr>`).join("")
    : `<tr><td colspan="3" style="text-align:center;color:var(--muted);padding:20px">Пока нет просмотров.</td></tr>`;
}
function fmtDateTime(iso){ if(!iso)return "—"; const d=new Date(iso); if(isNaN(d))return iso; const p=n=>String(n).padStart(2,"0"); return `${p(d.getDate())}.${p(d.getMonth()+1)}.${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`; }
async function openUserViews(login){
  const u=USERS_CACHE.find(x=>x.login===login)||{};
  $("views-title").textContent=`Просмотры: ${u.org||login} (${login})`;
  $("views-tbody").innerHTML=`<tr><td colspan="3"><span class="spinner"></span>Загрузка…</td></tr>`;
  viewsOverlay.classList.add("open");viewsOverlay.setAttribute("aria-hidden","false");
  const r=await api("user_views&login="+encodeURIComponent(login));
  if(!r.ok){$("views-tbody").innerHTML=`<tr><td colspan="3">${esc(r.error||"Ошибка")}</td></tr>`;return;}
  const last=u.last_login?fmtDateTime(u.last_login):"—";
  $("views-meta").innerHTML=`Последний вход: <b>${esc(last)}</b>${u.last_ip?` (IP ${esc(u.last_ip)})`:""} · Всего входов: <b>${esc(String(u.login_count||0))}</b> · Уникальных вебинаров: <b>${(r.views||[]).length}</b>`;
  drawViews(r.views||[]);
}

/* --- Админ: вкладка Журналы --- */
let logType="views";
async function loadLogStats(){
  const r=await api("logs_summary"); if(!r.ok)return;
  const c=r.counts;
  $("log-stats").innerHTML=`
    <div class="stat"><div class="num">${c.views}</div><div class="lbl">просмотров вебинаров</div></div>
    <div class="stat"><div class="num">${c.logins_ok}/${c.logins}</div><div class="lbl">успешных входов / всего</div></div>
    <div class="stat"><div class="num">${c.mails_ok}/${c.mails}</div><div class="lbl">писем отправлено / всего</div></div>`;
}
async function loadLogTable(){
  const r=await api("logs_list&type="+logType);
  const thead=$("log-thead"), tbody=$("log-tbody");
  if(!r.ok){tbody.innerHTML=`<tr><td>${esc(r.error||"Ошибка")}</td></tr>`;return;}
  const rows=r.rows||[];
  if(logType==="views"){
    thead.innerHTML=`<tr><th>Дата</th><th>Логин</th><th>ID</th><th>Тема</th><th>IP</th></tr>`;
    tbody.innerHTML=rows.map(x=>`<tr><td>${esc(fmtDateTime(x.ts))}</td><td class="mono">${esc(x.login)}</td><td>${esc(String(x.webinar_id))}</td><td>${esc(x.title||"")}</td><td class="mono">${esc(x.ip||"")}</td></tr>`).join("")||emptyRow(5);
  } else if(logType==="login"){
    thead.innerHTML=`<tr><th>Дата</th><th>Логин</th><th>Результат</th><th>Причина</th><th>IP</th></tr>`;
    tbody.innerHTML=rows.map(x=>`<tr><td>${esc(fmtDateTime(x.ts))}</td><td class="mono">${esc(x.login)}</td><td>${x.ok?'<span class="pill pill--ok">успех</span>':'<span class="pill pill--exp">ошибка</span>'}</td><td>${esc(x.reason||"")}</td><td class="mono">${esc(x.ip||"")}</td></tr>`).join("")||emptyRow(5);
  } else {
    thead.innerHTML=`<tr><th>Дата</th><th>Кем</th><th>Кому</th><th>Тип</th><th>Тема</th><th>Результат</th></tr>`;
    tbody.innerHTML=rows.map(x=>`<tr><td>${esc(fmtDateTime(x.ts))}</td><td class="mono">${esc(x.by)}</td><td>${esc(x.to)}</td><td>${esc(x.mail_type)}</td><td>${esc(x.subject||"")}</td><td>${x.ok?'<span class="pill pill--ok">ОК</span>':'<span class="pill pill--exp" title="'+esc(x.error||"")+'">ошибка</span>'}</td></tr>`).join("")||emptyRow(6);
  }
}
function emptyRow(n){return `<tr><td colspan="${n}" style="text-align:center;color:var(--muted);padding:20px">Записей нет.</td></tr>`;}
document.querySelectorAll("#log-seg .seg-btn").forEach(b=>b.addEventListener("click",()=>{
  document.querySelectorAll("#log-seg .seg-btn").forEach(x=>x.classList.remove("active"));
  b.classList.add("active"); logType=b.dataset.log; loadLogTable();
}));
on("log-export","click",()=>{ window.open("api/index.php?action=logs_export&type="+logType,"_blank"); });

/* --- Функции (тумблеры) --- */
function loadFeaturesForm(){
  document.querySelectorAll("#feat-list [data-feat]").forEach(cb=>{
    cb.checked = FEATURES[cb.dataset.feat] !== false;
  });
  const sp=$("feat-showpast"); if(sp) sp.checked = appSettings.show_past !== false;
}

/* ---------- Вкладка «Вид» ---------- */
const COLS=["date","speaker","timer","title","price"];             // столбцы со шрифтом
const WCOLS=["date","speaker","timer","title","price","action"];   // столбцы с шириной
const COL_TITLES = { date:"Дата", speaker:"Лектор", timer:"До начала", title:"Тема", price:"Цена", action:"Действия" };
const FMAP_KEYS = ["date","speaker","title","price","direction","link_participant","time","id"];
function loadViewForm(){
  COLS.forEach(k=>{ const f=$("cf-"+k); if(f) f.value=COL_FONTS[k]||""; });
  WCOLS.forEach(k=>{ const w=$("cw-"+k); if(w) w.value=(COL_WIDTHS[k]??"")===0?0:(COL_WIDTHS[k]??""); });
  const sh=$("vw-start-hour"); if(sh) sh.value=VIEW.start_hour ?? 10;
  const ps=$("vw-page-size"); if(ps) ps.value=VIEW.page_size ?? 0;
  const df=$("vw-date-format"); if(df) df.value=VIEW.date_format || "D MMMM YYYY";
  markSeg("density-seg","density",VIEW.density);
  markSeg("theme-seg","theme",VIEW.theme);
  markSeg("mode-seg","mode",VIEW.mode||"table");
  buildColConfig();
  buildFieldMapForm();
  buildCatConfig();
}
// --- Конфиг колонок: список с чекбоксом, полем подписи и drag-ن-drop ---
function buildColConfig(){
  const box=$("col-config"); if(!box) return;
  box.innerHTML = COLUMNS.map((c,i)=>`
    <div class="col-row" draggable="true" data-idx="${i}" data-key="${c.key}">
      <span class="col-drag" title="Перетащить">⋮⋮</span>
      <label class="col-vis"><input type="checkbox" ${c.visible!==false?"checked":""}> </label>
      <input class="input col-lbl" value="${esc(c.label||COL_TITLES[c.key]||c.key)}" placeholder="${esc(COL_TITLES[c.key]||c.key)}">
      <span class="col-keyname">${esc(c.key)}</span>
    </div>`).join("");
  // события
  box.querySelectorAll(".col-row").forEach(row=>{
    const key=row.dataset.key;
    row.querySelector(".col-vis input").addEventListener("change",e=>{
      const col=COLUMNS.find(x=>x.key===key); if(col) col.visible=e.target.checked; render();
    });
    row.querySelector(".col-lbl").addEventListener("input",e=>{
      const col=COLUMNS.find(x=>x.key===key); if(col) col.label=e.target.value; render();
    });
    row.addEventListener("dragstart",e=>{ e.dataTransfer.setData("text/plain",row.dataset.idx); row.classList.add("dragging"); });
    row.addEventListener("dragend",()=>row.classList.remove("dragging"));
    row.addEventListener("dragover",e=>{ e.preventDefault(); row.classList.add("drop-hint"); });
    row.addEventListener("dragleave",()=>row.classList.remove("drop-hint"));
    row.addEventListener("drop",e=>{
      e.preventDefault(); row.classList.remove("drop-hint");
      const from=+e.dataTransfer.getData("text/plain"), to=+row.dataset.idx;
      if(from===to||isNaN(from)) return;
      const moved=COLUMNS.splice(from,1)[0]; COLUMNS.splice(to,0,moved);
      buildColConfig(); render();
    });
  });
}
function buildFieldMapForm(){
  const box=$("fieldmap-box"); if(!box) return;
  box.innerHTML = FMAP_KEYS.map(k=>`<label class="cf"><span>${esc(k)}</span><input id="fm-${k}" value="${esc(FIELD_MAP[k]||k)}"></label>`).join("");
}
function buildCatConfig(){
  const box=$("cat-config"); if(!box) return;
  const keys=["zhkh","zdrav","electro","eco","build","land","goz","gas","other"];
  box.innerHTML = keys.map(k=>{
    const base=CATS[k]||CATS.other; const ov=CAT_OVERRIDES[k]||{};
    return `<div class="cat-row">
      <input type="color" id="cc-color-${k}" value="${(ov.color||base.c)}">
      <input class="input" id="cc-label-${k}" value="${esc(ov.label||base.label)}" placeholder="${esc(base.label)}">
      <span class="col-keyname">${esc(k)}</span>
    </div>`;
  }).join("");
}
function markSeg(segId, attr, val){
  const seg=$(segId); if(!seg) return;
  seg.querySelectorAll("button").forEach(b=>b.classList.toggle("active", b.dataset[attr]===val));
}
// живой предпросмотр шрифтов
document.querySelectorAll("#cf-date,[data-live]").forEach(()=>{});
COLS.forEach(k=>{
  on("cf-"+k,"input",()=>{ COL_FONTS[k]=Math.max(10,Math.min(28,parseInt($("cf-"+k).value,10)||COL_FONTS[k])); applyColFonts(); });
});
WCOLS.forEach(k=>{
  on("cw-"+k,"input",()=>{ const v=parseInt($("cw-"+k).value,10); COL_WIDTHS[k]=isNaN(v)?COL_WIDTHS[k]:(v<=0?0:Math.max(60,Math.min(600,v))); applyColWidths(); });
});
// пресеты
document.querySelectorAll("#preset-seg button").forEach(b=>b.addEventListener("click",()=>{
  const p=PRESETS[b.dataset.preset]; if(!p) return;
  COL_FONTS=Object.assign({},p.fonts);
  VIEW.density=p.density;
  applyColFonts(); applyView(); loadViewForm();
  if(typeof fitTitles==="function") fitTitles();
}));
// плотность
document.querySelectorAll("#density-seg button").forEach(b=>b.addEventListener("click",()=>{
  VIEW.density=b.dataset.density; applyView(); markSeg("density-seg","density",VIEW.density);
}));
// тема
document.querySelectorAll("#theme-seg button").forEach(b=>b.addEventListener("click",()=>{
  VIEW.theme=b.dataset.theme; applyView(); markSeg("theme-seg","theme",VIEW.theme);
}));
// режим таблица/карточки (в настройках)
document.querySelectorAll("#mode-seg button").forEach(b=>b.addEventListener("click",()=>{
  VIEW.mode=b.dataset.mode; applyView(); markSeg("mode-seg","mode",VIEW.mode); render();
}));
// час старта
on("vw-start-hour","input",()=>{ VIEW.start_hour=Math.max(0,Math.min(23,parseInt($("vw-start-hour").value,10)||0)); if(typeof render==="function") render(); });
// размер страницы
on("vw-page-size","input",()=>{ VIEW.page_size=Math.max(0,Math.min(500,parseInt($("vw-page-size").value,10)||0)); pageLimit=0; render(); });
// формат даты (живой предпросмотр)
on("vw-date-format","input",()=>{ VIEW.date_format=$("vw-date-format").value||"D MMMM YYYY"; render(); });
// быстрый тумблер темы в шапке
on("theme-toggle","click", async()=>{
  VIEW.theme = VIEW.theme==="dark"?"light":"dark"; applyView();
  if(currentUser && currentUser.role==="admin"){ await api("view_save",{body:{view:VIEW}}); }
});
// сброс к стандартным
on("view-reset","click",()=>{
  COL_FONTS={date:15,speaker:15,timer:14,title:16,price:15};
  COL_WIDTHS={date:118,speaker:140,timer:110,title:0,price:130,action:240};
  VIEW={density:"normal",theme:VIEW.theme,start_hour:10,mode:"table",page_size:0,date_format:"D MMMM YYYY"};
  COLUMNS=[
    {key:"date",label:"Дата",visible:true},{key:"speaker",label:"Лектор",visible:true},
    {key:"timer",label:"До начала",visible:true},{key:"title",label:"Тема",visible:true},
    {key:"price",label:"Цена без подписки",visible:true},{key:"action",label:"Действия",visible:true}
  ];
  CAT_OVERRIDES={};
  applyAllAppearance(); loadViewForm(); render(); toast("Сброшено к стандартным (не забудьте сохранить)");
});
// сохранить всё «Вид»
on("save-view","click", async()=>{
  const cf={}, cw={};
  COLS.forEach(k=>{ cf[k]=parseInt($("cf-"+k)?.value,10)||COL_FONTS[k]; });
  WCOLS.forEach(k=>{ const wv=parseInt($("cw-"+k)?.value,10); cw[k]=isNaN(wv)?COL_WIDTHS[k]:wv; });
  // собрать field_map и cat_overrides из формы
  const fm={}; FMAP_KEYS.forEach(k=>{ const el=$("fm-"+k); fm[k]=(el&&el.value.trim())||k; });
  const co={}; ["zhkh","zdrav","electro","eco","build","land","goz","gas","other"].forEach(k=>{
    const col=$("cc-color-"+k), lbl=$("cc-label-"+k), base=CATS[k]||CATS.other, o={};
    if(col&&col.value&&col.value.toLowerCase()!==base.c.toLowerCase()) o.color=col.value;
    if(lbl&&lbl.value.trim()&&lbl.value.trim()!==base.label) o.label=lbl.value.trim();
    if(Object.keys(o).length) co[k]=o;
  });
  const r1=await api("colfonts_save",{body:{col_fonts:cf}});
  const r2=await api("colwidths_save",{body:{col_widths:cw}});
  const r3=await api("view_save",{body:{view:VIEW}});
  const r4=await api("columns_save",{body:{columns:COLUMNS}});
  const r5=await api("fieldmap_save",{body:{field_map:fm}});
  const r6=await api("catoverrides_save",{body:{cat_overrides:co}});
  if(!(r1.ok&&r2.ok&&r3.ok&&r4.ok&&r5.ok&&r6.ok)){toast("Ошибка сохранения",true);return;}
  COL_FONTS=Object.assign(COL_FONTS,r1.col_fonts||cf);
  COL_WIDTHS=Object.assign(COL_WIDTHS,r2.col_widths||cw);
  VIEW=Object.assign(VIEW,r3.view||VIEW);
  if(r4.columns) COLUMNS=r4.columns;
  if(r5.field_map) FIELD_MAP=r5.field_map;
  CAT_OVERRIDES=co;
  applyAllAppearance();
  await loadWebinars(true);   // перечитать с новым сопоставлением полей
  toast("Вид сохранён");
});
on("save-features","click", async()=>{
  const features={};
  document.querySelectorAll("#feat-list [data-feat]").forEach(cb=>{features[cb.dataset.feat]=cb.checked;});
  const r=await api("features_save",{body:{features,show_past:$("feat-showpast").checked}});
  if(!r.ok){toast(r.error||"Ошибка",true);return;}
  FEATURES=r.features||features; appSettings.show_past=$("feat-showpast").checked;
  toast("Функции сохранены");
  render();                 // мгновенно применяем
  // обновим шапку (кнопка «Мои просмотры», таймер) без полного перезахода
  $("myviews").classList.toggle("hidden", !(feat("my_views")||currentUser.role==="admin"));
  { const th=$("th-timer"); if(th) th.style.display = feat("timer") ? "" : "none"; }
});

/* --- Авто-очистка логов --- */
async function loadRetentionForm(){
  const r=await api("settings_get"); if(!r.ok)return;
  const ret=(r.settings&&r.settings.log_retention)||{};
  $("ret-enabled").checked = ret.enabled!==false;
  $("ret-days").value = ret.days ?? 180;
  $("ret-lines").value = ret.max_lines ?? 20000;
}
on("ret-save","click", async()=>{
  $("ret-msg").textContent="";
  const r=await api("retention_save",{body:{log_retention:{enabled:$("ret-enabled").checked,days:$("ret-days").value,max_lines:$("ret-lines").value}}});
  if(!r.ok){toast(r.error||"Ошибка",true);return;}
  $("ret-msg").textContent="Настройки очистки сохранены ✓"; toast("Сохранено");
});
on("ret-prune","click", async()=>{
  if(!confirm("Очистить логи по текущим правилам сейчас?"))return;
  const r=await api("logs_prune_now",{body:{}});
  if(!r.ok){toast(r.error||"Ошибка",true);return;}
  const rem=r.removed||{}; const total=(rem.login||0)+(rem.views||0)+(rem.mail||0);
  $("ret-msg").textContent=`Удалено записей: ${total} (входы ${rem.login||0}, просмотры ${rem.views||0}, письма ${rem.mail||0})`;
  toast("Логи очищены"); loadLogStats(); loadLogTable();
});
on("log-clear","click", async()=>{
  if(!confirm("Полностью очистить текущий лог? Это действие необратимо."))return;
  const r=await api("logs_clear",{body:{type:logType}});
  if(!r.ok){toast(r.error||"Ошибка",true);return;}
  toast("Лог очищен"); loadLogStats(); loadLogTable();
});

/* --- Экспорт / импорт конфигурации --- */
on("btn-export","click",()=>{
  const withPass = $("exp-smtp-pass").checked ? "1" : "0";
  window.open("api/index.php?action=config_export&smtp_pass="+withPass,"_blank");
  toast("Файл конфигурации скачивается");
});
on("btn-import","click",()=>{
  $("imp-err").textContent=""; $("imp-ok").textContent="";
  const f=$("imp-file").files[0];
  if(!f){$("imp-err").textContent="Выберите файл конфигурации.";return;}
  const parts={settings:$("imp-settings").checked,users:$("imp-users").checked,webinars:$("imp-webinars").checked};
  if(!parts.settings&&!parts.users&&!parts.webinars){$("imp-err").textContent="Выберите хотя бы один раздел для импорта.";return;}
  const reader=new FileReader();
  reader.onload=async()=>{
    let bundle;
    try{ bundle=JSON.parse(reader.result); }
    catch(e){ $("imp-err").textContent="Файл повреждён или не является JSON."; return; }
    if(!confirm("Импортировать выбранные разделы? Текущие данные будут заменены."))return;
    const btn=$("btn-import"); btn.disabled=true; btn.textContent="Импорт…";
    const r=await api("config_import",{body:{bundle,parts}});
    btn.disabled=false; btn.textContent="Импортировать";
    if(!r.ok){$("imp-err").textContent=r.error||"Ошибка импорта.";return;}
    $("imp-ok").textContent="Импортировано: "+(r.applied||[]).join(", ")+". Обновляем…";
    toast("Конфигурация импортирована");
    // перечитываем актуальные функции/настройки/вебинары
    setTimeout(async()=>{
      const me=await api("me");
      if(me&&me.features)FEATURES=me.features;
      if(me&&typeof me.show_past!=="undefined")appSettings.show_past=me.show_past!==false;
      await loadWebinars();
      await loadSettingsIntoForm();
    },600);
  };
  reader.readAsText(f);
});

/* --- Проверка SMTP --- */
on("btn-smtp-test","click", async()=>{
  $("smtp-err").textContent="";$("smtp-ok").textContent="";
  const to=$("sm-test-to").value.trim();
  if(!to){$("smtp-err").textContent="Укажите email для теста.";return;}
  const btn=$("btn-smtp-test"); btn.disabled=true; btn.textContent="Отправка…";
  const r=await api("smtp_test",{body:{email:to}});
  btn.disabled=false; btn.textContent="Проверить SMTP";
  if(!r.ok){$("smtp-err").textContent=r.error||"Ошибка отправки.";return;}
  $("smtp-ok").textContent="Тестовое письмо отправлено ✓"; toast("Тестовое письмо отправлено");
});

/* ---------- Старт ---------- */
(async function init(){
  try{
    const r=await api("me");
    if(r&&r.csrf)CSRF=r.csrf;
    if(r&&r.features)FEATURES=r.features;
    ingestAppearance(r);
    if(r&&typeof r.show_past!=="undefined")appSettings.show_past=r.show_past!==false;
    applyAllAppearance();
    if(r&&r.authenticated){currentUser=r.user;await enterApp();}
    else{ setHidden("login-screen",false); const li=$("li-login"); if(li) li.focus(); }
  }catch(e){
    console.error("Ошибка инициализации:",e);
    setHidden("login-screen",false);   // при любой ошибке показываем экран входа, а не пустую страницу
  }
})();
