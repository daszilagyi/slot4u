<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\MessageTemplate;
use App\Models\User;

/**
 * Notification templates are a tenant-admin concern (docs/03 matrix:
 * `template.manage` is granted to Tenant Admin only). Cross-tenant access is
 * impossible: the BelongsToTenant global scope keeps every query and upsert inside
 * the current tenant. Super-admins pass via the Gate::before hook.
 */
class MessageTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::TemplateManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::TemplateManage->value);
    }

    public function update(User $user, MessageTemplate $template): bool
    {
        return $user->can(Permission::TemplateManage->value);
    }

    public function delete(User $user, MessageTemplate $template): bool
    {
        return $user->can(Permission::TemplateManage->value);
    }
}
