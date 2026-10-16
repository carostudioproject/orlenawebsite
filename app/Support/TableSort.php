<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * Admin tables sorted by a column header: ?sort=<key>&dir=asc|desc, only keys the table allows.
 * A key maps to a column name or to a closure (query, dir) for joins and aggregates.
 * Returns the applied sort for the page; without one the caller keeps its default order.
 */
final class TableSort
{
    /** @param  array<string, string|Closure>  $columns */
    public static function apply(EloquentBuilder|Builder $query, Request $request, array $columns, ?string $tiebreak = 'id'): array
    {
        $sort = $request->query('sort');
        if (! is_string($sort) || ! array_key_exists($sort, $columns)) {
            return ['sort' => null, 'dir' => null];
        }
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $column = $columns[$sort];
        $column instanceof Closure ? $column($query, $dir) : $query->orderBy($column, $dir);
        // Same values keep a stable order across pages.
        if ($tiebreak) {
            $query->orderBy($query instanceof EloquentBuilder ? $query->getModel()->qualifyColumn($tiebreak) : $tiebreak, $dir);
        }

        return ['sort' => $sort, 'dir' => $dir];
    }
}
