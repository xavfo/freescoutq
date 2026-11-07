<?php

namespace Modules\RestApi\Tests\Unit;

use App\Mailbox;
use Modules\RestApi\Support\Kanban;
use Tests\TestCase;

class KanbanTest extends TestCase
{
    public function test_stage_validation_accepts_none_and_default_stages()
    {
        if (!Kanban::isAvailable()) {
            $this->markTestSkipped('Kanban module is not active in this test database.');
        }

        $mailbox = new Mailbox();

        $this->assertTrue(Kanban::validate($mailbox, Kanban::NONE)['ok']);
        $this->assertNull(Kanban::validate($mailbox, Kanban::NONE)['stage']);
        $this->assertTrue(Kanban::validate($mailbox, 'new')['ok']);
        $this->assertSame('new', Kanban::validate($mailbox, 'new')['stage']);
    }

    public function test_stage_validation_rejects_unknown_stage()
    {
        if (!Kanban::isAvailable()) {
            $this->markTestSkipped('Kanban module is not active in this test database.');
        }

        $result = Kanban::validate(new Mailbox(), 'stage_that_does_not_exist');

        $this->assertFalse($result['ok']);
        $this->assertSame(Kanban::ERROR_STAGE_INVALID, $result['error']);
    }

    public function test_stages_include_the_virtual_none_column_first()
    {
        if (!Kanban::isAvailable()) {
            $this->markTestSkipped('Kanban module is not active in this test database.');
        }

        $stages = Kanban::stages(new Mailbox());

        $this->assertSame(Kanban::NONE, $stages[0]['id']);
        $this->assertTrue($stages[0]['virtual']);
        $this->assertSame('new', $stages[1]['id']);
    }

    public function test_stage_validation_reports_when_kanban_is_not_active()
    {
        if (Kanban::isAvailable()) {
            $this->markTestSkipped('Kanban module is active; the unavailable branch is not reachable.');
        }

        $result = Kanban::validate(new Mailbox(), 'new');

        $this->assertFalse($result['ok']);
        $this->assertSame(Kanban::ERROR_NOT_AVAILABLE, $result['error']);
    }
}
