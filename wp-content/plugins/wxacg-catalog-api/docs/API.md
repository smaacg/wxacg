\# Weixiao Anime API Usage Guide



\## Overview



Weixiao Anime API（微笑動漫 API）provides public, read-only access to anime catalog data.



Current API version:



```text

2.0

```



Current plugin version:



```text

1.1.0-beta1

```



Current test base URL:



```text

https://test.weixiaoacg.com/wp-json/wxacg-catalog/v2

```



The production v2 API is not released yet.



\---



\## Authentication



No authentication is required for the current Beta read-only endpoints.



Do not include private WordPress nonces, Application Passwords or administrative credentials in public client applications.



Authentication and rate limiting are planned for a later release.



\---



\## Content type



Responses use:



```http

Content-Type: application/json; charset=UTF-8

```



\---



\## Language selection



Use the `lang` query parameter.



Supported values:



| Language | Value |

|---|---|

| Traditional Chinese, Taiwan | `zh-TW` |

| Traditional Chinese, Hong Kong | `zh-HK` |

| Simplified Chinese | `zh-CN` |

| Japanese | `ja-JP` |

| Japanese romanisation | `ja-Latn` |

| English | `en` |



Default:



```text

zh-TW

```



Example:



```http

GET /anime/751?lang=ja-JP

```



\### Title fallback



A title response contains:



\- `display`: selected display title

\- `locale`: actual language used by `display`

\- `requested\_locale`: language requested by the client

\- `official`: known official titles

\- `aliases`: alternative titles



Example:



```json

{

&#x20; "display": "無職轉生 第二季",

&#x20; "locale": "zh-TW",

&#x20; "requested\_locale": "zh-HK",

&#x20; "official": {

&#x20;   "zh-TW": "無職轉生 第二季",

&#x20;   "zh-HK": null,

&#x20;   "zh-CN": null,

&#x20;   "ja-JP": "無職転生Ⅱ",

&#x20;   "ja-Latn": "Mushoku Tensei II",

&#x20;   "en": "Mushoku Tensei: Jobless Reincarnation II"

&#x20; },

&#x20; "aliases": \[]

}

```



If the requested title does not exist, `display` may use a fallback. Missing official language values remain `null`.



\---



\# Anime list



```http

GET /anime

```



Returns published anime records.



\## Query parameters



| Parameter | Type | Default | Description |

|---|---|---:|---|

| `lang` | string | `zh-TW` | Response language |

| `page` | integer | `1` | Page number, minimum 1 |

| `per\_page` | integer | `20` | Results per page, 1–50 |

| `genre` | string | — | Genre slug or name |

| `season` | string | — | Season slug or name |

| `format` | string | — | Anime format |

| `series` | string | — | Series slug or name |

| `studio` | string | — | Studio slug or name |

| `tag` | string | — | Tag slug or name |

| `status` | string | — | Anime status |

| `year` | integer | — | Release or season year |

| `orderby` | string | `date` | Ordering field |

| `order` | string | `DESC` | `ASC` or `DESC` |



\## Supported `orderby` values



```text

date

modified

title

popularity

score

site\_score

season\_year

start\_date

```



\## Example



```bash

curl -sS \\

&#x20; 'https://test.weixiaoacg.com/wp-json/wxacg-catalog/v2/anime?per\_page=2\&lang=zh-TW'

```



\## Example response



```json

{

&#x20; "success": true,

&#x20; "code": 200,

&#x20; "message": "OK",

&#x20; "data": \[

&#x20;   {

&#x20;     "id": 751,

&#x20;     "type": "anime",

&#x20;     "ids": {

&#x20;       "wxacg": 751,

&#x20;       "anilist": 166873,

&#x20;       "mal": 55888,

&#x20;       "bangumi": 444557,

&#x20;       "tmdb": null

&#x20;     },

&#x20;     "slug": "mushoku-tensei-ii-isekai-ittara-honki-dasu-part-2",

&#x20;     "url": "https://test.weixiaoacg.com/anime/mushoku-tensei-ii-isekai-ittara-honki-dasu-part-2/",

&#x20;     "title": {

&#x20;       "display": "無職轉生 第二季 ～到了異世界就拿出真本事～ 第2部分",

&#x20;       "locale": "zh-TW",

&#x20;       "requested\_locale": "zh-TW",

&#x20;       "official": {

&#x20;         "zh-TW": "無職轉生 第二季 ～到了異世界就拿出真本事～ 第2部分",

&#x20;         "zh-HK": null,

&#x20;         "zh-CN": null,

&#x20;         "ja-JP": "無職転生Ⅱ ～異世界行ったら本気だす～ 第2クール",

&#x20;         "ja-Latn": "Mushoku Tensei II: Isekai Ittara Honki Dasu Part 2",

&#x20;         "en": "Mushoku Tensei: Jobless Reincarnation Season 2 Part 2"

&#x20;       },

&#x20;       "aliases": \[]

&#x20;     },

&#x20;     "image": "https://s4.anilist.co/file/anilistcdn/media/anime/cover/large/bx166873-xO0BRPkmwFll.png",

&#x20;     "format": "TV",

&#x20;     "status": "FINISHED",

&#x20;     "season": {

&#x20;       "name": "SPRING",

&#x20;       "year": 2024

&#x20;     },

&#x20;     "counts": {

&#x20;       "episodes": 12,

&#x20;       "volumes": 0,

&#x20;       "chapters": 0

&#x20;     },

&#x20;     "scores": {

&#x20;       "anilist": 8.3,

&#x20;       "mal": 8.4

&#x20;     },

&#x20;     "genres": \[],

&#x20;     "series": \[],

&#x20;     "updated\_at": "2026-07-22T00:00:00+00:00"

&#x20;   }

&#x20; ],

&#x20; "pagination": {

&#x20;   "page": 1,

&#x20;   "per\_page": 2,

&#x20;   "total": 221,

&#x20;   "total\_pages": 111

&#x20; },

&#x20; "meta": {

&#x20;   "api\_version": "2.0",

&#x20;   "language": "zh-TW",

&#x20;   "cached": false,

&#x20;   "generated\_at": "2026-07-22T22:00:00+00:00"

&#x20; }

}

```



\---



\# Anime detail



```http

GET /anime/{id}

```



Returns one published anime record.



\## Path parameter



| Parameter | Type | Description |

|---|---|---|

| `id` | integer | Positive WXACG/WordPress anime ID |



\## Query parameter



| Parameter | Type | Default |

|---|---|---|

| `lang` | string | `zh-TW` |



\## Example



```bash

curl -sS \\

&#x20; 'https://test.weixiaoacg.com/wp-json/wxacg-catalog/v2/anime/751?lang=ja-JP'

```



\## Detail fields



A detail response may include:



```text

source

dates

duration

synopsis

images

trailer\_url

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



Fields are included when data exists. Clients must handle missing optional fields.



\## Missing anime



```http

HTTP/2 404

```



Example:



```json

{

&#x20; "success": false,

&#x20; "code": 404,

&#x20; "message": "找不到已發布的動畫。",

&#x20; "error": {

&#x20;   "type": "not\_found",

&#x20;   "details": \[]

&#x20; },

&#x20; "meta": {

&#x20;   "api\_version": "2.0",

&#x20;   "language": "zh-TW",

&#x20;   "generated\_at": "2026-07-22T22:00:00+00:00"

&#x20; }

}

```



\---



\# Search



```http

GET /search

```



Searches published anime using multilingual title data.



\## Parameters



| Parameter | Required | Description |

|---|---:|---|

| `q` | Yes | Search text, maximum 100 characters |

| `lang` | No | Response language |

| `page` | No | Page number |

| `per\_page` | No | Results per page, maximum 50 |

| Other collection filters | No | Same filters as `/anime` |



\## English/Romaji search



```bash

curl -sS \\

&#x20; 'https://test.weixiaoacg.com/wp-json/wxacg-catalog/v2/search?q=Mushoku\&per\_page=2\&lang=zh-TW'

```



\## Traditional Chinese search



Use URL encoding in shell commands and client libraries:



```bash

curl -sS \\

&#x20; 'https://test.weixiaoacg.com/wp-json/wxacg-catalog/v2/search?q=%E7%84%A1%E8%81%B7\&per\_page=2\&lang=zh-TW'

```



The encoded query represents:



```text

無職

```



Validated results include:



```text

743 | 無職轉生～到了異世界就拿出真本事～

745 | 無職轉生～到了異世界就拿出真本事～ 第2部分

```



Search results use the same anime summary schema and ID mapping as `/anime`.



\---



\# ID mapping



Every anime summary and detail includes:



```json

{

&#x20; "ids": {

&#x20;   "wxacg": 751,

&#x20;   "anilist": 166873,

&#x20;   "mal": 55888,

&#x20;   "bangumi": 444557,

&#x20;   "tmdb": null

&#x20; }

}

```



\## Rules



\- `wxacg` is always the internal public work ID.

\- Provider IDs are positive integers.

\- Missing mappings are `null`.

\- Clients must not assume every provider exists.

\- `0` is not used for missing mappings.

\- Mapping availability depends on synchronized source data.



\---



\# Pagination



List and search responses contain:



```json

{

&#x20; "pagination": {

&#x20;   "page": 1,

&#x20;   "per\_page": 20,

&#x20;   "total": 221,

&#x20;   "total\_pages": 12

&#x20; }

}

```



Headers may also include:



```http

X-WP-Total: 221

X-WP-TotalPages: 12

```



Invalid values return HTTP `400`.



Example invalid request:



```text

/anime?per\_page=9999

```



Example response:



```json

{

&#x20; "success": false,

&#x20; "code": 400,

&#x20; "message": "per\_page 必須是 1 至 50 之間的整數。",

&#x20; "error": {

&#x20;   "type": "invalid\_parameter",

&#x20;   "details": {

&#x20;     "parameter": "per\_page"

&#x20;   }

&#x20; },

&#x20; "meta": {

&#x20;   "api\_version": "2.0",

&#x20;   "language": "zh-TW",

&#x20;   "generated\_at": "2026-07-22T22:00:00+00:00"

&#x20; }

}

```



\---



\# Error handling



\## Invalid parameter



```json

{

&#x20; "success": false,

&#x20; "code": 400,

&#x20; "message": "不支援指定的語言。",

&#x20; "error": {

&#x20;   "type": "invalid\_parameter",

&#x20;   "details": {

&#x20;     "parameter": "lang",

&#x20;     "allowed": \[

&#x20;       "zh-TW",

&#x20;       "zh-HK",

&#x20;       "zh-CN",

&#x20;       "ja-JP",

&#x20;       "ja-Latn",

&#x20;       "en"

&#x20;     ]

&#x20;   }

&#x20; },

&#x20; "meta": {

&#x20;   "api\_version": "2.0",

&#x20;   "language": "zh-TW",

&#x20;   "generated\_at": "2026-07-22T22:00:00+00:00"

&#x20; }

}

```



\## Missing search query



```json

{

&#x20; "success": false,

&#x20; "code": 400,

&#x20; "message": "缺少搜尋關鍵字。",

&#x20; "error": {

&#x20;   "type": "missing\_parameter",

&#x20;   "details": {

&#x20;     "parameter": "q"

&#x20;   }

&#x20; },

&#x20; "meta": {

&#x20;   "api\_version": "2.0",

&#x20;   "language": "zh-TW",

&#x20;   "generated\_at": "2026-07-22T22:00:00+00:00"

&#x20; }

}

```



\## HTTP statuses



| HTTP status | Meaning |

|---:|---|

| `200` | Request succeeded |

| `304` | Cached representation is still current |

| `400` | Missing or invalid parameter |

| `404` | Published anime record not found |

| `500` | Unexpected server error |



A URL that does not match a registered route may use WordPress `rest\_no\_route` format instead of the v2 envelope.



\---



\# ETag and conditional requests



Successful responses may return:



```http

ETag: W/"33b9f684d28aa7a7a5045f54c5c541ad"

Cache-Control: public, max-age=60

```



Store the ETag and send it with the next request:



```bash

url='https://test.weixiaoacg.com/wp-json/wxacg-catalog/v2/anime?per\_page=2\&lang=zh-TW'



etag=$(curl -sS -D - -o /dev/null "$url" |

&#x20; awk 'BEGIN{IGNORECASE=1} /^etag:/{sub("\\r$",""); print $2}')



curl -sS -D - -o /dev/null \\

&#x20; -H "If-None-Match: $etag" \\

&#x20; "$url"

```



Expected unchanged response:



```http

HTTP/2 304

```



A `304` response has no JSON body.



\---



\# Version headers



v2 responses include:



```http

X-WXACG-API-Version: 2.0

X-WXACG-Catalog-Version: 1.1.0-beta1

```



`X-WXACG-API-Version` is the public response contract version.



`X-WXACG-Catalog-Version` is the installed WordPress plugin version.



\---



\# v1 compatibility



v1 base URL:



```text

https://test.weixiaoacg.com/wp-json/wxacg-catalog/v1

```



Important routes:



```http

GET /items

GET /items/{id}

GET /search

GET /lookup

GET /taxonomies/{taxonomy}

GET /series/{slug}

```



Legacy compatibility route:



```text

https://test.weixiaoacg.com/wp-json/weixiaoacg/v1/anime-url

```



v1 uses its existing response formats. Clients requiring the unified envelope should use v2.



\---



\# Client recommendations



Clients should:



1\. Always URL-encode query parameters.

2\. Use the `lang` parameter instead of language-specific endpoints.

3\. Treat optional provider IDs as nullable.

4\. Treat optional detail fields as nullable or absent.

5\. Respect `Cache-Control`.

6\. Store and reuse ETags.

7\. Handle HTTP `304` without attempting to parse a JSON body.

8\. Use `page` and `per\_page` instead of downloading all records.

9\. Avoid assuming `zh-HK` or `zh-CN` official titles exist.

10\. Avoid relying on Beta-only implementation details not documented here.



\---



\# Beta notice



Version `1.1.0-beta1` is available for test-site validation only.



The Beta release may be approved for production deployment after:



\- 24–72 hours of stable operation

\- No PHP fatal errors

\- No repeated origin-server failures

\- v1 regression tests remain successful

\- v2 list, detail and search remain successful

\- ETag invalidation works after data updates

\- OpenAPI documentation validation passes



Do not use the test site as a production dependency.
