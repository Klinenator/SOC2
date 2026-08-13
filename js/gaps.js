let gapData = null;

const GAP_LABELS = {
  evidence_present: 'Evidence Present',
  partial: 'Partial',
  no_evidence: 'No Evidence',
};

async function loadGaps() {
  try {
    gapData = await api.get('/api/gaps.php');
    const s = gapData.summary;
    document.getElementById('gap-total').textContent = s.total;
    document.getElementById('gap-covered').textContent = s.evidence_present;
    document.getElementById('gap-partial').textContent = s.partial;
    document.getElementById('gap-none').textContent = s.no_evidence;
    const category = document.getElementById('gap-category');
    category.innerHTML += gapData.categories.map(c => `<option value="${escHtml(c.id)}">${escHtml(c.name)}</option>`).join('');
    document.getElementById('gap-methodology').innerHTML = Object.entries(gapData.methodology).map(([key, value]) =>
      `<div style="margin-bottom:10px"><strong>${coverageBadge(key)}</strong><span style="margin-left:10px;color:var(--text-secondary)">${escHtml(value)}</span></div>`
    ).join('');
    renderGaps();
  } catch (error) {
    document.getElementById('gaps-body').innerHTML = `<tr><td colspan="6" class="td-muted">Unable to load evidence gaps: ${escHtml(error.message)}</td></tr>`;
  }
}

function coverageBadge(coverage) {
  const colors = {
    evidence_present: 'background:var(--green-100);color:#15803d',
    partial: 'background:var(--yellow-100);color:#a16207',
    no_evidence: 'background:var(--red-100);color:#dc2626',
  };
  return `<span class="badge" style="${colors[coverage] || ''}">${GAP_LABELS[coverage] || coverage}</span>`;
}

function sourceSummary(control) {
  const s = control.sources;
  const parts = [];
  if (s.uploadedEvidence) parts.push(`${s.uploadedEvidence} upload${s.uploadedEvidence === 1 ? '' : 's'}`);
  if (s.workpaperEvidence) parts.push(`${s.workpaperEvidence} workpaper file${s.workpaperEvidence === 1 ? '' : 's'}`);
  if (s.tickets) {
    const preview = s.ticketKeys.slice(0, 3).join(', ');
    const remainder = s.ticketKeys.length > 3 ? ` +${s.ticketKeys.length - 3} more` : '';
    parts.push(`${s.tickets} ticket${s.tickets === 1 ? '' : 's'}${preview ? ` (${preview}${remainder})` : ''}`);
  }
  if (s.approvedPolicies) parts.push(`${s.approvedPolicies} approved polic${s.approvedPolicies === 1 ? 'y' : 'ies'}`);
  if (s.completedWorkpapers) parts.push(`${s.completedWorkpapers} completed workpaper${s.completedWorkpapers === 1 ? '' : 's'}`);
  if (s.unresolvedTicketRefs.length) parts.push(`${s.unresolvedTicketRefs.length} unmatched ticket ref${s.unresolvedTicketRefs.length === 1 ? '' : 's'}`);
  return parts.length ? parts.join(' · ') : 'No reviewable artifact found';
}

function requirementSummary(control) {
  const r = control.requirements;
  const total = r.on_track + r.stale + r.missing;
  if (!total) return 'No recurring requirement defined';
  return `${r.on_track} on track · ${r.stale} stale · ${r.missing} missing`;
}

function renderGaps() {
  if (!gapData) return;
  const search = document.getElementById('gap-search').value.trim().toLowerCase();
  const coverage = document.getElementById('gap-coverage').value;
  const category = document.getElementById('gap-category').value;
  const controls = gapData.controls.filter(control => {
    const matchesText = !search || `${control.id} ${control.name} ${control.categoryName}`.toLowerCase().includes(search);
    const matchesCoverage = coverage === 'all' || (coverage === 'attention' ? control.coverage !== 'evidence_present' : control.coverage === coverage);
    const matchesCategory = category === 'all' || control.category === category;
    return matchesText && matchesCoverage && matchesCategory;
  });
  document.getElementById('gap-count').textContent = `${controls.length} control${controls.length === 1 ? '' : 's'}`;
  document.getElementById('gaps-body').innerHTML = controls.length ? controls.map(control => `
    <tr>
      <td><div class="td-mono"><a href="/controls.html">${escHtml(control.id)}</a></div><div class="td-name">${escHtml(control.name)}</div><div class="td-muted">${escHtml(control.categoryName)} · assertion ${escHtml(control.controlStatus)}</div></td>
      <td>${coverageBadge(control.coverage)}</td>
      <td class="td-muted">${escHtml(sourceSummary(control))}</td>
      <td class="td-muted">${escHtml(requirementSummary(control))}</td>
      <td>${control.openTasks || '—'}</td>
      <td class="td-muted">${escHtml(control.recommendations.join(' '))}</td>
    </tr>`).join('') : '<tr><td colspan="6" class="td-muted">No controls match these filters.</td></tr>';
}

document.addEventListener('DOMContentLoaded', loadGaps);
