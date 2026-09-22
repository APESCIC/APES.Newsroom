<?php

use App\Models\Post;
use App\Services\EditorJs\BodyTextExtractor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->longText('body_text')->nullable()->after('content');
        });

        $extractor = new BodyTextExtractor;

        Post::query()->withTrashed()->orderBy('id')->chunkById(100, function ($posts) use ($extractor): void {
            foreach ($posts as $post) {
                $post->forceFill([
                    'body_text' => $extractor->extract(is_array($post->content) ? $post->content : []),
                ])->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('body_text');
        });
    }
};
