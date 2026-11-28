<?php

namespace App\Http\Controllers;

use App\Models\AiConnection;
use App\Models\AiConversation;
use App\Models\Site;
use App\Services\CodexSessionManager;
use App\Services\HostingAccountWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class IkodeAgentController extends Controller
{
    public function state(Request $request, CodexSessionManager $codex, ?Site $site = null): JsonResponse
    {
        $routeSite = $site;
        $site = $this->selectedSite($request, $site);
        $selected = $this->connection($request);
        $conversations = $this->conversations($request, $selected, $site)->get();
        $conversation = $conversations->firstWhere('id', (int) $request->query('conversation_id')) ?? $conversations->first();

        return response()->json([
            'scope' => $site ? ['type' => 'site', 'label' => $site->domain] : ['type' => 'account', 'label' => 'Cuenta completa'],
            'sites' => $routeSite ? $this->family($routeSite)->map(fn (Site $item): array => ['id' => $item->id, 'domain' => $item->domain])->values() : [],
            'active_site_id' => $site?->id,
            'connections' => [$this->connectionData($selected)],
            'auth' => $this->safeAuthStatus($codex),
            'installation' => $this->safeInstallStatus($codex),
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
        return response()->json(['message' => 'Usa Iniciar sesión con Codex. Las claves API ya no se utilizan.'], 410);
    }

    public function destroyConnection(Request $request, int $connection, ?Site $site = null): JsonResponse
    {
        return response()->json(['message' => 'La conexión Codex se cierra desde Ajustes.'], 410);
    }

    public function updateConnection(Request $request, int $connection, ?Site $site = null): JsonResponse
    {
        return response()->json(['message' => 'Codex usa el inicio de sesión de la cuenta.'], 410);
    }

    public function storeConversation(Request $request, ?Site $site = null): JsonResponse
    {
        $site = $this->selectedSite($request, $site);
        $connection = $this->connection($request);
        abort_if((int) $request->input('connection_id') !== $connection->id, 422, 'La conexión no pertenece a Codex.');
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
        $site = $this->selectedSite($request, $site);
        $owned = AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->where('scope_key', $this->scopeKey($site))
            ->findOrFail($conversation);
        if ($owned->codex_job_id) {
            return response()->json(['message' => 'Espera a que termine la respuesta antes de eliminar el chat.'], 409);
        }
        $owned->delete();

        return response()->json(['status' => 'deleted']);
    }

    public function login(Request $request, CodexSessionManager $codex, ?Site $site = null): JsonResponse
    {
        $this->selectedSite($request, $site);
        try { return response()->json(['auth' => $codex->startLogin()]); }
        catch (Throwable $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    public function install(Request $request, CodexSessionManager $codex, ?Site $site = null): JsonResponse
    {
        $this->selectedSite($request, $site);
        try { return response()->json(['installation' => $codex->startInstall()]); }
        catch (Throwable $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    public function installStatus(Request $request, CodexSessionManager $codex, ?Site $site = null): JsonResponse
    {
        $this->selectedSite($request, $site);
        return response()->json(['installation' => $this->safeInstallStatus($codex)]);
    }

    public function authStatus(Request $request, CodexSessionManager $codex, ?Site $site = null): JsonResponse
    {
        $this->selectedSite($request, $site);
        return response()->json(['auth' => $this->safeAuthStatus($codex)]);
    }

    public function logout(Request $request, CodexSessionManager $codex, ?Site $site = null): JsonResponse
    {
        $this->selectedSite($request, $site);
        try { return response()->json(['auth' => $codex->logout()]); }
        catch (Throwable $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    public function chat(Request $request, CodexSessionManager $codex, HostingAccountWorkspace $workspace, ?Site $site = null): JsonResponse
    {
        $site = $this->selectedSite($request, $site);
        $data = $request->validate([
            'conversation_id' => 'nullable|integer',
            'message' => 'required|string|max:12000',
            'active_path' => 'nullable|string|max:2048',
        ]);
        $connection = $this->connection($request);
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
        if ($conversation->codex_job_id) {
            return response()->json(['message' => 'Este chat ya tiene una respuesta en curso.'], 409);
        }
        $job = (string) Str::uuid();
        $directory = $site ? $site->document_root : $workspace->systemRoot().'/public_html';
        $scope = $site ? "Trabaja únicamente en el sitio {$site->domain}. No modifiques otros sitios de esta cuenta." : 'Trabaja en los sitios de esta cuenta de hosting.';
        $prompt = "Ámbito: {$scope}\nDirectorio de trabajo: {$directory}\nArchivo activo: ".($data['active_path'] ?? 'ninguno')."\n\nSolicitud del usuario:\n{$data['message']}";
        try {
            $codex->startJob($directory, $job, $conversation->codex_thread_id, $prompt);
            $conversation->messages()->create(['role' => 'user', 'content' => $data['message']]);
            $conversation->update(['title' => $conversation->title === 'Nuevo chat' ? mb_substr(trim($data['message']), 0, 72) : $conversation->title]);
            $conversation->update(['codex_job_id' => $job]);
        } catch (Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'job' => $job,
            'conversation' => $this->conversationData($conversation->fresh()),
        ], 202);
    }

    public function jobStatus(Request $request, CodexSessionManager $codex): JsonResponse
    {
        $site = $request->route('site');
        $job = (string) $request->route('job');
        if (is_string($site)) {
            $site = Site::query()->where('domain', $site)->firstOrFail();
        }
        abort_unless($site === null || $site instanceof Site, 404);
        $site = $this->selectedSite($request, $site);
        $conversation = $this->conversations($request, $this->connection($request), $site)
            ->where('codex_job_id', $job)->firstOrFail();
        try { $status = $codex->jobStatus($job); }
        catch (Throwable $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        if (in_array($status['state'] ?? null, ['done', 'failed'], true)) {
            DB::transaction(function () use ($conversation, $job, $status): void {
                $locked = AiConversation::query()->lockForUpdate()->findOrFail($conversation->id);
                if ($locked->codex_job_id !== $job) return;
                if (($status['state'] ?? null) === 'done' && filled($status['answer'] ?? null)) {
                    $locked->messages()->create(['role' => 'assistant', 'content' => $status['answer']]);
                }
                $locked->update([
                    'codex_job_id' => null,
                    'codex_thread_id' => $status['thread_id'] ?: $locked->codex_thread_id,
                ]);
            });
        }
        return response()->json(['status' => $status, 'conversation_id' => $conversation->id]);
    }

    private function conversations(Request $request, AiConnection $connection, ?Site $site): Builder
    {
        return AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->where('ai_connection_id', $connection->id)
            ->where('scope_key', $this->scopeKey($site))
            ->latest('updated_at');
    }

    private function connection(Request $request): AiConnection
    {
        return AiConnection::query()->firstOrCreate(
            ['user_id' => $request->user()->id, 'provider' => 'codex'],
            ['name' => 'Codex', 'model' => 'Cuenta ChatGPT', 'api_key' => null, 'status' => 'configured'],
        );
    }

    private function safeAuthStatus(CodexSessionManager $codex): array
    {
        try { return $codex->authStatus(); }
        catch (Throwable $exception) { return ['status' => 'error', 'message' => $exception->getMessage()]; }
    }

    private function safeInstallStatus(CodexSessionManager $codex): string
    {
        try { return $codex->installStatus(); }
        catch (Throwable) { return 'error'; }
    }

    private function scopeKey(?Site $site): string
    {
        return $site ? 'site:'.$site->id : 'account';
    }

    private function selectedSite(Request $request, ?Site $site): ?Site
    {
        if ($site === null) {
            abort_if($request->filled('target_site_id'), 422, 'La vista general no acepta otro sitio.');
            return null;
        }
        if (! $request->filled('target_site_id')) {
            return $site;
        }
        $selected = $this->family($site)->firstWhere('id', (int) $request->input('target_site_id'));
        abort_unless($selected && (string) $selected->id === (string) $request->input('target_site_id'), 422, 'El sitio no pertenece a este hosting.');
        return $selected;
    }

    private function family(Site $site): \Illuminate\Support\Collection
    {
        return $site->parent_site_id === null
            ? collect([$site])->concat($site->subdomains()->get())
            : collect([$site]);
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
            'job_id' => $conversation->codex_job_id,
        ];
    }
}
