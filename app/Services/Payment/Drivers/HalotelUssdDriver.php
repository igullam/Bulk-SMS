<?php

namespace App\Services\Payment\Drivers;

use App\Models\UssdPayment;
use App\Services\Payment\Contracts\UssdPaymentInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class HalotelUssdDriver implements UssdPaymentInterface
{
    protected $apiUrl;
    protected $apiKey;
    protected $apiSecret;
    protected $merchantId;

    public function __construct()
    {
        $this->apiKey = config('payment.halotel.api_key');
        $this->apiSecret = config('payment.halotel.api_secret');
        $this->merchantId = config('payment.halotel.merchant_id');
        $this->apiUrl = config('payment.halotel.api_url', 'https://api.halotel.co.tz');
    }

    /**
     * Initiate USSD payment
     */
    public function initiatePayment(
        string $phoneNumber,
        float $amount,
        string $bundleType = null
    ): array {
        try {
            $transactionRef = 'HALOTEL-' . Str::uuid();
            $verificationToken = Str::random(32);
            $wallet = auth()->user()->wallet;

            // Create payment record
            $ussdPayment = UssdPayment::create([
                'user_id' => auth()->id(),
                'wallet_id' => $wallet->id,
                'provider' => 'halotel',
                'phone_number' => $phoneNumber,
                'amount' => $amount,
                'bundle_type' => $bundleType,
                'transaction_ref' => $transactionRef,
                'verification_token' => $verificationToken,
                'status' => 'pending',
            ]);

            // Trigger Halotel USSD
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl . '/v1/payment/ussd', [
                'msisdn' => $this->formatPhoneNumber($phoneNumber),
                'amount' => intval($amount),
                'transaction_id' => $transactionRef,
                'merchant_id' => $this->merchantId,
                'service_name' => "SMS Bundle: {$bundleType}",
                'callback_url' => route('api.payment.halotel.callback'),
            ])->json();

            Log::info('Halotel USSD Response', $response);

            if ($response['success'] ?? false) {
                $ussdPayment->update([
                    'status' => 'ussd_sent',
                    'ussd_sent_at' => now(),
                    'ussd_response' => $response,
                ]);

                return [
                    'success' => true,
                    'message' => 'USSD prompt sent. Please verify the payment.',
                    'transaction_ref' => $transactionRef,
                    'user_phone' => $this->maskPhoneNumber($phoneNumber),
                    'amount' => $amount,
                    'provider' => 'halotel',
                ];
            }

            throw new \Exception($response['message'] ?? 'USSD trigger failed');

        } catch (\Exception $e) {
            Log::error('Halotel USSD initiation failed', [
                'error' => $e->getMessage(),
                'phone' => $phoneNumber,
            ]);
            return ['success' => false, 'message' => 'Failed to send USSD prompt'];
        }
    }

    /**
     * Handle USSD callback/response
     */
    public function handleUssdResponse(string $sessionId, string $userInput): array
    {
        try {
            $ussdPayment = UssdPayment::where('transaction_ref', $sessionId)->first();

            if (!$ussdPayment) {
                return ['success' => false, 'message' => 'Payment session not found'];
            }

            if (!empty($userInput)) {
                $ussdPayment->markAsVerified();
                return ['success' => true, 'message' => 'Payment verified'];
            }

            $ussdPayment->markAsFailed();
            return ['success' => false, 'message' => 'Verification failed'];

        } catch (\Exception $e) {
            Log::error('Halotel USSD response handling failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error processing response'];
        }
    }

    /**
     * Verify payment
     */
    public function verifyPayment(string $transactionRef): bool
    {
        try {
            $ussdPayment = UssdPayment::where('transaction_ref', $transactionRef)
                ->where('status', 'verified')
                ->first();

            if (!$ussdPayment) {
                return false;
            }

            // Query Halotel for transaction status
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
            ])->get($this->apiUrl . "/v1/payment/status/{$transactionRef}")->json();

            if (($response['status'] ?? '') === 'COMPLETED' || $response['success'] ?? false) {
                $ussdPayment->markAsCompleted();
                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('Halotel payment verification failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Check payment status
     */
    public function checkStatus(string $transactionRef): array
    {
        $ussdPayment = UssdPayment::where('transaction_ref', $transactionRef)->first();

        if (!$ussdPayment) {
            return ['status' => 'not_found'];
        }

        return [
            'status' => $ussdPayment->status,
            'amount' => $ussdPayment->amount,
            'phone' => $this->maskPhoneNumber($ussdPayment->phone_number),
            'created_at' => $ussdPayment->created_at,
            'completed_at' => $ussdPayment->completed_at,
        ];
    }

    /**
     * Handle timeout
     */
    public function handleTimeout(string $transactionRef): void
    {
        $ussdPayment = UssdPayment::where('transaction_ref', $transactionRef)->first();
        if ($ussdPayment) {
            $ussdPayment->markAsExpired();
            Log::warning('Halotel USSD payment timeout', ['transaction_ref' => $transactionRef]);
        }
    }

    // Helper methods
    private function getAccessToken(): string
    {
        try {
            $response = Http::post($this->apiUrl . '/v1/auth/login', [
                'api_key' => $this->apiKey,
                'api_secret' => $this->apiSecret,
            ])->json();

            return $response['token'] ?? '';
        } catch (\Exception $e) {
            Log::error('Failed to get Halotel access token', ['error' => $e->getMessage()]);
            return '';
        }
    }

    private function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/^\+?/', '', $phone);
        if (substr($phone, 0, 1) === '0') {
            $phone = '255' . substr($phone, 1);
        }
        return $phone;
    }

    private function maskPhoneNumber(string $phone): string
    {
        return substr($phone, 0, 4) . '****' . substr($phone, -2);
    }
}
