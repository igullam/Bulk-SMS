<?php

namespace App\Services\Payment\Drivers;

use App\Models\UssdPayment;
use App\Models\Wallet;
use App\Services\Payment\Contracts\UssdPaymentInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class MpesaUssdDriver implements UssdPaymentInterface
{
    protected $apiUrl;
    protected $mpesaCode = '*123*1*1#';
    protected $consumerKey;
    protected $consumerSecret;
    protected $businessShortCode;
    protected $passkey;
    protected $initiator;
    protected $initiatorPassword;

    public function __construct()
    {
        $this->consumerKey = config('payment.mpesa.consumer_key');
        $this->consumerSecret = config('payment.mpesa.consumer_secret');
        $this->businessShortCode = config('payment.mpesa.business_shortcode');
        $this->passkey = config('payment.mpesa.passkey');
        $this->initiator = config('payment.mpesa.initiator');
        $this->initiatorPassword = config('payment.mpesa.initiator_password');
        
        $sandbox = config('payment.mpesa.sandbox', true);
        $this->apiUrl = $sandbox 
            ? 'https://sandbox.safaricom.co.ke' 
            : 'https://api.safaricom.co.ke';
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
            $transactionRef = 'MPESA-' . Str::uuid();
            $verificationToken = Str::random(32);
            $wallet = auth()->user()->wallet;

            // Create USSD payment record
            $ussdPayment = UssdPayment::create([
                'user_id' => auth()->id(),
                'wallet_id' => $wallet->id,
                'provider' => 'mpesa',
                'phone_number' => $phoneNumber,
                'amount' => $amount,
                'bundle_type' => $bundleType,
                'transaction_ref' => $transactionRef,
                'verification_token' => $verificationToken,
                'ussd_code' => $this->mpesaCode,
                'status' => 'pending',
            ]);

            // Get M-Pesa access token
            $token = $this->getAccessToken();

            if (!$token) {
                throw new \Exception('Failed to get access token');
            }

            // Prepare STK Push / USSD trigger
            $timestamp = now()->format('YmdHis');
            $password = base64_encode(
                $this->businessShortCode . $this->passkey . $timestamp
            );

            // Trigger USSD/STK on user's phone
            $response = Http::withToken($token)
                ->post($this->apiUrl . '/mpesa/stkpush/v1/processrequest', [
                    'BusinessShortCode' => $this->businessShortCode,
                    'Password' => $password,
                    'Timestamp' => $timestamp,
                    'TransactionType' => 'CustomerPayBillOnline',
                    'Amount' => intval($amount),
                    'PartyA' => $this->formatPhoneNumber($phoneNumber),
                    'PartyB' => $this->businessShortCode,
                    'PhoneNumber' => $this->formatPhoneNumber($phoneNumber),
                    'CallBackURL' => route('api.payment.mpesa.callback'),
                    'AccountReference' => $transactionRef,
                    'TransactionDesc' => "SMS Bundle: {$bundleType}",
                ])->json();

            Log::info('M-Pesa STK Push Response', $response);

            if ($response['ResponseCode'] === '0') {
                $ussdPayment->update([
                    'status' => 'ussd_sent',
                    'ussd_sent_at' => now(),
                    'ussd_response' => $response,
                ]);

                return [
                    'success' => true,
                    'message' => 'USSD prompt sent to your phone. Please enter your M-Pesa PIN to verify the payment.',
                    'transaction_ref' => $transactionRef,
                    'user_phone' => $this->maskPhoneNumber($phoneNumber),
                    'amount' => $amount,
                    'provider' => 'mpesa',
                ];
            }

            throw new \Exception($response['ResponseDescription'] ?? 'USSD trigger failed');

        } catch (\Exception $e) {
            Log::error('M-Pesa USSD initiation failed', [
                'error' => $e->getMessage(),
                'phone' => $phoneNumber,
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to send USSD prompt. Please try again.',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle USSD callback response
     */
    public function handleUssdResponse(
        string $sessionId,
        string $userInput
    ): array {
        try {
            $ussdPayment = UssdPayment::where('transaction_ref', $sessionId)->first();

            if (!$ussdPayment) {
                return ['success' => false, 'message' => 'Payment session not found'];
            }

            // Validate user input (expected: confirmation)
            if (!empty($userInput)) {
                $ussdPayment->update([
                    'status' => 'verified',
                    'verified_at' => now(),
                ]);

                return [
                    'success' => true,
                    'message' => 'Payment verified. Please wait for confirmation.',
                ];
            } else {
                $ussdPayment->markAsFailed();

                return [
                    'success' => false,
                    'message' => 'Payment verification failed.',
                ];
            }

        } catch (\Exception $e) {
            Log::error('USSD response handling failed', [
                'error' => $e->getMessage(),
                'session' => $sessionId,
            ]);

            return ['success' => false, 'message' => 'Error processing response'];
        }
    }

    /**
     * Verify payment via callback from M-Pesa
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

            // Query M-Pesa transaction status API
            $token = $this->getAccessToken();
            $timestamp = now()->format('YmdHis');
            $password = base64_encode(
                $this->businessShortCode . $this->passkey . $timestamp
            );

            $response = Http::withToken($token)
                ->post($this->apiUrl . '/mpesa/transactionstatus/v1/query', [
                    'Initiator' => $this->initiator,
                    'SecurityCredential' => $this->encryptCredential(),
                    'CommandID' => 'TransactionStatusQuery',
                    'TransactionID' => $transactionRef,
                    'PartyA' => $this->businessShortCode,
                    'IdentifierType' => 4,
                    'ResultURL' => route('api.payment.mpesa.result'),
                    'QueueTimeOutURL' => route('api.payment.mpesa.timeout'),
                    'Remarks' => 'Payment verification',
                ])->json();

            if (isset($response['ResultCode']) && $response['ResultCode'] === '0') {
                $ussdPayment->markAsCompleted();
                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('Payment verification failed', ['error' => $e->getMessage()]);
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
            Log::warning('USSD payment timeout', ['transaction_ref' => $transactionRef]);
        }
    }

    // Helper methods
    private function getAccessToken(): ?string
    {
        try {
            $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
                ->get($this->apiUrl . '/oauth/v1/generate?grant_type=client_credentials')
                ->json();

            return $response['access_token'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to get M-Pesa access token', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/^\+?/', '', $phone);
        if (substr($phone, 0, 1) === '0') {
            $phone = '254' . substr($phone, 1);
        }
        return $phone;
    }

    private function maskPhoneNumber(string $phone): string
    {
        return substr($phone, 0, 4) . '****' . substr($phone, -2);
    }

    private function encryptCredential(): string
    {
        // TODO: Implement M-Pesa certificate encryption
        return base64_encode($this->initiatorPassword);
    }
}
