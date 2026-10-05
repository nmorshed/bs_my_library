/* Run with: NODE_PATH=/private/tmp/bsml-browser/node_modules node tests/browser.cjs */
const {chromium} = require('playwright');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const wp = path.resolve(root, '../../../wp-includes/js');
const calls = [];
let claims = 0, saved = true, cartAdds = 0;
const items = Array.from({length: 25}, (_, i) => ({id: i + 1, title: 'Clearing ' + String(i + 1).padStart(2, '0'), date: '2026-09-01', event: '', image: '', url: '#product', clearing: i + 1, price: 20 + i, priceHtml: '$' + (20 + i), wishlisted: saved}));
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/admin-fixture') {
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end('<link rel="stylesheet" href="/assets/admin.css">' + fs.readFileSync('/private/tmp/bsml-admin-fixture.html', 'utf8') + '<script>window.wp={editor:{initialize(){},remove(){}}};window.ajaxurl="/admin-ajax";window.BSMLAdmin={nonce:"test"};</script><script src="/assets/admin.js"></script>'); return;
    }
    if (url.pathname === '/admin-ajax') { res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({success:true,data:{terms:[{id:url.searchParams.get('taxonomy') === 'topic' ? 11 : 23,label:url.searchParams.get('taxonomy') === 'topic' ? 'Topic term' : 'Program term'}],attached:url.searchParams.get('taxonomy') === 'topic'}})); return; }
    if (url.pathname.startsWith('/assets/')) { res.setHeader('Content-Type', url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript'); res.end(fs.readFileSync(path.join(root, url.pathname))); return; }
    if (url.pathname.startsWith('/vendor/')) { res.setHeader('Content-Type', 'text/javascript'); res.end(fs.readFileSync(path.join(wp, url.pathname.replace('/vendor/', '')))); return; }
    if (url.pathname === '/shortcode-fixture.js') { res.setHeader('Content-Type', 'text/javascript'); res.end('window.shortcodeLibraryLoads=(window.shortcodeLibraryLoads||0)+1;'); return; }
    if (url.pathname === '/woo-cart.js') { res.setHeader('Content-Type', 'text/javascript'); res.end(fs.readFileSync(path.resolve(root, '../woocommerce/assets/js/frontend/add-to-cart.js'))); return; }
    if (url.pathname === '/cart/add_to_cart') { cartAdds++; res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({fragments: {}, cart_hash: 'fixture-cart'})); return; }
    if (url.pathname === '/ajax') { saved = !saved; res.setHeader('Content-Type', 'application/json'); res.end('{}'); return; }
    if (url.searchParams.has('bsml_embed')) { res.end('<h1>Fixture clearing content</h1><video></video><script>parent.postMessage({type:"bsml-height",height:400},location.origin)</script>'); return; }
    if (url.pathname.startsWith('/api/')) {
        calls.push(url.href); res.setHeader('Content-Type', 'application/json'); res.setHeader('Cache-Control', 'no-store');
        if (url.pathname === '/api/content') { res.end(JSON.stringify({html:'<p class="inline-fixture">Custom text: ' + (url.searchParams.get('child') || 'welcome') + '</p><script>document.querySelector(".inline-fixture").dataset.initialized="yes";</script>',assets:'<script src="/shortcode-fixture.js"></script>'})); return; }
        if (url.pathname === '/api/claim') { claims++; setTimeout(() => res.end('{"confirmed":true}'), 150); return; }
        if (url.pathname === '/api/membership') { res.end(JSON.stringify({tier: 'Level 3', pending: false, benefits: {live: {label: 'Live GEC', remaining: 2 - claims, used: claims, limit: 2, configured: true}, replay: {label: 'Replays', remaining: 3, used: 0, limit: 3, configured: true}}, appointment: {eligible: true, booked: false}})); return; }
        if (url.pathname === '/api/history') { res.end(JSON.stringify({items: claims ? [{id: 1, title: 'Clearing 01', benefit: 'live', clearing: 1, displayDate: 'September 28, 2026', status: 'confirmed'}] : [], page: 1, pages: 1, total: claims})); return; }
        const kind = url.searchParams.get('kind');
        let found = items.map(item => ({...item, wishlisted: saved, clearing: kind === 'related' || kind === 'wishlist' ? 0 : item.clearing}));
        if (kind === 'library' && url.searchParams.get('tab') === 'programs') found = found.map(item => ({...item, postType: 'sfwd-courses', clearing: 0, url: '/courses/program-' + item.id}));
        if (kind === 'related') found = found.map(item => ({...item, cartHtml: '<div class="extension-wrapper"><a class="button product_type_simple add_to_cart_button ajax_add_to_cart" data-extension="preserved" href="/?add-to-cart=' + item.id + '" data-product_id="' + item.id + '" data-quantity="1" aria-label="Add to cart: ' + item.title + '"><span aria-hidden="true" class="cart-icon">+</span><span class="button-label">Add to cart</span></a></div>'}));
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
<script src="/vendor/jquery/jquery.js"></script><script>window.wc_add_to_cart_params={wc_ajax_url:'/cart/%%endpoint%%',cart_url:'/cart',cart_redirect_after_add:'no',is_cart:false,i18n_view_cart:'View cart'};</script><script src="/woo-cart.js"></script>
<script>window.wp.apiFetch=async function(o){let r=await fetch(o.url,{...o,headers:{...o.headers,'Content-Type':'application/json'},body:o.data?JSON.stringify(o.data):undefined});if(!r.ok)throw await r.json();return r.json()};window.BSML={root:'/api/',nonce:'fixture',tabs:[{id:'clearings',label:'My Clearings',type:'standard',sort:'newest'},{id:'vip',label:'My VIP Membership',type:'membership',sort:'newest'},{id:'wishlist',label:'My Wishlist',type:'wishlist',sort:'newest'},{id:'programs',label:'My Programs',type:'standard',show_related:false},{id:'quiet',label:'Quiet library',type:'standard',show_related:false,show_terms:false},{id:'welcome',label:'Welcome',type:'content',url:location.origin+'/?bsml_embed=section&bsml_section=welcome',children:[{id:'note',label:'Custom note',type:'content'},{id:'guide',label:'Guide page',type:'page',url:location.origin+'/?bsml_embed=section&bsml_section=welcome&bsml_child=guide'}]}],defaultTab:'clearings',embed:location.origin+'/',ajax:'/ajax',wishlistNonce:'fixture',wishlist:true,login:'/login'};</script><script src="/assets/library.js"></script>`);
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
        const related = page.getByRole('region', {name: 'Explore more'});
        await related.getByRole('link', {name: 'Add to cart: Clearing 01', exact: true}).waitFor();
        assert.equal(await related.getByRole('link', {name: 'View details for Clearing 01', exact: true}).getAttribute('href'), '#product');
        const beforeCartUrl = page.url();
        await related.getByRole('link', {name: 'Add to cart: Clearing 01', exact: true}).click();
        await related.locator('.added_to_cart').first().waitFor();
        const added = related.getByRole('link', {name: 'Added to cart: Clearing 01', exact: true});
        await added.waitFor();
        assert.equal(await added.locator('.button-label').textContent(), 'Added to cart');
        assert.equal(await added.locator('.cart-icon').textContent(), '+', 'Native icon markup is preserved');
        assert.equal(await added.getAttribute('data-extension'), 'preserved');
        assert.equal(cartAdds, 1, 'Native WooCommerce handler adds the related product');
        assert.equal(page.url(), beforeCartUrl, 'Adding to cart does not reload or navigate the library');
        await page.evaluate(() => window.jQuery(document.body).on('should_send_ajax_request.adding_to_cart.fixture', () => false));
        await related.getByRole('link', {name: 'Add to cart: Clearing 02', exact: true}).click();
        assert.equal(cartAdds, 1, 'Extension validation can stop a cart request');
        assert.equal(await related.getByRole('link', {name: 'Add to cart: Clearing 02', exact: true}).count(), 1, 'Blocked additions do not show success');
        assert.equal(await added.locator('.button-label').textContent(), 'Added to cart');
        await page.evaluate(() => window.jQuery(document.body).off('should_send_ajax_request.adding_to_cart.fixture'));
        assert(await library.evaluate(el => Boolean(el.querySelector('.bsml-pagination').compareDocumentPosition(el.querySelector('.bsml-category-section')) & Node.DOCUMENT_POSITION_FOLLOWING)), 'Category section follows pagination');
        await Promise.all([
            page.waitForResponse(r => r.url().includes('term=11') && r.url().includes('kind=library')),
            library.getByRole('button', {name: 'Release & Renew'}).click()
        ]);
        await library.getByRole('searchbox').fill('Clearing 25');
        await library.getByRole('heading', {name: 'Clearing 25', exact: true}).waitFor();
        assert.equal(await library.locator('.bsml-card').count(), 1);
        assert.equal(await library.locator('.bsml-pagination').count(), 0);
        assert.equal(await library.getByRole('region', {name: 'Browse by category'}).count(), 1, 'Categories remain on single-page results');
        await library.getByRole('searchbox').fill('');
        await library.getByRole('heading', {name: 'Clearing 01', exact: true}).waitFor();
        await Promise.all([
            page.waitForResponse(r => r.url().includes('sort=za')),
            library.getByRole('combobox').selectOption('za')
        ]);
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
        await page.getByRole('button', {name: 'My Programs', exact: true}).click();
        await library.getByRole('link', {name: 'Open program', exact: true}).first().waitFor();
        assert.equal(await library.getByRole('link', {name: 'Open program', exact: true}).first().getAttribute('href'), '/courses/program-1');
        assert.equal(await library.getByRole('button', {name: 'Open clearing', exact: true}).count(), 0);
        const beforeQuiet = calls.length;
        await page.getByRole('button', {name: 'Quiet library', exact: false}).click();
        await library.getByRole('button', {name: 'Open clearing'}).first().waitFor();
        assert.equal(await page.getByRole('region', {name: 'Explore more'}).count(), 0);
        assert.equal(await library.getByRole('region', {name: 'Browse by category'}).count(), 0);
        assert(!calls.slice(beforeQuiet).some(url => url.includes('kind=related')), 'Disabled recommendations make no requests');
        await page.getByRole('button', {name: 'Welcome', exact: true}).click();
        await page.locator('.inline-fixture[data-initialized="yes"]').waitFor();
        assert.equal(await page.locator('iframe').count(), 0, 'Custom content is rendered directly without an iframe');
        assert.equal(await page.locator('.inline-fixture').textContent(), 'Custom text: welcome');
        await page.getByRole('button', {name: 'Custom note', exact: true}).click();
        await page.getByText('Custom text: note', {exact: true}).waitFor();
        assert.equal(await page.locator('iframe').count(), 0, 'Custom submenu content is also inline');
        assert.equal(await page.evaluate(() => window.shortcodeLibraryLoads), 1, 'External shortcode dependencies are not loaded twice');
        await page.getByRole('button', {name: 'Welcome', exact: true}).click();
        await page.getByText('Custom text: welcome', {exact: true}).waitFor();
        const toggle = page.getByRole('button', {name: 'Toggle Welcome submenu'});
        assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
        await page.getByRole('button', {name: 'Guide page', exact: true}).click();
        await page.getByRole('heading', {name: 'Guide page', exact: true}).waitFor();
        assert((await page.locator('iframe').getAttribute('src')).includes('bsml_child=guide'));
        assert(page.url().includes('bsml_child=guide'));
        await toggle.click();
        assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
        assert.equal(await page.getByRole('heading', {name: 'Guide page', exact: true}).count(), 1, 'Arrow does not change displayed content');
        await page.goBack();
        await page.getByRole('heading', {name: 'Welcome', exact: true}).waitFor();
        await page.goForward();
        await page.getByRole('heading', {name: 'Guide page', exact: true}).waitFor();
        assert.equal(await toggle.getAttribute('aria-expanded'), 'true');

        await page.getByRole('button', {name: 'My Wishlist'}).click();
        await page.getByRole('button', {name: 'Remove Clearing 01 from wishlist'}).click();
        await page.getByText('Nothing here yet', {exact: true}).waitFor();
        await page.setViewportSize({width: 390, height: 844});
        await page.getByRole('combobox', {name: 'Library section'}).selectOption('vip');
        await page.getByRole('heading', {name: 'Level 3', exact: true}).waitFor();
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'No mobile horizontal overflow');
        await page.screenshot({path: '/private/tmp/bsml-mobile.png', fullPage: true});
        await page.getByRole('combobox', {name: 'Library section'}).selectOption('welcome/guide');
        await page.getByRole('heading', {name: 'Guide page', exact: true}).waitFor();
        assert((await page.locator('iframe').getAttribute('src')).includes('bsml_child=guide'));
        await page.goto('http://127.0.0.1:' + server.address().port + '/admin-fixture');
        const adminRow = page.locator('.bsml-tab-config');
        await adminRow.locator(':scope > summary').click();
        await adminRow.locator(':scope > .bsml-admin-grid .bsml-section-type').selectOption('standard');
        await adminRow.locator('.bsml-taxonomy').selectOption('ld_course_category');
        await adminRow.getByText('This taxonomy is not attached', {exact: false}).waitFor();
        assert.equal(await adminRow.locator('.bsml-library-scope select').first().locator('option').count(), 1);
        assert.equal(await adminRow.locator('.bsml-taxonomy').inputValue(), 'ld_course_category');
        assert.equal(await adminRow.locator('.bsml-library-scope select').first().locator('option').textContent(), 'Program term');
        await adminRow.locator('.bsml-taxonomy').selectOption('topic');
        await adminRow.getByText('Terms loaded. Select the included terms for this section.', {exact: true}).waitFor();
        assert.equal(await adminRow.locator('.bsml-taxonomy').inputValue(), 'topic');
        assert.equal(await adminRow.locator('.bsml-library-scope select').first().locator('option').textContent(), 'Topic term');

        await adminRow.locator(':scope > .bsml-admin-grid .bsml-section-type').selectOption('content');
        await adminRow.getByRole('button', {name: 'Add submenu', exact: true}).click();
        const children = adminRow.locator('.bsml-children > .bsml-child-config');
        assert.equal(await children.count(), 2);
        await children.last().locator('input[name$="[label]"]').fill('Second child');
        await children.last().getByRole('button', {name: 'Move up', exact: true}).click();
        assert.equal(await children.first().locator('input[name$="[label]"]').inputValue(), 'Second child');
        await page.evaluate(() => document.getElementById('bsml-settings').addEventListener('submit', e => e.preventDefault()));
        await page.getByRole('button', {name: 'Save', exact: true}).click();
        assert.equal(await children.first().locator('input[name$="[label]"]').getAttribute('name'), 'bsml_settings[tabs][0][children][0][label]');
        assert.deepEqual(errors, []);
        console.log('PASS browser: rendering, full-list search, sorting, filters, inline viewer cleanup, claims, fresh revisits, wishlist removal, mobile layout, native cart success feedback, content submenus/history, display switches, taxonomy reloads, submenu editing/order; no JavaScript errors');
    } finally { await browser.close(); server.close(); }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
