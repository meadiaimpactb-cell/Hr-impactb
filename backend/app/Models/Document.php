<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $guarded = ["id"];

    protected function casts(): array
    {
        return ["issue_date" => "date:Y-m-d", "expiry_date" => "date:Y-m-d", "renewed" => "boolean", "renewals" => "array", "alerted" => "array"];
    }

    public function employee() { return $this->belongsTo(Employee::class, "owner_id"); }
    public function company() { return $this->belongsTo(Company::class, "owner_id"); }
}
