<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrelloListMapping extends Model
{
    protected $fillable = ['trello_list_id', 'trello_list_name', 'pipeline_status'];

    public static function statusForList(string $listId): ?string
    {
        return static::where('trello_list_id', $listId)->value('pipeline_status');
    }
}
