<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Validated filters for the admin audit log viewer and its CSV export.
 */
class AuditLogQuery
{
    private const MAX_VALUE_LENGTH = 500;

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'actor' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_.-]+$/'],
            'subject_type' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'ip' => ['nullable', 'ip'],
        ];
    }

    /**
     * @return array{actor: string, action: string, subject_type: string, from: string, to: string, ip: string}
     */
    public static function filters(Request $request): array
    {
        $validated = $request->validate(self::rules());

        return [
            'actor' => trim((string) ($validated['actor'] ?? '')),
            'action' => (string) ($validated['action'] ?? ''),
            'subject_type' => (string) ($validated['subject_type'] ?? ''),
            'from' => (string) ($validated['from'] ?? ''),
            'to' => (string) ($validated['to'] ?? ''),
            'ip' => (string) ($validated['ip'] ?? ''),
        ];
    }

    /**
     * @param  array{actor: string, action: string, subject_type: string, from: string, to: string, ip: string}  $filters
     * @return Builder<AuditLog>
     */
    public static function build(array $filters): Builder
    {
        $query = AuditLog::query()->with('actor:id,name,email')->latest('id');

        if ($filters['actor'] !== '') {
            $actor = $filters['actor'];
            $query->whereHas('actor', fn (Builder $builder) => ctype_digit($actor)
                ? $builder->whereKey((int) $actor)
                : $builder->where(fn (Builder $inner) => $inner
                    ->where('email', 'like', '%'.$actor.'%')
                    ->orWhere('name', 'like', '%'.$actor.'%')));
        }

        if ($filters['action'] !== '') {
            $query->where('action', 'like', $filters['action'].'%');
        }

        if ($filters['subject_type'] !== '') {
            $type = self::subjectTypes()->search($filters['subject_type']);
            $query->where('subject_type', $type === false ? '' : $type);
        }

        if ($filters['from'] !== '') {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if ($filters['to'] !== '') {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        if ($filters['ip'] !== '') {
            $query->where('ip_address', $filters['ip']);
        }

        return $query;
    }

    /**
     * Stored morph class => short label.
     *
     * @return Collection<string, string>
     */
    public static function subjectTypes(): Collection
    {
        return AuditLog::query()
            ->whereNotNull('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->mapWithKeys(fn (string $type) => [$type => class_basename($type)]);
    }

    /**
     * Flattens a payload into display-safe key/value strings.
     *
     * @return list<array{key: string, value: string}>
     */
    public static function payloadPairs(?array $payload): array
    {
        $pairs = [];

        foreach (self::flatten($payload ?? []) as $key => $value) {
            $pairs[] = ['key' => (string) $key, 'value' => Str::limit($value, self::MAX_VALUE_LENGTH)];
        }

        return $pairs;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<string, string>
     */
    private static function flatten(array $payload, string $prefix = ''): array
    {
        $flat = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $flat += self::flatten($value, $path);

                continue;
            }

            $flat[$path] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => 'null',
                is_scalar($value) => (string) $value,
                default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            };
        }

        return $flat;
    }
}
