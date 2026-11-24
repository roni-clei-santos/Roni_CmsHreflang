# QA & Requirement Traceability Matrix — `Roni_CmsHreflang`

This document maps every functional, architectural, multi-store, caching (FPC / Varnish / Fastly), security and Adobe Commerce Marketplace (EQP) requirement of **`Roni_CmsHreflang` (`roni/module-cms-hreflang`)** to its automated tests (PHPUnit, static analysis, Playwright E2E) and step-by-step manual verification procedures.

---

## 1. Automated Quality Gates (CLI)

Run from the module root (`/Users/roneclaybrasil/workspace/m2/src/app/code/Roni/CmsHreflang`):

```bash
# [ ] Gate 1 — Blacklist (0 debug leftovers, 0 ObjectManager, 0 raw superglobals)
rtk php dev/ci/blacklist.php

# [ ] Gate 2 — 1:1 Unit Test Mirroring (every production class has a matching Test/Unit/*Test.php)
rtk php dev/ci/check-tests-exist.php

# [ ] Gate 3 — Multi-Store Scope Enforcement (every scopeConfig read passes an explicit scope)
rtk php dev/ci/check-scope-config.php

# [ ] Gate 4 — Strict Composer Schema Validation
rtk composer validate --strict

# [ ] Gate 5 — PHPCS Magento2 Standard (0 rule exclusions, warning-severity=1, PHP + PHTML + XML)
rtk bash dev/ci/in-container.sh vendor/bin/phpcs

# [ ] Gate 6 — PHPMD (Magento Ruleset) + PHPCPD (5 lines / 70 tokens) + PHPStan Level 9
rtk bash dev/ci/in-container.sh composer run-script phpmd
rtk bash dev/ci/in-container.sh bash dev/ci/phpcpd.sh Api Block Model ViewModel
rtk bash dev/ci/in-container.sh vendor/bin/phpstan analyse --memory-limit=1G --no-progress

# [ ] Gate 7 — PHPUnit Unit Tests + Per-File Coverage Gate (>= 70% required, 100% achieved)
rtk bash dev/ci/in-container.sh vendor/bin/phpunit --testdox

# [ ] Gate 8 — Adobe Commerce Marketplace Submission Package Builder & Verifier
rtk php dev/ci/package-marketplace.php 2.0.0
```

---

## 2. Requirement-to-Test Traceability Matrix

| Req ID | Requirement Description | Automated Test Coverage | Manual QA Verification (`https://m2.localhost/`) |
| :--- | :--- | :--- | :--- |
| **CH-01** | **CMS Home Page (`/`) Alternates & `x-default`**<br>The CMS home page must emit `<link rel="alternate" hreflang="...">` for every active store view that has an active home page configured, using the store view's base URL (never appending `/home`), plus `x-default` pointing to the current website's default store view. | `Test/Unit/Model/HreflangProviderTest::testHomeUsesTheBaseUrlAndTheHomePageOfEachStoreView`<br>`Test/Unit/ViewModel/HreflangTest::testReturnsTheHomeAlternatesOnTheCmsHomePage`<br>`e2e/hreflang.spec.js` (`CMS home page`) | 1. Run:<br>`curl -sk https://m2.localhost/ \| rtk grep 'rel="alternate"'`<br>2. Confirm `<head>` contains:<br>`<link rel="alternate" hreflang="en-US" href="https://m2.localhost/"/>`<br>`<link rel="alternate" hreflang="x-default" href="https://m2.localhost/"/>` |
| **CH-02** | **Standard CMS Pages (`/about-us`, `/privacy-policy-cookie-restriction-mode`)**<br>Any active CMS page viewed at its own URL (`cms_page_view`) must emit reciprocal `hreflang` links for every active store view serving that identifier, plus `x-default`. | `Test/Unit/Model/HreflangProviderTest::testBuildsOneAlternatePerLocaleAndXDefault`<br>`Test/Unit/ViewModel/HreflangTest::testReturnsThePageAlternatesOnACmsPage`<br>`e2e/hreflang.spec.js` (`page for All Store Views`) | 1. Open `https://m2.localhost/privacy-policy-cookie-restriction-mode` (or `/about-us`).<br>2. Inspect page source (`Cmd+Option+U`) and verify the `<link rel="alternate" hreflang="..." href="..."/>` tags match the page URL. |
| **CH-03** | **No Alternates on 404 (`cms_noroute_index`) or Non-Page Actions**<br>Although Magento's 404 route adds the `cms_page_view` layout handle, no `hreflang` tags may be emitted on 404 responses or when the page identifier is empty. | `Test/Unit/ViewModel/HreflangTest::testEmitsNothingOnThe404PageOrAnyOtherAction`<br>`Test/Unit/ViewModel/HreflangTest::testEmitsNothingWhenThePageHasNoIdentifier`<br>`e2e/hreflang.spec.js` (`withoutAlternates`) | 1. Request a non-existent URL:<br>`curl -sk https://m2.localhost/non-existent-404-page \| rtk grep 'rel="alternate"'`<br>2. Verify output is empty (0 tags emitted). |
| **CH-04** | **"Add Store Code to Urls" Compatibility (On & Off)**<br>URLs must come from `Store::getBaseUrl()`, which already includes the store code when `web/url/use_store = 1`. The module must never duplicate the store code (`/pt/pt/about-us`). | `Test/Unit/Model/HreflangProviderTest`<br>`e2e/hreflang.spec.js` (runs twice: `QA_URL_MODE=off` and `QA_URL_MODE=on`) | 1. Enable *Add Store Code to Urls* in `Stores > Configuration > General > Web > Url Options`.<br>2. Flush cache and verify `<link rel="alternate">` contains the store code once (`/default/...`), returning HTTP 200. |
| **CH-05** | **Per-Language CMS Pages Sharing an Identifier (`contact-us`)**<br>When separate CMS page entities share the same URL key (`identifier`) across store views, a store-specific assignment wins over `All Store Views (0)`, linking translated pages to each other. | `Test/Unit/Model/PageResolverTest::testAStoreSpecificPageWinsOverAllStoreViews`<br>`Test/Unit/Model/PageResolverTest::testMakesOneQueryPerDistinctIdentifier`<br>`e2e/hreflang.spec.js` (`one page per language with the same identifier`) | 1. Create two CMS pages with identifier `contact-us` assigned to different store views.<br>2. Verify both store views link to each other and expose both `cms_p_<id>` cache tags. |
| **CH-06** | **Exclusion of Disabled Store Views & Inactive CMS Pages**<br>Disabled store views (`is_active = 0`) and disabled CMS pages (`is_active = 0`) must never be listed in `hreflang` tags, and `x-default` must be omitted if the default store view does not serve the page. | `Test/Unit/Model/StoreSelectorTest::testSkipsInactiveStores`<br>`Test/Unit/Model/PageResolverTest::testLeavesOutStoreViewsWhereNoPageAnswers`<br>`Test/Unit/Model/HreflangProviderTest::testOmitsXDefaultWhenTheDefaultStoreViewDoesNotServeThePage` | 1. Disable a CMS page in `Content > Elements > Pages`.<br>2. Verify it is excluded from alternates and returns 404 with no `hreflang` tags. |
| **CH-07** | **Locale Deduplication & Multi-Website Scope (`include_other_websites`)**<br>Each `hreflang` value (`pt-BR`, `en-US`, etc.) appears at most once. Store views of other websites are excluded by default and included (after the current website) only when `Stores > Configuration > Roni > CMS Hreflang > General > Include store views of other websites` is `Yes` at website scope. | `Test/Unit/Model/ConfigTest::testReadsIncludeOtherWebsitesAtWebsiteScope`<br>`Test/Unit/Model/StoreSelectorTest::testIncludesOtherWebsitesWhenTheSettingIsOnCurrentWebsiteFirst`<br>`Test/Unit/Model/HreflangProviderTest::testKeepsTheFirstStoreViewWhenTwoShareALocale`<br>`e2e/hreflang.spec.js` (`admin: include store views of other websites`) | 1. Log into `https://m2.localhost/admin`.<br>2. Navigate to `Stores > Configuration > Roni > CMS Hreflang > General`.<br>3. Switch scope to a Website and toggle `Include store views of other websites`. |
| **CH-08** | **Full Page Cache (Varnish / Fastly) Tag Invalidation & XSS Escaping**<br>`Block\Hreflang` implements `IdentityInterface` and returns `cms_p_<id>` for every linked CMS page. Template output uses `$escaper->escapeHtmlAttr()` and `$escaper->escapeUrl()`. | `Test/Unit/Block/HreflangTest::testHandsOverTheCacheTagsOfItsViewModel`<br>`Test/Unit/ViewModel/HreflangTest::testReturnsUniqueCacheTagsOfThePagesBehindTheAlternates` | 1. Edit and save an active CMS page in the Admin.<br>2. Verify the cached page refreshes automatically without a full cache flush. |

---

## 3. Unit Test Inventory (41 Tests / 58 Assertions)

| Test Class | Tests | Production Class Under Test |
| :--- | :---: | :--- |
| `Test/Unit/Model/AlternateTest.php` | 2 | `Roni\CmsHreflang\Model\Alternate` |
| `Test/Unit/Model/ConfigTest.php` | 9 | `Roni\CmsHreflang\Model\Config` |
| `Test/Unit/Model/StoreSelectorTest.php` | 7 | `Roni\CmsHreflang\Model\StoreSelector` |
| `Test/Unit/Model/PageResolverTest.php` | 8 | `Roni\CmsHreflang\Model\PageResolver` |
| `Test/Unit/Model/HreflangProviderTest.php` | 8 | `Roni\CmsHreflang\Model\HreflangProvider` |
| `Test/Unit/ViewModel/HreflangTest.php` | 5 | `Roni\CmsHreflang\ViewModel\Hreflang` |
| `Test/Unit/Block/HreflangTest.php` | 2 | `Roni\CmsHreflang\Block\Hreflang` |
