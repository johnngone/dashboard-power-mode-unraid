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

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    page = browser.new_page(viewport={'width': 640, 'height': 850})
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))

    def api(route):
        global reject
        if route.request.method == 'POST':
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

    page.route('http://power-mode.test/', lambda route: route.fulfill(content_type='text/html', body=f'<!doctype html><meta charset="utf-8"><table>{tile}</table>'))
    page.route('**/plugins/dashboard-power-mode/api.php', api)
    page.goto('http://power-mode.test/')
    page.evaluate("window.csrf_token = 'synthetic-test-token'")
    page.add_style_tag(path=str(ROOT / 'src/styles/power-mode.css'))
    page.add_style_tag(content='body { margin: 12px; background:#f6f7f8; color:#25282b; font:14px Arial,sans-serif; } table { width:100%; } button,select { padding:8px; border:1px solid #aaa; border-radius:3px; background:transparent; color:inherit; } h3 { margin:6px 0; }')
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
    # Capture a privacy-safe preview before changing the synthetic state.
    if args.screenshot:
        (ROOT / 'docs').mkdir(exist_ok=True)
        card.screenshot(path=str(ROOT / 'docs/dashboard-power-mode.png'))

    card.locator('[data-dpm="governor"]').select_option('ondemand')
    page.wait_for_timeout(30)
    assert not posts, 'Selecting the current value must not write to flash'
    card.locator('[data-dpm="governor"]').select_option('performance')
    expect(message).to_contain_text('Applied and saved')
    assert posts[-1] == {'governor': 'performance'}
    expect(card.locator('[data-dpm="cap"]')).to_have_attribute('min', '1300')
    expect(card.locator('[data-dpm="cap"]')).to_have_attribute('max', '2800')
    card.locator('[data-dpm="governor"]').select_option('powersave')
    expect(message).to_contain_text('Applied and saved')
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
    expect(card.locator('[data-dpm="cap-info"]')).to_contain_text('No 100 MHz step')
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
