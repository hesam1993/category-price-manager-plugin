// Run: node tests/admin-regression.cjs
// Execute the shipped script against a small DOM and controlled AJAX failures.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
class Element {
  constructor(tag = 'div') { this.tag = tag; this.children = []; this.listeners = {}; this.disabled = false; this.hidden = false; }
  appendChild(child) { this.children.push(child); return child; }
  replaceChildren(...children) { this.children = children; }
  addEventListener(event, callback) { this.listeners[event] = callback; }
  querySelectorAll(tag) { return this.children.flatMap(child => [...(child.tag === tag ? [child] : []), ...child.querySelectorAll(tag)]); }
}
const elements = new Map();
const calls = [];
const counts = { pending: 1, applied: 0, skipped: 0, conflict: 0, undone: 0 };
let failPreview = false;
let failApply = false;
let upgradeResponses = 2;
let currentJob = { id: 2, date: 'today', status: 'ready', total: 1, counts, rule_parts: { operation: 'increase', amount: '20', method: 'percent', field: 'regular' } };
const previewData = id => ({ job_id: id, total: 1, counts, sample: [{ product: 'Product ' + id, before: { regular: '100', sale: '' }, after: { regular: '110', sale: '' }, note: 'Ready' }] });
const context = {
  document: { querySelector: selector => { if (!elements.has(selector)) elements.set(selector, new Element()); return elements.get(selector); }, createElement: tag => new Element(tag) },
  CPM: { ajax: '/ajax', nonce: 'test', currency: '$', decimals: 2 }, URLSearchParams, AbortController, setTimeout, clearTimeout,
  window: { confirm: () => true },
  fetch: async (url, options) => {
    const action = options.body.get('action');
    const id = Number(options.body.get('job_id'));
    const page = Number(options.body.get('page')) || 1;
    calls.push({ action, id, page });
    let data;
    if (action === 'cpm_history') data = { jobs: [currentJob], page, has_more: page === 1 };
    else if (action === 'cpm_preview') { if (failPreview) throw Error('Network failure'); data = previewData(id); }
    else if (action === 'cpm_apply') {
      if (failApply) throw Error('Network failure');
      if (upgradeResponses > 0) {
        upgradeResponses--;
        assert.equal(elements.get('#cpm-apply').disabled, true);
        data = { done: false, phase: 'database_upgrade', total: 1, counts };
      } else {
        currentJob = { ...currentJob, status: 'completed', counts: { ...counts, pending: 0, applied: 1 } };
        data = { done: true, total: 1, counts: currentJob.counts };
      }
    } else throw Error('Unexpected AJAX action: ' + action);
    return { ok: true, redirected: false, text: async () => JSON.stringify({ success: true, data }) };
  }
};
let source = fs.readFileSync('assets/admin.js', 'utf8');
// Expose only view setup helpers; all actions below use the actual event handlers.
source = source.replace('  loadHistory().catch', '  globalThis.audit = {renderPreview, loadHistory};\n  loadHistory().catch');
vm.createContext(context);
vm.runInContext(source, context);
const button = label => elements.get('#cpm-history').querySelectorAll('button').find(el => el.textContent === label);
const click = async el => { assert(el, 'Expected button'); assert.equal(el.disabled, false, 'Button must be usable'); await el.listeners.click(); };
(async () => {
  await new Promise(resolve => setImmediate(resolve));
  context.audit.renderPreview(previewData(1));
  failPreview = true;
  await click(button('Load preview'));
  assert.equal(elements.get('#cpm-preview').hidden, true);
  assert.equal(elements.get('#cpm-apply').disabled, true);
  await elements.get('#cpm-apply').listeners.click(); // Programmatic dispatch must also be blocked.
  assert.equal(calls.filter(call => call.action === 'cpm_apply').length, 0);
  console.log('PASS: failed preview switch cannot apply a different job');
  failPreview = false;
  await click(button('Load preview'));
  await click(elements.get('#cpm-apply'));
  assert.equal(calls.filter(call => call.action === 'cpm_apply').length, 3);
  assert(calls.filter(call => call.action === 'cpm_apply').every(call => call.id === 2));
  console.log('PASS: two upgrade responses continue into Apply with the same job');
  assert.equal(button('Undo').disabled, false);
  assert.equal(elements.get('#cpm-apply').disabled, true);
  console.log('PASS: successful preview binds the correct job and enables Undo after completion');
  await click(button('Next page'));
  assert.equal(calls.at(-1).page, 2);
  assert.equal(button('Previous page').disabled, false);
  assert.equal(button('Next page'), undefined);
  await click(button('Previous page'));
  assert.equal(calls.at(-1).page, 1);
  console.log('PASS: history pagination supports older jobs and return navigation');
  currentJob = { ...currentJob, status: 'ready', counts };
  await context.audit.loadHistory();
  await click(button('Load preview'));
  failApply = true;
  await click(elements.get('#cpm-apply'));
  assert.equal(button('Load preview').disabled, false);
  assert.equal(elements.get('#cpm-apply').disabled, true);
  console.log('PASS: request errors restore history controls without restoring stale Apply');
})().catch(error => { console.error(error); process.exitCode = 1; });
