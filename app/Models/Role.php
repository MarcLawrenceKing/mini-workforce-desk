<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Laratrust\Models\Role as RoleModel;

#[Fillable(['name', 'display_name', 'description'])]
class Role extends RoleModel {}
