<?php

namespace Modules\Kanban\Http\Controllers;

use App\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Kanban\Entities\Board;
use Modules\Kanban\Providers\KanbanServiceProvider;
use Modules\Kanban\Support\Medium;
use Modules\Kanban\Support\Stages;

class KanbanController extends Controller
{
    /**
     * Tablero.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $filters = $this->filtersFrom($request);
        $board = new Board($user, $filters);

        if (!$board->hasMailboxes()) {
            return response()->view('kanban::no-mailboxes', [
                'mailboxes' => $user->mailboxesCanView(),
            ], 403);
        }

        return view('kanban::index', [
            'columns'       => $this->renderColumns($board),
            'mailboxes'     => $user->mailboxesCanView(),
            'assignees'     => $this->assignees($board),
            'media'         => Medium::all(),
            'filters'       => $filters,
            'can_settings'  => $this->canSeeSettings($user),
            'moduleVersion' => KanbanServiceProvider::moduleVersion(),
        ]);
    }

    /**
     * Refresco / "cargar más" del tablero (JSON con el HTML de las tarjetas).
     */
    public function board(Request $request): JsonResponse
    {
        $user = auth()->user();
        $filters = $this->filtersFrom($request);
        $board = new Board($user, $filters);

        if (!$board->hasMailboxes()) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('kanban::kanban.error_mailbox'),
            ], 403);
        }

        $columns = $this->renderColumns($board);

        return response()->json([
            'status'  => 'success',
            'columns' => $columns,
            'total'   => array_sum(array_column($columns, 'count')),
        ]);
    }

    /**
     * Mueve una tarjeta a otra fase.
     */
    public function move(Request $request): JsonResponse
    {
        $user = auth()->user();

        $request->validate([
            'conversation_id' => 'required|integer',
            'stage'           => 'required|string|max:64',
        ]);

        $conversation = Conversation::find($request->input('conversation_id'));

        if (!$conversation) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('kanban::kanban.error_not_found'),
            ], 404);
        }

        // Permisos: ver y actualizar (los mismos que usa el core para cambiar
        // el estado o el asignado desde la interfaz nativa).
        if (!$user->can('view', $conversation)) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('kanban::kanban.error_forbidden'),
            ], 403);
        }

        if (!$user->can('update', $conversation)) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('kanban::kanban.error_update'),
            ], 403);
        }

        $stage = (string) $request->input('stage');

        if ($stage !== Stages::NONE) {
            $mailbox = $conversation->mailbox;

            if (!$mailbox || !Stages::find($mailbox, $stage)) {
                return response()->json([
                    'status' => 'error',
                    'msg'    => __('kanban::kanban.error_stage'),
                ], 422);
            }
        }

        $result = Stages::move($conversation, $stage, $user);

        $board = new Board($user, $this->filtersFrom($request));

        return response()->json([
            'status'          => 'success',
            'msg'             => $result['status_changed']
                ? __('kanban::kanban.moved_and_status', ['stage' => $this->stageName($conversation, $result['stage'])])
                : __('kanban::kanban.moved', ['stage' => $this->stageName($conversation, $result['stage'])]),
            'conversation_id' => $conversation->id,
            'stage'           => $result['stage'],
            'status_changed'  => $result['status_changed'],
            'counts'          => $board->counts(),
        ]);
    }

    /**
     * Nombre legible de una etapa (para los mensajes).
     *
     * @param  Conversation $conversation
     * @param  string       $stageId
     * @return string
     */
    protected function stageName(Conversation $conversation, $stageId)
    {
        if ($stageId === Stages::NONE) {
            return Stages::none()['name'];
        }

        $stage = $conversation->mailbox ? Stages::find($conversation->mailbox, $stageId) : null;

        return $stage ? $stage['name'] : $stageId;
    }

    /**
     * ¿Puede el usuario editar las etapas de algún buzón visible?
     *
     * @param  \App\User $user
     * @return bool
     */
    protected function canSeeSettings($user)
    {
        foreach ($user->mailboxesCanView() as $mailbox) {
            if ($user->can('updateSettings', $mailbox)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Editor de fases de un buzón.
     */
    public function settings(Request $request)
    {
        $user = auth()->user();
        $mailboxes = $user->mailboxesCanView();

        $mailbox = $this->resolveMailbox($request, $mailboxes);

        if (!$mailbox) {
            abort(404);
        }

        if (!$user->can('updateSettings', $mailbox)) {
            abort(403);
        }

        return view('kanban::settings', [
            'mailboxes' => $mailboxes,
            'mailbox'   => $mailbox,
            'stages'    => Stages::forMailbox($mailbox),
            'statuses'  => Stages::statusOptions(),
            'palette'   => config('kanban.palette', []),
            'moduleVersion' => KanbanServiceProvider::moduleVersion(),
        ]);
    }

    /**
     * Guarda las fases de un buzón (o restaura las de por defecto).
     */
    public function saveSettings(Request $request)
    {
        $user = auth()->user();
        $mailboxes = $user->mailboxesCanView();
        $mailbox = $this->resolveMailbox($request, $mailboxes);

        if (!$mailbox) {
            abort(404);
        }

        if (!$user->can('updateSettings', $mailbox)) {
            abort(403);
        }

        if ($request->filled('reset')) {
            Stages::saveForMailbox($mailbox, Stages::defaults());

            return redirect()
                ->route('kanban.settings', ['mailbox_id' => $mailbox->id])
                ->with('flash_success_floating', __('kanban::kanban.settings_reset'));
        }

        $request->validate([
            'stages'         => 'required|array|min:1',
            'stages.*.name'  => 'required|string|max:60',
            'stages.*.color' => 'nullable|string|max:7',
            'stages.*.wip'   => 'nullable|integer|min:0|max:999',
            'stages.*.status' => 'nullable|integer|in:1,2,3,4',
        ], [], [
            'stages' => __('kanban::kanban.stages'),
        ]);

        Stages::saveForMailbox($mailbox, (array) $request->input('stages', []));

        return redirect()
            ->route('kanban.settings', ['mailbox_id' => $mailbox->id])
            ->with('flash_success_floating', __('kanban::kanban.settings_saved'));
    }

    /**
     * Buzón solicitado (o el primero visible).
     */
    protected function resolveMailbox(Request $request, $mailboxes)
    {
        $id = (int) $request->input('mailbox_id', 0);

        if ($id) {
            return $mailboxes->where('id', $id)->first();
        }

        return $mailboxes->first();
    }

    /**
     * Columnas listas para pintar: las tarjetas se renderizan con el mismo
     * partial que usa la vista, para no duplicar marcado en el JS.
     *
     * @param  Board $board
     * @return array
     */
    protected function renderColumns(Board $board)
    {
        $columns = $board->columns();

        foreach ($columns as &$column) {
            $column['html'] = view('kanban::partials.cards', ['cards' => $column['cards']])->render();
            unset($column['cards']);
        }
        unset($column);

        return $columns;
    }

    /**
     * Filtros aceptados por el tablero.
     *
     * @param  Request $request
     * @return array
     */
    protected function filtersFrom(Request $request)
    {
        $filters = [
            'mailbox_id' => $request->input('mailbox_id'),
            'assignee'   => $request->input('assignee'),
            'search'     => $request->input('search'),
            'stale_days' => $request->input('stale_days'),
            'stage'      => $request->input('stage'),
            'offset'     => $request->input('offset'),
        ];

        return array_filter($filters, function ($value) {
            return $value !== null && $value !== '' && $value !== '0';
        });
    }

    /**
     * Usuarios que pueden ser asignados en los buzones visibles.
     *
     * @param  Board $board
     * @return array id => nombre
     */
    protected function assignees(Board $board)
    {
        $assignees = [];

        foreach ($board->mailboxes() as $mailbox) {
            foreach ($mailbox->usersHavingAccess(true) as $user) {
                $assignees[$user->id] = $user->getFullName();
            }
        }

        asort($assignees);

        return $assignees;
    }
}
