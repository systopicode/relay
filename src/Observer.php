<?php

declare(strict_types=1);

namespace Systopic\Relay;

use Systopic\Db\Rows;
use Systopic\Db\Schema\Schema;
use Systopic\System\Dom\Dependencies;
use WeakMap;

/**
 * Reads become dependencies of the layer being rendered, writes become
 * signals — one channel per table (Relay::topic()).
 *
 * Tables, not rows, for now: a list a row is about to enter has never seen
 * it, only the table tells it (see the plan, „Themen und Kette“). The diff
 * renderer re-renders the layers that read the table, and those whose output
 * did not change come out as before.
 */
final class Observer implements \Systopic\Db\Observer
{
    /** @var WeakMap<Rows, list<string>> a cached result is handed out again and again */
    private WeakMap $channels;

    public function __construct()
    {
        $this->channels = new WeakMap();
    }

    public function read(string $key, Rows $rows): void
    {
        $channels = $this->channels[$rows] ??= self::channelsOf($rows);
        if ($channels !== []) {
            Dependencies::add(...$channels);
        }
    }

    public function committed(array $written, array $removed): void
    {
        $tables = [];
        foreach ([...$written, ...$removed] as $row) {
            $tables[Schema::tableNameOf($row::class)] = true;
        }
        Relay::changed(...array_map('strval', array_keys($tables)));
    }

    /** @return list<string> */
    private static function channelsOf(Rows $rows): array
    {
        $channels = [];
        foreach ($rows->sets() as $set) {
            foreach ($set->map->tables() as $table) {
                $channels[Relay::topic($table->name)] = true;
            }
        }
        return array_keys($channels);
    }
}
