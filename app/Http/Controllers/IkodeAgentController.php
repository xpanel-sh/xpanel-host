<?php

namespace App\Http\Controllers;

use App\Models\AiConnection;
use App\Models\AiConversation;
use App\Models\Site;
use App\Services\AiProviderClient;
use App\Services\IkodeProjectContext;
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
        $conversation = $selected ? $this->conversation($request, $selected, $site, false) : null;

        return response()->json([
            'scope' => $site ? ['type' => 'site', 'label' => $site->domain] : ['type' => 'account', 'label' => 'Cuenta completa'],
            'connections' => $connections->map(fn (AiConnection $connection): array => $this->connectionData($connection))->values(),
            'active_connection_id' => $selected?->id,
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

    public function chat(Request $request, AiProviderClient $client, IkodeProjectContext $context, ?Site $site = null): JsonResponse
    {
        $data = $request->validate([
            'connection_id' => 'required|integer',
            'message' => 'required|string|max:12000',
            'active_path' => 'nullable|string|max:2048',
        ]);
        $connection = AiConnection::query()->whereBelongsTo($request->user())->findOrFail($data['connection_id']);
        $conversation = $this->conversation($request, $connection, $site);
        $userMessage = $conversation->messages()->create(['role' => 'user', 'content' => $data['message']]);
        $history = $conversation->messages()->latest()->limit(16)->get()->reverse()->values()->map(fn ($message): array => [
            'role' => $message->role, 'content' => $message->content,
        ])->all();

        try {
            $reply = $client->reply($connection, $context->build($site, $data['active_path'] ?? null), $history);
            $assistant = $conversation->messages()->create(['role' => 'assistant', 'content' => $reply]);
            $connection->update(['status' => 'connected', 'last_error' => null, 'last_used_at' => now()]);
        } catch (Throwable $exception) {
            $connection->update(['status' => 'error', 'last_error' => mb_substr($exception->getMessage(), 0, 1000)]);
            $userMessage->delete();

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => ['id' => $assistant->id, 'role' => 'assistant', 'content' => $assistant->content],
            'connection' => $this->connectionData($connection->fresh()),
        ]);
    }

    private function conversation(Request $request, AiConnection $connection, ?Site $site, bool $create = true): ?AiConversation
    {
        $scopeKey = $site ? 'site:'.$site->id : 'account';
        $attributes = ['user_id' => $request->user()->id, 'ai_connection_id' => $connection->id, 'scope_key' => $scopeKey];

        return $create
            ? AiConversation::firstOrCreate($attributes, ['site_id' => $site?->id, 'title' => $site?->domain ?? 'Cuenta completa'])
            : AiConversation::query()->where($attributes)->first();
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
}
