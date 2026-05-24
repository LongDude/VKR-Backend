<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'subfields', schema: 'public')]
class Subfield
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    private int|string|null $id = null;

    #[ORM\ManyToOne(targetEntity: Field::class)]
    #[ORM\JoinColumn(name: 'field_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Field $field = null;

    #[ORM\Column(name: 'openalex_id', type: Types::TEXT, nullable: true)]
    private ?string $openalexId = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $name = null;

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getField(): ?Field
    {
        return $this->field;
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
