<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Spec 012 chat slice: a chat conversation. This slice creates only
 * front-desk conversations (scope = front_desk, matter_id null);
 * matter_id exists so matter-scoped conversations need no migration
 * when the workspace chat is wired in a later slice.
 */
class ChatConversation extends Model
{
    use HasUuids;

    public const SCOPE_FRONT_DESK = 'front_desk';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'org_id',
        'scope',
        'matter_id',
        'title',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return BelongsTo<Matter, $this>
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /**
     * @return HasMany<ChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->orderBy('sequence');
    }

    /**
     * Next per-conversation sequence number (max + 1, starting at 1).
     */
    public function nextSequence(): int
    {
        return (int) $this->messages()->max('sequence') + 1;
    }
}
