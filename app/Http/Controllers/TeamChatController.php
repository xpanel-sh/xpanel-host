<?php

namespace App\Http\Controllers;

use App\Models\TeamConversation;
use App\Models\TeamMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamChatController extends Controller
{
    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();
        $conversations = $user->teamConversations()
            ->with(['participants:id,name', 'latestMessage.sender:id,name'])
            ->latest('team_conversations.updated_at')
            ->get();

        return response()->json([
            'conversations' => $conversations->map(fn (TeamConversation $conversation) => $this->conversationData($conversation, $user)),
            'unread' => $conversations->sum(fn (TeamConversation $conversation) => $this->unread($conversation, $user)),
            'users' => User::query()->whereKeyNot($user->id)->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function createConversation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['direct', 'group'])],
            'name' => ['nullable', 'string', 'max:80'],
            'participant_ids' => ['required', 'array', 'min:1', 'max:49'],
            'participant_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
        ]);
        $participantIds = collect($data['participant_ids'])->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === $request->user()->id)->unique()->values();
        abort_if($participantIds->isEmpty(), 422, 'Selecciona al menos otro miembro del equipo.');

        if ($data['type'] === 'direct') {
            abort_unless($participantIds->count() === 1, 422, 'Una conversación directa sólo admite otro usuario.');
            $members = $participantIds->push($request->user()->id)->sort()->values();
            $conversation = TeamConversation::query()->firstOrCreate(
                ['direct_key' => $members->implode(':')],
                ['type' => 'direct', 'created_by' => $request->user()->id],
            );
        } else {
            $name = trim((string) ($data['name'] ?? ''));
            abort_if($name === '', 422, 'Escribe un nombre para el grupo.');
            $members = $participantIds->push($request->user()->id)->unique()->values();
            $conversation = TeamConversation::create([
                'type' => 'group', 'name' => $name, 'created_by' => $request->user()->id,
            ]);
        }

        $conversation->participants()->syncWithoutDetaching($members->all());
        $conversation->load(['participants:id,name', 'latestMessage.sender:id,name']);

        return response()->json(['conversation' => $this->conversationData($conversation, $request->user())], 201);
    }

    public function messages(Request $request, TeamConversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($conversation, $request->user());
        $messages = $conversation->messages()->with('sender:id,name')->latest('id')->limit(100)->get()->reverse()->values();

        return response()->json([
            'conversation' => $this->conversationData($conversation->load('participants:id,name'), $request->user()),
            'messages' => $messages->map(fn (TeamMessage $message) => $this->messageData($message, $request->user())),
            'unread' => $this->unread($conversation, $request->user()),
        ]);
    }

    public function storeMessage(Request $request, TeamConversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($conversation, $request->user());
        $data = $request->validate(['body' => ['required', 'string', 'max:2000', 'not_regex:/[\x00]/']]);
        $body = trim($data['body']);
        abort_if($body === '', 422, 'Escribe un mensaje.');

        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'body' => $body,
        ])->load('sender:id,name');
        $conversation->touch();

        return response()->json(['message' => $this->messageData($message, $request->user())], 201);
    }

    public function read(Request $request, TeamConversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($conversation, $request->user());
        $conversation->participants()->updateExistingPivot($request->user()->id, [
            'last_read_at' => now(),
            'last_read_message_id' => $conversation->messages()->max('id'),
        ]);

        return response()->json(['ok' => true, 'unread' => 0]);
    }

    private function authorizeParticipant(TeamConversation $conversation, User $user): void
    {
        abort_unless($conversation->participants()->whereKey($user->id)->exists(), 404);
    }

    private function conversationData(TeamConversation $conversation, User $user): array
    {
        $participants = $conversation->participants;
        $other = $participants->firstWhere('id', '!=', $user->id);
        $latest = $conversation->latestMessage;

        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'name' => $conversation->type === 'direct' ? ($other?->name ?? 'Usuario eliminado') : $conversation->name,
            'participants' => $participants->pluck('name')->values(),
            'unread' => $this->unread($conversation, $user),
            'latest_message' => $latest?->body,
            'latest_sender' => $latest?->sender?->name,
            'updated_at' => ($latest?->created_at ?? $conversation->updated_at)?->toIso8601String(),
        ];
    }

    private function messageData(TeamMessage $message, User $user): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'mine' => $message->sender_id === $user->id,
            'sender' => $message->sender?->name ?? 'Usuario eliminado',
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function unread(TeamConversation $conversation, User $user): int
    {
        $membership = $conversation->participants()->whereKey($user->id)->first()?->pivot;
        $lastReadId = $membership?->last_read_message_id;

        return $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->when($lastReadId, fn ($query) => $query->where('id', '>', $lastReadId))
            ->count();
    }
}
