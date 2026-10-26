import type { IconName } from './Components/Icons/LineIcon';

export type ChannelAccent = 'apes' | 'shelter' | 'clinic' | 'times' | 'portal' | 'stream';

/**
 * Per-channel styling and short badge labels. Public channel names and
 * descriptions come from the server (Newsletters settings).
 */
export type ChannelMeta = {
    label: string;
    description: string;
    accent: ChannelAccent;
    icon: IconName;
    tintClass: string;
    textClass: string;
    borderClass: string;
    badgeClass: string;
    mediaClass: string;
};

export const channelMetaBySlug: Record<string, ChannelMeta> = {
    'apes-cic': {
        label: 'APES CIC',
        description: 'Mission stories from the field of global conservation.',
        accent: 'apes',
        icon: 'tree',
        tintClass: 'glass-channel-apes',
        textClass: 'text-apes-primary',
        borderClass: 'border-apes-primary',
        badgeClass: 'bg-apes-mist text-apes-primary',
        mediaClass: 'bg-apes-mist text-apes-primary',
    },
    'apes-shelter-rescue': {
        label: 'Shelter & Rescue',
        description: 'Updates on rescued animals and adoption successes.',
        accent: 'shelter',
        icon: 'shelter',
        tintClass: 'glass-channel-shelter',
        textClass: 'text-shelter-text',
        borderClass: 'border-shelter-accent',
        badgeClass: 'bg-shelter-mist text-shelter-text',
        mediaClass: 'bg-shelter-mist text-shelter-accent',
    },
    'apes-pet-care-clinic': {
        label: 'Pet Care Clinic',
        description: 'Expert clinic notes and community health advice.',
        accent: 'clinic',
        icon: 'clinic',
        tintClass: 'glass-channel-clinic',
        textClass: 'text-clinic-text',
        borderClass: 'border-clinic-accent',
        badgeClass: 'bg-clinic-mist text-clinic-text',
        mediaClass: 'bg-clinic-mist text-clinic-accent',
    },
    'apes-times': {
        label: 'APES Times',
        description: 'News and features from APES Times.',
        accent: 'times',
        icon: 'newspaper',
        tintClass: 'glass-channel-times',
        textClass: 'text-times-text',
        borderClass: 'border-times-accent',
        badgeClass: 'bg-times-mist text-times-text',
        mediaClass: 'bg-times-mist text-times-accent',
    },
    'myapes-portal': {
        label: 'MyAPES Portal',
        description: 'Updates about the MyAPES member portal.',
        accent: 'portal',
        icon: 'portal',
        tintClass: 'glass-channel-portal',
        textClass: 'text-portal-text',
        borderClass: 'border-portal-accent',
        badgeClass: 'bg-portal-mist text-portal-text',
        mediaClass: 'bg-portal-mist text-portal-accent',
    },
    'stream-4-apes': {
        label: 'Stream 4 APES',
        description: 'Stream 4 APES fundraising updates.',
        accent: 'stream',
        icon: 'play',
        tintClass: 'glass-channel-stream',
        textClass: 'text-stream-text',
        borderClass: 'border-stream-accent',
        badgeClass: 'bg-stream-mist text-stream-text',
        mediaClass: 'bg-stream-mist text-stream-accent',
    },
};

const slugAliases: Record<string, string> = {
    apes: 'apes-cic',
    apes_cic: 'apes-cic',
    'shelter-rescue': 'apes-shelter-rescue',
    apes_shelter_rescue: 'apes-shelter-rescue',
    'pet-care-clinic': 'apes-pet-care-clinic',
    apes_pet_care_clinic: 'apes-pet-care-clinic',
    apes_times: 'apes-times',
    myapes_portal: 'myapes-portal',
    stream_4_apes: 'stream-4-apes',
};

export function channelMeta(value: string): ChannelMeta | undefined {
    return channelMetaBySlug[slugAliases[value] ?? value];
}

export function canonicalChannelSlug(value: string): string {
    return slugAliases[value] ?? value;
}

export function channelTintClass(value: string): string {
    return channelMeta(value)?.tintClass ?? 'glass-channel-neutral';
}
