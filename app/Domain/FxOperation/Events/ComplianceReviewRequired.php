<?php

declare(strict_types=1);

namespace App\Domain\FxOperation\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class ComplianceReviewRequired extends ShouldBeStored
{
    public function __construct(
        public string $operationId,
    ) {}
}
