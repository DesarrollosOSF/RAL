/* global window */

(function () {
  const API_REQUERIMIENTOS = String(window.__REQ_API__ || 'api/requerimientos.php');
  const CSRF_TOKEN = String(window.__REQ_CSRF__ || '');
  const SEDE_ID = Number(window.__REQ_SEDE_ID__ || 0);
  const UPLOAD_CFG = window.__REQ_UPLOAD__ || {};
  const MAX_BYTES = Number(UPLOAD_CFG.maxBytes) || 5 * 1024 * 1024;

  const MIME_IMAGEN = new Set(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
  const EXT_IMAGEN = new Set(['jpg', 'jpeg', 'png', 'webp', 'gif']);
  const MIME_PDF = 'application/pdf';
  const EXT_PDF = 'pdf';

  let filterEstado = 'todos';
  let searchQuery = '';
  let adjuntoFile = null;
  let requerimientos = [];
  let loadingList = false;

  const form = document.getElementById('req-form');
  const submitBtn = document.getElementById('req-submit-btn');
  const listEl = document.getElementById('req-list');
  const emptyEl = document.getElementById('req-empty');
  const countBadge = document.getElementById('req-count-badge');
  const alertZone = document.getElementById('req-alert-zone');
  const searchInput = document.getElementById('req-search');
  const adjuntoPanel = document.getElementById('req-adjunto-panel');
  const adjuntoInput = document.getElementById('req-adjunto-file');
  const adjuntoDrop = document.getElementById('req-adjunto-drop');
  const adjuntoDropText = document.getElementById('req-adjunto-drop-text');
  const adjuntoDropHint = document.getElementById('req-adjunto-drop-hint');
  const adjuntoPreview = document.getElementById('req-adjunto-preview');
  const adjuntoPreviewMedia = document.getElementById('req-adjunto-preview-media');
  const adjuntoPreviewName = document.getElementById('req-adjunto-preview-name');
  const adjuntoPreviewSize = document.getElementById('req-adjunto-preview-size');
  const adjuntoError = document.getElementById('req-adjunto-error');
  const adjuntoRemoveBtn = document.getElementById('req-adjunto-remove');

  function escapeHtml(text) {
    return String(text ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatFecha(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' });
  }

  function formatTamano(bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
    return `${(n / (1024 * 1024)).toFixed(2)} MB`;
  }

  function getFileExtension(name) {
    const parts = String(name || '').toLowerCase().split('.');
    return parts.length > 1 ? parts.pop() : '';
  }

  function inferirTipoAdjunto(nombre, ruta) {
    const ref = String(nombre || ruta || '').toLowerCase();
    return ref.endsWith('.pdf') ? 'pdf' : 'imagen';
  }

  async function parseJsonResponse(res) {
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch (_e) {
      throw new Error('Respuesta inválida del servidor. Verifica que api/requerimientos.php exista.');
    }
  }

  function setSubmitLoading(loading) {
    if (!submitBtn) return;
    submitBtn.disabled = loading;
    submitBtn.innerHTML = loading
      ? '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Enviando...'
      : '<i class="bi bi-send me-2"></i>Enviar requerimiento';
  }

  function showAlert(type, message) {
    if (!alertZone) return;
    alertZone.innerHTML = `
      <div class="alert alert-${type} border-0 shadow-sm d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'} flex-shrink-0 mt-1"></i>
        <span>${escapeHtml(message)}</span>
      </div>
    `;
    window.setTimeout(() => {
      if (alertZone) alertZone.innerHTML = '';
    }, 6000);
  }

  function setAdjuntoError(msg) {
    if (!adjuntoError) return;
    if (msg) {
      adjuntoError.textContent = msg;
      adjuntoError.hidden = false;
    } else {
      adjuntoError.textContent = '';
      adjuntoError.hidden = true;
    }
  }

  function getAdjuntoTipoSeleccionado() {
    const checked = document.querySelector('input[name="adjunto_tipo"]:checked');
    return checked ? String(checked.value) : 'ninguno';
  }

  function updateAdjuntoPanel() {
    const adjuntoTipo = getAdjuntoTipoSeleccionado();
    if (!adjuntoPanel || !adjuntoInput) return;

    if (adjuntoTipo === 'ninguno') {
      adjuntoPanel.hidden = true;
      clearAdjuntoFile(false);
      return;
    }

    adjuntoPanel.hidden = false;
    if (adjuntoTipo === 'imagen') {
      adjuntoInput.accept = '.jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif';
      if (adjuntoDropHint) adjuntoDropHint.textContent = 'JPG, PNG, WebP o GIF';
    } else {
      adjuntoInput.accept = '.pdf,application/pdf';
      if (adjuntoDropHint) adjuntoDropHint.textContent = 'Solo archivos PDF';
    }

    if (adjuntoDropText && !adjuntoFile) {
      adjuntoDropText.textContent = adjuntoTipo === 'imagen'
        ? 'Selecciona o arrastra tu imagen aquí'
        : 'Selecciona o arrastra tu PDF aquí';
    }
  }

  function clearAdjuntoFile(resetTipo) {
    adjuntoFile = null;
    if (adjuntoInput) adjuntoInput.value = '';
    setAdjuntoError('');
    if (adjuntoPreview) adjuntoPreview.hidden = true;
    if (adjuntoDrop) adjuntoDrop.hidden = false;
    if (adjuntoPreviewMedia) adjuntoPreviewMedia.innerHTML = '';
    if (resetTipo) {
      const ninguno = document.getElementById('req-adjunto-ninguno');
      if (ninguno) ninguno.checked = true;
      updateAdjuntoPanel();
    }
  }

  function validateAdjuntoFile(file, tipo) {
    if (!file) return 'Selecciona un archivo.';

    if (file.size > MAX_BYTES) {
      return `El archivo supera el tamaño máximo (${formatTamano(MAX_BYTES)}).`;
    }

    const ext = getFileExtension(file.name);
    const mime = String(file.type || '').toLowerCase();

    if (tipo === 'pdf') {
      if (mime !== MIME_PDF && ext !== EXT_PDF) {
        return 'Solo se permiten documentos PDF.';
      }
      return null;
    }

    if (tipo === 'imagen') {
      const mimeOk = MIME_IMAGEN.has(mime);
      const extOk = EXT_IMAGEN.has(ext);
      if (!mimeOk && !extOk) {
        return 'Solo se permiten imágenes JPG, PNG, WebP o GIF.';
      }
      return null;
    }

    return 'Tipo de adjunto no válido.';
  }

  function renderAdjuntoPreview(file, tipo) {
    if (!adjuntoPreview || !adjuntoDrop) return;

    adjuntoDrop.hidden = true;
    adjuntoPreview.hidden = false;

    if (adjuntoPreviewName) adjuntoPreviewName.textContent = file.name;
    if (adjuntoPreviewSize) adjuntoPreviewSize.textContent = formatTamano(file.size);

    if (!adjuntoPreviewMedia) return;
    adjuntoPreviewMedia.innerHTML = '';

    if (tipo === 'imagen') {
      const img = document.createElement('img');
      img.src = URL.createObjectURL(file);
      img.alt = 'Vista previa del adjunto';
      img.onload = () => URL.revokeObjectURL(img.src);
      adjuntoPreviewMedia.appendChild(img);
    } else {
      adjuntoPreviewMedia.innerHTML = '<i class="bi bi-file-earmark-pdf-fill user-req-adjunto-pdf-icon" aria-hidden="true"></i>';
    }
  }

  function handleAdjuntoSelection(fileList) {
    const file = fileList && fileList[0] ? fileList[0] : null;
    const tipo = getAdjuntoTipoSeleccionado();

    if (!file || tipo === 'ninguno') {
      clearAdjuntoFile(false);
      return;
    }

    const err = validateAdjuntoFile(file, tipo);
    if (err) {
      setAdjuntoError(err);
      adjuntoFile = null;
      if (adjuntoInput) adjuntoInput.value = '';
      if (adjuntoPreview) adjuntoPreview.hidden = true;
      if (adjuntoDrop) adjuntoDrop.hidden = false;
      return;
    }

    setAdjuntoError('');
    adjuntoFile = file;
    renderAdjuntoPreview(file, tipo);
  }

  function bindAdjunto() {
    document.querySelectorAll('input[name="adjunto_tipo"]').forEach(radio => {
      radio.addEventListener('change', () => {
        clearAdjuntoFile(false);
        updateAdjuntoPanel();
      });
    });

    if (adjuntoInput) {
      adjuntoInput.addEventListener('change', () => handleAdjuntoSelection(adjuntoInput.files));
    }

    if (adjuntoRemoveBtn) {
      adjuntoRemoveBtn.addEventListener('click', () => clearAdjuntoFile(false));
    }

    if (adjuntoDrop) {
      ['dragenter', 'dragover'].forEach(evt => {
        adjuntoDrop.addEventListener(evt, e => {
          e.preventDefault();
          adjuntoDrop.classList.add('is-dragover');
        });
      });
      ['dragleave', 'drop'].forEach(evt => {
        adjuntoDrop.addEventListener(evt, e => {
          e.preventDefault();
          adjuntoDrop.classList.remove('is-dragover');
        });
      });
      adjuntoDrop.addEventListener('drop', e => {
        const files = e.dataTransfer?.files;
        if (files && files.length) handleAdjuntoSelection(files);
      });
    }

    updateAdjuntoPanel();
  }

  function labelEstado(estado) {
    const map = {
      pendiente: 'Pendiente',
      en_tramite: 'En trámite',
      cerrado: 'Cerrado',
    };
    return map[estado] || 'Pendiente';
  }

  function labelPrioridad(p) {
    const map = { baja: 'Baja', media: 'Media', alta: 'Alta', urgente: 'Urgente' };
    return map[p] || 'Media';
  }

  function getSelectedDependencia() {
    const sel = document.getElementById('req-dependencia');
    if (!sel || sel.disabled) {
      return { id: 0 };
    }
    return { id: Number(sel.value) || 0 };
  }

  function renderArchivoAdjunto(it) {
    if (!it.adjunto || !it.adjunto_url) return '';

    const tipo = inferirTipoAdjunto(it.adjunto_nombre, it.adjunto);
    const labelTipo = tipo === 'pdf' ? 'PDF' : 'Imagen';
    const nombre = it.adjunto_nombre || 'Adjunto';

    return `
      <div class="user-req-item-adjunto">
        <a href="${escapeHtml(it.adjunto_url)}" class="user-req-item-adjunto-link" target="_blank" rel="noopener noreferrer">
          <i class="bi bi-${tipo === 'pdf' ? 'file-earmark-pdf' : 'image'}"></i>
          <span>${escapeHtml(labelTipo)}: ${escapeHtml(nombre)}</span>
        </a>
      </div>
    `;
  }

  function filterItems(items) {
    let out = items.slice();
    if (filterEstado !== 'todos') {
      out = out.filter(it => (it.estado || 'pendiente') === filterEstado);
    }
    if (searchQuery) {
      const q = searchQuery.toLowerCase();
      out = out.filter(it => {
        const hay = [
          it.asunto,
          it.sede_nombre,
          it.dependencia_nombre,
          it.descripcion,
          it.adjunto_nombre,
        ].join(' ').toLowerCase();
        return hay.includes(q);
      });
    }
    return out;
  }

  function renderList() {
    const visible = filterItems(requerimientos);

    if (countBadge) {
      const n = requerimientos.length;
      countBadge.textContent = n === 1 ? '1 registrado' : `${n} registrados`;
    }

    if (!listEl) return;

    if (loadingList) {
      listEl.innerHTML = `
        <div class="user-req-empty">
          <div class="spinner-border text-primary mb-2" role="status" aria-hidden="true"></div>
          <p class="text-muted small mb-0">Cargando requerimientos...</p>
        </div>
      `;
      return;
    }

    if (visible.length === 0) {
      listEl.innerHTML = '';
      if (emptyEl) {
        emptyEl.hidden = false;
        emptyEl.classList.remove('d-none');
        listEl.appendChild(emptyEl);
        const msg = requerimientos.length > 0
          ? 'No hay requerimientos con este filtro.'
          : 'Completa el formulario para registrar el primero.';
        const p = emptyEl.querySelector('p.text-muted');
        if (p) p.textContent = msg;
      }
      return;
    }

    if (emptyEl) emptyEl.hidden = true;

    listEl.innerHTML = visible.map(it => {
      const estado = it.estado || 'pendiente';
      const prioridad = it.prioridad || 'media';
      const depNombre = it.dependencia_nombre || '';
      const dependencia = depNombre
        ? `<span><i class="bi bi-diagram-3"></i>${escapeHtml(depNombre)}</span>`
        : '';
      const adjuntoHtml = renderArchivoAdjunto(it);
      return `
        <article class="user-req-item" data-req-id="${escapeHtml(it.id_requerimiento)}">
          <div class="user-req-item-head">
            <h3 class="user-req-item-title">${escapeHtml(it.asunto)}</h3>
            <div class="user-req-item-badges">
              <span class="user-req-priority user-req-priority--${escapeHtml(prioridad)}">${escapeHtml(labelPrioridad(prioridad))}</span>
              <span class="user-req-status user-req-status--${escapeHtml(estado)}">${escapeHtml(labelEstado(estado))}</span>
            </div>
          </div>
          <div class="user-req-item-meta">
            <span><i class="bi bi-building"></i>${escapeHtml(it.sede_nombre || 'Sin sede')}</span>
            ${dependencia}
            <span><i class="bi bi-clock"></i>${escapeHtml(formatFecha(it.creado_at))}</span>
          </div>
          <p class="user-req-item-desc">${escapeHtml(it.descripcion)}</p>
          ${adjuntoHtml}
        </article>
      `;
    }).join('');
  }

  async function fetchRequerimientos() {
    loadingList = true;
    renderList();
    try {
      const res = await fetch(API_REQUERIMIENTOS, {
        method: 'GET',
        credentials: 'same-origin',
      });
      const data = await parseJsonResponse(res);
      if (!data.ok) {
        throw new Error(data.message || 'No se pudieron cargar los requerimientos.');
      }
      requerimientos = Array.isArray(data.requerimientos) ? data.requerimientos : [];
    } catch (err) {
      requerimientos = [];
      showAlert('danger', err.message || 'Error al cargar los requerimientos.');
    } finally {
      loadingList = false;
      renderList();
    }
  }

  function bindFilters() {
    document.querySelectorAll('[data-req-filter]').forEach(btn => {
      btn.addEventListener('click', () => {
        filterEstado = btn.dataset.reqFilter || 'todos';
        document.querySelectorAll('[data-req-filter]').forEach(b => {
          const active = b === btn;
          b.classList.toggle('active', active);
          b.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        renderList();
      });
    });

    if (searchInput) {
      searchInput.addEventListener('input', () => {
        searchQuery = String(searchInput.value || '').trim().toLowerCase();
        renderList();
      });
    }
  }

  if (form) {
    form.addEventListener('submit', async e => {
      e.preventDefault();

      const asunto = String(document.getElementById('req-titulo')?.value || '').trim();
      const dependencia = getSelectedDependencia();
      const prioridad = String(document.getElementById('req-prioridad')?.value || 'media');
      const descripcion = String(document.getElementById('req-descripcion')?.value || '').trim();
      const tipoAdjunto = getAdjuntoTipoSeleccionado();

      if (SEDE_ID <= 0) {
        showAlert('danger', 'Tu usuario no tiene una sede asignada. Contacta al administrador.');
        return;
      }

      if (!asunto || !descripcion) {
        showAlert('danger', 'Completa los campos obligatorios: asunto y descripción.');
        return;
      }

      if (tipoAdjunto !== 'ninguno' && !adjuntoFile) {
        setAdjuntoError('Debes seleccionar un archivo o elegir "Sin adjunto".');
        showAlert('danger', 'Selecciona un archivo válido o indica que no llevará adjunto.');
        return;
      }

      if (adjuntoFile) {
        const err = validateAdjuntoFile(adjuntoFile, tipoAdjunto);
        if (err) {
          setAdjuntoError(err);
          showAlert('danger', err);
          return;
        }
      }

      const fd = new FormData();
      fd.append('csrf_token', CSRF_TOKEN);
      fd.append('asunto', asunto);
      fd.append('id_dependencia', dependencia.id > 0 ? String(dependencia.id) : '');
      fd.append('prioridad', prioridad);
      fd.append('descripcion', descripcion);
      fd.append('adjunto_tipo', tipoAdjunto);
      if (adjuntoFile && tipoAdjunto !== 'ninguno') {
        fd.append('adjunto', adjuntoFile, adjuntoFile.name);
      }

      setSubmitLoading(true);
      try {
        const res = await fetch(API_REQUERIMIENTOS, {
          method: 'POST',
          credentials: 'same-origin',
          body: fd,
        });
        const data = await parseJsonResponse(res);
        if (!data.ok) {
          throw new Error(data.message || 'No se pudo registrar el requerimiento.');
        }

        form.reset();
        const pri = document.getElementById('req-prioridad');
        if (pri) pri.value = 'media';
        clearAdjuntoFile(true);

        showAlert('success', data.message || 'Requerimiento registrado correctamente.');
        filterEstado = 'todos';
        document.querySelectorAll('[data-req-filter]').forEach(b => {
          const todos = b.dataset.reqFilter === 'todos';
          b.classList.toggle('active', todos);
          b.setAttribute('aria-selected', todos ? 'true' : 'false');
        });
        if (searchInput) searchInput.value = '';
        searchQuery = '';

        if (data.requerimiento) {
          requerimientos.unshift(data.requerimiento);
          renderList();
        } else {
          await fetchRequerimientos();
        }
      } catch (err) {
        showAlert('danger', err.message || 'Error al enviar el requerimiento.');
      } finally {
        setSubmitLoading(false);
      }
    });
  }

  bindAdjunto();
  bindFilters();
  fetchRequerimientos();
})();
