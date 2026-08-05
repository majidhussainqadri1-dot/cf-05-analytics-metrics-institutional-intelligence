<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

final class SensitiveValueDetector
{
    /** @return array<int,string> */
    public function violations(mixed $value): array
    {
        $violations = [];
        $nodes = 0;
        $this->scan($value, $violations, $nodes, 0);
        return array_values(array_unique($violations));
    }

    /** @param array<int,string> $violations */
    private function scan(mixed $value, array &$violations, int &$nodes, int $depth): void
    {
        if ($nodes++ >= 500 || $depth > 6) {
            $violations[] = 'unbounded_sensitive_scan_input';
            return;
        }
        if (is_array($value)) {
            foreach (array_slice($value, 0, 150, true) as $key => $item) {
                if ($this->forbiddenKey(strtolower((string) $key))) {
                    $violations[] = 'forbidden_key_fragment';
                }
                $this->scan($item, $violations, $nodes, $depth + 1);
            }
            return;
        }
        if (!is_string($value) || trim($value) === '') {
            return;
        }
        $sample = Text::truncate(trim($value), 4096);
        if (preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i', $sample) === 1) {
            $violations[] = 'private_key_material';
        }
        if (preg_match('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', $sample) === 1) {
            $violations[] = 'bearer_token';
        }
        if (preg_match('/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/', $sample) === 1) {
            $violations[] = 'jwt_token';
        }
        if (filter_var($sample, FILTER_VALIDATE_EMAIL) !== false || preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $sample) === 1) {
            $violations[] = 'email_address';
        }
        if (filter_var($sample, FILTER_VALIDATE_IP) !== false || preg_match('/(?<!\d)(?:\d{1,3}\.){3}\d{1,3}(?!\d)/', $sample) === 1) {
            $violations[] = 'ip_address';
        }
        if (preg_match('/(?:^|\s)\+?\d[\d\s().-]{8,}\d(?:\s|$)/', $sample) === 1) {
            $violations[] = 'phone_like_value';
        }
        if ($this->containsValidCardNumber($sample)) {
            $violations[] = 'payment_card_number';
        }
        if (preg_match('/\b(?:password|passwd|pwd|otp|cvv|cvc|api[_-]?key|client[_-]?secret|private[_-]?key|access[_-]?token|refresh[_-]?token)\s*[:=]/i', $sample) === 1) {
            $violations[] = 'credential_assignment';
        }
        if (preg_match('/\b(?:clinical note|prescription body|private message body|identity document)\b/i', $sample) === 1) {
            $violations[] = 'restricted_content_marker';
        }
    }

    private function forbiddenKey(string $key): bool
    {
        foreach (['password','passwd','pwd','otp','cvv','cvc','pan','card_number','secret','api_key','client_secret','private_key','access_token','refresh_token','clinical_note','prescription','message_body','identity_document','raw_query'] as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }
        return false;
    }

    private function containsValidCardNumber(string $value): bool
    {
        preg_match_all('/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/', $value, $matches);
        foreach ($matches[0] ?? [] as $candidate) {
            $digits = preg_replace('/\D+/', '', (string) $candidate);
            if (is_string($digits) && strlen($digits) >= 13 && strlen($digits) <= 19 && $this->luhn($digits)) {
                return true;
            }
        }
        return false;
    }

    private function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($double) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $double = !$double;
        }
        return $sum > 0 && $sum % 10 === 0;
    }
}
