export type EmbedService = 'youtube' | 'vimeo' | 'twitter' | 'instagram' | 'codepen';

export type EmbedBlockData = {
    service: EmbedService;
    source: string;
    embed: string;
    width: number;
    height: number;
    caption: string;
};

type ServiceRule = {
    service: EmbedService;
    label: string;
    pattern: RegExp;
    embed: (match: RegExpMatchArray) => string | null;
    width: number;
    height: number;
};

/**
 * Same services, URL patterns and sizes as the Editor.js embed tool config in
 * EditorJsField, so TinyMCE produces identical `embed` blocks.
 */
const RULES: ServiceRule[] = [
    {
        service: 'youtube',
        label: 'YouTube',
        pattern:
            /(?:https?:\/\/)?(?:www\.)?(?:(?:youtu\.be\/)|(?:youtube\.com)\/(?:v\/|u\/\w\/|embed\/|watch|shorts\/))(?:(?:\?v=)?([^#&?=]*))?((?:[?&]\w*=\w*)*)/,
        embed: (match) => (match[1] ? `https://www.youtube.com/embed/${match[1]}` : null),
        width: 580,
        height: 320,
    },
    {
        service: 'vimeo',
        label: 'Vimeo',
        pattern: /(?:http[s]?:\/\/)?(?:www.)?(?:player.)?vimeo\.co(?:.+\/([^/]\d+)(?:#t=[\d]+)?s?$)/,
        embed: (match) => (match[1] ? `https://player.vimeo.com/video/${match[1]}?title=0&byline=0` : null),
        width: 580,
        height: 320,
    },
    {
        service: 'twitter',
        label: 'X (Twitter)',
        pattern: /^https?:\/\/(www\.)?(?:twitter\.com|x\.com)\/.+\/status\/(\d+)/,
        embed: (match) => (match[2] ? `https://platform.twitter.com/embed/Tweet.html?id=${match[2]}` : null),
        width: 600,
        height: 300,
    },
    {
        service: 'instagram',
        label: 'Instagram',
        pattern: /^https:\/\/(?:www\.)?instagram\.com\/(?:reel|p)\/(.*)/,
        embed: (match) => {
            const id = match[1]?.split('/')[0];
            return id ? `https://www.instagram.com/p/${id}/embed` : null;
        },
        width: 400,
        height: 505,
    },
    {
        service: 'codepen',
        label: 'CodePen',
        pattern: /https?:\/\/codepen\.io\/([^/?&]*)\/pen\/([^/?&]*)/,
        embed: (match) =>
            match[1] && match[2]
                ? `https://codepen.io/${match[1]}/embed/${match[2]}?height=300&theme-id=0&default-tab=css,result&embed-version=2`
                : null,
        width: 600,
        height: 300,
    },
];

export const SUPPORTED_EMBED_LABELS = RULES.map((rule) => rule.label);

export function resolveEmbed(url: string, caption = ''): EmbedBlockData | null {
    const source = url.trim();

    if (!/^https?:\/\//i.test(source)) {
        return null;
    }

    for (const rule of RULES) {
        const match = source.match(rule.pattern);
        const embed = match ? rule.embed(match) : null;

        if (embed) {
            return {
                service: rule.service,
                source,
                embed,
                width: rule.width,
                height: rule.height,
                caption: caption.trim(),
            };
        }
    }

    return null;
}

export function embedLabel(service: EmbedService): string {
    return RULES.find((rule) => rule.service === service)?.label ?? service;
}
