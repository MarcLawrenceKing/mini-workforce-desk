<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Laratrust\Models\Permission as PermissionModel;

#[Fillable(['name', 'display_name', 'description'])]
class Permission extends PermissionModel {}
