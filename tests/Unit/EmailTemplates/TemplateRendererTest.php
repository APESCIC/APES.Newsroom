<?php

namespace Tests\Unit\EmailTemplates;

use App\Services\EmailTemplates\TemplateRenderer;
use App\Support\EmailTemplates\TemplateDefinition;
use App\Support\EmailTemplates\TemplateRegistry;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new TemplateRenderer;
    }

    #[Test]
    public function it_replaces_whitespace_tolerant_placeholders(): void
    {
        $out = $this->renderer->render(
            'Hello {{ name }} and {{name}}.',
            ['name' => 'Ada'],
            ['name'],
        );

        $this->assertSame('Hello Ada and Ada.', $out);
    }

    #[Test]
    public function it_escapes_xss_in_placeholder_values(): void
    {
        $out = $this->renderer->render(
            'Hi {{name}}',
            ['name' => '<script>alert(1)</script>'],
            ['name'],
        );

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    #[Test]
    public function it_rejects_unknown_tags_in_the_template(): void
    {
        $this->expectException(DomainException::class);

        $this->renderer->render('Hello {{evil}}', ['evil' => 'x'], ['name']);
    }

    #[Test]
    public function it_does_not_evaluate_blade_or_php_from_stored_content(): void
    {
        $blade = '@php echo "pwned"; @endphp {{ name }} <?php echo "pwned"; ?>';
        $out = $this->renderer->render($blade, ['name' => 'safe'], ['name']);

        $this->assertStringContainsString('@php echo "pwned"; @endphp', $out);
        $this->assertStringContainsString('<?php echo "pwned"; ?>', $out);
        $this->assertStringContainsString('safe', $out);
        $this->assertStringNotContainsString('pwned', str_replace(['@php echo "pwned"; @endphp', '<?php echo "pwned"; ?>'], '', $out));
    }

    #[Test]
    public function it_treats_literal_blade_echo_syntax_as_text_when_not_a_placeholder_tag(): void
    {
        $out = $this->renderer->render(
            'Keep {{ $user->name }} literal; replace {{name}}.',
            ['name' => 'Ada'],
            ['name'],
        );

        $this->assertStringContainsString('{{ $user->name }}', $out);
        $this->assertStringContainsString('Ada', $out);
    }

    #[Test]
    public function validate_reports_unknown_and_missing_required_placeholders(): void
    {
        $def = new TemplateDefinition(
            key: 'demo',
            name: 'Demo',
            group: TemplateRegistry::GROUP_ACCOUNT,
            allowedPlaceholders: ['url', 'minutes'],
            requiredPlaceholders: ['url', 'minutes'],
            defaultSubject: 'Subject',
            defaultBody: 'Body',
        );

        $errors = $this->renderer->validate('Hi {{url}} {{evil}}', 'Body without minutes', $def);

        $this->assertContains('Unknown placeholder {{evil}}.', $errors);
        $this->assertContains('Required placeholder {{minutes}} is missing.', $errors);
    }

    #[Test]
    public function to_html_sanitises_dangerous_markup_after_markdown(): void
    {
        $html = $this->renderer->toHtml("Hello **world**\n\n<script>alert(1)</script>\n\n[Click](javascript:alert(1))");

        $this->assertStringContainsString('<strong>world</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    #[Test]
    public function to_text_strips_markup(): void
    {
        $text = $this->renderer->toText('<p>Hello <strong>world</strong></p>');

        $this->assertSame('Hello world', $text);
    }

    #[Test]
    public function render_subject_decodes_entities_for_plain_headers(): void
    {
        $subject = $this->renderer->renderSubject(
            'Hello {{name}}',
            ['name' => 'Ada & Bea'],
            ['name'],
        );

        $this->assertSame('Hello Ada & Bea', $subject);
    }
}
