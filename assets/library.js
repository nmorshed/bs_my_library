(function (wp, config) {
    'use strict';
    if (!wp || !wp.element || !wp.apiFetch || !config) return;
    const {createElement: h, useState, useEffect, useRef, createRoot} = wp.element;
    const sortLabels = {newest: 'Newest first', oldest: 'Oldest first', az: 'Title A–Z', za: 'Title Z–A', event_asc: 'Event date: next first', event_desc: 'Event date: latest first', price_asc: 'Price: low to high', price_desc: 'Price: high to low'};
    function api(path, options) {
        return wp.apiFetch(Object.assign({url: config.root + path, cache: 'no-store', credentials: 'same-origin', headers: {'X-WP-Nonce': config.nonce}}, options || {}));
    }
    function useResource(path, revision) {
        const [state, setState] = useState({data: null, loading: true, error: null});
        const [retry, setRetry] = useState(0);
        useEffect(function () {
            if (!path) { setState({data: null, loading: false, error: null}); return; }
            const controller = new AbortController();
            let active = true;
            setState({data: null, loading: true, error: null});
            api(path, {signal: controller.signal}).then(function (data) {
                if (active) setState({data, loading: false, error: null});
            }).catch(function (error) {
                if (active && error.name !== 'AbortError') setState({data: null, loading: false, error});
            });
            return function () { active = false; controller.abort(); };
        }, [path, revision, retry]);
        return Object.assign({}, state, {retry: function () { setRetry(n => n + 1); }});
    }
    function ErrorBox({error, retry}) {
        const loggedOut = error && (error.code === 'rest_cookie_invalid_nonce' || error.code === 'bsml_login');
        return h('div', {className: 'bsml-message bsml-error', role: 'alert'},
            h('p', null, error && error.message ? error.message : 'We could not load this section.'),
            loggedOut ? h('a', {href: config.login}, 'Log in again') : h('button', {type: 'button', onClick: retry}, 'Try again'));
    }
    function Skeleton() {
        return h('div', {className: 'bsml-grid bsml-skeleton', 'aria-label': 'Loading items', role: 'status'}, [0, 1, 2].map(i => h('div', {key: i, className: 'bsml-placeholder'}, h('span'), h('span'), h('span'))));
    }
    function Toolbar({prefix, query, change, product, history, defaultSort}) {
        const value = query.get('bsml_' + prefix + '_q') || '';
        const [draft, setDraft] = useState(value);
        const sort = query.get('bsml_' + prefix + '_sort') || defaultSort || 'newest';
        useEffect(() => setDraft(value), [value]);
        useEffect(function () {
            if (draft === value) return;
            const timer = setTimeout(function () { change(prefix, {q: draft, page: 1}, true); }, 350);
            return () => clearTimeout(timer);
        }, [draft, value, prefix, change]);
        const keys = ['newest', 'oldest', 'az', 'za'].concat(history ? [] : ['event_asc', 'event_desc']).concat(product ? ['price_asc', 'price_desc'] : []);
        return h('div', {className: 'bsml-toolbar'},
            h('label', {className: 'bsml-search'}, h('span', {className: 'bsml-sr'}, 'Search this list'), h('input', {type: 'search', placeholder: history ? 'Search past selections…' : 'Search this collection…', value: draft, maxLength: 150, onChange: e => setDraft(e.target.value)})),
            h('label', {className: 'bsml-sort'}, h('span', {className: 'bsml-sr'}, 'Sort this list'), h('select', {value: sort, onChange: e => change(prefix, {sort: e.target.value, page: 1})}, keys.map(key => h('option', {value: key, key}, sortLabels[key])))));
    }
    function Pagination({data, prefix, change}) {
        if (data.pages < 2) return null;
        return h('nav', {className: 'bsml-pagination', 'aria-label': 'List pages'},
            h('button', {type: 'button', disabled: data.page <= 1, onClick: () => change(prefix, {page: data.page - 1})}, 'Previous'),
            h('span', null, 'Page ' + data.page + ' of ' + data.pages),
            h('button', {type: 'button', disabled: data.page >= data.pages, onClick: () => change(prefix, {page: data.page + 1})}, 'Next'));
    }
    function NativeCart({html, title}) {
        const container = useRef(null);
        useEffect(function () {
            const node = container.current;
            const $ = window.jQuery;
            if (!node || !$) return;
            function onAdded(event, fragments, hash, $button) {
                const button = $button && $button[0];
                if (!button || !node.contains(button)) return;
                const label = config.addedToCart || 'Added to cart';
                // Keep extension wrappers/icons intact; change only visible label text.
                const walker = document.createTreeWalker(button, NodeFilter.SHOW_TEXT, {
                    acceptNode(text) {
                        return text.textContent.trim() && !text.parentElement.closest('[aria-hidden="true"], .screen-reader-text, .bsml-sr')
                            ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
                    }
                });
                const text = walker.nextNode();
                if (text) text.textContent = label;
                else button.appendChild(document.createTextNode(label));
                button.setAttribute('aria-label', label + ': ' + title);
            }
            $(document.body).on('added_to_cart.bsml', onAdded);
            return () => $(document.body).off('added_to_cart.bsml', onAdded);
        }, [title]);
        // WooCommerce owns this subtree and its delegated click handlers. React
        // leaves it untouched on unrelated rerenders, including success feedback.
        return h('div', {ref: container, className: 'bsml-native-cart woocommerce', dangerouslySetInnerHTML: {__html: html}});
    }
    function List({kind, prefix, title, tab, query, change, revision, refresh, open, benefit, selection, toggle, remaining, blocked, onLoaded}) {
        const search = query.get('bsml_' + prefix + '_q') || '';
        const sort = query.get('bsml_' + prefix + '_sort') || tab.sort || 'newest';
        const page = query.get('bsml_' + prefix + '_page') || '1';
        const term = tab.type === 'standard' && tab.show_terms === false ? '0' : query.get('bsml_' + (kind === 'related' ? 'library' : prefix) + '_term') || '0';
        const params = new URLSearchParams({tab: tab.id, kind, search, sort, page, term});
        if (benefit) params.set('benefit', benefit);
        const result = useResource('list?' + params, revision);
        const listHeading = useRef(null);
        const scrollAfterFilter = useRef(false);
        function scrollToResults() {
            if (!listHeading.current) return;
            listHeading.current.focus({preventScroll: true});
            listHeading.current.scrollIntoView({block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
        }
        useEffect(function () {
            if (result.data && scrollAfterFilter.current) { scrollAfterFilter.current = false; scrollToResults(); }
        }, [result.data]);
        function selectCategory(id) {
            if (Number(term) === id && Number(page) === 1) { scrollToResults(); return; }
            scrollAfterFilter.current = true;
            change(prefix, {term: id, page: 1});
        }
        useEffect(function () { if (result.data && onLoaded) onLoaded(); }, [result.data]);
        const [wishBusy, setWishBusy] = useState(null);
        const [wishError, setWishError] = useState(null);
        const product = kind === 'related' || kind === 'wishlist';
        function wishlist(item) {
            setWishBusy(item.id); setWishError(null);
            const body = new URLSearchParams({action: 'add_to_wishlist', product_id: item.id, variation_id: 0, quantity: 1, act: item.wishlisted ? 'remove' : 'add', wt_nonce: config.wishlistNonce});
            fetch(config.ajax, {method: 'POST', credentials: 'same-origin', cache: 'no-store', body}).then(async response => {
                if (!response.ok) throw new Error('Wishlist could not be updated. Please refresh and try again.');
                const json = await response.json();
                if (!json || json.success === false || typeof json !== 'object') throw new Error('Wishlist could not be updated.');
                refresh();
            }).catch(setWishError).finally(() => setWishBusy(null));
        }
        return h('section', {className: 'bsml-section', 'aria-label': title},
            h('div', {className: 'bsml-section-heading'}, h('h2', {ref: listHeading, tabIndex: -1}, title), result.data && h('span', {className: 'bsml-count', role: 'status', 'aria-live': 'polite'}, result.data.total + ' items')),
            h(Toolbar, {prefix, query, change, product, defaultSort: tab.sort}),
            wishError && h(ErrorBox, {error: wishError, retry: () => { setWishError(null); refresh(); }}),
            result.error ? h(ErrorBox, {error: result.error, retry: result.retry}) : result.loading ? h(Skeleton) : result.data && h(wp.element.Fragment, null,
                result.data.items.length === 0 ? h('div', {className: 'bsml-empty'}, h('h3', null, search || term !== '0' ? 'No matching items' : 'Nothing here yet'), h('p', null, result.data.configured === false ? 'This collection is being prepared. Please check back soon.' : kind === 'related' ? 'There are no additional recommendations in this collection.' : kind === 'wishlist' ? 'Save products to your wishlist while exploring the library.' : 'Try another category or search, or explore your other collections.')) :
                    h('div', {className: 'bsml-grid'}, result.data.items.map(item => h('article', {className: 'bsml-card' + (selection && selection.includes(item.id) ? ' is-selected' : ''), key: item.id},
                        kind === 'related' ? h('a', {className: 'bsml-product-image', href: item.url, 'aria-label': 'View details for ' + item.title},
                            item.image ? h('img', {src: item.image, alt: '', loading: 'lazy', decoding: 'async'}) : h('div', {className: 'bsml-image-placeholder', 'aria-hidden': true}, '✧')) :
                            item.image ? h('img', {src: item.image, alt: '', loading: 'lazy', decoding: 'async'}) : h('div', {className: 'bsml-image-placeholder', 'aria-hidden': true}, '✧'),
                        h('div', {className: 'bsml-card-body'}, h('h3', null, item.title), item.event && h('p', {className: 'bsml-meta'}, item.event),
                            product && h('div', {className: 'bsml-price', dangerouslySetInnerHTML: {__html: item.priceHtml}}),
                            kind === 'benefit' ? h('button', {type: 'button', className: 'bsml-button', disabled: blocked || (!selection.includes(item.id) && selection.length >= remaining), 'aria-pressed': selection.includes(item.id), onClick: () => toggle(item.id)}, selection.includes(item.id) ? 'Selected ✓' : 'Select') :
                                kind === 'related' ? h(NativeCart, {html: item.cartHtml || '', title: item.title}) :
                                    item.clearing ? h('button', {type: 'button', className: 'bsml-button', onClick: () => open(item.clearing)}, 'Open clearing') : h('a', {className: 'bsml-button', href: item.url}, item.postType === 'sfwd-courses' ? 'Open program' : 'View details'),
                            product && config.wishlist && h('button', {type: 'button', className: 'bsml-wish', disabled: wishBusy !== null, onClick: () => wishlist(item), 'aria-label': (item.wishlisted ? 'Remove ' : 'Save ') + item.title + (item.wishlisted ? ' from wishlist' : ' to wishlist')}, wishBusy === item.id ? 'Updating…' : item.wishlisted ? '♥ Remove from wishlist' : '♡ Save to wishlist'))))),
                h(Pagination, {data: result.data, prefix, change}),
                kind !== 'related' && tab.show_terms !== false && result.data.filters.length > 0 && h('section', {className: 'bsml-category-section', 'aria-label': 'Browse by category'},
                    h('h3', null, 'Browse by category'),
                    h('div', {className: 'bsml-filters', 'aria-label': 'Category filters'},
                        [{id: 0, label: 'All'}].concat(result.data.filters).map(filter => h('button', {key: filter.id, type: 'button', 'aria-pressed': Number(term) === filter.id, onClick: () => selectCategory(filter.id)}, filter.label))))));
    }
    function History({query, change, revision, open}) {
        const params = new URLSearchParams({search: query.get('bsml_history_q') || '', sort: query.get('bsml_history_sort') || 'newest', page: query.get('bsml_history_page') || '1'});
        const result = useResource('history?' + params, revision);
        return h('section', {className: 'bsml-section bsml-history'}, h('h2', null, 'Your past selections'), h('p', {className: 'bsml-meta'}, 'Your selection history stays here when your benefits renew.'), h(Toolbar, {prefix: 'history', query, change, history: true}),
            result.error ? h(ErrorBox, {error: result.error, retry: result.retry}) : result.loading ? h('p', {role: 'status'}, 'Loading selections…') : h(wp.element.Fragment, null,
                result.data.items.length ? h('ul', null, result.data.items.map((item, index) => h('li', {key: item.id + '-' + index},
                    h('div', null, item.clearing ? h('button', {type: 'button', className: 'bsml-text-button', onClick: () => open(item.clearing)}, item.title) : h('strong', null, item.title), h('span', {className: 'bsml-meta'}, item.benefit === 'live' ? 'Live GEC' : item.benefit === 'replay' ? 'Replay' : item.benefit)),
                    h('span', null, item.displayDate, item.status === 'pending' && h('small', null, ' · Awaiting confirmation'))))) : h('p', null, 'No matching past selections.'), h(Pagination, {data: result.data, prefix: 'history', change})));
    }
    const contentAssetLoads = new Map();
    async function loadContentScript(script) {
        if (script.src) {
            const src = script.src;
            if (contentAssetLoads.has(src)) return contentAssetLoads.get(src);
            if (Array.from(document.scripts).some(existing => existing.src === src)) return;
            const promise = new Promise((resolve, reject) => {
                const node = document.createElement('script');
                for (const attr of script.attributes) node.setAttribute(attr.name, attr.value);
                node.async = false;
                node.onload = resolve;
                node.onerror = () => { node.remove(); contentAssetLoads.delete(src); reject(new Error('A shortcode script could not load. Please try again.')); };
                document.head.appendChild(node);
            });
            contentAssetLoads.set(src, promise);
            return promise;
        }
        const node = document.createElement('script');
        for (const attr of script.attributes) node.setAttribute(attr.name, attr.value);
        node.textContent = script.textContent;
        document.head.appendChild(node);
        node.remove();
    }
    function RenderedContent({data, onReady}) {
        const container = useRef(null);
        const ready = useRef(onReady); ready.current = onReady;
        const [assetError, setAssetError] = useState(null);
        useEffect(function () {
            if (!data || !container.current) return;
            let active = true;
            const node = container.current;
            setAssetError(null);
            const content = document.createElement('template');
            content.innerHTML = data.html || '';
            const assets = document.createElement('template');
            assets.innerHTML = data.assets || '';
            const scripts = [];
            for (const fragment of [assets.content, content.content]) {
                fragment.querySelectorAll('script').forEach(script => {
                    if (!script.type || /^(module|text\/javascript|application\/javascript)$/i.test(script.type)) {
                        scripts.push(script.cloneNode(true)); script.remove();
                    }
                });
            }
            node.replaceChildren(content.content);
            assets.content.querySelectorAll('style, link[rel="stylesheet"]').forEach(asset => {
                const exists = asset.id ? document.getElementById(asset.id) : asset.href && Array.from(document.querySelectorAll('link[rel="stylesheet"]')).some(link => link.href === asset.href);
                if (!exists) document.head.appendChild(asset.cloneNode(true));
            });
            (async function () {
                try {
                    for (const script of scripts) { if (!active) return; await loadContentScript(script); }
                    if (active) { node.dispatchEvent(new CustomEvent('bsml:content-ready', {bubbles: true})); if (ready.current) ready.current(node); }
                } catch (error) { if (active) setAssetError(error); }
            })();
            return () => { active = false; node.dispatchEvent(new CustomEvent('bsml:content-unmount', {bubbles: true})); node.replaceChildren(); };
        }, [data]);
        return h(wp.element.Fragment, null,
            assetError && h('p', {className: 'bsml-message bsml-error', role: 'alert'}, assetError.message),
            h('div', {ref: container}));
    }
    function CustomContent({section, child, revision, resource}) {
        const params = new URLSearchParams({section, child: child || ''});
        const result = useResource(resource || 'content?' + params, revision);
        return h('section', {className: 'bsml-custom-content', 'aria-busy': result.loading},
            result.loading && h('p', {role: 'status'}, 'Loading content…'),
            result.error && h(ErrorBox, {error: result.error, retry: result.retry}),
            h(RenderedContent, {data: result.data}));
    }
    function accountUrl(base, value) {
        const url = new URL(base, location.href);
        if (value) {
            const queryKey = Array.from(url.searchParams.keys()).find(key => url.searchParams.get(key) === '');
            if (queryKey) url.searchParams.set(queryKey, value);
            else url.pathname = url.pathname.replace(/\/$/, '') + '/' + encodeURIComponent(value) + '/';
        }
        return url;
    }
    function accountLink(url, routes) {
        if (url.searchParams.has('_wpnonce') || url.searchParams.has('cancel_order') || url.searchParams.has('pay_for_order')) return null;
        for (const [endpoint, base] of Object.entries(routes).sort(([a], [b]) => a === 'dashboard' ? 1 : b === 'dashboard' ? -1 : 0)) {
            const root = new URL(base, location.href);
            if (url.origin !== root.origin) continue;
            const queryKey = Array.from(root.searchParams.keys()).find(key => root.searchParams.get(key) === '');
            if (queryKey) {
                if (url.pathname === root.pathname && url.searchParams.has(queryKey)) return {endpoint, value: url.searchParams.get(queryKey)};
            } else {
                const path = root.pathname.replace(/\/$/, '');
                if (url.pathname.replace(/\/$/, '') === path && Array.from(root.searchParams).every(([key, value]) => url.searchParams.get(key) === value) && (endpoint !== 'dashboard' || Array.from(url.searchParams.keys()).every(key => root.searchParams.has(key)))) return {endpoint, value: ''};
                if (endpoint !== 'dashboard' && url.pathname.startsWith(path + '/')) return {endpoint, value: decodeURIComponent(url.pathname.slice(path.length + 1).replace(/\/$/, ''))};
            }
        }
        return null;
    }
    function Account({tab, endpoint, value, revision, onNavigate}) {
        const [state, setState] = useState({data: null, loading: true, error: null});
        const [saving, setSaving] = useState(false);
        const [retry, setRetry] = useState(0);
        const responseKey = useRef(null);
        const alive = useRef(true);
        const routes = tab.accountRoutes || {};
        useEffect(() => { alive.current = true; return () => { alive.current = false; }; }, []);
        async function request(url, options) {
            url.searchParams.set('bsml_account', tab.id);
            const response = await fetch(url.href, Object.assign({credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}}, options));
            const data = await response.json().catch(() => { throw new Error('The account screen could not load. Please refresh or open WooCommerce My Account.'); });
            if (!response.ok) throw new Error(data.message || 'Your account could not load.');
            if (typeof data.html !== 'string') throw new Error('Unexpected account response. Please refresh.');
            if (data.nonce) config.nonce = data.nonce;
            return data;
        }
        useEffect(function () {
            const key = endpoint + ':' + value + ':' + revision + ':' + retry;
            if (responseKey.current === key) { responseKey.current = null; return; }
            if (!routes[endpoint]) { setState({data: null, loading: false, error: null}); return; }
            const controller = new AbortController();
            setState({data: null, loading: true, error: null});
            request(accountUrl(routes[endpoint], value), {signal: controller.signal}).then(data => setState({data, loading: false, error: null})).catch(error => {
                if (error.name !== 'AbortError') setState({data: null, loading: false, error});
            });
            return () => controller.abort();
        }, [tab.id, endpoint, value, revision, retry]);
        function click(event) {
            const link = event.target.closest('a');
            if (!link || link.hasAttribute('data-bsml-account-native') || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target === '_blank' || link.hasAttribute('download')) return;
            const route = accountLink(new URL(link.href), routes);
            if (route) { event.preventDefault(); if (!saving) onNavigate(route.endpoint, route.value); }
        }
        async function submit(event) {
            const form = event.target;
            const action = form.querySelector('input[name="action"]');
            if (!action || !['edit_address', 'save_account_details'].includes(action.value)) return;
            event.preventDefault();
            if (saving || !state.data) return;
            const body = new FormData(form);
            const submitter = event.nativeEvent.submitter;
            if (submitter && submitter.name) body.set(submitter.name, submitter.value);
            setSaving(true);
            try {
                const data = await request(new URL(state.data.url), {method: 'POST', body});
                if (!alive.current) return;
                setState({data, loading: false, error: null});
                if (data.endpoint !== endpoint || data.value !== value) {
                    responseKey.current = data.endpoint + ':' + data.value + ':' + revision + ':' + retry;
                    onNavigate(data.endpoint, data.value, true);
                }
            } catch (error) {
                if (alive.current) setState(previous => ({...previous, error: new Error(error.message + ' Refresh before submitting again if you are unsure whether it saved.')}));
            } finally { if (alive.current) setSaving(false); }
        }
        function ready(node) {
            node.querySelectorAll('form').forEach(form => { if (!form.getAttribute('action')) form.action = state.data.url; });
            if (window.jQuery) window.jQuery(node).find('select.country_to_state').trigger('change');
        }
        return h('section', {className: 'bsml-account-panel woocommerce', 'aria-busy': state.loading || saving, onClick: click, onSubmit: submit},
            state.loading && h('p', {role: 'status'}, 'Loading your account…'),
            state.error && h(ErrorBox, {error: state.error, retry: () => setRetry(n => n + 1)}),
            !routes[endpoint] && h('p', {className: 'bsml-message'}, 'No account screens are enabled for this section.'),
            saving && h('p', {role: 'status'}, 'Saving your changes…'),
            h('fieldset', {disabled: saving, className: 'bsml-account-fields'}, h(RenderedContent, {data: state.data, onReady: ready})),
            h('a', {className: 'bsml-account-native-link', 'data-bsml-account-native': true, href: tab.accountUrl}, 'Open full WooCommerce account ↗'));
    }
    function Frame({kind, id, revision, source, title}) {
        const ref = useRef(null);
        const [height, setHeight] = useState(600);
        const [loaded, setLoaded] = useState(false);
        const [slow, setSlow] = useState(false);
        const url = new URL(source || config.embed);
        url.searchParams.set('bsml_embed', kind);
        if (id && kind === 'clearing') url.searchParams.set('clearing_id', id);
        useEffect(function () {
            function onMessage(event) {
                if (event.origin === location.origin && ref.current && event.source === ref.current.contentWindow && event.data && event.data.type === 'bsml-height') {
                    const next = Number(event.data.height);
                    if (Number.isFinite(next)) setHeight(Math.max(240, Math.min(30000, next + 20)));
                }
            }
            addEventListener('message', onMessage);
            return () => removeEventListener('message', onMessage);
        }, []);
        useEffect(function () { setLoaded(false); setSlow(false); const timer = setTimeout(() => setSlow(true), 12000); return () => clearTimeout(timer); }, [kind, id, revision, source]);
        return h('div', {className: 'bsml-frame-wrap'}, !loaded && h('p', {role: 'status'}, slow ? 'The content is taking longer to load. Try Refresh if it does not appear.' : 'Loading content…'),
            h('iframe', {key: kind + '-' + id + '-' + revision + '-' + source, ref, src: url.href, title: title || (kind === 'clearing' ? 'Clearing content and video' : 'Accelerator appointment'), className: 'bsml-frame', style: {height: height + 'px'}, allow: 'fullscreen; picture-in-picture; encrypted-media', onLoad: () => setLoaded(true)}));
    }
    function Membership(props) {
        const {query, change, revision, refresh, tab, open} = props;
        const state = useResource('membership', revision);
        const [selection, setSelection] = useState({live: [], replay: []});
        const [claiming, setClaiming] = useState(false);
        const [message, setMessage] = useState(null);
        useEffect(() => setSelection({live: [], replay: []}), [revision]);
        async function claim(key) {
            if (claiming || !selection[key].length) return;
            setClaiming(true); setMessage(null);
            const requestKey = window.crypto && crypto.randomUUID ? crypto.randomUUID() : Date.now() + '-' + Math.random().toString(36).slice(2) + '-claim';
            try {
                await api('claim', {method: 'POST', data: {benefit: key, ids: selection[key], request_key: requestKey}});
                setMessage({ok: true, text: 'Your selection has been claimed. Open it from your past selections below.'});
                setSelection({live: [], replay: []}); refresh();
            } catch (error) {
                setMessage({ok: false, text: error.message || 'Your claim could not be confirmed. Refresh your benefits before trying again.'}); refresh();
            } finally { setClaiming(false); }
        }
        return h(wp.element.Fragment, null,
            state.error ? h(ErrorBox, {error: state.error, retry: state.retry}) : state.loading ? h(Skeleton) : h(wp.element.Fragment, null,
                h('div', {className: 'bsml-membership-intro'}, h('span', {className: 'bsml-eyebrow'}, 'YOUR MEMBERSHIP'), state.data.tier ? h(wp.element.Fragment, null, h('h2', null, state.data.tier), h('p', null, 'Choose something meaningful for your next step.')) : h('div', {dangerouslySetInnerHTML: {__html: state.data.nonMemberContent || ''}})),
                state.data.tier && h('div', {className: 'bsml-benefits'}, ['live', 'replay'].map(key => h('div', {className: 'bsml-benefit', key}, h('span', null, state.data.benefits[key].label), h('strong', null, state.data.benefits[key].remaining), h('span', null, 'of ' + state.data.benefits[key].limit + ' remaining'))),
                    state.data.appointment.eligible && h('div', {className: 'bsml-benefit'}, h('span', null, 'Accelerator session'), h('strong', {className: 'bsml-benefit-status'}, state.data.appointment.booked ? 'Booked' : 'Available'))),
                state.data.pending && h('p', {className: 'bsml-message', role: 'status'}, 'A claim is awaiting confirmation. Refresh your benefits or contact support before making another selection.'),
                message && h('p', {className: 'bsml-message' + (message.ok ? '' : ' bsml-error'), role: message.ok ? 'status' : 'alert'}, message.text),
                state.data.tier && ['live', 'replay'].map(key => h('div', {key},
                    h(List, Object.assign({}, props, {kind: 'benefit', prefix: key, title: 'Choose your ' + state.data.benefits[key].label.toLowerCase(), benefit: key, selection: selection[key], remaining: state.data.benefits[key].remaining, blocked: claiming || state.data.pending,
                        toggle: id => setSelection(previous => Object.assign({}, previous, {[key]: previous[key].includes(id) ? previous[key].filter(value => value !== id) : previous[key].concat(id)}))})),
                    h('div', {className: 'bsml-claim-bar'}, h('span', null, selection[key].length + ' selected · ' + state.data.benefits[key].remaining + ' remaining'), h('button', {type: 'button', className: 'bsml-button', disabled: claiming || state.data.pending || !selection[key].length || selection[key].length > state.data.benefits[key].remaining, onClick: () => claim(key)}, claiming ? 'Confirming…' : 'Claim selected items')),
                    state.data.benefits[key].remaining === 0 && h('p', {className: 'bsml-meta'}, 'Your allowance renews after your next payment is processed and synchronized.'))),
                state.data.appointment.eligible && h('section', {className: 'bsml-section'}, h('h2', null, 'Your accelerator session'), h(CustomContent, {resource: 'appointment', revision}))),
            h(History, {query, change, revision, open}));
    }
    function MenuItem({item, active, childId, navigate, index}) {
        const [expanded, setExpanded] = useState(active);
        const children = item.children || [];
        useEffect(() => { if (active) setExpanded(true); }, [active, childId]);
        const submenuId = 'bsml-submenu-' + item.id;
        return h('div', {className: 'bsml-nav-group'},
            h('div', {className: 'bsml-nav-parent'},
                h(item.newTab ? 'a' : 'button', item.newTab ? {href: item.url, target: '_blank', rel: 'noopener noreferrer', className: 'bsml-menu-link', 'aria-label': item.label + ' (opens in a new tab)', onClick: () => setExpanded(true)} : {type: 'button', 'aria-current': active && !childId ? 'page' : undefined, onClick: () => { setExpanded(true); navigate(item.id); }},
                    h('span', {className: 'bsml-nav-number', 'aria-hidden': true}, String(index + 1).padStart(2, '0')), item.label),
                children.length > 0 && h('button', {type: 'button', className: 'bsml-submenu-toggle', 'aria-label': 'Toggle ' + item.label + ' submenu', 'aria-expanded': expanded, 'aria-controls': submenuId, onClick: () => setExpanded(value => !value)}, expanded ? '▴' : '▾')),
            children.length > 0 && h('div', {id: submenuId, className: 'bsml-submenu' + (expanded ? ' is-open' : ''), 'aria-hidden': !expanded},
                h('div', null, children.map(child => h(child.newTab ? 'a' : 'button', Object.assign({key: child.id, tabIndex: expanded ? 0 : -1}, child.newTab ? {href: child.url, target: '_blank', rel: 'noopener noreferrer', className: 'bsml-menu-link', 'aria-label': child.label + ' (opens in a new tab)'} : {type: 'button', 'aria-current': active && childId === child.id ? 'page' : undefined, onClick: () => navigate(item.id, child.id)}), child.label)))));
    }
    function App() {
        const [query, setQuery] = useState(() => new URLSearchParams(location.search));
        const [revision, setRevision] = useState(0);
        const heading = useRef(null);
        const browseScroll = useRef(0);
        const restoreScroll = useRef(null);
        const tabs = config.tabs;
        const tab = tabs.find(t => t.id === query.get('bsml_tab')) || tabs.find(t => t.id === config.defaultTab && !t.newTab) || tabs.find(t => !t.newTab) || tabs[0];
        const child = tab && (tab.children || []).find(item => item.id === query.get('bsml_child')) || (tab && tab.type === 'account' ? (tab.children || []).find(item => !item.external) : null);
        const display = child || tab;
        const clearing = tab && tab.type === 'standard' ? Number(query.get('bsml_clearing')) || 0 : 0;
        const refresh = () => setRevision(value => value + 1);
        useEffect(function () {
            function pop() { setQuery(new URLSearchParams(location.search)); setRevision(value => value + 1); if (history.state && history.state.bsmlScroll != null) restoreScroll.current = history.state.bsmlScroll; }
            function focus() { if (document.visibilityState === 'visible') setRevision(value => value + 1); }
            addEventListener('popstate', pop); addEventListener('focus', focus);
            return () => { removeEventListener('popstate', pop); removeEventListener('focus', focus); };
        }, []);
        function update(next, replace) {
            const url = new URL(location.href); url.search = next.toString();
            history[replace ? 'replaceState' : 'pushState']({}, '', url);
            setQuery(new URLSearchParams(next));
        }
        function change(prefix, values, replace) {
            const next = new URLSearchParams(location.search);
            Object.entries(values).forEach(([key, value]) => { if (value === '' || value === 0) next.delete('bsml_' + prefix + '_' + key); else next.set('bsml_' + prefix + '_' + key, value); });
            if (prefix === 'library' && Object.prototype.hasOwnProperty.call(values, 'term')) next.delete('bsml_related_page');
            update(next, replace);
        }
        function navigate(id, childId) {
            const target = tabs.find(item => item.id === id);
            const sub = target && (target.children || []).find(item => item.id === childId);
            const destination = sub || target;
            if (destination && destination.newTab) { window.open(destination.url, '_blank', 'noopener,noreferrer'); return true; }
            if (sub && sub.external) { location.assign(sub.url); return; }
            const next = new URLSearchParams(location.search);
            Array.from(next.keys()).filter(key => key.startsWith('bsml_')).forEach(key => next.delete(key));
            next.set('bsml_tab', id); if (childId) next.set('bsml_child', childId); update(next); refresh();
            requestAnimationFrame(() => heading.current && heading.current.focus());
        }
        function navigateAccount(endpoint, value, replace) {
            const next = new URLSearchParams(location.search);
            next.set('bsml_child', endpoint === 'view-order' ? 'orders' : endpoint);
            next.set('bsml_account_endpoint', endpoint);
            if (value) next.set('bsml_account_value', value); else next.delete('bsml_account_value');
            update(next, replace);
        }
        function open(id) {
            browseScroll.current = window.scrollY;
            history.replaceState(Object.assign({}, history.state, {bsmlScroll: window.scrollY}), '', location.href);
            const next = new URLSearchParams(location.search); next.set('bsml_clearing', id); update(next);
            requestAnimationFrame(() => heading.current && heading.current.scrollIntoView({block: 'start'}));
        }
        function back() {
            restoreScroll.current = browseScroll.current;
            const next = new URLSearchParams(location.search); next.delete('bsml_clearing'); update(next); refresh();
        }
        if (!tab) return h('p', {className: 'bsml-message'}, 'Your library is being prepared. Please check back soon.');
        function onLoaded() {
            if (restoreScroll.current !== null) {
                const y = restoreScroll.current; restoreScroll.current = null;
                requestAnimationFrame(() => window.scrollTo(0, y));
            }
        }
        const props = {tab, query, change, revision, refresh, open, onLoaded};
        return h('div', {className: 'bsml-shell'},
            h('aside', {className: 'bsml-sidebar'}, h('div', {className: 'bsml-brand'}, h('span', {className: 'bsml-eyebrow'}, 'A SPACE FOR YOUR GROWTH'), h('strong', null, 'My Library')),
                h('nav', {'aria-label': 'Library sections', className: 'bsml-desktop-nav'}, tabs.map((item, index) => h(MenuItem, {key: item.id, item, index, active: item.id === tab.id, childId: child && child.id, navigate}))),
                h('label', {className: 'bsml-mobile-nav'}, h('span', {className: 'bsml-sr'}, 'Library section'), h('select', {value: tab.id + (child ? '/' + child.id : ''), onChange: e => { const parts = e.target.value.split('/'); if (navigate(parts[0], parts[1])) e.target.value = tab.id + (child ? '/' + child.id : ''); }}, tabs.flatMap(item => [h('option', {value: item.id, key: item.id}, item.label + (item.newTab ? ' ↗' : ''))].concat((item.children || []).map(sub => h('option', {value: item.id + '/' + sub.id, key: item.id + '/' + sub.id}, '— ' + sub.label + (sub.newTab ? ' ↗' : '')))))))),
            h('main', {className: 'bsml-main'}, h('header', {className: 'bsml-header'}, h('div', null, h('span', {className: 'bsml-eyebrow'}, clearing ? 'YOUR VIEWING SPACE' : 'WELCOME TO YOUR COLLECTION'), h('h1', {ref: heading, tabIndex: -1}, display.label)), h('button', {type: 'button', className: 'bsml-refresh', onClick: refresh}, 'Refresh')),
                clearing ? h('section', {className: 'bsml-viewer'}, h('div', {className: 'bsml-viewer-nav'}, h('button', {type: 'button', onClick: back}, '← Back to ' + tab.label), h('a', {href: config.embed + (config.embed.includes('?') ? '&' : '?') + 'post_type=clearing&p=' + clearing, target: '_blank', rel: 'noopener'}, 'Open page ↗')), h(Frame, {kind: 'clearing', id: clearing, revision})) :
                    tab.type === 'account' ? h(Account, {key: tab.id, tab, endpoint: query.get('bsml_account_endpoint') || (child && child.id) || '', value: query.get('bsml_account_value') || '', revision, onNavigate: navigateAccount}) :
                    display.type === 'content' ? h(CustomContent, {key: tab.id + '/' + (child ? child.id : ''), section: tab.id, child: child && child.id, revision}) :
                    display.type === 'page' && display.newTab ? h('p', {className: 'bsml-message'}, h('a', {href: display.url, target: '_blank', rel: 'noopener noreferrer'}, 'Open ' + display.label + ' in a new tab ↗')) :
                    display.type === 'page' && display.contentOnly !== false ? h(CustomContent, {key: tab.id + '/' + (child ? child.id : ''), section: tab.id, child: child && child.id, revision}) :
                    display.type === 'page' ? h(Frame, {kind: 'section', id: tab.id + (child ? '/' + child.id : ''), source: display.url, title: display.label, revision}) :
                    tab.type === 'membership' ? h(Membership, Object.assign({key: tab.id}, props)) : tab.type === 'wishlist' ? h(List, Object.assign({key: tab.id, kind: 'wishlist', prefix: 'wishlist', title: 'Your saved products'}, props)) : h(wp.element.Fragment, {key: tab.id},
                        h(List, Object.assign({kind: 'library', prefix: 'library', title: 'Available in your library'}, props)),
                        tab.show_related !== false && h(List, Object.assign({kind: 'related', prefix: 'related', title: 'Explore more'}, props)))));
    }
    document.querySelectorAll('.bsml-root').forEach(root => createRoot(root).render(h(App)));
})(window.wp, window.BSML);
