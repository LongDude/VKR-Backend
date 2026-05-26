<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class UserToolsRepository
{
    private const TABLES = [
        'domain' => ['table' => 'domains', 'id' => 'id', 'label' => 'name', 'link' => 'user_tracked_domains', 'link_id' => 'domain_id'],
        'field' => ['table' => 'fields', 'id' => 'id', 'label' => 'name', 'link' => 'user_tracked_fields', 'link_id' => 'field_id'],
        'subfield' => ['table' => 'subfields', 'id' => 'id', 'label' => 'name', 'link' => 'user_tracked_subfields', 'link_id' => 'subfield_id'],
        'topic' => ['table' => 'topics', 'id' => 'id', 'label' => 'name', 'link' => 'user_tracked_topics', 'link_id' => 'topic_id'],
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function entityExists(string $type, int $id): bool
    {
        $meta = $this->meta($type);

        return (bool) $this->connection->fetchOne(
            sprintf('SELECT EXISTS (SELECT 1 FROM %s WHERE id = :id)', $meta['table']),
            ['id' => $id],
            ['id' => ParameterType::INTEGER],
        );
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function listTracked(int $userId): array
    {
        $result = [];
        foreach (array_keys(self::TABLES) as $type) {
            $meta = $this->meta($type);
            $result[$this->plural($type)] = $this->connection->fetchAllAssociative(
                sprintf(
                    <<<'SQL'
SELECT e.id, e.name, l.created_at
FROM %s l
JOIN %s e ON e.id = l.%s
WHERE l.user_id = :user_id
ORDER BY l.created_at DESC, e.name ASC
SQL,
                    $meta['link'],
                    $meta['table'],
                    $meta['link_id'],
                ),
                ['user_id' => $userId],
                ['user_id' => ParameterType::INTEGER],
            );
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchOptions(string $type, string $query, int $limit): array
    {
        $meta = $this->meta($type);
        $where = '' === $query ? '' : 'WHERE LOWER(e.name) LIKE :query';
        $params = ['limit' => $limit];
        $types = ['limit' => ParameterType::INTEGER];
        if ('' !== $query) {
            $params['query'] = '%' . mb_strtolower($query) . '%';
            $types['query'] = ParameterType::STRING;
        }

        return $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT e.id, e.name FROM %s e %s ORDER BY e.name ASC LIMIT :limit',
                $meta['table'],
                $where,
            ),
            $params,
            $types,
        );
    }

    public function addTracked(int $userId, string $type, int $id): void
    {
        $meta = $this->meta($type);
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (user_id, %s) VALUES (:user_id, :entity_id) ON CONFLICT DO NOTHING',
                $meta['link'],
                $meta['link_id'],
            ),
            ['user_id' => $userId, 'entity_id' => $id],
            ['user_id' => ParameterType::INTEGER, 'entity_id' => ParameterType::INTEGER],
        );
    }

    public function removeTracked(int $userId, string $type, int $id): void
    {
        $meta = $this->meta($type);
        $this->connection->delete(
            $meta['link'],
            ['user_id' => $userId, $meta['link_id'] => $id],
            ['user_id' => ParameterType::INTEGER, $meta['link_id'] => ParameterType::INTEGER],
        );
    }

    public function addFavorite(int $userId, int $paperId): bool
    {
        if (!$this->paperExists($paperId)) {
            return false;
        }

        $this->connection->executeStatement(
            'INSERT INTO user_favourite_papers (user_id, paper_id) VALUES (:user_id, :paper_id) ON CONFLICT DO NOTHING',
            ['user_id' => $userId, 'paper_id' => $paperId],
            ['user_id' => ParameterType::INTEGER, 'paper_id' => ParameterType::INTEGER],
        );

        return true;
    }

    public function removeFavorite(int $userId, int $paperId): void
    {
        $this->connection->delete(
            'user_favourite_papers',
            ['user_id' => $userId, 'paper_id' => $paperId],
            ['user_id' => ParameterType::INTEGER, 'paper_id' => ParameterType::INTEGER],
        );
    }

    public function isFavorite(int $userId, int $paperId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM user_favourite_papers WHERE user_id = :user_id AND paper_id = :paper_id)',
            ['user_id' => $userId, 'paper_id' => $paperId],
            ['user_id' => ParameterType::INTEGER, 'paper_id' => ParameterType::INTEGER],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFavoritePapers(int $userId, int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    p.id,
    p.title,
    p.doi,
    p.openalex_id,
    p.publication_year,
    p.publication_date::date AS publication_date,
    p.language,
    p.is_open_access,
    p.cited_by_count,
    p.references_count,
    f.created_at AS favorite_created_at,
    COALESCE(authors.author_names, '') AS author_names
FROM user_favourite_papers f
JOIN papers p ON p.id = f.paper_id
LEFT JOIN LATERAL (
    SELECT string_agg(a.display_name, ', ' ORDER BY pa.author_order NULLS LAST, a.display_name) AS author_names
    FROM paper_authors pa
    JOIN authors a ON a.id = pa.author_id
    WHERE pa.paper_id = p.id
) authors ON TRUE
WHERE f.user_id = :user_id
ORDER BY f.created_at DESC, p.id DESC
LIMIT :limit OFFSET :offset
SQL,
            ['user_id' => $userId, 'limit' => $limit, 'offset' => $offset],
            ['user_id' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
    }

    public function countFavorites(int $userId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_favourite_papers WHERE user_id = :user_id',
            ['user_id' => $userId],
            ['user_id' => ParameterType::INTEGER],
        );
    }

    public function paperExists(int $paperId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM papers WHERE id = :paper_id)',
            ['paper_id' => $paperId],
            ['paper_id' => ParameterType::INTEGER],
        );
    }

    /**
     * @param array<string, list<int>> $tags
     *
     * @return list<string>
     */
    public function validateSelectedTags(array $tags): array
    {
        $errors = [];
        foreach (['domain' => 'domains', 'field' => 'fields', 'subfield' => 'subfields', 'topic' => 'topics'] as $type => $key) {
            $ids = array_values(array_unique(array_map('intval', $tags[$key] ?? [])));
            if ([] === $ids) {
                continue;
            }
            $meta = $this->meta($type);
            $existing = $this->connection->fetchFirstColumn(
                sprintf('SELECT id FROM %s WHERE id IN (:ids)', $meta['table']),
                ['ids' => $ids],
                ['ids' => ArrayParameterType::INTEGER],
            );
            $missing = array_values(array_diff($ids, array_map('intval', $existing)));
            if ([] !== $missing) {
                $errors[] = sprintf('Unknown %s ids: %s.', $key, implode(', ', $missing));
            }
        }

        return $errors;
    }

    /**
     * @return array{table: string, id: string, label: string, link: string, link_id: string}
     */
    private function meta(string $type): array
    {
        if (!isset(self::TABLES[$type])) {
            throw new \InvalidArgumentException('Unsupported tracked entity type.');
        }

        return self::TABLES[$type];
    }

    private function plural(string $type): string
    {
        return match ($type) {
            'domain' => 'domains',
            'field' => 'fields',
            'subfield' => 'subfields',
            'topic' => 'topics',
        };
    }
}
