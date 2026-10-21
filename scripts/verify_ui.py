"""Local browser smoke check. Uses initial credentials without printing them."""
import re
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
BASE = 'http://127.0.0.1:8020'
OUTPUT = ROOT / 'storage/app/private/qa'


def main():
    credentials = (ROOT / 'storage/app/private/initial-admin.txt').read_text()
    email = re.search(r'^Email: (.+)$', credentials, re.M)[1]
    password = re.search(r'^Password: (.+)$', credentials, re.M)[1]
    OUTPUT.mkdir(parents=True, exist_ok=True)
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless=True)
        page = browser.new_page(viewport={'width': 1440, 'height': 1050}, device_scale_factor=1)
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        response = page.goto(BASE + '/login')
        assert response.status == 200
        page.screenshot(path=str(OUTPUT / 'login.png'), full_page=True)
        page.locator('[name=email]').fill(email)
        page.locator('[name=password]').fill(password)
        page.get_by_role('button', name='Kirish').click()
        page.wait_for_url(BASE + '/')
        assert page.get_by_role('heading', name='Umumiy ko‘rinish').count() == 1
        page.screenshot(path=str(OUTPUT / 'dashboard.png'), full_page=True)
        for path in ['/channels', '/signals', '/trades', '/agents', '/backtests', '/risk', '/lab', '/integrations', '/audit', '/issues']:
            response = page.goto(BASE + path)
            assert response.status == 200, (path, response.status)
            assert page.locator('h1').count() == 1, path
        page.goto(BASE + '/channels')
        if page.locator('a.channel-name').count():
            response = page.goto(page.locator('a.channel-name').first.get_attribute('href'))
            assert response.status == 200
            assert page.locator('form[action$="/research"]').count() == 1
            assert page.locator('form[action$="/learn"]').count() == 1
            assert page.get_by_role('heading', name='Kanalning ML modeli').count() == 1
            assert page.get_by_role('heading', name='Kanalga individual yondashuv').count() == 1
            assert page.get_by_role('heading', name='Kanal uchun risk boshqaruvi').count() == 1
            assert page.locator('form[action$="/risk"]').count() == 1
            assert page.get_by_text('Ochilmaydi · yuklab olinmaydi', exact=True).count() == 1
            page.screenshot(path=str(OUTPUT / 'channel-policy.png'), full_page=True)
            page.set_viewport_size({'width': 390, 'height': 844})
            assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'), 'Channel mobile page overflows'
        page.set_viewport_size({'width': 390, 'height': 844})
        page.goto(BASE + '/issues')
        assert page.get_by_role('heading', name='Monitoring jurnali').count() == 1
        assert page.locator('form[action$="/issues"]').count() == 1
        assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'), 'Journal mobile page overflows'
        page.screenshot(path=str(OUTPUT / 'journal-mobile.png'), full_page=True)
        page.goto(BASE + '/')
        page.screenshot(path=str(OUTPUT / 'mobile.png'), full_page=True)
        assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'), 'Mobile page overflows'
        assert not errors, errors
        browser.close()
    print('Browser smoke passed: login, authenticated pages including journal, desktop and mobile. Screenshots: storage/app/private/qa/')


if __name__ == '__main__':
    main()
