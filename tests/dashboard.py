"""Browser checks with synthetic CPU data; no connection to a real server."""
import argparse
import re
from pathlib import Path
from urllib.parse import parse_qs
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--screenshot', action='store_true', help='Regenerate the synthetic README preview')
args = parser.parse_args()
page_source = (ROOT / 'src/DashboardPowerMode.page').read_text(encoding='utf-8')
tile = re.search(r"<<<'DPM_TILE'\n(.*?)\nDPM_TILE;", page_source, re.S).group(1)
tile = re.sub(r'_\(([^)]*)\)_', r'\1', tile)
state = {
    'policies': [{'id': 'policy0'}, {'id': 'policy1'}], 'drivers': ['acpi-cpufreq'],
    'governors': ['ondemand', 'performance', 'powersave'], 'governor_supported': True,
    'governor': 'ondemand', 'presets': {'efficiency': 'powersave', 'balanced': 'ondemand', 'performance': 'performance'},
    'turbo': {'supported': True, 'enabled': True},
    'frequency': {'supported': True, 'min_khz': 800000, 'max_khz': 4000000, 'cap_khz': 2400000, 'effective_caps_khz': [2400000]},
    'tips_conflict': True, 'saved': {},
}
posts = []
reject = False
hold = False
pending = []
samples = {'0': 1200000, '1': 2400000}
frequency_requests = 0
processor_fixture = '''<tbody id="synthetic-processor"><tr><td><h3>Processor</h3></td></tr>
<tr id="cpu_main"><td>Overall Load: 12%</td></tr>
<tr class="cpu_open"><td><span class="w26">CPU 0 - HT 1</span>
<span class="dashboard w36"><span class="cpu0 load resize">10%</span><div class="usage-disk sys"><span></span><span></span></div></span>
<span class="dashboard w36"><span class="cpu1 load resize">14%</span><div class="usage-disk sys"><span></span><span></span></div></span>
</td></tr></tbody>'''

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    page = browser.new_page(viewport={'width': 640, 'height': 850}, locale='en-US')
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))

    def api(route):
        global reject, frequency_requests
        if '?frequencies=1' in route.request.url:
            frequency_requests += 1
            route.fulfill(json={'frequencies_khz': samples})
            return
        if route.request.method == 'POST':
            if hold:
                pending.append(route)
                return
            fields = {key: values[0] for key, values in parse_qs(route.request.post_data).items()}
            posts.append(fields)
            assert fields.pop('csrf_token') == 'synthetic-test-token'
            if reject:
                route.fulfill(status=409, json={'error': 'Synthetic write failure; previous settings restored.'})
                return
            assert len(fields) == 1, 'Only the changed control should be submitted'
            if 'governor' in fields:
                state['governor'] = fields['governor']
                state['frequency']['min_khz'] = 1250000 if fields['governor'] == 'performance' else 800000
                state['frequency']['max_khz'] = 2840000 if fields['governor'] == 'performance' else (4000000 if state['turbo']['enabled'] else 2500000)
            if 'turbo' in fields:
                state['turbo']['enabled'] = fields['turbo'] == '1'
                state['frequency']['max_khz'] = 4000000 if state['turbo']['enabled'] else 2500000
            if 'max_freq_khz' in fields:
                state['frequency']['cap_khz'] = int(fields['max_freq_khz'])
                state['frequency']['effective_caps_khz'] = [int(fields['max_freq_khz'])]
        route.fulfill(json=state)

    page.route('http://power-mode.test/', lambda route: route.fulfill(content_type='text/html', body=f'<!doctype html><meta charset="utf-8"><table>{processor_fixture}{tile}</table>'))
    page.route('**/plugins/dashboard-power-mode/api.php*', api)
    page.goto('http://power-mode.test/')
    page.evaluate("window.csrf_token = 'synthetic-test-token'")
    page.add_style_tag(path=str(ROOT / 'src/styles/power-mode.css'))
    page.add_style_tag(content='body { margin: 12px; background:#f6f7f8; color:#25282b; font:14px Arial,sans-serif; } table { width:100%; } select { width:100%; min-width:300px; } button,select { padding:8px; border:1px solid #aaa; border-radius:3px; background:transparent; color:inherit; } h3 { margin:6px 0; } .usage-disk { height:7px; background:#bbb; }')
    page.add_script_tag(path=str(ROOT / 'src/javascript/power-mode.js'))
    card = page.locator('#dashboard-power-mode')
    message = card.locator('[data-dpm="message"]')
    expect(card.locator('[data-dpm="governor"]')).to_have_value('ondemand')
    assert card.locator('[data-dpm="apply"]').count() == 0
    assert card.locator('[data-preset]').count() == 0
    expect(card.locator('[data-dpm="turbo"]')).to_be_checked()
    expect(card.locator('[data-dpm="cap"]')).to_have_attribute('step', '100')
    assert card.locator('[data-dpm="cap-ticks"] option').count() == 33
    assert not posts
    expect(message).to_be_hidden()
    notice = card.locator('[data-dpm="conflict"]')
    expect(notice).to_have_text('(Tips & Tweaks plugin may override)')
    expect(notice).to_be_visible()
    assert card.locator('.dpm-heading [data-dpm="conflict"]').count() == 1
    assert card.locator('.tile-header-right-controls [data-dpm="refresh"]').count() == 1
    assert card.locator('.dpm-body [data-dpm="refresh"], .dpm-warning').count() == 0
    expect(card.locator('[data-dpm="hardware"]')).to_have_text('acpi-cpufreq adjustment')
    assert card.evaluate('el => el.closest("tbody").id') == 'synthetic-processor'
    assert page.locator('tbody#dashboard-power-mode').count() == 0
    expect(page.locator('.dpm-cpu-ghz[data-cpu="0"]')).to_have_text('1.20 GHz')
    expect(page.locator('.dpm-cpu-ghz[data-cpu="1"]')).to_have_text('2.40 GHz')
    expect(card.locator('[data-dpm="cap-low"]')).to_have_text('800 MHz')
    expect(card.locator('[data-dpm="cap-high"]')).to_have_text('4,000 MHz')
    assert card.locator('[data-dpm="cap-info"]').count() == 0
    for width in (375, 640):
        page.set_viewport_size({'width': width, 'height': 850})
        box = card.locator('[data-dpm="governor"]').evaluate('el => { const s=el.getBoundingClientRect(), r=el.parentElement.getBoundingClientRect(); return { width:s.width, rowWidth:r.width, right:s.right, rowRight:r.right }; }')
        assert abs(box['width'] - box['rowWidth'] / 2) <= 1
        assert abs(box['right'] - box['rowRight']) <= 1
    # Capture a privacy-safe preview before changing the synthetic state.
    if args.screenshot:
        (ROOT / 'docs').mkdir(exist_ok=True)
        page.locator('#synthetic-processor').screenshot(path=str(ROOT / 'docs/dashboard-power-mode.png'))

    samples['0'] = 1800000
    card.locator('[data-dpm="refresh"]').click()
    expect(page.locator('.dpm-cpu-ghz[data-cpu="0"]')).to_have_text('1.80 GHz')
    page.locator('.cpu_open').evaluate('el => el.style.display = "none"')
    count = frequency_requests
    card.locator('[data-dpm="refresh"]').click()
    page.wait_for_timeout(100)
    assert frequency_requests == count, 'Hidden CPU details must not fetch frequency samples'
    page.locator('.cpu_open').evaluate('el => el.style.display = ""')

    state['tips_conflict'] = False
    card.locator('[data-dpm="refresh"]').click()
    expect(notice).to_be_hidden()
    state['tips_conflict'] = True
    card.locator('[data-dpm="refresh"]').click()
    expect(notice).to_be_visible()

    card.locator('[data-dpm="governor"]').select_option('ondemand')
    page.wait_for_timeout(30)
    assert not posts, 'Selecting the current value must not write to flash'
    hold = True
    before = card.bounding_box()
    card.locator('[data-dpm="governor"]').select_option('performance')
    saving = card.locator('[data-dpm="saving"]')
    expect(saving).to_be_visible()
    during = card.bounding_box()
    assert abs(before['height'] - during['height']) < 1, 'Saving must not resize the tile'
    save_box = saving.bounding_box()
    refresh_box = card.locator('[data-dpm="refresh"]').bounding_box()
    assert save_box['x'] + save_box['width'] <= refresh_box['x']
    expect(message).to_be_hidden()
    assert len(pending) == 1
    hold = False
    api(pending.pop())
    expect(card.locator('[data-dpm="governor"]')).to_be_enabled()
    expect(saving).to_be_hidden()
    assert abs(before['height'] - card.bounding_box()['height']) < 1
    expect(message).to_be_hidden()
    assert posts[-1] == {'governor': 'performance'}
    expect(card.locator('[data-dpm="cap"]')).to_have_attribute('min', '1300')
    expect(card.locator('[data-dpm="cap"]')).to_have_attribute('max', '2800')
    expect(card.locator('[data-dpm="cap-low"]')).to_have_text('1,300 MHz')
    expect(card.locator('[data-dpm="cap-high"]')).to_have_text('2,800 MHz')
    card.locator('[data-dpm="governor"]').select_option('powersave')
    expect(card.locator('[data-dpm="governor"]')).to_be_enabled()
    assert posts[-1] == {'governor': 'powersave'}
    card.locator('[data-dpm="turbo"]').uncheck()
    expect(card.locator('[data-dpm="turbo"]')).to_be_enabled()
    assert posts[-1] == {'turbo': '0'}
    expect(card.locator('[data-dpm="cap"]')).to_have_attribute('max', '2500')
    assert card.locator('[data-dpm="cap-ticks"] option').count() == 18
    count = len(posts)
    card.locator('[data-dpm="cap"]').evaluate("el => { for (let n=1500;n<=2000;n+=10) { el.value=String(n); el.dispatchEvent(new Event('input',{bubbles:true})); } }")
    page.wait_for_timeout(100)
    assert len(posts) == count, 'Dragging must not send save requests'
    card.locator('[data-dpm="cap"]').dispatch_event('change')
    expect(card.locator('[data-dpm="cap"]')).to_be_enabled()
    assert len(posts) == count + 1 and posts[-1] == {'max_freq_khz': '2000000'}
    # Releasing an unchanged slider must neither save nor leave polling suspended.
    card.locator('[data-dpm="cap"]').dispatch_event('pointerdown')
    card.locator('[data-dpm="cap"]').dispatch_event('pointerup')
    assert len(posts) == count + 1
    reject = True
    card.locator('[data-dpm="governor"]').select_option('performance')
    expect(message).to_contain_text('Synthetic write failure')
    expect(card.locator('[data-dpm="governor"]')).to_have_value('powersave')
    expect(card.locator('[data-dpm="governor"]')).to_be_enabled()
    reject = False
    # Checkbox can represent a mixed initial state without inventing a third setting.
    state['turbo']['enabled'] = None
    card.locator('[data-dpm="refresh"]').click()
    expect(card.locator('[data-dpm="turbo-status"]')).to_have_text('Mixed')
    assert card.locator('[data-dpm="turbo"]').evaluate('el => el.indeterminate')
    card.locator('[data-dpm="turbo"]').check()
    expect(card.locator('[data-dpm="turbo-status"]')).to_have_text('On')
    expect(card.locator('[data-dpm="cap"]')).to_have_attribute('max', '4000')
    assert posts[-1] == {'turbo': '1'}
    # A safe 100 MHz grid must not cross unusual hardware bounds.
    state['frequency'].update(min_khz=1250000, max_khz=1290000, cap_khz=1270000)
    card.locator('[data-dpm="refresh"]').click()
    expect(card.locator('[data-dpm="cap"]')).to_be_disabled()
    expect(card.locator('[data-dpm="cap"]')).to_have_attribute('title', re.compile('No 100 MHz step'))
    state['frequency'].update(min_khz=800000, max_khz=4000000, cap_khz=2000000)
    state['turbo']['supported'] = False
    card.locator('[data-dpm="refresh"]').click()
    expect(card.locator('[data-dpm="turbo"]')).to_be_disabled()
    state['turbo']['supported'] = True
    card.locator('[data-dpm="refresh"]').click()
    expect(card.locator('[data-dpm="turbo"]')).to_be_enabled()
    page.evaluate("window.csrf_token = ''")
    count = len(posts)
    card.locator('[data-dpm="turbo"]').click()
    expect(message).to_contain_text('session has expired')
    expect(card.locator('[data-dpm="turbo"]')).to_be_checked()
    assert len(posts) == count
    assert not errors, errors
    browser.close()
print('PASS: automatic saves, Turbo Boost checkbox, 100 MHz notches, dynamic bounds, absent presets, mixed/unsupported states, slider batching, error recovery, expired session')
