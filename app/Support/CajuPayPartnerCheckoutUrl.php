<?php

namespace App\Support;

use App\Models\ApiCheckoutSession;
use App\Models\CommerceCheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\URL;

/**
 * URL HTTPS da página de checkout do parceiro (campo partner_checkout_url na API CajuPay).
 */
final class CajuPayPartnerCheckoutUrl
{
    public static function forOrder(Order $order): ?string
    {
        if ($order->api_checkout_session_id) {
            $apiSession = ApiCheckoutSession::query()
                ->where('id', $order->api_checkout_session_id)
                ->value('session_token');
            if (is_string($apiSession) && $apiSession !== '') {
                return self::sanitize(URL::route('api-checkout.show', ['token' => $apiSession]));
            }
        }

        $commerceToken = CommerceCheckoutSession::query()
            ->where('order_id', $order->id)
            ->value('session_token');
        if (is_string($commerceToken) && $commerceToken !== '') {
            return self::sanitize(URL::route('commerce.checkout.show', ['token' => $commerceToken]));
        }

        $order->loadMissing(['product', 'productOffer', 'subscriptionPlan']);

        return self::forProductCheckout(
            $order->product,
            $order->productOffer,
            $order->subscriptionPlan
        );
    }

    public static function forProductCheckout(
        ?Product $product,
        ?ProductOffer $offer = null,
        ?SubscriptionPlan $plan = null,
    ): ?string {
        $slug = '';
        if (filled($offer?->checkout_slug)) {
            $slug = (string) $offer->checkout_slug;
        } elseif (filled($plan?->checkout_slug)) {
            $slug = (string) $plan->checkout_slug;
        } else {
            $slug = trim((string) ($product?->checkout_slug ?? ''));
        }

        if ($slug === '') {
            return null;
        }

        $url = URL::route('checkout.show', ['slug' => $slug]);

        if ($offer && filled($offer->checkout_slug)) {
            return self::sanitize($url);
        }
        if ($plan && filled($plan->checkout_slug)) {
            return self::sanitize($url);
        }

        $query = self::offerOrPlanQueryParams($offer, $plan);
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return self::sanitize($url);
    }

    public static function forApiCheckoutSession(ApiCheckoutSession $session): ?string
    {
        $token = trim((string) ($session->session_token ?? ''));
        if ($token === '') {
            return null;
        }

        return self::sanitize(URL::route('api-checkout.show', ['token' => $token]));
    }

    /**
     * @return array<string, string>
     */
    private static function offerOrPlanQueryParams(?ProductOffer $offer, ?SubscriptionPlan $plan): array
    {
        if ($offer) {
            $publicId = trim((string) ($offer->public_id ?? ''));
            if ($publicId !== '') {
                return ['offer' => $publicId];
            }

            return ['offer_id' => (string) $offer->id];
        }

        if ($plan) {
            $publicId = trim((string) ($plan->public_id ?? ''));
            if ($publicId !== '') {
                return ['plan' => $publicId];
            }

            return ['plan_id' => (string) $plan->id];
        }

        return [];
    }

    public static function sanitize(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            return null;
        }

        return $url;
    }
}
