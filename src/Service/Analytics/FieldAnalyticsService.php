<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Dto\Analytics\FieldDashboardRequest;
use App\Repository\FieldAnalyticsRepository;

final class FieldAnalyticsService
{
    private const EPSILON_COUNT = 5.0;
    private const EPSILON_SHARE = 0.000001;
    private const ACTIVE_TOPIC_THRESHOLD = 10;
    private const RANKING_LIMIT = 15;

    public function __construct(private readonly FieldAnalyticsRepository $repository)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFields(string $query, int $limit): array
    {
        return array_map(
            fn (array $row): array => [
                ...$this->normalizeField($row),
                'recent12mPapers' => (int) ($row['recent_12m_papers'] ?? 0),
            ],
            $this->repository->listFields($query, $limit),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildDashboard(int $fieldId, FieldDashboardRequest $request): ?array
    {
        $fieldRow = $this->repository->findField($fieldId);
        if (null === $fieldRow) {
            return null;
        }

        $maxPeriod = $this->repository->findMaxPeriodForField($fieldId);
        $periodEnd = $this->monthStart($request->periodEnd ?? $maxPeriod ?? new \DateTimeImmutable('first day of this month'));
        $periodStart = $this->monthStart($request->periodStart ?? $this->addMonths($periodEnd, -35));
        $comparisonWindow = $request->comparisonWindowMonths;
        $movingAverageWindow = $request->movingAverageMonths;

        $metricStart = $this->addMonths($periodEnd, -max(23, 2 * $comparisonWindow - 1));
        $loadStart = $periodStart < $metricStart ? $periodStart : $metricStart;

        $rows = $this->repository->loadTopicMonthlyStats($fieldId, $loadStart, $periodEnd);
        $state = $this->buildMonthlyState($rows, $loadStart, $periodEnd);
        $chartMonths = $this->monthKeys($periodStart, $periodEnd);
        $allMonths = $this->monthKeys($loadStart, $periodEnd);

        $topicMetrics = $this->buildTopicMetrics($state, $periodEnd, $comparisonWindow, $allMonths);
        $topicMetrics = $this->scoreTopicMetrics($topicMetrics);
        $subfieldActivity = $this->buildSubfieldActivity($state, $periodEnd, $comparisonWindow, $movingAverageWindow, $chartMonths);

        return [
            'field' => $this->normalizeField($fieldRow),
            'filters' => [
                'periodStart' => $periodStart->format('Y-m'),
                'periodEnd' => $periodEnd->format('Y-m'),
                'comparisonWindowMonths' => $comparisonWindow,
                'movingAverageMonths' => $movingAverageWindow,
                'availablePeriodEnd' => null === $maxPeriod ? null : $maxPeriod->format('Y-m'),
            ],
            'kpi' => $this->buildKpi($state, $periodEnd, $topicMetrics),
            'fieldActivity' => [
                'series' => $this->buildSeries($state['fieldCounts'], $chartMonths, $movingAverageWindow),
            ],
            'subfieldActivity' => [
                'items' => $subfieldActivity,
            ],
            'topicMap' => [
                'points' => array_map(fn (array $metric): array => $this->topicPoint($metric), $topicMetrics),
            ],
            'rankings' => $this->buildRankings($topicMetrics),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function normalizeField(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'openalexId' => $row['openalex_id'] ?? null,
            'domain' => null === ($row['domain_id'] ?? null) ? null : [
                'id' => (int) $row['domain_id'],
                'name' => (string) $row['domain_name'],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>              $months
     *
     * @return array{
     *     topicMeta: array<int, array<string, mixed>>,
     *     subfieldMeta: array<int, array<string, mixed>>,
     *     topicCounts: array<int, array<string, int>>,
     *     subfieldCounts: array<int, array<string, int>>,
     *     fieldCounts: array<string, int>
     * }
     */
    private function buildMonthlyState(array $rows, \DateTimeImmutable $loadStart, \DateTimeImmutable $periodEnd): array
    {
        $months = $this->monthKeys($loadStart, $periodEnd);
        $fieldCounts = array_fill_keys($months, 0);
        $topicMeta = [];
        $subfieldMeta = [];
        $topicCounts = [];
        $subfieldCounts = [];

        foreach ($rows as $row) {
            $topicId = (int) $row['topic_id'];
            $subfieldId = (int) $row['subfield_id'];
            $month = (new \DateTimeImmutable((string) $row['period_start']))->format('Y-m');
            $count = (int) $row['works_count'];

            $topicMeta[$topicId] = [
                'id' => $topicId,
                'name' => (string) $row['topic_name'],
                'subfieldId' => $subfieldId,
                'subfieldName' => (string) $row['subfield_name'],
            ];
            $subfieldMeta[$subfieldId] = [
                'id' => $subfieldId,
                'name' => (string) $row['subfield_name'],
            ];

            $topicCounts[$topicId][$month] = ($topicCounts[$topicId][$month] ?? 0) + $count;
            $subfieldCounts[$subfieldId][$month] = ($subfieldCounts[$subfieldId][$month] ?? 0) + $count;
            $fieldCounts[$month] = ($fieldCounts[$month] ?? 0) + $count;
        }

        ksort($topicMeta);
        ksort($subfieldMeta);

        return [
            'topicMeta' => $topicMeta,
            'subfieldMeta' => $subfieldMeta,
            'topicCounts' => $topicCounts,
            'subfieldCounts' => $subfieldCounts,
            'fieldCounts' => $fieldCounts,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string>         $months
     *
     * @return list<array<string, mixed>>
     */
    private function buildTopicMetrics(array $state, \DateTimeImmutable $periodEnd, int $comparisonWindow, array $months): array
    {
        $recent12Start = $this->addMonths($periodEnd, -11);
        $previous12Start = $this->addMonths($periodEnd, -23);
        $previous12End = $this->addMonths($periodEnd, -12);
        $recentWindowStart = $this->addMonths($periodEnd, -($comparisonWindow - 1));
        $previousWindowStart = $this->addMonths($periodEnd, -(2 * $comparisonWindow - 1));
        $previousWindowEnd = $this->addMonths($periodEnd, -$comparisonWindow);

        $metrics = [];
        foreach ($state['topicMeta'] as $topicId => $topic) {
            $subfieldId = (int) $topic['subfieldId'];
            $topicCounts = $state['topicCounts'][$topicId] ?? [];
            $subfieldCounts = $state['subfieldCounts'][$subfieldId] ?? [];
            $papersLast12m = $this->windowSum($topicCounts, $recent12Start, $periodEnd);
            $previousPapersLast12m = $this->windowSum($topicCounts, $previous12Start, $previous12End);
            $subfieldLast12m = $this->windowSum($subfieldCounts, $recent12Start, $periodEnd);
            $recentWindowPapers = $this->windowSum($topicCounts, $recentWindowStart, $periodEnd);
            $previousWindowPapers = $this->windowSum($topicCounts, $previousWindowStart, $previousWindowEnd);
            $recentSubfieldPapers = $this->windowSum($subfieldCounts, $recentWindowStart, $periodEnd);
            $previousSubfieldPapers = $this->windowSum($subfieldCounts, $previousWindowStart, $previousWindowEnd);
            $share = $this->safeDivide($papersLast12m, $subfieldLast12m, 0.0);
            $recentShare = $this->safeDivide($recentWindowPapers, $recentSubfieldPapers, 0.0);
            $previousShare = $this->safeDivide($previousWindowPapers, $previousSubfieldPapers, 0.0);
            $monthlyRecentShares = $this->monthlyShares($topicCounts, $subfieldCounts, $recentWindowStart, $periodEnd);
            $monthlyPreviousShares = $this->monthlyShares($topicCounts, $subfieldCounts, $previousWindowStart, $previousWindowEnd);
            $burstScore = (
                $this->average($monthlyRecentShares) - $this->average($monthlyPreviousShares)
            ) / ($this->standardDeviation($monthlyPreviousShares) + self::EPSILON_SHARE);
            $nonZeroRecentMonths = 0;
            foreach ($this->monthKeys($recent12Start, $periodEnd) as $month) {
                if (($topicCounts[$month] ?? 0) > 0) {
                    ++$nonZeroRecentMonths;
                }
            }

            $confidence = 0.70 * min(1.0, $papersLast12m / self::ACTIVE_TOPIC_THRESHOLD)
                + 0.30 * min(1.0, $nonZeroRecentMonths / 6);

            $metrics[] = [
                'topicId' => $topicId,
                'topicName' => (string) $topic['name'],
                'subfieldId' => $subfieldId,
                'subfieldName' => (string) $topic['subfieldName'],
                'papersLast12m' => $papersLast12m,
                'previousPapersLast12m' => $previousPapersLast12m,
                'share' => $share,
                'recentShare' => $recentShare,
                'previousShare' => $previousShare,
                'deltaShare' => $recentShare - $previousShare,
                'momentum' => $recentShare - $previousShare,
                'growth' => $this->growth($recentWindowPapers, $previousWindowPapers),
                'burstScore' => $burstScore,
                'confidence' => min(1.0, $confidence),
                'logPapers' => $papersLast12m > 0 ? log($papersLast12m) : 0.0,
                'monthCount' => count($months),
            ];
        }

        return $metrics;
    }

    /**
     * @param list<array<string, mixed>> $metrics
     *
     * @return list<array<string, mixed>>
     */
    private function scoreTopicMetrics(array $metrics): array
    {
        if ([] === $metrics) {
            return [];
        }

        $deltaValues = array_column($metrics, 'deltaShare');
        $growthValues = array_column($metrics, 'growth');
        $volumeValues = array_map(fn (array $row): float => log(1 + (float) $row['papersLast12m']), $metrics);
        $burstValues = array_column($metrics, 'burstScore');
        $shareValues = array_column($metrics, 'share');
        $paperValues = array_column($metrics, 'papersLast12m');

        $deltaZ = $this->robustZ($deltaValues);
        $growthZ = $this->robustZ($growthValues);
        $volumeZ = $this->robustZ($volumeValues);
        $burstZ = $this->robustZ($burstValues);
        $negativeDeltaZ = $this->robustZ(array_map(fn (float|int $value): float => -(float) $value, $deltaValues));
        $negativeBurstZ = $this->robustZ(array_map(fn (float|int $value): float => -(float) $value, $burstValues));

        $thresholds = [
            'highDelta' => $this->quantile($deltaValues, 0.75),
            'negativeDelta' => $this->quantile($deltaValues, 0.25),
            'highBurst' => $this->quantile($burstValues, 0.75),
            'mediumBurst' => $this->quantile($burstValues, 0.60),
            'negativeBurst' => $this->quantile($burstValues, 0.25),
            'mediumVolume' => $this->quantile($paperValues, 0.75),
            'highShare' => $this->quantile($shareValues, 0.75),
            'smallDelta' => $this->quantile(array_map('abs', $deltaValues), 0.25),
        ];

        foreach ($metrics as $index => $metric) {
            $trendScore = 0.50 * $deltaZ[$index] + 0.30 * $growthZ[$index] + 0.20 * $volumeZ[$index];
            $emergingScore = (0.60 * $deltaZ[$index] + 0.40 * $burstZ[$index]) * (float) $metric['confidence'];
            $decliningScore = (0.60 * $negativeDeltaZ[$index] + 0.40 * $negativeBurstZ[$index]) * (float) $metric['confidence'];

            $metrics[$index]['trendScore'] = $trendScore;
            $metrics[$index]['emergingScore'] = $emergingScore;
            $metrics[$index]['decliningScore'] = $decliningScore;
            $metrics[$index]['status'] = $this->classifyTopic($metric, $thresholds);
        }

        return $metrics;
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string>         $chartMonths
     *
     * @return list<array<string, mixed>>
     */
    private function buildSubfieldActivity(
        array $state,
        \DateTimeImmutable $periodEnd,
        int $comparisonWindow,
        int $movingAverageWindow,
        array $chartMonths,
    ): array {
        $recent12Start = $this->addMonths($periodEnd, -11);
        $recentWindowStart = $this->addMonths($periodEnd, -($comparisonWindow - 1));
        $previousWindowStart = $this->addMonths($periodEnd, -(2 * $comparisonWindow - 1));
        $previousWindowEnd = $this->addMonths($periodEnd, -$comparisonWindow);
        $fieldLast12m = $this->windowSum($state['fieldCounts'], $recent12Start, $periodEnd);
        $items = [];

        foreach ($state['subfieldMeta'] as $subfieldId => $meta) {
            $counts = $state['subfieldCounts'][$subfieldId] ?? [];
            $recentPapers = $this->windowSum($counts, $recentWindowStart, $periodEnd);
            $previousPapers = $this->windowSum($counts, $previousWindowStart, $previousWindowEnd);
            $papersLast12m = $this->windowSum($counts, $recent12Start, $periodEnd);

            $items[] = [
                'id' => $subfieldId,
                'name' => (string) $meta['name'],
                'papersLast12m' => $papersLast12m,
                'yoyGrowth' => $this->growth($recentPapers, $previousPapers),
                'shareInsideField' => $this->safeDivide($papersLast12m, $fieldLast12m, 0.0),
                'series' => $this->buildSeries($counts, $chartMonths, $movingAverageWindow),
            ];
        }

        usort($items, fn (array $left, array $right): int => $right['papersLast12m'] <=> $left['papersLast12m']);

        return $items;
    }

    /**
     * @param array<string, mixed>       $state
     * @param list<array<string, mixed>> $topicMetrics
     *
     * @return array<string, mixed>
     */
    private function buildKpi(array $state, \DateTimeImmutable $periodEnd, array $topicMetrics): array
    {
        $recent12Start = $this->addMonths($periodEnd, -11);
        $previous12Start = $this->addMonths($periodEnd, -23);
        $previous12End = $this->addMonths($periodEnd, -12);
        $papersLast12m = $this->windowSum($state['fieldCounts'], $recent12Start, $periodEnd);
        $previousPapersLast12m = $this->windowSum($state['fieldCounts'], $previous12Start, $previous12End);
        $activeTopics = count(array_filter(
            $topicMetrics,
            fn (array $topic): bool => (int) $topic['papersLast12m'] >= self::ACTIVE_TOPIC_THRESHOLD,
        ));

        return [
            'papersLast12m' => $papersLast12m,
            'previousPapersLast12m' => $previousPapersLast12m,
            'changePercent' => $this->growth($papersLast12m, $previousPapersLast12m),
            'activeTopics' => $activeTopics,
        ];
    }

    /**
     * @param array<string, int> $counts
     * @param list<string>       $months
     *
     * @return list<array{period: string, papers: int, movingAverage: float}>
     */
    private function buildSeries(array $counts, array $months, int $window): array
    {
        $values = [];
        foreach ($months as $month) {
            $values[] = (int) ($counts[$month] ?? 0);
        }

        $movingAverage = $this->movingAverage($values, $window);
        $series = [];
        foreach ($months as $index => $month) {
            $series[] = [
                'period' => $month,
                'papers' => $values[$index],
                'movingAverage' => $movingAverage[$index],
            ];
        }

        return $series;
    }

    /**
     * @param array<string, mixed> $metric
     *
     * @return array<string, mixed>
     */
    private function topicPoint(array $metric): array
    {
        return [
            ...$this->topicRankingRecord($metric),
            'x' => (float) $metric['logPapers'],
            'y' => (float) $metric['momentum'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $topicMetrics
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildRankings(array $topicMetrics): array
    {
        $eligible = array_values(array_filter(
            $topicMetrics,
            fn (array $topic): bool => (int) $topic['papersLast12m'] >= self::ACTIVE_TOPIC_THRESHOLD,
        ));
        if ([] === $eligible) {
            $eligible = $topicMetrics;
        }

        return [
            'popular' => $this->rank($eligible, 'papersLast12m'),
            'growing' => $this->rank($eligible, 'trendScore'),
            'emerging' => $this->rank($eligible, 'emergingScore'),
            'declining' => $this->rank($eligible, 'decliningScore'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $topics
     *
     * @return list<array<string, mixed>>
     */
    private function rank(array $topics, string $scoreField): array
    {
        usort($topics, function (array $left, array $right) use ($scoreField): int {
            $scoreComparison = ((float) $right[$scoreField]) <=> ((float) $left[$scoreField]);
            if (0 !== $scoreComparison) {
                return $scoreComparison;
            }

            return strcmp((string) $left['topicName'], (string) $right['topicName']);
        });

        return array_map(
            fn (array $metric): array => $this->topicRankingRecord($metric),
            array_slice($topics, 0, self::RANKING_LIMIT),
        );
    }

    /**
     * @param array<string, mixed> $metric
     *
     * @return array<string, mixed>
     */
    private function topicRankingRecord(array $metric): array
    {
        return [
            'topic' => [
                'id' => (int) $metric['topicId'],
                'name' => (string) $metric['topicName'],
            ],
            'subfield' => [
                'id' => (int) $metric['subfieldId'],
                'name' => (string) $metric['subfieldName'],
            ],
            'papersLast12m' => (int) $metric['papersLast12m'],
            'share' => (float) $metric['share'],
            'deltaShare' => (float) $metric['deltaShare'],
            'momentum' => (float) $metric['momentum'],
            'yoyGrowth' => (float) $metric['growth'],
            'burstScore' => (float) $metric['burstScore'],
            'confidence' => (float) $metric['confidence'],
            'status' => (string) $metric['status'],
        ];
    }

    /**
     * @param array<string, mixed> $metric
     * @param array<string, float> $thresholds
     */
    private function classifyTopic(array $metric, array $thresholds): string
    {
        if ((float) $metric['confidence'] < 0.4 || (int) $metric['papersLast12m'] < self::ACTIVE_TOPIC_THRESHOLD) {
            return 'low_confidence';
        }

        if (
            (float) $metric['deltaShare'] > $thresholds['highDelta']
            && (float) $metric['burstScore'] > $thresholds['highBurst']
            && (int) $metric['papersLast12m'] < $thresholds['mediumVolume']
        ) {
            return 'emerging';
        }

        if (
            (float) $metric['deltaShare'] > $thresholds['highDelta']
            && (float) $metric['burstScore'] > $thresholds['mediumBurst']
            && (int) $metric['papersLast12m'] >= $thresholds['mediumVolume']
        ) {
            return 'accelerating';
        }

        if ((float) $metric['share'] > $thresholds['highShare'] && abs((float) $metric['deltaShare']) <= $thresholds['smallDelta']) {
            return 'popular_hot';
        }

        if ((float) $metric['deltaShare'] < $thresholds['negativeDelta'] && (float) $metric['burstScore'] < $thresholds['negativeBurst']) {
            return 'declining';
        }

        return 'stable';
    }

    /**
     * @param array<string, int> $topicCounts
     * @param array<string, int> $subfieldCounts
     *
     * @return list<float>
     */
    private function monthlyShares(array $topicCounts, array $subfieldCounts, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $shares = [];
        foreach ($this->monthKeys($start, $end) as $month) {
            $shares[] = $this->safeDivide((int) ($topicCounts[$month] ?? 0), (int) ($subfieldCounts[$month] ?? 0), 0.0);
        }

        return $shares;
    }

    /**
     * @param array<string, int> $counts
     */
    private function windowSum(array $counts, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $sum = 0;
        foreach ($this->monthKeys($start, $end) as $month) {
            $sum += (int) ($counts[$month] ?? 0);
        }

        return $sum;
    }

    private function growth(int|float $recent, int|float $previous): float
    {
        return (((float) $recent + self::EPSILON_COUNT) / ((float) $previous + self::EPSILON_COUNT)) - 1;
    }

    private function safeDivide(int|float $numerator, int|float $denominator, float $default): float
    {
        if (0.0 === (float) $denominator) {
            return $default;
        }

        return (float) $numerator / (float) $denominator;
    }

    /**
     * @param list<int> $values
     *
     * @return list<float>
     */
    private function movingAverage(array $values, int $window): array
    {
        $result = [];
        foreach ($values as $index => $value) {
            $start = max(0, $index - $window + 1);
            $slice = array_slice($values, $start, $index - $start + 1);
            $result[] = $this->average($slice);
        }

        return $result;
    }

    /**
     * @param list<float|int> $values
     *
     * @return list<float>
     */
    private function robustZ(array $values): array
    {
        if ([] === $values) {
            return [];
        }

        $numeric = array_map(fn (float|int $value): float => (float) $value, $values);
        $median = $this->median($numeric);
        $absoluteDeviations = array_map(fn (float $value): float => abs($value - $median), $numeric);
        $mad = $this->median($absoluteDeviations);

        if (0.0 === $mad) {
            $mean = $this->average($numeric);
            $std = $this->standardDeviation($numeric);
            if (0.0 === $std) {
                return array_fill(0, count($numeric), 0.0);
            }

            return array_map(fn (float $value): float => ($value - $mean) / $std, $numeric);
        }

        return array_map(fn (float $value): float => ($value - $median) / (1.4826 * $mad), $numeric);
    }

    /**
     * @param list<float|int> $values
     */
    private function quantile(array $values, float $q): float
    {
        if ([] === $values) {
            return 0.0;
        }

        $numeric = array_map(fn (float|int $value): float => (float) $value, $values);
        sort($numeric);
        $position = (count($numeric) - 1) * $q;
        $lowerIndex = (int) floor($position);
        $upperIndex = (int) ceil($position);
        if ($lowerIndex === $upperIndex) {
            return $numeric[$lowerIndex];
        }

        $weight = $position - $lowerIndex;

        return $numeric[$lowerIndex] * (1 - $weight) + $numeric[$upperIndex] * $weight;
    }

    /**
     * @param list<float|int> $values
     */
    private function median(array $values): float
    {
        if ([] === $values) {
            return 0.0;
        }

        $numeric = array_map(fn (float|int $value): float => (float) $value, $values);
        sort($numeric);
        $count = count($numeric);
        $middle = intdiv($count, 2);

        if (1 === $count % 2) {
            return $numeric[$middle];
        }

        return ($numeric[$middle - 1] + $numeric[$middle]) / 2;
    }

    /**
     * @param list<float|int> $values
     */
    private function average(array $values): float
    {
        if ([] === $values) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * @param list<float|int> $values
     */
    private function standardDeviation(array $values): float
    {
        if ([] === $values) {
            return 0.0;
        }

        $mean = $this->average($values);
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ((float) $value - $mean) ** 2;
        }

        return sqrt($variance / count($values));
    }

    private function monthStart(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-01'));
    }

    private function addMonths(\DateTimeImmutable $date, int $months): \DateTimeImmutable
    {
        if (0 === $months) {
            return $this->monthStart($date);
        }

        return $this->monthStart($date->modify(sprintf('%+d months', $months)));
    }

    /**
     * @return list<string>
     */
    private function monthKeys(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $months = [];
        $cursor = $this->monthStart($start);
        $end = $this->monthStart($end);

        while ($cursor <= $end) {
            $months[] = $cursor->format('Y-m');
            $cursor = $this->addMonths($cursor, 1);
        }

        return $months;
    }
}
