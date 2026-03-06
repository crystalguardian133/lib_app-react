<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
   protected $fillable = [
    'book_id',
    'member_id',
    'borrowed_at',
    'due_date',
    'status',
    'returned_at',
];
    protected $casts = [
        'borrowed_at' => 'datetime',
        'due_date' => 'datetime',
        'returned_at' => 'datetime',
    ];
public function member()
{
    return $this->belongsTo(Member::class);
}

public function book()
{
    return $this->belongsTo(Book::class);
}
}
