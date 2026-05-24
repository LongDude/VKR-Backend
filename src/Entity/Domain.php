<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'domains', schema: 'public')]
class Domain
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    private int|string|null $id = null;

    #[ORM\Column(name: 'openalex_id', type: Types::TEXT, nullable: true)]
    private ?string $openalexId = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $name = null;

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getOpenalexId(): ?string
    {
        return $this->openalexId;
    }

    public function getName(): ?string
    {
        return $this->name;
    }
}
