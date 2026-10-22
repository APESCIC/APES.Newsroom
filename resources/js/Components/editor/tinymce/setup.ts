/**
 * Self-hosted TinyMCE (GPLv2+, #255). Everything TinyMCE would otherwise fetch
 * from a CDN is bundled here, and skins register as in-memory resources, so the
 * editor makes no third-party requests. Only TinyMceField imports this module,
 * which keeps TinyMCE in its own lazily loaded chunk.
 */
import tinymce from 'tinymce';
import 'tinymce/models/dom';
import 'tinymce/themes/silver';
import 'tinymce/icons/default';
import 'tinymce/skins/ui/oxide/skin.js';
import 'tinymce/skins/ui/oxide/content.js';
import 'tinymce/skins/content/default/content.js';
import 'tinymce/plugins/autolink';
import 'tinymce/plugins/image';
import 'tinymce/plugins/link';
import 'tinymce/plugins/lists';
import 'tinymce/plugins/table';
import 'tinymce/plugins/wordcount';

export default tinymce;
