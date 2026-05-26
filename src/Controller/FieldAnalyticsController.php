<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Analytics\FieldDashboardRequest;
use App\Dto\Analytics\TopicDashboardRequest;
use App\Entity\User;
use App\Service\Analytics\FieldAnalyticsService;
use App\Service\Analytics\TopicAnalyticsService;
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

    #[Route('/fields/{fieldId<\d+>}/topics', name: 'api_analytics_field_topics', methods: ['GET'])]
    public function topics(int $fieldId, Request $request, TopicAnalyticsService $analytics): JsonResponse
    {
        $limit = max(1, min(500, (int) $request->query->get('limit', 500)));

        return $this->json([
            'topics' => $analytics->listTopicsByField($fieldId, $limit),
        ]);
    }

    #[Route('/topics/{topicId<\d+>}/dashboard', name: 'api_analytics_topic_dashboard', methods: ['GET'])]
    public function topicDashboard(int $topicId, Request $request, TopicAnalyticsService $analytics): JsonResponse
    {
        $dashboardRequest = TopicDashboardRequest::fromRequest($request);
        if (!$dashboardRequest->isValid()) {
            return $this->json(
                [
                    'error' => 'Invalid topic analytics filters.',
                    'details' => $dashboardRequest->getErrors(),
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $dashboard = $analytics->buildDashboard($topicId, $dashboardRequest);
        if (null === $dashboard) {
            return $this->json(['error' => 'Topic was not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($dashboard);
    }

    #[Route('/papers/{paperId<\d+>}', name: 'api_analytics_paper_metadata', methods: ['GET'])]
    public function paper(int $paperId, TopicAnalyticsService $analytics): JsonResponse
    {
        $user = $this->getUser();
        $paper = $analytics->findPaper($paperId, $user instanceof User ? (int) $user->getId() : null);
        if (null === $paper) {
            return $this->json(['error' => 'Paper was not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($paper);
    }
}
