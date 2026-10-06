<?php

namespace Modules\Kanban\Tests\Unit;

use App\Conversation;
use Modules\Kanban\Support\Medium;
use Tests\TestCase;

/**
 * Tipo de medio de una conversación: de él depende el color de la línea
 * izquierda de la tarjeta, que es la mitad de la información del tablero.
 */
class MediumTest extends TestCase
{
    public function test_from_type_maps_every_core_type()
    {
        $this->assertSame(Medium::EMAIL, Medium::fromType(Conversation::TYPE_EMAIL));
        $this->assertSame(Medium::PHONE, Medium::fromType(Conversation::TYPE_PHONE));
        $this->assertSame(Medium::CHAT, Medium::fromType(Conversation::TYPE_CHAT));
        $this->assertSame(Medium::WHATSAPP, Medium::fromType(Conversation::TYPE_WHATSAPP));
        $this->assertSame(Medium::CUSTOM, Medium::fromType(Conversation::TYPE_CUSTOM));
    }

    public function test_from_type_falls_back_to_custom_for_unknown_types()
    {
        $this->assertSame(Medium::CUSTOM, Medium::fromType(999));
        $this->assertSame(Medium::CUSTOM, Medium::fromType(null));
    }

    public function test_from_name_accepts_aliases_including_spanish()
    {
        $this->assertSame(Medium::EMAIL, Medium::fromName('Email'));
        $this->assertSame(Medium::EMAIL, Medium::fromName('correo'));
        $this->assertSame(Medium::WHATSAPP, Medium::fromName('WhatsApp'));
        $this->assertSame(Medium::WHATSAPP, Medium::fromName('wa'));
        $this->assertSame(Medium::PHONE, Medium::fromName('teléfono'));
        $this->assertSame(Medium::SMS, Medium::fromName('sms'));
        $this->assertSame(Medium::CUSTOM, Medium::fromName('otro'));

        $this->assertNull(Medium::fromName(''));
        $this->assertNull(Medium::fromName('paloma mensajera'));
    }

    public function test_of_uses_the_conversation_type()
    {
        $conversation = new Conversation();
        $conversation->type = Conversation::TYPE_WHATSAPP;

        $this->assertSame(Medium::WHATSAPP, Medium::of($conversation));

        $conversation->type = Conversation::TYPE_EMAIL;

        $this->assertSame(Medium::EMAIL, Medium::of($conversation));
    }

    public function test_all_exposes_every_medium_translated()
    {
        $all = Medium::all();

        foreach ([Medium::EMAIL, Medium::WHATSAPP, Medium::PHONE, Medium::SMS, Medium::CHAT, Medium::CUSTOM] as $medium) {
            $this->assertArrayHasKey($medium, $all);
            $this->assertNotEmpty($all[$medium]);
        }
    }

    public function test_label_and_color_come_from_config()
    {
        $this->assertSame('WhatsApp', Medium::label(Medium::WHATSAPP));
        $this->assertSame(config('kanban.media.whatsapp'), Medium::color(Medium::WHATSAPP));
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', Medium::color(Medium::WHATSAPP));
    }

    public function test_color_falls_back_to_custom_for_unknown_mediums()
    {
        $this->assertSame(Medium::color(Medium::CUSTOM), Medium::color('paloma'));
    }
}
