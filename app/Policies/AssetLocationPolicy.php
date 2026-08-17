<?php

namespace App\Policies;

class AssetLocationPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'assets';
    }
}
