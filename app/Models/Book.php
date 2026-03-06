<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    protected $table = 'books';

    protected $fillable = ['title', 'author', 'genre', 'published_year', 'availability','qr_url', 'cover_image'];

}

