<?php

namespace App;

use App\Doctrine\Type\PostgresTextArrayType;
use Doctrine\DBAL\Types\Type;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        if (!Type::hasType(PostgresTextArrayType::NAME)) {
            Type::addType(PostgresTextArrayType::NAME, PostgresTextArrayType::class);
        }

        parent::boot();
    }
}
