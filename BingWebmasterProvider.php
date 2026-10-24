<?php

namespace Omnireach\BingWebmaster;

use Omnireach\Capabilities;
use Omnireach\Exception\NotSupportedException;
use Omnireach\Http\JsonApi;
use Omnireach\Model\Dimension;
use Omnireach\Model\Metric;
use Omnireach\Model\ReachQuery;
use Omnireach\Model\Report;
use Omnireach\Model\Row;
use Omnireach\SearchPerformanceInterface;
use Omnireach\Support\Data;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Bing Webmaster API (https://learn.microsoft.com/en-us/dotnet/api/microsoft.bing.webmaster.api.interfaces.iwebmasterapi):
 * GET GetQueryStats, GetPageStats and GetRankAndTrafficStats with the
 * site's URL and the API key. Bing answers with its own periods (a row per
 * week, about six months back); the rows in the query's days are kept,
 * summed over the period unless the date is asked for.
 */
final class BingWebmasterProvider implements SearchPerformanceInterface
{
    private readonly JsonApi $api;

    public function __construct(
        HttpClientInterface $http,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $site,
        private readonly string $url = 'https://ssl.bing.com/webmaster/api.svc/json',
    ) {
        $this->api = new JsonApi($http, 'Bing Webmaster');
    }

    public function getName(): string
    {
        return 'bing-webmaster';
    }

    public function getTitle(): string
    {
        return 'Bing Webmaster Tools';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            searchPerformance: true,
            dimensions: [Dimension::Query, Dimension::Page, Dimension::Date],
            metrics: [Metric::Clicks, Metric::Impressions, Metric::Ctr, Metric::Position],
            combined: false,
            terms: ['https://learn.microsoft.com/en-us/dotnet/api/microsoft.bing.webmaster.api.interfaces.iwebmasterapi', 'https://www.bing.com/webmasters/'],
            limits: ['the data is updated weekly (the API\'s own remark): one row per period Bing chose, about six months back', 'no quota figure in what was read: keep calls few, cache the answers'],
        );
    }

    public function searchPerformance(ReachQuery $query): Report
    {
        $metrics = $query->metricsOr([Metric::Clicks, Metric::Impressions, Metric::Ctr, Metric::Position]);
        $this->capabilities()->check($query, $metrics, 'Bing Webmaster');
        $dimension = $query->breakdown()[0] ?? null;
        foreach (array_keys($query->filters) as $filtered) {
            if ($filtered !== $dimension?->value) {
                throw new NotSupportedException(\sprintf('Bing Webmaster filters on the dimension "%s" only in a report broken down by it.', $filtered));
            }
        }
        $site = $query->site ?? $this->site;
        $method = match ($dimension) {
            Dimension::Query => 'GetQueryStats',
            Dimension::Page => 'GetPageStats',
            default => 'GetRankAndTrafficStats',
        };
        $lines = $this->api->request('GET', $this->url.'/'.$method, ['query' => ['siteUrl' => $site, 'apikey' => $this->apiKey]], $method)->list('d');

        /** @var array<string, array{dimensions: array<string, string>, clicks: int, impressions: int, weighted: float, positioned: int}> $groups */
        $groups = [];
        foreach ($lines as $line) {
            $date = self::date($line->string('Date'));
            if (null === $date || $date < $query->start->format('Y-m-d') || $date > $query->end->format('Y-m-d')) {
                continue;
            }
            $dimensions = (null !== $dimension ? [$dimension->value => (string) $line->string('Query')] : []) + ($query->has(Dimension::Date) ? ['date' => $date] : []);
            $key = implode("\0", $dimensions);
            $groups[$key] ??= ['dimensions' => $dimensions, 'clicks' => 0, 'impressions' => 0, 'weighted' => 0.0, 'positioned' => 0];
            $impressions = $line->int('Impressions') ?? 0;
            $groups[$key]['clicks'] += $line->int('Clicks') ?? 0;
            $groups[$key]['impressions'] += $impressions;
            if (null !== ($position = $line->float('AvgImpressionPosition')) && $position > 0) {
                $groups[$key]['weighted'] += $position * $impressions;
                $groups[$key]['positioned'] += $impressions;
            }
        }

        $rows = [];
        foreach ($groups as $group) {
            $values = [];
            foreach ($metrics as $metric) {
                $value = match ($metric) {
                    Metric::Clicks => $group['clicks'],
                    Metric::Impressions => $group['impressions'],
                    Metric::Ctr => $group['impressions'] > 0 ? round($group['clicks'] / $group['impressions'], 4) : 0.0,
                    Metric::Position => $group['positioned'] > 0 ? round($group['weighted'] / $group['positioned'], 1) : null,
                    default => null,
                };
                if (null !== $value) {
                    $values[$metric->value] = $value;
                }
            }
            $rows[] = new Row($group['dimensions'], $values);
        }
        // A history in order of its days (by query or page first); a breakdown by clicks, then impressions.
        usort($rows, $query->has(Dimension::Date)
            ? static fn (Row $a, Row $b) => array_values($a->dimensions) <=> array_values($b->dimensions)
            : static fn (Row $a, Row $b) => [$b->metric(Metric::Clicks) ?? 0, $b->metric(Metric::Impressions) ?? 0] <=> [$a->metric(Metric::Clicks) ?? 0, $a->metric(Metric::Impressions) ?? 0]);

        return new Report('bing-webmaster', $site, $query, $query->apply($rows), 'Bing\'s own periods (weekly rows) inside the query\'s days; positions weighted by impressions'.(null === $dimension ? '; no position for the whole site' : '').'.');
    }

    /** "/Date(1316156400000-0700)/" as Y-m-d, in the offset it names. */
    private static function date(?string $value): ?string
    {
        if (null === $value || !preg_match('~^/Date\((-?\d+)([+-]\d{4})?\)/$~', $value, $m)) {
            return null !== $value ? Data::of(['d' => $value])->date('d')?->format('Y-m-d') : null;
        }
        $moment = (new \DateTimeImmutable('@'.intdiv((int) $m[1], 1000)));

        return (isset($m[2]) ? $moment->setTimezone(new \DateTimeZone($m[2])) : $moment)->format('Y-m-d');
    }
}
