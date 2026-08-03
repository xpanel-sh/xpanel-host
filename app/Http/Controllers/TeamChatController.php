<?php

namespace App\Http\Controllers;

use App\Models\TeamChatRead;
use App\Models\TeamMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $readAt = TeamChatRead::query()->whereKey($user->id)->value('last_read_at');
        $messages = TeamMessage::query()
            ->with('sender:id,name')
            ->latest('id')
            ->limit(50)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (TeamMessage $message) => [
                'id' => $message->id,
                'body' => $message->body,
                'mine' => $message->sender_id === $user->id,
                'sender' => $message->sender?->name ?? 'Usuario eliminado',
                'created_at' => $message->created_at?->toIso8601String(),
            ]);

        $unread = TeamMessage::query()
            ->where('sender_id', '!=', $user->id)
            ->when($readAt, fn ($query) => $query->where('created_at', '>', $readAt))
            ->count();

        return response()->json(['messages' => $messages, 'unread' => $unread]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000', 'not_regex:/[\x00]/'],
        ]);
        $body = trim($data['body']);
        abort_if($body === '', 422, 'Escribe un mensaje.');

        $message = TeamMessage::create([
            'sender_id' => $request->user()->id,
            'body' => $body,
        ])->load('sender:id,name');

        return response()->json([
            'message' => [
                'id' => $message->id,
                'body' => $message->body,
                'mine' => true,
                'sender' => $message->sender?->name,
                'created_at' => $message->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function read(Request $request): JsonResponse
    {
        TeamChatRead::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['last_read_at' => now()],
        );

        return response()->json(['ok' => true, 'unread' => 0]);
    }
}
