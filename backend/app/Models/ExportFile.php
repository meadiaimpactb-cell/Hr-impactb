<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportFile extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path'];
}
