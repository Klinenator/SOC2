let dashData = null;

// Which team's outstanding work the task panels show. The portal mixes IT, Business and HR
// queues, so landing on it means reading past two thirds of the list to find your own.
// Remembered across visits; the rest of the dashboard stays global on purpose (see below).
const TASK_SCOPES = { all: 'All teams', it: 'IT', business: 'Business', hr: 'HR' };
let taskScope = 'all';

function loadTaskScope() {
  try {
    const saved = localStorage.getItem('soc2.taskScope');
    if (saved && TASK_SCOPES[saved]) taskScope = saved;
  } catch (e) { /* private window or blocked storage — fall back to all */ }
  const sel = document.getElementById('task-scope');
  if (sel) sel.value = taskScope;
}

function setTaskScope(scope) {
  taskScope = TASK_SCOPES[scope] ? scope : 'all';
  try { localStorage.setItem('soc2.taskScope', taskScope); } catch (e) { /* not fatal */ }
  // Keep the control in step. The onchange path sets it for us, but a programmatic call
  // otherwise leaves the dropdown showing one scope while the panel shows another.
  const sel = document.getElementById('task-scope');
  if (sel && sel.value !== taskScope) sel.value = taskScope;
  renderStats();
  renderUpcomingTasks();
}

function scopedTasks() {
  const all = (dashData?.tasks?.upcoming) || [];
  return taskScope === 'all' ? all : all.filter(t => (t.category || 'business') === taskScope);
}

// Counts for the active scope, from the server's per-category tally.
function scopedTaskCounts() {
  const t = dashData?.tasks || {};
  if (taskScope === 'all') return { open: t.open || 0, overdue: t.overdue || 0 };
  const row = (t.byCategory || []).find(c => c.id === taskScope);
  return { open: row?.open || 0, overdue: row?.overdue || 0 };
}

async function loadDashboard() {
  try {
    dashData = await api.get('/api/dashboard.php');
    loadTaskScope();
    renderStats();
    renderScore();
    renderCategoryBars();
    renderStatusBreakdown();
    renderUpcomingTasks();
    renderEvidenceHealth();
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
  const ev = d.evidence.requirements || { on_track: 0, stale: 0, missing: 0 };
  document.getElementById('stat-coverage').textContent    = `${inProg} in progress`;
  document.getElementById('stat-compliant-pct').textContent = `${d.readinessScore}% overall`;
  // Scoped, and says so. "11 open tasks" beside an IT-only list that shows eleven is
  // readable; the global 19 beside the same list is not.
  const st = scopedTaskCounts();
  const suffix = taskScope === 'all' ? '' : ` · ${TASK_SCOPES[taskScope]}`;
  document.getElementById('stat-open-tasks').textContent =
    `${st.open} open task${st.open !== 1 ? 's' : ''}${suffix}`;
  document.getElementById('stat-policies').textContent   = `${ev.on_track} on track · ${ev.stale + ev.missing} need attention`;
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
  const b = dashData.readinessBreakdown || {};
  sub.textContent = `Workpapers ${b.workpapers || 0}% · Evidence ${b.evidence || 0}%`;
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
  // The server sends 40 days' worth unsliced so the scope filter has something to filter;
  // the card still shows five.
  const tasks = scopedTasks().slice(0, 5);
  const el = document.getElementById('upcoming-tasks');
  const viewAll = document.getElementById('upcoming-view-all');
  if (viewAll) viewAll.href = taskScope === 'all' ? '/tasks.html' : `/tasks.html#${taskScope}`;
  if (!tasks.length) {
    const scopeNote = taskScope === 'all' ? '' : ` for ${TASK_SCOPES[taskScope]}`;
    el.innerHTML = `<div class="empty-state" style="padding:30px">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="20 6 9 17 4 12"/></svg>
      <p>No upcoming deadlines in the next 30 days${escHtml(scopeNote)}</p>
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
  ${(() => {
    const st = scopedTaskCounts();
    if (!st.overdue) return '';
    const where = taskScope === 'all' ? '/tasks.html' : `/tasks.html#${taskScope}`;
    const label = taskScope === 'all' ? '' : ` in ${TASK_SCOPES[taskScope]}`;
    return `<div class="alert alert-warn" style="margin-top:14px;margin-bottom:0">${st.overdue} task${st.overdue>1?'s':''} overdue${escHtml(label)} — <a href="${where}">view tasks</a></div>`;
  })()}`;
}

function renderEvidenceHealth() {
  const el = document.getElementById('evidence-health');
  const ev = dashData.evidence.requirements || { on_track: 0, stale: 0, missing: 0 };
  const total = ev.on_track + ev.stale + ev.missing;

  if (!total) {
    el.innerHTML = `<div class="empty-state" style="padding:30px">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      <p>No operational evidence plan defined yet</p>
    </div>`;
    return;
  }

  const rows = [
    { label: 'On track', count: ev.on_track, color: 'var(--green-500)' },
    { label: 'Stale', count: ev.stale, color: 'var(--yellow-500)' },
    { label: 'Missing', count: ev.missing, color: 'var(--red-500)' },
  ];

  el.innerHTML = rows.map(row => {
    const pct = Math.round((row.count / total) * 100);
    return `
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;margin-bottom:5px">
          <span style="font-size:13px;font-weight:500;display:flex;align-items:center;gap:8px">
            <span style="width:10px;height:10px;border-radius:50%;background:${row.color};display:inline-block"></span>
            ${row.label}
          </span>
          <span style="font-size:13px;color:var(--text-secondary)">${row.count}</span>
        </div>
        <div class="progress-bar">
          <div class="progress-fill" style="width:${pct}%;background:${row.color};transition:width .8s ease"></div>
        </div>
      </div>`;
  }).join('') + `<div class="alert ${ev.missing ? 'alert-warn' : 'alert-info'}" style="margin-top:14px;margin-bottom:0">
    ${ev.missing ? `${ev.missing} operational evidence item${ev.missing > 1 ? 's are' : ' is'} missing outright.` : 'Operational evidence plan is populated and being tracked.'}
  </div>`;
}

document.addEventListener('DOMContentLoaded', () => {
  // Set date
  const dateEl = document.getElementById('dash-date');
  if (dateEl) dateEl.textContent = new Date().toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
  loadDashboard();
});
