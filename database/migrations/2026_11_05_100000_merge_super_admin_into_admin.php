<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Settled in #713 and #715 (v1.28.0): the super_admin role is merged into
     * admin. Every original value is kept in role_merge_snapshots so down()
     * can put it back. Raw queries only, because the Role enum no longer has
     * a super_admin case.
     */
    private const OLD_ROLE = 'super_admin';

    private const NEW_ROLE = 'admin';

    public const ACTION = 'roles.merged_into_admin';

    /**
     * Tables and columns down() may restore from the snapshot.
     *
     * @var array<string, string>
     */
    private const RESTORABLE = [
        'users' => 'role',
        'audit_logs' => 'payload',
    ];

    public function up(): void
    {
        Schema::create('role_merge_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('source_table');
            $table->unsignedBigInteger('source_id');
            $table->string('source_column');
            $table->longText('original')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['source_table', 'source_id']);
        });

        $now = now();
        $users = 0;
        $entries = 0;

        DB::table('users')
            ->where('role', self::OLD_ROLE)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($now, &$users) {
                foreach ($rows as $row) {
                    $this->snapshot('users', (int) $row->id, $row->role, $now);
                    DB::table('users')->where('id', $row->id)->update(['role' => self::NEW_ROLE]);
                    $users++;
                }
            });

        DB::table('audit_logs')
            ->where('payload', 'like', '%'.self::OLD_ROLE.'%')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($now, &$entries) {
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row->payload, true);

                    if (! is_array($payload)) {
                        continue;
                    }

                    $rewritten = $this->rewrite($payload);

                    if ($rewritten === $payload) {
                        continue;
                    }

                    $this->snapshot('audit_logs', (int) $row->id, $row->payload, $now);
                    DB::table('audit_logs')->where('id', $row->id)->update(['payload' => json_encode($rewritten)]);
                    $entries++;
                }
            });

        if ($users === 0 && $entries === 0) {
            return;
        }

        DB::table('audit_logs')->insert([
            'actor_id' => null,
            'action' => self::ACTION,
            'subject_type' => null,
            'subject_id' => null,
            'payload' => json_encode(['users' => $users, 'audit_entries' => $entries]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('role_merge_snapshots')) {
            DB::table('role_merge_snapshots')->orderBy('id')->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $column = self::RESTORABLE[$row->source_table] ?? null;

                    if ($column === null || $column !== $row->source_column) {
                        continue;
                    }

                    DB::table($row->source_table)->where('id', $row->source_id)->update([$column => $row->original]);
                }
            });
        }

        DB::table('audit_logs')->where('action', self::ACTION)->delete();
        Schema::dropIfExists('role_merge_snapshots');
    }

    private function snapshot(string $table, int $id, ?string $original, mixed $now): void
    {
        DB::table('role_merge_snapshots')->insert([
            'source_table' => $table,
            'source_id' => $id,
            'source_column' => self::RESTORABLE[$table],
            'original' => $original,
            'created_at' => $now,
        ]);
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function rewrite(array $payload): array
    {
        return array_map(
            fn (mixed $value) => match (true) {
                is_array($value) => $this->rewrite($value),
                $value === self::OLD_ROLE => self::NEW_ROLE,
                default => $value,
            },
            $payload,
        );
    }
};
