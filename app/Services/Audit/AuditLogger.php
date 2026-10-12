<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(?User $actor, string $action, ?Model $subject = null, array $payload = [], ?Request $request = null): AuditLog
    {
        return AuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'payload' => $payload,
            'ip_address' => $request?->ip(),
            'user_agent' => filled($request?->userAgent()) ? Str::limit((string) $request->userAgent(), 509) : null,
        ]);
    }
}
