import { describe, expect, it } from 'vitest';
import { resolveEmbed } from '../Components/editor/embedServices';

describe('resolveEmbed', () => {
    it.each([
        ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'https://www.youtube.com/embed/dQw4w9WgXcQ'],
        ['https://youtu.be/dQw4w9WgXcQ', 'youtube', 'https://www.youtube.com/embed/dQw4w9WgXcQ'],
        ['https://vimeo.com/76979871', 'vimeo', 'https://player.vimeo.com/video/76979871?title=0&byline=0'],
        ['https://x.com/apesorguk/status/1234567890', 'twitter', 'https://platform.twitter.com/embed/Tweet.html?id=1234567890'],
        ['https://twitter.com/apesorguk/status/42', 'twitter', 'https://platform.twitter.com/embed/Tweet.html?id=42'],
        ['https://www.instagram.com/p/AbC123/', 'instagram', 'https://www.instagram.com/p/AbC123/embed'],
        [
            'https://codepen.io/apes/pen/abcXYZ',
            'codepen',
            'https://codepen.io/apes/embed/abcXYZ?height=300&theme-id=0&default-tab=css,result&embed-version=2',
        ],
    ])('resolves %s', (url, service, embed) => {
        expect(resolveEmbed(url)).toMatchObject({ service, source: url, embed });
    });

    it.each(['', 'javascript:alert(1)', 'https://evil.example/watch?v=1', 'ftp://youtube.com/watch?v=1', 'https://www.youtube.com/'])(
        'rejects %s',
        (url) => {
            expect(resolveEmbed(url)).toBeNull();
        },
    );
});
