<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\Plans\CreateRequest;
use App\Http\Requests\Admin\Plans\UpdateRequest;
use App\Models\Plan;
use App\Services\PlanService;
use App\Services\VendorSubscriptionPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlanController extends Controller
{
    protected PlanService $service;
    protected VendorSubscriptionPaymentService $vendorSubscriptionPaymentService;

    public function __construct(PlanService $service, VendorSubscriptionPaymentService $vendorSubscriptionPaymentService)
    {
        $this->service = $service;
        $this->vendorSubscriptionPaymentService = $vendorSubscriptionPaymentService;
    }

    public function index(Request $request): View|JsonResponse
    {
        $filters = [
            'search' => $request->get('search', ''),
            'status' => $request->get('status', ''),
            'featured' => $request->get('featured', ''),
        ];

        $plans = $this->service->getPaginatedPlans(15, $filters);

        // If AJAX request, return JSON
        if (request()->ajax() || request()->wantsJson()) {
            return response()->json([
                'html' => view('admin.plans.partials.table', compact('plans'))->render(),
                'pagination' => view('admin.plans.partials.pagination', compact('plans'))->render(),
            ]);
        }

        return view('admin.plans.index', compact('plans', 'filters'));
    }

    public function create(): View
    {
        return view('admin.plans.create');
    }

    public function store(CreateRequest $request): RedirectResponse
    {

        try {
            $this->service->createPlan($request);

            return redirect()->route('plans.index')
                ->with('success', 'Plan created successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to create plan: '.$e->getMessage());
        }
    }

    public function show(Plan $plan): View
    {
        $plan = $this->service->getPlanById($plan->id);

        return view('admin.plans.show', compact('plan'));
    }

    public function edit(Plan $plan): View
    {
        $plan = $this->service->getPlanById($plan->id);

        return view('admin.plans.edit', compact('plan'));
    }

    public function update(UpdateRequest $request, Plan $plan): RedirectResponse
    {
        try {
            $this->service->updatePlan($request, $plan);

            return redirect()->route('plans.index')
                ->with('success', 'Plan updated successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to update plan: '.$e->getMessage());
        }
    }

    public function destroy(Plan $plan): RedirectResponse|JsonResponse
    {
        try {
            $this->service->deletePlan($plan);

            if (request()->wantsJson() || request()->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => __('Plan deleted successfully.'),
                ]);
            }

            return redirect()->route('plans.index')
                ->with('success', 'Plan deleted successfully.');
        } catch (\Exception $e) {
            if (request()->wantsJson() || request()->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => __('Failed to delete plan: :error', ['error' => $e->getMessage()]),
                ], 422);
            }

            return redirect()->back()
                ->with('error', 'Failed to delete plan: '.$e->getMessage());
        }
    }

    public function vendorIndex(): View
    {
        $plans = $this->service->getPaginatedPlans(15, []);

        return view('vendor.plans.index', compact('plans'));
    }

    public function subscribe(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'immediate' => 'sometimes|boolean',
            'payment_method' => 'sometimes|string|in:paymob',
        ]);

        try {
            $paymentMethod = (string) $request->input('payment_method', 'paymob');
            $result = $this->vendorSubscriptionPaymentService->initiateForUser(
                user: auth()->user(),
                planId: (int) $request->plan_id,
                immediate: $request->boolean('immediate', true),
                paymentMethod: $paymentMethod
            );

            return response()->json([
                'success' => true,
                'message' => $result['already_initiated']
                    ? __('Payment already initiated for this subscription request.')
                    : __('Payment initiated successfully.'),
                'data' => [
                    'subscription_payment_request_id' => $result['request']->id,
                    'payment' => [
                        'gateway' => $result['gateway'],
                        'status' => $result['result']->status,
                        'transaction_id' => $result['result']->transactionId,
                        'redirect_url' => $result['result']->redirectUrl,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function check(Request $request)
    {
        $plan = Plan::findOrFail($request->plan_id);
        $vendor = auth()->user()->vendor();

        $featured_count = $vendor->products()->featured()->count();
        $current_products = $vendor->products()->active()->count();

        // Check if this is a downgrade
        $isDowngrade = false;
        $currentSubscription = $vendor->activeSubscription();
        if ($currentSubscription && $currentSubscription->plan) {
            $currentPlan = $currentSubscription->plan;

            // Compare by price
            if ($plan->getRawOriginal('price') < $currentPlan->getRawOriginal('price')) {
                $isDowngrade = true;
            }

            // Compare by features
            if ($currentPlan->can_feature_products && ! $plan->can_feature_products) {
                $isDowngrade = true;
            }

            // Compare product limits
            $currentMax = $currentPlan->max_products_count;
            $newMax = $plan->max_products_count;

            if ($currentMax === null && $newMax !== null) {
                $isDowngrade = true;
            }

            if ($currentMax !== null && $newMax !== null && $currentMax > $newMax) {
                $isDowngrade = true;
            }
        }

        return response()->json([
            'can_feature_products' => $plan->can_feature_products,
            'max_products_count' => $plan->max_products_count,
            'featured_count' => $featured_count,
            'current_products' => $current_products,
            'is_downgrade' => $isDowngrade,
            'has_active_subscription' => $currentSubscription !== null,
        ]);
    }
}
