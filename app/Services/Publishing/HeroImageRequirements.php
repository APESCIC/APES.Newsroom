<?php

namespace App\Services\Publishing;

use App\Models\Page;
use App\Models\Post;
use Illuminate\Validation\ValidationException;

/**
 * A hero image cannot go live without alt text (#536).
 */
class HeroImageRequirements
{
    public const MISSING_ALT = 'Add alt text for the hero image before publishing.';

    public function missingAlt(Post|Page $model): bool
    {
        return filled($model->hero_image) && blank($model->hero_image_alt);
    }

    public function assertPublishable(Post|Page $model): void
    {
        if ($this->missingAlt($model)) {
            throw ValidationException::withMessages(['hero_image_alt' => self::MISSING_ALT]);
        }
    }
}
