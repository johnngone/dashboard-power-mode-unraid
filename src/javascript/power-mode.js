/* SPDX-License-Identifier: GPL-2.0-only */
(() => {
  'use strict';
  const endpoint = '/plugins/dashboard-power-mode/api.php';
  function init() {
    let root = document.getElementById('dashboard-power-mode');
    if (!root || root.dataset.initialized) return;
    const processor = document.getElementById('cpu_main')?.closest('tbody');
    if (processor && processor !== root && root.tagName === 'TBODY') {
      const section = document.createElement('div');
      section.id = root.id;
      section.className = 'dpm-adjustments';
      // Keep the controls inside Processor so native sorting and collapsing
      // move them together, without modifying any Unraid source files.
      root.querySelectorAll('.tile, .openclose').forEach((control) => control.remove());
      Array.from(root.rows).forEach((row) => {
        const cell = row.cells[0];
        while (cell?.firstChild) section.append(cell.firstChild);
      });
      const row = document.createElement('tr');
      row.className = 'dpm-adjustments-row';
      const cell = document.createElement('td');
      cell.append(section);
      row.append(cell);
      processor.append(row);
      const spacer = root.previousElementSibling;
      if (spacer?.querySelector('.stopgap')) spacer.remove();
      root.remove();
      root = section;
    }
    root.dataset.initialized = '1';
    const el = (name) => root.querySelector(`[data-dpm="${name}"]`);
    let state;
    let busy = false;
    let sliding = false;
    let revision = 0;
    let frequencyBusy = false;
    const frequencyLabels = [];
    processor?.querySelectorAll('tr.cpu_open').forEach((row) => {
      const metrics = [];
      row.querySelectorAll('.load.resize').forEach((load) => {
        const cpuClass = Array.from(load.classList).find((name) => /^cpu\d+$/.test(name));
        if (!cpuClass) return;
        const metric = load.parentElement;
        metric.classList.add('dpm-cpu-metric');
        const label = document.createElement('span');
        label.className = 'dpm-cpu-ghz';
        label.dataset.cpu = cpuClass.slice(3);
        label.textContent = '— GHz';
        label.title = `CPU ${label.dataset.cpu} sampled frequency`;
        metric.append(label);
        frequencyLabels.push(label);
        metrics.push(metric);
      });
      if (metrics.length) {
        row.cells[0].classList.add('dpm-cpu-row');
        if (metrics.length === 1) metrics[0].style.gridColumn = 'span 2';
      }
    });
    const mhz = (khz) => `${(khz / 1000).toLocaleString(undefined, { maximumFractionDigits: 3 })} MHz`;
    const message = (text) => { el('message').textContent = text; el('message').hidden = !text; };
    function render(next) {
      state = next;
      el('hardware').textContent = next.drivers.length ? `${next.drivers.join(', ')} adjustment` : 'CPU adjustment';
      el('governor').replaceChildren();
      if (next.governor === null) el('governor').add(new Option('Mixed / unavailable', '', true, true));
      next.governors.forEach((governor) => el('governor').add(new Option(governor, governor, false, next.governor === governor)));
      el('governor').disabled = busy || !next.governor_supported;
      el('turbo').checked = next.turbo.enabled === true;
      el('turbo').indeterminate = next.turbo.supported && next.turbo.enabled === null;
      el('turbo').disabled = busy || !next.turbo.supported;
      el('turbo-status').textContent = !next.turbo.supported ? 'Unavailable'
        : (next.turbo.enabled === null ? 'Mixed' : (next.turbo.enabled ? 'On' : 'Off'));
      const freq = next.frequency;
      const lower = Math.ceil(freq.min_khz / 100000) * 100;
      const upper = Math.floor(freq.max_khz / 100000) * 100;
      const selectable = freq.supported && lower <= upper;
      el('cap').disabled = busy || !selectable;
      el('cap-ticks').replaceChildren();
      el('cap-low').textContent = selectable ? mhz(lower * 1000) : '—';
      el('cap-high').textContent = selectable ? mhz(upper * 1000) : '—';
      el('cap').title = '';
      if (selectable) {
        el('cap').min = String(lower);
        el('cap').max = String(upper);
        el('cap').value = String(Math.max(lower, Math.min(upper, Math.round((freq.cap_khz ?? freq.max_khz) / 100000) * 100)));
        for (let tick = lower; tick <= upper; tick += 100) {
          el('cap-ticks').append(new Option('', String(tick)));
        }
        el('cap-label').textContent = freq.cap_khz === null ? 'Mixed' : mhz(freq.cap_khz);
      } else {
        el('cap-label').textContent = freq.cap_khz === null ? 'Unavailable' : mhz(freq.cap_khz);
        el('cap').title = freq.supported
          ? 'No 100 MHz step fits within the current hardware limits.'
          : 'No writable frequency ceiling with a shared range is exposed by this machine.';
      }
      const unavailable = [];
      if (!next.governor_supported) unavailable.push('Governor selection is not available across all CPU policies.');
      if (!next.turbo.supported) unavailable.push('A writable Turbo Boost control is not exposed by this machine or firmware.');
      el('unsupported').hidden = !unavailable.length;
      el('unsupported').textContent = unavailable.join(' ');
      el('conflict').hidden = !next.tips_conflict;
      el('refresh').disabled = busy;
    }
    async function request(body, suffix = '') {
      const response = await fetch(endpoint + suffix, {
        method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
        headers: body ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {},
        body: body ? body.toString() : undefined,
      });
      const text = await response.text();
      let result;
      try { result = JSON.parse(text); } catch (_) { throw new Error('Could not read CPU settings. Sign in again or refresh the dashboard.'); }
      if (!response.ok || result.error) throw new Error(result.error || `Request failed (${response.status}).`);
      return result;
    }
    async function refreshFrequencies() {
      if (frequencyBusy || document.hidden || !root.isConnected
        || !frequencyLabels.some((label) => label.getClientRects().length)) return;
      frequencyBusy = true;
      try {
        const result = await request(null, '?frequencies=1');
        frequencyLabels.forEach((label) => {
          const khz = result.frequencies_khz?.[label.dataset.cpu];
          label.textContent = Number.isFinite(khz) && khz > 0 ? `${(khz / 1000000).toFixed(2)} GHz` : '— GHz';
        });
      } catch (_) {
        frequencyLabels.forEach((label) => { label.textContent = '— GHz'; });
      } finally {
        frequencyBusy = false;
      }
    }
    async function refresh(quiet = false) {
      if (busy || sliding || document.hidden) return;
      const startedAt = revision;
      try {
        const next = await request();
        // Do not overwrite edits made while the status request was in flight.
        if (busy || sliding || startedAt !== revision) return;
        render(next);
        if (!quiet) message('');
      } catch (error) { message(error.message); }
    }
    el('governor').addEventListener('change', () => apply('governor'));
    el('turbo').addEventListener('change', () => apply('turbo'));
    el('cap').addEventListener('pointerdown', () => { sliding = true; revision++; });
    ['pointerup', 'pointercancel', 'blur'].forEach((event) => {
      el('cap').addEventListener(event, () => { sliding = false; });
    });
    el('cap').addEventListener('input', () => {
      el('cap-label').textContent = mhz(Math.round(Number(el('cap').value) * 1000));
      sliding = true;
      revision++;
    });
    el('cap').addEventListener('change', () => {
      sliding = false;
      apply('max_freq_khz');
    });
    el('refresh').addEventListener('click', () => {
      sliding = false;
      revision++;
      refresh();
      refreshFrequencies();
    });
    async function apply(field) {
      if (busy || !state) return;
      const values = {
        governor: el('governor').value,
        turbo: el('turbo').checked ? '1' : '0',
        max_freq_khz: String(Math.round(Number(el('cap').value) * 1000)),
      };
      const current = {
        governor: state.governor,
        turbo: state.turbo.enabled === null ? null : (state.turbo.enabled ? '1' : '0'),
        max_freq_khz: state.frequency.cap_khz === null ? null : String(state.frequency.cap_khz),
      };
      if (values[field] === current[field]) return;
      if (typeof window.csrf_token !== 'string' || !window.csrf_token) {
        render(state);
        message('Your Unraid session has expired. Refresh the dashboard.'); return;
      }
      const body = new URLSearchParams({ csrf_token: window.csrf_token });
      body.set(field, values[field]);
      busy = true;
      sliding = false;
      revision++;
      root.querySelectorAll('button, select, input').forEach((control) => { control.disabled = true; });
      message('');
      el('saving').hidden = false;
      try {
        const next = await request(body);
        busy = false;
        render(next);
        message('');
        refreshFrequencies();
      } catch (error) {
        busy = false;
        try { render(await request()); } catch (_) { if (state) render(state); }
        message(error.message);
      } finally {
        el('saving').hidden = true;
      }
    }
    refresh();
    refreshFrequencies();
    const frequencyTimer = frequencyLabels.length ? setInterval(() => {
      if (!root.isConnected) { clearInterval(frequencyTimer); return; }
      refreshFrequencies();
    }, 3000) : null;
    // Poll live values so changes made by Unraid or another plugin are visible.
    const timer = setInterval(() => {
      if (!root.isConnected) { clearInterval(timer); return; }
      refresh(true);
    }, 10000);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
