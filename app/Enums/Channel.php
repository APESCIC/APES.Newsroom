<?php

namespace App\Enums;

enum Channel: string
{
    case ApesCic = 'apes_cic';
    case ApesShelterRescue = 'apes_shelter_rescue';
    case ApesPetCareClinic = 'apes_pet_care_clinic';
    case ApesTimes = 'apes_times';
    case MyapesPortal = 'myapes_portal';
    case Stream4Apes = 'stream_4_apes';

    public function label(): string
    {
        return match ($this) {
            self::ApesCic => 'APES CIC',
            self::ApesShelterRescue => 'APES Shelter & Rescue',
            self::ApesPetCareClinic => 'APES Pet Care Clinic',
            self::ApesTimes => 'APES Times',
            self::MyapesPortal => 'MyAPES',
            self::Stream4Apes => 'Stream 4 APES',
        };
    }

    public function slug(): string
    {
        return match ($this) {
            self::ApesCic => 'apes-cic',
            self::ApesShelterRescue => 'apes-shelter-rescue',
            self::ApesPetCareClinic => 'apes-pet-care-clinic',
            self::ApesTimes => 'apes-times',
            self::MyapesPortal => 'myapes',
            self::Stream4Apes => 'stream-4-apes',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        return collect(self::cases())->first(fn (self $c) => $c->slug() === $slug);
    }

    /**
     * Regex alternation of every channel slug, for top-level route constraints.
     */
    public static function routePattern(): string
    {
        return implode('|', array_map(fn (self $c) => $c->slug(), self::cases()));
    }
}
