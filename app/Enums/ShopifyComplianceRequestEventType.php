<?php

namespace App\Enums;

enum ShopifyComplianceRequestEventType: string
{
    case Received = 'received';
    case ProcessingStarted = 'processing_started';
    case StepCompleted = 'step_completed';
    case PartiallyProcessed = 'partially_processed';
    case AwaitingPolicy = 'awaiting_policy';
    case Failed = 'failed';
    case RetryRequested = 'retry_requested';
    case RecoveryDispatched = 'recovery_dispatched';
    case Completed = 'completed';
}
