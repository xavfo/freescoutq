<?php

namespace Modules\Kanban\Entities;

use App\Conversation;
use App\Customer;
use App\Email;
use App\User;
use Illuminate\Support\Facades\DB;
use Modules\Kanban\Entities\DTOs\CardDTO;
use Modules\Kanban\Support\Stages;

/**
 * Construye el tablero: columnas (fases) y sus tarjetas.
 *
 * Reglas de oro de este repositorio que se respetan aquí:
 *   · Todo va acotado a los buzones que el usuario puede ver.
 *   · Nada de whereHas/orWhereHas: el Query\Builder sobrescrito por FreeScout
 *     lanza "compact(): Undefined variable $operator". Se usan subconsultas.
 *   · Una consulta por columna con limit/offset (paginación real) más una
 *     consulta agregada para los contadores.
 */
class Board
{
    /** @var User */
    protected $user;

    /** @var array */
    protected $filters;

    /** @var \Illuminate\Support\Collection */
    protected $mailboxes;

    /** @var array */
    protected $stages;

    /** @var int */
    protected $perPage;

    /** @var int */
    protected $offset;

    public function __construct(User $user, array $filters = [])
    {
        $this->user = $user;
        $this->filters = $filters;
        $this->mailboxes = $this->resolveMailboxes();
        $this->stages = Stages::union($this->mailboxes);
        $this->perPage = max(1, (int) config('kanban.per_column', 30));
        $this->offset = max(0, (int) ($filters['offset'] ?? 0));
    }

    public function mailboxes()
    {
        return $this->mailboxes;
    }

    public function stages()
    {
        return $this->stages;
    }

    public function hasMailboxes()
    {
        return $this->mailboxes->isNotEmpty();
    }

    /**
     * Ids de los buzones visibles (o del buzón seleccionado).
     *
     * @return array
     */
    public function mailboxIds()
    {
        return $this->mailboxes->pluck('id')->all();
    }

    /**
     * Buzones que puede ver el usuario, acotados al que haya seleccionado.
     *
     * Es la primera barrera de seguridad del tablero: si el usuario pide un
     * buzón que no le corresponde, el resultado es una colección vacía (y el
     * tablero responde 403), nunca los datos de ese buzón.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function resolveMailboxes()
    {
        $visible = $this->user->mailboxesCanView()->values();

        $requested = (int) ($this->filters['mailbox_id'] ?? 0);

        if ($requested) {
            $visible = $visible->where('id', $requested)->values();
        }

        return $visible;
    }

    /**
     * Columnas del tablero, con sus tarjetas.
     *
     * Si el filtro trae `stage`, se devuelve solo esa columna (usado por
     * "cargar más").
     *
     * @return array
     */
    public function columns()
    {
        if (!$this->hasMailboxes()) {
            return [];
        }

        $counts = $this->counts();
        $only = isset($this->filters['stage']) ? (string) $this->filters['stage'] : null;

        $columns = [];

        $none = Stages::none();
        if ($only === null || $only === Stages::NONE) {
            $columns[] = $this->buildColumn(Stages::NONE, $none['name'], $none['color'], 0, $counts);
        }

        foreach ($this->stages as $stage) {
            if ($only !== null && $only !== $stage['id']) {
                continue;
            }
            $columns[] = $this->buildColumn($stage['id'], $stage['name'], $stage['color'], (int) $stage['wip'], $counts);
        }

        return $columns;
    }

    /**
     * Contadores por fase (incluida la columna "Sin etapa").
     *
     * @return array
     */
    public function counts()
    {
        $counts = [Stages::NONE => 0];
        $known = [];

        foreach ($this->stages as $stage) {
            $counts[$stage['id']] = 0;
            $known[$stage['id']] = true;
        }

        if (!$this->hasMailboxes()) {
            return $counts;
        }

        $rows = $this->baseQuery()
            ->select('kanban_stage', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('kanban_stage')
            ->pluck('aggregate', 'kanban_stage')
            ->all();

        foreach ($rows as $stageId => $total) {
            $stageId = $stageId ?: Stages::NONE;

            // Fase desconocida (borrada del buzón): cuenta como "Sin etapa".
            if (isset($known[$stageId])) {
                $counts[$stageId] += (int) $total;
            } else {
                $counts[Stages::NONE] += (int) $total;
            }
        }

        return $counts;
    }

    /**
     * Construye una columna con sus tarjetas.
     */
    protected function buildColumn($id, $name, $color, $wip, array $counts)
    {
        $count = (int) ($counts[$id] ?? 0);
        $cards = $count ? $this->cards($id) : [];

        return [
            'id'         => $id,
            'name'       => $name,
            'color'      => $color,
            'color_soft' => Stages::tint($color),
            'wip'        => (int) $wip,
            'count'      => $count,
            'has_more'   => ($this->offset + count($cards)) < $count,
            'offset'     => $this->offset + count($cards),
            'cards'      => $cards,
        ];
    }

    /**
     * Tarjetas de una columna.
     *
     * @param  string $stageId
     * @return CardDTO[]
     */
    protected function cards($stageId)
    {
        $query = $this->baseQuery()
            ->with([
                'customer:id,first_name,last_name',
                'user:id,first_name,last_name',
                'mailbox:id,name',
            ]);

        $this->applyStage($query, $stageId);

        return $query
            ->orderByRaw('COALESCE(last_reply_at, created_at) DESC')
            ->offset($this->offset)
            ->limit($this->perPage)
            ->get()
            ->map(function ($conversation) {
                return CardDTO::fromModel($conversation, (int) config('kanban.stale_days', 0));
            })
            ->all();
    }

    /**
     * Restringe la consulta a una fase (o a "sin etapa").
     */
    protected function applyStage($query, $stageId)
    {
        if ($stageId !== Stages::NONE) {
            $query->where('kanban_stage', $stageId);

            return;
        }

        $known = array_column($this->stages, 'id');

        $query->where(function ($q) use ($known) {
            $q->whereNull('kanban_stage');
            if (!empty($known)) {
                $q->orWhereNotIn('kanban_stage', $known);
            }
        });
    }

    /**
     * Consulta base: conversaciones abiertas de los buzones visibles,
     * con los filtros del tablero aplicados.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function baseQuery()
    {
        $statuses = array_map('intval', (array) config('kanban.statuses', [1, 2]));

        $query = Conversation::query()
            ->where('state', Conversation::STATE_PUBLISHED)
            ->whereIn('status', $statuses)
            ->whereIn('mailbox_id', $this->mailboxIds());

        $filters = $this->filters;

        // Asignado: 'none', 'me' o el id de un usuario.
        if (!empty($filters['assignee'])) {
            if ($filters['assignee'] === 'none') {
                $query->whereNull('user_id');
            } elseif ($filters['assignee'] === 'me') {
                $query->where('user_id', $this->user->id);
            } elseif (is_numeric($filters['assignee'])) {
                $query->where('user_id', (int) $filters['assignee']);
            }
        }

        // Búsqueda por asunto, email del cliente o nombre del cliente.
        // Subconsultas en lugar de whereHas (roto en este FreeScout).
        if (!empty($filters['search'])) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($filters['search'])) . '%';

            $query->where(function ($q) use ($like) {
                $q->where('subject', 'like', $like)
                    ->orWhere('customer_email', 'like', $like)
                    ->orWhereIn('customer_id', Customer::query()->select('id')->where(function ($c) use ($like) {
                        // También el nombre completo: buscar "Ana Pérez" no debe
                        // fallar sólo porque esté repartido en dos columnas.
                        $c->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like])
                            ->orWhereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", [$like]);
                    }))
                    ->orWhereIn('customer_id', Email::query()->select('customer_id')->where('email', 'like', $like));
            });
        }

        // Antigüedad: "la pelota está en nuestro tejado y lleva mucho tiempo ahí".
        // Se pide lo mismo que marca la tarjeta en rojo (CardDTO::stale), para
        // que el filtro y el aviso visual no puedan contradecirse.
        if (!empty($filters['stale_days']) && is_numeric($filters['stale_days'])) {
            $query->where(function ($q) use ($filters) {
                $q->where(function ($waiting) {
                    $waiting->whereNull('last_reply_from')
                        ->orWhere('last_reply_from', Conversation::PERSON_CUSTOMER);
                })
                    ->whereRaw('COALESCE(last_reply_at, created_at) < ?', [
                        now()->subDays((int) $filters['stale_days'])->toDateTimeString(),
                    ]);
            });
        }

        return $query;
    }
}
