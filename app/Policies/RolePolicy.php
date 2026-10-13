<?php

namespace App\Policies;

use App\Policies\Concerns\OnlyAdministrators;

final class RolePolicy
{
    use OnlyAdministrators;
}
