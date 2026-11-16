<?php

namespace App\Services\Seo;

use App\Models\Page;
use App\Models\Post;
use App\Services\Media\HeroImagePresenter;
use App\Services\Media\ImageCodec;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Resolves 1200×630 social images, with a branded fallback when there is no hero (#674).
 */
class SocialCardService
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    public function __construct(
        private readonly HeroImagePresenter $heroes,
        private readonly ImageCodec $codec,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @return array{url: string, width: int, height: int, alt: string, from_hero: bool}
     */
    public function forPost(Post $post, string $title): array
    {
        $presented = $this->heroes->present($post);
        $override = $this->nullableUrl($post->og_image ?? null)
            ?? $this->nullableUrl($post->twitter_image ?? null);

        if ($override !== null) {
            return [
                'url' => $override,
                'width' => self::WIDTH,
                'height' => self::HEIGHT,
                'alt' => $this->alt($post->hero_image_alt, $title),
                'from_hero' => false,
            ];
        }

        if ($presented['hero_image_social'] ?? null) {
            return [
                'url' => $presented['hero_image_social'],
                'width' => self::WIDTH,
                'height' => self::HEIGHT,
                'alt' => $this->alt($post->hero_image_alt, $title),
                'from_hero' => true,
            ];
        }

        return $this->brandedFallback($title);
    }

    /**
     * @return array{url: string, width: int, height: int, alt: string, from_hero: bool}
     */
    public function forPage(Page $page, string $title): array
    {
        $presented = $this->heroes->present($page);
        $override = $this->nullableUrl($page->og_image ?? null)
            ?? $this->nullableUrl($page->twitter_image ?? null);

        if ($override !== null) {
            return [
                'url' => $override,
                'width' => self::WIDTH,
                'height' => self::HEIGHT,
                'alt' => $this->alt($page->hero_image_alt, $title),
                'from_hero' => false,
            ];
        }

        if ($presented['hero_image_social'] ?? null) {
            return [
                'url' => $presented['hero_image_social'],
                'width' => self::WIDTH,
                'height' => self::HEIGHT,
                'alt' => $this->alt($page->hero_image_alt, $title),
                'from_hero' => true,
            ];
        }

        return $this->brandedFallback($title);
    }

    /**
     * @return array{url: string, width: int, height: int, alt: string, from_hero: bool}
     */
    public function defaultShareImage(?string $title = null): array
    {
        $configured = $this->nullableUrl((string) $this->settings->get(SettingDefinitions::SEO_DEFAULT_SHARE_IMAGE));
        if ($configured !== null) {
            return [
                'url' => $configured,
                'width' => self::WIDTH,
                'height' => self::HEIGHT,
                'alt' => $title ? $this->alt(null, $title) : 'APES Newsroom',
                'from_hero' => false,
            ];
        }

        return $this->brandedFallback($title ?? 'APES Newsroom');
    }

    /**
     * @return array{url: string, width: int, height: int, alt: string, from_hero: bool}
     */
    public function brandedFallback(string $title): array
    {
        $alt = $this->alt(null, $title);

        try {
            $url = $this->ensureBrandedCard($title);
        } catch (Throwable) {
            $url = url('/brand/apes-logo-square.png');
        }

        return [
            'url' => $url,
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'alt' => $alt,
            'from_hero' => false,
        ];
    }

    private function ensureBrandedCard(string $title): string
    {
        $slug = substr(hash('sha256', $title), 0, 16);
        $path = 'seo/social-fallback/'.$slug.'.jpg';
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            $bytes = $this->renderBrandedJpeg($title);
            $disk->put($path, $bytes);
        }

        // Keep generated cards under 1 MB (JPEG quality tuned in render).
        $relative = $disk->url($path);

        return str_starts_with($relative, 'http') ? $relative : url($relative);
    }

    private function renderBrandedJpeg(string $title): string
    {
        $this->codec->raiseMemoryLimit();

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($image === false) {
            throw new \RuntimeException('Could not create social card canvas.');
        }

        $teal = imagecolorallocate($image, 14, 90, 92);
        $mist = imagecolorallocate($image, 232, 246, 246);
        $ink = imagecolorallocate($image, 18, 42, 44);
        imagefilledrectangle($image, 0, 0, self::WIDTH, self::HEIGHT, $teal);
        imagefilledrectangle($image, 48, 48, self::WIDTH - 48, self::HEIGHT - 48, $mist);

        $logoPath = public_path('brand/apes-logo-mark.png');
        if (is_file($logoPath)) {
            $logo = @imagecreatefrompng($logoPath);
            if ($logo !== false) {
                $lw = imagesx($logo);
                $lh = imagesy($logo);
                $target = 96;
                imagecopyresampled($image, $logo, 80, 80, 0, 0, $target, (int) round($target * ($lh / max(1, $lw))), $lw, $lh);
                imagedestroy($logo);
            }
        }

        $label = mb_strlen($title) > 90 ? rtrim(mb_substr($title, 0, 89)).'…' : $title;
        $wrapped = wordwrap($label, 36, "\n", true);
        $y = 220;
        foreach (explode("\n", $wrapped) as $line) {
            imagestring($image, 5, 80, $y, $line, $ink);
            $y += 28;
        }
        imagestring($image, 4, 80, self::HEIGHT - 100, 'APES Newsroom', $teal);

        ob_start();
        imagejpeg($image, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        if (strlen($bytes) > 1024 * 1024) {
            ob_start();
            // Re-encode more aggressively if somehow oversized.
            $again = imagecreatefromstring($bytes);
            if ($again !== false) {
                imagejpeg($again, null, 70);
                imagedestroy($again);
                $bytes = (string) ob_get_clean();
            } else {
                ob_end_clean();
            }
        }

        return $bytes;
    }

    private function alt(?string $heroAlt, string $title): string
    {
        $alt = trim((string) $heroAlt);

        return $alt !== '' ? $alt : $title;
    }

    private function nullableUrl(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
