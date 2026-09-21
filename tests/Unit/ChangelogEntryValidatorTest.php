<?php

namespace Tests\Unit;

use App\Services\Releases\ChangelogEntryValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChangelogEntryValidatorTest extends TestCase
{
    private ChangelogEntryValidator $validator;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ChangelogEntryValidator;
        $this->tempDir = sys_get_temp_dir().'/newsroom-changelog-'.uniqid('', true);
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function it_accepts_a_valid_entry_file(): void
    {
        $path = $this->write('v1.2.3.json', [
            'version' => 'v1.2.3',
            'summary' => 'Shipped a fix.',
            'detailed_changes' => ['Fixed footer link.'],
            'change_types' => ['fixed'],
            'topic_tags' => ['public-facing'],
        ]);

        $this->assertSame([], $this->validator->validateFile($path));
    }

    #[Test]
    public function it_rejects_filename_version_mismatch(): void
    {
        $path = $this->write('v9.9.9.json', [
            'version' => 'v1.2.3',
            'summary' => 'Shipped a fix.',
            'detailed_changes' => ['Fixed footer link.'],
            'change_types' => ['fixed'],
            'topic_tags' => ['public-facing'],
        ]);

        $errors = $this->validator->validateFile($path);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Filename must match version', $errors[0]);
    }

    #[Test]
    public function it_rejects_invalid_change_types(): void
    {
        $path = $this->write('v1.2.3.json', [
            'version' => 'v1.2.3',
            'summary' => 'Shipped a fix.',
            'detailed_changes' => ['Fixed footer link.'],
            'change_types' => ['enhanced'],
            'topic_tags' => ['public-facing'],
        ]);

        $errors = $this->validator->validateFile($path);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('change_types', implode("\n", $errors));
    }

    #[Test]
    public function it_rejects_template_files(): void
    {
        $path = $this->write('_template.json', [
            'version' => 'v1.2.3',
            'summary' => 'Template',
            'detailed_changes' => ['x'],
            'change_types' => ['added'],
            'topic_tags' => ['public-facing'],
        ]);

        $errors = $this->validator->validateFile($path);
        $this->assertNotEmpty($errors);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(string $name, array $payload): string
    {
        $path = $this->tempDir.'/'.$name;
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }
}
