let allEvidence = [];
let allControls = [];
let allRequirements = [];
let allWorkpapers = [];
let selectedFile = null;
let editingId = null;

async function load() {
  const requirementsResult = await api.get('/api/evidence_requirements.php');
  let workpaperResult;
  [allEvidence, allControls, workpaperResult] = await Promise.all([
    api.get('/api/evidence.php'),
    api.get('/api/controls.php'),
    api.get('/api/audit_tests.php'),
  ]);
  allWorkpapers = workpaperResult.workpapers || [];
  allRequirements = requirementsResult.requirements || [];
  populateControlFilter();
  populateControlCheckboxes('control-checkboxes', []);
  populateWorkpaperSelect('upload-workpaper', '');
  populateWorkpaperSelect('edit-workpaper', '');
  renderRequirementSummary(requirementsResult.summary || {});
  renderRequirements();
  renderEvidence();
  updateSubtitle();
}

function populateWorkpaperSelect(id, selected) {
  const el = document.getElementById(id); if (!el) return;
  el.innerHTML = '<option value="">Not linked to a specific workpaper</option>' + allWorkpapers.map(w => `<option value="${escHtml(w.id)}" ${w.id === selected ? 'selected' : ''}>${escHtml(w.id)} - ${escHtml(w.activity)}</option>`).join('');
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
    tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state">
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

  const sorted = [...evidence].sort((a, b) => (recordDate(b) || '').localeCompare(recordDate(a) || ''));
  tbody.innerHTML = sorted.map(e => {
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
      <td class="td-muted">${escHtml(e.source) || '—'}${e.owner ? `<div style="font-size:11px">${escHtml(e.owner)}</div>` : ''}</td>
      <td>${controls || '<span class="td-muted">—</span>'}</td>
      <td class="td-muted">${formatDate(recordDate(e))}</td>
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

function renderRequirementSummary(summary) {
  const total = (summary.on_track || 0) + (summary.stale || 0) + (summary.missing || 0);
  document.getElementById('req-total').textContent = total;
  document.getElementById('req-on-track').textContent = summary.on_track || 0;
  document.getElementById('req-stale').textContent = summary.stale || 0;
  document.getElementById('req-missing').textContent = summary.missing || 0;
}

function requirementBadge(status) {
  if (status === 'on_track') return '<span class="badge badge-compliant">On Track</span>';
  if (status === 'stale') return '<span class="badge badge-in_progress">Stale</span>';
  return '<span class="badge badge-gap">Missing</span>';
}

function renderRequirements() {
  const tbody = document.getElementById('requirements-tbody');
  if (!allRequirements.length) {
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-secondary)">No operational evidence checks configured yet.</td></tr>`;
    return;
  }

  tbody.innerHTML = allRequirements.map(req => `
    <tr>
      <td style="max-width:260px">
        <div style="font-weight:600">${escHtml(req.title)}</div>
        <div class="td-muted" style="font-size:12px">${escHtml(req.description || '')}</div>
      </td>
      <td>${req.controlId ? `<span class="td-mono">${escHtml(req.controlId)}</span>` : '—'}</td>
      <td class="td-muted">${escHtml(req.recurrence || '—')}</td>
      <td class="td-muted">
        ${req.latestEvidenceDate ? formatDate(req.latestEvidenceDate) : '—'}
        ${req.latestEvidence ? `<div style="font-size:11px">${escHtml(req.latestEvidence.filename)}</div>` : ''}
      </td>
      <td class="td-muted">${req.nextDue ? formatDate(req.nextDue) : '—'}</td>
      <td>${requirementBadge(req.status)}</td>
      <td class="td-muted" style="max-width:320px">${escHtml(req.collectionMethod || '')}</td>
    </tr>
  `).join('');
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
    fd.append('source', document.getElementById('upload-source').value.trim());
    fd.append('owner', document.getElementById('upload-owner').value.trim());
    fd.append('evidenceDate', document.getElementById('upload-evidence-date').value);
    fd.append('controlIds', JSON.stringify(controlIds));
    const workpaperId = document.getElementById('upload-workpaper').value;
    fd.append('auditTestIds', JSON.stringify(workpaperId ? [workpaperId] : []));
    await api.upload('/api/evidence.php', fd);
    const [requirementsResult, evidenceResult, controlsResult] = await Promise.all([
      api.get('/api/evidence_requirements.php'),
      api.get('/api/evidence.php'),
      api.get('/api/controls.php'),
    ]);
    allRequirements = requirementsResult.requirements || [];
    allEvidence = evidenceResult;
    allControls = controlsResult;
    renderRequirementSummary(requirementsResult.summary || {});
    renderRequirements();
    renderEvidence();
    updateSubtitle();
    closeModal('upload-modal');
    document.getElementById('upload-desc').value = '';
    document.getElementById('upload-source').value = '';
    document.getElementById('upload-owner').value = '';
    document.getElementById('upload-evidence-date').value = '';
    document.getElementById('selected-file-name').textContent = '';
    document.getElementById('upload-input').value = '';
    populateControlCheckboxes('control-checkboxes', []);
    populateWorkpaperSelect('upload-workpaper', '');
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
  document.getElementById('edit-source').value = e.source || '';
  document.getElementById('edit-owner').value = e.owner || '';
  document.getElementById('edit-evidence-date').value = e.evidenceDate || '';
  populateControlCheckboxes('edit-control-checkboxes', e.controlIds || []);
  populateWorkpaperSelect('edit-workpaper', (e.auditTestIds || [])[0] || '');
  openModal('edit-modal');
}

async function saveEvidence() {
  if (!editingId) return;
  const desc = document.getElementById('edit-desc').value.trim();
  const controlIds = getCheckedControls('edit-control-checkboxes');
  try {
    const updated = await api.put(`/api/evidence.php?id=${editingId}`, {
      description: desc,
      source: document.getElementById('edit-source').value.trim(),
      owner: document.getElementById('edit-owner').value.trim(),
      evidenceDate: document.getElementById('edit-evidence-date').value,
      controlIds,
      auditTestIds: document.getElementById('edit-workpaper').value ? [document.getElementById('edit-workpaper').value] : [],
    });
    const idx = allEvidence.findIndex(e => e.id === editingId);
    if (idx > -1) Object.assign(allEvidence[idx], updated);
    const requirementsResult = await api.get('/api/evidence_requirements.php');
    allRequirements = requirementsResult.requirements || [];
    renderRequirementSummary(requirementsResult.summary || {});
    renderRequirements();
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
    const requirementsResult = await api.get('/api/evidence_requirements.php');
    allRequirements = requirementsResult.requirements || [];
    renderRequirementSummary(requirementsResult.summary || {});
    renderRequirements();
    renderEvidence();
    updateSubtitle();
    toast('Evidence deleted');
  } catch (e) {
    toast('Error: ' + e.message, 'error');
  }
}

function recordDate(e) {
  return e.evidenceDate || (e.uploadedAt ? e.uploadedAt.split(' ')[0] : '');
}

document.addEventListener('DOMContentLoaded', load);
