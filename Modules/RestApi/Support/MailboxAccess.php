<?php

namespace Modules\RestApi\Support;

/**
 * Normalizes and applies the "mailbox_ids" restriction attached to an API token.
 *
 * The value used to be stored in several different shapes depending on how the
 * token was created:
 *
 *  - null / '' / '0' / 'null'   -> token has access to every mailbox
 *  - '["1","2"]'                -> JSON array (current writer)
 *  - '"[\"1\",\"2\"]"'          -> JSON array encoded twice (legacy writer + json cast)
 *  - '[1,2]'                    -> JSON array of integers
 *  - '1,2'                      -> comma separated list (token form / CLI option)
 *  - [1, 2]                     -> already decoded array
 *  - [] / '[]' / 'abc'          -> no mailbox at all (unsupported value: fails closed)
 *
 * Passing any of those strings straight to in_array() raised
 * "in_array(): Argument #2 ($haystack) must be of type array, string given"
 * and produced HTTP 500 responses on every endpoint that checks mailbox access,
 * while the listing endpoint silently ignored the restriction and leaked every
 * mailbox. This helper makes the value canonical for all call sites.
 */
class MailboxAccess
{
    /**
     * Maximum depth used to unwrap JSON encoded values (defensive against
     * values that were encoded several times).
     */
    const MAX_DEPTH = 4;

    /**
     * Return the canonical mailbox scope for a token.
     *
     * @param  mixed $value raw value coming from the database, request or form.
     * @return array|null   null when the token can access every mailbox,
     *                      an array of unique positive integers otherwise.
     *                      An empty array means "no mailbox at all" and is
     *                      only returned for values that cannot be parsed,
     *                      so that unknown input fails closed.
     *
     * The method is idempotent: normalizing an already normalized scope
     * returns the same scope.
     */
    public static function normalize($value)
    {
        return self::parse($value, 0);
    }

    /**
     * Whether the given scope grants access to every mailbox.
     *
     * @param  array|null $scope
     * @return bool
     */
    public static function isAllMailboxes($scope)
    {
        return $scope === null;
    }

    /**
     * Whether the given scope grants access to a specific mailbox.
     *
     * @param  array|null $scope
     * @param  mixed      $mailboxId
     * @return bool
     */
    public static function allows($scope, $mailboxId)
    {
        if ($scope === null) {
            return true;
        }

        if (!is_array($scope) || !is_numeric($mailboxId)) {
            return false;
        }

        return in_array((int) $mailboxId, $scope, true);
    }

    /**
     * Apply the scope to a query builder.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param  string            $column
     * @param  array|null        $scope
     * @return mixed
     */
    public static function applyScope($query, $column, $scope)
    {
        if ($scope === null) {
            return $query;
        }

        if (empty($scope)) {
            // No authorized mailbox: force an empty result set without
            // relying on whereIn([]) which generates invalid SQL on some
            // database drivers.
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $scope);
    }

    /**
     * Parse a raw value into the canonical scope.
     *
     * @param  mixed $value
     * @param  int   $depth
     * @return array|null
     */
    protected static function parse($value, $depth)
    {
        if ($value === null || $value === false) {
            return null;
        }

        if (is_array($value)) {
            return self::canonicalize($value);
        }

        if (is_int($value) || is_float($value)) {
            return $value > 0 ? [(int) $value] : null;
        }

        if (is_object($value)) {
            return self::parse(json_decode(json_encode($value), true), $depth + 1);
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || $value === '0' || strtolower($value) === 'null') {
            return null;
        }

        $first = substr($value, 0, 1);

        if ($depth < self::MAX_DEPTH && ($first === '[' || $first === '{' || $first === '"')) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                return self::parse($decoded, $depth + 1);
            }
        }

        // Comma separated list: "1,2,3".
        return self::canonicalize(explode(',', $value));
    }

    /**
     * Reduce a list of values to unique positive integers.
     *
     * @param  array $values
     * @return array
     */
    protected static function canonicalize(array $values)
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_array($value)) {
                if (isset($value['id'])) {
                    $value = $value['id'];
                } elseif (isset($value['value'])) {
                    $value = $value['value'];
                } else {
                    continue;
                }
            }

            if (is_bool($value) || $value === null) {
                continue;
            }

            if (is_object($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '' || !is_numeric($value)) {
                continue;
            }

            $id = (int) $value;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
