<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UssdSession extends Model
{
    protected $fillable = [
        'ussd_payment_id',
        'session_id',
        'phone_number',
        'provider',
        'user_input',
        'menu_response',
        'session_status',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'phone_number',
    ];

    /**
     * Get the USSD payment associated with this session
     */
    public function ussdPayment(): BelongsTo
    {
        return $this->belongsTo(UssdPayment::class);
    }

    /**
     * Check if session is active
     */
    public function isActive(): bool
    {
        return $this->session_status === 'active' && $this->expires_at->isFuture();
    }

    /**
     * Mark session as completed
     */
    public function markAsCompleted(): void
    {
        $this->update(['session_status' => 'completed']);
    }

    /**
     * Mark session as timeout
     */
    public function markAsTimeout(): void
    {
        $this->update(['session_status' => 'timeout']);
    }
}
