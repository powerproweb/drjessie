<?php
declare(strict_types=1);

/**
 * Lightweight file-backed rate limiting helper.
 * Stores rolling-window request timestamps under uploads/rate_limit.
 */

function drj_rate_limit_client_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return $ip === '' ? 'unknown' : $ip;
}

/**
 * @return array{allowed: bool, retry_after: int}
 */
function drj_rate_limit_check(string $bucket, string $identifier, int $maxRequests, int $windowSeconds): array
{
    if ($maxRequests <= 0 || $windowSeconds <= 0) {
        return ['allowed' => true, 'retry_after' => 0];
    }

    $storageDir = __DIR__ . '/../uploads/rate_limit';
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }

    $now = time();
    $key = hash('sha256', $bucket . '|' . $identifier);
    $filePath = $storageDir . '/' . $key . '.json';
    $handle = @fopen($filePath, 'c+');
    if ($handle === false) {
        return ['allowed' => true, 'retry_after' => 0];
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return ['allowed' => true, 'retry_after' => 0];
    }

    $raw = stream_get_contents($handle);
    $decoded = json_decode((string) $raw, true);
    $timestamps = is_array($decoded) ? $decoded : [];

    $windowStart = $now - $windowSeconds;
    $hits = [];
    foreach ($timestamps as $value) {
        $ts = (int) $value;
        if ($ts >= $windowStart) {
            $hits[] = $ts;
        }
    }

    $allowed = count($hits) < $maxRequests;
    $retryAfter = 0;
    if ($allowed) {
        $hits[] = $now;
    } elseif (isset($hits[0])) {
        $retryAfter = max(1, $windowSeconds - ($now - (int) $hits[0]));
    } else {
        $retryAfter = $windowSeconds;
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($hits));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return ['allowed' => $allowed, 'retry_after' => $retryAfter];
}
