// ===== Shared Utilities =====

// Nav injection
const NAV_LINKS = [
  { href: '/index.html', icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></rect></svg>`, label: 'Dashboard' },
  { href: '/controls.html', icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>`, label: 'Controls' },
  { href: '/evidence.html', icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>`, label: 'Evidence' },
  { href: '/tasks.html', icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>`, label: 'Tasks', badgeId: 'nav-tasks-badge' },
  { href: '/policies.html', icon: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>`, label: 'Policies' },
];

function renderNav() {
  const current = window.location.pathname.split('/').pop() || 'index.html';
  const el = document.getElementById('sidebar-nav');
  if (!el) return;
  el.innerHTML = NAV_LINKS.map(link => {
    const page = link.href.replace('/','');
    const active = (current === page || (current === '' && page === 'index.html')) ? 'active' : '';
    const badge = link.badgeId ? `<span class="nav-badge" id="${link.badgeId}" style="display:none">0</span>` : '';
    return `<a href="${link.href}" class="nav-item ${active}">${link.icon}<span>${link.label}</span>${badge}</a>`;
  }).join('');
}

// Load open task count for badge
async function loadNavBadge() {
  try {
    const tasks = await api.get('/api/tasks.php');
    const open = tasks.filter(t => t.status === 'open').length;
    const badge = document.getElementById('nav-tasks-badge');
    if (badge) { badge.textContent = open; badge.style.display = open > 0 ? '' : 'none'; }
  } catch {}
}

// ===== API wrapper =====
const api = {
  async request(url, opts = {}) {
    const res = await fetch(url, { headers: { 'Content-Type': 'application/json' }, ...opts });
    if (!res.ok) {
      const err = await res.json().catch(() => ({ error: res.statusText }));
      throw new Error(err.error || res.statusText);
    }
    return res.json();
  },
  get(url)           { return this.request(url); },
  post(url, data)    { return this.request(url, { method: 'POST', body: JSON.stringify(data) }); },
  put(url, data)     { return this.request(url, { method: 'PUT', body: JSON.stringify(data) }); },
  delete(url)        { return this.request(url, { method: 'DELETE' }); },

  async upload(url, formData) {
    const res = await fetch(url, { method: 'POST', body: formData });
    if (!res.ok) {
      const err = await res.json().catch(() => ({ error: res.statusText }));
      throw new Error(err.error || res.statusText);
    }
    return res.json();
  }
};

// ===== Toast =====
let toastContainer;
function toast(msg, type = 'success') {
  if (!toastContainer) {
    toastContainer = document.createElement('div');
    toastContainer.id = 'toast-container';
    document.body.appendChild(toastContainer);
  }
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.textContent = msg;
  toastContainer.appendChild(el);
  setTimeout(() => { el.classList.add('hiding'); setTimeout(() => el.remove(), 300); }, 3000);
}

// ===== Status helpers =====
const STATUS_LABELS = {
  not_started: 'Not Started', in_progress: 'In Progress',
  compliant: 'Compliant', gap: 'Gap',
  open: 'Open', closed: 'Closed',
  draft: 'Draft', under_review: 'Under Review', approved: 'Approved',
  high: 'High', medium: 'Medium', low: 'Low',
};

function badge(status) {
  const label = STATUS_LABELS[status] || status;
  return `<span class="badge badge-${status}">${label}</span>`;
}

function statusDot(status) {
  const colors = { compliant:'#22c55e', gap:'#ef4444', in_progress:'#f59e0b', not_started:'#94a3b8' };
  return `<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${colors[status]||'#94a3b8'};margin-right:6px"></span>`;
}

function formatDate(d) {
  if (!d) return '—';
  const [y,m,day] = d.split('-');
  return `${m}/${day}/${y}`;
}

function formatBytes(bytes) {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024*1024) return (bytes/1024).toFixed(1) + ' KB';
  return (bytes/(1024*1024)).toFixed(1) + ' MB';
}

function escHtml(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Modal helpers
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

// Init nav on every page
document.addEventListener('DOMContentLoaded', () => {
  renderNav();
  loadNavBadge();
});
