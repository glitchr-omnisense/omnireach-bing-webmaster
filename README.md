# omnireach/bing-webmaster

[omnisense/omnireach](https://github.com/glitchr-omnisense/omnireach)'s Bing Webmaster Tools, under
any PHP host: a site's search queries and pages in Bing with their clicks, impressions, CTR and
average position, period by period or summed over the query's days.

```php
use Omnireach\BingWebmaster\BingWebmasterFactory;
use Omnireach\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new BingWebmasterFactory(HttpClient::create())], [
    'bing' => ['factory' => 'bing-webmaster', 'options' => ['api_key' => '...', 'site' => 'https://example.org/']],
]);
```

Options: `api_key` (Bing Webmaster Tools, Settings, API access), `site` (as verified there),
`url`.

## The API, as read on 2026-10-24

The Bing Webmaster API's JSON endpoint (`https://ssl.bing.com/webmaster/api.svc/json/`, its
reference at
<https://learn.microsoft.com/en-us/dotnet/api/microsoft.bing.webmaster.api.interfaces.iwebmasterapi>):
`GetQueryStats` (queries), `GetPageStats` (pages; the page is in the `Query` field),
`GetRankAndTrafficStats` (the whole site), with `siteUrl` and `apikey`. Bing chooses the periods:
one row per week ("the data will be updated every week"), about six months back, dates as
`/Date(ms-0700)/`. This package keeps the rows inside the query's days, one per period when the
date is asked for, summed otherwise (the position weighted by impressions, from
`AvgImpressionPosition`); filters work on the dimension reported; the whole site has no
position. Positions are passed as Bing gives them (not verified against a live account).

**Terms**: Bing Webmaster Tools' terms; no quota figure in what was read - keep calls few and
cache the answers. Reuse: no maintained PHP client was found.

## Verified, and not

- Verified (the core's harness, PHP 8.4): queries summed over a period, week by week, the whole
  site, a filter, what Bing cannot answer, an error message - against the JSON sample of
  `GetQueryStats` in Bing's reference and rows composed in its shape; every class loading with the
  package's requirements alone (`BareTest`).
- Not verified live: no Bing Webmaster key was used.

## Licence

MIT. An unofficial client, affiliated with Microsoft in no way.
