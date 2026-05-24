<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Dto\Analytics\FieldDashboardRequest;
use App\Repository\FieldAnalyticsRepository;

final class FieldAnalyticsService
{
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
        $coveredMonths = $this->coveredMonthSet($this->repository->loadCoveredMonthsForField($fieldId, $loadStart, $periodEnd));
        $state = $this->buildMonthlyState($rows, $loadStart, $periodEnd, $coveredMonths);
        $chartMonths = $this->monthKeys($periodStart, $periodEnd);

        $topicMetrics = $this->buildTopicMetrics($state, $periodEnd, $comparisonWindow);
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
            'kpi' => $this->buildKpi($fieldRow, $state, $periodEnd, $comparisonWindow, $topicMetrics),
            'fieldActivity' => [
                'series' => $this->buildSeries($state['fieldCounts'], $chartMonths, $movingAverageWindow, $state['coveredMonths']),
            ],
            'subfieldActivity' => [
                'items' => $subfieldActivity,
            ],
            'topicMap' => [
                'points' => array_values(array_filter(
                    array_map(fn (array $metric): array => $this->topicPoint($metric), $topicMetrics),
                    fn (array $point): bool => null !== $point['y'],
                )),
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
     * @param list<string> $months
     *
     * @return array<string, true>
     */
    private function coveredMonthSet(array $months): array
    {
        $set = [];
        foreach ($months as $month) {
            $set[$month] = true;
        }

        return $set;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, true>        $coveredMonths
     *
     * @return array{
     *     topicMeta: array<int, array<string, mixed>>,
     *     subfieldMeta: array<int, array<string, mixed>>,
     *     topicCounts: array<int, array<string, int>>,
     *     subfieldCounts: array<int, array<string, int>>,
     *     fieldCounts: array<string, int>,
     *     coveredMonths: array<string, true>
     * }
     */
    private function buildMonthlyState(
        array $rows,
        \DateTimeImmutable $loadStart,
        \DateTimeImmutable $periodEnd,
        array $coveredMonths,
    ): array {
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
            'coveredMonths' => $coveredMonths,
        ];
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return list<array<string, mixed>>
     */
    private function buildTopicMetrics(array $state, \DateTimeImmutable $periodEnd, int $comparisonWindow): array
    {
        $recent12Start = $this->addMonths($periodEnd, -11);
        $recentWindowStart = $this->addMonths($periodEnd, -($comparisonWindow - 1));
        $previousWindowStart = $this->addMonths($periodEnd, -(2 * $comparisonWindow - 1));
        $previousWindowEnd = $this->addMonths($periodEnd, -$comparisonWindow);
        $coveredMonths = $state['coveredMonths'];
        $recent12Window = $this->windowInfo($state['fieldCounts'], $coveredMonths, $recent12Start, $periodEnd);
        $recentComparisonWindow = $this->windowInfo($state['fieldCounts'], $coveredMonths, $recentWindowStart, $periodEnd);
        $previousComparisonWindow = $this->windowInfo($state['fieldCounts'], $coveredMonths, $previousWindowStart, $previousWindowEnd);

        $metrics = [];
        foreach ($state['topicMeta'] as $topicId => $topic) {
            $subfieldId = (int) $topic['subfieldId'];
            $topicCounts = $state['topicCounts'][$topicId] ?? [];
            $subfieldCounts = $state['subfieldCounts'][$subfieldId] ?? [];

            $topicRecent12 = $this->windowInfo($topicCounts, $coveredMonths, $recent12Start, $periodEnd);
            $subfieldRecent12 = $this->windowInfo($subfieldCounts, $coveredMonths, $recent12Start, $periodEnd);
            $topicRecent = $this->windowInfo($topicCounts, $coveredMonths, $recentWindowStart, $periodEnd);
            $topicPrevious = $this->windowInfo($topicCounts, $coveredMonths, $previousWindowStart, $previousWindowEnd);
            $subfieldRecent = $this->windowInfo($subfieldCounts, $coveredMonths, $recentWindowStart, $periodEnd);
            $subfieldPrevious = $this->windowInfo($subfieldCounts, $coveredMonths, $previousWindowStart, $previousWindowEnd);

            $share = $this->safeDivide((int) $topicRecent12['papers'], (int) $subfieldRecent12['papers'], 0.0);
            $recentShare = $this->safeDivide((int) $topicRecent['papers'], (int) $subfieldRecent['papers'], 0.0);
            $previousShare = $this->safeDivide((int) $topicPrevious['papers'], (int) $subfieldPrevious['papers'], 0.0);
            $hasComparison = $recentComparisonWindow['isComplete']
                && $previousComparisonWindow['isComplete']
                && (int) $subfieldPrevious['papers'] > 0;
            $deltaShare = $hasComparison ? $recentShare - $previousShare : null;
            $monthlyRecentShares = $this->monthlyShares($topicCounts, $subfieldCounts, $recentWindowStart, $periodEnd, $coveredMonths);
            $monthlyPreviousShares = $this->monthlyShares($topicCounts, $subfieldCounts, $previousWindowStart, $previousWindowEnd, $coveredMonths);
            $burstScore = $hasComparison && count($monthlyPreviousShares) >= 2
                ? ($this->average($monthlyRecentShares) - $this->average($monthlyPreviousShares)) / ($this->standardDeviation($monthlyPreviousShares) + 0.000001)
                : null;
            $nonZeroRecentMonths = 0;
            foreach ($this->monthKeys($recent12Start, $periodEnd) as $month) {
                if (isset($coveredMonths[$month]) && ($topicCounts[$month] ?? 0) > 0) {
                    ++$nonZeroRecentMonths;
                }
            }

            $volumeScore = min(1.0, (int) $topicRecent12['papers'] / self::ACTIVE_TOPIC_THRESHOLD);
            $regularityDenominator = max(1, min(6, (int) $recent12Window['observedMonths']));
            $regularityScore = min(1.0, $nonZeroRecentMonths / $regularityDenominator);
            $confidence = (0.70 * $volumeScore + 0.30 * $regularityScore) * (float) $recent12Window['coverage'];

            $metrics[] = [
                'topicId' => $topicId,
                'topicName' => (string) $topic['name'],
                'subfieldId' => $subfieldId,
                'subfieldName' => (string) $topic['subfieldName'],
                'papersLast12m' => (int) $topicRecent12['papers'],
                'share' => $share,
                'recentShare' => $recentShare,
                'previousShare' => $hasComparison ? $previousShare : null,
                'deltaShare' => $deltaShare,
                'momentum' => $deltaShare,
                'growth' => $this->growth(
                    (int) $topicRecent['papers'],
                    (int) $topicPrevious['papers'],
                    (bool) $recentComparisonWindow['isComplete'],
                    (bool) $previousComparisonWindow['isComplete'],
                ),
                'burstScore' => $burstScore,
                'confidence' => min(1.0, $confidence),
                'coverage' => (float) $recent12Window['coverage'],
                'logPapers' => (int) $topicRecent12['papers'] > 0 ? log((int) $topicRecent12['papers']) : 0.0,
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

        $deltaValues = array_map(fn (array $row): float => (float) ($row['deltaShare'] ?? 0), $metrics);
        $growthValues = array_map(fn (array $row): float => (float) ($row['growth'] ?? 0), $metrics);
        $volumeValues = array_map(fn (array $row): float => log(1 + (float) $row['papersLast12m']), $metrics);
        $burstValues = array_map(fn (array $row): float => (float) ($row['burstScore'] ?? 0), $metrics);
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
        $coveredMonths = $state['coveredMonths'];
        $fieldLast12m = $this->windowInfo($state['fieldCounts'], $coveredMonths, $recent12Start, $periodEnd);
        $recentWindow = $this->windowInfo($state['fieldCounts'], $coveredMonths, $recentWindowStart, $periodEnd);
        $previousWindow = $this->windowInfo($state['fieldCounts'], $coveredMonths, $previousWindowStart, $previousWindowEnd);
        $items = [];

        foreach ($state['subfieldMeta'] as $subfieldId => $meta) {
            $counts = $state['subfieldCounts'][$subfieldId] ?? [];
            $recentPapers = $this->windowInfo($counts, $coveredMonths, $recentWindowStart, $periodEnd);
            $previousPapers = $this->windowInfo($counts, $coveredMonths, $previousWindowStart, $previousWindowEnd);
            $papersLast12m = $this->windowInfo($counts, $coveredMonths, $recent12Start, $periodEnd);

            $items[] = [
                'id' => $subfieldId,
                'name' => (string) $meta['name'],
                'papersLast12m' => (int) $papersLast12m['papers'],
                'yoyGrowth' => $this->growth(
                    (int) $recentPapers['papers'],
                    (int) $previousPapers['papers'],
                    (bool) $recentWindow['isComplete'],
                    (bool) $previousWindow['isComplete'],
                ),
                'shareInsideField' => $this->safeDivide((int) $papersLast12m['papers'], (int) $fieldLast12m['papers'], 0.0),
                'coverage' => (float) $papersLast12m['coverage'],
                'series' => $this->buildSeries($counts, $chartMonths, $movingAverageWindow, $coveredMonths),
            ];
        }

        usort($items, fn (array $left, array $right): int => $right['papersLast12m'] <=> $left['papersLast12m']);

        return $items;
    }

    /**
     * @param array<string, mixed>       $fieldRow
     * @param array<string, mixed>       $state
     * @param list<array<string, mixed>> $topicMetrics
     *
     * @return array<string, mixed>
     */
    private function buildKpi(array $fieldRow, array $state, \DateTimeImmutable $periodEnd, int $comparisonWindow, array $topicMetrics): array
    {
        $recent12Start = $this->addMonths($periodEnd, -11);
        $currentComparisonStart = $this->addMonths($periodEnd, -($comparisonWindow - 1));
        $previousComparisonStart = $this->addMonths($periodEnd, -(2 * $comparisonWindow - 1));
        $previousComparisonEnd = $this->addMonths($periodEnd, -$comparisonWindow);
        $coveredMonths = $state['coveredMonths'];
        $papersLast12m = $this->windowInfo($state['fieldCounts'], $coveredMonths, $recent12Start, $periodEnd);
        $currentComparison = $this->windowInfo($state['fieldCounts'], $coveredMonths, $currentComparisonStart, $periodEnd);
        $previousComparison = $this->windowInfo($state['fieldCounts'], $coveredMonths, $previousComparisonStart, $previousComparisonEnd);
        $activeTopics = count(array_filter(
            $topicMetrics,
            fn (array $topic): bool => (int) $topic['papersLast12m'] >= self::ACTIVE_TOPIC_THRESHOLD,
        ));

        return [
            'domainName' => $fieldRow['domain_name'] ?? null,
            'fieldName' => (string) $fieldRow['name'],
            'subfieldsCount' => (int) ($fieldRow['subfields_count'] ?? 0),
            'papersLast12m' => (int) $papersLast12m['papers'],
            'papersLast12mWindow' => $papersLast12m,
            'comparisonCurrentPapers' => (int) $currentComparison['papers'],
            'comparisonPreviousPapers' => (int) $previousComparison['papers'],
            'comparisonCurrentWindow' => $currentComparison,
            'comparisonPreviousWindow' => $previousComparison,
            'comparisonWindowMonths' => $comparisonWindow,
            'changePercent' => $this->growth(
                (int) $currentComparison['papers'],
                (int) $previousComparison['papers'],
                (bool) $currentComparison['isComplete'],
                (bool) $previousComparison['isComplete'],
            ),
            'activeTopics' => $activeTopics,
        ];
    }

    /**
     * @param array<string, int>  $counts
     * @param list<string>        $months
     * @param array<string, true> $coveredMonths
     *
     * @return list<array{period: string, papers: int|null, movingAverage: float|null, isObserved: bool}>
     */
    private function buildSeries(array $counts, array $months, int $window, array $coveredMonths): array
    {
        $series = [];
        foreach ($months as $month) {
            $isObserved = isset($coveredMonths[$month]);
            $series[] = [
                'period' => $month,
                'papers' => $isObserved ? (int) ($counts[$month] ?? 0) : null,
                'movingAverage' => $isObserved ? $this->calendarMovingAverage($counts, $coveredMonths, $month, $window) : null,
                'isObserved' => $isObserved,
            ];
        }

        return $series;
    }

    /**
     * @param array<string, int>  $counts
     * @param array<string, true> $coveredMonths
     */
    private function calendarMovingAverage(array $counts, array $coveredMonths, string $month, int $window): ?float
    {
        $end = new \DateTimeImmutable($month . '-01');
        $start = $this->addMonths($end, -($window - 1));
        $values = [];
        foreach ($this->monthKeys($start, $end) as $monthKey) {
            if (isset($coveredMonths[$monthKey])) {
                $values[] = (int) ($counts[$monthKey] ?? 0);
            }
        }

        return [] === $values ? null : $this->average($values);
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
            'y' => null === $metric['momentum'] ? null : (float) $metric['momentum'],
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
            'deltaShare' => null === $metric['deltaShare'] ? null : (float) $metric['deltaShare'],
            'momentum' => null === $metric['momentum'] ? null : (float) $metric['momentum'],
            'yoyGrowth' => null === $metric['growth'] ? null : (float) $metric['growth'],
            'burstScore' => null === $metric['burstScore'] ? null : (float) $metric['burstScore'],
            'confidence' => (float) $metric['confidence'],
            'coverage' => (float) $metric['coverage'],
            'status' => (string) $metric['status'],
        ];
    }

    /**
     * @param array<string, mixed> $metric
     * @param array<string, float> $thresholds
     */
    private function classifyTopic(array $metric, array $thresholds): string
    {
        $deltaShare = (float) ($metric['deltaShare'] ?? 0);
        $burstScore = (float) ($metric['burstScore'] ?? 0);

        if ((float) $metric['confidence'] < 0.4 || (int) $metric['papersLast12m'] < self::ACTIVE_TOPIC_THRESHOLD) {
            return 'low_confidence';
        }

        if (
            $deltaShare > $thresholds['highDelta']
            && $burstScore > $thresholds['highBurst']
            && (int) $metric['papersLast12m'] < $thresholds['mediumVolume']
        ) {
            return 'emerging';
        }

        if (
            $deltaShare > $thresholds['highDelta']
            && $burstScore > $thresholds['mediumBurst']
            && (int) $metric['papersLast12m'] >= $thresholds['mediumVolume']
        ) {
            return 'accelerating';
        }

        if ($deltaShare < $thresholds['negativeDelta'] && $burstScore < $thresholds['negativeBurst']) {
            return 'declining';
        }

        if ((float) $metric['share'] > $thresholds['highShare'] && $deltaShare >= $thresholds['negativeDelta']) {
            return 'popular_hot';
        }

        return 'stable';
    }

    /**
     * @param array<string, int>  $topicCounts
     * @param array<string, int>  $subfieldCounts
     * @param array<string, true> $coveredMonths
     *
     * @return list<float>
     */
    private function monthlyShares(
        array $topicCounts,
        array $subfieldCounts,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        array $coveredMonths,
    ): array {
        $shares = [];
        foreach ($this->monthKeys($start, $end) as $month) {
            if (isset($coveredMonths[$month])) {
                $shares[] = $this->safeDivide((int) ($topicCounts[$month] ?? 0), (int) ($subfieldCounts[$month] ?? 0), 0.0);
            }
        }

        return $shares;
    }

    /**
     * @param array<string, int>  $counts
     * @param array<string, true> $coveredMonths
     *
     * @return array{start: string, end: string, papers: int, expectedMonths: int, observedMonths: int, coverage: float, isComplete: bool}
     */
    private function windowInfo(array $counts, array $coveredMonths, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $months = $this->monthKeys($start, $end);
        $observed = 0;
        $papers = 0;
        foreach ($months as $month) {
            if (!isset($coveredMonths[$month])) {
                continue;
            }
            ++$observed;
            $papers += (int) ($counts[$month] ?? 0);
        }

        $expected = count($months);

        return [
            'start' => $this->monthStart($start)->format('Y-m'),
            'end' => $this->monthStart($end)->format('Y-m'),
            'papers' => $papers,
            'expectedMonths' => $expected,
            'observedMonths' => $observed,
            'coverage' => 0 === $expected ? 0.0 : $observed / $expected,
            'isComplete' => $expected > 0 && $observed === $expected,
        ];
    }

    private function growth(int|float $recent, int|float $previous, bool $recentComplete, bool $previousComplete): ?float
    {
        if (!$recentComplete || !$previousComplete || (float) $previous <= 0.0) {
            return null;
        }

        return ((float) $recent / (float) $previous) - 1;
    }

    private function safeDivide(int|float $numerator, int|float $denominator, float $default): float
    {
        if (0.0 === (float) $denominator) {
            return $default;
        }

        return (float) $numerator / (float) $denominator;
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
