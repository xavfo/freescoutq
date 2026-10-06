<?php

namespace Modules\Kanban\Tests\Feature;

use App\Conversation;
use Modules\Kanban\Entities\Board;
use Modules\Kanban\Support\Medium;
use Modules\Kanban\Support\Stages;
use Modules\Kanban\Tests\KanbanTestCase;

/**
 * Consultas del tablero: qué entra en cada columna, contadores, filtros,
 * paginación por columna y aislamiento por buzón.
 *
 * Todas las aserciones van acotadas al buzón de la prueba (`mailbox_id`), así
 * que no dependen de las conversaciones que ya haya en la base de datos.
 */
class BoardTest extends KanbanTestCase
{
    protected function board(array $filters = [])
    {
        return new Board($this->admin, array_merge(['mailbox_id' => $this->mailbox->id], $filters));
    }

    /**
     * @return array|null
     */
    protected function column(Board $board, $id)
    {
        foreach ($board->columns() as $column) {
            if ($column['id'] === $id) {
                return $column;
            }
        }

        return null;
    }

    protected function total(Board $board)
    {
        return array_sum($board->counts());
    }

    public function test_a_user_without_mailboxes_gets_no_board()
    {
        $board = new Board($this->makeUser(), []);

        $this->assertFalse($board->hasMailboxes());
        $this->assertSame([], $board->columns());
    }

    public function test_the_board_has_one_column_per_stage_plus_the_none_column()
    {
        $columns = $this->board()->columns();

        $this->assertCount(5, $columns);
        $this->assertSame(Stages::NONE, $columns[0]['id']);
        $this->assertSame(
            ['new', 'in_progress', 'waiting', 'resolved'],
            array_slice(array_column($columns, 'id'), 1)
        );
    }

    public function test_every_column_carries_the_data_the_view_needs()
    {
        foreach ($this->board()->columns() as $column) {
            foreach (['id', 'name', 'color', 'color_soft', 'wip', 'count', 'has_more', 'offset', 'cards'] as $key) {
                $this->assertArrayHasKey($key, $column);
            }

            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $column['color']);
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $column['color_soft']);
            $this->assertIsInt($column['count']);
            $this->assertIsInt($column['offset']);
        }
    }

    public function test_new_conversations_land_in_the_none_column()
    {
        $this->makeConversation();
        $this->makeConversation();

        $column = $this->column($this->board(), Stages::NONE);

        $this->assertSame(2, $column['count']);
        $this->assertCount(2, $column['cards']);
    }

    public function test_a_conversation_with_a_stage_goes_to_that_column()
    {
        $staged = $this->makeConversation(['kanban_stage' => 'waiting']);
        $this->makeConversation();

        $board = $this->board();

        $this->assertSame(1, $this->column($board, 'waiting')['count']);
        $this->assertSame(1, $this->column($board, Stages::NONE)['count']);
        $this->assertSame($staged->id, $this->column($board, 'waiting')['cards'][0]->id);
    }

    public function test_a_conversation_whose_stage_no_longer_exists_falls_back_to_none()
    {
        $this->makeConversation(['kanban_stage' => 'etapa_borrada']);

        $board = $this->board();

        $this->assertSame(1, $this->column($board, Stages::NONE)['count']);
        $this->assertSame(0, array_sum(array_column(array_slice($board->columns(), 1), 'count')));
    }

    public function test_only_open_and_published_conversations_are_shown()
    {
        $this->makeConversation();
        $this->makeConversation(['status' => Conversation::STATUS_CLOSED]);
        $this->makeConversation(['status' => Conversation::STATUS_SPAM]);
        $this->makeConversation(['state' => Conversation::STATE_DRAFT]);

        $this->assertSame(1, $this->total($this->board()));
    }

    public function test_pending_conversations_are_shown_too()
    {
        $this->makeConversation(['status' => Conversation::STATUS_PENDING]);

        $this->assertSame(1, $this->total($this->board()));
    }

    public function test_cards_expose_the_medium_and_its_color()
    {
        $this->makeConversation(['type' => Conversation::TYPE_WHATSAPP]);

        $card = $this->column($this->board(), Stages::NONE)['cards'][0];

        $this->assertSame(Medium::WHATSAPP, $card->medium);
        $this->assertSame(Medium::color(Medium::WHATSAPP), $card->medium_color);
        $this->assertSame('WhatsApp', $card->medium_label);
    }

    public function test_cards_expose_subject_customer_assignee_and_url()
    {
        $this->makeConversation([
            'subject'  => 'Se me ha caído la web',
            'user_id'  => $this->admin->id,
            'threads_count' => 4,
        ]);

        $card = $this->column($this->board(), Stages::NONE)['cards'][0];

        $this->assertSame('Se me ha caído la web', $card->subject);
        $this->assertSame('Cliente Kanban', $card->customer_name);
        $this->assertSame('Prueba Kanban', $card->assignee_name);
        $this->assertSame('PK', $card->assignee_initials);
        $this->assertSame(4, $card->threads_count);
        $this->assertStringContainsString('conversation', $card->url);
    }

    public function test_stale_only_when_the_last_word_was_the_customers()
    {
        $this->makeConversation([
            'subject'         => 'Sin contestar',
            'last_reply_at'   => now()->subDays(10),
            'last_reply_from' => Conversation::PERSON_CUSTOMER,
        ]);
        $this->makeConversation([
            'subject'         => 'Contestada',
            'last_reply_at'   => now()->subDays(10),
            'last_reply_from' => Conversation::PERSON_USER,
        ]);

        $cards = $this->column($this->board(), Stages::NONE)['cards'];
        $bySubject = [];

        foreach ($cards as $card) {
            $bySubject[$card->subject] = $card;
        }

        $this->assertTrue($bySubject['Sin contestar']->stale);
        $this->assertFalse($bySubject['Contestada']->stale);
        $this->assertSame(10, $bySubject['Sin contestar']->age_days);
    }

    public function test_filter_by_assignee()
    {
        $this->makeConversation(['user_id' => $this->admin->id]);
        $this->makeConversation();

        $this->assertSame(1, $this->total($this->board(['assignee' => 'me'])));
        $this->assertSame(1, $this->total($this->board(['assignee' => 'none'])));
        $this->assertSame(1, $this->total($this->board(['assignee' => $this->admin->id])));
        $this->assertSame(2, $this->total($this->board()));
    }

    public function test_filter_by_search_over_subject_and_customer()
    {
        $this->makeConversation(['subject' => 'Falta la factura de marzo']);
        $this->makeConversation(['subject' => 'Otra cosa distinta']);

        $this->assertSame(1, $this->total($this->board(['search' => 'factura'])));
        $this->assertSame(2, $this->total($this->board(['search' => 'Cliente Kanban'])));
        $this->assertSame(0, $this->total($this->board(['search' => 'no existe nada así'])));
    }

    public function test_filter_by_search_over_the_customer_email()
    {
        $this->makeConversation();
        $email = $this->customer->getMainEmail();

        $this->assertSame(1, $this->total($this->board(['search' => substr($email, 0, 12)])));
    }

    public function test_filter_waiting_on_us_ignores_conversations_we_answered_last()
    {
        $this->makeConversation([
            'last_reply_at'   => now()->subDays(5),
            'last_reply_from' => Conversation::PERSON_CUSTOMER,
        ]);
        $this->makeConversation([
            'last_reply_at'   => now()->subDays(5),
            'last_reply_from' => Conversation::PERSON_USER,
        ]);
        $this->makeConversation([
            'last_reply_at'   => now()->subHour(),
            'last_reply_from' => Conversation::PERSON_CUSTOMER,
        ]);

        $this->assertSame(1, $this->total($this->board(['stale_days' => 3])));
        $this->assertSame(3, $this->total($this->board()));
    }

    public function test_cards_per_column_and_load_more()
    {
        for ($i = 0; $i < 3; $i++) {
            $this->makeConversation(['subject' => 'Conversación ' . $i]);
        }

        config(['kanban.per_column' => 2]);

        $column = $this->column($this->board(), Stages::NONE);

        $this->assertCount(2, $column['cards']);
        $this->assertSame(3, $column['count']);
        $this->assertSame(2, $column['offset']);
        $this->assertTrue($column['has_more']);

        $second = $this->column($this->board(['stage' => Stages::NONE, 'offset' => 2]), Stages::NONE);

        $this->assertCount(1, $second['cards']);
        $this->assertFalse($second['has_more']);
        $this->assertSame(3, $second['offset']);
    }

    public function test_asking_for_a_single_stage_returns_only_that_column()
    {
        $this->makeConversation(['kanban_stage' => 'waiting']);

        $columns = $this->board(['stage' => 'waiting'])->columns();

        $this->assertCount(1, $columns);
        $this->assertSame('waiting', $columns[0]['id']);

        $columns = $this->board(['stage' => Stages::NONE])->columns();

        $this->assertCount(1, $columns);
        $this->assertSame(Stages::NONE, $columns[0]['id']);
    }

    public function test_the_wip_limit_is_reported_but_does_not_block()
    {
        Stages::saveForMailbox($this->mailbox, [
            ['id' => 'limitada', 'name' => 'Limitada', 'color' => '#3498db', 'wip' => 2, 'status' => null],
        ]);

        $this->makeConversation(['kanban_stage' => 'limitada']);
        $this->makeConversation(['kanban_stage' => 'limitada']);
        $this->makeConversation(['kanban_stage' => 'limitada']);

        $column = $this->column($this->board(), 'limitada');

        $this->assertSame(2, $column['wip']);
        $this->assertSame(3, $column['count']);
        $this->assertCount(3, $column['cards']);
        $this->assertFalse($column['has_more']);
    }

    public function test_the_board_uses_the_stages_configured_for_the_mailbox()
    {
        Stages::saveForMailbox($this->mailbox, [
            ['id' => 'entrada', 'name' => 'Entrada', 'color' => '#3498db', 'wip' => 0, 'status' => null],
            ['id' => 'cerrado', 'name' => 'Cerrado', 'color' => '#27ae60', 'wip' => 0, 'status' => 3],
        ]);

        $board = $this->board();

        $this->assertSame(['entrada', 'cerrado'], array_column($board->stages(), 'id'));
        $this->assertCount(3, $board->columns());
    }

    public function test_a_user_only_sees_the_mailboxes_he_can_access()
    {
        $other = $this->makeMailbox('Otro buzón');
        $other_folder = $this->makeFolder($other);

        $this->makeConversation(['mailbox' => $other, 'folder' => $other_folder]);
        $this->makeConversation();

        $user = $this->makeUser();
        $this->grantAccess($user, $this->mailbox);

        $board = new Board($user, []);

        $this->assertSame([$this->mailbox->id], $board->mailboxIds());
        $this->assertSame(1, array_sum($board->counts()));

        // Y no puede colarse pidiendo un buzón que no es suyo.
        $this->assertFalse((new Board($user, ['mailbox_id' => $other->id]))->hasMailboxes());
    }
}
