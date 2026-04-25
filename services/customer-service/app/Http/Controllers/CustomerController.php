<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Throwable;

class CustomerController extends Controller
{
    use ApiResponse;

    /**
     * Create customer profile. Needs user_id from auth token.
     */
    public function store(StoreCustomerRequest $request)
    {
        $userId = $request->attributes->get('user_id');

        if (! $userId) {
            return $this->error('User ID not found in token', 401);
        }

        if (Customer::where('user_id', $userId)->exists()) {
            return $this->error('Customer profile already exists for this user', 409);
        }

        try {
            $customer = Customer::create(array_merge(
                $request->validated(),
                [
                    'user_id' => $userId,
                    'kyc_status' => 'pending',
                ],
            ));

            return $this->success($customer, 'Customer profile created successfully', 201);
        } catch (Throwable) {
            return $this->error('Failed to create customer profile', 500);
        }
    }

    /**
     * Get current user's profile
     */
    public function me(Request $request)
    {
        $userId = $request->attributes->get('user_id');

        $customer = Customer::where('user_id', $userId)
            ->with(['kycDocuments', 'kycAuditLogs'])
            ->first();

        if (! $customer) {
            return $this->error('Customer profile not found', 404);
        }

        return $this->success($customer, 'Profile retrieved successfully');
    }

    /**
     * Update current user's profile.
     */
    public function update(UpdateCustomerRequest $request)
    {
        $userId = $request->attributes->get('user_id');

        $customer = Customer::where('user_id', $userId)->first();

        if (! $customer) {
            return $this->error('Customer profile not found', 404);
        }

        try {
            $customer->update($request->validated());

            return $this->success($customer, 'Profile updated successfully');
        } catch (Throwable) {
            return $this->error('Failed to update profile', 500);
        }
    }

    /**
     * Get KYC status of a Specific customer.
     */
    public function kycStatus(Request $request, string $id)
    {
        $customer = Customer::find($id);

        if (! $customer) {
            return $this->error('Customer not found', 404);
        }

        if ($request->attributes->get('user_role') === 'customer'
            && $customer->user_id !== $request->attributes->get('user_id')) {
            return $this->error('Forbidden. You cannot access another customer KYC status.', 403);
        }

        return $this->success(['status' => $customer->kyc_status], 'KYC status retrieved');
    }
}
