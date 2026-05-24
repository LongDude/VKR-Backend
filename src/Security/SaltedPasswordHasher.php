<?php

namespace App\Security;

use Symfony\Component\PasswordHasher\Exception\InvalidPasswordException;
use Symfony\Component\PasswordHasher\LegacyPasswordHasherInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

final class SaltedPasswordHasher implements LegacyPasswordHasherInterface
{
    private string $algorithm;

    /**
     * @var array<string, int>
     */
    private array $options;

    public function __construct()
    {
        $argon2id = \defined('PASSWORD_ARGON2ID') ? \constant('PASSWORD_ARGON2ID') : null;

        $this->algorithm = $argon2id ?? \PASSWORD_DEFAULT;
        $this->options = $argon2id === $this->algorithm ? [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 1,
        ] : [];
    }

    public function hash(#[\SensitiveParameter] string $plainPassword, ?string $salt = null): string
    {
        if ('' === $plainPassword || PasswordHasherInterface::MAX_PASSWORD_LENGTH < \strlen($plainPassword)) {
            throw new InvalidPasswordException();
        }

        return password_hash($this->saltPassword($plainPassword, $salt), $this->algorithm, $this->options);
    }

    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword, ?string $salt = null): bool
    {
        if ('' === $plainPassword || PasswordHasherInterface::MAX_PASSWORD_LENGTH < \strlen($plainPassword)) {
            return false;
        }

        return password_verify($this->saltPassword($plainPassword, $salt), $hashedPassword);
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return password_needs_rehash($hashedPassword, $this->algorithm, $this->options);
    }

    private function saltPassword(#[\SensitiveParameter] string $plainPassword, ?string $salt): string
    {
        if (null === $salt || '' === $salt) {
            throw new InvalidPasswordException();
        }

        return base64_encode(hash('sha512', $salt . "\0" . $plainPassword, true));
    }
}
