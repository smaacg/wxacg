# Weixiao Anime API

**Weixiao Anime API（微笑動漫 API）** is an Asian-first anime data API centred on Traditional Chinese.

It provides multilingual anime titles, partial and multilingual search, cross-platform ID mapping, series information, production metadata and regional streaming information through the WordPress REST API.

> Current status: `1.1.0-beta1`
> API v2 status: Beta / test-site validation
> Production deployment: Not approved yet

## Product identity

| Purpose | Name |
|---|---|
| Public product | Weixiao Anime API |
| Chinese product name | 微笑動漫 API |
| Brand | Weixiao ACG |
| WordPress plugin | WXACG Catalog API |
| Repository directory | `wxacg-catalog-api` |
| REST API v1 | `wxacg-catalog/v1` |
| REST API v2 | `wxacg-catalog/v2` |

## Positioning

Weixiao Anime API focuses on:

- Traditional Chinese as the primary language
- Taiwan, Hong Kong, Mainland China, Japan and English-language audiences
- Multilingual official titles and aliases
- AniList, MyAnimeList, Bangumi and TMDB ID mapping
- Partial Chinese, Japanese, English and romanised search
- Regional streaming and licensing information
- Stable REST API versioning
- Traceable and extensible anime metadata

## Architecture

The current system separates data writing from public API access.

### Anime Sync Pro

Anime Sync Pro is the data synchronization and write layer. It manages:

- WordPress anime and manga records
- AniList, MAL and Bangumi synchronization
- Post metadata and taxonomies
- Images, scores, seasons and series
- Production and streaming information
- Administrative import and update workflows

### WXACG Catalog API

WXACG Catalog API is the read-only public API layer. It manages:

- REST API v1 compatibility
- REST API v2 responses
- Language selection and fallback
- Anime list and detail responses
- Multilingual search
- External ID mapping
- Pagination and validation
- ETag and HTTP caching
- Legacy API compatibility

The API currently reads existing WordPress CPT, postmeta and taxonomy data. Custom catalog tables are planned for a later development stage.

## Requirements

- WordPress `6.4` or later
- PHP `8.0` or later
- PHP `8.3` recommended
- Anime Sync Pro data source
- Pretty permalinks and WordPress REST API enabled

## Current plugin version

```text
1.1.0-beta1
```

Response header:

```http
X-WXACG-Catalog-Version: 1.1.0-beta1
```

API v2 response header:

```http
X-WXACG-API-Version: 2.0
```

## API base URLs

### Test environment

```text
https://test.weixiaoacg.com/wp-json/wxacg-catalog/v2
```

### v1 compatibility API

```text
https://test.weixiaoacg.com/wp-json/wxacg-catalog/v1
```

The production v2 API is not released yet.

## v2 endpoints

```http
GET /anime
GET /anime/{id}
GET /search
```

Examples:

```text
https://test.weixiaoacg.com/wp-json/wxacg-catalog/v2/anime?per_page=2&lang=zh-TW
```

```text
https://test.weixiaoacg.com/wp-json/wxacg-catalog/v2/anime/751?lang=ja-JP
```

```text
https://test.weixiaoacg.com/wp-json/wxacg-catalog/v2/search?q=Mushoku&per_page=2&lang=zh-TW
```

URL-encoded Traditional Chinese search:

```text
https://test.weixiaoacg.com/wp-json/wxacg-catalog/v2/search?q=%E7%84%A1%E8%81%B7&per_page=2&lang=zh-TW
```

## Supported languages

```text
zh-TW
zh-HK
zh-CN
ja-JP
ja-Latn
en
```

Default:

```text
zh-TW
```

Select a response language with the `lang` query parameter:

```text
?lang=zh-TW
?lang=zh-HK
?lang=ja-JP
?lang=en
```

When the requested title is unavailable, `title.display` may fall back to another language. Missing official translations remain `null` and are not fabricated from fallback values.

Example:

```json
{
  "title": {
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
}
```

## External ID mapping

Anime list, search and detail responses use the same ID structure:

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

- `wxacg` is the WordPress/WXACG work ID.
- Valid provider IDs are positive integers.
- Missing provider IDs are returned as `null`.
- Missing IDs are never represented by `0`.
- List, search and detail mappings must remain consistent.

## Successful response

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

## Error response

```json
{
  "success": false,
  "code": 400,
  "message": "不支援指定的語言。",
  "error": {
    "type": "invalid_parameter",
    "details": {
      "parameter": "lang",
      "allowed": [
        "zh-TW",
        "zh-HK",
        "zh-CN",
        "ja-JP",
        "ja-Latn",
        "en"
      ]
    }
  },
  "meta": {
    "api_version": "2.0",
    "language": "zh-TW",
    "generated_at": "2026-07-22T22:00:00+00:00"
  }
}
```

## Pagination

Anime list and search endpoints support:

```text
page
per_page
```

Limits:

```text
Default page: 1
Default per_page: 20
Maximum per_page: 50
```

Pagination headers:

```http
X-WP-Total: 221
X-WP-TotalPages: 111
```

## HTTP caching

Successful responses may include:

```http
ETag: W/"..."
Cache-Control: public, max-age=60
```

Clients should send the ETag back with:

```http
If-None-Match: W/"..."
```

If the response has not changed, the API returns:

```http
HTTP/2 304
```

Validation errors use:

```http
Cache-Control: no-store, no-cache, must-revalidate
```

## Authentication

The current Beta API endpoints are public and read-only.

No API token is required at this stage.

API tokens, scopes and rate limiting are planned for a later release and must not be assumed to exist in client applications yet.

## Installation

Place the plugin in:

```text
wp-content/plugins/wxacg-catalog-api/
```

Expected entry file:

```text
wp-content/plugins/wxacg-catalog-api/wxacg-catalog-api.php
```

Activate the required plugins in this order:

1. Anime Sync Pro
2. WXACG API, if required by the installation
3. WXACG Catalog API

After activation:

1. Save WordPress permalink settings.
2. Clear Hostinger/LiteSpeed cache.
3. Clear Cloudflare cache if required.
4. Verify `/wp-json/`.
5. Verify `wxacg-catalog/v1`.
6. Verify `wxacg-catalog/v2`.

## Syntax validation

```bash
cd wp-content/plugins/wxacg-catalog-api

find . -name '*.php' -print0 |
while IFS= read -r -d '' file; do
  php -l "$file" || exit 1
done
```

Every PHP file must report:

```text
No syntax errors detected
```

## Cache invalidation

With WP-CLI:

```bash
wp eval '
Wxacg_Catalog_Api_Cache::bump_version();
echo "Catalog cache bumped\n";
'
```

Do not delete unrelated WordPress or Anime Sync Pro data.

## Documentation

- [API usage documentation](docs/API.md)
- [OpenAPI specification](docs/openapi.yaml)
- [Development context](CONTEXT.md)

## v1 compatibility

The existing v1 API remains available:

```text
/wp-json/wxacg-catalog/v1
```

Important v1 routes include:

```http
GET /items
GET /items/{id}
GET /search
GET /lookup
GET /taxonomies/{taxonomy}
GET /series/{slug}
```

Legacy compatibility route:

```http
GET /wp-json/weixiaoacg/v1/anime-url
```

v1 routes and response formats must remain backward compatible.

## Current release status

Validated on:

```text
https://test.weixiaoacg.com/
```

Current status:

```text
Plugin version: 1.1.0-beta1
v1 acceptance: Passed
v1 regression after v2 deployment: Passed
v2 foundation: Passed
v2 multilingual search: Passed
v2 external ID mapping: Passed
v2 unified validation errors: Passed
v2 ETag 304: Passed
Production release: Pending
```

The Beta version must remain on the test site for stability monitoring before production deployment.

## Roadmap

Planned later stages include:

- OpenAPI publication through Swagger UI or Redoc
- Relations endpoint
- Characters, people, staff and companies
- Themes and regional streaming endpoints
- Source and verification metadata
- Multilingual title tables
- External ID mapping tables
- Search index normalization
- API tokens and scopes
- Rate limiting
- Redis cache backend
- Optional GraphQL layer

These features are not part of `1.1.0-beta1`.
