// Management reminders view: owners, recurring obligations, calendar, preview.

let PEOPLE = [];
let TASKS = [];

const RECURRENCES = ['annual', 'quarterly', 'monthly', 'onEvent', 'once'];

function daysUntil(due) {
  if (!due) return null;
  const d = new Date(due + 'T00:00:00');
  const t = new Date(); t.setHours(0, 0, 0, 0);
  return Math.round((d - t) / 86400000);
}

function timingBadge(due) {
  const n = daysUntil(due);
  if (n === null) return '—';
  if (n < 0) return `<span class="badge badge-gap">${Math.abs(n)}d overdue</span>`;
  if (n === 0) return `<span class="badge badge-in_progress">due today</span>`;
  if (n <= 30) return `<span class="badge badge-in_progress">in ${n}d</span>`;
  return `<span class="badge badge-compliant">in ${n}d</span>`;
}

function ownerName(id) {
  const p = PEOPLE.find(x => x.id === id);
  return p ? p.name : '(unassigned)';
}

async function loadAll() {
  [PEOPLE, TASKS] = await Promise.all([
    api.get('/api/people.php'),
    api.get('/api/tasks.php'),
  ]);
  renderOwners();
  renderCalendar();
  renderTasks();
  loadMode();
}

async function loadMode() {
  try {
    const r = await api.get('/api/reminders.php?preview=1');
    const pill = document.getElementById('mode-pill');
    const note = document.getElementById('mode-note');
    if (r.dryRun) {
      pill.className = 'badge badge-in_progress'; pill.textContent = 'DRY-RUN';
      note.textContent = `Reminders are in dry-run — a preview digest goes only to the test recipient; no owners are emailed yet. ${r.count} item(s) fire today. Confirm owner emails, then flip dryRun=false in data/reminders_config.json.`;
    } else {
      pill.className = 'badge badge-compliant'; pill.textContent = 'LIVE';
      note.textContent = `Reminders are live — owners are emailed on the 30/14/7/1-day schedule and weekly while overdue. ${r.count} item(s) fire today.`;
    }
  } catch (e) {
    document.getElementById('mode-note').textContent = 'Could not read reminder status: ' + e.message;
  }
}

function renderOwners() {
  const host = location.host;
  document.getElementById('owners-tbody').innerHTML = PEOPLE.map(p => {
    const cal = `webcal://${host}/api/calendar.php?owner=${encodeURIComponent(p.id)}`;
    return `<tr>
      <td>${escHtml(p.name)}</td>
      <td><input class="form-control" style="min-width:90px" value="${escHtml(p.title || '')}" onchange="savePerson('${p.id}','title',this.value)"></td>
      <td><input class="form-control" type="email" style="min-width:200px" placeholder="email@accessrrs.com" value="${escHtml(p.email || '')}" onchange="savePerson('${p.id}','email',this.value)"></td>
      <td><input type="checkbox" ${p.confirmed ? 'checked' : ''} onchange="savePerson('${p.id}','confirmed',this.checked)"> ${p.confirmed ? '' : '<span class="badge badge-gap">unconfirmed</span>'}</td>
      <td><button class="btn btn-secondary" onclick="copyText('${cal}')">Copy feed</button></td>
    </tr>`;
  }).join('');
}

function renderCalendar() {
  document.getElementById('cal-all').value = `webcal://${location.host}/api/calendar.php`;
}

function renderTasks() {
  const mgmt = TASKS.filter(t => t.reminders);
  document.getElementById('mgmt-count').textContent = `${mgmt.length} reminder-enabled`;
  const ownerOpts = id => PEOPLE.map(p =>
    `<option value="${p.id}" ${p.id === id ? 'selected' : ''}>${escHtml(p.name)}</option>`).join('');
  const recOpts = r => RECURRENCES.map(x =>
    `<option value="${x}" ${x === r ? 'selected' : ''}>${x}</option>`).join('');

  mgmt.sort((a, b) => (a.dueDate || '').localeCompare(b.dueDate || ''));
  document.getElementById('mgmt-tbody').innerHTML = mgmt.map(t => `
    <tr>
      <td>${escHtml(t.title)}</td>
      <td>${escHtml(t.controlId || '')}</td>
      <td><select class="form-control" onchange="saveTaskField('${t.id}','ownerId',this.value)"><option value="">(unassigned)</option>${ownerOpts(t.ownerId)}</select></td>
      <td><select class="form-control" onchange="saveTaskField('${t.id}','recurrence',this.value)">${recOpts(t.recurrence || 'once')}</select></td>
      <td><input class="form-control" type="date" value="${escHtml(t.dueDate || '')}" onchange="saveTaskField('${t.id}','dueDate',this.value)"></td>
      <td>${timingBadge(t.dueDate)}</td>
      <td>${badge(t.status || 'open')}</td>
    </tr>`).join('') || `<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-secondary)">No reminder-enabled tasks.</td></tr>`;
}

async function savePerson(id, field, value) {
  try {
    await api.put('/api/people.php?id=' + encodeURIComponent(id), { [field]: value });
    const p = PEOPLE.find(x => x.id === id); if (p) p[field] = value;
    toast('Owner updated');
    if (field === 'confirmed' || field === 'name') renderOwners();
  } catch (e) { toast(e.message, 'error'); }
}

async function saveTaskField(id, field, value) {
  try {
    await api.put('/api/tasks.php?id=' + encodeURIComponent(id), { [field]: value });
    const t = TASKS.find(x => x.id === id); if (t) t[field] = value;
    toast('Task updated');
    renderTasks();
  } catch (e) { toast(e.message, 'error'); }
}

async function previewReminders() {
  const date = document.getElementById('preview-date').value;
  try {
    const r = await api.get('/api/reminders.php?preview=1' + (date ? '&date=' + date : ''));
    document.getElementById('preview-when').textContent = 'on ' + r.date + (r.dryRun ? ' (dry-run)' : '');
    document.getElementById('preview-tbody').innerHTML = r.items.map(i => {
      const email = i.ownerEmail
        ? escHtml(i.ownerEmail) + (i.ownerConfirmed ? '' : ' <span class="badge badge-gap">unconfirmed</span>')
        : '<span class="badge badge-gap">no email</span>';
      return `<tr><td>${escHtml(i.title)}</td><td>${escHtml(i.controlId)}</td><td>${escHtml(i.dueDate)}</td>
        <td>${escHtml(i.milestoneLabel)}</td><td>${escHtml(i.ownerName)} — ${email}</td>
        <td>${i.escalate ? 'CC CTO' : ''}</td></tr>`;
    }).join('') || `<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--text-secondary)">Nothing fires ${r.date}.</td></tr>`;
    document.getElementById('preview-card').classList.remove('hidden');
  } catch (e) { toast(e.message, 'error'); }
}

function copyText(t) { navigator.clipboard.writeText(t).then(() => toast('Copied')); }
function copyVal(id) { copyText(document.getElementById(id).value); }

document.addEventListener('DOMContentLoaded', loadAll);
