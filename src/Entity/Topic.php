<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'topics', schema: 'public')]
class Topic
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    private int|string|null $id = null;

    #[ORM\ManyToOne(targetEntity: Subfield::class)]
    #[ORM\JoinColumn(name: 'subfield_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Subfield $subfield = null;

    #[ORM\Column(name: 'openalex_id', type: Types::TEXT, nullable: true)]
    private ?string $openalexId = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $name = null;

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getSubfield(): ?Subfield
    {
        return $this->subfield;
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
