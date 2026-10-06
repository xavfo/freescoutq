<?php

namespace Modules\Kanban\Tests\Feature;

use App\Conversation;
use Modules\Kanban\Support\Stages;
use Modules\Kanban\Tests\KanbanTestCase;

/**
 * Rutas del módulo: tablero, refresco AJAX, mover tarjetas y editor de etapas.
 *
 * Se prueba de verdad el HTTP (middleware `auth`, CSRF desactivado en las
 * pruebas, permisos) y no sólo el controlador.
 */
class KanbanApiTest extends KanbanTestCase
{
    public function test_the_board_is_only_for_authenticated_users()
    {
        $this->get('/kanban')->assertStatus(302);
        $this->post('/kanban/board')->assertStatus(302);
        $this->post('/kanban/move')->assertStatus(302);
        $this->get('/kanban/settings')->assertStatus(302);
    }

    public function test_the_board_is_rendered_server_side()
    {
        $this->makeConversation(['type' => Conversation::TYPE_WHATSAPP]);

        $response = $this->actingAs($this->admin)->get('/kanban');

        $response->assertStatus(200);

        $html = $response->getContent();

        // El marcado lo pinta el servidor: la vista trae las columnas, la tarjeta
        // con su medio y los datos que el JavaScript necesita para hablar con la API.
        foreach (
            [
                'id="kanban"',
                'data-board-url',
                'data-move-url',
                'data-conversations-url',
                'data-user-id',
                'kb-board',
                'kb-card',
                'data-medium="whatsapp"',
                'kb-legend',
                '/modules/kanban/css/kanban.css',
                '/modules/kanban/js/kanban.js',
            ] as $needle
        ) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function test_the_board_lists_every_stage_as_a_column()
    {
        $html = $this->actingAs($this->admin)->get('/kanban')->getContent();

        foreach (['new', 'in_progress', 'waiting', 'resolved', Stages::NONE] as $stage) {
            $this->assertStringContainsString('data-stage="' . $stage . '"', $html);
        }
    }

    public function test_the_board_is_forbidden_for_a_user_without_mailboxes()
    {
        $this->actingAs($this->makeUser())->get('/kanban')->assertStatus(403);
        $this->actingAs($this->makeUser())->postJson('/kanban/board')->assertStatus(403);
    }

    public function test_the_board_endpoint_returns_the_columns_with_rendered_html()
    {
        $this->makeConversation();

        $response = $this->actingAs($this->admin)->postJson('/kanban/board', [
            'mailbox_id' => $this->mailbox->id,
        ]);

        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertCount(5, $json['columns']);
        $this->assertSame(1, $json['total']);

        $none = $json['columns'][0];

        $this->assertSame(Stages::NONE, $none['id']);
        $this->assertSame(1, $none['count']);
        $this->assertArrayHasKey('html', $none);
        $this->assertStringContainsString('kb-card', $none['html']);
        // El HTML va renderizado: no se expone el array de DTOs.
        $this->assertArrayNotHasKey('cards', $none);
    }

    public function test_the_board_endpoint_can_return_a_single_column()
    {
        $this->makeConversation(['kanban_stage' => 'waiting']);

        $response = $this->actingAs($this->admin)->postJson('/kanban/board', [
            'mailbox_id' => $this->mailbox->id,
            'stage'     => 'waiting',
        ]);

        $response->assertStatus(200);

        $columns = $response->json()['columns'];


        $this->assertCount(1, $columns);
        $this->assertSame('waiting', $columns[0]['id']);
    }

    public function test_a_conversation_can_be_moved_to_another_stage()
    {
        $conversation = $this->makeConversation();

        $response = $this->actingAs($this->admin)->postJson('/kanban/move', [
            'conversation_id' => $conversation->id,
            'stage'           => 'in_progress',
            'mailbox_id'      => $this->mailbox->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['msg', 'counts']);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame($conversation->id, $json['conversation_id']);
        $this->assertSame('in_progress', $json['stage']);
        $this->assertFalse($json['status_changed']);
        $this->assertNotEmpty($json['msg']);

        $fresh = Conversation::find($conversation->id);

        $this->assertSame('in_progress', $fresh->kanban_stage);
        $this->assertNotNull($fresh->getMeta('kanban_stage_at'));
        $this->assertSame($this->admin->id, (int) $fresh->getMeta('kanban_stage_by'));
        // El mensaje lleva el nombre legible de la etapa, no el id.
        $this->assertStringContainsString('En curso', $json['msg']);
    }

    public function test_moving_to_a_stage_with_a_status_changes_the_conversation_status()
    {
        $conversation = $this->makeConversation();

        // La etapa "resolved" de la configuración por defecto cierra la conversación.
        $response = $this->actingAs($this->admin)->postJson('/kanban/move', [
            'conversation_id' => $conversation->id,
            'stage'           => 'resolved',
        ]);

        $response->assertStatus(200);

        $fresh = Conversation::find($conversation->id);

        $this->assertTrue($response->json()['status_changed']);
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $fresh->status);
        $this->assertSame('resolved', $fresh->kanban_stage);
    }

    public function test_moving_to_the_none_stage_clears_it()
    {
        $conversation = $this->makeConversation(['kanban_stage' => 'new']);

        $response = $this->actingAs($this->admin)->postJson('/kanban/move', [
            'conversation_id' => $conversation->id,
            'stage'           => Stages::NONE,
        ]);

        $response->assertStatus(200);

        $this->assertSame(Stages::NONE, $response->json()['stage']);
        $this->assertNull(Conversation::find($conversation->id)->kanban_stage);
    }

    public function test_a_stage_of_another_mailbox_is_rejected()
    {
        $other = $this->makeMailbox('Buzón ajeno');
        Stages::saveForMailbox($other, [
            ['id' => 'solo_ajena', 'name' => 'Ajena', 'color' => '#3498db', 'wip' => 0, 'status' => null],
        ]);

        $conversation = $this->makeConversation();

        $response = $this->actingAs($this->admin)->postJson('/kanban/move', [
            'conversation_id' => $conversation->id,
            'stage'           => 'solo_ajena',
        ]);

        $response->assertStatus(422);
        $this->assertNull(Conversation::find($conversation->id)->kanban_stage);
    }

    public function test_moving_an_unknown_conversation_returns_404()
    {
        $this->actingAs($this->admin)->postJson('/kanban/move', [
            'conversation_id' => 999999999,
            'stage'           => 'new',
        ])->assertStatus(404);
    }

    public function test_a_user_without_access_cannot_move_a_conversation()
    {
        $conversation = $this->makeConversation();

        $this->actingAs($this->makeUser())->postJson('/kanban/move', [
            'conversation_id' => $conversation->id,
            'stage'           => 'new',
        ])->assertStatus(403);

        $this->assertNull(Conversation::find($conversation->id)->kanban_stage);
    }

    public function test_move_validates_the_payload()
    {
        $this->actingAs($this->admin)->postJson('/kanban/move', [])->assertStatus(422);

        $this->actingAs($this->admin)->postJson('/kanban/move', ['conversation_id' => 1])
            ->assertStatus(422);
    }

    public function test_the_settings_page_requires_the_right_permission()
    {
        $user = $this->makeUser();
        $this->grantAccess($user, $this->mailbox);

        $this->actingAs($user)->get('/kanban/settings?mailbox_id=' . $this->mailbox->id)->assertStatus(403);
        $this->actingAs($user)->post('/kanban/settings', ['mailbox_id' => $this->mailbox->id])->assertStatus(403);

        // Y un administrador sí entra.
        $this->actingAs($this->admin)->get('/kanban/settings?mailbox_id=' . $this->mailbox->id)->assertStatus(200);
    }

    public function test_the_settings_page_lists_the_stages_of_the_mailbox()
    {
        $html = $this->actingAs($this->admin)
            ->get('/kanban/settings?mailbox_id=' . $this->mailbox->id)
            ->assertStatus(200)
            ->getContent();

        foreach (
            [
                'kb-stage-row',
                'name="stages[0][name]"',
                'name="stages[0][wip]"',
                'name="stages[0][status]"',
                'name="stages[0][color]"',
                '__INDEX__',
            ] as $needle
        ) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function test_the_settings_page_is_not_available_for_another_mailbox()
    {
        $other = $this->makeMailbox('Buzón ajeno');

        // El buzón propio, visible pero sin permiso de configuración: 403.
        $user = $this->makeUser();
        $this->grantAccess($user, $this->mailbox);

        $this->actingAs($user)->get('/kanban/settings?mailbox_id=' . $this->mailbox->id)->assertStatus(403);

        // Un buzón que no ve ni siquiera existe para él: 404 (no se filtra que exista).
        $this->actingAs($user)->get('/kanban/settings?mailbox_id=' . $other->id)->assertStatus(404);

        // Y un administrador entra en los dos.
        $this->actingAs($this->admin)->get('/kanban/settings?mailbox_id=' . $other->id)->assertStatus(200);
    }

    public function test_saving_the_settings_stores_the_stages_in_the_mailbox()
    {
        $response = $this->actingAs($this->admin)->post('/kanban/settings', [
            'mailbox_id' => $this->mailbox->id,
            'stages'     => [
                ['id' => 'nuevo', 'name' => 'Nuevo', 'color' => '#3498db', 'wip' => 0, 'status' => ''],
                ['id' => 'vip', 'name' => 'VIP', 'color' => '#e91e63', 'wip' => 2, 'status' => ''],
                ['id' => 'hecho', 'name' => 'Hecho', 'color' => '#27ae60', 'wip' => 0, 'status' => 3],
            ],
        ]);

        $response->assertStatus(302);

        $saved = Stages::forMailbox($this->mailbox->fresh());

        $this->assertSame(['nuevo', 'vip', 'hecho'], array_column($saved, 'id'));
        $this->assertSame(2, $saved[1]['wip']);
        $this->assertSame(3, $saved[2]['status']);
        $this->assertSame('#E91E63', $saved[1]['color']);
    }

    public function test_the_settings_can_be_reset_to_the_defaults()
    {
        Stages::saveForMailbox($this->mailbox, [
            ['id' => 'uno', 'name' => 'Uno', 'color' => '#3498db', 'wip' => 0, 'status' => null],
        ]);

        $this->actingAs($this->admin)->post('/kanban/settings', [
            'mailbox_id' => $this->mailbox->id,
            'reset'      => 1,
        ])->assertStatus(302);

        $this->assertSame(
            ['new', 'in_progress', 'waiting', 'resolved'],
            array_column(Stages::forMailbox($this->mailbox->fresh()), 'id')
        );
    }

    public function test_the_settings_reject_a_payload_without_stages()
    {
        $this->actingAs($this->admin)
            ->post('/kanban/settings', ['mailbox_id' => $this->mailbox->id, 'stages' => []])
            ->assertSessionHasErrors('stages');
    }

    public function test_the_settings_reject_a_stage_without_a_name()
    {
        $this->actingAs($this->admin)->post('/kanban/settings', [
            'mailbox_id' => $this->mailbox->id,
            'stages'     => [['id' => 'x', 'name' => '', 'color' => '#3498db', 'wip' => 0, 'status' => '']],
        ])->assertSessionHasErrors('stages.0.name');
    }
}
