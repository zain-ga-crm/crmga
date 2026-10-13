<?php

namespace App\Policies;

use App\Policies\Concerns\OnlyAdministrators;

final class ModulePolicy
{
    use OnlyAdministrators;
}
