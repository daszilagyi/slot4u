<?php

namespace App\Http\Requests\Concerns;

use App\Support\ScheduleVisibility;
use Illuminate\Contracts\Validation\Validator;

/**
 * The employee "saját" scope on the write side of the schedule (SLO-177).
 *
 * Every schedule write names its target in the body — a band create, a band
 * update (which may MOVE the band to another resource), an exception, a
 * day-copy. Each of those is a separate Form Request, and a scope rule present
 * in three of four is worth nothing: the missing one is the way round it. Hence
 * one shared check rather than four copies.
 *
 * The read side and the bound-record writes are covered elsewhere — the same
 * {@see ScheduleVisibility} narrows the list queries and the route binding, so a
 * colleague's existing band 404s before validation is even reached.
 */
trait ScopesSchedulable
{
    /**
     * Reject a schedulable outside the actor's scope: without
     * `schedule.manage_all` only a staff record they are linked to, never a
     * colleague's and never a room's (a room has no owner to be linked to).
     *
     * A validation error rather than a 403 — this is a form field, and it is the
     * same shape the tenant-scoped `exists` rule already produces for a forged
     * id, so the UI has somewhere to show it.
     */
    protected function validateSchedulableScope(Validator $validator): void
    {
        $actor = $this->user();

        if ($actor === null) {
            return;
        }

        $type = $this->input('schedulable_type');
        $id = $this->input('schedulable_id');

        $inScope = ScheduleVisibility::ownsSchedulable(
            $actor,
            is_string($type) ? $type : null,
            $id === null ? null : (int) $id,
        );

        if (! $inScope) {
            $validator->errors()->add('schedulable_id', __('app.admin.schedule.error.out_of_scope'));
        }
    }
}
