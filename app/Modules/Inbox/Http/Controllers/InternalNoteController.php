<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InternalNote;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalNoteController extends Controller
{
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);

        $notes = $conversation->internalNotes()->with('user:id,name')->latest()->get();

        return response()->json($notes);
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
        ]);

        $note = InternalNote::create([
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
            'mentioned_user_ids' => [],
        ]);

        $note->load('user:id,name');

        return response()->json($note, 201);
    }

    private function authorise(Request $request, Conversation $conversation): void
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $conversation->workspace_id === (int) $workspaceId, 403);
    }
}
