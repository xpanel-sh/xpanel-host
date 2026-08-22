<?php

namespace App\Http\Controllers;

use App\Models\AiConnection;
use App\Models\AiConversation;
use App\Models\Site;
use App\Services\AiProviderClient;
use App\Services\IkodeProjectContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class IkodeAgentController extends Controller
{
    public function state(Request $request, ?Site $site = null): JsonResponse
    {
        $connections = AiConnection::query()->whereBelongsTo($request->user())->latest()->get();
        $selected = $connections->firstWhere('id', (int) $request->query('connection_id')) ?? $connections->first();
        $conversations = $selected ? $this->conversations($request, $selected, $site)->get() : collect();
        $conversation = $conversations->firstWhere('id', (int) $request->query('conversation_id')) ?? $conversations->first();

        return response()->json([
            'scope' => $site ? ['type' => 'site', 'label' => $site->domain] : ['type' => 'account', 'label' => 'Cuenta completa'],
            'connections' => $connections->map(fn (AiConnection $connection): array => $this->connectionData($connection))->values(),
            'active_connection_id' => $selected?->id,
            'conversations' => $conversations->map(fn (AiConversation $item): array => $this->conversationData($item))->values(),
            'active_conversation_id' => $conversation?->id,
            'messages' => $conversation?->messages()->latest()->limit(50)->get()->reverse()->values()->map(fn ($message): array => [
                'id' => $message->id, 'role' => $message->role, 'content' => $message->content,
            ]) ?? [],
        ]);
    }

    public function storeConnection(Request $request, ?Site $site = null): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(['openai', 'anthropic'])],
            'name' => 'required|string|max:80',
            'model' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'api_key' => 'required|string|min:16|max:500',
        ]);
        $connection = AiConnection::create([...$data, 'user_id' => $request->user()->id, 'status' => 'configured']);

        return response()->json(['connection' => $this->connectionData($connection)], 201);
    }

    public function destroyConnection(Request $request, int $connection, ?Site $site = null): JsonResponse
    {
        $owned = AiConnection::query()->whereBelongsTo($request->user())->findOrFail($connection);
        $owned->delete();

        return response()->json(['status' => 'deleted']);
    }

    public function updateConnection(Request $request, int $connection, ?Site $site = null): JsonResponse
    {
        $owned = AiConnection::query()->whereBelongsTo($request->user())->findOrFail($connection);
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'model' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'api_key' => 'nullable|string|min:16|max:500',
        ]);
        if (! filled($data['api_key'] ?? null)) {
            unset($data['api_key']);
        }
        $owned->update([...$data, 'status' => 'configured', 'last_error' => null]);

        return response()->json(['connection' => $this->connectionData($owned->fresh())]);
    }

    public function storeConversation(Request $request, ?Site $site = null): JsonResponse
    {
        $data = $request->validate(['connection_id' => 'required|integer']);
        $connection = AiConnection::query()->whereBelongsTo($request->user())->findOrFail($data['connection_id']);
        $conversation = AiConversation::create([
            'user_id' => $request->user()->id,
            'ai_connection_id' => $connection->id,
            'site_id' => $site?->id,
            'scope_key' => $this->scopeKey($site),
            'title' => 'Nuevo chat',
        ]);

        return response()->json(['conversation' => $this->conversationData($conversation)], 201);
    }

    public function destroyConversation(Request $request, int $conversation, ?Site $site = null): JsonResponse
    {
        $owned = AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->where('scope_key', $this->scopeKey($site))
            ->findOrFail($conversation);
        $owned->delete();

        return response()->json(['status' => 'deleted']);
    }

    public function chat(Request $request, AiProviderClient $client, IkodeProjectContext $context, ?Site $site = null): JsonResponse
    {
        $data = $request->validate([
            'connection_id' => 'required|integer',
            'conversation_id' => 'nullable|integer',
            'message' => 'required|string|max:12000',
            'active_path' => 'nullable|string|max:2048',
        ]);
        $connection = AiConnection::query()->whereBelongsTo($request->user())->findOrFail($data['connection_id']);
        $conversation = isset($data['conversation_id'])
            ? $this->conversations($request, $connection, $site)->findOrFail($data['conversation_id'])
            : null;
        $conversation ??= AiConversation::create([
            'user_id' => $request->user()->id,
            'ai_connection_id' => $connection->id,
            'site_id' => $site?->id,
            'scope_key' => $this->scopeKey($site),
            'title' => 'Nuevo chat',
        ]);
        $userMessage = $conversation->messages()->create(['role' => 'user', 'content' => $data['message']]);
        $history = $conversation->messages()->latest()->limit(16)->get()->reverse()->values()->map(fn ($message): array => [
            'role' => $message->role, 'content' => $message->content,
        ])->all();

        try {
            $reply = $client->reply($connection, $context->build($site, $data['active_path'] ?? null), $history);
            $assistant = $conversation->messages()->create(['role' => 'assistant', 'content' => $reply]);
            $conversation->update(['title' => $conversation->title === 'Nuevo chat' ? mb_substr(trim($data['message']), 0, 72) : $conversation->title]);
            $connection->update(['status' => 'connected', 'last_error' => null, 'last_used_at' => now()]);
        } catch (Throwable $exception) {
            $connection->update(['status' => 'error', 'last_error' => mb_substr($exception->getMessage(), 0, 1000)]);
            $userMessage->delete();

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => ['id' => $assistant->id, 'role' => 'assistant', 'content' => $assistant->content],
            'connection' => $this->connectionData($connection->fresh()),
            'conversation' => $this->conversationData($conversation->fresh()),
        ]);
    }

    private function conversations(Request $request, AiConnection $connection, ?Site $site): Builder
    {
        return AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->where('ai_connection_id', $connection->id)
            ->where('scope_key', $this->scopeKey($site))
            ->latest('updated_at');
    }

    private function scopeKey(?Site $site): string
    {
        return $site ? 'site:'.$site->id : 'account';
    }

    private function connectionData(AiConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'provider' => $connection->provider,
            'name' => $connection->name,
            'model' => $connection->model,
            'status' => $connection->status,
            'last_error' => $connection->last_error,
        ];
    }

    private function conversationData(AiConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title ?: 'Nuevo chat',
            'updated_at' => $conversation->updated_at?->toIso8601String(),
        ];
    }
}
