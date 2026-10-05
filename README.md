# BS My Library

A WordPress member library using WordPress's `wp.element` React runtime and `wp.apiFetch`. No separate React bundle, build step, or CDN dependency is needed. The source JavaScript uses `createElement` directly and is ready to deploy.

## Requirements and setup

- WordPress 6.2+, PHP 7.4+, WooCommerce, Connector Wizard.
- SA Custom Update supplies the `clearing` post type, `topic` taxonomy, date/video rendering helpers, and product/content links.
- WebToffee Wishlist is required for wishlist features; other sections work without it.
- Activate **BS My Library**, then visit **Settings → My Library**.
- Place `[bs_my_library]` on the desired page. Add its page ID in settings, particularly for page builders where the shortcode is not stored in normal post content.
- Exclude this page and all `bsml/v1` REST endpoints and `bsml_embed` requests from page caching/CDN caching. The plugin sends private `no-store` headers and sets `DONOTCACHEPAGE`. A cache serving a page before WordPress executes must be excluded in that cache's configuration.

The eleven requested menu items are seeded in order. Set included clearing terms and related product categories for every standard section. Empty inclusion lists intentionally show no items; they do not expose the entire catalog. My Purchased is a standard taxonomy section, per the agreed specification.

Live GEC starts with product category 156. Replay categories are intentionally unset: category 99 was clarified as Live GEC, so no replay category is guessed. Set the replay category/categories and exclusions before launch. Included and excluded descendants have separate controls. A tier can override the shared benefit category scope.

Each standard tab has a unique stable ID, editable label, type, enable flag, taxonomy, included/excluded terms, descendant switches, optional ordered menu terms, additional related categories/exclusions, default sort, and optional subcategory-to-product-category mapping. Related products combine the selected topic's name-matched `product_cat` category with all manually added categories (OR, with duplicate products removed). Names match exactly after trimming and case normalization. Duplicate names are resolved by the full parent-name hierarchy; ambiguous matches are skipped. “All” matches the tab's included topics and permitted descendants. An explicit mapping, e.g. `{"12":[34,56]}`, replaces automatic matching for topic 12, while additional categories still apply. An empty mapping disables automatic matching for that topic only. Product exclusions and descendant settings apply to the combined categories.

Non-empty category filters appear in a separate **Browse by category** section after each clearing/claimable list's pagination, including on single-page lists. Selecting a category resets pagination and returns focus and scroll to the updated results, respecting reduced-motion preferences.

Settings are grouped into General, Library Sections, Membership, Appearance, and Claim Management. Taxonomy choices are limited to Topic (`topic`) and Program Categories (`ld_course_category`, associated with `sfwd-courses`); Topic queries `clearing` posts; Program Categories queries `sfwd-courses`. Both use the configured terms and Connector Wizard access checks. Course cards link to their native LearnDash page using “Open program”, preserving the course navigation and enrollment flow. Term selections reload without saving when taxonomy changes; old term selections and mapping overrides are cleared only after a successful reload. Category-menu terms can be searched, selected, and reordered.

Each standard section has **Show related products** and **Show category menu** switches. Both remain enabled for preexisting sections. Disabled recommendations are neither requested by the frontend nor available through that section's list endpoint. Hiding the category menu ignores stale URL filters while preserving the configured content scope.

**WordPress Page** sections select an existing page; **Custom Content** sections use a Visual/Text editor with shortcode support. Both support one level of independently configurable page/content submenu items, with editable labels, ordering, and enabled status. The desktop parent opens its content and slides open its submenu; the arrow toggles the submenu independently. Mobile navigation includes submenu choices. Browser history and direct section/submenu links are supported.

WordPress Page sections open in the same-origin viewer and use normal WordPress content filters. Custom Content is fetched from the authenticated, no-store `/bsml/v1/content` endpoint and inserted directly into the library panel without an iframe. It uses block rendering, paragraph formatting and shortcode execution without the global `the_content`, `wp_head` or `wp_footer` hooks. Enqueued styles and scripts are returned separately; external script dependencies load once and inline initialization runs after content insertion. The panel emits `bsml:content-ready` and `bsml:content-unmount` events for shortcode integrations. Shortcodes that initialize only on a full page load or rely on global page hooks may require an explicit integration. Page content requires publication, Connector Wizard access, and any page password; disabled or unknown section/submenu IDs are rejected. Custom section content is available to logged-in members. Saved content is not included in the initial navigation configuration. Embedding the library shortcode inside content is suppressed to prevent recursion. Builder-specific templates and third-party shortcodes should be checked with the actual selected pages; the viewer renders page content, not an entire theme page template.

Brand defaults come from existing local styles: burgundy `#611203`, gold `#efcb82`, peach `#fad5bb`. They are editable; typography inherits the theme.

## Member experience

- Asynchronous sections, filters, full-dataset search, sorting, and pagination.
- Separate search/sort/page controls for accessible clearings and related products.
- Related product images link to product details. Cart buttons are rendered by `woocommerce_template_loop_add_to_cart()`, honoring the site's template overrides, argument/HTML filters, and each product extension's own URL, markup, and AJAX policy. WooCommerce's native delegated handler processes supported AJAX buttons. Only its successful `added_to_cart` event changes the visible label to “Added to cart”; custom wrappers and icons remain intact. Configurable bundles, variable products, and other product types retain their native action instead of being forced into the simple-product flow. The local test environment does not contain the live bundle extension; custom-product and extension-filter fixtures verify preservation, while the actual bundle flow requires a check where that extension is installed.
- Product price sorting, title/date sorting, and event-date sorting. Undated items follow dated items.
- URL-backed navigation, browser history, and restoration of browse filters and scroll after viewing.
- Mobile section selector, loading skeletons, focus states, reduced-motion support, empty/error states, and retry controls.
- Wishlist cards read the current member's WebToffee table without its cached getter; mutations use WebToffee's existing authenticated AJAX endpoint. No second wishlist is stored. Variation entries are represented by their parent product, matching the plugin endpoint's product-wide removal behavior.

## Access and purchase rules

Only published `clearing` posts permitted by `hlwpw_has_access()` are returned. Every viewer request checks access again. Administrators inherit Connector Wizard's administrator bypass, so use ordinary member accounts when verifying permissions.

A product is considered purchased when **any** of its `hlwpw_location_tags` matches through Connector Wizard's tag checker. Products with accessible linked clearings are also excluded from recommendations and claims. Both `_sa_related_clearing` and the reverse `_sa_related_product` mapping are recognized.

There is no persistent plugin response cache, frontend result cache, service worker, or localStorage content cache. Each visit, search, sort, filter, page change, and explicit refresh makes fresh requests. Relationship data is batched in request-local memory; WordPress's ordinary metadata loading is used. Connector Wizard remains responsible for synchronization freshness. Opening the library does not fetch GHL on every search keystroke.

Candidate IDs are processed in batches, access-filtered, then sorted and paginated. This guarantees correct accessible totals and full-dataset searching. For very large catalogs, benchmark on production-sized data: exact personalized counts require evaluating all matching candidates.

## Inline clearing and appointment rendering

The viewing area uses a same-origin iframe, so the outer library page never reloads. It reuses the existing single-clearing template's rendering sequence: title, `_sa_display_clearing_event_date_time()`, `_sa_display_clearing_video()`, and `the_content()`.

The frame renders content before `wp_head`/`wp_footer`, allowing player and shortcode assets to enqueue normally. Gumlet, Publitio, and Presto Player keep their own document lifecycle. Closing/replacing the frame destroys its video document. Header/footer chrome is omitted; supporting scripts still load. This is intentionally an isolated viewer rather than inserting shortcode HTML and hoping its scripts execute.

Appointment content uses two admin editors (available/booked), including shortcode support. The plugin reads `membership appointment booked`; GHL handles booking and appointment tags. It does not create accelerator appointments or schedule resets.

## Membership and claims

| Tier tag | Live | Replay | Appointment |
|---|---:|---:|---|
| `level-1` | 1 | 1 | No |
| `level-2` | 2 | 2 | No |
| `level-3` | 2 | 3 | Yes |

Highest configured tier wins when several tags match. No tier means no claims. Allowances and tier tags are editable.

Count tags are `1` through `4 membership_live_gc_added` and `1` through `4 membership_replay_added`. Existing count tags are retained; the highest present count is usage. When Connector Wizard receives a valid synchronized tag set without those tags, remaining allowances reset immediately. Failed/missing/flagged-for-sync contact data never becomes an empty-tag reset. No payment identifier or calendar reset is used.

Claims have:

1. Authentication and REST nonce protection.
2. Tier, allowance, scope, exclusion, visibility, duplicate-ID, existing-access, purchase-tag, and item-mapping checks.
3. A MySQL connection-owned lock per member, preventing concurrent submissions through this plugin.
4. A durable pending row and unique user/request key before applying GHL tags.
5. One Connector Wizard add-tags request containing content-access tags and the resulting count tag.
6. Confirmation only after success. Ambiguous failures remain pending and block further claims.

Pending rows are reconciled when all expected tags appear in synchronized GHL data. Admins can resolve pending records after verifying the GHL contact. “Not applied” must not be used unless tags were never granted or have been corrected in GHL. Admin resolution is recorded in user metadata.

GHL count tags cannot encode which item was claimed elsewhere, nor identify payment cycles that happened entirely between synchronization observations. Counts always follow GHL; the immutable local history records claims initiated here. Existing `bs_membership_claimed_items` entries are displayed without destructive migration. Current allowances are derived from tags, so resetting them never deletes history or removes content access.

The plugin does not invoke the old `memb_apply_tag_ids()` helper because it mixes tag application with appointment side effects and does not expose a reliable success result. The hook `bsml_claim_confirmed($user_id, $product_ids, $benefit_key, $claim_id)` is available for separately defined post-claim integrations. Existing legacy claim pages should be retired from member navigation at rollout: they do not share this plugin's submission lock and validation.

## Verification

PHP lint and JavaScript syntax checks:

```sh
find . -name '*.php' -exec php -l {} \;
node --check assets/library.js
node --check assets/admin.js
```

`tests/wp-smoke.php` runs read-only checks in the parent WordPress installation. `tests/wp-integration.php` creates then removes its own fixture member, terms, and products and mocks all external HTTP. It exercises real WordPress/Connector Wizard/WooCommerce code, including access-before-pagination, purchase exclusions, duplicate submissions, cumulative tags, ambiguous failures, reset/history behavior, native cart templates, content-section saving, and page access restrictions. It also writes an admin HTML fixture to `/private/tmp/bsml-admin-fixture.html` for the browser test. Run only on a local/staging installation.

`tests/browser.cjs` serves isolated fixture responses and tests the actual frontend using WordPress's installed React/element scripts. Run the integration test first to generate the admin fixture. Browser checks also cover content submenus/history, disabled display options, taxonomy reloads, and submenu editing/reordering. It requires Playwright installed outside the plugin and a local Chrome executable (adjust the executable path for your machine).

The automated suite does not make real GHL claims or appointments. Before production rollout, configure categories and booking shortcodes and verify one normal member per tier with the actual hosted video/booking providers.

Deactivation preserves settings and claim history. There is intentionally no destructive uninstall routine.
