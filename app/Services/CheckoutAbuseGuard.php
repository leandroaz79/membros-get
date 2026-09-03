<?php

namespace App\Services;

use App\Exceptions\ExistingPixCheckoutRedirect;
use App\Models\Order;
use App\Models\Product;
use App\Support\PendingPixCheckoutResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class CheckoutAbuseGuard
{
    public function isEnabled(): bool
    {
        return (bool) config('checkout_security.enabled', true);
    }

    public function honeypotField(): string
    {
        return (string) config('checkout_security.honeypot_field', 'website');
    }

    public function honeypotTriggered(Request $request): bool
    {
        $field = $this->honeypotField();
        $value = $request->input($field);

        return is_string($value) && trim($value) !== '';
    }

    public function floodPixAttemptCount(Request $request): int
    {
        if (! $this->isPixCheckoutRequest($request)) {
            return 0;
        }

        return (int) Cache::get($this->floodPixCacheKey($request), 0);
    }

    public function isFloodPixThresholdExceeded(Request $request): bool
    {
        $threshold = max(1, (int) config('checkout_security.flood.pix_attempts_per_minute', 2));

        return $this->floodPixAttemptCount($request) > $threshold;
    }

    public function assertCanCreateCheckout(Request $request, ?Product $product): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if ($this->honeypotTriggered($request)) {
            throw new TooManyRequestsHttpException(60, 'Muitas tentativas. Aguarde e tente novamente.');
        }

        $this->assertFloodPixReuse($request);
        $this->assertPendingLimits($request, $product);
    }

    public function assertFloodPixReuse(Request $request): void
    {
        if (! $this->isEnabled() || ! $this->isPixCheckoutRequest($request)) {
            return;
        }

        $key = $this->floodPixCacheKey($request);
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, now()->addMinute());

        if ($count > max(1, (int) config('checkout_security.flood.pix_attempts_per_minute', 2))) {
            $this->tryRedirectToExistingPixRelaxed($request);
        }
    }

    public function assertPendingLimits(Request $request, ?Product $product = null): void
    {
        $this->tryRedirectToExistingPix($request);

        $lookbackHours = max(1, (int) config('checkout_security.pending.lookback_hours', 1));
        $since = now()->subHours($lookbackHours);

        $maxIp = max(1, (int) config('checkout_security.pending.max_per_ip_hour', 10));
        $ip = $request->ip();
        if ($ip) {
            $ipCount = Order::query()
                ->where('status', 'pending')
                ->where('customer_ip', $ip)
                ->where('created_at', '>=', $since)
                ->count();
            if ($ipCount >= $maxIp) {
                $this->tryRedirectToExistingPix($request);
                $this->tryRedirectToExistingPixRelaxed($request);
                throw new TooManyRequestsHttpException(300, 'Muitas tentativas de pagamento. Aguarde alguns minutos.');
            }
        }

        $maxEmail = max(1, (int) config('checkout_security.pending.max_per_email_hour', 6));
        $email = $this->normalizeEmail($request->input('email'));
        if ($email !== '') {
            $emailCount = Order::query()
                ->where('status', 'pending')
                ->where('email', $email)
                ->where('created_at', '>=', $since)
                ->count();
            if ($emailCount >= $maxEmail) {
                $this->tryRedirectToExistingPix($request);
                $this->tryRedirectToExistingPixRelaxed($request);
                throw new TooManyRequestsHttpException(300, 'Muitas tentativas de pagamento para este e-mail. Aguarde alguns minutos.');
            }
        }
    }

    private function tryRedirectToExistingPix(Request $request): void
    {
        $order = PendingPixCheckoutResolver::findReusable($request);
        if ($order) {
            throw new ExistingPixCheckoutRedirect($order, $request);
        }
    }

    private function tryRedirectToExistingPixRelaxed(Request $request): void
    {
        $order = PendingPixCheckoutResolver::findReusableRelaxed($request);
        if ($order) {
            throw new ExistingPixCheckoutRedirect($order, $request, relaxed: true);
        }
    }

    private function isPixCheckoutRequest(Request $request): bool
    {
        return PendingPixCheckoutResolver::isPixLikePaymentMethod($request->input('payment_method'));
    }

    private function floodPixCacheKey(Request $request): string
    {
        $email = $this->normalizeEmail($request->input('email'));
        $productId = trim((string) $request->input('product_id', ''));

        return 'checkout_flood_pix:'.sha1($email.'|'.$productId);
    }

    private function normalizeEmail(mixed $email): string
    {
        if (! is_string($email)) {
            return '';
        }

        return strtolower(trim($email));
    }
}
