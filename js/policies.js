let allPolicies = [];
let editingPolicyId = null;

async function load() {
  allPolicies = await api.get('/api/policies.php');
  renderPolicies();
  updateStats();
}

function updateStats() {
  const counts = { draft: 0, under_review: 0, approved: 0 };
  allPolicies.forEach(p => { counts[p.status] = (counts[p.status] || 0) + 1; });
  document.getElementById('stat-draft').textContent   = counts.draft;
  document.getElementById('stat-review').textContent  = counts.under_review;
  document.getElementById('stat-approved').textContent = counts.approved;
  document.getElementById('policies-subtitle').textContent =
    `${allPolicies.length} policies — ${counts.approved} approved`;
}

function filterPolicies() {
  const q = document.getElementById('search').value.toLowerCase();
  const status = document.getElementById('filter-status').value;
  const cat = document.getElementById('filter-category').value;
  const filtered = allPolicies.filter(p => {
    if (status && p.status !== status) return false;
    if (cat && p.category !== cat) return false;
    if (q && !p.name.toLowerCase().includes(q) && !(p.description||'').toLowerCase().includes(q)) return false;
    return true;
  });
  renderPolicies(filtered);
}

function renderPolicies(policies = allPolicies) {
  const grid = document.getElementById('policy-grid');
  document.getElementById('policies-count').textContent = `${policies.length} polic${policies.length !== 1 ? 'ies' : 'y'}`;

  if (!policies.length) {
    grid.innerHTML = `<div style="grid-column:1/-1" class="empty-state">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      <h3>No policies found</h3><p>Add policies using the button above</p></div>`;
    return;
  }

  grid.innerHTML = policies.map(p => {
    const hasContent = p.content && p.content.trim().length > 0;
    return `<div class="policy-card">
      <div class="policy-card-header">
        <div>
          <div class="policy-card-name">${escHtml(p.name)}</div>
          <div style="margin-top:4px;display:flex;gap:6px;align-items:center">
            ${badge(p.status)}
            <span style="font-size:11px;color:var(--text-secondary);background:var(--slate-50);padding:2px 7px;border-radius:10px;border:1px solid var(--slate-200)">${escHtml(p.category)}</span>
          </div>
        </div>
      </div>
      ${p.description ? `<div class="policy-card-desc">${escHtml(p.description)}</div>` : ''}
      ${!hasContent ? '<div class="alert alert-warn" style="font-size:12px;padding:8px 12px;margin:0">Policy content not yet added</div>' : '<div style="font-size:12px;color:var(--green-500);font-weight:500">✓ Content added</div>'}
      <div class="policy-card-footer">
        <div class="policy-card-meta">
          v${escHtml(p.version || '1.0')}
          ${p.owner ? ` · ${escHtml(p.owner)}` : ''}
          ${p.reviewDate ? ` · Review: ${formatDate(p.reviewDate)}` : ''}
        </div>
        <div class="policy-actions">
          <button class="btn btn-secondary btn-sm" onclick="openEdit('${escHtml(p.id)}')">Edit</button>
          ${p.status !== 'approved'
            ? `<button class="btn btn-primary btn-sm" onclick="quickApprove('${escHtml(p.id)}')">Approve</button>`
            : `<button class="btn btn-ghost btn-sm" onclick="quickStatus('${escHtml(p.id)}', 'under_review')">Unapprove</button>`}
          <button class="btn btn-ghost btn-sm" style="color:var(--red-500)" onclick="deletePolicy('${escHtml(p.id)}')">×</button>
        </div>
      </div>
    </div>`;
  }).join('');
}

function newPolicy() {
  editingPolicyId = null;
  document.getElementById('policy-modal-title').textContent = 'Add Policy';
  document.getElementById('policy-id').value = '';
  document.getElementById('policy-name').value = '';
  document.getElementById('policy-category').value = 'Security';
  document.getElementById('policy-status').value = 'draft';
  document.getElementById('policy-owner').value = '';
  document.getElementById('policy-version').value = '1.0';
  document.getElementById('policy-review').value = '';
  document.getElementById('policy-desc').value = '';
  document.getElementById('policy-content').value = '';
  openModal('policy-modal');
}

function openEdit(id) {
  const p = allPolicies.find(x => x.id === id);
  if (!p) return;
  editingPolicyId = id;
  document.getElementById('policy-modal-title').textContent = 'Edit Policy';
  document.getElementById('policy-id').value = p.id;
  document.getElementById('policy-name').value = p.name;
  document.getElementById('policy-category').value = p.category || 'Security';
  document.getElementById('policy-status').value = p.status;
  document.getElementById('policy-owner').value = p.owner || '';
  document.getElementById('policy-version').value = p.version || '1.0';
  document.getElementById('policy-review').value = p.reviewDate || '';
  document.getElementById('policy-desc').value = p.description || '';
  document.getElementById('policy-content').value = p.content || '';
  openModal('policy-modal');
}

async function savePolicy() {
  const name = document.getElementById('policy-name').value.trim();
  if (!name) { toast('Policy name is required', 'error'); return; }
  const body = {
    name,
    category:    document.getElementById('policy-category').value,
    status:      document.getElementById('policy-status').value,
    owner:       document.getElementById('policy-owner').value.trim(),
    version:     document.getElementById('policy-version').value.trim() || '1.0',
    reviewDate:  document.getElementById('policy-review').value,
    description: document.getElementById('policy-desc').value.trim(),
    content:     document.getElementById('policy-content').value.trim(),
  };
  try {
    if (editingPolicyId) {
      const updated = await api.put(`/api/policies.php?id=${editingPolicyId}`, body);
      const idx = allPolicies.findIndex(p => p.id === editingPolicyId);
      if (idx > -1) Object.assign(allPolicies[idx], updated);
      toast('Policy saved');
    } else {
      const created = await api.post('/api/policies.php', body);
      allPolicies.push(created);
      toast('Policy created');
    }
    filterPolicies();
    updateStats();
    closeModal('policy-modal');
  } catch (e) {
    toast('Error: ' + e.message, 'error');
  }
}

async function quickApprove(id) { await quickStatus(id, 'approved'); }
async function quickStatus(id, status) {
  try {
    const updated = await api.put(`/api/policies.php?id=${id}`, { status });
    const idx = allPolicies.findIndex(p => p.id === id);
    if (idx > -1) Object.assign(allPolicies[idx], updated);
    filterPolicies();
    updateStats();
    toast(status === 'approved' ? 'Policy approved ✓' : 'Status updated');
  } catch (e) { toast('Error: ' + e.message, 'error'); }
}

async function deletePolicy(id) {
  if (!confirm('Delete this policy?')) return;
  try {
    await api.delete(`/api/policies.php?id=${id}`);
    allPolicies = allPolicies.filter(p => p.id !== id);
    filterPolicies();
    updateStats();
    toast('Policy deleted');
  } catch (e) { toast('Error: ' + e.message, 'error'); }
}

document.addEventListener('DOMContentLoaded', load);
