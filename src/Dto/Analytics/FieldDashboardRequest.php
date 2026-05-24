<?php

declare(strict_types=1);

namespace App\Dto\Analytics;

use Symfony\Component\HttpFoundation\Request;

final class FieldDashboardRequest
{
    public const COMPARISON_WINDOWS = [6, 12, 24];
    public const MOVING_AVERAGE_WINDOWS = [1, 2, 3];

    /**
     * @param list<string> $errors
     */
    private function __construct(
        public readonly ?\DateTimeImmutable $periodStart,
        public readonly ?\DateTimeImmutable $periodEnd,
        public readonly int $comparisonWindowMonths,
        public readonly int $movingAverageMonths,
        private readonly array $errors,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $errors = [];
        $periodStart = self::parseMonth($request->query->get('periodStart'), 'periodStart', $errors);
        $periodEnd = self::parseMonth($request->query->get('periodEnd'), 'periodEnd', $errors);

        $comparisonWindowMonths = self::parseAllowedInteger(
            $request->query->get('comparisonWindowMonths', '12'),
            'comparisonWindowMonths',
            self::COMPARISON_WINDOWS,
            $errors,
        );
        $movingAverageMonths = self::parseAllowedInteger(
            $request->query->get('movingAverageMonths', '3'),
            'movingAverageMonths',
            self::MOVING_AVERAGE_WINDOWS,
            $errors,
        );

        if (null !== $periodStart && null !== $periodEnd && $periodStart > $periodEnd) {
            $errors[] = 'periodStart must be earlier than or equal to periodEnd.';
        }

        return new self($periodStart, $periodEnd, $comparisonWindowMonths, $movingAverageMonths, $errors);
    }

    public function isValid(): bool
    {
        return [] === $this->errors;
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param list<string> $errors
     */
    private static function parseMonth(mixed $value, string $name, array &$errors): ?\DateTimeImmutable
    {
        if (null === $value || '' === trim((string) $value)) {
            return null;
        }

        $raw = trim((string) $value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw . '-01');
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (false === $date || (false !== $dateErrors && (0 < $dateErrors['warning_count'] || 0 < $dateErrors['error_count']))) {
            $errors[] = sprintf('%s must use YYYY-MM format.', $name);

            return null;
        }

        if ($date->format('Y-m') !== $raw) {
            $errors[] = sprintf('%s must use YYYY-MM format.', $name);

            return null;
        }

        return $date;
    }

    /**
     * @param list<int>    $allowed
     * @param list<string> $errors
     */
    private static function parseAllowedInteger(mixed $value, string $name, array $allowed, array &$errors): int
    {
        $raw = trim((string) $value);
        if (!ctype_digit($raw)) {
            $errors[] = sprintf('%s must be one of: %s.', $name, implode(', ', $allowed));

            return $allowed[0];
        }

        $number = (int) $raw;
        if (!in_array($number, $allowed, true)) {
            $errors[] = sprintf('%s must be one of: %s.', $name, implode(', ', $allowed));

            return $allowed[0];
        }

        return $number;
    }
}
