<?php

namespace Paymenter\Extensions\Others\DueDate;

use App\Classes\Extension\Extension;
use App\Events\InvoiceItem\Creating as InvoiceItemCreating;
use App\Events\Service\Created as ServiceCreated;
use App\Events\Service\Updated as ServiceUpdated;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class DueDate extends Extension
{
    /**
     * Track services processed in the current request (for invoice item description fix).
     *
     * @var array<int, array{prorata_ratio: float, new_expires_at: Carbon, base_date: Carbon}>
     */
    private array $processedServices = [];

    /**
     * Get all the configuration for the extension.
     *
     * @param  array  $values
     * @return array
     */
    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'dd_category_keyword',
                'label' => 'Category Keyword',
                'type' => 'text',
                'required' => false,
                'description' => 'When the product category name contains this keyword, the quarterly-to-monthly alignment logic will be triggered. Defaults to "Pro-rata" if left empty.',
            ],
        ];
    }

    /**
     * Check if a product matches the category keyword condition.
     */
    private function matchesCategory(Product $product): bool
    {
        $keyword = $this->config('dd_category_keyword');
        if (empty($keyword)) {
            $keyword = 'Pro-rata';
        }

        $category = $product->category;

        return $category && str_contains($category->name, $keyword);
    }

    /**
     * Calculate the pro-rata ratio for a quarterly plan aligned to end of month.
     * Standard period = actual calendar days in 3 months from base date.
     */
    private function calculateProrataRatio(Carbon $baseDate): array
    {
        $newExpiresAt = $baseDate->copy()->addMonthsNoOverflow(3)->endOfMonth();
        $actualDays = $baseDate->copy()->startOfDay()->diffInDays($newExpiresAt);
        // Use the actual number of days in 3 calendar months as the standard period
        $standardDays = $baseDate->copy()->startOfDay()->diffInDays($baseDate->copy()->addMonthsNoOverflow(3)->startOfDay());

        return [
            'ratio' => $actualDays / $standardDays,
            'actual_days' => $actualDays,
            'new_expires_at' => $newExpiresAt,
        ];
    }

    /**
     * Store the aligned expires_at date in the service's properties (database-persisted).
     */
    private function storeAlignedExpiresAt(Service $service, Carbon $expiresAt): void
    {
        $service->properties()->updateOrCreate(
            ['key' => 'duedate_aligned_expires_at'],
            ['value' => $expiresAt->toDateTimeString(), 'name' => 'DueDate Aligned Expires At']
        );
    }

    /**
     * Retrieve the aligned expires_at date from the service's properties.
     */
    private function getAlignedExpiresAt(Service $service): ?Carbon
    {
        $prop = $service->properties()->where('key', 'duedate_aligned_expires_at')->first();
        if ($prop) {
            return Carbon::parse($prop->value);
        }

        return null;
    }

    /**
     * Remove the aligned expires_at property after it has been consumed.
     */
    private function clearAlignedExpiresAt(Service $service): void
    {
        $service->properties()->where('key', 'duedate_aligned_expires_at')->delete();
    }

    /**
     * Boot the extension: register event listeners.
     */
    public function boot()
    {
        // 1. Hide monthly plans from frontend for products matching the category keyword.
        Event::listen('plans.available', function ($product, $plans) {
            if (!($product instanceof Product) || !$this->matchesCategory($product)) {
                return null;
            }

            return $plans->filter(function ($plan) {
                if ($plan->type === 'free' || $plan->type === 'one-time') {
                    return true;
                }

                // Hide monthly plans - they are only for internal renewal use
                if ($plan->billing_unit === 'month' && $plan->billing_period === 1) {
                    return false;
                }

                return true;
            });
        });

        // 2. Adjust checkout/cart pricing for quarterly plans to show pro-rata price.
        Event::listen('checkout.pricing', function ($product, $plan, $total, $setup_fee) {
            if (!($product instanceof Product) || !$this->matchesCategory($product)) {
                return null;
            }

            if ($plan->billing_unit !== 'month' || $plan->billing_period !== 3) {
                return null;
            }

            $baseDate = Carbon::now();
            $prorata = $this->calculateProrataRatio($baseDate);

            return [
                'total' => round($total * $prorata['ratio'], 2),
                'setup_fee' => $setup_fee,
            ];
        });

        // 3. Listen for new service creation to align expires_at and switch to monthly plan.
        Event::listen(ServiceCreated::class, function (ServiceCreated $event) {
            $service = $event->service;
            $plan = $service->plan;

            if (!$plan || $plan->type === 'free' || $plan->type === 'one-time') {
                return;
            }

            if ($plan->billing_unit !== 'month' || $plan->billing_period !== 3) {
                return;
            }

            $product = $service->product;
            if (!$product || !$this->matchesCategory($product)) {
                return;
            }

            $baseDate = $service->created_at ? Carbon::parse($service->created_at) : Carbon::now();
            $prorata = $this->calculateProrataRatio($baseDate);
            $newExpiresAt = $prorata['new_expires_at'];
            $service->expires_at = $newExpiresAt;

            // Persist the aligned date to database so it survives across HTTP requests
            $this->storeAlignedExpiresAt($service, $newExpiresAt);

            // Also keep in memory for same-request invoice item description fix
            $this->processedServices[$service->id] = [
                'prorata_ratio' => $prorata['ratio'],
                'new_expires_at' => $newExpiresAt,
                'base_date' => $baseDate,
            ];

            Log::info("[DueDate] Pro-rata first period: {$prorata['actual_days']} actual days, ratio: {$prorata['ratio']}");

            // Switch to monthly plan for subsequent renewals
            $monthlyPlan = $product->plans()
                ->where('billing_unit', 'month')
                ->where('billing_period', 1)
                ->first();

            if ($monthlyPlan) {
                $service->plan_id = $monthlyPlan->id;
                $service->unsetRelation('plan');

                Log::info("[DueDate] Quarterly->Monthly alignment applied. Service #{$service->id}, expires_at: {$newExpiresAt->toDateString()}, switched to monthly plan #{$monthlyPlan->id}");
            } else {
                Log::warning("[DueDate] No monthly plan found for product #{$product->id}. Only aligned expires_at for Service #{$service->id} to {$newExpiresAt->toDateString()}");
            }

            $service->save();
        });

        // 4. Listen for invoice item creation to adjust the first invoice's description.
        Event::listen(InvoiceItemCreating::class, function (InvoiceItemCreating $event) {
            $invoiceItem = $event->invoiceItem;

            if ($invoiceItem->reference_type !== Service::class) {
                return;
            }

            $serviceId = $invoiceItem->reference_id;
            if (!isset($this->processedServices[$serviceId])) {
                return;
            }

            $info = $this->processedServices[$serviceId];

            $service = Service::find($serviceId);
            if ($service) {
                $startDate = $info['base_date']->format('M d, Y');
                $endDate = $info['new_expires_at']->format('M d, Y');
                $invoiceItem->description = $service->product->name . ' (' . $startDate . ' - ' . $endDate . ')';
            }

            Log::info("[DueDate] Adjusted first invoice item description for Service #{$serviceId}, price: {$invoiceItem->price}");
        });

        // 5. Listen for service updates to restore expires_at after payment activates the service.
        //    When payment is processed (a separate HTTP request), RenewServiceService recalculates
        //    expires_at using the (now monthly) plan, resulting in +1 month from now.
        //    We read the correct date from the database (properties) and restore it.
        Event::listen(ServiceUpdated::class, function (ServiceUpdated $event) {
            $service = $event->service;

            // Only act when service becomes active (payment completed)
            if ($service->status !== Service::STATUS_ACTIVE) {
                return;
            }

            // Check database for persisted aligned expires_at
            $correctExpiresAt = $this->getAlignedExpiresAt($service);
            if (!$correctExpiresAt) {
                return;
            }

            // Check if expires_at was changed away from our correct value
            if ($service->expires_at && $service->expires_at->format('Y-m-d') !== $correctExpiresAt->format('Y-m-d')) {
                Log::info("[DueDate] Correcting expires_at for Service #{$service->id} from {$service->expires_at->toDateString()} back to {$correctExpiresAt->toDateString()}");

                DB::table('services')
                    ->where('id', $service->id)
                    ->update(['expires_at' => $correctExpiresAt]);

                // Also update the in-memory model
                $service->expires_at = $correctExpiresAt;
            }

            // Clean up the property after successful correction
            $this->clearAlignedExpiresAt($service);
        });
    }
}
