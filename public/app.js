let CSRF = null;
let chartRef = null;

const $ = sel => document.querySelector(sel);
const fmtMoney = v => Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

async function getCSRF() {
  const r = await fetch('../api/csrf');
  const j = await r.json();
  CSRF = j.token;
}

function filters() {
  const params = new URLSearchParams();
  const f = $('#from').value.trim();
  const t = $('#to').value.trim();
  const c = $('#category').value.trim();
  const q = $('#q').value.trim();
  if (f) params.set('from', f);
  if (t) params.set('to', t);
  if (c) params.set('category', c);
  if (q) params.set('q', q);
  return params;
}

async function loadStats() {
  const r = await fetch('../api/stats?' + filters().toString());
  const j = await r.json();

  $('#income').textContent = fmtMoney(j.income);
  $('#expense').textContent = fmtMoney(j.expense);
  $('#balance').textContent = fmtMoney(j.balance);

  const labels = j.series.map(s => s.ym);
  const data = j.series.map(s => Number(s.total));

  if (chartRef) { chartRef.destroy(); }
  const ctx = $('#chart').getContext('2d');
  chartRef = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: 'Total por mês', data }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false
    }
  });
}

function rowTemplate(item) {
  const tpl = $('#row').content.cloneNode(true);
  Object.entries(item).forEach(([k,v]) => {
    const c = tpl.querySelector(`[data-k="${k}"]`);
    if (!c) return;
    c.textContent = k === 'amount' ? fmtMoney(v) : (v ?? '');
    if (k === 'amount') {
      c.style.color = Number(v) >= 0 ? 'var(--positive)' : 'var(--negative)';
      c.style.fontWeight = '700';
    }
  });
  tpl.querySelector('[data-act="edit"]').onclick = () => openEdit(item);
  tpl.querySelector('[data-act="del"]').onclick = () => delItem(item.id);
  return tpl;
}

async function loadTable() {
  const r = await fetch('../api/transactions?' + filters().toString());
  const j = await r.json();
  const tbody = $('#tbody');
  tbody.innerHTML = '';
  j.items.forEach(it => tbody.appendChild(rowTemplate(it)));
}

async function addItem(ev) {
  ev.preventDefault();
  const fd = new FormData(ev.currentTarget);
  const data = Object.fromEntries(fd.entries());
  data.amount = Number(data.amount);

  const r = await fetch('../api/transactions', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(data)
  });
  if (!r.ok) {
    const j = await r.json();
    alert('Erro ao adicionar:\n' + JSON.stringify(j, null, 2));
    return;
  }
  ev.currentTarget.reset();
  await Promise.all([loadStats(), loadTable()]);
}

function openEdit(item) {
  const dlg = $('#dlgEdit');
  const f = $('#formEdit');
  f.id.value = item.id;
  f.date.value = item.date;
  f.description.value = item.description;
  f.category.value = item.category;
  f.account.value = item.account;
  f.amount.value = item.amount;
  f.tags.value = item.tags ?? '';
  dlg.showModal();
}

async function saveEdit(ev) {
  ev.preventDefault();
  const fd = new FormData(ev.currentTarget);
  const id = fd.get('id');
  const data = Object.fromEntries(fd.entries());
  delete data.id;
  data.amount = Number(data.amount);

  const r = await fetch(`../api/transactions/${id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(data)
  });
  if (!r.ok) {
    const j = await r.json();
    alert('Erro ao salvar:\n' + JSON.stringify(j, null, 2));
    return;
  }
  $('#dlgEdit').close();
  await Promise.all([loadStats(), loadTable()]);
}

async function delItem(id) {
  if (!confirm('Excluir esta transação?')) return;
  const r = await fetch(`../api/transactions/${id}`, {
    method: 'DELETE',
    headers: { 'X-CSRF-Token': CSRF }
  });
  if (!r.ok) {
    const j = await r.json();
    alert('Erro ao excluir:\n' + JSON.stringify(j, null, 2));
    return;
  }
  await Promise.all([loadStats(), loadTable()]);
}

function bindFilters() {
  $('#btnFilter').onclick = async () => { await Promise.all([loadStats(), loadTable()]); };
  $('#btnClear').onclick = async () => {
    $('#from').value = '';
    $('#to').value = '';
    $('#category').value = '';
    $('#q').value = '';
    await Promise.all([loadStats(), loadTable()]);
  };
  $('#btnExport').onclick = () => {
    const url = '../api/export?' + filters().toString();
    window.open(url, '_blank');
  };
  $('#importFile').onchange = async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('file', file);
    const r = await fetch('../api/import', {
      method: 'POST',
      headers: { 'X-CSRF-Token': CSRF },
      body: fd
    });
    const j = await r.json();
    if (!r.ok) {
      alert('Erro ao importar:\n' + JSON.stringify(j, null, 2));
      return;
    }
    alert(`Importados: ${j.imported}`);
    e.target.value = '';
    await Promise.all([loadStats(), loadTable()]);
  };
}

function bindForms() {
  $('#formNew').addEventListener('submit', addItem);
  $('#formEdit').addEventListener('submit', saveEdit);
  $('#btnCancelEdit').onclick = () => $('#dlgEdit').close();
}

(async function init() {
  await getCSRF();
  bindFilters();
  bindForms();
  await Promise.all([loadStats(), loadTable()]);
})();
