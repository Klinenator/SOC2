let allTasks = [];
let allControls = [];
let editingTaskId = null;

async function load() {
  [allTasks, allControls] = await Promise.all([
    api.get('/api/tasks.php'),
    api.get('/api/controls.php'),
  ]);
  populateControlFilter();
  populateControlSelect('task-control');
  renderTasks();
  updateSubtitle();
}

function updateSubtitle() {
  const open = allTasks.filter(t => t.status === 'open').length;
  const overdue = allTasks.filter(t => t.status === 'open' && t.dueDate && t.dueDate < today()).length;
  let sub = `${allTasks.length} task${allTasks.length !== 1 ? 's' : ''} — ${open} open`;
  if (overdue) sub += ` · ${overdue} overdue`;
  document.getElementById('tasks-subtitle').textContent = sub;
}

function today() { return new Date().toISOString().split('T')[0]; }

function populateControlFilter() {
  const sel = document.getElementById('filter-control');
  allControls.forEach(c => {
    const o = document.createElement('option');
    o.value = c.id; o.textContent = `${c.id} — ${c.name.substring(0,40)}`;
    sel.appendChild(o);
  });
}

function populateControlSelect(id) {
  const sel = document.getElementById(id);
  if (!sel) return;
  // Keep first option
  const first = sel.options[0];
  sel.innerHTML = '';
  sel.appendChild(first);
  allControls.forEach(c => {
    const o = document.createElement('option');
    o.value = c.id; o.textContent = `${c.id} — ${c.name.substring(0,50)}`;
    sel.appendChild(o);
  });
}

function filterTasks() {
  const q = document.getElementById('search').value.toLowerCase();
  const status = document.getElementById('filter-status').value;
  const priority = document.getElementById('filter-priority').value;
  const ctrl = document.getElementById('filter-control').value;

  const filtered = allTasks.filter(t => {
    if (status && t.status !== status) return false;
    if (priority && t.priority !== priority) return false;
    if (ctrl && t.controlId !== ctrl) return false;
    if (q && !t.title.toLowerCase().includes(q) &&
        !(t.description||'').toLowerCase().includes(q) &&
        !(t.assignee||'').toLowerCase().includes(q)) return false;
    return true;
  });
  renderTasks(filtered);
}

function renderTasks(tasks = allTasks) {
  const tbody = document.getElementById('tasks-tbody');
  document.getElementById('tasks-count').textContent = `${tasks.length} task${tasks.length !== 1 ? 's' : ''}`;

  if (!tasks.length) {
    tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
      <h3>No tasks found</h3><p>Create a task using the button above</p></div></td></tr>`;
    return;
  }

  tbody.innerHTML = tasks.map(t => {
    const isOverdue = t.status === 'open' && t.dueDate && t.dueDate < today();
    const dueCls = isOverdue ? 'style="color:var(--red-500);font-weight:600"' : '';
    return `<tr ${t.status === 'closed' ? 'style="opacity:.65"' : ''}>
      <td>${badge(t.priority)}</td>
      <td class="td-name" style="max-width:220px">
        ${t.status === 'closed' ? '<s>' : ''}${escHtml(t.title)}${t.status === 'closed' ? '</s>' : ''}
        ${t.description ? `<div class="td-muted" style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px">${escHtml(t.description)}</div>` : ''}
      </td>
      <td>${t.controlId ? `<span class="td-mono">${escHtml(t.controlId)}</span>` : '<span class="td-muted">—</span>'}</td>
      <td class="td-muted">${escHtml(t.assignee) || '—'}</td>
      <td ${dueCls}>${formatDate(t.dueDate)}${isOverdue ? ' ⚠' : ''}</td>
      <td>${badge(t.status)}</td>
      <td>
        <div class="td-actions">
          <button class="btn btn-ghost btn-sm" onclick="openEditTask('${escHtml(t.id)}')">Edit</button>
          ${t.status === 'open'
            ? `<button class="btn btn-ghost btn-sm" style="color:var(--green-500)" onclick="closeTask('${escHtml(t.id)}')">✓ Close</button>`
            : `<button class="btn btn-ghost btn-sm" onclick="reopenTask('${escHtml(t.id)}')">Reopen</button>`}
          <button class="btn btn-ghost btn-sm" style="color:var(--red-500)" onclick="deleteTask('${escHtml(t.id)}')">Delete</button>
        </div>
      </td>
    </tr>`;
  }).join('');
}

function resetTaskForm() {
  editingTaskId = null;
  document.getElementById('task-modal-title').textContent = 'New Task';
  document.getElementById('task-id').value = '';
  document.getElementById('task-title').value = '';
  document.getElementById('task-desc').value = '';
  document.getElementById('task-control').value = '';
  document.getElementById('task-assignee').value = '';
  document.getElementById('task-priority').value = 'medium';
  document.getElementById('task-due').value = '';
  document.getElementById('task-status-group').style.display = 'none';
}

function openEditTask(id) {
  const t = allTasks.find(x => x.id === id);
  if (!t) return;
  editingTaskId = id;
  document.getElementById('task-modal-title').textContent = 'Edit Task';
  document.getElementById('task-id').value = t.id;
  document.getElementById('task-title').value = t.title;
  document.getElementById('task-desc').value = t.description || '';
  document.getElementById('task-control').value = t.controlId || '';
  document.getElementById('task-assignee').value = t.assignee || '';
  document.getElementById('task-priority').value = t.priority;
  document.getElementById('task-due').value = t.dueDate || '';
  document.getElementById('task-status').value = t.status;
  document.getElementById('task-status-group').style.display = '';
  openModal('task-modal');
}

async function saveTask() {
  const title = document.getElementById('task-title').value.trim();
  if (!title) { toast('Title is required', 'error'); return; }
  const body = {
    title,
    description: document.getElementById('task-desc').value.trim(),
    controlId:   document.getElementById('task-control').value,
    assignee:    document.getElementById('task-assignee').value.trim(),
    priority:    document.getElementById('task-priority').value,
    dueDate:     document.getElementById('task-due').value,
  };
  if (editingTaskId) body.status = document.getElementById('task-status').value;

  try {
    if (editingTaskId) {
      const updated = await api.put(`/api/tasks.php?id=${editingTaskId}`, body);
      const idx = allTasks.findIndex(t => t.id === editingTaskId);
      if (idx > -1) Object.assign(allTasks[idx], updated);
      toast('Task updated');
    } else {
      const created = await api.post('/api/tasks.php', body);
      allTasks.unshift(created);
      toast('Task created');
    }
    filterTasks();
    updateSubtitle();
    loadNavBadge();
    closeModal('task-modal');
  } catch (e) {
    toast('Error: ' + e.message, 'error');
  }
}

async function closeTask(id) {
  try {
    const updated = await api.put(`/api/tasks.php?id=${id}`, { status: 'closed' });
    const idx = allTasks.findIndex(t => t.id === id);
    if (idx > -1) Object.assign(allTasks[idx], updated);
    filterTasks(); updateSubtitle(); loadNavBadge();
    toast('Task closed ✓');
  } catch (e) { toast('Error: ' + e.message, 'error'); }
}

async function reopenTask(id) {
  try {
    const updated = await api.put(`/api/tasks.php?id=${id}`, { status: 'open' });
    const idx = allTasks.findIndex(t => t.id === id);
    if (idx > -1) Object.assign(allTasks[idx], updated);
    filterTasks(); updateSubtitle(); loadNavBadge();
    toast('Task reopened');
  } catch (e) { toast('Error: ' + e.message, 'error'); }
}

async function deleteTask(id) {
  if (!confirm('Delete this task?')) return;
  try {
    await api.delete(`/api/tasks.php?id=${id}`);
    allTasks = allTasks.filter(t => t.id !== id);
    filterTasks(); updateSubtitle(); loadNavBadge();
    toast('Task deleted');
  } catch (e) { toast('Error: ' + e.message, 'error'); }
}

document.addEventListener('DOMContentLoaded', load);
