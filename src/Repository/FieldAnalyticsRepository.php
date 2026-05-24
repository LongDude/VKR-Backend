<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class FieldAnalyticsRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFields(string $query, int $limit): array
    {
        $query = trim($query);
        $sql = <<<'SQL'
WITH max_period AS (
    SELECT MAX(period_start) AS period_end
    FROM openalex_montly_topic_stats
),
recent AS (
    SELECT
        f.id AS field_id,
        SUM(s.works_count)::bigint AS recent_12m_papers
    FROM fields f
    JOIN subfields sf ON sf.field_id = f.id
    JOIN topics t ON t.subfield_id = sf.id
    JOIN openalex_montly_topic_stats s ON s.topic_id = t.id
    CROSS JOIN max_period mp
    WHERE mp.period_end IS NOT NULL
      AND s.period_start BETWEEN (mp.period_end - INTERVAL '11 months')::date AND mp.period_end
    GROUP BY f.id
)
SELECT
    f.id,
    f.name,
    f.openalex_id,
    d.id AS domain_id,
    d.name AS domain_name,
    COALESCE(recent.recent_12m_papers, 0)::bigint AS recent_12m_papers
FROM fields f
LEFT JOIN domains d ON d.id = f.domain_id
LEFT JOIN recent ON recent.field_id = f.id
WHERE (:query = '' OR f.name ILIKE :query_like OR COALESCE(f.openalex_id, '') ILIKE :query_like)
ORDER BY
    CASE WHEN :query = '' THEN COALESCE(recent.recent_12m_papers, 0) ELSE 0 END DESC,
    f.name ASC
LIMIT :limit
SQL;

        return $this->connection->fetchAllAssociative(
            $sql,
            [
                'query' => $query,
                'query_like' => '%' . $query . '%',
                'limit' => $limit,
            ],
            [
                'query' => ParameterType::STRING,
                'query_like' => ParameterType::STRING,
                'limit' => ParameterType::INTEGER,
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findField(int $fieldId): ?array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
SELECT
    f.id,
    f.name,
    f.openalex_id,
    d.id AS domain_id,
    d.name AS domain_name,
    COUNT(DISTINCT sf.id)::integer AS subfields_count
FROM fields f
LEFT JOIN domains d ON d.id = f.domain_id
LEFT JOIN subfields sf ON sf.field_id = f.id
WHERE f.id = :field_id
GROUP BY f.id, f.name, f.openalex_id, d.id, d.name
SQL,
            ['field_id' => $fieldId],
            ['field_id' => ParameterType::INTEGER],
        );

        return false === $row ? null : $row;
    }

    /**
     * @return list<string>
     */
    public function loadCoveredMonthsForField(int $fieldId, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        $rows = $this->connection->fetchFirstColumn(
            <<<'SQL'
SELECT DISTINCT TO_CHAR(s.period_start, 'YYYY-MM') AS period_month
FROM openalex_montly_topic_stats s
JOIN topics t ON t.id = s.topic_id
JOIN subfields sf ON sf.id = t.subfield_id
WHERE sf.field_id = :field_id
  AND s.period_start BETWEEN :date_from AND :date_to
ORDER BY period_month ASC
SQL,
            [
                'field_id' => $fieldId,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
            ],
            [
                'field_id' => ParameterType::INTEGER,
                'date_from' => ParameterType::STRING,
                'date_to' => ParameterType::STRING,
            ],
        );

        return array_map('strval', $rows);
    }

    public function findMaxPeriodForField(int $fieldId): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
SELECT MAX(s.period_start)::date
FROM openalex_montly_topic_stats s
JOIN topics t ON t.id = s.topic_id
JOIN subfields sf ON sf.id = t.subfield_id
WHERE sf.field_id = :field_id
SQL,
            ['field_id' => $fieldId],
            ['field_id' => ParameterType::INTEGER],
        );

        if (false === $value || null === $value) {
            return null;
        }

        return new \DateTimeImmutable((string) $value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadTopicMonthlyStats(int $fieldId, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    t.id AS topic_id,
    t.name AS topic_name,
    sf.id AS subfield_id,
    sf.name AS subfield_name,
    f.id AS field_id,
    f.name AS field_name,
    s.period_start::date AS period_start,
    SUM(s.works_count)::bigint AS works_count
FROM openalex_montly_topic_stats s
JOIN topics t ON t.id = s.topic_id
JOIN subfields sf ON sf.id = t.subfield_id
JOIN fields f ON f.id = sf.field_id
WHERE f.id = :field_id
  AND s.period_start BETWEEN :date_from AND :date_to
GROUP BY
    t.id,
    t.name,
    sf.id,
    sf.name,
    f.id,
    f.name,
    s.period_start
ORDER BY s.period_start ASC, sf.name ASC, t.name ASC
SQL,
            [
                'field_id' => $fieldId,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
            ],
            [
                'field_id' => ParameterType::INTEGER,
                'date_from' => ParameterType::STRING,
                'date_to' => ParameterType::STRING,
            ],
        );
    }
}
