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
        for path in ['/channels', '/signals', '/trades', '/agents', '/backtests', '/risk', '/lab', '/integrations', '/audit']:
            response = page.goto(BASE + path)
            assert response.status == 200, (path, response.status)
            assert page.locator('h1').count() == 1, path
        page.set_viewport_size({'width': 390, 'height': 844})
        page.goto(BASE + '/')
        page.screenshot(path=str(OUTPUT / 'mobile.png'), full_page=True)
        assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'), 'Mobile page overflows'
        assert not errors, errors
        browser.close()
    print('Browser smoke passed: login, 10 authenticated pages, desktop and mobile. Screenshots: storage/app/private/qa/')


if __name__ == '__main__':
    main()
