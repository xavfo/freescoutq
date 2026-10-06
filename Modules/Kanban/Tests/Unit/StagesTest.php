<?php

namespace Modules\Kanban\Tests\Unit;

use App\Conversation;
use App\Mailbox;
use Modules\Kanban\Support\Stages;
use Tests\TestCase;

/**
 * Fases del tablero: valores por defecto, columna virtual y, sobre todo, la
 * normalización de lo que llega del editor (ahí es donde se pueden colar datos
 * raros que rompan el tablero).
 */
class StagesTest extends TestCase
{
    public function test_defaults_defines_the_four_expected_stages()
    {
        $this->assertSame(
            ['new', 'in_progress', 'waiting', 'resolved'],
            array_column(Stages::defaults(), 'id')
        );
    }

    public function test_every_default_stage_has_a_valid_shape()
    {
        foreach (Stages::defaults() as $stage) {
            $this->assertArrayHasKey('id', $stage);
            $this->assertArrayHasKey('name', $stage);
            $this->assertArrayHasKey('color', $stage);
            $this->assertArrayHasKey('wip', $stage);
            $this->assertArrayHasKey('status', $stage);
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $stage['color']);
            $this->assertIsInt($stage['wip']);
        }
    }

    public function test_none_column_is_reserved_and_complete()
    {
        $none = Stages::none();

        $this->assertSame('__none', Stages::NONE);
        $this->assertSame(Stages::NONE, $none['id']);
        $this->assertNotEmpty($none['name']);
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $none['color']);
    }

    public function test_status_options_offer_the_core_statuses_plus_no_change()
    {
        $options = Stages::statusOptions();

        $this->assertArrayHasKey('', $options);

        foreach (array_keys(Conversation::$statuses) as $code) {
            $this->assertArrayHasKey($code, $options);
            $this->assertSame(Conversation::statusCodeToName($code), $options[$code]);
        }
    }

    public function test_for_mailbox_falls_back_to_defaults()
    {
        $mailbox = new Mailbox();

        $this->assertSame(Stages::defaults(), Stages::forMailbox($mailbox));
    }

    public function test_normalize_drops_entries_without_a_name()
    {
        $stages = Stages::normalize([
            ['id' => 'sin_nombre', 'name' => '   ', 'color' => '#3498db'],
            ['id' => 'ok', 'name' => 'Válida', 'color' => '#3498db'],
            'no soy un array',
        ]);

        $this->assertSame(['ok'], array_column($stages, 'id'));
    }

    public function test_normalize_keeps_only_the_first_of_duplicated_ids()
    {
        $stages = Stages::normalize([
            ['id' => 'dup', 'name' => 'Primera', 'color' => '#3498db'],
            ['id' => 'dup', 'name' => 'Segunda', 'color' => '#27ae60'],
        ]);

        $this->assertCount(1, $stages);
        $this->assertSame('Primera', $stages[0]['name']);
    }

    public function test_normalize_never_returns_the_reserved_id()
    {
        $stages = Stages::normalize([
            ['id' => '__none', 'name' => 'Reservada', 'color' => '#3498db'],
        ]);

        $this->assertCount(1, $stages);
        $this->assertNotSame(Stages::NONE, $stages[0]['id']);
        $this->assertSame('reservada', $stages[0]['id']);
    }

    public function test_normalize_builds_an_id_from_the_name_when_missing()
    {
        $stages = Stages::normalize([
            ['name' => 'En Espera Técnica', 'color' => '#3498db'],
        ]);

        $this->assertSame('en_espera_tecnica', $stages[0]['id']);
    }

    public function test_normalize_replaces_a_color_outside_the_palette()
    {
        $stages = Stages::normalize([
            ['id' => 'x', 'name' => 'X', 'color' => 'rojo'],
            ['id' => 'y', 'name' => 'Y', 'color' => '#12345'],
        ]);

        foreach ($stages as $stage) {
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $stage['color']);
            $this->assertContains($stage['color'], array_map('strtoupper', config('kanban.palette')));
        }
    }

    /**
     * Regresión: los colores se guardan en mayúsculas y la paleta está en
     * minúsculas. Comparar sin normalizar hacía que elegir un color de la paleta
     * se detectara como "no permitido" y se sustituyera por otro.
     */
    public function test_normalize_keeps_palette_colors_ignoring_case()
    {
        $palette = config('kanban.palette');

        $stages = Stages::normalize([
            ['id' => 'a', 'name' => 'A', 'color' => strtolower($palette[0])],
            ['id' => 'b', 'name' => 'B', 'color' => strtoupper($palette[1])],
            ['id' => 'c', 'name' => 'C', 'color' => $palette[2]],
        ]);

        $this->assertSame(strtoupper($palette[0]), $stages[0]['color']);
        $this->assertSame(strtoupper($palette[1]), $stages[1]['color']);
        $this->assertSame(strtoupper($palette[2]), $stages[2]['color']);
    }

    public function test_normalize_clamps_wip_and_drops_unknown_status()
    {
        $stages = Stages::normalize([
            ['id' => 'x', 'name' => 'X', 'color' => '#3498db', 'wip' => -5, 'status' => 99],
            ['id' => 'y', 'name' => 'Y', 'color' => '#3498db', 'wip' => '3', 'status' => 3],
        ]);

        $this->assertSame(0, $stages[0]['wip']);
        $this->assertNull($stages[0]['status']);
        $this->assertSame(3, $stages[1]['wip']);
        $this->assertSame(3, $stages[1]['status']);
    }

    public function test_normalize_limits_the_name_length()
    {
        $stages = Stages::normalize([
            ['id' => 'x', 'name' => str_repeat('a', 200), 'color' => '#3498db'],
        ]);

        $this->assertSame(60, mb_strlen($stages[0]['name']));
    }

    public function test_tint_lightens_a_color()
    {
        $this->assertSame('#808080', Stages::tint('#000000', 0.5));
        $this->assertSame('#FFFFFF', Stages::tint('#FFFFFF'));
        $this->assertNotSame('#3498DB', Stages::tint('#3498DB'));
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', Stages::tint('#3498DB'));
    }

    public function test_tint_survives_invalid_input()
    {
        $this->assertSame('#F4F6F8', Stages::tint('nope'));
        $this->assertSame('#F4F6F8', Stages::tint(''));
    }

    public function test_find_returns_the_stage_or_null()
    {
        $mailbox = new Mailbox();

        $this->assertSame('new', Stages::find($mailbox, 'new')['id']);
        $this->assertNull(Stages::find($mailbox, 'no_existe'));
    }

    public function test_union_merges_stages_of_several_mailboxes_without_duplicates()
    {
        $union = Stages::union(collect([new Mailbox(), new Mailbox()]));

        $this->assertSame(['new', 'in_progress', 'waiting', 'resolved'], array_column($union, 'id'));
    }

    public function test_of_conversation_defaults_to_none()
    {
        $conversation = new Conversation();

        $this->assertSame(Stages::NONE, Stages::ofConversation($conversation));

        $conversation->kanban_stage = 'waiting';

        $this->assertSame('waiting', Stages::ofConversation($conversation));
    }
}
