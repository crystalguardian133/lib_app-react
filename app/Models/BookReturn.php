<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookReturn extends Model
{
    protected $table = 'returns';

    protected $fillable = [
        'transaction_id', 'returned_at'
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
