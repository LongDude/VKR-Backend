<?php

namespace App\Entity;

use App\Doctrine\Type\PostgresTextArrayType;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\LegacyPasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users', schema: 'public')]
#[ORM\UniqueConstraint(name: 'users_email_key', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, LegacyPasswordAuthenticatedUserInterface, EquatableInterface
{
    private const DEFAULT_ROLE = 'ROLE_USER';

    private const ROLE_PRIORITY = ['ROLE_ADMIN', self::DEFAULT_ROLE];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'users_id_seq', allocationSize: 1)]
    #[ORM\Column(type: Types::BIGINT, options: ['default' => "nextval('users_id_seq'::regclass)"])]
    private int|string|null $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $email = null;

    #[ORM\Column(name: 'password_hash', type: Types::TEXT)]
    private ?string $passwordHash = null;

    #[ORM\Column(name: 'password_salt', type: Types::TEXT)]
    private ?string $passwordSalt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $name = null;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: PostgresTextArrayType::NAME, options: ['default' => "ARRAY['ROLE_USER']"])]
    private array $roles = [self::DEFAULT_ROLE];

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'now()'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower(trim($email));

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = array_values(array_filter(
            array_map(static fn (mixed $role): string => trim((string) $role), $this->roles),
            static fn (string $role): bool => '' !== $role,
        ));

        $roles[] = self::DEFAULT_ROLE;

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique(array_filter(
            array_map(static fn (mixed $role): string => trim((string) $role), $roles),
            static fn (string $role): bool => '' !== $role,
        )));

        if ([] === $this->roles) {
            $this->roles = [self::DEFAULT_ROLE];
        }

        return $this;
    }

    public function getPrimaryRole(): string
    {
        $roles = $this->getRoles();

        foreach (self::ROLE_PRIORITY as $role) {
            if (\in_array($role, $roles, true)) {
                return $role;
            }
        }

        return $roles[0] ?? self::DEFAULT_ROLE;
    }

    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        return $this->getUserIdentifier() === $user->getUserIdentifier()
            && $this->isSameSerializedPassword($user->getPassword())
            && $this->getSalt() === $user->getSalt();
    }

    private function isSameSerializedPassword(?string $refreshedPassword): bool
    {
        $sessionPassword = $this->getPassword();

        if ($sessionPassword === $refreshedPassword) {
            return true;
        }

        return null !== $sessionPassword
            && 8 === \strlen($sessionPassword)
            && hash('crc32c', $refreshedPassword ?? $sessionPassword) === $sessionPassword;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function setPassword(string $passwordHash): static
    {
        $this->passwordHash = $passwordHash;

        return $this;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): static
    {
        $this->passwordHash = $passwordHash;

        return $this;
    }

    public function getSalt(): ?string
    {
        return $this->passwordSalt;
    }

    public function getPasswordSalt(): ?string
    {
        return $this->passwordSalt;
    }

    public function setPasswordSalt(string $passwordSalt): static
    {
        $this->passwordSalt = $passwordSalt;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = null === $name ? null : trim($name);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0" . self::class . "\0passwordHash"] = hash('crc32c', (string) $this->passwordHash);

        return $data;
    }
}
