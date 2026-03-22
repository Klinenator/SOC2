let dashData = null;

async function loadDashboard() {
  try {
    dashData = await api.get('/api/dashboard.php');
    renderStats();
    renderScore();
    renderCategoryBars();
    renderStatusBreakdown();
    renderUpcomingTasks();
  } catch (e) {
    console.error(e);
  }
}

function renderStats() {
  const d = dashData;
  document.getElementById('stat-total').textContent     = d.controls.total;
  document.getElementById('stat-compliant').textContent = d.controls.byStatus.compliant;
  document.getElementById('stat-gaps').textContent      = d.controls.byStatus.gap;
  document.getElementById('stat-evidence').textContent  = d.evidence.total;

  const inProg = d.controls.byStatus.in_progress;
  document.getElementById('stat-coverage').textContent    = `${inProg} in progress`;
  document.getElementById('stat-compliant-pct').textContent = `${d.readinessScore}% overall`;
  document.getElementById('stat-open-tasks').textContent = `${d.tasks.open} open task${d.tasks.open !== 1 ? 's' : ''}`;
  document.getElementById('stat-policies').textContent   = `${d.policies.byStatus.approved} policies approved`;
}

function renderScore() {
  const score = dashData.readinessScore;
  const circumference = 2 * Math.PI * 72; // r=72
  const offset = circumference * (1 - score / 100);
  const arc = document.getElementById('score-arc');
  const pct = document.getElementById('score-pct');
  const sub = document.getElementById('score-sub');

  // Color by score
  const color = score >= 80 ? '#22c55e' : score >= 50 ? '#f59e0b' : '#ef4444';
  arc.setAttribute('stroke', color);

  // Animate
  setTimeout(() => { arc.setAttribute('stroke-dashoffset', offset); }, 100);
  pct.textContent = score + '%';

  const c = dashData.controls.byStatus;
  sub.textContent = `${c.compliant}/${dashData.controls.total} compliant`;
}

function renderCategoryBars() {
  const categories = dashData.controls.byCategory;
  const el = document.getElementById('category-bars');
  if (!categories.length) { el.innerHTML = '<p style="color:var(--text-secondary);text-align:center">No data yet</p>'; return; }

  el.innerHTML = categories.map(cat => {
    const pct = cat.total > 0 ? Math.round((cat.compliant / cat.total) * 100) : 0;
    const color = pct >= 80 ? 'var(--green-500)' : pct >= 50 ? 'var(--yellow-500)' : 'var(--red-500)';
    return `
      <div class="cat-bar-row">
        <span class="cat-bar-label" title="${escHtml(cat.name)}">${escHtml(cat.name.split('—')[0].trim())}</span>
        <div class="cat-bar-track">
          <div class="cat-bar-fill" style="width:${pct}%;background:${color}"></div>
        </div>
        <span class="cat-bar-pct">${pct}%</span>
      </div>`;
  }).join('');
}

function renderStatusBreakdown() {
  const s = dashData.controls.byStatus;
  const total = dashData.controls.total;
  const el = document.getElementById('status-breakdown');
  const rows = [
    { key: 'compliant',   label: 'Compliant',    color: 'var(--green-500)' },
    { key: 'in_progress', label: 'In Progress',   color: 'var(--yellow-500)' },
    { key: 'gap',         label: 'Gap',           color: 'var(--red-500)' },
    { key: 'not_started', label: 'Not Started',   color: 'var(--slate-300)' },
  ];
  el.innerHTML = rows.map(r => {
    const count = s[r.key] || 0;
    const pct = total > 0 ? Math.round((count / total) * 100) : 0;
    return `
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;margin-bottom:5px">
          <span style="font-size:13px;font-weight:500;display:flex;align-items:center;gap:8px">
            <span style="width:10px;height:10px;border-radius:50%;background:${r.color};display:inline-block"></span>
            ${r.label}
          </span>
          <span style="font-size:13px;color:var(--text-secondary)">${count} <span style="color:var(--slate-300)">(${pct}%)</span></span>
        </div>
        <div class="progress-bar">
          <div class="progress-fill" style="width:${pct}%;background:${r.color};transition:width .8s ease"></div>
        </div>
      </div>`;
  }).join('');
}

function renderUpcomingTasks() {
  const tasks = dashData.tasks.upcoming;
  const el = document.getElementById('upcoming-tasks');
  if (!tasks.length) {
    el.innerHTML = `<div class="empty-state" style="padding:30px">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="20 6 9 17 4 12"/></svg>
      <p>No upcoming deadlines in the next 30 days</p>
    </div>`;
    return;
  }
  const today = new Date().toISOString().split('T')[0];
  el.innerHTML = `<ul class="task-list">${tasks.map(t => {
    const overdue = t.dueDate < today;
    const daysLeft = Math.ceil((new Date(t.dueDate) - new Date()) / 86400000);
    const dueLabel = overdue ? `<span style="color:var(--red-500)">Overdue</span>` : daysLeft === 0 ? 'Today' : `${daysLeft}d left`;
    return `<li>
      <span class="task-dot ${overdue ? 'overdue' : ''}"></span>
      <div style="flex:1;min-width:0">
        <div class="task-title">${escHtml(t.title)}</div>
        <div class="task-meta">${t.controlId ? `${escHtml(t.controlId)} · ` : ''}${dueLabel} · ${badge('open')}</div>
      </div>
    </li>`;
  }).join('')}</ul>
  ${dashData.tasks.overdue > 0 ? `<div class="alert alert-warn" style="margin-top:14px;margin-bottom:0">${dashData.tasks.overdue} task${dashData.tasks.overdue>1?'s':''} overdue — <a href="/tasks.html">view tasks</a></div>` : ''}`;
}

document.addEventListener('DOMContentLoaded', () => {
  // Set date
  const dateEl = document.getElementById('dash-date');
  if (dateEl) dateEl.textContent = new Date().toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
  loadDashboard();
});
