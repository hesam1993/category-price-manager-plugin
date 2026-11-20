(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const t = (key) => (CPM.i18n && CPM.i18n[key]) || key;
  const ruleLabel = (parts) => t(parts.operation) + ' ' + parts.amount + (parts.method === 'percent' && parts.operation !== 'set' ? '%' : ' (' + t('currency') + ')') + ' / ' + t(parts.field);
  const form = $('#cpm-form');
  const feedback = $('#cpm-feedback');
  const preview = $('#cpm-preview');
  const progress = $('#cpm-progress');
  const summary = $('#cpm-summary');
  const tbody = $('#cpm-preview-body');
  const history = $('#cpm-history');
  const applyButton = $('#cpm-apply');
  const prepareButton = $('#cpm-prepare');
  const operation = $('#cpm-operation');
  const method = $('#cpm-method');
  const jsStatus = $('#cpm-js-status');
  if (!form || !feedback || !prepareButton || !applyButton || typeof CPM === 'undefined' || !CPM.ajax || !CPM.nonce) {
    return; // Keep the visible diagnostic if this script cannot initialize.
  }
  if (jsStatus) jsStatus.hidden = true;
  let jobId = null;
  let busy = false;
  let previewAllowed = false;
  let historyPage = 1;

  function node(tag, text, className) {
    const el = document.createElement(tag);
    if (text !== undefined) el.textContent = text;
    if (className) el.className = className;
    return el;
  }
  async function request(action, data = {}) {
    const body = new URLSearchParams();
    body.set('action', 'cpm_' + action);
    body.set('nonce', CPM.nonce);
    Object.entries(data).forEach(([key, value]) => {
      if (Array.isArray(value)) value.forEach((v) => body.append(key + '[]', String(v)));
      else body.set(key, String(value));
    });
    // AJAX must never navigate the admin page. Handle JSON errors, redirects,
    // timeouts and HTML error pages explicitly, without depending on browser UI.
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 30000);
    let response;
    let raw;
    try {
      response = await fetch(CPM.ajax, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        body, signal: controller.signal, redirect: 'follow'
      });
      raw = await response.text();
    } catch (error) {
      if (error && error.name === 'AbortError') {
        throw new Error(t('Request timed out. The operation can be resumed from Update History.'));
      }
      throw new Error(t('Request failed.') + ' ' + (error && error.message ? error.message : 'Network error'));
    } finally {
      clearTimeout(timer);
    }
    if (response.redirected && /wp-login\.php|wp-admin\/login/i.test(response.url)) {
      throw new Error(t('The request was redirected to a login page. Log in again and retry.'));
    }
    let result;
    try { result = JSON.parse(raw); }
    catch (_) {
      throw new Error(t('Request failed.') + ' HTTP ' + response.status + '. ' +
        t('The server returned a page instead of a JSON response. Check the browser Network tab and PHP error log.'));
    }
    if (!response.ok || !result || !result.success) {
      throw new Error(result && result.data && result.data.message || t('Request failed.') + ' HTTP ' + response.status);
    }
    return result.data;
  }
  function message(text, isError = false) {
    feedback.replaceChildren(node('p', text, isError ? 'cpm-error' : ''));
  }
  function busyUI(value) {
    busy = value;
    prepareButton.disabled = value;
    applyButton.disabled = value || !previewAllowed;
    history.querySelectorAll('button').forEach((button) => { button.disabled = value; });
  }
  function resetPreview() {
    jobId = null;
    previewAllowed = false;
    preview.hidden = true;
    applyButton.disabled = true;
  }
  function price(v) { return v === '' ? '—' : CPM.currency + Number(v).toFixed(Number(CPM.decimals)); }
  function pair(p) { return price(p.regular) + ' / ' + price(p.sale); }
  function countsText(c) { return `${t('Applied:')} ${c.applied} · ${t('Skipped:')} ${c.skipped} · ${t('Conflicts:')} ${c.conflict} · ${t('Undone:')} ${c.undone}`; }
  function renderPreview(data) {
    tbody.replaceChildren();
    for (const item of data.sample) {
      const tr = node('tr');
      [item.product, pair(item.before), pair(item.after), item.note].forEach((value) => tr.appendChild(node('td', value)));
      tbody.appendChild(tr);
    }
    summary.textContent = `${data.total} ${t('product records matched.')} ${data.counts.pending} ${t('ready to change;')} ${data.counts.skipped} ${t('skipped by pricing rules.')}`;
    if (!data.total) summary.textContent += ' ' + t('No products found for these categories and settings.');
    jobId = data.job_id;
    previewAllowed = data.counts.pending > 0;
    applyButton.disabled = busy || !previewAllowed;
    preview.hidden = false;
    progress.hidden = true;
    message(t('Preview ready. Confirm to begin updating prices.'));
  }
  function progressUpdate(data, undo) {
    const c = data.counts;
    const processed = undo ? c.undone + c.conflict + c.skipped : c.applied + c.conflict + c.skipped;
    const max = Math.max(1, data.total);
    $('#cpm-bar').value = Math.min(100, (processed / max) * 100);
    $('#cpm-count').textContent = `${processed} / ${data.total} · ${countsText(c)}`;
  }
  function formData() {
    const fd = new FormData(form);
    return {
      categories: fd.getAll('categories[]'),
      descendants: fd.has('descendants') ? 1 : 0,
      operation: fd.get('operation'), method: fd.get('operation') === 'set' ? 'fixed' : fd.get('method'),
      amount: fd.get('amount'), field: fd.get('field'),
      variations: fd.has('variations') ? 1 : 0
    };
  }
  async function run(action, id) {
    resetPreview();
    progress.hidden = false;
    preview.hidden = true;
    message(action === 'apply' ? t('Updating prices in batches. Keep this page open.') : t('Restoring original prices in batches. Keep this page open.'));
    let result;
    do {
      result = await request(action, { job_id: id });
      progressUpdate(result, action === 'undo');
      if (!result.done) {
        message(result.phase === 'database_upgrade'
          ? t('Preparing safe price updates. Keep this page open.')
          : action === 'apply' ? t('Updating prices in batches. Keep this page open.') : t('Restoring original prices in batches. Keep this page open.'));
      }
    } while (!result.done);
    message(action === 'apply' ? t('Update completed.') + ' ' + countsText(result.counts) : t('Undo completed.') + ' ' + countsText(result.counts));
    try { await loadHistory(); } catch (_) { /* Completed operation still succeeded. */ }
  }
  async function startPreview() {
    if (busy) return;
    const data = formData();
    if (!data.categories.length) { message(t('Select at least one category.'), true); return; }
    resetPreview(); busyUI(true); progress.hidden = false;
    $('#cpm-bar').value = 0;
    $('#cpm-count').textContent = '';
    try {
      const created = await request('create', data);
      const stagingId = created.job_id;
      historyPage = 1;
      let stage;
      do {
        stage = await request('stage', { job_id: stagingId });
        message(`${t('Collecting products and saving original prices:')} ${stage.count} ${t('records so far…')}`);
      } while (!stage.done);
      const result = await request('preview', { job_id: stagingId });
      renderPreview(result);
    } catch (err) {
      message(err && err.message || t('Request failed.'), true);
      try { await loadHistory(); } catch (_) { /* Show original error above. */ }
    } finally {
      busyUI(false);
    }
  }
  // Neither clicking Preview nor pressing Enter should use the browser's
  // default form submission. The button is type=button even if JS does not load.
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    startPreview();
  });
  prepareButton.addEventListener('click', (event) => {
    event.preventDefault();
    startPreview();
  });
  applyButton.addEventListener('click', async () => {
    if (busy || !previewAllowed || !jobId) return;
    if (!window.confirm(t('Apply all previewed category price changes? This can affect your storefront immediately.'))) return;
    busyUI(true);
    try { await run('apply', jobId); }
    catch (err) { message(t('Paused:') + ' ' + err.message + ' ' + t('You can resume from the history section.'), true); try { await loadHistory(); } catch (_) {} }
    finally { busyUI(false); }
  });
  async function loadHistory(page = historyPage) {
    const result = await request('history', { page });
    historyPage = result.page;
    history.replaceChildren();
    if (!result.jobs.length) history.appendChild(node('p', t('No jobs yet.')));
    const table = node('table'); table.className = 'widefat striped';
    const head = node('thead'); const hr = node('tr');
    ['Date / job', 'Rule', 'Status', 'Products', 'Results', 'Action'].map(t).forEach((v) => hr.appendChild(node('th', v)));
    head.appendChild(hr); table.appendChild(head);
    const body = node('tbody');
    for (const job of result.jobs) {
      const row = node('tr');
      [job.date + ' (#' + job.id + ')', ruleLabel(job.rule_parts), t(job.status), String(job.total), countsText(job.counts)].forEach((v) => row.appendChild(node('td', v)));
      const actionCell = node('td');
      let label = null; let action = null;
      if (job.status === 'completed' && job.counts.applied) { label = t('Undo'); action = 'undo'; }
      else if (job.status === 'undoing') { label = t('Resume undo'); action = 'undo'; }
      else if (job.status === 'applying') { label = t('Resume update'); action = 'apply'; }
      else if (job.status === 'staging') { label = t('Resume preview'); action = 'stage'; }
      else if (job.status === 'ready') { label = t('Load preview'); action = 'preview'; }
      if (action) {
        const button = node('button', label); button.type = 'button'; button.className = 'button';
        button.disabled = busy;
        button.addEventListener('click', async () => {
          if (busy) return;
          if (action === 'undo' && !window.confirm(t('Undo this price update wherever the price still matches the value it set?'))) return;
          resetPreview(); busyUI(true);
          try {
            if (action === 'stage') {
              let result;
              do { result = await request('stage', { job_id: job.id }); message(`${t('Collecting snapshot:')} ${result.count} ${t('records…')}`); } while (!result.done);
              renderPreview(await request('preview', { job_id: job.id }));
            } else if (action === 'preview') { renderPreview(await request('preview', { job_id: job.id })); }
            else await run(action, job.id);
          } catch (err) { message(t('Paused:') + ' ' + err.message, true); try { await loadHistory(); } catch (_) {} }
          finally { busyUI(false); }
        });
        actionCell.appendChild(button);
      }
      row.appendChild(actionCell); body.appendChild(row);
    }
    table.appendChild(body); history.appendChild(table);
    const navigation = node('p');
    for (const [label, page, visible] of [
      ['Previous page', historyPage - 1, historyPage > 1],
      ['Next page', historyPage + 1, result.has_more]
    ]) {
      if (!visible) continue;
      const button = node('button', t(label), 'button');
      button.type = 'button'; button.disabled = busy;
      button.addEventListener('click', async () => {
        if (busy) return;
        busyUI(true);
        try { await loadHistory(page); }
        catch (err) { message(t('Could not load history:') + ' ' + err.message, true); }
        finally { busyUI(false); }
      });
      navigation.appendChild(button);
    }
    history.appendChild(navigation);
  }
  operation.addEventListener('change', () => {
    method.disabled = operation.value === 'set';
  });
  loadHistory().catch((err) => message(t('Could not load history:') + ' ' + err.message, true));
})();
