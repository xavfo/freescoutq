<?php

namespace Modules\RestApi\Tests\Unit;

use Modules\RestApi\Support\MailboxAccess;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MailboxAccess.
 *
 * These tests do not need the database or the framework, they only validate
 * the normalization of the "mailbox_ids" value that used to be passed with
 * the wrong type to in_array(), causing HTTP 500 responses.
 */
class MailboxAccessTest extends TestCase
{
    /**
     * Values meaning "this token can access every mailbox".
     *
     * @return array
     */
    public function allMailboxesProvider()
    {
        return [
            'null' => [null],
            'false' => [false],
            'empty string' => [''],
            'blank string' => ['   '],
            'zero string' => ['0'],
            'null string' => ['null'],
        ];
    }

    /**
     * @dataProvider allMailboxesProvider
     */
    public function test_empty_values_mean_access_to_every_mailbox($value)
    {
        $this->assertNull(MailboxAccess::normalize($value));
        $this->assertTrue(MailboxAccess::isAllMailboxes(MailboxAccess::normalize($value)));
    }

    /**
     * Every shape the value has been stored with over time.
     *
     * @return array
     */
    public function scopeProvider()
    {
        return [
            'csv' => ['1,2,3', [1, 2, 3]],
            'csv with spaces' => [' 1 , 2 ', [1, 2]],
            'single id' => ['7', [7]],
            'json of strings' => ['["1","2"]', [1, 2]],
            'json of integers' => ['[1,2]', [1, 2]],
            'json encoded twice' => [json_encode(json_encode([1, 2])), [1, 2]],
            'json encoded three times' => [json_encode(json_encode(json_encode([1, 2]))), [1, 2]],
            'array' => [[1, 2], [1, 2]],
            'array of strings' => [['1', '2'], [1, 2]],
            'integer' => [3, [3]],
            'duplicated ids' => ['1,1,2', [1, 2]],
            'trailing comma' => ['1,2,', [1, 2]],
        ];
    }

    /**
     * @dataProvider scopeProvider
     */
    public function test_normalize_returns_canonical_array($value, array $expected)
    {
        $this->assertSame($expected, MailboxAccess::normalize($value));
    }

    public function test_normalize_is_idempotent()
    {
        $inputs = [
            null,
            '',
            '1,2,3',
            '["1","2"]',
            json_encode(json_encode([1, 2])),
            [1, 2],
            [],
            '[]',
            'garbage',
        ];

        foreach ($inputs as $input) {
            $once = MailboxAccess::normalize($input);
            $twice = MailboxAccess::normalize($once);

            $this->assertSame($once, $twice, 'normalize() is not idempotent for ' . var_export($input, true));
        }
    }

    /**
     * Values that cannot be parsed must fail closed (no mailbox at all)
     * instead of silently granting access to every mailbox.
     */
    public function unparsableProvider()
    {
        return [
            'garbage' => ['garbage'],
            'json string garbage' => ['"garbage"'],
            'empty json array' => ['[]'],
            'empty array' => [[]],
        ];
    }

    /**
     * @dataProvider unparsableProvider
     */
    public function test_unparsable_values_fail_closed($value)
    {
        $this->assertSame([], MailboxAccess::normalize($value));
    }

    public function test_allows_grants_every_mailbox_for_null_scope()
    {
        $this->assertTrue(MailboxAccess::allows(null, 1));
        $this->assertTrue(MailboxAccess::allows(null, 999));
    }

    public function test_allows_checks_the_list()
    {
        $this->assertTrue(MailboxAccess::allows([1, 2], 2));
        $this->assertTrue(MailboxAccess::allows([1, 2], '2'));
        $this->assertFalse(MailboxAccess::allows([1, 2], 3));
        $this->assertFalse(MailboxAccess::allows([], 1));
    }

    public function test_allows_never_throws_for_a_raw_string()
    {
        // This is the failing case reported in production: a string was passed
        // to in_array() and produced "Argument #2 must be of type array".
        $this->assertFalse(MailboxAccess::allows('1,2', 1));
    }

    public function test_apply_scope_adds_a_where_in_for_a_restricted_token()
    {
        $query = $this->fakeQuery();

        MailboxAccess::applyScope($query, 'mailbox_id', [1, 2]);

        $this->assertSame([['whereIn', 'mailbox_id', [1, 2]]], $query->calls);
    }

    public function test_apply_scope_does_not_filter_when_every_mailbox_is_allowed()
    {
        $query = $this->fakeQuery();

        MailboxAccess::applyScope($query, 'mailbox_id', null);

        $this->assertSame([], $query->calls);
    }

    public function test_apply_scope_returns_no_rows_when_nothing_is_authorized()
    {
        $query = $this->fakeQuery();

        MailboxAccess::applyScope($query, 'mailbox_id', []);

        $this->assertCount(1, $query->calls);
        $this->assertSame('whereRaw', $query->calls[0][0]);
    }

    /**
     * Minimal stand in for the query builder.
     *
     * @return object
     */
    protected function fakeQuery()
    {
        return new class {
            public $calls = [];

            public function whereIn($column, $values)
            {
                $this->calls[] = ['whereIn', $column, $values];

                return $this;
            }

            public function whereRaw($sql)
            {
                $this->calls[] = ['whereRaw', $sql];

                return $this;
            }
        };
    }
}
