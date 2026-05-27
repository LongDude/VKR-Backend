<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends AbstractController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        UserRepository $users,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        $name = isset($payload['name']) ? (string) $payload['name'] : null;

        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Valid email is required.'], Response::HTTP_BAD_REQUEST);
        }

        if (8 > \strlen($password)) {
            return $this->json(['error' => 'Password must contain at least 8 characters.'], Response::HTTP_BAD_REQUEST);
        }

        if (null !== $users->findOneByEmail($email)) {
            return $this->json(['error' => 'User with this email already exists.'], Response::HTTP_CONFLICT);
        }

        $user = (new User())
            ->setEmail($email)
            ->setName($name)
            ->setPasswordSalt(bin2hex(random_bytes(32)));
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $entityManager->persist($user);
        $entityManager->flush();

        return $this->json(['user' => $this->normalizeUser($user)], Response::HTTP_CREATED);
    }

    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return $this->json(['error' => 'Login was not handled by the security firewall.'], Response::HTTP_BAD_REQUEST);
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['user' => $this->normalizeUser($user)]);
    }

    #[Route('/api/me', name: 'api_me_update', methods: ['PATCH'])]
    public function updateMe(Request $request, UserRepository $users, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $email = strtolower(trim((string) ($payload['email'] ?? $user->getEmail())));
        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : $user->getName();
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Valid email is required.'], Response::HTTP_BAD_REQUEST);
        }

        $existing = $users->findOneByEmail($email);
        if (null !== $existing && (string) $existing->getId() !== (string) $user->getId()) {
            return $this->json(['error' => 'User with this email already exists.'], Response::HTTP_CONFLICT);
        }

        $user->setEmail($email)->setName('' === $name ? null : $name);
        $entityManager->flush();

        return $this->json(['user' => $this->normalizeUser($user)]);
    }

    #[Route('/api/me/password', name: 'api_me_password', methods: ['POST'])]
    public function updatePassword(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $currentPassword = (string) ($payload['currentPassword'] ?? '');
        $newPassword = (string) ($payload['newPassword'] ?? '');
        $confirmation = (string) ($payload['newPasswordConfirmation'] ?? '');
        if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
            return $this->json(['error' => 'Current password is invalid.'], Response::HTTP_BAD_REQUEST);
        }
        if (8 > \strlen($newPassword)) {
            return $this->json(['error' => 'Password must contain at least 8 characters.'], Response::HTTP_BAD_REQUEST);
        }
        if ($newPassword !== $confirmation) {
            return $this->json(['error' => 'Password confirmation does not match.'], Response::HTTP_BAD_REQUEST);
        }

        $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
        $entityManager->flush();

        return $this->json(['ok' => true]);
    }

    /**
     * @return array{id: int|string|null, email: string|null, name: string|null, roles: list<string>, role: string}
     */
    private function normalizeUser(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'roles' => $user->getRoles(),
            'role' => $user->getPrimaryRole(),
        ];
    }
}
