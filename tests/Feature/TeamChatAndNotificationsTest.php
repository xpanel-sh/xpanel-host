<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TeamConversation;
use App\Models\TeamMessage;
use App\Models\User;
use App\Notifications\PanelActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamChatAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role_id' => Role::where('slug', 'developer')->firstOrFail()->id,
        ]);
    }

    public function test_authenticated_team_members_can_exchange_messages_and_track_unread_state(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $conversation = TeamConversation::where('is_default', true)->firstOrFail();

        $this->actingAs($alice)->postJson(route('team-chat.messages.store', $conversation), ['body' => 'Hola equipo'])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Hola equipo');

        $this->assertDatabaseHas('team_messages', ['team_conversation_id' => $conversation->id, 'sender_id' => $alice->id, 'body' => 'Hola equipo']);
        $this->actingAs($bob)->getJson(route('team-chat.messages', $conversation))
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('messages.0.sender', 'Alice');

        $this->actingAs($bob)->postJson(route('team-chat.read', $conversation))->assertOk()->assertJsonPath('unread', 0);
        $this->actingAs($bob)->getJson(route('team-chat.messages', $conversation))->assertJsonPath('unread', 0);
    }

    public function test_chat_rejects_empty_and_oversized_messages(): void
    {
        $user = $this->user('Alice');
        $conversation = TeamConversation::where('is_default', true)->firstOrFail();

        $this->actingAs($user)->postJson(route('team-chat.messages.store', $conversation), ['body' => '   '])->assertUnprocessable();
        $this->actingAs($user)->postJson(route('team-chat.messages.store', $conversation), ['body' => str_repeat('a', 2001)])->assertUnprocessable();
        $this->assertSame(0, TeamMessage::count());
    }

    public function test_users_can_create_direct_and_group_conversations(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $charlie = $this->user('Charlie');

        $direct = $this->actingAs($alice)->postJson(route('team-chat.conversations.store'), [
            'type' => 'direct', 'participant_ids' => [$bob->id],
        ])->assertCreated()->assertJsonPath('conversation.name', 'Bob')->json('conversation.id');

        $this->actingAs($alice)->postJson(route('team-chat.messages.store', $direct), ['body' => 'Privado'])->assertCreated();
        $this->actingAs($bob)->getJson(route('team-chat.messages', $direct))->assertOk()->assertJsonPath('messages.0.body', 'Privado');
        $this->actingAs($charlie)->getJson(route('team-chat.messages', $direct))->assertNotFound();

        $group = $this->actingAs($alice)->postJson(route('team-chat.conversations.store'), [
            'type' => 'group', 'name' => 'Operaciones', 'participant_ids' => [$bob->id, $charlie->id],
        ])->assertCreated()->assertJsonPath('conversation.name', 'Operaciones')->json('conversation.id');

        $this->actingAs($charlie)->getJson(route('team-chat.messages', $group))->assertOk();
        $this->assertDatabaseHas('team_conversation_user', ['team_conversation_id' => $group, 'user_id' => $bob->id]);
    }

    public function test_direct_conversations_are_reused_and_validate_participants(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $charlie = $this->user('Charlie');
        $payload = ['type' => 'direct', 'participant_ids' => [$bob->id]];

        $first = $this->actingAs($alice)->postJson(route('team-chat.conversations.store'), $payload)->assertCreated()->json('conversation.id');
        $second = $this->actingAs($alice)->postJson(route('team-chat.conversations.store'), $payload)->assertCreated()->json('conversation.id');
        $this->assertSame($first, $second);
        $this->actingAs($alice)->postJson(route('team-chat.conversations.store'), [
            'type' => 'direct', 'participant_ids' => [$bob->id, $charlie->id],
        ])->assertUnprocessable();
        $this->actingAs($alice)->postJson(route('team-chat.conversations.store'), [
            'type' => 'group', 'name' => '', 'participant_ids' => [$bob->id],
        ])->assertUnprocessable();
    }

    public function test_notifications_can_be_listed_and_marked_as_read(): void
    {
        $user = $this->user('Alice');
        $user->notify(new PanelActivityNotification('Backup terminado', 'La copia está disponible.', '/sites', 'success', 'ki-check-circle'));
        $notification = $user->notifications()->firstOrFail();

        $this->actingAs($user)->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('notifications.0.title', 'Backup terminado');

        $this->actingAs($user)->postJson(route('notifications.read', $notification->id))->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_users_cannot_mark_another_users_notification_as_read(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $alice->notify(new PanelActivityNotification('Privada', 'Solo Alice debe verla.'));
        $notification = $alice->notifications()->firstOrFail();

        $this->actingAs($bob)->postJson(route('notifications.read', $notification->id))->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_important_team_actions_create_notifications(): void
    {
        $owner = User::factory()->create([
            'name' => 'Owner',
            'role_id' => Role::where('slug', 'owner')->firstOrFail()->id,
        ]);
        $developer = Role::where('slug', 'developer')->firstOrFail();

        $this->actingAs($owner)->post(route('team.store'), [
            'name' => 'Nuevo miembro',
            'email' => 'nuevo@example.com',
            'password' => 'supersecreta',
            'role_id' => $developer->id,
        ])->assertRedirect(route('team.index'));

        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $owner->id]);
        $this->assertSame('Añadió un miembro al equipo.', $owner->notifications()->firstOrFail()->data['title']);
    }

    public function test_header_contains_functional_chat_and_notification_controls_without_builder(): void
    {
        $user = $this->user('Alice');

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Chat del equipo')
            ->assertSee('team-chat\\/conversations', false)
            ->assertSee('notifications', false)
            ->assertDontSee('Builder')
            ->assertDontSee('Sin mensajes por ahora.')
            ->assertDontSee('Sin notificaciones por ahora.');

        $this->assertFalse(view()->exists('builder.index'));
        $this->assertFalse(view()->exists('sites.website.builder'));
    }
}
