<?php

declare(strict_types=1);

namespace App\Service\Analytics;

final class TopicAnalyticsMlClient
{
    /**
     * @return array{available: bool, payload: array<string, mixed>|null, errors: list<string>}
     */
    public function insights(array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $errors = [];
        foreach ($this->baseUrls() as $baseUrl) {
            $result = $this->postInsights($baseUrl, $body);
            if (true === $result['available']) {
                return $result;
            }

            $errors = [...$errors, ...$result['errors']];
        }

        return [
            'available' => false,
            'payload' => null,
            'errors' => [] === $errors ? ['MLService is unavailable.'] : $errors,
        ];
    }

    /**
     * @return list<string>
     */
    private function baseUrls(): array
    {
        $configured = rtrim((string) ($_ENV['ML_SERVICE_BASE_URL'] ?? $_SERVER['ML_SERVICE_BASE_URL'] ?? getenv('ML_SERVICE_BASE_URL') ?: ''), '/');
        $candidates = array_filter([
            $configured,
            'http://vkr-ml-api:8000',
            'http://host.docker.internal:8000',
            'http://localhost:8000',
        ]);

        return array_values(array_filter(
            array_unique($candidates),
            fn (string $baseUrl): bool => $this->isResolvable($baseUrl),
        ));
    }

    private function isResolvable(string $baseUrl): bool
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($host) || '' === $host) {
            return false;
        }
        if (in_array($host, ['localhost', '127.0.0.1', 'host.docker.internal'], true)) {
            return true;
        }

        return gethostbyname($host) !== $host;
    }

    /**
     * @return array{available: bool, payload: array<string, mixed>|null, errors: list<string>}
     */
    private function postInsights(string $baseUrl, string $body): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        try {
            $response = @file_get_contents($baseUrl . '/v1/topic-analytics/insights', false, $context);
        } catch (\Throwable $exception) {
            return [
                'available' => false,
                'payload' => null,
                'errors' => [sprintf('MLService request to %s failed: %s', $baseUrl, $exception->getMessage())],
            ];
        }

        if (false === $response) {
            return [
                'available' => false,
                'payload' => null,
                'errors' => [sprintf('MLService is unavailable at %s.', $baseUrl)],
            ];
        }

        $statusLine = $http_response_header[0] ?? '';
        if (!str_contains($statusLine, ' 2')) {
            return [
                'available' => false,
                'payload' => null,
                'errors' => [sprintf('MLService at %s returned %s.', $baseUrl, $statusLine ?: 'an HTTP error')],
            ];
        }

        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [
                'available' => false,
                'payload' => null,
                'errors' => [sprintf('MLService at %s returned invalid JSON: %s', $baseUrl, $exception->getMessage())],
            ];
        }

        if (!is_array($decoded)) {
            return [
                'available' => false,
                'payload' => null,
                'errors' => [sprintf('MLService at %s returned an invalid response shape.', $baseUrl)],
            ];
        }

        return [
            'available' => true,
            'payload' => $decoded,
            'errors' => array_values(array_map('strval', $decoded['errors'] ?? [])),
        ];
    }
}
