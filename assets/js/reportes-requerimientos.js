/* global window */

(function () {
  const API = String(window.__REPORT_REQ_API__ || 'api/requerimientos_admin.php');
  const CSRF = String(window.__REPORT_REQ_CSRF__ || '');
  const alertZone = document.getElementById('report-req-alert');

  function showAlert(type, message) {
    if (!alertZone) return;
    alertZone.innerHTML = `
      <div class="alert alert-${type} border-0 shadow-sm d-flex align-items-start gap-2 mb-3" role="alert">
        <i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'} flex-shrink-0 mt-1"></i>
        <span>${String(message ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</span>
      </div>
    `;
    window.setTimeout(() => {
      if (alertZone) alertZone.innerHTML = '';
    }, 4500);
  }

  function updateSelectStyle(sel) {
    sel.classList.remove(
      'report-req-estado-select--pendiente',
      'report-req-estado-select--en_tramite',
      'report-req-estado-select--cerrado'
    );
    sel.classList.add(`report-req-estado-select--${sel.value}`);
  }

  async function cambiarEstado(sel) {
    const reqId = Number(sel.dataset.reqId);
    const prev = String(sel.dataset.prev || sel.value);
    const estado = String(sel.value);

    if (!reqId || estado === prev) return;

    sel.disabled = true;
    try {
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('id_requerimiento', String(reqId));
      fd.append('estado', estado);

      const res = await fetch(API, {
        method: 'POST',
        credentials: 'same-origin',
        body: fd,
      });
      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (_e) {
        throw new Error('Respuesta inválida del servidor.');
      }

      if (!data.ok) {
        throw new Error(data.message || 'No se pudo actualizar el estado.');
      }

      sel.dataset.prev = estado;
      updateSelectStyle(sel);
      document.querySelectorAll(`.report-req-estado-select[data-req-id="${reqId}"]`).forEach(other => {
        if (other !== sel) {
          other.value = estado;
          other.dataset.prev = estado;
          updateSelectStyle(other);
        }
      });
      showAlert('success', 'Estado actualizado correctamente.');
    } catch (err) {
      sel.value = prev;
      updateSelectStyle(sel);
      showAlert('danger', err.message || 'Error al cambiar el estado.');
    } finally {
      sel.disabled = false;
    }
  }

  document.querySelectorAll('.report-req-estado-select').forEach(sel => {
    updateSelectStyle(sel);
    sel.addEventListener('change', () => cambiarEstado(sel));
  });
})();
