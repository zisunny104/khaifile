"""Real Office conversion and browser download tests; no external network needed."""
import io
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import http.cookiejar
import uuid
import zipfile

from docx import Document
from openpyxl import Workbook
from pptx import Presentation
from pptx.util import Inches
from pypdf import PdfReader
from PIL import Image
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
CHECKS = 0


def check(condition, label):
    global CHECKS
    if not condition:
        raise AssertionError(label)
    CHECKS += 1
    print('PASS', label, flush=True)


class Client:
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        status, _, html = self.get('/')
        check(status == 200, 'Application renders')
        self.settings = json.loads(re.search(rb'<script id="app-data" type="application/json">(.*?)</script>', html, re.S)[1])

    def request(self, request):
        try:
            with self.opener.open(request, timeout=280) as response:
                return response.status, response.headers, response.read()
        except urllib.error.HTTPError as error:
            return error.code, error.headers, error.read()

    def get(self, path):
        return self.request(self.base + path)

    def post(self, action, data=None, file=None, csrf=True):
        data = dict(data or {})
        if csrf:
            data['csrf'] = self.settings['csrf']
        boundary = 'khaifile-' + uuid.uuid4().hex
        parts = []
        for key, values in data.items():
            if not isinstance(values, list):
                values = [values]
            else:
                key += '[]'
            for value in values:
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        if file:
            name, content = file
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + content + b'\r\n')
        parts.append(f'--{boundary}--\r\n'.encode())
        request = urllib.request.Request(self.base + '/?api=' + action, data=b''.join(parts), headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
        status, headers, body = self.request(request)
        return status, json.loads(body)

    def process(self, path, name=None, compress=True):
        status, result = self.post('process', {'name': name or path.stem, 'compress': '1' if compress else '0'}, (path.name, path.read_bytes()))
        check(status == 200, f'{path.suffix} converts: {result.get("error", "ok")}')
        return result['job']

    def download(self, job, role):
        return self.get('/?api=download&' + urllib.parse.urlencode({'id': job['id'], 'file': role}))


def fixtures(directory):
    document = Document()
    document.add_heading('KhaiFile Document Test', 0)
    document.add_paragraph('Hello open formats. 中文文件測試。')
    document.save(directory / '報告.docx')
    workbook = Workbook()
    sheet = workbook.active
    sheet.title = 'Results'
    sheet.append(['Item', 'Value'])
    sheet.append(['KhaiFile', 42])
    sheet.print_area = 'A1:B2'
    workbook.save(directory / '成績.xlsx')
    presentation = Presentation()
    slide = presentation.slides.add_slide(presentation.slide_layouts[1])
    slide.shapes.title.text = 'KhaiFile Slide Test'
    slide.placeholders[1].text = 'Open presentation formats'
    presentation.save(directory / '簡報.pptx')
    # A large uncompressed PDF image gives compression a meaningful target.
    image = Image.new('RGB', (1800, 1800))
    pixels = image.load()
    for y in range(1800):
        for x in range(1800):
            pixels[x, y] = (x % 256, y % 256, (x + y) % 256)
    image.save(directory / '掃描.pdf', 'PDF', resolution=300)
    (directory / '損壞.docx').write_bytes(b'not an office file')


def test_api(client, directory):
    jobs = []
    for filename, expected in [('報告.docx', 'odt'), ('成績.xlsx', 'ods'), ('簡報.pptx', 'odp')]:
        path = directory / filename
        job = client.process(path)
        jobs.append(job)
        check([entry['id'] for entry in job['outputs']] == ['original', 'odf', 'pdf'], 'Original, ODF and PDF are returned')
        status, _, original = client.download(job, 'original')
        check(status == 200 and original == path.read_bytes(), 'Original bytes are unchanged')
        status, _, odf = client.download(job, 'odf')
        with zipfile.ZipFile(io.BytesIO(odf)) as archive:
            check('content.xml' in archive.namelist(), f'Valid {expected.upper()} content')
        status, _, pdf = client.download(job, 'pdf')
        reader = PdfReader(io.BytesIO(pdf))
        check(status == 200 and len(reader.pages) >= 1, 'PDF contains rendered pages')
        check('KhaiFile' in ''.join(page.extract_text() or '' for page in reader.pages), 'PDF contains source content')
        if expected == 'odt':
            odf_path = directory / '開放文件.odt'
            odf_path.write_bytes(odf)
    odf_job = client.process(directory / '開放文件.odt', compress=False)
    check([entry['id'] for entry in odf_job['outputs']] == ['original', 'pdf'], 'Existing ODF is not duplicated')
    pdf_job = client.process(directory / '掃描.pdf')
    check(any('PDF 暫不支援' in note for note in pdf_job['notes']), 'PDF-to-editable limitation shown')
    check(any('壓縮' in note or '原 PDF' in note for note in pdf_job['notes']), 'Compression outcome reported')
    if len(pdf_job['outputs']) > 1:
        check(pdf_job['outputs'][1]['size'] < pdf_job['outputs'][0]['size'], 'Compressed PDF is smaller')
        _, _, compressed = client.download(pdf_job, 'pdf')
        check(len(PdfReader(io.BytesIO(compressed)).pages) == 1, 'Compression preserves PDF pages')
    jobs.append(pdf_job)
    status, result = client.post('rename', {'id': jobs[0]['id'], 'name': '網站公告 2026'})
    check(status == 200 and all(entry['name'].startswith('網站公告 2026.') for entry in result['job']['outputs']), 'Rename applies to all formats')
    jobs[0] = result['job']
    _, headers, _ = client.download(jobs[0], 'odf')
    check(urllib.parse.quote('網站公告 2026.odt') in headers['Content-Disposition'], 'Unicode download filename preserved')
    status, bundle = client.post('archive', {'ids': [jobs[0]['id']]})
    status, _, body = client.get('/?api=download&bundle=' + bundle['bundle'])
    with zipfile.ZipFile(io.BytesIO(body)) as archive:
        check(set(archive.namelist()) == {'網站公告 2026.docx', '網站公告 2026.odt', '網站公告 2026.pdf'}, 'Single group ZIP has all renamed outputs')
    client.post('rename', {'id': jobs[1]['id'], 'name': '網站公告 2026'})
    status, bundle = client.post('archive', {'ids': [job['id'] for job in jobs]})
    status, _, body = client.get('/?api=download&bundle=' + bundle['bundle'])
    with zipfile.ZipFile(io.BytesIO(body)) as archive:
        check(any(name.startswith('網站公告 2026 (2)/') for name in archive.namelist()), 'Batch ZIP disambiguates repeated group names')
        check(len(archive.namelist()) == sum(len(job['outputs']) for job in jobs), 'Batch ZIP includes all selected outputs')
    other = Client(client.base)
    status, _, _ = other.download(jobs[0], 'original')
    check(status == 410, 'Another browser session cannot download files')
    status, _ = client.post('rename', {'id': jobs[0]['id'], 'name': 'no'}, csrf=False)
    check(status == 403, 'Mutation requires CSRF token')
    status, _ = client.post('process', {'name': 'bad'}, ('損壞.docx', b'not zip'))
    check(status == 422, 'Corrupt document is rejected')
    status, _ = client.post('process', {'name': 'bad'}, ('script.php', b'<?php'))
    check(status == 415, 'Unsupported format is rejected')
    status, _ = client.post('rename', {'id': jobs[0]['id'], 'name': '../../公告'})
    check(status == 200, 'Unsafe name is sanitized')
    for path in ['/.git/config', '/api/lib.php', '/config.php', '/tools/router.php']:
        status, _, _ = client.get(path)
        check(status == 403, f'Private path blocked: {path}')
    status, _ = client.post('delete', {'id': jobs[0]['id']})
    status, _, _ = client.download(jobs[0], 'original')
    check(status == 410, 'Deleted files cannot be downloaded')
    status, _ = client.post('clear')
    status, _, _ = client.download(jobs[1], 'original')
    check(status == 410, 'Clear removes retained results')


def test_browser(base, directory):
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=shutil.which('chromium'), headless=True, args=['--no-sandbox'])
        context = browser.new_context(accept_downloads=True)
        page = context.new_page()
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.goto(base, wait_until='networkidle')
        check(page.locator('#compress').is_checked(), 'PDF compression defaults on')
        check(page.evaluate('getComputedStyle(document.querySelector(".ts-button")).borderRadius') != '0px', 'Local Tocas UI styles loaded')
        page.set_input_files('#file-input', [str(directory / '報告.docx'), str(directory / '損壞.docx'), str(directory / '成績.xlsx')])
        page.locator('#queue article').nth(0).locator('input[type=text]').fill('瀏覽器公告')
        page.locator('#start').click()
        page.wait_for_function('document.querySelector("#summary").textContent.includes("2 組完成") && document.querySelector("#summary").textContent.includes("1 組需重試") && !document.querySelector("#summary").textContent.includes("正在")', timeout=280000)
        check(page.locator('#queue article').count() == 3, 'Mixed queue continues after a corrupt file')
        first = page.locator('#queue article').nth(0)
        check(first.locator('.output').count() == 3, 'Three output formats are shown side by side')
        first.locator('input[type=text]').fill('更名公告')
        first.locator('input[type=text]').press('Tab')
        first.locator('.output .file-name').filter(has_text='更名公告.odt').wait_for()
        with page.expect_download() as download:
            first.get_by_role('button', name='下載 ODT', exact=True).click()
        check(download.value.suggested_filename == '更名公告.odt', 'Renamed individual download filename')
        with page.expect_download() as download:
            first.get_by_role('button', name='下載這組 ZIP', exact=True).click()
        path = directory / 'browser-group.zip'
        download.value.save_as(path)
        with zipfile.ZipFile(path) as archive:
            check('更名公告.odt' in archive.namelist(), 'Browser group ZIP contains renamed file')
        with page.expect_download() as download:
            page.locator('#download-all').click()
        check(download.value.suggested_filename == 'KhaiFile.zip', 'Batch ZIP download works')
        downloads = []
        page.on('download', lambda download: downloads.append(download.suggested_filename))
        page.locator('#download-groups').click()
        page.wait_for_function('document.querySelector("#download-status").textContent.includes("已依序送出 2 個")', timeout=30000)
        check(len(downloads) == 2, 'Batch per-group downloads send one ZIP per source')
        downloads.clear()
        page.locator('#download-files').click()
        page.wait_for_function('document.querySelector("#download-status").textContent.includes("已依序送出 6 個")', timeout=30000)
        check(len(downloads) == 6, 'Batch individual downloads send every output in order')
        page.locator('label.item').filter(has=page.locator('input[name=theme][value=dark]')).click()
        check('is-dark' in page.locator('body').get_attribute('class'), 'Dark theme works')
        page.set_viewport_size({'width': 390, 'height': 844})
        check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Mobile layout has no horizontal overflow')
        page.screenshot(path=str(directory / 'mobile.png'), full_page=True)
        page.set_viewport_size({'width': 1360, 'height': 1000})
        page.screenshot(path=str(directory / 'desktop.png'), full_page=True)
        page.locator('#clear').click()
        page.wait_for_function('document.querySelector("#count").textContent === "0"')
        check(not errors, f'No browser JavaScript errors: {errors}')
        browser.close()


def main():
    with tempfile.TemporaryDirectory(prefix='khaifile-test-') as temp:
        directory = Path(temp)
        fixtures(directory)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        env = os.environ.copy()
        env['KHAIFILE_TEMP_DIR'] = str(directory / 'storage')
        log = open(directory / 'server.log', 'w')
        process = subprocess.Popen([shutil.which('php'), '-d', 'upload_max_filesize=50M', '-d', 'post_max_size=52M', '-d', 'max_execution_time=300', '-S', f'127.0.0.1:{port}', '-t', str(ROOT), str(ROOT / 'tools/router.php')], env=env, stdout=log, stderr=log)
        base = f'http://127.0.0.1:{port}'
        try:
            for _ in range(100):
                try:
                    urllib.request.urlopen(base, timeout=1).close()
                    break
                except urllib.error.URLError:
                    time.sleep(.05)
            client = Client(base)
            test_api(client, directory)
            test_browser(base, directory)
            artifacts = ROOT / 'tests/artifacts'
            artifacts.mkdir(exist_ok=True)
            for name in ['mobile.png', 'desktop.png']:
                shutil.copyfile(directory / name, artifacts / name)
            print(f'PASS {CHECKS} integration checks', flush=True)
        except Exception:
            log.flush()
            print((directory / 'server.log').read_text()[-5000:], flush=True)
            raise
        finally:
            process.terminate()
            process.wait(timeout=10)
            log.close()


if __name__ == '__main__':
    main()
