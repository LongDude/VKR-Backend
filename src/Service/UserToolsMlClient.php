<?php

declare(strict_types=1);

namespace App\Service;

final class UserToolsMlClient
{
    /**
     * @return array{available: bool, payload: array<string, mixed>|null, errors: list<string>}
     */
    public function recomputeProfile(int $userId): array
    {
        return $this->post('/v1/user-profiles/' . $userId . '/recompute', []);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{available: bool, payload: array<string, mixed>|null, errors: list<string>}
     */
    public function recommendations(array $payload): array
    {
        return $this->post('/v1/recommendations/papers', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{available: bool, payload: array<string, mixed>|null, errors: list<string>}
     */
    private function post(string $path, array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $errors = [];
        foreach ($this->baseUrls() as $baseUrl) {
            $result = $this->postJson($baseUrl, $path, $body);
            if ($result['available']) {
                return $result;
            }
            $errors = [...$errors, ...$result['errors']];
        }

        return ['available' => false, 'payload' => null, 'errors' => [] === $errors ? ['MLService is unavailable.'] : $errors];
    }

    /**
     * @return list<string>
     */
    private function baseUrls(): array
    {
        $configured = rtrim((string) ($_ENV['ML_SERVICE_BASE_URL'] ?? $_SERVER['ML_SERVICE_BASE_URL'] ?? getenv('ML_SERVICE_BASE_URL') ?: ''), '/');
        $candidates = array_filter([$configured, 'http://vkr-ml-api:8000', 'http://host.docker.internal:8000', 'http://localhost:8000']);

        return array_values(array_filter(array_unique($candidates), fn (string $url): bool => $this->isResolvable($url)));
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
    private function postJson(string $baseUrl, string $path, string $body): array
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

        $response = @file_get_contents($baseUrl . $path, false, $context);
        if (false === $response) {
            return ['available' => false, 'payload' => null, 'errors' => [sprintf('MLService is unavailable at %s.', $baseUrl)]];
        }
        $statusLine = $http_response_header[0] ?? '';
        if (!str_contains($statusLine, ' 2')) {
            return ['available' => false, 'payload' => null, 'errors' => [sprintf('MLService at %s returned %s.', $baseUrl, $statusLine ?: 'an HTTP error')]];
        }

        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return ['available' => false, 'payload' => null, 'errors' => ['MLService returned invalid JSON: ' . $exception->getMessage()]];
        }

        return is_array($decoded)
            ? ['available' => true, 'payload' => $decoded, 'errors' => array_values(array_map('strval', $decoded['errors'] ?? []))]
            : ['available' => false, 'payload' => null, 'errors' => ['MLService returned invalid response.']];
    }
}
