<?php

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class PostgresTextArrayType extends Type
{
    public const NAME = 'postgres_text_array';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'text[]';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_array($value)) {
            $value = [$value];
        }

        $items = array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $value),
            static fn (string $item): bool => '' !== $item,
        ));

        if ([] === $items) {
            return '{}';
        }

        return '{' . implode(',', array_map($this->quoteArrayItem(...), $items)) . '}';
    }

    /**
     * @return list<string>
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): array
    {
        if (null === $value || [] === $value) {
            return [];
        }

        if (\is_array($value)) {
            return $this->normalizeItems($value);
        }

        if (\is_resource($value)) {
            $value = stream_get_contents($value);
        }

        if (!\is_string($value)) {
            return [];
        }

        return $this->normalizeItems($this->parseArrayLiteral($value));
    }

    /**
     * @return list<string>
     */
    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return ['_text'];
    }

    private function quoteArrayItem(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * @param array<mixed> $items
     *
     * @return list<string>
     */
    private function normalizeItems(array $items): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $items),
            static fn (string $item): bool => '' !== $item,
        ));
    }

    /**
     * @return list<string|null>
     */
    private function parseArrayLiteral(string $value): array
    {
        $value = trim($value);
        if ('' === $value || '{}' === $value) {
            return [];
        }

        if (!str_starts_with($value, '{') || !str_ends_with($value, '}')) {
            return [$value];
        }

        $body = substr($value, 1, -1);
        $items = [];
        $item = '';
        $quoted = false;
        $escaped = false;

        for ($i = 0, $length = \strlen($body); $i < $length; ++$i) {
            $char = $body[$i];

            if ($escaped) {
                $item .= $char;
                $escaped = false;
                continue;
            }

            if ('\\' === $char && $quoted) {
                $escaped = true;
                continue;
            }

            if ('"' === $char) {
                $quoted = !$quoted;
                continue;
            }

            if (',' === $char && !$quoted) {
                $items[] = 'NULL' === $item ? null : $item;
                $item = '';
                continue;
            }

            $item .= $char;
        }

        $items[] = 'NULL' === $item ? null : $item;

        return $items;
    }
}
