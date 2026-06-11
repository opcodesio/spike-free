<?php

namespace Opcodes\Spike\Stripe;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\PromotionCode;
use Opcodes\Spike\Contracts\SpikeSubscription;
use Opcodes\Spike\Database\Factories\Stripe\SubscriptionFactory;
use Opcodes\Spike\Facades\PaymentGateway as PaymentGatewayFacade;

class Subscription extends \Laravel\Cashier\Subscription implements SpikeSubscription
{
    use HasFactory;

    protected $table = 'stripe_subscriptions';

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

    public function getBillable()
    {
        return $this->owner;
    }

    public function getForeignKey()
    {
        return 'stripe_subscription_id';
    }

    public function getPriceId(): string
    {
        return $this->stripe_price;
    }

    public function isPastDue(): bool
    {
        return $this->stripe_status === \Stripe\Subscription::STATUS_PAST_DUE;
    }

    public function getPromotionCodeId(): ?string
    {
        return $this->promotion_code_id;
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
        return ! empty($this->stripe_id);
    }

    public function hasPromotionCode(): bool
    {
        return !is_null($this->promotionCode());
    }

    public function promotionCode(): ?PromotionCode
    {
        if ($this->promotion_code_id) {
            $cacheKey = 'spike.stripe_subscription_'.$this->id.'_promotion_code';
            $promotionCode = Cache::driver('array')->remember($cacheKey, 10, function () {
                return PaymentGatewayFacade::findStripePromotionCode($this->promotion_code_id);
            });

            if (is_null($promotionCode)) {
                // such promotion code doesn't exist anymore, let's unset it.
                $this->promotion_code_id = null;
                $this->save();

                Cache::driver('array')->forget($cacheKey);
            }

            return $promotionCode;
        }

        return null;
    }

    public function renewalDate(): ?CarbonInterface
    {
        if ($this->onGracePeriod()) {
            return $this->ends_at;
        }

        if ($this->hasPaymentCard()) {
            return Carbon::createFromTimestamp(
                $this->asStripeSubscription()->current_period_end
            );
        }

        return $this->renews_at ?? $this->created_at->copy()->addMonthNoOverflow();
    }

    public function cancel(bool $cancelNow = false)
    {
        if ($cancelNow) {
            return $this->cancelNow();
        }

        if ($this->hasPaymentCard()) {
            return parent::cancel();
        }

        if ($this->onTrial()) {
            $this->ends_at = $this->trial_ends_at;
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

    public function stopCancelation()
    {
        return $this->resume();
    }

    public function resume($resumeAt = null)
    {
        if ($this->hasPaymentCard()) {
            return parent::resume();
        }

        $this->fill([
            'stripe_status' => \Stripe\Subscription::STATUS_ACTIVE,
            'ends_at' => null,
        ])->save();

        return $this;
    }

    public function hasPriceId(string $priceId): bool
    {
        return $this->stripe_price === $priceId
            || $this->items->where('stripe_price', $priceId)->isNotEmpty();
    }
}
