<?php

namespace Paymenter\Extensions\Others\DueDate;

use App\Classes\Extension\Extension;
use App\Events\InvoiceItem\Creating as InvoiceItemCreating;
use App\Events\Service\Created as ServiceCreated;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class DueDate extends Extension
{
    /**
     * Track services that have been processed by this extension,
     * so we can adjust their first invoice item accordingly.
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
                'name' => 'category_keyword',
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
        $keyword = $this->config('category_keyword');
        if (empty($keyword)) {
            $keyword = 'Pro-rata';
        }

        $category = $product->category;

        return $category && str_contains($category->name, $keyword);
    }

    /**
     * Calculate the pro-rata ratio for a quarterly plan aligned to end of month.
     * expires_at is set to the 1st of the next month at 00:00 so the last day of the month is fully included.
     * Standard period = actual calendar days in 3 months from base date.
     */
    private function calculateProrataRatio(Carbon $baseDate): array
    {
        // Set expires_at to 1st of next month 00:00, so the entire last day of the month is covered
        $newExpiresAt = $baseDate->copy()->addMonths(3)->endOfMonth()->addDay()->startOfDay();
        $actualDays = $baseDate->copy()->startOfDay()->diffInDays($newExpiresAt);
        // Use the actual number of days in 3 calendar months as the standard period
        $standardDays = $baseDate->copy()->startOfDay()->diffInDays($baseDate->copy()->addMonths(3)->startOfDay());

        return [
            'ratio' => $actualDays / $standardDays,
            'actual_days' => $actualDays,
            'new_expires_at' => $newExpiresAt,
        ];
    }

    /**
     * Boot the extension: register event listeners.
     */
    public function boot()
    {
        // 1. Hide monthly plans from frontend for products matching the category keyword.
        //    Monthly plans are only used internally after the first quarterly period.
        Event::listen('plans.available', function ($product, $plans) {
            if (!($product instanceof Product) || !$this->matchesCategory($product)) {
                return null;
            }

            // Filter out monthly plans (billing_unit=month, billing_period=1)
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

            // Only adjust quarterly plans
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

            // Only handle quarterly plans (billing_unit = month, billing_period = 3)
            if ($plan->billing_unit !== 'month' || $plan->billing_period !== 3) {
                return;
            }

            $product = $service->product;
            if (!$product || !$this->matchesCategory($product)) {
                return;
            }

            // Align expires_at to end of month, 3 months from creation date
            $baseDate = $service->created_at ? Carbon::parse($service->created_at) : Carbon::now();
            $prorata = $this->calculateProrataRatio($baseDate);
            $newExpiresAt = $prorata['new_expires_at'];
            $service->expires_at = $newExpiresAt;

            // Store info so InvoiceItem\Creating listener can adjust the first invoice
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

        // 4. Listen for invoice item creation to adjust the first invoice's price and description.
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

            // The invoice item price already comes from the cart's pro-rata calculated total,
            // so we only need to fix the description — no price adjustment needed.

            // Fix the description to show the correct date range
            $service = Service::find($serviceId);
            if ($service) {
                $startDate = $info['base_date']->format('M d, Y');
                // Display the last service day (expires_at minus 1 day) since expires_at is the 1st of next month
                $endDate = $info['new_expires_at']->copy()->subDay()->format('M d, Y');
                $invoiceItem->description = $service->product->name . ' (' . $startDate . ' - ' . $endDate . ')';
            }

            Log::info("[DueDate] Adjusted first invoice item description for Service #{$serviceId}, price: {$invoiceItem->price}");

            // Clean up - only apply once
            unset($this->processedServices[$serviceId]);
        });
    }
}
