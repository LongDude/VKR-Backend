<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class TopicAnalyticsRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTopicsByField(int $fieldId, int $limit): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
WITH max_period AS (
    SELECT MAX(s.period_start) AS period_end
    FROM openalex_montly_topic_stats s
    JOIN topics t ON t.id = s.topic_id
    JOIN subfields sf ON sf.id = t.subfield_id
    WHERE sf.field_id = :field_id
),
recent AS (
    SELECT
        t.id AS topic_id,
        SUM(s.works_count)::bigint AS recent_12m_papers
    FROM topics t
    JOIN openalex_montly_topic_stats s ON s.topic_id = t.id
    CROSS JOIN max_period mp
    WHERE mp.period_end IS NOT NULL
      AND s.period_start BETWEEN (mp.period_end - INTERVAL '11 months')::date AND mp.period_end
    GROUP BY t.id
)
SELECT
    t.id,
    t.name,
    t.openalex_id,
    sf.id AS subfield_id,
    sf.name AS subfield_name,
    COALESCE(recent.recent_12m_papers, 0)::bigint AS recent_12m_papers
FROM topics t
JOIN subfields sf ON sf.id = t.subfield_id
LEFT JOIN recent ON recent.topic_id = t.id
WHERE sf.field_id = :field_id
ORDER BY COALESCE(recent.recent_12m_papers, 0) DESC, t.name ASC
LIMIT :limit
SQL,
            [
                'field_id' => $fieldId,
                'limit' => $limit,
            ],
            [
                'field_id' => ParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTopic(int $topicId): ?array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
SELECT
    t.id,
    t.name,
    t.openalex_id,
    sf.id AS subfield_id,
    sf.name AS subfield_name,
    f.id AS field_id,
    f.name AS field_name,
    d.id AS domain_id,
    d.name AS domain_name
FROM topics t
LEFT JOIN subfields sf ON sf.id = t.subfield_id
LEFT JOIN fields f ON f.id = sf.field_id
LEFT JOIN domains d ON d.id = f.domain_id
WHERE t.id = :topic_id
SQL,
            ['topic_id' => $topicId],
            ['topic_id' => ParameterType::INTEGER],
        );

        return false === $row ? null : $row;
    }

    public function findMaxPeriodForTopic(int $topicId): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
SELECT MAX(period_start)::date
FROM openalex_montly_topic_stats
WHERE topic_id = :topic_id
SQL,
            ['topic_id' => $topicId],
            ['topic_id' => ParameterType::INTEGER],
        );

        return false === $value || null === $value ? null : new \DateTimeImmutable((string) $value);
    }

    /**
     * @return list<string>
     */
    public function loadCoveredMonthsForSubfield(int $subfieldId, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        $rows = $this->connection->fetchFirstColumn(
            <<<'SQL'
SELECT DISTINCT TO_CHAR(s.period_start, 'YYYY-MM') AS period_month
FROM openalex_montly_topic_stats s
JOIN topics t ON t.id = s.topic_id
WHERE t.subfield_id = :subfield_id
  AND s.period_start BETWEEN :date_from AND :date_to
ORDER BY period_month ASC
SQL,
            [
                'subfield_id' => $subfieldId,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
            ],
            [
                'subfield_id' => ParameterType::INTEGER,
                'date_from' => ParameterType::STRING,
                'date_to' => ParameterType::STRING,
            ],
        );

        return array_map('strval', $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadTopicSubfieldMonthlyStats(int $topicId, int $subfieldId, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
WITH topic_counts AS (
    SELECT period_start, SUM(works_count)::bigint AS topic_papers
    FROM openalex_montly_topic_stats
    WHERE topic_id = :topic_id
      AND period_start BETWEEN :date_from AND :date_to
    GROUP BY period_start
),
subfield_counts AS (
    SELECT s.period_start, SUM(s.works_count)::bigint AS subfield_papers
    FROM openalex_montly_topic_stats s
    JOIN topics t ON t.id = s.topic_id
    WHERE t.subfield_id = :subfield_id
      AND s.period_start BETWEEN :date_from AND :date_to
    GROUP BY s.period_start
)
SELECT
    COALESCE(sc.period_start, tc.period_start)::date AS period_start,
    COALESCE(tc.topic_papers, 0)::bigint AS topic_papers,
    COALESCE(sc.subfield_papers, 0)::bigint AS subfield_papers
FROM subfield_counts sc
FULL OUTER JOIN topic_counts tc ON tc.period_start = sc.period_start
ORDER BY period_start ASC
SQL,
            [
                'topic_id' => $topicId,
                'subfield_id' => $subfieldId,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
            ],
            [
                'topic_id' => ParameterType::INTEGER,
                'subfield_id' => ParameterType::INTEGER,
                'date_from' => ParameterType::STRING,
                'date_to' => ParameterType::STRING,
            ],
        );
    }

    public function loadCitationVelocity(int $topicId, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): ?float
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
SELECT AVG(
    COALESCE(p.cited_by_count, 0)::numeric /
    GREATEST(1, EXTRACT(YEAR FROM age(:date_to::date, COALESCE(p.publication_date, :date_to::date))) * 12
        + EXTRACT(MONTH FROM age(:date_to::date, COALESCE(p.publication_date, :date_to::date))) + 1)
)::float
FROM papers p
JOIN paper_topics pt ON pt.paper_id = p.id
WHERE pt.topic_id = :topic_id
  AND p.publication_date BETWEEN :date_from AND :date_to
SQL,
            [
                'topic_id' => $topicId,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
            ],
            [
                'topic_id' => ParameterType::INTEGER,
                'date_from' => ParameterType::STRING,
                'date_to' => ParameterType::STRING,
            ],
        );

        return false === $value || null === $value ? null : (float) $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadRepresentativeWorks(int $topicId, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo, int $limit): array
    {
        $representativeIds = $this->loadRepresentativePaperIds($topicId, $dateFrom, $dateTo, $limit);
        $rows = [];
        if ([] !== $representativeIds) {
            $rows = $this->loadPaperSummariesByIds($representativeIds, 'Representative cluster paper');
        }

        $existingIds = array_map(fn (array $row): int => (int) $row['id'], $rows);
        $topCited = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT p.id
FROM papers p
JOIN paper_topics pt ON pt.paper_id = p.id
WHERE pt.topic_id = :topic_id
  AND p.publication_date BETWEEN :date_from AND :date_to
  AND (:existing_count = 0 OR p.id NOT IN (:existing_ids))
ORDER BY p.cited_by_count DESC NULLS LAST, p.publication_date DESC NULLS LAST, p.id DESC
LIMIT :limit
SQL,
            [
                'topic_id' => $topicId,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'existing_count' => count($existingIds),
                'existing_ids' => [] === $existingIds ? [0] : $existingIds,
                'limit' => max(0, $limit - count($rows)),
            ],
            [
                'topic_id' => ParameterType::INTEGER,
                'date_from' => ParameterType::STRING,
                'date_to' => ParameterType::STRING,
                'existing_count' => ParameterType::INTEGER,
                'existing_ids' => ArrayParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
            ],
        );
        $topIds = array_map(fn (array $row): int => (int) $row['id'], $topCited);

        return array_slice([...$rows, ...$this->loadPaperSummariesByIds($topIds, 'Highly cited in selected period')], 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadQuarterReports(int $topicId, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    r.id,
    r.topic_id,
    r.period_start::date AS period_start,
    r.period_end::date AS period_end,
    r.period_key,
    r.summary,
    r.period_characterization,
    r.dynamics_summary,
    r.future_dynamics,
    r.metrics,
    r.keyword_dynamics,
    COALESCE(items.items, '[]'::json)::text AS items_json,
    COALESCE(papers.papers, '[]'::json)::text AS papers_json
FROM topic_quarter_reports r
LEFT JOIN LATERAL (
    SELECT json_agg(json_build_object(
        'id', i.id,
        'itemType', i.item_type,
        'title', i.title,
        'description', i.description,
        'maturity', i.maturity,
        'evidence', i.evidence,
        'sortOrder', i.sort_order
    ) ORDER BY i.sort_order, i.id) AS items
    FROM topic_quarter_report_items i
    WHERE i.report_id = r.id
) items ON TRUE
LEFT JOIN LATERAL (
    SELECT json_agg(json_build_object(
        'paperId', rp.paper_id,
        'role', rp.role,
        'score', rp.score,
        'note', rp.note,
        'title', p.title,
        'year', p.publication_year
    ) ORDER BY rp.role, rp.score DESC NULLS LAST) AS papers
    FROM topic_quarter_report_papers rp
    JOIN papers p ON p.id = rp.paper_id
    WHERE rp.report_id = r.id
) papers ON TRUE
WHERE r.topic_id = :topic_id
  AND r.period_start <= :date_to
  AND r.period_end >= :date_from
ORDER BY r.period_start DESC
SQL,
            [
                'topic_id' => $topicId,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
            ],
            [
                'topic_id' => ParameterType::INTEGER,
                'date_from' => ParameterType::STRING,
                'date_to' => ParameterType::STRING,
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPaper(int $paperId, ?int $userId = null): ?array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
SELECT
    p.id,
    p.title,
    p.doi,
    p.openalex_id,
    p.publication_year,
    p.publication_date::date AS publication_date,
    p.language,
    p.abstract,
    p.extracted_keywords::text AS extracted_keywords_json,
    p.is_open_access,
    p.cited_by_count,
    p.references_count,
    CASE
        WHEN :user_id <= 0 THEN false
        ELSE EXISTS (
            SELECT 1 FROM user_favourite_papers f
            WHERE f.user_id = :user_id AND f.paper_id = p.id
        )
    END AS is_favorite,
    COALESCE(authors.authors, '[]'::json)::text AS authors_json,
    COALESCE(keywords.keywords, '[]'::json)::text AS keywords_json,
    COALESCE(topics.topics, '[]'::json)::text AS topics_json,
    COALESCE(landings.landings, '[]'::json)::text AS landings_json
FROM papers p
LEFT JOIN LATERAL (
    SELECT json_agg(json_build_object('id', a.id, 'name', a.display_name, 'order', pa.author_order) ORDER BY pa.author_order NULLS LAST, a.display_name) AS authors
    FROM paper_authors pa
    JOIN authors a ON a.id = pa.author_id
    WHERE pa.paper_id = p.id
) authors ON TRUE
LEFT JOIN LATERAL (
    SELECT json_agg(json_build_object('id', k.id, 'value', k.value, 'score', pk.score) ORDER BY pk.score DESC NULLS LAST, k.value) AS keywords
    FROM paper_keywords pk
    JOIN keywords k ON k.id = pk.keyword_id
    WHERE pk.paper_id = p.id
) keywords ON TRUE
LEFT JOIN LATERAL (
    SELECT json_agg(json_build_object('id', t.id, 'name', t.name, 'score', pt.score) ORDER BY pt.score DESC NULLS LAST, t.name) AS topics
    FROM paper_topics pt
    JOIN topics t ON t.id = pt.topic_id
    WHERE pt.paper_id = p.id
) topics ON TRUE
LEFT JOIN LATERAL (
    SELECT json_agg(json_build_object('url', l.landing_url, 'pdfUrl', l.pdf_url, 'license', l.license, 'isBest', l.is_best) ORDER BY l.is_best DESC NULLS LAST, l.id) AS landings
    FROM landings l
    WHERE l.paper_id = p.id
) landings ON TRUE
WHERE p.id = :paper_id
SQL,
            ['paper_id' => $paperId, 'user_id' => $userId ?? 0],
            ['paper_id' => ParameterType::INTEGER, 'user_id' => ParameterType::INTEGER],
        );

        return false === $row ? null : $row;
    }

    /**
     * @return list<int>
     */
    private function loadRepresentativePaperIds(int $topicId, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo, int $limit): array
    {
        $rows = $this->connection->fetchFirstColumn(
            <<<'SQL'
SELECT jsonb_array_elements_text(ps.representative_paper_ids)::bigint AS paper_id
FROM research_clusters c
JOIN research_cluster_period_stats ps ON ps.cluster_id = c.id
WHERE c.cluster_key = :cluster_key
  AND ps.period_start <= :date_to
  AND ps.period_end >= :date_from
  AND jsonb_typeof(ps.representative_paper_ids) = 'array'
ORDER BY ps.period_end DESC, ps.id DESC
LIMIT :limit
SQL,
            [
                'cluster_key' => 'topic:' . $topicId,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'limit' => $limit,
            ],
            [
                'cluster_key' => ParameterType::STRING,
                'date_from' => ParameterType::STRING,
                'date_to' => ParameterType::STRING,
                'limit' => ParameterType::INTEGER,
            ],
        );

        return array_values(array_unique(array_map('intval', $rows)));
    }

    /**
     * @param list<int> $paperIds
     *
     * @return list<array<string, mixed>>
     */
    private function loadPaperSummariesByIds(array $paperIds, string $reason): array
    {
        if ([] === $paperIds) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    p.id,
    p.title,
    p.publication_year,
    p.publication_date::date AS publication_date,
    p.doi,
    p.openalex_id,
    p.extracted_keywords::text AS extracted_keywords_json,
    p.cited_by_count,
    COALESCE(authors.author_names, '') AS author_names,
    COALESCE(keywords.keywords, '[]'::json)::text AS keywords_json,
    COALESCE(best_landing.landing_url, p.doi, p.openalex_id) AS source,
    :reason AS reason_selected
FROM papers p
LEFT JOIN LATERAL (
    SELECT string_agg(a.display_name, ', ' ORDER BY pa.author_order NULLS LAST, a.display_name) AS author_names
    FROM paper_authors pa
    JOIN authors a ON a.id = pa.author_id
    WHERE pa.paper_id = p.id
) authors ON TRUE
LEFT JOIN LATERAL (
    SELECT json_agg(json_build_object('id', k.id, 'value', k.value, 'score', pk.score) ORDER BY pk.score DESC NULLS LAST, k.value) AS keywords
    FROM paper_keywords pk
    JOIN keywords k ON k.id = pk.keyword_id
    WHERE pk.paper_id = p.id
) keywords ON TRUE
LEFT JOIN LATERAL (
    SELECT landing_url
    FROM landings l
    WHERE l.paper_id = p.id
    ORDER BY l.is_best DESC NULLS LAST, l.id ASC
    LIMIT 1
) best_landing ON TRUE
WHERE p.id IN (:paper_ids)
ORDER BY p.cited_by_count DESC NULLS LAST, p.publication_date DESC NULLS LAST, p.id DESC
SQL,
            [
                'paper_ids' => $paperIds,
                'reason' => $reason,
            ],
            [
                'paper_ids' => ArrayParameterType::INTEGER,
                'reason' => ParameterType::STRING,
            ],
        );
    }
}
