<?php

namespace Modules\Kanban\Tests;

use App\Conversation;
use App\Customer;
use App\Folder;
use App\Mailbox;
use App\MailboxUser;
use App\User;
use Tests\TestCase;

/**
 * Base de las pruebas del módulo Kanban.
 *
 * Los datos se crean a mano (sin `factory()`) a propósito: las factorías de
 * Laravel necesitan Faker y este `vendor/` no lo trae, así que la suite no
 * podría ejecutarse. Con modelos construidos aquí, las pruebas dependen sólo
 * del core y de la base de datos.
 */
abstract class KanbanTestCase extends TestCase
{
    /** @var User */
    protected $admin;

    /** @var Mailbox */
    protected $mailbox;

    /** @var Folder */
    protected $folder;

    /** @var Customer */
    protected $customer;

    /** @var Mailbox[] */
    protected $createdMailboxes = [];

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * `app.allowed_user_agents` no está definido en config/app.php, y
         * App\Http\Middleware\CheckBrowser hace strtolower(null) con él. En
         * PHP 8.1 eso es una deprecación que Laravel convierte en excepción, así
         * que cualquier petición autenticada acaba en 500. Es un fallo del core
         * (ajeno a este módulo): se define aquí para que las pruebas HTTP puedan
         * ejecutarse sin tocar el core.
         */
        config(['app.allowed_user_agents' => '']);

        // Punto de partida conocido: el buzón de la prueba no tiene fases propias.
        $this->admin = $this->makeUser(User::ROLE_ADMIN);
        $this->mailbox = $this->makeMailbox();
        $this->folder = $this->makeFolder($this->mailbox);

        $this->customer = Customer::create($this->uniqueEmail('cliente'), [
            'first_name' => 'Cliente',
            'last_name'  => 'Kanban',
        ]);
    }

    protected function tearDown(): void
    {
        // Cada prueba deja su propio rastro; se devuelven las fases a los valores
        // por defecto para no arrastrar configuración entre ejecuciones.
        foreach (array_merge($this->createdMailboxes, $this->mailbox ? [$this->mailbox] : []) as $mailbox) {
            \Modules\Kanban\Support\Stages::saveForMailbox($mailbox->fresh(), \Modules\Kanban\Support\Stages::defaults());
        }

        parent::tearDown();
    }

    /**
     * @return string
     */
    protected function uniqueEmail($prefix = 'kanban')
    {
        return $prefix . '-' . uniqid('', true) . '@example.com';
    }

    /**
     * @param  int $role
     * @return User
     */
    protected function makeUser($role = User::ROLE_USER)
    {
        $user = new User();
        $user->first_name = 'Prueba';
        $user->last_name = 'Kanban';
        $user->email = $this->uniqueEmail('usuario');
        $user->password = bcrypt('secret');
        // `role` está en $guarded, así que se asigna directamente.
        $user->role = $role;
        $user->status = User::STATUS_ACTIVE;
        $user->save();

        return $user;
    }

    /**
     * @param  string|null $name
     * @return Mailbox
     */
    protected function makeMailbox($name = null)
    {
        $mailbox = new Mailbox();
        $mailbox->name = $name ?: 'Kanban ' . substr(uniqid(), -6);
        $mailbox->email = $this->uniqueEmail('buzon');
        $mailbox->save();
        $this->createdMailboxes[] = $mailbox;
        return $mailbox;
    }

    /**
     * @param  Mailbox $mailbox
     * @param  int     $type
     * @return Folder
     */
    protected function makeFolder(Mailbox $mailbox, $type = Folder::TYPE_UNASSIGNED)
    {
        $folder = new Folder();
        $folder->mailbox_id = $mailbox->id;
        $folder->type = $type;
        $folder->save();

        return $folder;
    }

    /**
     * Da acceso a un usuario a un buzón (fila en mailbox_user).
     */
    protected function grantAccess($user, $mailbox)
    {
        $pivot = new MailboxUser();
        $pivot->mailbox_id = $mailbox->id;
        $pivot->user_id = $user->id;
        $pivot->save();

        return $pivot;
    }

    /**
     * Crea una conversación publicada y abierta en el buzón de la prueba.
     *
     * @param  array $attributes
     * @return Conversation
     */
    protected function makeConversation(array $attributes = [])
    {
        $mailbox = $attributes['mailbox'] ?? $this->mailbox;
        unset($attributes['mailbox']);

        $folder = $attributes['folder'] ?? $this->folder;
        unset($attributes['folder']);

        $conversation = new Conversation();
        $conversation->type = Conversation::TYPE_EMAIL;
        $conversation->source_type = Conversation::SOURCE_TYPE_WEB;
        $conversation->source_via = Conversation::PERSON_CUSTOMER;
        $conversation->mailbox_id = $mailbox->id;
        $conversation->folder_id = $folder->id;
        $conversation->customer_id = $this->customer->id;
        $conversation->customer_email = $this->customer->getMainEmail();
        $conversation->subject = 'Conversación de prueba';
        $conversation->preview = 'Vista previa';
        $conversation->status = Conversation::STATUS_ACTIVE;
        $conversation->state = Conversation::STATE_PUBLISHED;
        $conversation->threads_count = 1;
        $conversation->last_reply_at = now()->subHours(2);
        $conversation->last_reply_from = Conversation::PERSON_CUSTOMER;

        foreach ($attributes as $key => $value) {
            $conversation->{$key} = $value;
        }

        $conversation->save();

        return $conversation;
    }
}
