let allEvidence = [];
let allControls = [];
let selectedFile = null;
let editingId = null;

async function load() {
  [allEvidence, allControls] = await Promise.all([
    api.get('/api/evidence.php'),
    api.get('/api/controls.php'),
  ]);
  populateControlFilter();
  populateControlCheckboxes('control-checkboxes', []);
  renderEvidence();
  updateSubtitle();
}

function updateSubtitle() {
  document.getElementById('evidence-subtitle').textContent =
    `${allEvidence.length} file${allEvidence.length !== 1 ? 's' : ''} uploaded`;
}

function populateControlFilter() {
  const sel = document.getElementById('filter-control');
  allControls.forEach(c => {
    const opt = document.createElement('option');
    opt.value = c.id; opt.textContent = `${c.id} — ${c.name.substring(0,40)}`;
    sel.appendChild(opt);
  });
}

function populateControlCheckboxes(containerId, selected) {
  const container = document.getElementById(containerId);
  if (!container) return;
  container.innerHTML = allControls.map(c => `
    <label style="display:flex;align-items:center;gap:6px;padding:3px 4px;cursor:pointer;border-radius:4px;font-size:12px" title="${escHtml(c.name)}">
      <input type="checkbox" value="${escHtml(c.id)}" ${selected.includes(c.id) ? 'checked' : ''} style="accent-color:var(--blue-500)">
      <span style="font-family:monospace;color:var(--blue-600);font-weight:600">${escHtml(c.id)}</span>
    </label>`).join('');
}

function getCheckedControls(containerId) {
  return Array.from(document.querySelectorAll(`#${containerId} input[type=checkbox]:checked`)).map(cb => cb.value);
}

function filterEvidence() {
  const q = document.getElementById('search').value.toLowerCase();
  const ctrl = document.getElementById('filter-control').value;
  const filtered = allEvidence.filter(e => {
    if (ctrl && !(e.controlIds || []).includes(ctrl)) return false;
    if (q && !e.filename.toLowerCase().includes(q) && !(e.description||'').toLowerCase().includes(q)) return false;
    return true;
  });
  renderEvidence(filtered);
}

function renderEvidence(evidence = allEvidence) {
  const tbody = document.getElementById('evidence-tbody');
  document.getElementById('evidence-count').textContent = `${evidence.length} file${evidence.length !== 1 ? 's' : ''}`;

  if (!evidence.length) {
    tbody.innerHTML = `<tr><td colspan="6"><div class="empty-state">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      <h3>No evidence files yet</h3><p>Upload files using the button above</p></div></td></tr>`;
    return;
  }

  const iconMap = {
    'application/pdf': '📄', 'image/png': '🖼', 'image/jpeg': '🖼',
    'text/csv': '📊', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': '📊',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document': '📝',
    'application/zip': '🗜', 'text/plain': '📃',
  };

  tbody.innerHTML = evidence.map(e => {
    const icon = iconMap[e.mimeType] || '📎';
    const controls = (e.controlIds || []).map(id =>
      `<span class="badge badge-not_started" style="font-family:monospace;font-size:11px">${escHtml(id)}</span>`
    ).join(' ');
    return `<tr>
      <td>
        <div style="display:flex;align-items:center;gap:8px">
          <span style="font-size:18px">${icon}</span>
          <div>
            <div style="font-weight:500;font-size:13px">${escHtml(e.filename)}</div>
            <div class="td-muted" style="font-size:11px">${formatBytes(e.size)}</div>
          </div>
        </div>
      </td>
      <td class="td-muted">${escHtml(e.description) || '—'}</td>
      <td>${controls || '<span class="td-muted">—</span>'}</td>
      <td class="td-muted">${formatBytes(e.size)}</td>
      <td class="td-muted">${e.uploadedAt ? e.uploadedAt.split(' ')[0] : '—'}</td>
      <td>
        <div class="td-actions">
          <a href="/api/evidence.php?download=${escHtml(e.id)}" class="btn btn-ghost btn-sm" title="Download">↓</a>
          <button class="btn btn-ghost btn-sm" onclick="openEdit('${escHtml(e.id)}')">Edit</button>
          <button class="btn btn-ghost btn-sm" style="color:var(--red-500)" onclick="deleteEvidence('${escHtml(e.id)}')">Delete</button>
        </div>
      </td>
    </tr>`;
  }).join('');
}

// File selection
function handleFileSelect(e) {
  setFile(e.target.files[0]);
}
function handleDragOver(e) {
  e.preventDefault();
  document.getElementById('upload-zone').classList.add('dragover');
}
function handleDragLeave() {
  document.getElementById('upload-zone').classList.remove('dragover');
}
function handleDrop(e) {
  e.preventDefault();
  document.getElementById('upload-zone').classList.remove('dragover');
  if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]);
}
function setFile(file) {
  selectedFile = file;
  document.getElementById('selected-file-name').textContent = file ? `✓ ${file.name}` : '';
}

async function uploadEvidence() {
  if (!selectedFile) { toast('Please select a file', 'error'); return; }
  const desc = document.getElementById('upload-desc').value.trim();
  const controlIds = getCheckedControls('control-checkboxes');
  const btn = document.getElementById('upload-btn');
  btn.textContent = 'Uploading...'; btn.disabled = true;

  try {
    const fd = new FormData();
    fd.append('file', selectedFile);
    fd.append('description', desc);
    fd.append('controlIds', JSON.stringify(controlIds));
    const result = await api.upload('/api/evidence.php', fd);
    allEvidence.unshift(result);
    // Re-sync controls with updated evidenceIds
    [allEvidence, allControls] = await Promise.all([
      api.get('/api/evidence.php'),
      api.get('/api/controls.php'),
    ]);
    renderEvidence();
    updateSubtitle();
    closeModal('upload-modal');
    document.getElementById('upload-desc').value = '';
    document.getElementById('selected-file-name').textContent = '';
    document.getElementById('upload-input').value = '';
    populateControlCheckboxes('control-checkboxes', []);
    selectedFile = null;
    toast('Evidence uploaded successfully');
  } catch (e) {
    toast('Upload failed: ' + e.message, 'error');
  } finally {
    btn.textContent = 'Upload'; btn.disabled = false;
  }
}

function openEdit(id) {
  const e = allEvidence.find(x => x.id === id);
  if (!e) return;
  editingId = id;
  document.getElementById('edit-desc').value = e.description || '';
  populateControlCheckboxes('edit-control-checkboxes', e.controlIds || []);
  openModal('edit-modal');
}

async function saveEvidence() {
  if (!editingId) return;
  const desc = document.getElementById('edit-desc').value.trim();
  const controlIds = getCheckedControls('edit-control-checkboxes');
  try {
    const updated = await api.put(`/api/evidence.php?id=${editingId}`, { description: desc, controlIds });
    const idx = allEvidence.findIndex(e => e.id === editingId);
    if (idx > -1) Object.assign(allEvidence[idx], updated);
    renderEvidence();
    closeModal('edit-modal');
    toast('Evidence updated');
  } catch (e) {
    toast('Error: ' + e.message, 'error');
  }
}

async function deleteEvidence(id) {
  if (!confirm('Delete this evidence file? This cannot be undone.')) return;
  try {
    await api.delete(`/api/evidence.php?id=${id}`);
    allEvidence = allEvidence.filter(e => e.id !== id);
    renderEvidence();
    updateSubtitle();
    toast('Evidence deleted');
  } catch (e) {
    toast('Error: ' + e.message, 'error');
  }
}

document.addEventListener('DOMContentLoaded', load);
