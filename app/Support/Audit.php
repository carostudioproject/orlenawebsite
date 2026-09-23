<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Audit
{
    public static function record(string $action, Model $subject, array $changes = [], ?int $actor = null): void
    {
        self::log($action, $subject->getTable(), (int) $subject->getKey(), $changes, $actor);
    }

    /** For subjects that are not a numbered row, e.g. settings (id 0). */
    public static function log(string $action, string $subjectType, int $subjectId, array $changes = [], ?int $actor = null): void
    {
        // Only explicit, non-secret fields may be passed here.
        unset($changes['password'], $changes['remember_token'], $changes['email']);
        DB::table('audit_logs')->insert([
            'actor_id' => $actor ?? auth()->id(), 'action' => $action,
            'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
