let workpapers = [], editingId = null;

async function loadWorkpapers() {
  const result = await api.get('/api/audit_tests.php');
  workpapers = result.workpapers || [];
  const controls = [...new Set(workpapers.map(w => w.controlId))];
  document.getElementById('control-filter').innerHTML += controls.map(id => `<option value="${escHtml(id)}">${escHtml(id)}</option>`).join('');
  renderStats(result.summary || {}); filterWorkpapers();
}
function renderStats(s) {
  document.getElementById('wp-total').textContent = s.total || 0;
  document.getElementById('wp-tested').textContent = s.tested || 0;
  document.getElementById('wp-ready').textContent = s.ready || 0;
  document.getElementById('wp-exceptions').textContent = s.exception || 0;
}
function filterWorkpapers() {
  const q = document.getElementById('search').value.toLowerCase(), c = document.getElementById('control-filter').value, s = document.getElementById('status-filter').value;
  const rows = workpapers.filter(w => (!c || w.controlId === c) && (!s || w.status === s) && (!q || `${w.id} ${w.activity} ${w.populationSource}`.toLowerCase().includes(q)));
  document.getElementById('count').textContent = `${rows.length} activities`;
  document.getElementById('tbody').innerHTML = rows.map(w => `<tr><td class="td-mono">${escHtml(w.id)}</td><td class="td-name" style="max-width:360px">${escHtml(w.activity)}<div class="td-muted" style="font-size:11px">${escHtml(w.priorProcedure)}</div></td><td>${escHtml(w.frequency)}</td><td>${escHtml((w.priorResult||'').replaceAll('_',' '))}</td><td class="td-muted">${escHtml(w.populationSource) || 'Not defined'}</td><td>${badge(w.status)}</td><td>${(w.evidenceIds||[]).length}</td><td><button class="btn btn-ghost btn-sm" onclick="editWorkpaper('${escHtml(w.id)}')">Edit</button></td></tr>`).join('');
}
function editWorkpaper(id) {
  const w = workpapers.find(x => x.id === id); if (!w) return; editingId = id;
  document.getElementById('modal-title').textContent = w.id; document.getElementById('activity').textContent = w.activity;
  document.getElementById('wp-status').value = w.status; document.getElementById('wp-owner').value = w.ownerId || ''; document.getElementById('wp-population').value = w.populationSource || '';
  document.getElementById('wp-samples').value = (w.sampleRefs||[]).join('\n'); document.getElementById('wp-conclusion').value = w.conclusion || ''; document.getElementById('wp-exception').value = w.exception || ''; openModal('edit-modal');
}
async function saveWorkpaper() {
  const body = {status:document.getElementById('wp-status').value,ownerId:document.getElementById('wp-owner').value.trim(),populationSource:document.getElementById('wp-population').value.trim(),sampleRefs:document.getElementById('wp-samples').value.split('\n').map(x=>x.trim()).filter(Boolean),conclusion:document.getElementById('wp-conclusion').value.trim(),exception:document.getElementById('wp-exception').value.trim()};
  const updated = await api.put(`/api/audit_tests.php?id=${encodeURIComponent(editingId)}`,body); Object.assign(workpapers.find(w=>w.id===editingId),updated); closeModal('edit-modal'); filterWorkpapers(); const r=await api.get('/api/audit_tests.php'); renderStats(r.summary); toast('Workpaper saved');
}
function exportCSV(){const h=['ID','Control','Activity','Frequency','Prior Result','Population','Status','Owner','Samples','Conclusion','Exception'];const rows=workpapers.map(w=>[w.id,w.controlId,w.activity,w.frequency,w.priorResult,w.populationSource,w.status,w.ownerId,(w.sampleRefs||[]).join('; '),w.conclusion,w.exception].map(v=>`"${String(v||'').replaceAll('"','""')}"`).join(','));const a=document.createElement('a');a.href='data:text/csv;charset=utf-8,'+encodeURIComponent([h.join(','),...rows].join('\n'));a.download=`soc2-management-testing-${new Date().toISOString().slice(0,10)}.csv`;a.click();}
document.addEventListener('DOMContentLoaded',loadWorkpapers);
