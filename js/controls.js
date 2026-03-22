let allControls = [];
let editingId = null;

async function loadControls() {
  allControls = await api.get('/api/controls.php');
  renderControls();
  updateSubtitle();
}

function updateSubtitle() {
  const compliant = allControls.filter(c => c.status === 'compliant').length;
  document.getElementById('controls-subtitle').textContent =
    `${allControls.length} controls — ${compliant} compliant (${Math.round(compliant/allControls.length*100)||0}%)`;
}

function filterControls() {
  const q = document.getElementById('search').value.toLowerCase();
  const cat = document.getElementById('filter-category').value;
  const status = document.getElementById('filter-status').value;

  const filtered = allControls.filter(c => {
    if (cat && c.category !== cat) return false;
    if (status && c.status !== status) return false;
    if (q && !c.id.toLowerCase().includes(q) && !c.name.toLowerCase().includes(q) &&
        !c.description.toLowerCase().includes(q) && !c.owner.toLowerCase().includes(q)) return false;
    return true;
  });

  renderControls(filtered);
  document.getElementById('controls-count').textContent = `${filtered.length} control${filtered.length !== 1 ? 's' : ''}`;
}

function renderControls(controls = allControls) {
  const tbody = document.getElementById('controls-tbody');
  document.getElementById('controls-count').textContent = `${controls.length} control${controls.length !== 1 ? 's' : ''}`;

  if (!controls.length) {
    tbody.innerHTML = `<tr><td colspan="8"><div class="empty-state"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><h3>No controls match</h3><p>Try adjusting your filters</p></div></td></tr>`;
    return;
  }

  tbody.innerHTML = controls.map(c => `
    <tr>
      <td><span class="td-mono">${escHtml(c.id)}</span></td>
      <td><span class="td-muted" style="font-size:12px">${escHtml(c.categoryName)}</span></td>
      <td class="td-name" style="max-width:280px">
        <span title="${escHtml(c.description)}">${escHtml(c.name)}</span>
        ${c.notes ? `<div class="td-muted" style="font-size:12px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:260px">${escHtml(c.notes)}</div>` : ''}
      </td>
      <td>${badge(c.status)}</td>
      <td class="td-muted">${escHtml(c.owner) || '—'}</td>
      <td class="td-muted">${formatDate(c.dueDate)}</td>
      <td>
        ${c.evidenceIds && c.evidenceIds.length
          ? `<a href="/evidence.html" style="font-size:13px;font-weight:500">${c.evidenceIds.length} file${c.evidenceIds.length>1?'s':''}</a>`
          : `<span class="td-muted">None</span>`}
      </td>
      <td>
        <div class="td-actions">
          <button class="btn btn-ghost btn-sm" onclick="openEdit('${escHtml(c.id)}')">Edit</button>
          ${c.status !== 'compliant'
            ? `<button class="btn btn-ghost btn-sm" onclick="quickMark('${escHtml(c.id)}', 'compliant')" title="Mark compliant" style="color:var(--green-500)">✓</button>`
            : `<button class="btn btn-ghost btn-sm" onclick="quickMark('${escHtml(c.id)}', 'not_started')" title="Reset" style="color:var(--slate-100)">↩</button>`}
        </div>
      </td>
    </tr>`).join('');
}

function openEdit(id) {
  const c = allControls.find(x => x.id === id);
  if (!c) return;
  editingId = id;
  document.getElementById('edit-modal-title').textContent = `Edit ${c.id}`;
  document.getElementById('edit-control-id').textContent = c.id + ' — ' + c.categoryName;
  document.getElementById('edit-control-name').textContent = c.name;
  document.getElementById('edit-control-desc').textContent = c.description;
  document.getElementById('edit-status').value = c.status;
  document.getElementById('edit-owner').value = c.owner || '';
  document.getElementById('edit-due').value = c.dueDate || '';
  document.getElementById('edit-notes').value = c.notes || '';
  openModal('edit-modal');
}

async function saveControl() {
  if (!editingId) return;
  const body = {
    status:  document.getElementById('edit-status').value,
    owner:   document.getElementById('edit-owner').value.trim(),
    dueDate: document.getElementById('edit-due').value,
    notes:   document.getElementById('edit-notes').value.trim(),
  };
  try {
    const updated = await api.put(`/api/controls.php?id=${editingId}`, body);
    const idx = allControls.findIndex(c => c.id === editingId);
    if (idx > -1) Object.assign(allControls[idx], updated);
    filterControls();
    updateSubtitle();
    closeModal('edit-modal');
    toast('Control updated');
  } catch (e) {
    toast('Error: ' + e.message, 'error');
  }
}

async function quickMark(id, status) {
  try {
    const updated = await api.put(`/api/controls.php?id=${id}`, { status });
    const idx = allControls.findIndex(c => c.id === id);
    if (idx > -1) Object.assign(allControls[idx], updated);
    filterControls();
    updateSubtitle();
    toast(status === 'compliant' ? 'Marked compliant ✓' : 'Status reset');
  } catch (e) {
    toast('Error: ' + e.message, 'error');
  }
}

function exportCSV() {
  const headers = ['ID','Category','Name','Status','Owner','Due Date','Evidence Count','Notes'];
  const rows = allControls.map(c => [
    c.id, c.categoryName, `"${c.name.replace(/"/g,'""')}"`,
    c.status, c.owner, c.dueDate,
    (c.evidenceIds||[]).length,
    `"${(c.notes||'').replace(/"/g,'""')}"`
  ]);
  const csv = [headers, ...rows].map(r => r.join(',')).join('\n');
  const a = document.createElement('a');
  a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
  a.download = `soc2-controls-${new Date().toISOString().split('T')[0]}.csv`;
  a.click();
}

document.addEventListener('DOMContentLoaded', loadControls);
