<?php

namespace Add\Hr\Performance\Enums;

/**
 * A review cycle's phases, in order; HR moves it one step at a time.
 */
enum CycleStatus: string
{
    case Draft = 'draft';
    case GoalSetting = 'goal_setting';
    case SelfReview = 'self_review';
    case ManagerReview = 'manager_review';
    case Calibration = 'calibration';
    case Closed = 'closed';

    public function next(): ?self
    {
        return match ($this) {
            self::Draft => self::GoalSetting,
            self::GoalSetting => self::SelfReview,
            self::SelfReview => self::ManagerReview,
            self::ManagerReview => self::Calibration,
            self::Calibration => self::Closed,
            self::Closed => null,
        };
    }

    /**
     * Goals' titles and weights may still change.
     */
    public function goalsOpen(): bool
    {
        return in_array($this, [self::Draft, self::GoalSetting], true);
    }
}
