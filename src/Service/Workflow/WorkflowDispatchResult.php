<?php

declare(strict_types=1);

namespace App\Service\Workflow;

final readonly class WorkflowDispatchResult
{
    public function __construct(
        public string $deliveryMode,
        public ?string $dispatchReference = null,
    ) {
    }
}
