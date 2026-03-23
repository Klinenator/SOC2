let allPolicies = [];
let allSignatures = [];
let editingPolicyId = null;
let viewingPolicyId = null;

async function load() {
  [allPolicies, allSignatures] = await Promise.all([
    api.get('/api/policies.php'),
    api.get('/api/signatures.php'),
  ]);
  renderPolicies();
  updateStats();
}

function updateStats() {
  const counts = { draft: 0, under_review: 0, approved: 0 };
  allPolicies.forEach(p => { counts[p.status] = (counts[p.status] || 0) + 1; });
  document.getElementById('stat-draft').textContent    = counts.draft;
  document.getElementById('stat-review').textContent   = counts.under_review;
  document.getElementById('stat-approved').textContent = counts.approved;
  document.getElementById('stat-sigs').textContent     = allSignatures.length;

  const total = allPolicies.length;
  const approved = counts.approved;
  document.getElementById('policies-subtitle').textContent =
    `${total} policies — ${approved} approved · ${allSignatures.length} total signatures`;
}

function sigCountForPolicy(policyId) {
  return allSignatures.filter(s => s.policyId === policyId).length;
}

function filterPolicies() {
  const q      = document.getElementById('search').value.toLowerCase();
  const status = document.getElementById('filter-status').value;
  const cat    = document.getElementById('filter-category').value;
  const filtered = allPolicies.filter(p => {
    if (status && p.status !== status) return false;
    if (cat && p.category !== cat) return false;
    if (q && !p.name.toLowerCase().includes(q) && !(p.description || '').toLowerCase().includes(q)) return false;
    return true;
  });
  renderPolicies(filtered);
}

function renderPolicies(policies = allPolicies) {
  const grid = document.getElementById('policy-grid');
  document.getElementById('policies-count').textContent =
    `${policies.length} polic${policies.length !== 1 ? 'ies' : 'y'}`;

  if (!policies.length) {
    grid.innerHTML = `<div style="grid-column:1/-1" class="empty-state">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      <h3>No policies found</h3><p>Adjust your filters or add a policy</p></div>`;
    return;
  }

  grid.innerHTML = policies.map(p => {
    const sigCount = sigCountForPolicy(p.id);
    const hasContent = p.content && p.content.trim().length > 0;
    const contentSnippet = hasContent
      ? escHtml(p.content.substring(0, 120).replace(/\n/g, ' ')) + '...'
      : '<em style="color:var(--text-secondary)">No content yet — click Edit to add policy text.</em>';

    return `<div class="policy-card">
      <div class="policy-card-header">
        <div style="flex:1;min-width:0">
          <div class="policy-card-name">${escHtml(p.name)}</div>
          <div style="margin-top:5px;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            ${badge(p.status)}
            <span style="font-size:11px;color:var(--text-secondary);background:var(--slate-50);padding:2px 7px;border-radius:10px;border:1px solid var(--slate-200)">${escHtml(p.category)}</span>
            <span style="font-size:11px;color:var(--text-secondary)">v${escHtml(p.version || '1.0')}</span>
          </div>
        </div>
      </div>

      <div style="font-size:12px;color:var(--text-secondary);line-height:1.5;margin:4px 0 2px">${contentSnippet}</div>

      <div style="display:flex;align-items:center;gap:8px;margin-top:4px">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:${sigCount > 0 ? 'var(--green-500)' : 'var(--slate-300)'}"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
        <span style="font-size:12px;color:var(--text-secondary)">${sigCount} signature${sigCount !== 1 ? 's' : ''}</span>
        ${p.owner ? `<span style="font-size:12px;color:var(--text-secondary);margin-left:4px">· ${escHtml(p.owner)}</span>` : ''}
        ${p.reviewDate ? `<span style="font-size:12px;color:var(--text-secondary)">· Review: ${formatDate(p.reviewDate)}</span>` : ''}
      </div>

      <div class="policy-card-footer" style="margin-top:10px">
        <div></div>
        <div class="policy-actions">
          <button class="btn btn-secondary btn-sm" onclick="openView('${escHtml(p.id)}')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            View &amp; Sign
          </button>
          <button class="btn btn-secondary btn-sm" onclick="openEdit('${escHtml(p.id)}')">Edit</button>
          ${p.status !== 'approved'
            ? `<button class="btn btn-primary btn-sm" onclick="quickApprove('${escHtml(p.id)}')">Approve</button>`
            : `<button class="btn btn-ghost btn-sm" style="font-size:11px" onclick="quickStatus('${escHtml(p.id)}','under_review')">Unapprove</button>`}
          <button class="btn btn-ghost btn-sm" style="color:var(--red-500)" onclick="deletePolicy('${escHtml(p.id)}')">×</button>
        </div>
      </div>
    </div>`;
  }).join('');
}

// ===== View & Sign =====
function switchTab(tab) {
  const isContent = tab === 'content';
  document.getElementById('panel-content').style.display = isContent ? '' : 'none';
  document.getElementById('panel-sigs').style.display    = isContent ? 'none' : '';
  document.getElementById('tab-content').style.cssText = isContent
    ? 'padding:8px 18px;border:none;background:none;font-size:14px;font-weight:600;color:var(--blue-500);border-bottom:2px solid var(--blue-500);cursor:pointer'
    : 'padding:8px 18px;border:none;background:none;font-size:14px;font-weight:500;color:var(--text-secondary);cursor:pointer';
  document.getElementById('tab-sigs').style.cssText = !isContent
    ? 'padding:8px 18px;border:none;background:none;font-size:14px;font-weight:600;color:var(--blue-500);border-bottom:2px solid var(--blue-500);cursor:pointer'
    : 'padding:8px 18px;border:none;background:none;font-size:14px;font-weight:500;color:var(--text-secondary);cursor:pointer';
}

function openView(id) {
  const p = allPolicies.find(x => x.id === id);
  if (!p) return;
  viewingPolicyId = id;

  document.getElementById('view-policy-name').textContent = p.name;
  document.getElementById('view-policy-meta').textContent =
    `Version ${p.version || '1.0'} · ${p.category} · Last updated ${p.updatedAt || p.createdAt}`;
  document.getElementById('view-policy-content').textContent =
    p.content || 'Policy content not yet added. Use the Edit button to add content.';

  document.getElementById('sign-name').value = '';
  document.getElementById('sign-title').value = '';
  document.getElementById('sign-ack').checked = false;

  renderSignatures(id);
  switchTab('content');
  openModal('view-modal');
}

function renderSignatures(policyId) {
  const sigs = allSignatures.filter(s => s.policyId === policyId);
  document.getElementById('sig-count-tab').textContent = sigs.length;

  const list = document.getElementById('sigs-list');
  const empty = document.getElementById('sigs-empty');

  if (!sigs.length) {
    list.innerHTML = '';
    empty.style.display = '';
    return;
  }
  empty.style.display = 'none';
  list.innerHTML = sigs.map(s => {
    const initials = s.signerName.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
    return `<div class="sig-row">
      <div class="sig-avatar">${escHtml(initials)}</div>
      <div style="flex:1">
        <div style="font-weight:600">${escHtml(s.signerName)}</div>
        <div style="color:var(--text-secondary);font-size:12px">${escHtml(s.signerTitle || 'No title')} · v${escHtml(s.policyVersion)} · ${s.signedAt}</div>
      </div>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--green-500)" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
    </div>`;
  }).join('');
}

async function signPolicy() {
  const name = document.getElementById('sign-name').value.trim();
  const ack  = document.getElementById('sign-ack').checked;
  if (!name) { toast('Please enter your name', 'error'); return; }
  if (!ack)  { toast('Please check the acknowledgment box', 'error'); return; }

  const btn = document.getElementById('sign-btn');
  btn.textContent = 'Signing...'; btn.disabled = true;

  try {
    const sig = await api.post('/api/signatures.php', {
      policyId:    viewingPolicyId,
      signerName:  name,
      signerTitle: document.getElementById('sign-title').value.trim(),
    });
    allSignatures.push(sig);
    renderSignatures(viewingPolicyId);
    renderPolicies();
    updateStats();
    document.getElementById('sign-name').value = '';
    document.getElementById('sign-title').value = '';
    document.getElementById('sign-ack').checked = false;
    switchTab('sigs');
    toast('Policy signed successfully ✓');
  } catch (e) {
    toast('Error: ' + e.message, 'error');
  } finally {
    btn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/></svg> Sign Policy`;
    btn.disabled = false;
  }
}

// ===== Edit =====
function newPolicy() {
  editingPolicyId = null;
  document.getElementById('policy-modal-title').textContent = 'Add Policy';
  ['policy-id','policy-name','policy-owner','policy-review','policy-desc','policy-content'].forEach(id => {
    document.getElementById(id).value = '';
  });
  document.getElementById('policy-category').value = 'Security';
  document.getElementById('policy-status').value = 'draft';
  document.getElementById('policy-version').value = '1.0';
  openModal('policy-modal');
}

function openEdit(id) {
  const p = allPolicies.find(x => x.id === id);
  if (!p) return;
  editingPolicyId = id;
  document.getElementById('policy-modal-title').textContent = 'Edit Policy';
  document.getElementById('policy-id').value       = p.id;
  document.getElementById('policy-name').value     = p.name;
  document.getElementById('policy-category').value = p.category || 'Security';
  document.getElementById('policy-status').value   = p.status;
  document.getElementById('policy-owner').value    = p.owner || '';
  document.getElementById('policy-version').value  = p.version || '1.0';
  document.getElementById('policy-review').value   = p.reviewDate || '';
  document.getElementById('policy-desc').value     = p.description || '';
  document.getElementById('policy-content').value  = p.content || '';
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
    filterPolicies(); updateStats();
    toast(status === 'approved' ? 'Policy approved ✓' : 'Status updated');
  } catch (e) { toast('Error: ' + e.message, 'error'); }
}

async function deletePolicy(id) {
  if (!confirm('Delete this policy? All signatures will be lost.')) return;
  try {
    await api.delete(`/api/policies.php?id=${id}`);
    allPolicies = allPolicies.filter(p => p.id !== id);
    filterPolicies(); updateStats();
    toast('Policy deleted');
  } catch (e) { toast('Error: ' + e.message, 'error'); }
}

function exportSignatures() {
  if (!allSignatures.length) { toast('No signatures to export', 'error'); return; }
  const headers = ['Policy','Version','Signer Name','Title','Signed At'];
  const rows = allSignatures.map(s => [
    `"${s.policyName.replace(/"/g,'""')}"`,
    s.policyVersion,
    `"${s.signerName.replace(/"/g,'""')}"`,
    `"${(s.signerTitle||'').replace(/"/g,'""')}"`,
    s.signedAt,
  ]);
  const csv = [headers, ...rows].map(r => r.join(',')).join('\n');
  const a = document.createElement('a');
  a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
  a.download = `policy-signatures-${new Date().toISOString().split('T')[0]}.csv`;
  a.click();
  toast('Signatures exported');
}

document.addEventListener('DOMContentLoaded', load);
