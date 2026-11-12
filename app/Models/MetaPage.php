<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaPage extends Model
{
    use HasUlids;

    protected $fillable = [
        'store_id',
        'meta_connection_id',
        'meta_page_id',
        'name',
        'tasks',
        'is_default',
        'selected_at',
        'page_token_refreshed_at',
        'page_token_expires_at',
    ];

    protected $hidden = [
        'page_access_token',
    ];

    protected function casts(): array
    {
        return [
            'page_access_token' => 'encrypted',
            'tasks' => 'array',
            'is_default' => 'boolean',
            'selected_at' => 'datetime',
            'page_token_refreshed_at' => 'datetime',
            'page_token_expires_at' => 'datetime',
        ];
    }

    /**
     * Meta Pages API requires CREATE_CONTENT to publish as the Page.
     * Docs: https://developers.facebook.com/docs/graph-api/reference/page/feed/
     */
    public function canPublishContent(): bool
    {
        $tasks = is_array($this->tasks) ? $this->tasks : [];

        return in_array('CREATE_CONTENT', $tasks, true)
            || in_array('MANAGE', $tasks, true);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }
}
