/* global window */

const API_BOARD = 'api/board.php';
const API_ACTION = 'api/actividad_accion.php';
const API_HISTORIAL = 'api/historial_actividad.php';
const API_NOTAS = 'api/actividad_notas.php';
const ACTIVIDADES_POR_PAGINA = 3;
/** Actividades con enlace al modal de notas (desde `getActividadesConModalNotasIds` en PHP). */
const NOTAS_ACTIVITY_IDS = (() => {
  const raw = typeof window !== 'undefined' ? window.__ACTIVIDAD_NOTAS_IDS__ : null;
  if (Array.isArray(raw)) {
    const ids = raw.map(n => Math.floor(Number(n))).filter(n => n > 0);
    if (ids.length) return ids;
  }
  return [10];
})();

function actividadTieneModalNotas(actividadId){
  return NOTAS_ACTIVITY_IDS.includes(Number(actividadId) || 0);
}

const NOTAS_FUNERARIO_ID = (() => {
  const v = Number(typeof window !== 'undefined' ? window.__ACTIVIDAD_NOTAS_FUNERARIO_ID__ : undefined);
  return Number.isFinite(v) && v > 0 ? Math.floor(v) : 10;
})();

const NOTAS_OTROS_ID = (() => {
  const v = Number(typeof window !== 'undefined' ? window.__ACTIVIDAD_NOTAS_OTROS_ID__ : undefined);
  return Number.isFinite(v) && v > 0 ? Math.floor(v) : 0;
})();

const NOTAS_FACTURACION_ID = (() => {
  const v = Number(typeof window !== 'undefined' ? window.__ACTIVIDAD_NOTAS_FACTURACION_ID__ : undefined);
  return Number.isFinite(v) && v > 0 ? Math.floor(v) : 0;
})();

/** Etiqueta y placeholder del primer campo según actividad. */
function getNotasCampoPrincipalEtiquetas(actividadId){
  const id = Number(actividadId) || 0;
  if (NOTAS_OTROS_ID > 0 && id === NOTAS_OTROS_ID) {
    return { label: 'Nombre actividad', placeholder: 'Nombre actividad' };
  }
  if (NOTAS_FACTURACION_ID > 0 && id === NOTAS_FACTURACION_ID) {
    return { label: 'Número de Factura', placeholder: 'Número de factura' };
  }
  return { label: 'Fallecido', placeholder: 'Nombre del fallecido' };
}

function applyNotasCampoPrincipalLabels(actividadId){
  const lbl = document.getElementById('actividadNotasCampoLabel');
  const inp = document.getElementById('actividadNotasFallecido');
  const { label, placeholder } = getNotasCampoPrincipalEtiquetas(actividadId);
  if (lbl) lbl.textContent = label;
  if (inp) {
    inp.placeholder = placeholder;
    inp.setAttribute('aria-label', label);
  }
}
/** Meta diaria fija (segundos); definida en `config.php` como META_JORNADA_SEG_FIJA. */
const META_JORNADA_SEG = (() => {
  const v = Number(typeof window !== 'undefined' ? window.__META_JORNADA_SEG__ : undefined);
  return Number.isFinite(v) && v > 0 ? Math.floor(v) : 8 * 3600 + 30 * 60;
})();
/** Tope del contador diario al sumar al general (8 h); `TOPE_HORAS_DIARIAS_CONTADOR_SEG` en PHP. */
const TOPE_HORAS_DIARIAS_CONTADOR_SEG = (() => {
  const v = Number(typeof window !== 'undefined' ? window.__TOPE_HORAS_DIARIAS_CONTADOR_SEG__ : undefined);
  return Number.isFinite(v) && v > 0 ? Math.floor(v) : 8 * 3600;
})();
/** No suma en barra de jornada / totales de tablero (coincide con `ACTIVIDAD_ALMUERZO_ID` en PHP). */
const ALMUERZO_ACTIVITY_ID = (() => {
  const v = Number(typeof window !== 'undefined' ? window.__ACTIVIDAD_ALMUERZO_ID__ : undefined);
  return Number.isFinite(v) && v > 0 ? Math.floor(v) : 16;
})();

let searchQuery = '';
/** Suma de `tiempo_acumulado_seg` de todas las actividades de la jornada (el tramo en curso se suma aparte). */
let jornadaBaseSeg = 0;

/** Columna visible en vista estrecha (pastillas, breakpoint móvil). */
let activeMobileColumn = 'asignadas';
let boardSortOrder = 'nombre';
let notasContext = { actividadId: 0, titulo: '' };

function escapeHtml(text){
  return String(text ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function titleInitials(titulo){
  const parts = String(titulo || '').trim().split(/\s+/).filter(Boolean);
  if(parts.length === 0) return '?';
  if(parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0].charAt(0) + parts[1].charAt(0)).toUpperCase();
}

function formatNotaFechaDisplay(value){
  if(!value) return '';
  const normalized = String(value).trim().replace(' ', 'T');
  const d = value instanceof Date ? value : new Date(normalized);
  if(Number.isNaN(d.getTime())) return '';
  const pad = n => String(n).padStart(2, '0');
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

async function saveNotaActividad(payload){
  const fd = new FormData();
  fd.append('csrf_token', getCsrfToken());
  fd.append('actividad_id', String(payload.actividadId));
  fd.append('fallecido', String(payload.fallecido ?? ''));
  fd.append('fecha', String(payload.fecha ?? ''));
  fd.append('observaciones', String(payload.observaciones ?? ''));

  const res = await fetch(API_NOTAS, {
    method: 'POST',
    body: fd,
    credentials: 'same-origin',
  });
  const data = await res.json().catch(() => ({ ok: false, message: 'Respuesta inválida del servidor' }));
  if(!data.ok){
    throw new Error(data.message || 'Error al guardar la nota');
  }
  return data.nota || null;
}

function sortCardList(list){
  if(boardSortOrder !== 'nombre') return list;
  return [...list].sort((a, b) => String(a.titulo || '').localeCompare(String(b.titulo || ''), 'es', { sensitivity: 'base' }));
}

function msToHMSFromSeconds(totalSeg){
  totalSeg = Math.max(0, Math.floor(Number(totalSeg) || 0));
  const h = Math.floor(totalSeg / 3600);
  const m = Math.floor((totalSeg % 3600) / 60);
  const s = totalSeg % 60;
  return String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
}

function getCsrfToken(){
  return window.__CSRF_TOKEN__ || '';
}

function setLoadingState(button, isLoading){
  if(!button) return;
  button.disabled = isLoading;
  if(isLoading){
    const original = button.dataset.originalText;
    if(!original){
      button.dataset.originalText = button.textContent;
    }
    button.textContent = 'Procesando...';
  }else{
    const original = button.dataset.originalText;
    button.textContent = original || button.textContent;
    button.dataset.originalText = '';
  }
}

let runningCards = []; // cards con timer activo (se renderizan una vez por refresh)
let timerIntervalId = null;
const columnPage = {
  asignadas: 1,
  iniciadas: 1,
  finalizadas: 1,
};

function ensureTimerLoop(){
  if(timerIntervalId !== null) return;
  timerIntervalId = setInterval(tickRunningTimers, 1000);
}

function renderColumn(columnEl, pagerEl, cards, columnKey){
  columnEl.innerHTML = '';
  if(pagerEl) pagerEl.innerHTML = '';
  if(!Array.isArray(cards) || cards.length === 0){
    columnPage[columnKey] = 1;
    columnEl.innerHTML = '<div class="text-muted small py-2">Sin actividades</div>';
    return;
  }

  const filteredCards = sortCardList(applySearchFilter(cards));
  const totalItems = filteredCards.length;
  const totalPages = Math.max(1, Math.ceil(totalItems / ACTIVIDADES_POR_PAGINA));
  const currentPage = Math.min(Math.max(1, Number(columnPage[columnKey] || 1)), totalPages);
  columnPage[columnKey] = currentPage;

  const startIdx = (currentPage - 1) * ACTIVIDADES_POR_PAGINA;
  const endIdx = startIdx + ACTIVIDADES_POR_PAGINA;
  const visibleCards = filteredCards.slice(startIdx, endIdx);

  for(const card of visibleCards){
    const isRunning = Boolean(card.esta_corriendo);
    const actividadId = card.actividad_id;
    const cardEl = document.createElement('div');
    cardEl.className = 'activity-card';
    cardEl.dataset.actividadId = actividadId;
    cardEl.dataset.columnKey = columnKey;
    cardEl.dataset.running = isRunning ? '1' : '0';

    const estadoLabel = (() => {
      if(card.estado_slug === 'asignadas') return 'Asignada';
      if(card.estado_slug === 'finalizadas') return 'Finalizada';
      // iniciadas
      return isRunning ? 'Iniciada (corriendo)' : 'Iniciada (pausada)';
    })();

    const tituloHtml = actividadTieneModalNotas(actividadId)
      ? `<button type="button" class="btn btn-link p-0 activity-title activity-title-link text-start" data-action="notes" data-actividad-id="${actividadId}" data-actividad-title="${escapeHtml(card.titulo)}">${escapeHtml(card.titulo)}</button>`
      : `<div class="activity-title">${escapeHtml(card.titulo)}</div>`;

    cardEl.innerHTML = `
      <div class="activity-card-head">
        <div class="activity-card-icon activity-card-icon--${columnKey}" aria-hidden="true">${escapeHtml(titleInitials(card.titulo))}</div>
        <div class="activity-card-head-text">
          ${tituloHtml}
        </div>
        <button type="button" class="btn btn-outline-secondary btn-history" data-action="history" data-actividad-id="${actividadId}">
          <i class="bi bi-clock-history me-1"></i>Historial
        </button>
      </div>
      <div class="activity-desc">${card.descripcion ? escapeHtml(card.descripcion) : ''}</div>
      <div class="mt-2 d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <span class="text-muted small">${escapeHtml(estadoLabel)}</span>
        <span class="time-badge bg-light border rounded px-2 py-1" data-time-seg="${card.tiempo_acumulado_seg}">
          ${msToHMSFromSeconds(card.tiempo_acumulado_seg)}
        </span>
      </div>
      <div class="d-grid">
        ${renderActions(card)}
      </div>
    `;

    columnEl.appendChild(cardEl);

    // Robustez: `inicio_unix_ms` puede venir como string/null dependiendo de encoding BD/PHP.
    const inicioUnixMs = isRunning ? Number(card.inicio_unix_ms) : NaN;
    if(isRunning && Number.isFinite(inicioUnixMs)){
      runningCards.push({
        cardEl,
        inicioUnixMs,
        baseSeg: Number(card.tiempo_acumulado_seg) || 0,
        timeEl: cardEl.querySelector('[data-time-seg]'),
        actividadId: Number(actividadId) || 0
      });
    }
  }

  if(totalPages > 1){
    const pager = document.createElement('div');
    pager.className = 'd-flex justify-content-between align-items-center mt-2';
    pager.innerHTML = `
      <button class="btn btn-sm pager-arrow-btn" data-page-action="prev" data-column-key="${columnKey}" ${currentPage === 1 ? 'disabled' : ''} aria-label="Página anterior">
        &lt;&lt;
      </button>
      <span class="small text-muted">Página ${currentPage} de ${totalPages}</span>
      <button class="btn btn-sm pager-arrow-btn" data-page-action="next" data-column-key="${columnKey}" ${currentPage === totalPages ? 'disabled' : ''} aria-label="Página siguiente">
        &gt;&gt;
      </button>
    `;
    if(pagerEl){
      pagerEl.appendChild(pager);
    } else {
      columnEl.appendChild(pager);
    }
  }
}

function applySearchFilter(list){
  if(!searchQuery) return list;
  const q = searchQuery.toLowerCase();
  return list.filter(card => {
    const t = (card.titulo || '').toLowerCase();
    const d = (card.descripcion || '').toLowerCase();
    return t.includes(q) || d.includes(q);
  });
}

function renderActions(card){
  const actividadId = card.actividad_id;
  const isRunning = Boolean(card.esta_corriendo);

  if(card.estado_slug === 'asignadas'){
    return `
      <button class="btn btn-primary btn-action" data-action="start" data-actividad-id="${actividadId}">
        <i class="bi bi-play-fill me-1"></i>Iniciar
      </button>
    `;
  }

  if(card.estado_slug === 'finalizadas'){
    return `
      <button class="btn btn-secondary btn-action" disabled>
        <i class="bi bi-check2-circle me-1"></i>Completada
      </button>
    `;
  }

  // iniciadas (running o paused)
  return `
    <div class="started-actions-row">
      ${isRunning ? `
        <button class="btn btn-warning btn-action" data-action="pause" data-actividad-id="${actividadId}">
          <i class="bi bi-pause-fill me-1"></i>Pausar
        </button>
      ` : `
        <button class="btn btn-success btn-action" data-action="resume" data-actividad-id="${actividadId}">
          <i class="bi bi-play-circle-fill me-1"></i>Reanudar
        </button>
      `}
      <button class="btn btn-danger btn-action" data-action="finish" data-actividad-id="${actividadId}">
        <i class="bi bi-flag-fill me-1"></i>Finalizar
      </button>
    </div>
  `;
}

function sumAllCardsBaseSeg(board){
  let s = 0;
  for(const key of ['asignadas', 'iniciadas', 'finalizadas']){
    for(const card of (board[key] || [])){
      if(Number(card.actividad_id) === ALMUERZO_ACTIVITY_ID) continue;
      s += Number(card.tiempo_acumulado_seg) || 0;
    }
  }
  return s;
}

function getTotalJornadaSegundos(){
  let extra = 0;
  for(const r of runningCards){
    if(Number(r.actividadId) === ALMUERZO_ACTIVITY_ID) continue;
    if(!Number.isFinite(r.inicioUnixMs)) continue;
    extra += Math.floor((Date.now() - r.inicioUnixMs) / 1000);
  }
  return jornadaBaseSeg + Math.max(0, extra);
}

/** Contador diario mostrado en sidebar (sin almuerzo, tope 8 h). */
function getContadorDiarioSegundos(){
  return Math.min(getTotalJornadaSegundos(), TOPE_HORAS_DIARIAS_CONTADOR_SEG);
}

function updateJornadaProgress(){
  const wrap = document.getElementById('jornada-progress-wrap');
  if(!wrap) return;

  const bar = document.getElementById('jornada-progress-bar');
  const label = document.getElementById('jornada-progress-label');
  const root = document.getElementById('jornada-progress-root');
  if(!bar || !label) return;

  const totalSeg = getTotalJornadaSegundos();
  const pctRaw = META_JORNADA_SEG > 0 ? (totalSeg / META_JORNADA_SEG) * 100 : 0;
  const pctBar = Math.min(100, Math.max(0, pctRaw));
  const pctText = Math.round(pctRaw * 10) / 10;

  bar.style.width = String(pctBar) + '%';
  bar.classList.remove('bg-primary', 'bg-success');
  if(pctRaw >= 100){
    bar.classList.add('bg-success');
    bar.classList.remove('progress-bar-striped', 'progress-bar-animated');
  } else {
    bar.classList.add('bg-primary');
    bar.classList.add('progress-bar-striped', 'progress-bar-animated');
  }

  label.textContent = `${pctText} % — ${msToHMSFromSeconds(totalSeg)} / ${msToHMSFromSeconds(META_JORNADA_SEG)}`;
  if(root){
    root.setAttribute('aria-valuenow', String(Math.round(pctBar)));
  }
}

function updateUserSidebarJornada(){
  const timeEl = document.getElementById('user-jornada-time');
  const bar = document.getElementById('user-jornada-bar');
  const pctLabel = document.getElementById('user-jornada-pct-label');
  const root = document.getElementById('user-jornada-progress-root');
  if(!timeEl || !bar || !pctLabel) return;

  const totalSeg = getContadorDiarioSegundos();
  timeEl.textContent = msToHMSFromSeconds(totalSeg);
  const pctRaw = META_JORNADA_SEG > 0 ? (totalSeg / META_JORNADA_SEG) * 100 : 0;
  const pctBar = Math.min(100, Math.max(0, pctRaw));
  const pctText = Math.round(pctRaw * 10) / 10;

  bar.style.width = String(pctBar) + '%';
  bar.classList.remove('bg-primary', 'bg-success');
  if(pctRaw >= 100){
    bar.classList.add('bg-success');
  } else {
    bar.classList.add('bg-primary');
  }

  pctLabel.textContent = `${pctText}% completado`;
  if(root){
    root.setAttribute('aria-valuenow', String(Math.round(pctBar)));
  }
}

/** Totales en pastillas: mismas longitudes que los arrays usados en renderColumn. */
function updatePillCountLabels(nAsignadas, nIniciadas, nFinalizadas){
  const root = document.querySelector('.user-status-pills');
  if(!root) return;
  const pairs = [
    ['asignadas', nAsignadas],
    ['iniciadas', nIniciadas],
    ['finalizadas', nFinalizadas],
  ];
  for(const [key, n] of pairs){
    const el = root.querySelector(`[data-pill-count="${key}"]`);
    if(el) el.textContent = String(n);
  }
}

function adjustActiveMobileColumn(board){
  const keys = ['asignadas', 'iniciadas', 'finalizadas'];
  if(!keys.includes(activeMobileColumn)) activeMobileColumn = 'asignadas';
  const curLen = (board[activeMobileColumn] || []).length;
  if(curLen === 0){
    const next = keys.find(k => (board[k] || []).length > 0);
    if(next) activeMobileColumn = next;
  }
  document.querySelectorAll('.user-status-pill').forEach(p => {
    const col = p.dataset.column;
    const sel = col === activeMobileColumn;
    p.classList.toggle('active', sel);
    p.setAttribute('aria-selected', sel ? 'true' : 'false');
  });
}

function syncColumnVisibility(){
  const wrap = document.querySelector('.user-board-wrap');
  if(!wrap) return;
  const isDesktop = window.matchMedia('(min-width: 992px)').matches;
  wrap.querySelectorAll('.board-column').forEach(col => {
    const key = col.dataset.columnKey;
    if(isDesktop){
      col.classList.add('user-col-visible');
    } else {
      col.classList.toggle('user-col-visible', key === activeMobileColumn);
    }
  });
}

function tickRunningTimers(){
  for(const r of runningCards){
    if(!Number.isFinite(r.inicioUnixMs)) continue;
    const elapsedSeg = Math.floor((Date.now() - r.inicioUnixMs) / 1000);
    const totalSeg = r.baseSeg + Math.max(0, elapsedSeg);
    if(r.timeEl) r.timeEl.textContent = msToHMSFromSeconds(totalSeg);
  }
  updateJornadaProgress();
  updateUserSidebarJornada();
}

async function refreshBoard(){
  const res = await fetch(API_BOARD, { method:'GET', credentials:'same-origin' });
  const data = await res.json().catch(() => ({ ok:false, message:'Respuesta inválida del servidor' }));
  if(!data.ok){
    throw new Error(data.message || 'Error al cargar tablero');
  }

  const asignadas = data.board.asignadas || [];
  const iniciadas = data.board.iniciadas || [];
  const finalizadas = data.board.finalizadas || [];

  jornadaBaseSeg = sumAllCardsBaseSeg(data.board);

  const elA = document.getElementById('col-asignadas');
  const elI = document.getElementById('col-iniciadas');
  const elF = document.getElementById('col-finalizadas');
  const pagerA = document.getElementById('pager-asignadas');
  const pagerI = document.getElementById('pager-iniciadas');
  const pagerF = document.getElementById('pager-finalizadas');

  // Se limpia una sola vez por refresco completo del tablero.
  runningCards = [];

  renderColumn(elA, pagerA, asignadas, 'asignadas');
  renderColumn(elI, pagerI, iniciadas, 'iniciadas');
  renderColumn(elF, pagerF, finalizadas, 'finalizadas');

  // Contadores = totales del API (mismas longitudes que las columnas recién pintadas).
  updatePillCountLabels(asignadas.length, iniciadas.length, finalizadas.length);
  adjustActiveMobileColumn(data.board);
  syncColumnVisibility();

  // rebind de eventos (por refresh)
  bindActions();

  // Asegura que el tiempo en curso arranque visible inmediatamente (incluye barra de jornada si aplica).
  tickRunningTimers();
  ensureTimerLoop();
}

function bindActions(){
  document.querySelectorAll('[data-action]').forEach(btn => {
    // evitar duplicar listener
    btn.removeEventListener('click', onActionClick);
    btn.addEventListener('click', onActionClick);
  });

  document.querySelectorAll('[data-page-action]').forEach(btn => {
    btn.removeEventListener('click', onPageClick);
    btn.addEventListener('click', onPageClick);
  });

  const searchInput = document.getElementById('search-actividad');
  if (searchInput && !searchInput.dataset.bound) {
    searchInput.dataset.bound = '1';
    searchInput.addEventListener('input', () => {
      searchQuery = String(searchInput.value || '').trim().toLowerCase();
      columnPage.asignadas = 1;
      columnPage.iniciadas = 1;
      columnPage.finalizadas = 1;
      refreshBoard().catch(err => {
        console.error(err);
        alert(err.message || 'Error al aplicar búsqueda');
      });
    });
  }

  const sortSel = document.getElementById('board-sort-order');
  if(sortSel && !sortSel.dataset.bound){
    sortSel.dataset.bound = '1';
    sortSel.addEventListener('change', () => {
      boardSortOrder = sortSel.value || 'nombre';
      refreshBoard().catch(err => {
        console.error(err);
        alert(err.message || 'Error al ordenar');
      });
    });
  }

  document.querySelectorAll('.user-status-pill').forEach(pill => {
    if(pill.dataset.bound) return;
    pill.dataset.bound = '1';
    pill.addEventListener('click', () => {
      activeMobileColumn = pill.dataset.column || 'asignadas';
      document.querySelectorAll('.user-status-pill').forEach(p => {
        const sel = p.dataset.column === activeMobileColumn;
        p.classList.toggle('active', sel);
        p.setAttribute('aria-selected', sel ? 'true' : 'false');
      });
      syncColumnVisibility();
    });
  });

  if(!document.documentElement.dataset.boardKbd){
    document.documentElement.dataset.boardKbd = '1';
    document.addEventListener('keydown', e => {
      if((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')){
        const inp = document.getElementById('search-actividad');
        if(inp){
          e.preventDefault();
          inp.focus();
        }
      }
    });
  }
}

function onPageClick(e){
  const btn = e.currentTarget;
  const pageAction = btn.dataset.pageAction;
  const columnKey = btn.dataset.columnKey;
  if(!columnKey || !columnPage[columnKey]) return;

  if(pageAction === 'prev'){
    columnPage[columnKey] = Math.max(1, Number(columnPage[columnKey]) - 1);
  } else if(pageAction === 'next'){
    columnPage[columnKey] = Number(columnPage[columnKey]) + 1;
  }
  refreshBoard().catch(err => {
    console.error(err);
    alert(err.message || 'Error al cambiar de página');
  });
}

async function onActionClick(e){
  const btn = e.currentTarget;
  const actividadId = btn.dataset.actividadId;
  const accion = btn.dataset.action;

  setLoadingState(btn, true);
  try{
    if(accion === 'history'){
      await openHistory(actividadId);
      return;
    }
    if(accion === 'notes'){
      openNotasModal(actividadId, btn.dataset.actividadTitle || '');
      return;
    }

    const fd = new FormData();
    fd.append('csrf_token', getCsrfToken());
    fd.append('actividad_id', actividadId);
    fd.append('accion', accion);

    const res = await fetch(API_ACTION, {
      method:'POST',
      body: fd,
      credentials:'same-origin',
    });
    const data = await res.json().catch(() => ({ ok:false, message:'Respuesta inválida del servidor' }));
    if(!data.ok){
      alert(data.message || 'Acción no permitida');
      return;
    }

    await refreshBoard();
  }catch(err){
    alert(err.message || 'Error');
  }finally{
    setLoadingState(btn, false);
  }
}

function openNotasModal(actividadId, titulo){
  const modalEl = document.getElementById('actividadNotasModal');
  const sub = document.getElementById('actividadNotasModalSub');
  const inpFallecido = document.getElementById('actividadNotasFallecido');
  const inpFecha = document.getElementById('actividadNotasFecha');
  const inpObs = document.getElementById('actividadNotasObservaciones');
  if(!modalEl || !sub || !inpFallecido || !inpFecha || !inpObs) return;

  const ahora = new Date();
  notasContext = {
    actividadId: Number(actividadId) || 0,
    titulo: String(titulo || 'Actividad'),
    fechaIso: ahora.toISOString(),
  };
  sub.textContent = notasContext.titulo;
  applyNotasCampoPrincipalLabels(notasContext.actividadId);

  inpFallecido.value = '';
  inpObs.value = '';
  inpFecha.value = formatNotaFechaDisplay(ahora);

  const modal = window.bootstrap?.Modal?.getOrCreateInstance(modalEl);
  if(modal){
    modal.show();
    window.setTimeout(() => inpFallecido.focus(), 280);
  }
}

function formatEsCoDate(dateStr){
  if(!dateStr) return '--';
  const d = new Date(dateStr);
  if(Number.isNaN(d.getTime())) return '--';
  return d.toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' });
}

async function openHistory(actividadId){
  const modalEl = document.getElementById('historialModal');
  const tbody = document.getElementById('historialTbody');
  const totalAc = document.getElementById('historialTotalAcumulado');
  const totalAll = document.getElementById('historialTotal');
  const sub = document.getElementById('historialModalSub');

  tbody.innerHTML = `<tr><td colspan="5" class="text-muted small">Cargando...</td></tr>`;
  totalAc.textContent = '--';
  totalAll.textContent = '--';
  sub.textContent = 'Cargando...';

  const res = await fetch(`${API_HISTORIAL}?actividad_id=${encodeURIComponent(actividadId)}`, {
    method:'GET',
    credentials:'same-origin',
  });
  const data = await res.json().catch(() => ({ ok:false, message:'Respuesta inválida del servidor' }));
  if(!data.ok){
    throw new Error(data.message || 'Error al cargar historial');
  }

  const actividad = data.actividad || {};
  const tiempo = data.tiempo || {};
  const intervalos = data.intervalos || [];

  sub.textContent = `${actividad.titulo || 'Actividad'} - Estado: ${actividad.estado_slug || ''}`;
  totalAc.textContent = msToHMSFromSeconds(tiempo.tiempo_acumulado_seg || 0);
  totalAll.textContent = msToHMSFromSeconds(tiempo.tiempo_total_seg || 0);

  if(intervalos.length === 0){
    tbody.innerHTML = `<tr><td colspan="5" class="text-muted small">Sin historial aún.</td></tr>`;
  } else {
    tbody.innerHTML = intervalos.map((it, idx) => {
      const finTxt = it.fin_at ? formatEsCoDate(it.fin_at) : '<span class="text-warning fw-semibold">En curso</span>';
      const inicioTxt = formatEsCoDate(it.inicio_at);
      const durTxt = msToHMSFromSeconds(it.duracion_seg || 0);
      const eventoTxt = it.evento ? String(it.evento) : '--';
      return `
        <tr>
          <td>${idx + 1}</td>
          <td>${inicioTxt}</td>
          <td>${finTxt}</td>
          <td class="time-badge">${durTxt}</td>
          <td>${eventoTxt}</td>
        </tr>
      `;
    }).join('');
  }

  const modal = window.bootstrap?.Modal?.getOrCreateInstance(modalEl);
  if(modal){
    modal.show();
  }
}

async function initBoard(){
  try{
    if(!window.__boardResizeBound){
      window.__boardResizeBound = '1';
      window.addEventListener('resize', () => syncColumnVisibility());
    }
    const notasSaveBtn = document.getElementById('actividadNotasSaveBtn');
    if(notasSaveBtn && !notasSaveBtn.dataset.bound){
      notasSaveBtn.dataset.bound = '1';
      notasSaveBtn.addEventListener('click', async () => {
        const inpFallecido = document.getElementById('actividadNotasFallecido');
        const inpObs = document.getElementById('actividadNotasObservaciones');
        if(!inpFallecido || !inpObs) return;

        const fechaGuardar = new Date().toISOString();
        setLoadingState(notasSaveBtn, true);
        try{
          const nota = await saveNotaActividad({
            actividadId: notasContext.actividadId,
            fallecido: inpFallecido.value,
            fecha: fechaGuardar,
            observaciones: inpObs.value,
          });
          if(nota && nota.fecha){
            notasContext.fechaIso = String(nota.fecha);
            const inpFecha = document.getElementById('actividadNotasFecha');
            if(inpFecha){
              inpFecha.value = formatNotaFechaDisplay(nota.fecha);
            }
          }
          const modalEl = document.getElementById('actividadNotasModal');
          const modal = window.bootstrap?.Modal?.getOrCreateInstance(modalEl);
          if(modal){
            modal.hide();
          }
        }catch(err){
          alert(err.message || 'Error al guardar la nota');
        }finally{
          setLoadingState(notasSaveBtn, false);
        }
      });
    }
    await refreshBoard();
    tickRunningTimers();
    ensureTimerLoop();
  }catch(err){
    console.error(err);
    alert(err.message || 'Error al iniciar tablero');
  }
}

// Inicialización única del tablero.
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initBoard);
} else {
  initBoard();
}

