<?php

namespace App\Services;

use App\Models\SecurityAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SecurityMonitor
{
    private const MAX_PAYLOAD_SIZE = 10000;

    private const SEVERITY_HIGH = 'high';

    private const SEVERITY_MEDIUM = 'medium';

    private const SEVERITY_LOW = 'low';

    /**
     * Livewire security exceptions that indicate tampering attempts.
     *
     * @var array<string>
     */
    private const LIVEWIRE_SECURITY_EXCEPTIONS = [
        'Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException',
        'Livewire\Exceptions\ComponentNotFoundException',
        'Livewire\Exceptions\MethodNotFoundException',
        'Livewire\Exceptions\MissingFileUploadsTraitException',
        'Livewire\Exceptions\PropertyNotFoundException',
        'Livewire\Exceptions\PublicPropertyNotFoundException',
        'Livewire\Exceptions\CannotBindToModelDataWithoutValidationRuleException',
        'Livewire\Exceptions\CorruptComponentPayloadException',
    ];

    /**
     * Patterns in payloads that indicate injection attacks.
     *
     * @var array<string>
     */
    private const MALICIOUS_PATTERNS = [
        '__toString',
        'phpinfo',
        'system(',
        'exec(',
        'shell_exec',
        'passthru',
        'eval(',
        'base64_decode',
        'SerializableClosure',
        'BroadcastEvent',
        'FnStream',
        'PendingBroadcast',
        'dispatchNextJobInChain',
    ];

    public function recordFromException(Throwable $exception, ?Request $request = null): void
    {
        try {
            $request ??= request();

            if (! $this->shouldRecord($exception)) {
                return;
            }

            $componentName = $this->extractComponentName($request);
            $targetProperty = $this->extractTargetProperty($exception);
            $payload = $this->sanitizePayload($request);
            $severity = $this->determineSeverity($exception, $payload);

            SecurityAttempt::query()->create([
                'ip_address' => $this->getIpAddress($request),
                'user_agent' => $this->truncate($request->userAgent(), 500),
                'method' => $request->method(),
                'url' => $this->truncate($request->fullUrl(), 2000),
                'route_name' => $request->route()?->getName(),
                'exception_class' => get_class($exception),
                'exception_message' => $this->truncate($exception->getMessage(), 1000),
                'component_name' => $componentName,
                'target_property' => $targetProperty,
                'payload' => $payload,
                'severity' => $severity,
            ]);
        } catch (Throwable $e) {
            // Never let monitoring fail the application
            Log::warning('SecurityMonitor failed to record attempt', [
                'error' => $e->getMessage(),
                'original_exception' => get_class($exception),
            ]);
        }
    }

    public function shouldRecord(Throwable $exception): bool
    {
        $exceptionClass = get_class($exception);

        foreach (self::LIVEWIRE_SECURITY_EXCEPTIONS as $securityException) {
            if ($exceptionClass === $securityException || is_subclass_of($exception, $securityException)) {
                return true;
            }
        }

        return false;
    }

    private function extractComponentName(Request $request): ?string
    {
        try {
            $components = $request->input('components', []);
            if (empty($components)) {
                return null;
            }

            $snapshot = json_decode($components[0]['snapshot'] ?? '{}', true);

            return $snapshot['memo']['name'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    private function extractTargetProperty(Throwable $exception): ?string
    {
        $message = $exception->getMessage();

        if (preg_match('/\[([^\]]+)\]/', $message, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function sanitizePayload(Request $request): ?array
    {
        try {
            $payload = $request->input('components', []);
            $jsonPayload = json_encode($payload);

            // Truncate if too large
            if (strlen($jsonPayload) > self::MAX_PAYLOAD_SIZE) {
                return [
                    '_truncated' => true,
                    '_original_size' => strlen($jsonPayload),
                    'summary' => $this->extractPayloadSummary($payload),
                ];
            }

            return $payload;
        } catch (Throwable) {
            return ['_error' => 'Could not serialize payload'];
        }
    }

    private function extractPayloadSummary(array $payload): array
    {
        $summary = [];

        try {
            foreach ($payload as $index => $component) {
                $summary[$index] = [
                    'has_snapshot' => isset($component['snapshot']),
                    'updates_count' => count($component['updates'] ?? []),
                    'update_keys' => array_keys($component['updates'] ?? []),
                    'calls_count' => count($component['calls'] ?? []),
                ];
            }
        } catch (Throwable) {
            // Ignore
        }

        return $summary;
    }

    private function determineSeverity(Throwable $exception, ?array $payload): string
    {
        // Check for injection patterns in payload
        $payloadJson = json_encode($payload) ?: '';

        foreach (self::MALICIOUS_PATTERNS as $pattern) {
            if (stripos($payloadJson, $pattern) !== false) {
                return self::SEVERITY_HIGH;
            }
        }

        // Locked property tampering is medium severity
        if (str_contains(get_class($exception), 'LockedProperty')) {
            return self::SEVERITY_MEDIUM;
        }

        return self::SEVERITY_LOW;
    }

    /**
     * The IP a security attempt is recorded against.
     *
     * This used to read `X-Forwarded-For` unconditionally and store its first
     * entry. That let every caller pick the IP that would end up in
     * `security_attempts.ip_address` — in a table whose whole purpose is to
     * name attackers, and which `security:attempts --ip` and `--top-ips`
     * report on. Worse, it disagreed with the rate limiters, which count
     * `$request->ip()`: the address we blocked was never the address we
     * logged.
     *
     * `$request->ip()` respects the forwarded headers exactly when a trusted
     * proxy sent them (see trustProxies() in bootstrap/app.php) and otherwise
     * reports the peer that actually opened the connection. One source, one
     * answer, and only trusted parties can influence it.
     */
    private function getIpAddress(Request $request): string
    {
        return $request->ip() ?? 'unknown';
    }

    private function truncate(?string $value, int $length): ?string
    {
        return $value === null ? null : Str::limit($value, $length - 3);
    }
}
