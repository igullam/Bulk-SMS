<?php

namespace App\Services\Payment\Contracts;

interface UssdPaymentInterface
{
    /**
     * Initiate USSD payment request
     * Triggers USSD popup on user's phone
     *
     * @param string $phoneNumber User's phone number
     * @param float $amount Amount to charge
     * @param string|null $bundleType SMS bundle type (daily, monthly, yearly)
     * @return array Response with success status and transaction details
     */
    public function initiatePayment(
        string $phoneNumber,
        float $amount,
        string $bundleType = null
    ): array;

    /**
     * Handle USSD callback/response
     * Process user verification input from USSD prompt
     *
     * @param string $sessionId USSD session or transaction reference
     * @param string $userInput User's input (PIN or confirmation code)
     * @return array Response with verification status
     */
    public function handleUssdResponse(
        string $sessionId,
        string $userInput
    ): array;

    /**
     * Verify payment after USSD confirmation
     * Query provider's API for final payment status
     *
     * @param string $transactionRef Transaction reference
     * @return bool True if payment is verified/completed
     */
    public function verifyPayment(string $transactionRef): bool;

    /**
     * Check payment status
     * Get current status of a payment transaction
     *
     * @param string $transactionRef Transaction reference
     * @return array Payment status details
     */
    public function checkStatus(string $transactionRef): array;

    /**
     * Handle timeout/expiry
     * Mark payment as failed if user doesn't respond within timeout
     *
     * @param string $transactionRef Transaction reference
     * @return void
     */
    public function handleTimeout(string $transactionRef): void;
}
