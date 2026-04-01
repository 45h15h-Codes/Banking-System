<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CustomerController extends Controller
{
    use ApiResponse;

    /**
     * Create customer profile. Needs user_id from auth token.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'dob' => 'required|date|before:today',
            'pan_number' => 'nullable|string|size:10',
            'aadhaar_number' => 'nullable|string|size:12',
            'address' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        // Simulating getting user_id from token middleware (e.g. ServiceAuthMiddleware)
        $userId = $request->attributes->get('user_id');

        if (!$userId) {
            return $this->error('User ID not found in token', 401);
        }

        if (Customer::where('user_id', $userId)->exists()) {
            return $this->error('Customer profile already exists for this user', 409);
        }

        try {
            $customer = Customer::create([
                'user_id' => $userId,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'dob' => $request->dob,
                'pan_number' => $request->pan_number,
                'aadhaar_number' => $request->aadhaar_number,
                'address' => $request->address,
                'kyc_status' => 'pending',
            ]);

            return $this->success($customer, 'Customer profile created successfully', 201);
        } catch (\Exception $e) {
            return $this->error('Failed to create customer profile', 500, $e->getMessage());
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

        if (!$customer) {
            return $this->error('Customer profile not found', 404);
        }

        return $this->success($customer, 'Profile retrieved successfully');
    }

    /**
     * Update current user's profile.
     */
    public function update(Request $request)
    {
        $userId = $request->attributes->get('user_id');

        $customer = Customer::where('user_id', $userId)->first();

        if (!$customer) {
            return $this->error('Customer profile not found', 404);
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'address' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $customer->update($request->only('first_name', 'last_name', 'address'));
            return $this->success($customer, 'Profile updated successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to update profile', 500, $e->getMessage());
        }
    }

    /**
     * Get KYC status of a Specific customer.
     */
    public function kycStatus($id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return $this->error('Customer not found', 404);
        }

        return $this->success(['status' => $customer->kyc_status], 'KYC status retrieved');
    }
}
