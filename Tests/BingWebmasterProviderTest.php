<?php

namespace Omnireach\BingWebmaster\Tests;

use Omnireach\BingWebmaster\BingWebmasterFactory;
use Omnireach\BingWebmaster\BingWebmasterProvider;
use Omnireach\Exception\NotSupportedException;
use Omnireach\Exception\ProviderException;
use Omnireach\Model\Dimension;
use Omnireach\Model\Metric;
use Omnireach\Model\ReachQuery;
use Omnireach\Model\Row;
use Omnireach\Registry;
use Omnireach\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Bing's answers follow the JSON sample of GetQueryStats in its API
 * reference (read on 2026-10-24: {"d": [QueryStats...]}, dates as
 * "/Date(ms-0700)/"); the sample row itself is the one dated 2011, the
 * others are composed in its shape. Not recorded with a key.
 */
final class BingWebmasterProviderTest extends TestCase
{
    public function testQueriesOverAPeriod(): void
    {
        $http = self::http();
        $report = self::provider($http)->searchPerformance(self::period([Dimension::Query]));

        self::assertSame([
            ['query' => 'atelier poterie strasbourg', 'clicks' => 16, 'impressions' => 300, 'ctr' => 0.0533, 'position' => 4.3],
            ['query' => 'cours de poterie', 'clicks' => 0, 'impressions' => 50, 'ctr' => 0.0, 'position' => 12.0],
        ], array_map(static fn (Row $r) => $r->dimensions + $r->metrics, $report->rows), 'two weeks summed, the position weighted by impressions; 2011 left out');
        self::assertSame('https://ssl.bing.com/webmaster/api.svc/json/GetQueryStats', $http->requests[0]['url']);
        self::assertSame(['siteUrl' => 'https://atelier.example.org/', 'apikey' => 'example-key-not-real'], $http->requests[0]['options']['query']);
    }

    public function testWeekByWeek(): void
    {
        $report = self::provider(self::http())->searchPerformance(self::period([Dimension::Query, Dimension::Date], [Metric::Clicks]));

        self::assertSame([['query' => 'atelier poterie strasbourg', 'date' => '2026-09-28', 'clicks' => 10], ['query' => 'atelier poterie strasbourg', 'date' => '2026-10-05', 'clicks' => 6], ['query' => 'cours de poterie', 'date' => '2026-10-05', 'clicks' => 0]], array_map(static fn (Row $r) => $r->dimensions + $r->metrics, $report->rows));
    }

    public function testTheWholeSiteAndAFilter(): void
    {
        $site = self::provider(self::http())->searchPerformance(self::period([Dimension::Date]));
        self::assertSame([['date' => '2026-09-28', 'clicks' => 10, 'impressions' => 200, 'ctr' => 0.05], ['date' => '2026-10-05', 'clicks' => 16, 'impressions' => 150, 'ctr' => 0.1067]], array_map(static fn (Row $r) => $r->dimensions + $r->metrics, $site->rows), 'no position for the whole site');

        $one = self::provider(self::http())->searchPerformance(new ReachQuery(new \DateTimeImmutable('2026-09-21'), new \DateTimeImmutable('2026-10-11'), [Dimension::Query], filters: ['query' => 'cours de poterie']));
        self::assertSame(['cours de poterie'], array_map(static fn (Row $r) => $r->dimension(Dimension::Query), $one->rows));
    }

    public function testWhatBingCannotAnswer(): void
    {
        foreach ([self::period([Dimension::Country]), self::period([Dimension::Query, Dimension::Page]), new ReachQuery(new \DateTimeImmutable('2026-09-21'), new \DateTimeImmutable('2026-10-11'), [Dimension::Query], filters: ['page' => 'https://atelier.example.org/'])] as $query) {
            try {
                self::provider(self::http())->searchPerformance($query);
                self::fail('Not supported.');
            } catch (NotSupportedException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Bing Webmaster answered 400 for GetQueryStats: Invalid API key');
        self::provider(RecordedHttpClient::routes(['GET /GetQueryStats' => [400, '{"ErrorCode": 3, "Message": "Invalid API key"}']]))->searchPerformance(self::period([Dimension::Query]));
    }

    public function testTheFactory(): void
    {
        $registry = new Registry([new BingWebmasterFactory(self::http())], ['bing' => ['factory' => 'bing-webmaster', 'options' => ['api_key' => 'example-key-not-real', 'site' => 'https://atelier.example.org/']]]);

        self::assertSame(['searchPerformance'], $registry->get('bing')->capabilities()->contracts());
        self::assertCount(2, $registry->search('bing')->searchPerformance(self::period([Dimension::Query]))->rows);
    }

    /**
     * @param list<Dimension> $dimensions
     * @param list<Metric>    $metrics
     */
    private static function period(array $dimensions, array $metrics = []): ReachQuery
    {
        return new ReachQuery(new \DateTimeImmutable('2026-09-21'), new \DateTimeImmutable('2026-10-11'), $dimensions, $metrics);
    }

    private static function provider(RecordedHttpClient $http): BingWebmasterProvider
    {
        return new BingWebmasterProvider($http, 'example-key-not-real', 'https://atelier.example.org/');
    }

    private static function http(): RecordedHttpClient
    {
        return RecordedHttpClient::routes([
            'GET /GetQueryStats' => (string) file_get_contents(__DIR__.'/Fixtures/query-stats.json'),
            'GET /GetRankAndTrafficStats' => (string) file_get_contents(__DIR__.'/Fixtures/traffic.json'),
        ]);
    }
}
