<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserToolsRepository;
use App\Service\Analytics\TopicAnalyticsService;
use App\Service\UserToolsMlClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class UserToolsController extends AbstractController
{
    private const TRACKED_TYPES = ['domain', 'field', 'subfield', 'topic'];

    #[Route('/profile/tracked', name: 'api_profile_tracked', methods: ['GET'])]
    public function tracked(UserToolsRepository $repository): JsonResponse
    {
        $user = $this->currentUser();

        return $this->json($this->normalizeTracked($repository->listTracked((int) $user->getId())));
    }

    #[Route('/profile/tracked/options', name: 'api_profile_tracked_options', methods: ['GET'])]
    public function trackedOptions(Request $request, UserToolsRepository $repository): JsonResponse
    {
        $type = (string) $request->query->get('type', '');
        if (!in_array($type, self::TRACKED_TYPES, true)) {
            return $this->json(['error' => 'Unsupported tracked entity type.'], Response::HTTP_BAD_REQUEST);
        }
        $query = trim((string) $request->query->get('query', ''));
        $limit = max(1, min(50, (int) $request->query->get('limit', 20)));

        return $this->json([
            'items' => array_map(
                fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'type' => $type],
                $repository->searchOptions($type, $query, $limit),
            ),
        ]);
    }

    #[Route('/profile/tracked/{type}/{id<\d+>}', name: 'api_profile_tracked_add', methods: ['POST'])]
    public function addTracked(string $type, int $id, UserToolsRepository $repository, UserToolsMlClient $mlClient): JsonResponse
    {
        $user = $this->currentUser();
        if (!in_array($type, self::TRACKED_TYPES, true)) {
            return $this->json(['error' => 'Unsupported tracked entity type.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$repository->entityExists($type, $id)) {
            return $this->json(['error' => 'Tracked entity was not found.'], Response::HTTP_NOT_FOUND);
        }

        $repository->addTracked((int) $user->getId(), $type, $id);
        $ml = $mlClient->recomputeProfile((int) $user->getId());
        $warnings = false === $ml['available'] ? $ml['errors'] : [];
        if ('domain' === $type) {
            array_unshift($warnings, 'Темы выбранного домена будут учитываться в рекомендациях неявно и не добавляются в список Topic.');
        }

        return $this->json([
            ...$this->normalizeTracked($repository->listTracked((int) $user->getId())),
            'warnings' => $warnings,
        ]);
    }

    #[Route('/profile/tracked/{type}/{id<\d+>}', name: 'api_profile_tracked_delete', methods: ['DELETE'])]
    public function deleteTracked(string $type, int $id, UserToolsRepository $repository, UserToolsMlClient $mlClient): JsonResponse
    {
        $user = $this->currentUser();
        if (!in_array($type, self::TRACKED_TYPES, true)) {
            return $this->json(['error' => 'Unsupported tracked entity type.'], Response::HTTP_BAD_REQUEST);
        }

        $repository->removeTracked((int) $user->getId(), $type, $id);
        $ml = $mlClient->recomputeProfile((int) $user->getId());

        return $this->json([
            ...$this->normalizeTracked($repository->listTracked((int) $user->getId())),
            'warnings' => false === $ml['available'] ? $ml['errors'] : [],
        ]);
    }

    #[Route('/favorites/papers', name: 'api_favorites_papers', methods: ['GET'])]
    public function favorites(Request $request, UserToolsRepository $repository): JsonResponse
    {
        $user = $this->currentUser();
        $limit = max(1, min(100, (int) $request->query->get('limit', 50)));
        $offset = max(0, (int) $request->query->get('offset', 0));

        return $this->json([
            'items' => array_map(
                fn (array $row): array => $this->normalizePaperSummary($row, true),
                $repository->listFavoritePapers((int) $user->getId(), $limit, $offset),
            ),
            'total' => $repository->countFavorites((int) $user->getId()),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    #[Route('/favorites/papers/{paperId<\d+>}', name: 'api_favorites_paper_add', methods: ['PUT'])]
    public function addFavorite(int $paperId, UserToolsRepository $repository, UserToolsMlClient $mlClient): JsonResponse
    {
        $user = $this->currentUser();
        if (!$repository->addFavorite((int) $user->getId(), $paperId)) {
            return $this->json(['error' => 'Paper was not found.'], Response::HTTP_NOT_FOUND);
        }
        $ml = $mlClient->recomputeProfile((int) $user->getId());

        return $this->json([
            'paperId' => $paperId,
            'isFavorite' => true,
            'warnings' => false === $ml['available'] ? $ml['errors'] : [],
        ]);
    }

    #[Route('/favorites/papers/{paperId<\d+>}', name: 'api_favorites_paper_delete', methods: ['DELETE'])]
    public function deleteFavorite(int $paperId, UserToolsRepository $repository, UserToolsMlClient $mlClient): JsonResponse
    {
        $user = $this->currentUser();
        $repository->removeFavorite((int) $user->getId(), $paperId);
        $ml = $mlClient->recomputeProfile((int) $user->getId());

        return $this->json([
            'paperId' => $paperId,
            'isFavorite' => false,
            'warnings' => false === $ml['available'] ? $ml['errors'] : [],
        ]);
    }

    #[Route('/papers/{paperId<\d+>}', name: 'api_paper_metadata', methods: ['GET'])]
    public function paper(int $paperId, TopicAnalyticsService $analytics): JsonResponse
    {
        $user = $this->currentUser();
        $paper = $analytics->findPaper($paperId, (int) $user->getId());
        if (null === $paper) {
            return $this->json(['error' => 'Paper was not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($paper);
    }

    #[Route('/recommendations/papers', name: 'api_recommendations_papers', methods: ['POST'])]
    public function recommendations(Request $request, UserToolsRepository $repository, UserToolsMlClient $mlClient): JsonResponse
    {
        $user = $this->currentUser();
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $selectedTags = $this->selectedTags($payload['selectedTags'] ?? []);
        $errors = $repository->validateSelectedTags($selectedTags);
        if ([] !== $errors) {
            return $this->json(['error' => 'Invalid selected tags.', 'details' => $errors], Response::HTTP_BAD_REQUEST);
        }
        $limit = max(1, min(100, (int) ($payload['limit'] ?? 20)));
        $excludeFavorites = (bool) ($payload['excludeFavorites'] ?? true);
        $ml = $mlClient->recommendations([
            'user_id' => (int) $user->getId(),
            'limit' => $limit,
            'exclude_favourites' => $excludeFavorites,
            'domain_ids' => $selectedTags['domains'],
            'field_ids' => $selectedTags['fields'],
            'subfield_ids' => $selectedTags['subfields'],
            'topic_ids' => $selectedTags['topics'],
        ]);

        if (!$ml['available'] || !is_array($ml['payload'])) {
            return $this->json([
                'items' => [],
                'total' => 0,
                'strategy' => 'ml_unavailable',
                'mlStatus' => ['available' => false, 'errors' => $ml['errors']],
            ]);
        }

        $items = [];
        foreach (($ml['payload']['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $paper = is_array($item['paper'] ?? null) ? $item['paper'] : [];
            $paperId = (int) ($paper['id'] ?? 0);
            $items[] = [
                'paper' => $this->normalizeMlPaper($paper, $repository->isFavorite((int) $user->getId(), $paperId)),
                'score' => (float) ($item['score'] ?? 0.0),
                'reason' => $item['reason'] ?? null,
                'scoreDetails' => $this->normalizeScoreDetails(is_array($item['score_details'] ?? null) ? $item['score_details'] : []),
            ];
        }

        return $this->json([
            'items' => $items,
            'total' => (int) ($ml['payload']['total'] ?? count($items)),
            'strategy' => $ml['payload']['strategy'] ?? null,
            'mlStatus' => ['available' => true, 'errors' => $ml['errors']],
        ]);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $tracked
     */
    private function normalizeTracked(array $tracked): array
    {
        $result = [];
        foreach (['domains', 'fields', 'subfields', 'topics'] as $key) {
            $result[$key] = array_map(
                fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'createdAt' => $row['created_at'] ?? null,
                    'type' => rtrim($key, 's'),
                ],
                $tracked[$key] ?? [],
            );
        }

        return $result;
    }

    private function normalizePaperSummary(array $row, bool $isFavorite): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'doi' => $row['doi'] ?? null,
            'openalexId' => $row['openalex_id'] ?? null,
            'publicationYear' => null === $row['publication_year'] ? null : (int) $row['publication_year'],
            'publicationDate' => $row['publication_date'] ?? null,
            'language' => $row['language'] ?? null,
            'isOpenAccess' => null === $row['is_open_access'] ? null : (bool) $row['is_open_access'],
            'citedBy' => (int) ($row['cited_by_count'] ?? 0),
            'referencesCount' => (int) ($row['references_count'] ?? 0),
            'authors' => (string) ($row['author_names'] ?? ''),
            'isFavorite' => $isFavorite,
        ];
    }

    private function normalizeMlPaper(array $paper, bool $isFavorite): array
    {
        return [
            'id' => (int) ($paper['id'] ?? 0),
            'title' => (string) ($paper['title'] ?? 'Untitled paper'),
            'doi' => $paper['doi'] ?? null,
            'openalexId' => $paper['openalex_id'] ?? null,
            'publicationYear' => null === ($paper['publication_year'] ?? null) ? null : (int) $paper['publication_year'],
            'publicationDate' => $paper['publication_date'] ?? null,
            'language' => $paper['language'] ?? null,
            'isOpenAccess' => null === ($paper['is_open_access'] ?? null) ? null : (bool) $paper['is_open_access'],
            'citedBy' => (int) ($paper['cited_by_count'] ?? 0),
            'referencesCount' => (int) ($paper['references_count'] ?? 0),
            'isFavorite' => $isFavorite,
        ];
    }

    private function normalizeScoreDetails(array $details): array
    {
        return [
            'semanticScore' => null === ($details['semantic_score'] ?? null) ? null : (float) $details['semantic_score'],
            'profileScore' => null === ($details['profile_score'] ?? null) ? null : (float) $details['profile_score'],
            'tagMatchScore' => null === ($details['tag_match_score'] ?? null) ? null : (float) $details['tag_match_score'],
            'trendScore' => null === ($details['trend_score'] ?? null) ? null : (float) $details['trend_score'],
            'recencyScore' => null === ($details['recency_score'] ?? null) ? null : (float) $details['recency_score'],
            'citationScore' => null === ($details['citation_score'] ?? null) ? null : (float) $details['citation_score'],
        ];
    }

    /**
     * @return array{domains: list<int>, fields: list<int>, subfields: list<int>, topics: list<int>}
     */
    private function selectedTags(mixed $value): array
    {
        $source = is_array($value) ? $value : [];

        return [
            'domains' => $this->integerList($source['domains'] ?? []),
            'fields' => $this->integerList($source['fields'] ?? []),
            'subfields' => $this->integerList($source['subfields'] ?? []),
            'topics' => $this->integerList($source['topics'] ?? []),
        ];
    }

    /**
     * @return list<int>
     */
    private function integerList(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map('intval', is_array($value) ? $value : []), fn (int $id): bool => $id > 0)));
    }
}
