/* SPDX-License-Identifier: GPL-2.0-only */
(() => {
  'use strict';
  const endpoint = '/plugins/dashboard-power-mode/api.php';
  function init() {
    const root = document.getElementById('dashboard-power-mode');
    if (!root || root.dataset.initialized) return;
    root.dataset.initialized = '1';
    const el = (name) => root.querySelector(`[data-dpm="${name}"]`);
    let state;
    let busy = false;
    let sliding = false;
    let revision = 0;
    const mhz = (khz) => `${(khz / 1000).toLocaleString(undefined, { maximumFractionDigits: 3 })} MHz`;
    const message = (text) => { el('message').textContent = text; el('message').hidden = !text; };
    function render(next) {
      state = next;
      el('hardware').textContent = next.policies.length
        ? `${next.drivers.join(', ')} · ${next.policies.length} CPUs` : 'CPU frequency controls unavailable';
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
    async function request(body) {
      const response = await fetch(endpoint, {
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
      } catch (error) {
        busy = false;
        try { render(await request()); } catch (_) { if (state) render(state); }
        message(error.message);
      } finally {
        el('saving').hidden = true;
      }
    }
    refresh();
    // Poll live values so changes made by Unraid or another plugin are visible.
    const timer = setInterval(() => {
      if (!root.isConnected) { clearInterval(timer); return; }
      refresh(true);
    }, 10000);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
