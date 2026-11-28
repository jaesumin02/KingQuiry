<?php

function kq_normalize_ph_mobile(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', trim($phone));
    if (!is_string($digits)) {
        return null;
    }

    if (preg_match('/\A09[0-9]{9}\z/', $digits)) {
        $digits = substr($digits, 1);
    } elseif (preg_match('/\A639[0-9]{9}\z/', $digits)) {
        $digits = substr($digits, 2);
    }

    return preg_match('/\A9[0-9]{9}\z/', $digits)
        ? '63' . $digits
        : null;
}

function kq_semaphore_is_configured(): bool
{
    $apiKey = getenv('KINGQUIRY_SEMAPHORE_API_KEY');
    return is_string($apiKey) && trim($apiKey) !== '';
}

function kq_send_semaphore_otp(string $phone, string $code): bool
{
    if (!preg_match('/\A639[0-9]{9}\z/', $phone) || !preg_match('/\A[0-9]{6}\z/', $code)) {
        throw new InvalidArgumentException('A normalized Philippines mobile number and six-digit OTP are required.');
    }

    $apiKey = getenv('KINGQUIRY_SEMAPHORE_API_KEY');
    if (!is_string($apiKey) || trim($apiKey) === '') {
        error_log('KingQuiry Semaphore SMS is unavailable because KINGQUIRY_SEMAPHORE_API_KEY is not configured.');
        return false;
    }
    if (!function_exists('curl_init')) {
        error_log('KingQuiry Semaphore SMS is unavailable because the PHP cURL extension is not enabled.');
        return false;
    }

    $payload = [
        'apikey' => trim($apiKey),
        'number' => $phone,
        'message' => 'Your KingQuiry verification code is {otp}. It expires in 10 minutes.',
        'code' => $code,
    ];
    $senderName = getenv('KINGQUIRY_SEMAPHORE_SENDERNAME');
    if (is_string($senderName) && trim($senderName) !== '') {
        $payload['sendername'] = trim($senderName);
    }

    $curl = curl_init('https://api.semaphore.co/api/v4/otp');
    if ($curl === false) {
        error_log('KingQuiry Semaphore SMS request could not be initialized.');
        return false;
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $response = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlErrorNumber = curl_errno($curl);
    unset($curl);

    if (!is_string($response) || $curlErrorNumber !== 0 || $httpStatus < 200 || $httpStatus >= 300) {
        error_log('KingQuiry Semaphore SMS request failed (HTTP ' . $httpStatus . ', cURL ' . $curlErrorNumber . ').');
        return false;
    }

    $result = json_decode($response, true);
    $messageResult = is_array($result) && isset($result[0]) && is_array($result[0])
        ? $result[0]
        : null;
    $status = is_array($messageResult) && is_string($messageResult['status'] ?? null)
        ? strtolower((string) $messageResult['status'])
        : '';

    if (!in_array($status, ['queued', 'pending', 'sent'], true)) {
        error_log('KingQuiry Semaphore did not accept the OTP message.');
        return false;
    }

    return true;
}
