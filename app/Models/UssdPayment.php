<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UssdPayment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'wallet_id',
        'provider',
        'phone_number',
        'amount',
        'bundle_type',
        'transaction_ref',
        'status',
        'ussd_code',
        'verification_token',
        'ussd_response',
        'retry_count',
        'ussd_sent_at',
        'verified_at',
        'completed_at',
        'expired_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'ussd_response' => 'array',
        'ussd_sent_at' => 'datetime',
        'verified_at' => 'datetime',
        'completed_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    protected $hidden = [
        'phone_number',
        'verification_token',
    ];

    /**
     * Get the user that owns the USSD payment
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the wallet associated with this payment
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * Get the USSD sessions for this payment
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UssdSession::class);
    }

    /**
     * Check if payment is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if payment is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if payment has failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if payment is expired
     */
    public function isExpired(): bool
    {
        return $this->expired_at !== null || $this->status === 'failed';
    }

    /**
     * Mark as verified
     */
    public function markAsVerified(): void
    {
        $this->update([
            'status' => 'verified',
            'verified_at' => now(),
        ]);
    }

    /**
     * Mark as completed
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(): void
    {
        $this->update([
            'status' => 'failed',
            'retry_count' => $this->retry_count + 1,
        ]);
    }

    /**
     * Mark as expired
     */
    public function markAsExpired(): void
    {
        $this->update([
            'status' => 'failed',
            'expired_at' => now(),
        ]);
    }

    /**
     * Get bundle details
     */
    public function getBundleDetails(): array
    {
        $bundles = config('payment.bundles');
        return $bundles[$this->bundle_type] ?? [];
    }
}
