<?php

namespace Add\Hr\Tests\Fixtures;

use Add\Hr\Approvals\Contracts\ApprovalHandler;
use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Support\HrException;
use Illuminate\Database\Eloquent\Model;

/**
 * A request type for engine tests: records what the engine called, and
 * refuses approvals while `$refuse` is set.
 */
class RecordingApprovalHandler implements ApprovalHandler
{
    public static bool $refuse = false;

    /** @var list<string> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$refuse = false;
        self::$calls = [];
    }

    public function beforeApprove(Model $subject, ApprovalRequest $request, bool $final): void
    {
        self::$calls[] = $final ? 'before_final' : 'before';

        if (self::$refuse) {
            HrException::fail('hr::approvals.not_pending');
        }
    }

    public function onApproved(Model $subject, ApprovalRequest $request): void
    {
        self::$calls[] = 'approved';
    }

    public function onRejected(Model $subject, ApprovalRequest $request): void
    {
        self::$calls[] = 'rejected';
    }

    public function summary(Model $subject): array
    {
        return ['subject_id' => $subject->getKey()];
    }
}
