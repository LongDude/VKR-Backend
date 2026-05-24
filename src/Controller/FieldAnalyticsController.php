<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Analytics\FieldDashboardRequest;
use App\Service\Analytics\FieldAnalyticsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/analytics')]
final class FieldAnalyticsController extends AbstractController
{
    #[Route('/fields', name: 'api_analytics_fields', methods: ['GET'])]
    public function fields(Request $request, FieldAnalyticsService $analytics): JsonResponse
    {
        $query = trim((string) $request->query->get('query', ''));
        $limit = max(1, min(100, (int) $request->query->get('limit', 50)));

        return $this->json([
            'fields' => $analytics->listFields($query, $limit),
        ]);
    }

    #[Route('/fields/{fieldId<\d+>}/dashboard', name: 'api_analytics_field_dashboard', methods: ['GET'])]
    public function dashboard(int $fieldId, Request $request, FieldAnalyticsService $analytics): JsonResponse
    {
        $dashboardRequest = FieldDashboardRequest::fromRequest($request);
        if (!$dashboardRequest->isValid()) {
            return $this->json(
                [
                    'error' => 'Invalid analytics filters.',
                    'details' => $dashboardRequest->getErrors(),
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $dashboard = $analytics->buildDashboard($fieldId, $dashboardRequest);
        if (null === $dashboard) {
            return $this->json(['error' => 'Field was not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($dashboard);
    }
}
