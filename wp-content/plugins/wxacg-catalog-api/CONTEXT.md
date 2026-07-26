# WXACG Catalog API — CONTEXT

## 1. Current status

- **Public product:** Weixiao Anime API
- **Chinese name:** 微笑動漫 API
- **WordPress plugin:** WXACG Catalog API
- **Plugin version:** `1.1.0-beta1`
- **Public API contract:** `2.0`
- **REST API v1 namespace:** `wxacg-catalog/v1`
- **REST API v2 namespace:** `wxacg-catalog/v2`
- **Repository:** <https://github.com/smaacg/hostingerphp8.3wordpress7.0/tree/main/wxacg-catalog-api>
- **Test environment:** <https://test.weixiaoacg.com/>
- **Validation date:** `2026-07-22`
- **Release stage:** Beta / test-site validation
- **Production deployment:** Not approved yet

This file describes the actual `wxacg-catalog-api` plugin.

It does not describe the separate `wxacg-api` plugin.

---

## 2. Product positioning

Weixiao Anime API is an Asian-first anime data API centred on Traditional Chinese.

The intended audience includes:

- Taiwan
- Hong Kong
- Mainland China
- Japan
- English-language users

The API focuses on:

- Traditional Chinese anime data
- Multilingual titles
- Partial and multilingual search
- Cross-platform external ID mapping
- Series information
- Production metadata
- Regional streaming information
- Stable versioned REST contracts
- Extensible source and verification data

---

## 3. Plugin responsibilities

### Anime Sync Pro

`anime-sync-pro` is the data synchronization and write layer.

It is responsible for:

- Anime and manga WordPress records
- AniList, MAL and Bangumi synchronization
- WordPress postmeta
- WordPress taxonomies
- Images, scores, seasons and series
- Production information
- Streaming information
- Administrative imports and updates

Do not move Anime Sync Pro write operations into WXACG Catalog API.

### WXACG Catalog API

`wxacg-catalog-api` is the public read-only API layer.

It is responsible for:

- REST API v1 compatibility
- REST API v2
- Anime list and detail responses
- Multilingual search
- Language fallback
- External ID mapping
- Pagination
- Request validation
- Unified v2 responses
- ETag and conditional HTTP requests
- Cache headers
- Legacy API compatibility

The current Beta reads existing WordPress CPT, postmeta and taxonomy data.

It does not yet use new catalog custom tables.

---

## 4. Requirements

- WordPress `6.4` or later
- PHP `8.0` or later
- Validated with PHP `8.3.31`
- WordPress REST API enabled
- Pretty permalinks enabled
- Anime Sync Pro data available

---

## 5. Current directory structure

```text
wxacg-catalog-api/
├── wxacg-catalog-api.php
├── uninstall.php
├── README.md
├── CONTEXT.md
├── includes/
│   ├── class-plugin.php
│   ├── class-cache.php
│   ├── class-catalog-query.php
│   ├── class-schema.php
│   ├── class-rest-controller.php
│   ├── class-compatibility.php
│   ├── class-language.php
│   └── class-response.php
├── v2/
│   ├── class-router.php
│   ├── class-anime-controller.php
│   ├── class-search-controller.php
│   ├── class-work-repository.php
│   └── class-anime-serializer.php
└── docs/
    ├── API.md
    └── openapi.yaml
```

Do not commit:

```text
*.bak-*
*.working-*
debug.log
error_log
```

---

## 6. Bootstrap

Main entry file:

```text
wxacg-catalog-api.php
```

Current plugin version constant:

```php
WXACG_CATALOG_API_VERSION = 1.1.0-beta1
```

The entry file:

- Defines plugin constants
- Registers the `Wxacg_Catalog_Api_` autoloader
- Supports classes in `includes/`
- Supports v2 classes in `v2/`
- Initializes the plugin on `plugins_loaded`
- Initializes cache version on activation
- Bumps cache when the installed plugin version changes
- Bumps cache on deactivation

Module composition is handled by:

```text
includes/class-plugin.php
```

---

## 7. REST API v1

Base namespace:

```text
/wp-json/wxacg-catalog/v1
```

v1 is a backward-compatible API and must not be broken by v2 development.

Important v1 endpoints:

```http
GET /items
GET /items/{id}
GET /search
GET /lookup
GET /taxonomies/{taxonomy}
GET /series/{slug}
```

Examples:

```text
/wp-json/wxacg-catalog/v1/items?type=anime&per_page=2
/wp-json/wxacg-catalog/v1/items/751
/wp-json/wxacg-catalog/v1/search?q=無職&per_page=5
/wp-json/wxacg-catalog/v1/lookup?anilist_ids=166873&type=anime
/wp-json/wxacg-catalog/v1/taxonomies/genre?per_page=5
/wp-json/wxacg-catalog/v1/series/mushoku-tensei-isekai-ittara-honki-dasu?page=1&per_page=2
```

Legacy compatibility route:

```text
/wp-json/weixiaoacg/v1/anime-url?ids=166873
```

### v1 accepted behavior

- Anime list returns published anime
- Detail endpoint returns published work data
- Multilingual title search works
- AniList lookup works
- Legacy anime URL route works
- Taxonomy `data` is a JSON array
- Taxonomy pagination works
- Series pagination works
- Series `root_anilist_id` is `null` when unavailable
- Invalid parameters return HTTP 400
- Missing published records return HTTP 404
- ETag conditional requests return HTTP 304

Do not redesign the v1 response envelope.

---

## 8. REST API v2

Base namespace:

```text
/wp-json/wxacg-catalog/v2
```

Current Beta endpoints:

```http
GET /anime
GET /anime/{id}
GET /search
```

Current test base URL:

```text
https://test.weixiaoacg.com/wp-json/wxacg-catalog/v2
```

The production v2 API is not released yet.

---

## 9. v2 Anime List

```http
GET /anime
```

Supported parameters:

```text
lang
page
per_page
genre
season
format
series
studio
tag
status
year
orderby
order
```

Pagination:

```text
Default page: 1
Default per_page: 20
Maximum per_page: 50
```

Supported ordering fields:

```text
date
modified
title
popularity
score
site_score
season_year
start_date
```

Supported order directions:

```text
ASC
DESC
```

Only published anime may be returned.

---

## 10. v2 Anime Detail

```http
GET /anime/{id}
```

Example:

```text
/wp-json/wxacg-catalog/v2/anime/751?lang=ja-JP
```

Optional detail fields may include:

```text
source
dates
duration
synopsis
images
trailer_url
popularity
taxonomies
production
streaming
themes
relations
characters
staff
episodes
```

Clients must tolerate optional fields being absent or null.

---

## 11. v2 Search

```http
GET /search
```

Required parameter:

```text
q
```

Rules:

- Search query cannot be empty
- Maximum search length is 100 characters
- Search supports partial Chinese matching
- Search supports Japanese titles
- Search supports English titles
- Search supports romanised titles
- Search results use the same summary serializer as Anime List

English/Romaji example:

```text
/wp-json/wxacg-catalog/v2/search?q=Mushoku&per_page=2&lang=zh-TW
```

URL-encoded Chinese example:

```text
/wp-json/wxacg-catalog/v2/search?q=%E7%84%A1%E8%81%B7&per_page=2&lang=zh-TW
```

Validated Chinese search results include WXACG IDs `743` and `745`.

---

## 12. Supported languages

```text
zh-TW
zh-HK
zh-CN
ja-JP
ja-Latn
en
```

Default language:

```text
zh-TW
```

Language is selected with:

```text
?lang=zh-TW
```

Title response structure:

```json
{
  "display": "無職轉生 第二季",
  "locale": "zh-TW",
  "requested_locale": "zh-HK",
  "official": {
    "zh-TW": "無職轉生 第二季",
    "zh-HK": null,
    "zh-CN": null,
    "ja-JP": "無職転生Ⅱ",
    "ja-Latn": "Mushoku Tensei II",
    "en": "Mushoku Tensei: Jobless Reincarnation II"
  },
  "aliases": []
}
```

Fallback rules:

- `requested_locale` records the requested language
- `locale` records the language used for `display`
- `display` may use a fallback title
- Missing official translations remain `null`
- Fallback must not fabricate official translations

---

## 13. External ID mapping

List, search and detail endpoints use the same structure:

```json
{
  "ids": {
    "wxacg": 751,
    "anilist": 166873,
    "mal": 55888,
    "bangumi": 444557,
    "tmdb": null
  }
}
```

Rules:

- `wxacg` is the WordPress/WXACG work ID
- External IDs must be positive integers
- Missing IDs are `null`
- Missing IDs are never represented as `0`
- Scores and years must not be interpreted as external IDs
- List, search and detail mappings must remain consistent

The v2 serializer first uses Schema `external_ids`.

When a summary record does not contain external IDs, the serializer may resolve known provider IDs from postmeta.

Most current records do not have TMDB mappings, so `tmdb: null` is valid.

---

## 14. v2 response envelope

Collection response:

```json
{
  "success": true,
  "code": 200,
  "message": "OK",
  "data": [],
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total": 221,
    "total_pages": 12
  },
  "meta": {
    "api_version": "2.0",
    "language": "zh-TW",
    "cached": false,
    "generated_at": "2026-07-22T22:00:00+00:00"
  }
}
```

Error response:

```json
{
  "success": false,
  "code": 400,
  "message": "不支援指定的語言。",
  "error": {
    "type": "invalid_parameter",
    "details": {
      "parameter": "lang"
    }
  },
  "meta": {
    "api_version": "2.0",
    "language": "zh-TW",
    "generated_at": "2026-07-22T22:00:00+00:00"
  }
}
```

Validated error cases:

- Invalid language: HTTP 400
- `per_page > 50`: HTTP 400
- Missing search query: HTTP 400
- Anime ID `0`: HTTP 400
- Missing published anime: HTTP 404

URLs that do not match a registered route may use the native WordPress `rest_no_route` response.

---

## 15. HTTP cache behavior

Successful responses may include:

```http
ETag: W/"..."
Cache-Control: public, max-age=60
X-WXACG-API-Version: 2.0
X-WXACG-Catalog-Version: 1.1.0-beta1
X-WP-Total: ...
X-WP-TotalPages: ...
```

Clients may send:

```http
If-None-Match: W/"..."
```

Unchanged responses return:

```http
HTTP/2 304
```

Validation errors use:

```http
Cache-Control: no-store, no-cache, must-revalidate
```

---

## 16. Authentication

Current Beta endpoints are public and read-only.

No API token is required.

API tokens, scopes and rate limiting are planned but are not implemented in this release.

---

## 17. Documentation

Project documentation:

```text
README.md
docs/API.md
docs/openapi.yaml
CONTEXT.md
```

OpenAPI version:

```text
2.0.0-beta1
```

Swagger test server:

```text
https://test.weixiaoacg.com/wp-json/wxacg-catalog/v2
```

Current OpenAPI paths:

```text
/anime
/anime/{id}
/search
```

The OpenAPI document has been loaded successfully by Swagger Editor.

---

## 18. Beta validation results

Validated environment:

```text
https://test.weixiaoacg.com/
```

Validated plugin header:

```http
X-WXACG-Catalog-Version: 1.1.0-beta1
```

Validated API header:

```http
X-WXACG-API-Version: 2.0
```

Validated endpoints:

- v1 Anime List: HTTP 200
- v2 Anime List: HTTP 200
- v2 Anime Detail: HTTP 200
- v2 English/Romaji Search: HTTP 200
- v2 Traditional Chinese Search: HTTP 200
- Invalid `per_page`: HTTP 400
- Conditional ETag request: HTTP 304

Typical cached response time:

```text
Approximately 0.18–0.35 seconds
```

Beta validation status:

```text
PHP syntax: PASSED
v1 regression: PASSED
v2 list: PASSED
v2 detail: PASSED
v2 multilingual search: PASSED
v2 external ID mapping: PASSED
v2 unified errors: PASSED
ETag 304: PASSED
Catalog API PHP errors: NONE
Fatal errors: NONE
Production deployment: PENDING
```

---

## 19. Known unrelated notices

The test site currently logs a WordPress notice for GamiPress:

```text
_load_textdomain_just_in_time
Translation loading for the gamipress domain was triggered too early
```

Installed GamiPress version observed during validation:

```text
7.9.9.1
```

This notice occurs during WordPress/WP-CLI initialization and is not generated by WXACG Catalog API.

It is not a Beta API blocker.

Historical Blocksy child-theme warnings referenced old line numbers. They were not reproduced after the debug log baseline was reset.

A single temporary Cloudflare HTTP 521 was observed during Alpha testing. The origin recovered, and it was not reproduced as a Catalog API PHP error.

---

## 20. Cache invalidation

Manual cache version bump:

```bash
wp eval '
Wxacg_Catalog_Api_Cache::bump_version();
echo "Catalog cache bumped\n";
'
```

Plugin update and deactivation also invalidate Catalog API cache.

Do not delete Anime Sync Pro data or unrelated WordPress transients.

`uninstall.php` may only delete options/transients owned by WXACG Catalog API.

---

## 21. Release restrictions

During Beta:

- Do not remove v1 routes
- Do not modify the v1 response contract
- Do not remove the legacy compatibility route
- Do not deploy v2 to production yet
- Do not modify Anime Sync Pro core synchronization yet
- Do not add custom tables in the same Beta release
- Do not add GraphQL
- Do not add Redis requirements
- Do not add API tokens or rate limiting
- Do not fabricate missing translations
- Do not return `0` for missing external IDs
- Do not commit backup files or debug logs

---

## 22. Beta observation

Beta observation begins after the test site returns:

```http
X-WXACG-Catalog-Version: 1.1.0-beta1
```

Observe for at least 24–72 hours.

Monitor:

- HTTP 200/400/404/304 behavior
- PHP Fatal and Parse errors
- Catalog API warnings
- Origin HTTP 521/500 errors
- Response time
- Cache invalidation
- ETag changes after data updates
- Cloudflare caching behavior
- Hostinger CPU, RAM, I/O and entry processes

Production deployment requires successful Beta observation.

---

## 23. Future roadmap

Later phases may include:

1. OpenAPI publication through Swagger UI or Redoc
2. Relations endpoint
3. External ID lookup endpoint
4. Characters and people
5. Staff and companies
6. Themes
7. Regional streaming endpoints
8. Sources and verification metadata
9. Multilingual title custom table
10. External ID custom table
11. Search index table
12. API tokens
13. Rate limiting
14. Redis cache backend
15. Optional GraphQL

Planned data tables may include:

```text
wp_wxacg_sources
wp_wxacg_titles
wp_wxacg_external_ids
wp_wxacg_relations
wp_wxacg_streaming
wp_wxacg_search_index
```

These tables are not part of `1.1.0-beta1`.

---

## 24. Source of truth

Code source:

```text
https://github.com/smaacg/hostingerphp8.3wordpress7.0/tree/main/wxacg-catalog-api
```

Runtime validation source:

```text
https://test.weixiaoacg.com/
```

The following must remain consistent:

- GitHub source
- Test-site files
- Plugin version header
- Runtime response headers
- README
- API documentation
- OpenAPI specification
- CONTEXT.md

If documentation and runtime behavior differ, verify the actual GitHub code and test-site API before updating documentation.
