<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Dto\Analytics\TopicDashboardRequest;
use App\Repository\TopicAnalyticsRepository;

final class TopicAnalyticsService
{
    private const ACTIVE_TOPIC_THRESHOLD = 10;

    public function __construct(
        private readonly TopicAnalyticsRepository $repository,
        private readonly TopicAnalyticsMlClient $mlClient,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTopicsByField(int $fieldId, int $limit): array
    {
        return array_map(
            fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'openalexId' => $row['openalex_id'] ?? null,
                'subfield' => [
                    'id' => (int) $row['subfield_id'],
                    'name' => (string) $row['subfield_name'],
                ],
                'recent12mPapers' => (int) ($row['recent_12m_papers'] ?? 0),
            ],
            $this->repository->listTopicsByField($fieldId, $limit),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildDashboard(int $topicId, TopicDashboardRequest $request): ?array
    {
        $topic = $this->repository->findTopic($topicId);
        if (null === $topic) {
            return null;
        }

        $maxPeriod = $this->repository->findMaxPeriodForTopic($topicId);
        $periodEnd = $this->monthStart($request->periodEnd ?? $maxPeriod ?? new \DateTimeImmutable('first day of this month'));
        $periodStart = $this->monthStart($request->periodStart ?? $this->addMonths($periodEnd, -35));
        $comparisonWindow = $request->comparisonWindowMonths;
        $metricStart = $this->addMonths($periodEnd, -max(23, 2 * $comparisonWindow - 1));
        $loadStart = $periodStart < $metricStart ? $periodStart : $metricStart;
        $subfieldId = (int) ($topic['subfield_id'] ?? 0);

        $coveredMonths = $this->coveredMonthSet($this->repository->loadCoveredMonthsForSubfield($subfieldId, $loadStart, $periodEnd));
        $rows = $this->repository->loadTopicSubfieldMonthlyStats($topicId, $subfieldId, $loadStart, $periodEnd);
        $counts = $this->buildCounts($rows, $loadStart, $periodEnd);
        $chartMonths = $this->monthKeys($periodStart, $periodEnd);
        $metrics = $this->buildMetrics($counts, $coveredMonths, $periodEnd, $comparisonWindow);
        $dbDecomposition = $this->dbDecomposition($topicId, $metrics, $periodEnd, $comparisonWindow);
        $ml = $this->mlClient->insights([
            'topic_id' => $topicId,
            'period_start' => $periodStart->format('Y-m-d'),
            'period_end' => $periodEnd->format('Y-m-d'),
            'comparison_window_months' => $comparisonWindow,
            'forecast_months' => $request->forecastMonths,
            'max_related' => 12,
        ]);
        $mlPayload = $ml['payload'] ?? null;
        $forecast = is_array($mlPayload) ? ($mlPayload['forecast'] ?? []) : [];
        $mlDecomposition = is_array($mlPayload) ? ($mlPayload['decomposition'] ?? []) : [];
        $relatedTopics = is_array($mlPayload) ? ($mlPayload['related_topics'] ?? []) : [];
        $mlErrors = [
            ...$ml['errors'],
            ...array_values(array_map('strval', is_array($mlPayload) ? ($mlPayload['errors'] ?? []) : [])),
        ];

        return [
            'topic' => $this->normalizeTopic($topic),
            'filters' => [
                'periodStart' => $periodStart->format('Y-m'),
                'periodEnd' => $periodEnd->format('Y-m'),
                'comparisonWindowMonths' => $comparisonWindow,
                'forecastMonths' => $request->forecastMonths,
                'availablePeriodEnd' => null === $maxPeriod ? null : $maxPeriod->format('Y-m'),
            ],
            'kpi' => [
                'topicName' => (string) $topic['name'],
                'parentSubfield' => $topic['subfield_name'] ?? null,
                'field' => $topic['field_name'] ?? null,
                'domain' => $topic['domain_name'] ?? null,
                'papersLast12m' => (int) $metrics['recent12']['papers'],
                'papersLast12mWindow' => $metrics['recent12'],
                'growth' => $metrics['growth'],
                'shareInsideSubfield' => $metrics['share'],
                'status' => $this->classify($metrics),
                'confidence' => $metrics['confidence'],
            ],
            'activity' => [
                'series' => $this->buildSeries($counts, $chartMonths, $coveredMonths),
                'forecast' => $this->normalizeForecast(is_array($forecast) ? $forecast : []),
            ],
            'trendDecomposition' => [
                'items' => $this->mergeDecomposition($dbDecomposition, is_array($mlDecomposition) ? $mlDecomposition : []),
                'error' => [] === $mlErrors ? null : implode(' ', $mlErrors),
            ],
            'relatedTopics' => [
                'items' => $this->normalizeRelatedTopics(is_array($relatedTopics) ? $relatedTopics : []),
                'error' => [] === $mlErrors ? null : implode(' ', $mlErrors),
            ],
            'representativeWorks' => [
                'items' => $this->normalizeWorks($this->repository->loadRepresentativeWorks($topicId, $periodStart, $periodEnd, 12), $periodEnd),
            ],
            'quarterReports' => [
                'items' => $this->normalizeQuarterReports($this->repository->loadQuarterReports($topicId, $periodStart, $periodEnd)),
            ],
            'mlStatus' => [
                'available' => (bool) $ml['available'],
                'errors' => $mlErrors,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPaper(int $paperId): ?array
    {
        $paper = $this->repository->findPaper($paperId);
        if (null === $paper) {
            return null;
        }

        return [
            'id' => (int) $paper['id'],
            'title' => (string) $paper['title'],
            'doi' => $paper['doi'] ?? null,
            'openalexId' => $paper['openalex_id'] ?? null,
            'publicationYear' => null === $paper['publication_year'] ? null : (int) $paper['publication_year'],
            'publicationDate' => $paper['publication_date'] ?? null,
            'language' => $paper['language'] ?? null,
            'abstract' => $paper['abstract'] ?? null,
            'isOpenAccess' => null === $paper['is_open_access'] ? null : (bool) $paper['is_open_access'],
            'citedBy' => (int) ($paper['cited_by_count'] ?? 0),
            'referencesCount' => (int) ($paper['references_count'] ?? 0),
            'authors' => $this->jsonList($paper['authors_json'] ?? '[]'),
            'keywords' => $this->jsonList($paper['keywords_json'] ?? '[]'),
            'topics' => $this->jsonList($paper['topics_json'] ?? '[]'),
            'landings' => $this->jsonList($paper['landings_json'] ?? '[]'),
        ];
    }

    /**
     * @param array<string, mixed> $topic
     *
     * @return array<string, mixed>
     */
    private function normalizeTopic(array $topic): array
    {
        return [
            'id' => (int) $topic['id'],
            'name' => (string) $topic['name'],
            'openalexId' => $topic['openalex_id'] ?? null,
            'subfield' => null === ($topic['subfield_id'] ?? null) ? null : [
                'id' => (int) $topic['subfield_id'],
                'name' => (string) $topic['subfield_name'],
            ],
            'field' => null === ($topic['field_id'] ?? null) ? null : [
                'id' => (int) $topic['field_id'],
                'name' => (string) $topic['field_name'],
            ],
            'domain' => null === ($topic['domain_id'] ?? null) ? null : [
                'id' => (int) $topic['domain_id'],
                'name' => (string) $topic['domain_name'],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{topic: array<string, int>, subfield: array<string, int>}
     */
    private function buildCounts(array $rows, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $months = $this->monthKeys($start, $end);
        $topic = array_fill_keys($months, 0);
        $subfield = array_fill_keys($months, 0);
        foreach ($rows as $row) {
            $month = (new \DateTimeImmutable((string) $row['period_start']))->format('Y-m');
            $topic[$month] = (int) $row['topic_papers'];
            $subfield[$month] = (int) $row['subfield_papers'];
        }

        return ['topic' => $topic, 'subfield' => $subfield];
    }

    /**
     * @param array{topic: array<string, int>, subfield: array<string, int>} $counts
     * @param array<string, true>                                            $coveredMonths
     *
     * @return array<string, mixed>
     */
    private function buildMetrics(array $counts, array $coveredMonths, \DateTimeImmutable $periodEnd, int $comparisonWindow): array
    {
        $recent12 = $this->windowInfo($counts['topic'], $coveredMonths, $this->addMonths($periodEnd, -11), $periodEnd);
        $recent12Subfield = $this->windowInfo($counts['subfield'], $coveredMonths, $this->addMonths($periodEnd, -11), $periodEnd);
        $currentStart = $this->addMonths($periodEnd, -($comparisonWindow - 1));
        $previousStart = $this->addMonths($periodEnd, -(2 * $comparisonWindow - 1));
        $previousEnd = $this->addMonths($periodEnd, -$comparisonWindow);
        $current = $this->windowInfo($counts['topic'], $coveredMonths, $currentStart, $periodEnd);
        $previous = $this->windowInfo($counts['topic'], $coveredMonths, $previousStart, $previousEnd);
        $currentSubfield = $this->windowInfo($counts['subfield'], $coveredMonths, $currentStart, $periodEnd);
        $previousSubfield = $this->windowInfo($counts['subfield'], $coveredMonths, $previousStart, $previousEnd);
        $recentShare = $this->safeDivide((int) $current['papers'], (int) $currentSubfield['papers'], 0.0);
        $previousShare = $this->safeDivide((int) $previous['papers'], (int) $previousSubfield['papers'], 0.0);
        $hasComparison = $current['isComplete'] && $previous['isComplete'] && (int) $previousSubfield['papers'] > 0;
        $deltaShare = $hasComparison ? $recentShare - $previousShare : null;
        $recentShares = $this->monthlyShares($counts, $currentStart, $periodEnd, $coveredMonths);
        $previousShares = $this->monthlyShares($counts, $previousStart, $previousEnd, $coveredMonths);
        $burst = $hasComparison && count($previousShares) >= 2
            ? ($this->average($recentShares) - $this->average($previousShares)) / ($this->standardDeviation($previousShares) + 0.000001)
            : null;
        $nonZeroMonths = 0;
        foreach ($this->monthKeys($this->addMonths($periodEnd, -11), $periodEnd) as $month) {
            if (isset($coveredMonths[$month]) && ($counts['topic'][$month] ?? 0) > 0) {
                ++$nonZeroMonths;
            }
        }
        $volumeScore = min(1.0, (int) $recent12['papers'] / self::ACTIVE_TOPIC_THRESHOLD);
        $regularityScore = min(1.0, $nonZeroMonths / max(1, min(6, (int) $recent12['observedMonths'])));

        return [
            'recent12' => $recent12,
            'current' => $current,
            'previous' => $previous,
            'share' => $this->safeDivide((int) $recent12['papers'], (int) $recent12Subfield['papers'], 0.0),
            'deltaShare' => $deltaShare,
            'growth' => $this->growth((int) $current['papers'], (int) $previous['papers'], (bool) $current['isComplete'], (bool) $previous['isComplete']),
            'burstScore' => $burst,
            'confidence' => min(1.0, (0.70 * $volumeScore + 0.30 * $regularityScore) * (float) $recent12['coverage']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dbDecomposition(int $topicId, array $metrics, \DateTimeImmutable $periodEnd, int $comparisonWindow): array
    {
        $currentStart = $this->addMonths($periodEnd, -($comparisonWindow - 1));
        $citationVelocity = $this->repository->loadCitationVelocity($topicId, $currentStart, $periodEnd);

        return [
            $this->metric('publication_growth', 'Publication growth', $metrics['growth'], 'percent', $this->normalizeSigned($metrics['growth'], 1.0)),
            $this->metric('share_growth', 'Share growth', $metrics['deltaShare'], 'percentage_point', $this->normalizeSigned($metrics['deltaShare'], 0.05)),
            $this->metric('burst_score', 'Burst score', $metrics['burstScore'], 'score', $this->normalizeSigned($metrics['burstScore'], 3.0)),
            $this->metric('citation_velocity', 'Citation velocity', $citationVelocity, 'citations_per_month', null === $citationVelocity ? null : min(1.0, $citationVelocity / 5.0)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $dbItems
     * @param list<array<string, mixed>> $mlItems
     *
     * @return list<array<string, mixed>>
     */
    private function mergeDecomposition(array $dbItems, array $mlItems): array
    {
        $byKey = [];
        foreach ($dbItems as $item) {
            $byKey[(string) $item['key']] = $item;
        }
        foreach ($mlItems as $item) {
            if (is_array($item) && isset($item['key'])) {
                $byKey[(string) $item['key']] = $item;
            }
        }

        $order = ['publication_growth', 'share_growth', 'burst_score', 'citation_velocity', 'keyphrase_novelty', 'semantic_drift'];
        $result = [];
        foreach ($order as $key) {
            $result[] = $byKey[$key] ?? $this->metric($key, ucwords(str_replace('_', ' ', $key)), null, 'score', null);
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeForecast(array $rows): array
    {
        return array_values(array_filter(array_map(static function (mixed $row): ?array {
            if (!is_array($row)) {
                return null;
            }

            return [
                'period' => isset($row['period_start']) ? substr((string) $row['period_start'], 0, 7) : null,
                'forecastCount' => null === ($row['forecast_count'] ?? null) ? null : (float) $row['forecast_count'],
                'lowerBound' => null === ($row['lower_bound'] ?? null) ? null : (float) $row['lower_bound'],
                'upperBound' => null === ($row['upper_bound'] ?? null) ? null : (float) $row['upper_bound'],
                'forecastShare' => null === ($row['forecast_share'] ?? null) ? null : (float) $row['forecast_share'],
                'lowerShare' => null === ($row['lower_share'] ?? null) ? null : (float) $row['lower_share'],
                'upperShare' => null === ($row['upper_share'] ?? null) ? null : (float) $row['upper_share'],
                'modelName' => $row['model_name'] ?? null,
                'shareModelName' => $row['share_model_name'] ?? null,
                'subfieldModelName' => $row['subfield_model_name'] ?? null,
                'backtestErrorMae' => null === ($row['backtest_error_mae'] ?? null) ? null : (float) $row['backtest_error_mae'],
                'backtestErrorMape' => null === ($row['backtest_error_mape'] ?? null) ? null : (float) $row['backtest_error_mape'],
                'backtestErrorSmape' => null === ($row['backtest_error_smape'] ?? null) ? null : (float) $row['backtest_error_smape'],
            ];
        }, $rows)));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeRelatedTopics(array $rows): array
    {
        return array_values(array_filter(array_map(static function (mixed $row): ?array {
            if (!is_array($row)) {
                return null;
            }

            return [
                'topicId' => (int) ($row['topic_id'] ?? $row['topicId'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'relationType' => (string) ($row['relation_type'] ?? $row['relationType'] ?? ''),
                'similarity' => null === ($row['similarity'] ?? null) ? null : (float) $row['similarity'],
                'sharedKeyphrases' => array_values(array_map('strval', is_array($row['shared_keyphrases'] ?? null) ? $row['shared_keyphrases'] : [])),
                'commonPapers' => null === ($row['common_papers'] ?? null) ? null : (int) $row['common_papers'],
                'commonCitations' => null === ($row['common_citations'] ?? null) ? null : (int) $row['common_citations'],
                'trendStatus' => $row['trend_status'] ?? null,
            ];
        }, $rows)));
    }

    private function metric(string $key, string $label, int|float|null $value, string $unit, ?float $normalized): array
    {
        $score = null === $normalized ? null : max(0.0, min(1.0, $normalized));

        return [
            'key' => $key,
            'label' => $label,
            'value' => null === $value ? null : (float) $value,
            'unit' => $unit,
            'normalized' => $score,
            'level' => null === $score ? null : ($score >= 0.66 ? 'high' : ($score >= 0.33 ? 'medium' : 'low')),
        ];
    }

    private function normalizeSigned(int|float|null $value, float $scale): ?float
    {
        return null === $value ? null : min(1.0, abs((float) $value) / $scale);
    }

    /**
     * @param array{topic: array<string, int>, subfield: array<string, int>} $counts
     * @param list<string>                                                   $months
     * @param array<string, true>                                             $coveredMonths
     *
     * @return list<array<string, mixed>>
     */
    private function buildSeries(array $counts, array $months, array $coveredMonths): array
    {
        $series = [];
        foreach ($months as $month) {
            $observed = isset($coveredMonths[$month]);
            $topicPapers = $observed ? (int) ($counts['topic'][$month] ?? 0) : null;
            $subfieldPapers = $observed ? (int) ($counts['subfield'][$month] ?? 0) : null;
            $series[] = [
                'period' => $month,
                'papers' => $topicPapers,
                'subfieldPapers' => $subfieldPapers,
                'share' => null === $topicPapers || null === $subfieldPapers ? null : $this->safeDivide($topicPapers, $subfieldPapers, 0.0),
                'isObserved' => $observed,
            ];
        }

        return $series;
    }

    private function classify(array $metrics): string
    {
        $share = (float) $metrics['share'];
        $deltaShare = (float) ($metrics['deltaShare'] ?? 0.0);
        $burst = (float) ($metrics['burstScore'] ?? 0.0);

        if ($deltaShare > 0.01 && $burst > 1.0 && $share < 0.08) {
            return 'emerging';
        }
        if ($deltaShare < -0.005 && $burst < -0.5) {
            return 'declining';
        }
        if ($share >= 0.05 && $deltaShare >= -0.005) {
            return 'popular';
        }

        return 'stable';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeWorks(array $rows, \DateTimeImmutable $periodEnd): array
    {
        return array_map(function (array $row) use ($periodEnd): array {
            $publicationDate = null === $row['publication_date'] ? null : new \DateTimeImmutable((string) $row['publication_date']);
            $ageMonths = null === $publicationDate ? null : max(1, ((int) $periodEnd->format('Y') - (int) $publicationDate->format('Y')) * 12 + ((int) $periodEnd->format('n') - (int) $publicationDate->format('n')) + 1);
            $citedBy = (int) ($row['cited_by_count'] ?? 0);

            return [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'year' => null === $row['publication_year'] ? null : (int) $row['publication_year'],
                'date' => $row['publication_date'] ?? null,
                'authors' => (string) ($row['author_names'] ?? ''),
                'source' => $row['source'] ?? null,
                'citedBy' => $citedBy,
                'citationVelocity' => null === $ageMonths ? null : $citedBy / $ageMonths,
                'reasonSelected' => (string) $row['reason_selected'],
            ];
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeQuarterReports(array $rows): array
    {
        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'topicId' => (int) $row['topic_id'],
            'periodStart' => $row['period_start'],
            'periodEnd' => $row['period_end'],
            'periodKey' => (string) $row['period_key'],
            'summary' => $row['summary'] ?? null,
            'periodCharacterization' => $row['period_characterization'] ?? null,
            'dynamicsSummary' => $row['dynamics_summary'] ?? null,
            'futureDynamics' => $row['future_dynamics'] ?? null,
            'metrics' => $this->jsonObject($row['metrics'] ?? '{}'),
            'keywordDynamics' => $this->jsonObject($row['keyword_dynamics'] ?? '{}'),
            'items' => $this->jsonList($row['items_json'] ?? '[]'),
            'papers' => $this->jsonList($row['papers_json'] ?? '[]'),
        ], $rows);
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
     * @param array<string, int>  $counts
     * @param array<string, true> $coveredMonths
     *
     * @return array{start: string, end: string, papers: int, expectedMonths: int, observedMonths: int, coverage: float, isComplete: bool}
     */
    private function windowInfo(array $counts, array $coveredMonths, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $papers = 0;
        $observed = 0;
        $months = $this->monthKeys($start, $end);
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

    /**
     * @param array{topic: array<string, int>, subfield: array<string, int>} $counts
     * @param array<string, true>                                            $coveredMonths
     *
     * @return list<float>
     */
    private function monthlyShares(array $counts, \DateTimeImmutable $start, \DateTimeImmutable $end, array $coveredMonths): array
    {
        $shares = [];
        foreach ($this->monthKeys($start, $end) as $month) {
            if (isset($coveredMonths[$month])) {
                $shares[] = $this->safeDivide((int) ($counts['topic'][$month] ?? 0), (int) ($counts['subfield'][$month] ?? 0), 0.0);
            }
        }

        return $shares;
    }

    private function growth(int|float $recent, int|float $previous, bool $recentComplete, bool $previousComplete): ?float
    {
        if (!$recentComplete || !$previousComplete || (float) $previous <= 0.0) {
            return null;
        }

        return ((float) $recent / (float) $previous) - 1.0;
    }

    private function safeDivide(int|float $numerator, int|float $denominator, float $default): float
    {
        return 0.0 === (float) $denominator ? $default : (float) $numerator / (float) $denominator;
    }

    /**
     * @param list<float|int> $values
     */
    private function average(array $values): float
    {
        return [] === $values ? 0.0 : array_sum($values) / count($values);
    }

    /**
     * @param list<float|int> $values
     */
    private function standardDeviation(array $values): float
    {
        if ([] === $values) {
            return 0.0;
        }
        $average = $this->average($values);
        $variance = array_sum(array_map(fn (float|int $value): float => ((float) $value - $average) ** 2, $values)) / count($values);

        return sqrt($variance);
    }

    /**
     * @return list<string>
     */
    private function monthKeys(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $months = [];
        $cursor = $this->monthStart($start);
        $last = $this->monthStart($end);
        while ($cursor <= $last) {
            $months[] = $cursor->format('Y-m');
            $cursor = $this->addMonths($cursor, 1);
        }

        return $months;
    }

    private function addMonths(\DateTimeImmutable $date, int $months): \DateTimeImmutable
    {
        return $date->modify(($months >= 0 ? '+' : '') . $months . ' months');
    }

    private function monthStart(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->modify('first day of this month')->setTime(0, 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonObject(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }
}
