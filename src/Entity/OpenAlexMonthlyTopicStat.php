<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'openalex_montly_topic_stats', schema: 'public')]
#[ORM\UniqueConstraint(name: 'openalex_montly_topic_stats_topic_id_period_start_key', fields: ['topic', 'periodStart'])]
class OpenAlexMonthlyTopicStat
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    private int|string|null $id = null;

    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Topic $topic = null;

    #[ORM\Column(name: 'period_start', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(name: 'works_count', type: Types::INTEGER)]
    private int $worksCount = 0;

    #[ORM\Column(name: 'collected_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $collectedAt;

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getTopic(): ?Topic
    {
        return $this->topic;
    }

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function getWorksCount(): int
    {
        return $this->worksCount;
    }

    public function getCollectedAt(): \DateTimeImmutable
    {
        return $this->collectedAt;
    }
}
