<?php

namespace App\Services\Engagement;

use App\Enums\ModerationStatus;
use App\Models\ModerationAudit;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfileService
{
    public function forUser(User $user): Profile
    {
        return Profile::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'moderation_status' => ModerationStatus::Private,
                'public_opt_in' => false,
                'display_name' => $user->name,
            ],
        );
    }

    /**
     * @param  array{display_name?: string|null, bio?: string|null, public_opt_in?: bool}  $data
     */
    public function update(User $user, array $data, ?UploadedFile $avatar = null): Profile
    {
        $profile = $this->forUser($user);
        $materialChange = false;

        foreach (['display_name', 'bio'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== $profile->{$field}) {
                $materialChange = true;
                $profile->{$field} = $data[$field];
            }
        }

        if (array_key_exists('public_opt_in', $data)) {
            $optIn = (bool) $data['public_opt_in'];
            if ($optIn !== $profile->public_opt_in) {
                $materialChange = true;
                $profile->public_opt_in = $optIn;
            }
        }

        if ($avatar) {
            $path = $this->storeAvatar($avatar, $user->id);
            if ($profile->avatar_path) {
                Storage::disk('public')->delete($profile->avatar_path);
            }
            $profile->avatar_path = $path;
            $materialChange = true;
        }

        if ($materialChange && $profile->public_opt_in) {
            $profile->moderation_status = ModerationStatus::Pending;
            $profile->moderation_notes = null;
            $profile->moderated_by = null;
            $profile->moderated_at = null;
        } elseif (! $profile->public_opt_in) {
            $profile->moderation_status = ModerationStatus::Private;
        }

        $profile->save();

        $this->audit($user->id, $profile, 'profile_updated', [
            'public_opt_in' => $profile->public_opt_in,
            'status' => $profile->moderation_status->value,
        ]);

        return $profile->fresh();
    }

    public function moderate(Profile $profile, User $moderator, ModerationStatus $status, ?string $notes = null): Profile
    {
        $profile->update([
            'moderation_status' => $status,
            'moderation_notes' => $notes,
            'moderated_by' => $moderator->id,
            'moderated_at' => now(),
        ]);

        $this->audit($moderator->id, $profile, 'profile_moderated', [
            'status' => $status->value,
            'notes' => $notes,
        ]);

        return $profile->fresh();
    }

    /**
     * A staff or admin edit of the public fields. The moderation status is
     * left as it is: staff edits are already reviewed (#572).
     *
     * @param  array{display_name: string|null, bio: string|null}  $data
     * @return list<string> the fields that changed
     */
    public function staffUpdate(Profile $profile, User $actor, array $data, ?UploadedFile $avatar = null, bool $removeAvatar = false): array
    {
        $changed = [];

        foreach (['display_name', 'bio'] as $field) {
            if ($data[$field] !== $profile->{$field}) {
                $profile->{$field} = $data[$field];
                $changed[] = $field;
            }
        }

        if ($avatar) {
            $path = $this->storeAvatar($avatar, (int) $profile->user_id);
            $this->deleteAvatar($profile);
            $profile->avatar_path = $path;
            $changed[] = 'avatar';
        } elseif ($removeAvatar && $profile->avatar_path) {
            $this->deleteAvatar($profile);
            $profile->avatar_path = null;
            $changed[] = 'avatar';
        }

        if ($changed !== []) {
            $profile->save();
            $this->audit($actor->id, $profile, 'profile_staff_edited', ['fields' => $changed]);
        }

        return $changed;
    }

    /**
     * Clear the display name, bio and avatar back to defaults.
     *
     * @return list<string> the fields that were cleared
     */
    public function resetFields(Profile $profile, User $actor): array
    {
        $cleared = array_values(array_filter([
            $profile->display_name !== null ? 'display_name' : null,
            $profile->bio !== null ? 'bio' : null,
            $profile->avatar_path !== null ? 'avatar' : null,
        ]));

        if ($cleared === []) {
            return [];
        }

        $this->deleteAvatar($profile);
        $profile->forceFill(['display_name' => null, 'bio' => null, 'avatar_path' => null])->save();
        $this->audit($actor->id, $profile, 'profile_fields_reset', ['fields' => $cleared]);

        return $cleared;
    }

    private function deleteAvatar(Profile $profile): void
    {
        if ($profile->avatar_path) {
            Storage::disk('public')->delete($profile->avatar_path);
        }
    }

    private function storeAvatar(UploadedFile $avatar, int $userId): string
    {
        $relative = 'avatars/'.$userId.'/'.Str::uuid().'.jpg';

        if (function_exists('imagecreatefromstring')) {
            $image = @imagecreatefromstring($avatar->get());
            if ($image === false) {
                throw new \InvalidArgumentException('Invalid image upload.');
            }

            $width = imagesx($image);
            $height = imagesy($image);
            $size = min($width, $height, 512);
            $dst = imagecreatetruecolor($size, $size);
            imagecopyresampled(
                $dst,
                $image,
                0,
                0,
                (int) (($width - min($width, $height)) / 2),
                (int) (($height - min($width, $height)) / 2),
                $size,
                $size,
                min($width, $height),
                min($width, $height),
            );

            $absolute = Storage::disk('public')->path($relative);
            if (! is_dir(dirname($absolute))) {
                mkdir(dirname($absolute), 0755, true);
            }
            imagejpeg($dst, $absolute, 85);
            imagedestroy($image);
            imagedestroy($dst);

            return $relative;
        }

        return $avatar->storeAs(
            'avatars/'.$userId,
            Str::uuid().'.'.$avatar->getClientOriginalExtension(),
            'public',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(?int $actorId, Profile $profile, string $action, array $payload = []): void
    {
        ModerationAudit::create([
            'actor_id' => $actorId,
            'subject_type' => Profile::class,
            'subject_id' => $profile->id,
            'action' => $action,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
