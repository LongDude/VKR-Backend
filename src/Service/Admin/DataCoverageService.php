<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DataCoverageService
{
    private const DEFAULT_MONTHS = 36;

    private const PANELS = [
        'monthly-stats' => [
            'title' => 'Сбор статистики месячных публикаций с OpenAlex',
            'periodKind' => 'month',
        ],
        'cluster-dynamics' => [
            'title' => 'Анализ динамики кластеров',
            'periodKind' => 'month',
        ],
        'sample-papers' => [
            'title' => 'Сбор sample статей',
            'periodKind' => 'month',
        ],
        'keyphrases' => [
            'title' => 'Извлечение ключевых фраз',
            'periodKind' => 'month',
        ],
        'quarter-reports' => [
            'title' => 'Формирование характеристики (LLM-отчетов)',
            'periodKind' => 'quarter',
        ],
    ];

    private const MONTH_ROWS = [
        ['key' => '01', 'label' => 'Янв'],
        ['key' => '02', 'label' => 'Фев'],
        ['key' => '03', 'label' => 'Мар'],
        ['key' => '04', 'label' => 'Апр'],
        ['key' => '05', 'label' => 'Май'],
        ['key' => '06', 'label' => 'Июн'],
        ['key' => '07', 'label' => 'Июл'],
        ['key' => '08', 'label' => 'Авг'],
        ['key' => '09', 'label' => 'Сен'],
        ['key' => '10', 'label' => 'Окт'],
        ['key' => '11', 'label' => 'Ноя'],
        ['key' => '12', 'label' => 'Дек'],
    ];

    private const QUARTER_ROWS = [
        ['key' => 'Q1', 'label' => 'I кв.'],
        ['key' => 'Q2', 'label' => 'II кв.'],
        ['key' => 'Q3', 'label' => 'III кв.'],
        ['key' => 'Q4', 'label' => 'IV кв.'],
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<string>
     */
    public function panelKeys(): array
    {
        return array_keys(self::PANELS);
    }

    public function hasPanel(string $panelKey): bool
    {
        return isset(self::PANELS[$panelKey]);
    }

    /**
     * @param list<int> $topicIds
     *
     * @return array<string, mixed>
     */
    public function buildPanel(string $panelKey, array $topicIds, ?string $periodFrom, ?string $periodTo): array
    {
        $meta = self::PANELS[$panelKey] ?? null;
        if (null === $meta) {
            throw new \InvalidArgumentException('Unsupported coverage panel.');
        }

        [$dateFrom, $dateTo] = $this->resolveMonthRange($periodFrom, $periodTo);
        $topicIds = $this->normalizeTopicIds($topicIds);
        $expectedTopics = count($topicIds);
        $actualByPeriod = $this->loadActualCounts($panelKey, $topicIds, $dateFrom, $dateTo);
        $periodKind = $meta['periodKind'];
        $periods = 'quarter' === $periodKind
            ? $this->quarterPeriods($dateFrom, $dateTo)
            : $this->monthPeriods($dateFrom, $dateTo);

        $cells = [];
        $missingCount = 0;
        foreach ($periods as $period) {
            $actual = min($expectedTopics, $actualByPeriod[$period['key']] ?? 0);
            $missingCount += max(0, $expectedTopics - $actual);
            $percentage = 0 === $expectedTopics ? 0 : (int) round($actual / $expectedTopics * 100);
            $status = match (true) {
                0 === $actual => 'none',
                $actual >= $expectedTopics => 'full',
                default => 'partial',
            };

            $cells[] = [
                'period' => $period['key'],
                'year' => $period['year'],
                'rowKey' => $period['rowKey'],
                'rowLabel' => $period['rowLabel'],
                'expected' => $expectedTopics,
                'actual' => $actual,
                'percentage' => $percentage,
                'status' => $status,
            ];
        }

        return [
            'key' => $panelKey,
            'title' => $meta['title'],
            'periodKind' => $periodKind,
            'periodFrom' => $dateFrom->format('Y-m'),
            'periodTo' => $dateTo->format('Y-m'),
            'years' => $this->years($dateFrom, $dateTo),
            'rows' => 'quarter' === $periodKind ? self::QUARTER_ROWS : self::MONTH_ROWS,
            'cells' => $cells,
            'expectedTopics' => $expectedTopics,
            'missingCount' => $missingCount,
        ];
    }

    /**
     * @param list<int> $topicIds
     *
     * @return list<array{id: int, name: string}>
     */
    public function topicsByIds(array $topicIds): array
    {
        $topicIds = $this->normalizeTopicIds($topicIds);
        if ([] === $topicIds) {
            return [];
        }

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            $this->connection->fetchAllAssociative(
                <<<'SQL'
SELECT id, name
FROM topics
WHERE id IN (:topic_ids)
ORDER BY name ASC
SQL,
                ['topic_ids' => $topicIds],
                ['topic_ids' => ArrayParameterType::INTEGER],
            ),
        );
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function resolveMonthRange(?string $periodFrom, ?string $periodTo): array
    {
        $dateTo = $this->parseMonth($periodTo) ?? new \DateTimeImmutable('first day of this month');
        $dateFrom = $this->parseMonth($periodFrom) ?? $dateTo->modify('-' . (self::DEFAULT_MONTHS - 1) . ' months');

        if ($dateFrom > $dateTo) {
            throw new \InvalidArgumentException('periodFrom must not be later than periodTo.');
        }

        return [$dateFrom, $dateTo];
    }

    private function parseMonth(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        if (1 !== preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            throw new \InvalidArgumentException('Periods must use YYYY-MM format.');
        }

        return new \DateTimeImmutable($value . '-01');
    }

    /**
     * @param list<int> $topicIds
     *
     * @return array<string, int>
     */
    private function loadActualCounts(string $panelKey, array $topicIds, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        if ([] === $topicIds) {
            return [];
        }

        return match ($panelKey) {
            'monthly-stats' => $this->monthlyStatsCounts($topicIds, $dateFrom, $dateTo),
            'cluster-dynamics' => $this->clusterDynamicsCounts($topicIds, $dateFrom, $dateTo),
            'sample-papers' => $this->samplePaperCounts($topicIds, $dateFrom, $dateTo, false),
            'keyphrases' => $this->samplePaperCounts($topicIds, $dateFrom, $dateTo, true),
            'quarter-reports' => $this->quarterReportCounts($topicIds, $dateFrom, $dateTo),
            default => [],
        };
    }

    /**
     * @param list<int> $topicIds
     *
     * @return array<string, int>
     */
    private function monthlyStatsCounts(array $topicIds, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        return $this->periodCounts(
            <<<'SQL'
SELECT TO_CHAR(period_start, 'YYYY-MM') AS period_key, COUNT(DISTINCT topic_id)::int AS actual
FROM openalex_montly_topic_stats
WHERE topic_id IN (:topic_ids)
  AND period_start >= :date_from
  AND period_start < (:date_to::date + INTERVAL '1 month')
GROUP BY period_key
SQL,
            $topicIds,
            $dateFrom,
            $dateTo,
        );
    }

    /**
     * @param list<int> $topicIds
     *
     * @return array<string, int>
     */
    private function clusterDynamicsCounts(array $topicIds, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        $clusterKeys = array_map(static fn (int $id): string => 'topic:' . $id, $topicIds);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
WITH selected_clusters AS (
    SELECT
        id,
        CASE
            WHEN source_topic_id IS NOT NULL THEN source_topic_id
            WHEN cluster_key ~ '^topic:[0-9]+$' THEN SUBSTRING(cluster_key FROM 7)::bigint
            ELSE NULL
        END AS topic_id
    FROM research_clusters
    WHERE source_topic_id IN (:topic_ids)
       OR cluster_key IN (:cluster_keys)
),
periods AS (
    SELECT GENERATE_SERIES(:date_from::date, :date_to::date, INTERVAL '1 month')::date AS period_start
)
SELECT TO_CHAR(p.period_start, 'YYYY-MM') AS period_key, COUNT(DISTINCT sc.topic_id)::int AS actual
FROM periods p
JOIN research_cluster_period_stats ps
  ON ps.period_start <= (p.period_start + INTERVAL '1 month' - INTERVAL '1 day')::date
 AND ps.period_end >= p.period_start
JOIN selected_clusters sc ON sc.id = ps.cluster_id
WHERE sc.topic_id IN (:topic_ids)
GROUP BY period_key
SQL,
            [
                'topic_ids' => $topicIds,
                'cluster_keys' => $clusterKeys,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
            ],
            [
                'topic_ids' => ArrayParameterType::INTEGER,
                'cluster_keys' => ArrayParameterType::STRING,
                'date_from' => ParameterType::STRING,
                'date_to' => ParameterType::STRING,
            ],
        );

        return $this->countsByPeriod($rows);
    }

    /**
     * @param list<int> $topicIds
     *
     * @return array<string, int>
     */
    private function samplePaperCounts(array $topicIds, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo, bool $requireKeyphrases): array
    {
        $keyphraseClause = $requireKeyphrases
            ? "AND p.extracted_keywords IS NOT NULL AND p.extracted_keywords <> '{}'::jsonb AND p.extracted_keywords <> '[]'::jsonb"
            : '';

        return $this->periodCounts(
            sprintf(
                <<<'SQL'
SELECT TO_CHAR(DATE_TRUNC('month', p.publication_date), 'YYYY-MM') AS period_key,
       COUNT(DISTINCT p.primary_topic_id)::int AS actual
FROM papers p
WHERE p.primary_topic_id IN (:topic_ids)
  AND p.publication_date >= :date_from
  AND p.publication_date < (:date_to::date + INTERVAL '1 month')
  %s
GROUP BY period_key
SQL,
                $keyphraseClause,
            ),
            $topicIds,
            $dateFrom,
            $dateTo,
        );
    }

    /**
     * @param list<int> $topicIds
     *
     * @return array<string, int>
     */
    private function quarterReportCounts(array $topicIds, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        [$quarterFrom, $quarterTo] = $this->quarterRange($dateFrom, $dateTo);
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
WITH periods AS (
    SELECT GENERATE_SERIES(:date_from::date, :date_to::date, INTERVAL '3 months')::date AS period_start
)
SELECT
    EXTRACT(YEAR FROM p.period_start)::int || '-Q' || EXTRACT(QUARTER FROM p.period_start)::int AS period_key,
    COUNT(DISTINCT r.topic_id)::int AS actual
FROM periods p
JOIN topic_quarter_reports r
  ON r.topic_id IN (:topic_ids)
 AND r.period_start <= (p.period_start + INTERVAL '3 months' - INTERVAL '1 day')::date
 AND r.period_end >= p.period_start
GROUP BY 1
SQL,
            [
                'topic_ids' => $topicIds,
                'date_from' => $quarterFrom->format('Y-m-d'),
                'date_to' => $quarterTo->format('Y-m-d'),
            ],
            [
                'topic_ids' => ArrayParameterType::INTEGER,
                'date_from' => ParameterType::STRING,
                'date_to' => ParameterType::STRING,
            ],
        );

        return $this->countsByPeriod($rows);
    }

    /**
     * @param list<int> $topicIds
     *
     * @return array<string, int>
     */
    private function periodCounts(string $sql, array $topicIds, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        return $this->countsByPeriod($this->connection->fetchAllAssociative(
            $sql,
            [
                'topic_ids' => $topicIds,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
            ],
            [
                'topic_ids' => ArrayParameterType::INTEGER,
                'date_from' => ParameterType::STRING,
                'date_to' => ParameterType::STRING,
            ],
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int>
     */
    private function countsByPeriod(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['period_key']] = (int) $row['actual'];
        }

        return $counts;
    }

    /**
     * @param list<int> $topicIds
     *
     * @return list<int>
     */
    private function normalizeTopicIds(array $topicIds): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $topicIds),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * @return list<int>
     */
    private function years(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        $years = range((int) $dateFrom->format('Y'), (int) $dateTo->format('Y'));

        return array_map('intval', $years);
    }

    /**
     * @return list<array{key: string, year: int, rowKey: string, rowLabel: string}>
     */
    private function monthPeriods(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        $periods = [];
        for ($cursor = $dateFrom; $cursor <= $dateTo; $cursor = $cursor->modify('+1 month')) {
            $month = $cursor->format('m');
            $periods[] = [
                'key' => $cursor->format('Y-m'),
                'year' => (int) $cursor->format('Y'),
                'rowKey' => $month,
                'rowLabel' => self::MONTH_ROWS[((int) $month) - 1]['label'],
            ];
        }

        return $periods;
    }

    /**
     * @return list<array{key: string, year: int, rowKey: string, rowLabel: string}>
     */
    private function quarterPeriods(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        [$quarterFrom, $quarterTo] = $this->quarterRange($dateFrom, $dateTo);
        $periods = [];
        for ($cursor = $quarterFrom; $cursor <= $quarterTo; $cursor = $cursor->modify('+3 months')) {
            $quarter = (int) ceil(((int) $cursor->format('n')) / 3);
            $periods[] = [
                'key' => $cursor->format('Y') . '-Q' . $quarter,
                'year' => (int) $cursor->format('Y'),
                'rowKey' => 'Q' . $quarter,
                'rowLabel' => self::QUARTER_ROWS[$quarter - 1]['label'],
            ];
        }

        return $periods;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function quarterRange(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        $fromQuarterMonth = ((int) floor(((int) $dateFrom->format('n') - 1) / 3) * 3) + 1;
        $toQuarterMonth = ((int) floor(((int) $dateTo->format('n') - 1) / 3) * 3) + 1;

        return [
            $dateFrom->setDate((int) $dateFrom->format('Y'), $fromQuarterMonth, 1),
            $dateTo->setDate((int) $dateTo->format('Y'), $toQuarterMonth, 1),
        ];
    }
}
