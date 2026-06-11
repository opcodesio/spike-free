<?php

namespace Opcodes\Spike\Paddle;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Paddle\Cashier;
use Laravel\Cashier\PromotionCode;
use Opcodes\Spike\Contracts\SpikeSubscription;
use Opcodes\Spike\Contracts\SpikeSubscriptionItem;
use Opcodes\Spike\Database\Factories\Paddle\SubscriptionFactory;

class Subscription extends \Laravel\Paddle\Subscription implements SpikeSubscription
{
    use HasFactory;

    protected $table = 'paddle_subscriptions';

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $casts = [
        'quantity' => 'integer',
        'ends_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'renews_at' => 'datetime',
    ];

    protected static function newFactory()
    {
        return SubscriptionFactory::new();
    }

    public function getForeignKey()
    {
        return 'paddle_subscription_id';
    }

    public function getBillable()
    {
        return $this->billable;
    }

    public function getPriceId(): string
    {
        return $this->firstItem()->getPriceId();
    }

    public function isPastDue(): bool
    {
        return $this->status === self::STATUS_PAST_DUE;
    }

    public function getPromotionCodeId(): ?string
    {
        return null;
    }

    public function firstItem(): SpikeSubscriptionItem
    {
        return $this->items->first();
    }

    /**
     * Get the subscription items related to the subscription.
     *
     * @return HasMany|Collection|SubscriptionItem[]
     */
    public function items(): HasMany
    {
        return $this->hasMany(Cashier::$subscriptionItemModel);
    }

    public function hasPaymentCard(): bool
    {
        return ! empty($this->paddle_id);
    }

    public function hasPromotionCode(): bool
    {
        return false;
    }

    public function promotionCode(): ?PromotionCode
    {
        return null;
    }

    public function renewalDate(): ?CarbonInterface
    {
        if ($this->onGracePeriod()) {
            return $this->ends_at;
        }

        if ($this->hasPaymentCard()) {
            // Get the current subscription data from Paddle
            $paddleSubscription = $this->asPaddleSubscription();

            // Sync status if it differs between database and Paddle
            if ($paddleSubscription['status'] !== $this->status) {
                $this->status = $paddleSubscription['status'];
                $this->save();
            }

            // Don't process billing periods for cancelled or paused subscriptions
            if (in_array($this->status, [self::STATUS_CANCELED, self::STATUS_PAUSED])) {
                return null;
            }

            if (is_null($paddleSubscription['current_billing_period'])) {
                // Only log as warning if the Paddle subscription status indicates it should be active
                // For paused/cancelled subscriptions, this is expected behavior
                if ($this->status === self::STATUS_ACTIVE) {
                    Log::warning('Active Paddle Subscription has no current billing period. This may indicate an issue.', [
                        'paddle_id' => $this->paddle_id,
                        'status' => $this->status,
                    ]);
                }

                return null;
            }

            return Carbon::parse($paddleSubscription['current_billing_period']['ends_at']);
        }

        return $this->renews_at ?? $this->created_at->copy()->addMonthNoOverflow();
    }

    public function cancel(bool $cancelNow = false)
    {
        if ($this->hasPaymentCard()) {
            return parent::cancel($cancelNow);
        }

        if ($cancelNow) {
            $this->ends_at = now();
            $this->trial_ends_at = null;
            $this->status = self::STATUS_CANCELED;
        } elseif ($this->onTrial()) {
            $this->ends_at = $this->trial_ends_at;
            $this->status = self::STATUS_TRIALING;
        } else {
            $this->ends_at = $this->renewalDate();
        }

        $this->save();

        return $this;
    }

    public function cancelNow()
    {
        if ($this->hasPaymentCard()) {
            return parent::cancelNow();
        }

        $this->markAsCanceled();

        return $this;
    }

    public function cancelNowAndInvoice()
    {
        if ($this->hasPaymentCard()) {
            return parent::cancelNowAndInvoice();
        }

        $this->markAsCanceled();

        return $this;
    }

    public function resume($resumeAt = null)
    {
        // In Spike, we don't do subscription pausing, only cancellations.
        return $this->stopCancelation();
    }

    public function stopCancelation()
    {
        if ($this->hasPaymentCard()) {
            return parent::stopCancelation();
        }

        $this->fill([
            'status' => self::STATUS_ACTIVE,
            'ends_at' => null,
        ])->save();

        return $this;
    }

    public function hasPriceId(string $priceId): bool
    {
        return $this->items
            ->where('price_id', $priceId)
            ->isNotEmpty();
    }
}
