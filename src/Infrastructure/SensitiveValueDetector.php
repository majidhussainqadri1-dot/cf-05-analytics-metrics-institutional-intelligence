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
        if ($nodes++ >= 250 || $depth > 5) {
            $violations[] = 'unbounded_sensitive_scan_input';
            return;
        }

        if (is_array($value)) {
            foreach (array_slice($value, 0, 100, true) as $key => $item) {
                $keyText = strtolower((string) $key);
                if ($this->containsForbiddenKeyFragment($keyText)) {
                    $violations[] = 'forbidden_key_fragment';
                }
                $this->scan($item, $violations, $nodes, $depth + 1);
            }
            return;
        }

        if (!is_string($value) || $value === '') {
            return;
        }

        $sample = Text::truncate(trim($value), 2048);
        $lower = strtolower($sample);

        if (preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i', $sample) === 1) {
            $violations[] = 'private_key_material';
        }
        if (preg_match('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*\b/i', $sample) === 1) {
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
        if (str_contains($lower, 'message body') || str_contains($lower, 'clinical note') || str_contains($lower, 'identity document')) {
            $violations[] = 'restricted_content_marker';
        }
    }

    private function containsForbiddenKeyFragment(string $key): bool
    {
        foreach (['password','passwd','otp','cvv','cvc','pan','card_number','secret','private_key','access_token','refresh_token','clinical_note','prescription','message_body','identity_document','raw_query'] as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }
        return false;
    }

    private function containsValidCardNumber(string $value): bool
    {
        if (preg_match_all('/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/', $value, $matches) !== 1 && empty($matches[0])) {
            return false;
        }
        foreach ($matches[0] as $candidate) {
            $digits = preg_replace('/\D+/', '', (string) $candidate);
            if (is_string($digits) && strlen($digits) >= 13 && strlen($digits) <= 19 && $this->luhnValid($digits)) {
                return true;
            }
        }
        return false;
    }

    private function luhnValid(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $number = (int) $digits[$index];
            if ($double) {
                $number *= 2;
                if ($number > 9) {
                    $number -= 9;
                }
            }
            $sum += $number;
            $double = !$double;
        }
        return $sum > 0 && $sum % 10 === 0;
    }
}
