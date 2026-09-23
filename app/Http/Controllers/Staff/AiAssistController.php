<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Integrations\AiAssistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAssistController extends Controller
{
    public function __invoke(Request $request, AiAssistService $ai): JsonResponse
    {
        if (! $ai->enabled()) {
            return response()->json([
                'enabled' => false,
                'message' => 'AI assist is disabled.',
            ], 503);
        }

        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
            'context' => ['nullable', 'string', 'max:8000'],
        ]);

        try {
            $result = $ai->suggest($validated['prompt'], $validated['context'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'enabled' => true,
            'suggestion' => $result['suggestion'],
            'model' => $result['model'],
            'note' => 'Insert into the draft manually. AI assist never publishes.',
        ]);
    }

    public function status(AiAssistService $ai): JsonResponse
    {
        return response()->json([
            'enabled' => $ai->enabled(),
        ]);
    }
}
