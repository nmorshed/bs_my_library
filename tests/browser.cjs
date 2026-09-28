/* Run with: NODE_PATH=/private/tmp/bsml-browser/node_modules node tests/browser.cjs */
const {chromium} = require('playwright');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const wp = path.resolve(root, '../../../wp-includes/js');
const calls = [];
let claims = 0, saved = true;
const items = Array.from({length: 25}, (_, i) => ({id: i + 1, title: 'Clearing ' + String(i + 1).padStart(2, '0'), date: '2026-09-01', event: '', image: '', url: '#product', clearing: i + 1, price: 20 + i, priceHtml: '$' + (20 + i), wishlisted: saved}));
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname.startsWith('/assets/')) { res.setHeader('Content-Type', url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript'); res.end(fs.readFileSync(path.join(root, url.pathname))); return; }
    if (url.pathname.startsWith('/vendor/')) { res.setHeader('Content-Type', 'text/javascript'); res.end(fs.readFileSync(path.join(wp, url.pathname.replace('/vendor/', '')))); return; }
    if (url.pathname === '/ajax') { saved = !saved; res.setHeader('Content-Type', 'application/json'); res.end('{}'); return; }
    if (url.searchParams.has('bsml_embed')) { res.end('<h1>Fixture clearing content</h1><video></video><script>parent.postMessage({type:"bsml-height",height:400},location.origin)</script>'); return; }
    if (url.pathname.startsWith('/api/')) {
        calls.push(url.href); res.setHeader('Content-Type', 'application/json'); res.setHeader('Cache-Control', 'no-store');
        if (url.pathname === '/api/claim') { claims++; setTimeout(() => res.end('{"confirmed":true}'), 150); return; }
        if (url.pathname === '/api/membership') { res.end(JSON.stringify({tier: 'Level 3', pending: false, benefits: {live: {label: 'Live GEC', remaining: 2 - claims, used: claims, limit: 2, configured: true}, replay: {label: 'Replays', remaining: 3, used: 0, limit: 3, configured: true}}, appointment: {eligible: true, booked: false}})); return; }
        if (url.pathname === '/api/history') { res.end(JSON.stringify({items: claims ? [{id: 1, title: 'Clearing 01', benefit: 'live', clearing: 1, displayDate: 'September 28, 2026', status: 'confirmed'}] : [], page: 1, pages: 1, total: claims})); return; }
        const kind = url.searchParams.get('kind');
        let found = items.map(item => ({...item, wishlisted: saved, clearing: kind === 'related' || kind === 'wishlist' ? 0 : item.clearing}));
        if (kind === 'wishlist') found = saved ? found.slice(0, 1) : [];
        const search = (url.searchParams.get('search') || '').toLowerCase();
        found = found.filter(item => item.title.toLowerCase().includes(search));
        if (url.searchParams.get('sort') === 'za') found.reverse();
        const page = Number(url.searchParams.get('page') || 1);
        res.end(JSON.stringify({items: found.slice((page - 1) * 12, page * 12), page, pages: Math.max(1, Math.ceil(found.length / 12)), total: found.length, configured: true, filters: kind === 'related' || kind === 'wishlist' ? [] : [{id: 11, label: 'Release & Renew'}]})); return;
    }
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>My Library test fixture</title><style>body{margin:20px;font-family:Arial,sans-serif}</style><link rel="stylesheet" href="/assets/library.css"><div class="bsml-root"></div>
<script src="/vendor/dist/vendor/react.js"></script><script src="/vendor/dist/vendor/react-dom.js"></script><script src="/vendor/dist/escape-html.js"></script><script src="/vendor/dist/element.js"></script>
<script>window.wp.apiFetch=async function(o){let r=await fetch(o.url,{...o,headers:{...o.headers,'Content-Type':'application/json'},body:o.data?JSON.stringify(o.data):undefined});if(!r.ok)throw await r.json();return r.json()};window.BSML={root:'/api/',nonce:'fixture',tabs:[{id:'clearings',label:'My Clearings',type:'standard',sort:'newest'},{id:'vip',label:'My VIP Membership',type:'membership',sort:'newest'},{id:'wishlist',label:'My Wishlist',type:'wishlist',sort:'newest'}],defaultTab:'clearings',embed:location.origin+'/',ajax:'/ajax',wishlistNonce:'fixture',wishlist:true,login:'/login'};</script><script src="/assets/library.js"></script>`);
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const browser = await chromium.launch({executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true});
    try {
        const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
        const errors = []; page.on('pageerror', e => errors.push(e.message));
        page.on('pageerror', e => console.error('Browser error:', e.message));
        await page.goto('http://127.0.0.1:' + server.address().port);
        const library = page.getByRole('region', {name: 'Available in your library'});
        await library.getByRole('button', {name: 'Open clearing'}).first().waitFor();
        assert.equal(await library.locator('.bsml-card').count(), 12);
        await library.getByRole('button', {name: 'Release & Renew'}).click();
        await page.waitForResponse(r => r.url().includes('term=11') && r.url().includes('kind=library'));
        await library.getByRole('searchbox').fill('Clearing 25');
        await library.getByRole('heading', {name: 'Clearing 25', exact: true}).waitFor();
        assert.equal(await library.locator('.bsml-card').count(), 1);
        await library.getByRole('searchbox').fill('');
        await library.getByRole('heading', {name: 'Clearing 01', exact: true}).waitFor();
        await library.getByRole('combobox').selectOption('za');
        await page.waitForResponse(r => r.url().includes('sort=za'));
        await library.getByRole('heading', {name: 'Clearing 25', exact: true}).waitFor();
        await library.getByRole('button', {name: 'Open clearing'}).first().click();
        await page.locator('iframe').waitFor();
        await page.getByRole('button', {name: '← Back to My Clearings'}).click();
        await library.getByRole('heading', {name: 'Clearing 25', exact: true}).waitFor();
        assert.equal(await page.locator('iframe').count(), 0);
        assert.equal(await library.getByRole('combobox').inputValue(), 'za');
        const before = calls.length;
        await page.getByRole('button', {name: 'My VIP Membership'}).click();
        const live = page.getByRole('region', {name: 'Choose your live gec'});
        await live.getByRole('button', {name: 'Select', exact: true}).first().click();
        await page.getByRole('button', {name: 'Claim selected items'}).first().click();
        await page.getByText('Your selection has been claimed.', {exact: false}).waitFor();
        assert.equal(claims, 1);
        await page.getByRole('button', {name: 'My Clearings'}).click();
        await library.getByRole('button', {name: 'Open clearing'}).first().waitFor();
        assert(calls.length > before, 'Returning requests fresh data');
        await page.screenshot({path: '/private/tmp/bsml-desktop.png', fullPage: true});
        await page.getByRole('button', {name: 'My Wishlist'}).click();
        await page.getByRole('button', {name: 'Remove Clearing 01 from wishlist'}).click();
        await page.getByText('Nothing here yet', {exact: true}).waitFor();
        await page.setViewportSize({width: 390, height: 844});
        await page.getByRole('combobox', {name: 'Library section'}).selectOption('vip');
        await page.getByRole('heading', {name: 'Level 3', exact: true}).waitFor();
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'No mobile horizontal overflow');
        await page.screenshot({path: '/private/tmp/bsml-mobile.png', fullPage: true});
        assert.deepEqual(errors, []);
        console.log('PASS browser: rendering, full-list search, sorting, filters, inline viewer cleanup, claims, fresh revisits, wishlist removal, mobile layout; no JavaScript errors');
    } finally { await browser.close(); server.close(); }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
