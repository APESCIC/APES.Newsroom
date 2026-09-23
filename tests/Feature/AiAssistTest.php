<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAssistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_status_reports_disabled_by_default(): void
    {
        config([
            'newsroom.ai_assist.enabled' => false,
            'newsroom.ai_assist.api_key' => null,
        ]);

        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->getJson(route('staff.ai.status'))
            ->assertOk()
            ->assertJsonPath('enabled', false);
    }

    public function test_suggest_returns_503_when_disabled(): void
    {
        config([
            'newsroom.ai_assist.enabled' => false,
            'newsroom.ai_assist.api_key' => 'sk-test',
        ]);

        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->postJson(route('staff.ai.suggest'), ['prompt' => 'Write an intro'])
            ->assertStatus(503)
            ->assertJsonPath('enabled', false);
    }

    public function test_suggest_returns_draft_text_when_enabled(): void
    {
        config([
            'newsroom.ai_assist.enabled' => true,
            'newsroom.ai_assist.api_key' => 'sk-test',
            'newsroom.ai_assist.model' => 'gpt-4o-mini',
        ]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Suggested opening paragraph.'],
                ]],
            ], 200),
        ]);

        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->postJson(route('staff.ai.suggest'), [
                'prompt' => 'Write an intro about union news',
                'context' => 'Title: Members win',
            ])
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('suggestion', 'Suggested opening paragraph.')
            ->assertJsonPath('model', 'gpt-4o-mini')
            ->assertJsonPath('note', 'Insert into the draft manually. AI assist never publishes.');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.openai.com')
                && $request['messages'][1]['content'] !== null;
        });
    }

    public function test_guests_cannot_use_ai_assist(): void
    {
        $this->getJson(route('staff.ai.status'))->assertUnauthorized();
        $this->postJson(route('staff.ai.suggest'), ['prompt' => 'x'])->assertUnauthorized();
    }

    public function test_enabled_requires_api_key(): void
    {
        config([
            'newsroom.ai_assist.enabled' => true,
            'newsroom.ai_assist.api_key' => null,
        ]);

        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->getJson(route('staff.ai.status'))
            ->assertOk()
            ->assertJsonPath('enabled', false);
    }
}
