<?php

use App\Models\Post;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('featured')->default(false)->after('needs_import_review');
        });

        Schema::create('post_author', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['post_id', 'user_id']);
        });

        // Backfill primary authors into the pivot.
        Post::query()->orderBy('id')->chunkById(100, function ($posts) {
            foreach ($posts as $post) {
                if ($post->author_id) {
                    DB::table('post_author')->insertOrIgnore([
                        'post_id' => $post->id,
                        'user_id' => $post->author_id,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_author');
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('featured');
        });
    }
};
