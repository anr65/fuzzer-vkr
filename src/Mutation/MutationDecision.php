<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

final class MutationDecision {
    public string $operatorId;
    public string $seedClass;
    public string $policy;
    public ?string $weightSnapshotId;

    public function __construct(string $operatorId, string $seedClass, string $policy, ?string $weightSnapshotId = null) {
        $this->operatorId = $operatorId;
        $this->seedClass = $seedClass;
        $this->policy = $policy;
        $this->weightSnapshotId = $weightSnapshotId;
    }
}
