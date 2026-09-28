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
    function List({kind, prefix, title, tab, query, change, revision, refresh, open, benefit, selection, toggle, remaining, blocked, onLoaded}) {
        const search = query.get('bsml_' + prefix + '_q') || '';
        const sort = query.get('bsml_' + prefix + '_sort') || tab.sort || 'newest';
        const page = query.get('bsml_' + prefix + '_page') || '1';
        const term = query.get('bsml_' + (kind === 'related' ? 'library' : prefix) + '_term') || '0';
        const params = new URLSearchParams({tab: tab.id, kind, search, sort, page, term});
        if (benefit) params.set('benefit', benefit);
        const result = useResource('list?' + params, revision);
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
            h('div', {className: 'bsml-section-heading'}, h('h2', null, title), result.data && h('span', {className: 'bsml-count', role: 'status', 'aria-live': 'polite'}, result.data.total + ' items')),
            h(Toolbar, {prefix, query, change, product, defaultSort: tab.sort}),
            result.data && kind !== 'related' && result.data.filters.length > 0 && h('div', {className: 'bsml-filters', 'aria-label': 'Category filters'},
                [{id: 0, label: 'All'}].concat(result.data.filters).map(filter => h('button', {key: filter.id, type: 'button', 'aria-pressed': Number(term) === filter.id, onClick: () => change(prefix, {term: filter.id, page: 1})}, filter.label))),
            wishError && h(ErrorBox, {error: wishError, retry: () => { setWishError(null); refresh(); }}),
            result.error ? h(ErrorBox, {error: result.error, retry: result.retry}) : result.loading ? h(Skeleton) : result.data && h(wp.element.Fragment, null,
                result.data.items.length === 0 ? h('div', {className: 'bsml-empty'}, h('h3', null, search || term !== '0' ? 'No matching items' : 'Nothing here yet'), h('p', null, result.data.configured === false ? 'This collection is being prepared. Please check back soon.' : kind === 'related' ? 'There are no additional recommendations in this collection.' : kind === 'wishlist' ? 'Save products to your wishlist while exploring the library.' : 'Try another category or search, or explore your other collections.')) :
                    h('div', {className: 'bsml-grid'}, result.data.items.map(item => h('article', {className: 'bsml-card' + (selection && selection.includes(item.id) ? ' is-selected' : ''), key: item.id},
                        item.image ? h('img', {src: item.image, alt: '', loading: 'lazy', decoding: 'async'}) : h('div', {className: 'bsml-image-placeholder', 'aria-hidden': true}, '✧'),
                        h('div', {className: 'bsml-card-body'}, h('h3', null, item.title), item.event && h('p', {className: 'bsml-meta'}, item.event),
                            product && h('div', {className: 'bsml-price', dangerouslySetInnerHTML: {__html: item.priceHtml}}),
                            kind === 'benefit' ? h('button', {type: 'button', className: 'bsml-button', disabled: blocked || (!selection.includes(item.id) && selection.length >= remaining), 'aria-pressed': selection.includes(item.id), onClick: () => toggle(item.id)}, selection.includes(item.id) ? 'Selected ✓' : 'Select') :
                                item.clearing ? h('button', {type: 'button', className: 'bsml-button', onClick: () => open(item.clearing)}, 'Open clearing') : h('a', {className: 'bsml-button', href: item.url}, 'View details'),
                            product && config.wishlist && h('button', {type: 'button', className: 'bsml-wish', disabled: wishBusy !== null, onClick: () => wishlist(item), 'aria-label': (item.wishlisted ? 'Remove ' : 'Save ') + item.title + (item.wishlisted ? ' from wishlist' : ' to wishlist')}, wishBusy === item.id ? 'Updating…' : item.wishlisted ? '♥ Remove from wishlist' : '♡ Save to wishlist'))))),
                h(Pagination, {data: result.data, prefix, change})));
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
    function Frame({kind, id, revision}) {
        const ref = useRef(null);
        const [height, setHeight] = useState(600);
        const [loaded, setLoaded] = useState(false);
        const [slow, setSlow] = useState(false);
        const url = new URL(config.embed);
        url.searchParams.set('bsml_embed', kind);
        if (id) url.searchParams.set('clearing_id', id);
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
        useEffect(function () { setLoaded(false); setSlow(false); const timer = setTimeout(() => setSlow(true), 12000); return () => clearTimeout(timer); }, [kind, id, revision]);
        return h('div', {className: 'bsml-frame-wrap'}, !loaded && h('p', {role: 'status'}, slow ? 'The content is taking longer to load. You can use the open-page link above.' : 'Loading content…'),
            h('iframe', {key: kind + '-' + id + '-' + revision, ref, src: url.href, title: kind === 'clearing' ? 'Clearing content and video' : 'Accelerator appointment', className: 'bsml-frame', style: {height: height + 'px'}, allow: 'fullscreen; picture-in-picture; encrypted-media', onLoad: () => setLoaded(true)}));
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
                h('div', {className: 'bsml-membership-intro'}, h('span', {className: 'bsml-eyebrow'}, 'YOUR MEMBERSHIP'), h('h2', null, state.data.tier || 'Explore VIP membership'), h('p', null, state.data.tier ? 'Choose something meaningful for your next step.' : 'Your account does not currently have an eligible membership tier.')),
                state.data.tier && h('div', {className: 'bsml-benefits'}, ['live', 'replay'].map(key => h('div', {className: 'bsml-benefit', key}, h('span', null, state.data.benefits[key].label), h('strong', null, state.data.benefits[key].remaining), h('span', null, 'of ' + state.data.benefits[key].limit + ' remaining'))),
                    state.data.appointment.eligible && h('div', {className: 'bsml-benefit'}, h('span', null, 'Accelerator session'), h('strong', {className: 'bsml-benefit-status'}, state.data.appointment.booked ? 'Booked' : 'Available'))),
                state.data.pending && h('p', {className: 'bsml-message', role: 'status'}, 'A claim is awaiting confirmation. Refresh your benefits or contact support before making another selection.'),
                message && h('p', {className: 'bsml-message' + (message.ok ? '' : ' bsml-error'), role: message.ok ? 'status' : 'alert'}, message.text),
                state.data.tier && ['live', 'replay'].map(key => h('div', {key},
                    h(List, Object.assign({}, props, {kind: 'benefit', prefix: key, title: 'Choose your ' + state.data.benefits[key].label.toLowerCase(), benefit: key, selection: selection[key], remaining: state.data.benefits[key].remaining, blocked: claiming || state.data.pending,
                        toggle: id => setSelection(previous => Object.assign({}, previous, {[key]: previous[key].includes(id) ? previous[key].filter(value => value !== id) : previous[key].concat(id)}))})),
                    h('div', {className: 'bsml-claim-bar'}, h('span', null, selection[key].length + ' selected · ' + state.data.benefits[key].remaining + ' remaining'), h('button', {type: 'button', className: 'bsml-button', disabled: claiming || state.data.pending || !selection[key].length || selection[key].length > state.data.benefits[key].remaining, onClick: () => claim(key)}, claiming ? 'Confirming…' : 'Claim selected items')),
                    state.data.benefits[key].remaining === 0 && h('p', {className: 'bsml-meta'}, 'Your allowance renews after your next payment is processed and synchronized.'))),
                state.data.appointment.eligible && h('section', {className: 'bsml-section'}, h('h2', null, 'Your accelerator session'), h(Frame, {kind: 'appointment', revision}))),
            h(History, {query, change, revision, open}));
    }
    function App() {
        const [query, setQuery] = useState(() => new URLSearchParams(location.search));
        const [revision, setRevision] = useState(0);
        const heading = useRef(null);
        const browseScroll = useRef(0);
        const restoreScroll = useRef(null);
        const tabs = config.tabs;
        const tab = tabs.find(t => t.id === query.get('bsml_tab')) || tabs.find(t => t.id === config.defaultTab) || tabs[0];
        const clearing = Number(query.get('bsml_clearing')) || 0;
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
        function navigate(id) {
            const next = new URLSearchParams(location.search);
            Array.from(next.keys()).filter(key => key.startsWith('bsml_')).forEach(key => next.delete(key));
            next.set('bsml_tab', id); update(next); refresh();
            requestAnimationFrame(() => heading.current && heading.current.focus());
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
                h('nav', {'aria-label': 'Library sections', className: 'bsml-desktop-nav'}, tabs.map((item, index) => h('button', {key: item.id, type: 'button', 'aria-current': item.id === tab.id ? 'page' : undefined, onClick: () => navigate(item.id)}, h('span', {className: 'bsml-nav-number', 'aria-hidden': true}, String(index + 1).padStart(2, '0')), item.label))),
                h('label', {className: 'bsml-mobile-nav'}, h('span', {className: 'bsml-sr'}, 'Library section'), h('select', {value: tab.id, onChange: e => navigate(e.target.value)}, tabs.map(item => h('option', {value: item.id, key: item.id}, item.label))))),
            h('main', {className: 'bsml-main'}, h('header', {className: 'bsml-header'}, h('div', null, h('span', {className: 'bsml-eyebrow'}, clearing ? 'YOUR VIEWING SPACE' : 'WELCOME TO YOUR COLLECTION'), h('h1', {ref: heading, tabIndex: -1}, tab.label)), h('button', {type: 'button', className: 'bsml-refresh', onClick: refresh}, 'Refresh')),
                clearing ? h('section', {className: 'bsml-viewer'}, h('div', {className: 'bsml-viewer-nav'}, h('button', {type: 'button', onClick: back}, '← Back to ' + tab.label), h('a', {href: config.embed + (config.embed.includes('?') ? '&' : '?') + 'post_type=clearing&p=' + clearing, target: '_blank', rel: 'noopener'}, 'Open page ↗')), h(Frame, {kind: 'clearing', id: clearing, revision})) :
                    tab.type === 'membership' ? h(Membership, Object.assign({key: tab.id}, props)) : tab.type === 'wishlist' ? h(List, Object.assign({key: tab.id, kind: 'wishlist', prefix: 'wishlist', title: 'Your saved products'}, props)) : h(wp.element.Fragment, {key: tab.id},
                        h(List, Object.assign({kind: 'library', prefix: 'library', title: 'Available in your library'}, props)),
                        h(List, Object.assign({kind: 'related', prefix: 'related', title: 'Explore more'}, props)))));
    }
    document.querySelectorAll('.bsml-root').forEach(root => createRoot(root).render(h(App)));
})(window.wp, window.BSML);
