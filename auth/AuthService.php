<?php

function auth_base64url_encode($data)
{
    return rtrim(strtr(base64_encode((string) $data), '+/', '-_'), '=');
}

function auth_base64url_decode($data)
{
    $input = strtr((string) $data, '-_', '+/');
    $padding = strlen($input) % 4;
    if ($padding > 0) {
        $input .= str_repeat('=', 4 - $padding);
    }
    return base64_decode($input, true);
}

function auth_jwt_secret()
{
    $envSecret = getenv('JWT_SECRET');
    if (is_string($envSecret) && trim($envSecret) !== '') {
        return trim($envSecret);
    }
    return 'charging-aiot-jwt-secret-change-in-production';
}

function auth_jwt_issuer()
{
    return 'charging-aiot';
}

function auth_extract_bearer_token()
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authorization = '';

    if (is_array($headers)) {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'authorization') {
                $authorization = (string) $value;
                break;
            }
        }
    }

    if ($authorization === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authorization = (string) $_SERVER['HTTP_AUTHORIZATION'];
    }

    if ($authorization === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authorization = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $authorization, $matches)) {
        return trim((string) $matches[1]);
    }

    return '';
}

function auth_issue_jwt(array $claims, $expireSeconds = 7200)
{
    $now = time();
    $exp = $now + max(60, intval($expireSeconds));

    $payload = array_merge($claims, [
        'iss' => auth_jwt_issuer(),
        'iat' => $now,
        'exp' => $exp
    ]);

    $headerEncoded = auth_base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payloadEncoded = auth_base64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, auth_jwt_secret(), true);
    $signatureEncoded = auth_base64url_encode($signature);

    return [
        'token' => $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded,
        'expiresAt' => $exp
    ];
}

function auth_verify_jwt($token)
{
    $jwt = trim((string) $token);
    if ($jwt === '') {
        return null;
    }

    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }

    list($headerPart, $payloadPart, $signaturePart) = $parts;
    $headerRaw = auth_base64url_decode($headerPart);
    $payloadRaw = auth_base64url_decode($payloadPart);
    $signatureRaw = auth_base64url_decode($signaturePart);

    if ($headerRaw === false || $payloadRaw === false || $signatureRaw === false) {
        return null;
    }

    $header = json_decode($headerRaw, true);
    $payload = json_decode($payloadRaw, true);
    if (!is_array($header) || !is_array($payload)) {
        return null;
    }

    if (!isset($header['alg']) || strtoupper((string) $header['alg']) !== 'HS256') {
        return null;
    }

    $expectedSignature = hash_hmac('sha256', $headerPart . '.' . $payloadPart, auth_jwt_secret(), true);
    if (!hash_equals($expectedSignature, $signatureRaw)) {
        return null;
    }

    if (!isset($payload['iss']) || (string) $payload['iss'] !== auth_jwt_issuer()) {
        return null;
    }

    $now = time();
    if (!isset($payload['exp']) || intval($payload['exp']) < $now) {
        return null;
    }

    return $payload;
}

function auth_respond_unauthorized($message = 'Unauthorized', $httpCode = 401)
{
    http_response_code(intval($httpCode));
    echo json_encode([
        'code' => 0,
        'msg' => (string) $message,
        'data' => null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function auth_require_jwt(array $roles = [], callable $onError = null)
{
    $token = auth_extract_bearer_token();
    if ($token === '') {
        if ($onError !== null) {
            $onError('Missing authorization token', 401);
            exit;
        }
        auth_respond_unauthorized('Missing authorization token', 401);
    }

    $payload = auth_verify_jwt($token);
    if (!is_array($payload)) {
        if ($onError !== null) {
            $onError('Invalid or expired token', 401);
            exit;
        }
        auth_respond_unauthorized('Invalid or expired token', 401);
    }

    if (!empty($roles)) {
        $roleClaim = $payload['role'] ?? null;
        $allowed = false;
        foreach ($roles as $candidate) {
            if (is_numeric($candidate) || is_int($candidate)) {
                if (intval($roleClaim) === intval($candidate)) {
                    $allowed = true;
                    break;
                }
                continue;
            }
            $role = strtolower((string) $roleClaim);
            if ($role === strtolower((string) $candidate)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            if ($onError !== null) {
                $onError('Permission denied', 403);
                exit;
            }
            auth_respond_unauthorized('Permission denied', 403);
        }
    }

    return $payload;
}

function auth_normalize_password_digest($inputPassword)
{
    $value = trim((string) $inputPassword);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^[a-fA-F0-9]{64}$/', $value) === 1) {
        return strtolower($value);
    }
    return hash('sha256', $value);
}

function auth_hash_password_for_storage($inputPassword)
{
    $digest = auth_normalize_password_digest($inputPassword);
    if ($digest === '') {
        return '';
    }
    return password_hash($digest, PASSWORD_BCRYPT);
}

function auth_verify_password($inputPassword, $storedPasswordHash)
{
    $digest = auth_normalize_password_digest($inputPassword);
    if ($digest === '') {
        return false;
    }
    return password_verify($digest, (string) $storedPasswordHash);
}
