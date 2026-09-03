<?php

namespace App\Support\Acl;

use App\Support\Acl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The shared global scope (BACKEND_BRIEF §8.3): none -> no rows, owner -> only
 * assigned_user_id = me, group -> only records granted to one of the user's
 * groups (via `group_records`), all -> unrestricted. Applied to every model
 * using HasAcl; the API reuses this unchanged, so the interface and the API
 * can never disagree about who sees what.
 */
final class AppliesRecordAccess implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! $model instanceof Aclable) {
            return;
        }

        $user = auth()->user();
        if ($user === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $level = app(Acl::class)->effective($user, $model->moduleKey(), 'view');

        match ($level) {
            AccessLevel::All => null,
            AccessLevel::Owner => $builder->where($model->qualifyColumn('assigned_user_id'), $user->id),
            AccessLevel::Group => $builder->whereExists(function (QueryBuilder $query) use ($model, $user): void {
                $query->select(DB::raw(1))
                    ->from('group_records')
                    ->whereColumn('group_records.recordable_id', $model->qualifyColumn($model->getKeyName()))
                    ->where('group_records.recordable_type', $model::class)
                    ->whereIn('group_records.group_id', app(Acl::class)->groupIdsFor($user));
            }),
            AccessLevel::None, AccessLevel::NotSet => $builder->whereRaw('1 = 0'),
        };
    }
}
