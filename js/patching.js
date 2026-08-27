// Server patching page.
//
// patching.html has always loaded /js/patching.js and this file has never existed — not in
// the repository, not in git history, and not on the deployed box. Every other page's script
// is present; this was the only one missing. The page therefore rendered its shell, 404'd on
// its script, and left four stat cards showing an em dash. That is why the monthly CC7.3 task
// says "export or screenshot the patch management dashboard" rather than "review it": there
// was nothing to screenshot.
//
// api/patching.php is complete and unchanged. This is the front end it was written for.

let patchData = null;
let selectedServerId = null;

const HEALTH_LABELS = {
  healthy: 'Healthy',
  stale:   'Stale',
  error:   'Error',
  unknown: 'Never reported',
};

// The shared badge() only knows the status vocabulary in api.js, and patch health is its own
// set. Map onto classes that already exist in the stylesheet rather than inventing colours.
const HEALTH_BADGE_CLASS = {
  healthy: 'compliant',
  stale:   'in_progress',
  error:   'gap',
  unknown: 'not_started',
};

function healthBadge(health) {
  const key = HEALTH_BADGE_CLASS[health] || 'not_started';
  return `<span class="badge badge-${key}">${escHtml(HEALTH_LABELS[health] || health || 'Unknown')}</span>`;
}

function jobBadge(status) {
  const map = { pending: 'not_started', acknowledged: 'in_progress', succeeded: 'compliant', failed: 'gap' };
  return `<span class="badge badge-${map[status] || 'not_started'}">${escHtml(status || 'pending')}</span>`;
}

// "2 hours ago" reads better than a timestamp for a check-in, but keep the exact value in the
// title attribute — a reviewer chasing a stale agent wants the precise time.
function relativeTime(value) {
  if (!value) return '<span style="color:var(--text-secondary)">never</span>';
  const then = new Date(value.replace(' ', 'T'));
  if (isNaN(then)) return escHtml(value);
  const mins = Math.floor((Date.now() - then.getTime()) / 60000);
  let label;
  if (mins < 1)        label = 'just now';
  else if (mins < 60)  label = `${mins}m ago`;
  else if (mins < 1440) label = `${Math.floor(mins / 60)}h ago`;
  else                 label = `${Math.floor(mins / 1440)}d ago`;
  return `<span title="${escHtml(value)}">${label}</span>`;
}

async function loadPatching() {
  try {
    patchData = await api.get('/api/patching.php?action=summary');
    renderPatchStats();
    renderServers();
    renderJobs();
    renderServerDetail();
  } catch (e) {
    toast('Error: ' + e.message, 'error');
    document.getElementById('servers-tbody').innerHTML =
      `<tr><td colspan="8" style="text-align:center;padding:30px;color:var(--red-500)">${escHtml(e.message)}</td></tr>`;
  }
}

// The Refresh button in patching.html. Re-fetches rather than reloading the page so the
// selected server and scroll position survive.
async function refreshData() {
  await loadPatching();
  toast('Refreshed ✓');
}

function renderPatchStats() {
  const s = patchData.summary;
  document.getElementById('stat-servers').textContent  = s.servers;
  document.getElementById('stat-healthy').textContent  = s.healthy;
  document.getElementById('stat-security').textContent = s.securityUpdates;
  document.getElementById('stat-jobs').textContent     = s.pendingJobs;
}

function renderServers() {
  const el = document.getElementById('servers-tbody');
  const servers = patchData.servers || [];
  if (!servers.length) {
    el.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:34px;color:var(--text-secondary)">
      No servers configured yet. Add one, or populate them with
      <code>scripts/export_patch_compliance.php</code>.</td></tr>`;
    return;
  }
  el.innerHTML = servers.map(sv => {
    const rep = sv.lastReport || {};
    // Security updates are the number that matters; show it in red when non-zero rather than
    // burying it in a total that a reviewer has to subtract.
    const sec = Number(sv.securityUpdateCount || 0);
    const total = Number(sv.updateCount || 0);
    const updates = sec > 0
      ? `<strong style="color:var(--red-500)">${sec} security</strong><span style="color:var(--text-secondary)"> / ${total}</span>`
      : `<span style="color:var(--text-secondary)">${total || 0} pending</span>`;
    return `<tr style="cursor:pointer${sv.id === selectedServerId ? ';background:var(--blue-50)' : ''}" onclick="selectServer('${escHtml(sv.id)}')">
      <td class="td-name">${escHtml(sv.name || '')}${sv.hostname ? `<div style="font-size:12px;color:var(--text-secondary)">${escHtml(sv.hostname)}</div>` : ''}</td>
      <td>${escHtml(sv.ownerName || '—')}</td>
      <td>${healthBadge(sv.health)}${sv.rebootRequired ? ' <span class="badge badge-in_progress">reboot</span>' : ''}</td>
      <td style="font-size:12px">${escHtml(rep.osVersion || '—')}</td>
      <td>${updates}</td>
      <td>${relativeTime(sv.lastCheckIn)}</td>
      <td>${sv.lastPatchAt ? escHtml(sv.lastPatchAt) : '<span style="color:var(--text-secondary)">—</span>'}</td>
      <td onclick="event.stopPropagation()">
        <button class="btn btn-ghost btn-sm" onclick="queueJob('${escHtml(sv.id)}','scan')">Scan</button>
        <button class="btn btn-ghost btn-sm" onclick="editServer('${escHtml(sv.id)}')">Edit</button>
      </td>
    </tr>`;
  }).join('');
}

function selectServer(id) {
  selectedServerId = selectedServerId === id ? null : id;
  renderServers();
  renderServerDetail();
}

function renderServerDetail() {
  const el = document.getElementById('server-detail');
  const sv = (patchData.servers || []).find(s => s.id === selectedServerId);
  if (!sv) {
    el.innerHTML = `<div class="empty-state" style="padding:30px 20px">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="4" width="20" height="8" rx="2"/><rect x="2" y="12" width="20" height="8" rx="2"/></svg>
      <p>Select a server to inspect updates and held packages.</p>
    </div>`;
    return;
  }
  const rep = sv.lastReport || {};
  const updates = rep.updates || [];
  const held = rep.heldPackages || [];

  const updateRows = updates.length
    ? `<table style="width:100%;font-size:12px"><tbody>${updates.slice(0, 40).map(u => `
        <tr>
          <td class="td-mono">${escHtml(u.name || u.package || '')}</td>
          <td style="color:var(--text-secondary)">${escHtml(u.version || '')}</td>
          <td style="text-align:right">${u.security ? '<span class="badge badge-gap">security</span>' : ''}</td>
        </tr>`).join('')}</tbody></table>
        ${updates.length > 40 ? `<div style="font-size:12px;color:var(--text-secondary);margin-top:6px">…and ${updates.length - 40} more</div>` : ''}`
    : '<div style="color:var(--text-secondary);font-size:13px">No pending updates reported.</div>';

  el.innerHTML = `
    <div style="margin-bottom:12px">
      <div style="font-weight:600">${escHtml(sv.name || '')}</div>
      <div style="font-size:12px;color:var(--text-secondary)">${escHtml(sv.hostname || '')} · ${escHtml(sv.environment || '')} · ${escHtml(rep.osVersion || 'OS unknown')}</div>
    </div>
    ${sv.error ? `<div class="alert alert-warn" style="margin-bottom:12px">${escHtml(sv.error)}</div>` : ''}
    ${sv.rebootRequired ? `<div class="alert alert-warn" style="margin-bottom:12px">Reboot required to complete patching.</div>` : ''}

    <div class="form-label" style="margin-bottom:6px">Pending updates</div>
    ${updateRows}

    ${held.length ? `<div class="form-label" style="margin:14px 0 6px">Held packages</div>
      <div style="font-size:12px" class="td-mono">${held.map(h => escHtml(h)).join(', ')}</div>
      <div class="form-hint">Held packages do not receive security updates. Each one needs a reason.</div>` : ''}

    ${rep.aptHistoryExcerpt ? `<div class="form-label" style="margin:14px 0 6px">Recent apt history</div>
      <pre style="font-size:11px;white-space:pre-wrap;background:var(--slate-50);padding:8px;border-radius:6px;max-height:220px;overflow:auto">${escHtml(rep.aptHistoryExcerpt)}</pre>` : ''}

    <div style="margin-top:14px;display:flex;gap:8px">
      <button class="btn btn-secondary btn-sm" onclick="queueJob('${escHtml(sv.id)}','scan')">Queue scan</button>
      <button class="btn btn-secondary btn-sm" onclick="queueJob('${escHtml(sv.id)}','security-upgrade')">Queue security upgrade</button>
    </div>`;
}

function renderJobs() {
  const el = document.getElementById('jobs-tbody');
  const jobs = patchData.jobs || [];
  const byId = Object.fromEntries((patchData.servers || []).map(s => [s.id, s.name]));
  if (!jobs.length) {
    el.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:30px;color:var(--text-secondary)">No patch jobs yet.</td></tr>`;
    return;
  }
  el.innerHTML = jobs.map(j => `<tr>
    <td style="font-size:12px">${escHtml(j.createdAt || '')}</td>
    <td>${escHtml(byId[j.serverId] || j.serverId || '')}</td>
    <td class="td-mono">${escHtml(j.command || '')}</td>
    <td>${jobBadge(j.status)}</td>
    <td>${escHtml(j.requestedBy || '—')}</td>
  </tr>`).join('');
}

async function queueJob(serverId, command) {
  try {
    await api.put(`/api/patching.php?action=queue&id=${encodeURIComponent(serverId)}`, { command });
    toast(`Queued ${command} ✓`);
    await loadPatching();
  } catch (e) { toast('Error: ' + e.message, 'error'); }
}

// ===== Server records =====

function populateOwnerSelect(people) {
  const sel = document.getElementById('server-owner');
  if (!sel) return;
  sel.innerHTML = '<option value="">Unassigned</option>' +
    people.map(p => `<option value="${escHtml(p.id)}">${escHtml(p.name)}</option>`).join('');
}

function openServerModal() {
  document.getElementById('server-modal-title').textContent = 'Add Server';
  document.getElementById('server-id').value = '';
  document.getElementById('server-name').value = '';
  document.getElementById('server-hostname').value = '';
  document.getElementById('server-environment').value = 'production';
  document.getElementById('server-owner').value = '';
  document.getElementById('rotate-token-group').style.display = 'none';
  document.getElementById('new-token-wrap').classList.add('hidden');
  openModal('server-modal');
}

function editServer(id) {
  const sv = (patchData.servers || []).find(s => s.id === id);
  if (!sv) return;
  document.getElementById('server-modal-title').textContent = 'Edit Server';
  document.getElementById('server-id').value = sv.id;
  document.getElementById('server-name').value = sv.name || '';
  document.getElementById('server-hostname').value = sv.hostname || '';
  document.getElementById('server-environment').value = sv.environment || 'production';
  document.getElementById('server-owner').value = sv.ownerId || '';
  document.getElementById('server-rotate-token').checked = false;
  document.getElementById('rotate-token-group').style.display = '';
  document.getElementById('new-token-wrap').classList.add('hidden');
  openModal('server-modal');
}

async function saveServer() {
  const id = document.getElementById('server-id').value;
  const name = document.getElementById('server-name').value.trim();
  if (!name) { toast('Display name is required', 'error'); return; }
  const body = {
    name,
    hostname:    document.getElementById('server-hostname').value.trim(),
    environment: document.getElementById('server-environment').value,
    ownerId:     document.getElementById('server-owner').value,
  };
  try {
    let result;
    if (id) {
      body.rotateToken = document.getElementById('server-rotate-token').checked;
      result = await api.put(`/api/patching.php?action=server&id=${encodeURIComponent(id)}`, body);
    } else {
      result = await api.post('/api/patching.php?action=server', body);
    }
    await loadPatching();
    // The token is returned exactly once, on create or rotate. Show it rather than closing,
    // because there is no way to retrieve it afterwards.
    if (result && result.token) {
      document.getElementById('server-token').value = result.token;
      document.getElementById('new-token-wrap').classList.remove('hidden');
      toast('Saved — copy the agent token before closing');
    } else {
      closeModal('server-modal');
      toast('Server saved ✓');
    }
  } catch (e) { toast('Error: ' + e.message, 'error'); }
}

document.addEventListener('DOMContentLoaded', async () => {
  try {
    populateOwnerSelect(await api.get('/api/people.php'));
  } catch (e) { /* owner list is a convenience; the page still works without it */ }
  loadPatching();
});
