<?php
// app/Models/TimeLog.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimeLog extends Model
{
    protected $fillable = ['member_id', 'time_in', 'time_out'];

    public function member() {
        return $this->belongsTo(Member::class);
    }
}
