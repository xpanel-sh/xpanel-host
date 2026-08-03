<?php

namespace Tests\Feature;

use App\Models\Role;
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

        $this->actingAs($alice)->postJson(route('team-chat.store'), ['body' => 'Hola equipo'])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Hola equipo');

        $this->assertDatabaseHas('team_messages', ['sender_id' => $alice->id, 'body' => 'Hola equipo']);
        $this->actingAs($bob)->getJson(route('team-chat.index'))
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('messages.0.sender', 'Alice');

        $this->actingAs($bob)->postJson(route('team-chat.read'))->assertOk()->assertJsonPath('unread', 0);
        $this->actingAs($bob)->getJson(route('team-chat.index'))->assertJsonPath('unread', 0);
    }

    public function test_chat_rejects_empty_and_oversized_messages(): void
    {
        $user = $this->user('Alice');

        $this->actingAs($user)->postJson(route('team-chat.store'), ['body' => '   '])->assertUnprocessable();
        $this->actingAs($user)->postJson(route('team-chat.store'), ['body' => str_repeat('a', 2001)])->assertUnprocessable();
        $this->assertSame(0, TeamMessage::count());
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
            ->assertSee('team-chat\\/messages', false)
            ->assertSee('notifications', false)
            ->assertDontSee('Builder')
            ->assertDontSee('Sin mensajes por ahora.')
            ->assertDontSee('Sin notificaciones por ahora.');

        $this->assertFalse(view()->exists('builder.index'));
        $this->assertFalse(view()->exists('sites.website.builder'));
    }
}
