<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Admin\DataCoverageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/data-coverage')]
final class AdminDataCoverageController extends AbstractController
{
    #[Route('/panels/{panelKey}', name: 'api_admin_data_coverage_panel', methods: ['POST'])]
    public function panel(string $panelKey, Request $request, DataCoverageService $coverage): JsonResponse
    {
        if (!$coverage->hasPanel($panelKey)) {
            return $this->json(['error' => 'Coverage panel was not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Administrator access required.'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $topicIds = $this->integerList($payload['topicIds'] ?? []);
        $periodFrom = isset($payload['periodFrom']) ? (string) $payload['periodFrom'] : null;
        $periodTo = isset($payload['periodTo']) ? (string) $payload['periodTo'] : null;

        try {
            return $this->json($coverage->buildPanel($panelKey, $topicIds, $periodFrom, $periodTo));
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * @return list<int>
     */
    private function integerList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $value),
            static fn (int $id): bool => $id > 0,
        )));
    }
}
