# Staff editors: Editor.js and TinyMCE

The Newsroom has two editors for staff posts and pages. An admin picks one for
the whole site; there is no per-user or per-post choice.

| | Editor.js (default) | TinyMCE |
|---|---|---|
| Feel | Block-based: each paragraph, image or embed is its own block | Word-processor style, one continuous document |
| Text formatting | Headings (H2 to H4), bold, italic, inline code, links, lists, quotes, tables | The same |
| Images | Upload or link, with alt text, caption and credit | Upload or link, with alt text and caption; existing credits are kept |
| Callouts, toggles, galleries, video, audio, files, bookmarks, products, link previews, Markdown or HTML snippets | Create and edit | Shown as read-only grey boxes: move or delete them, but edit them in Editor.js |
| Embeds (YouTube, Vimeo, X, Instagram, CodePen) | Create and edit | Add with the **Embed** button; existing embeds are read-only boxes |

Whichever editor is used, content is stored in the same Editor.js block format,
so public pages, search, email campaigns and the Admin API look and behave the
same. Switching editors needs no content migration.

## For admins: choosing the editor

1. Open **Admin panel → Settings**.
2. Under **Editor**, choose **Editor.js** or **TinyMCE** and select **Save
   settings**. Saving asks you to confirm your identity if you have not done so
   recently, and the change is recorded in the audit log.
3. Staff get the new editor the next time they open a post or page. Anyone with
   an editor already open keeps that editor until they reload; their saves are
   stored correctly either way, and they see a notice that the setting changed.

## For staff: writing in TinyMCE

- Use **Paragraph** and **Heading 2 to 4** from the block menu. Fonts, colours
  and other styling are not offered and are removed when you paste.
- Use the toolbar for bold, italic, inline code, links, bulleted and numbered
  lists, quotes, images, tables and horizontal lines.
- **Images:** upload a file or paste a link, and always fill in the alternative
  description; a post with an image that has no alt text will not save.
- **Embeds:** select **Embed a post or video**, paste a YouTube, Vimeo, X,
  Instagram or CodePen link and add an optional caption. Other links are refused.
- **Grey boxes** are blocks TinyMCE cannot edit, such as callouts and galleries.
  You can move or delete them. To change what is inside, ask an admin to switch
  the site to Editor.js, edit the block there, then switch back.
- Drafts autosave about eight seconds after you stop typing, and every save adds
  a revision you can restore from the **Revisions** list.

## Technical notes

- TinyMCE 8 is self-hosted from npm under GPLv2+ (`license_key: 'gpl'`,
  confirmed on [#245](https://github.com/APESCIC/APES.Newsroom/issues/245)). It
  loads only on staff editor screens when the site uses TinyMCE, and makes no
  requests to Tiny Cloud.
- The edit screen receives `content_html` produced by
  `App\Services\EditorJs\EditorHtmlPresenter`; saves send `editor=tinymce` and
  `content_html`, which `App\Services\EditorJs\HtmlToBlocksConverter` turns back
  into blocks before `BlockValidator` runs. Read-only boxes carry the original
  block in a `data-block` attribute and are restored unchanged.
- Text fields keep a small allowlist of inline formatting in both editors:
  `strong`, `em`, `code`, `br` and `a` links (http, https and mailto only).
