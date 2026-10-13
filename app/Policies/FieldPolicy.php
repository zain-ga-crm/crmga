<?php

namespace App\Policies;

use App\Policies\Concerns\OnlyAdministrators;

final class FieldPolicy
{
    use OnlyAdministrators;
}
