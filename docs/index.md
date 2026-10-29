# omnireach/bing-webmaster

Bing Webmaster Tools for [omnisense/omnireach](https://github.com/glitchr-omnisense/omnireach): a
site's search queries and pages in Bing with their clicks, impressions, CTR and average position,
period by period or summed over the query's days. The provider calls the Bing Webmaster API's
JSON endpoint (its reference:
<https://learn.microsoft.com/en-us/dotnet/api/microsoft.bing.webmaster.api.interfaces.iwebmasterapi>):

| Report | Method | Request |
|---|---|---|
| by query | `GetQueryStats` | `GET {url}/GetQueryStats?siteUrl=...&apikey=...` |
| by page | `GetPageStats` | `GET {url}/GetPageStats?siteUrl=...&apikey=...` |
| the whole site | `GetRankAndTrafficStats` | `GET {url}/GetRankAndTrafficStats?siteUrl=...&apikey=...` |

`{url}` is `https://ssl.bing.com/webmaster/api.svc/json` by default; there is no sandbox. No
maintained PHP client of this API was found to reuse.

## Installation

```sh
composer require omnireach/bing-webmaster symfony/http-client
```

Requires PHP 8.2, `omnisense/omnireach` (1.x) and `symfony/http-client-contracts` (3).

## Credentials

An **API key**: Bing Webmaster Tools, Settings, API access. The **site** is given as verified
there (`https://example.org/`); `ReachQuery::$site` reports on another site of the same account.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `api_key` | string | - | yes | the API key |
| `site` | string | - | yes | the site's URL as verified in Bing Webmaster Tools |
| `url` | string | `https://ssl.bing.com/webmaster/api.svc/json` | no | the JSON endpoint's base URL |

Per query: `ReachQuery::$site` replaces `site` for one report.

## Capabilities

| | |
|---|---|
| contract | `SearchPerformanceInterface` |
| dimensions | query, page, date - one at a time besides the date (`combined: false`) |
| metrics | clicks, impressions, ctr, position (all four by default) |
| filters | only on the dimension the report is broken down by |
| country, device | no |
| `AudienceInterface` | no |

## Behaviour

**The call.** One `GET` per report, chosen by the dimension besides the date: `GetQueryStats` for
`query`, `GetPageStats` for `page`, `GetRankAndTrafficStats` for none. The site and the key go in
the query string, which is how Bing's API takes them:

```
GET https://ssl.bing.com/webmaster/api.svc/json/GetQueryStats
  query: {"siteUrl":"https://atelier.example.org/","apikey":"example-key"}
```

(seen on a recorded client in the harness, 2026-10-26). The answer's rows are under `d`.

**Bing's periods.** Bing chooses the periods, not the query: one row per week ("the data will be
updated every week"), about six months back, dated `/Date(1316156400000-0700)/` (milliseconds, an
offset). Each row's date is read in the offset it names; the rows whose date falls inside the
query's days are kept, the others dropped. So a query of three days may hold no Bing row at all,
and a month holds four or five.

**Grouping.** Rows are grouped by the dimension's value (a page is in Bing's `Query` field too),
and by the date when the date is asked: without it, a query's weeks are summed into one row;
with it, each week is a row dated by Bing. Clicks and impressions are summed; the CTR is clicks
over impressions (0 when there is no impression), rounded to 4 decimals; the position is Bing's
`AvgImpressionPosition` averaged weighted by impressions, rounded to 1 - left out when Bing gave
none (the whole site's report has no position).

**Order.** Without the date, rows are sorted by clicks then impressions, most first; with it, by
the dimension's value then the date. Then the query's filters and limit are applied.

**Filters.** A filter on the dimension of the report (`['query' => 'cours de poterie']` in a
report by query) is applied to Bing's rows. Any other filter - a page in a report by query, any
filter on the whole site's report, a date - is refused with `NotSupportedException` (`Bing
Webmaster filters on the dimension "page" only in a report broken down by it.`), since Bing
cannot filter.

**Note.** Every report's `note`: `Bing's own periods (weekly rows) inside the query's days;
positions weighted by impressions.`, with `; no position for the whole site` for the whole site.

```
row: {"dimensions":{"query":"atelier poterie strasbourg"},"metrics":{"clicks":16,"impressions":300,"ctr":0.0533,"position":4.3}}
row: {"dimensions":{"query":"cours de poterie"},"metrics":{"clicks":0,"impressions":50,"ctr":0,"position":12}}
note: Bing's own periods (weekly rows) inside the query's days; positions weighted by impressions.
```

(queries from 2026-09-01 to 2026-10-31 on the package's test answers, in the harness, 2026-10-26)

**Limits**: no quota figure in what was read of Bing's reference; keep calls few and cache the
answers.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnireach\BingWebmaster\BingWebmasterFactory;
use Omnireach\Model\Dimension;
use Omnireach\Model\Metric;
use Omnireach\Model\ReachQuery;
use Omnireach\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new BingWebmasterFactory(HttpClient::create())], [
    'bing' => ['factory' => 'bing-webmaster', 'options' => ['api_key' => getenv('BING_WEBMASTER_KEY'), 'site' => 'https://example.org/']],
]);
$bing = $registry->search('bing');

// The queries of the last three months, summed.
foreach ($bing->searchPerformance(ReachQuery::lastDays(90, [Dimension::Query]))->rows as $row) {
    printf("%-40s %4d clicks %6d impressions\n", $row->dimension(Dimension::Query), $row->metric(Metric::Clicks), $row->metric(Metric::Impressions));
}

// One page, week by week.
$weeks = $bing->searchPerformance(new ReachQuery(
    new DateTimeImmutable('-180 days'), new DateTimeImmutable('yesterday'),
    [Dimension::Page, Dimension::Date], [Metric::Clicks, Metric::Position],
    ['page' => 'https://example.org/old-page'],
));
```

In Symfony:

```yaml
services:
    Omnireach\BingWebmaster\BingWebmasterFactory:
        arguments: ['@http_client']
    Omnireach\Registry:
        arguments:
            $factories: ['@Omnireach\BingWebmaster\BingWebmasterFactory']
            $config:
                bing: { factory: bing-webmaster, options: { api_key: '%env(BING_WEBMASTER_KEY)%', site: 'https://example.org/' } }
```

The example needs a key: it was not run live.

## Errors

The provider's calls are named `Bing Webmaster` in the messages, and the method called is the
"what": 401 and 403 an `InvalidKeyException` (`Bing Webmaster refused the credentials (401) for
GetQueryStats: ...`, Bing's `Message` after the colon), 404 a `NotFoundException`, any other
status of 400 or more or a body that is not JSON a `ProviderException`, a network error an
`UnreachableException`. A missing `api_key` or `site` is an `InvalidConfigException` when the
provider is built; a country, device, source or an analytics metric is a `NotSupportedException`
before any call.

The key travels in the query string, as Bing's API takes it; an `UnreachableException`'s message
names the method and the path only, with the HTTP client's reason without the URL (`Bing Webmaster
could not be reached for GetQueryStats (GET /json/GetQueryStats): Could not resolve host:
bing.invalid.`). The exception's previous, the HTTP client's, still quotes the URL: keep a log that
writes the whole chain private.

## Verified, and not

Tested offline (`Tests/BingWebmasterProviderTest.php`, in the core's harness, PHP 8.4): queries
summed over a period, week by week, the whole site, a filter, what Bing cannot answer, an error
message, the key kept out of an unreachable Bing's message, the factory through a `Registry` - against the JSON sample of `GetQueryStats` in Bing's
reference and rows composed in its shape. `Tests/BareTest.php` loads every class with the
package's requirements alone.

Not verified: no Bing Webmaster key was used; no call was made to Bing. Whether
`AvgImpressionPosition` is the position a site owner sees in Bing Webmaster Tools is unchecked:
positions are passed as Bing gives them.

## Responsibility

An independent, unofficial client, affiliated with Microsoft in no way. Whoever runs it complies
with Bing Webmaster Tools' terms (<https://www.bing.com/webmasters/>): see the README.
