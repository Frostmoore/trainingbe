<?php

namespace App\Filament\God\Resources\Tenants\Pages;

use App\Filament\God\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;
}
