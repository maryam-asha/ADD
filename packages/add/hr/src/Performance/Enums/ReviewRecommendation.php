<?php

namespace Add\Hr\Performance\Enums;

/**
 * What the reviewer suggests. Never applied by itself: a promotion or a pay
 * change is still an employment event or a compensation change by HR.
 */
enum ReviewRecommendation: string
{
    case None = 'none';
    case Promotion = 'promotion';
    case CompensationChange = 'compensation_change';
    case ImprovementPlan = 'improvement_plan';
}
