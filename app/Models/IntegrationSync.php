<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One outbound sync with an external system (Erzap). Statuses:
 * pending → synced, or failed (retried with backoff), waiting_config (credentials not set yet),
 * needs_mapping (outlet/product without an Erzap ID), skipped (nothing to send).
 */
class IntegrationSync extends Model
{
    public const LABELS = [
        'pending' => 'Queued', 'synced' => 'Sent', 'failed' => 'Failed', 'waiting_config' => 'Awaiting setup',
        'needs_mapping' => 'Needs mapping', 'skipped' => 'Skipped',
    ];

    /** Rows the scheduler picks up again. */
    public const RUNNABLE = ['pending', 'failed', 'waiting_config', 'needs_mapping'];

    /** Automatic retries stop after this many failed attempts; staff can still retry by hand. */
    public const MAX_AUTO_ATTEMPTS = 5;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'response' => 'array', 'attempts' => 'integer', 'next_attempt_at' => 'datetime', 'synced_at' => 'datetime'];
    }
}
