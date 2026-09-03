<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use App\Services\PartnerAccessService;
use App\Services\StorageService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PartnerProductController extends Controller
{
    public function show(Request $request, Product $produto): Response
    {
        $user = $request->user();
        $access = app(PartnerAccessService::class);

        if (! $access->canAccessProduct($user, $produto)) {
            abort(403);
        }

        $type = $access->partnerTypeForProduct($user, $produto->id);
        $affiliate = $access->affiliateMembershipForProduct($user, $produto->id);
        $links = [];
        $canUseLinks = $user->isAfiliado() && $access->canUseAffiliateLinks($user, $produto->id);
        $canEditPixels = $user->isAfiliado() && $access->canEditAffiliatePixels($user, $produto->id);

        if ($canUseLinks && $affiliate) {
            $ref = $affiliate->affiliate_code;
            $links[] = [
                'label' => 'Checkout principal',
                'url' => url('/c/'.$produto->checkout_slug.'?ref='.$ref),
            ];

            foreach (ProductOffer::where('product_id', $produto->id)->whereNotNull('checkout_slug')->orderBy('name')->get() as $offer) {
                $links[] = [
                    'label' => 'Oferta: '.$offer->name,
                    'url' => url('/c/'.$offer->checkout_slug.'?ref='.$ref),
                ];
            }

            foreach (ProductOffer::where('product_id', $produto->id)->whereNull('checkout_slug')->orderBy('name')->get() as $offer) {
                $publicId = $offer->ensurePublicId();
                $query = $publicId ? 'offer='.$publicId : 'offer_id='.$offer->id;
                $links[] = [
                    'label' => 'Oferta: '.$offer->name,
                    'url' => url('/c/'.$produto->checkout_slug.'?'.$query.'&ref='.$ref),
                ];
            }

            foreach (SubscriptionPlan::where('product_id', $produto->id)->whereNotNull('checkout_slug')->orderBy('name')->get() as $plan) {
                $links[] = [
                    'label' => 'Plano: '.$plan->name,
                    'url' => url('/c/'.$plan->checkout_slug.'?ref='.$ref),
                ];
            }

            foreach (SubscriptionPlan::where('product_id', $produto->id)->whereNull('checkout_slug')->orderBy('name')->get() as $plan) {
                $publicId = $plan->ensurePublicId();
                $query = $publicId ? 'plan='.$publicId : 'plan_id='.$plan->id;
                $links[] = [
                    'label' => 'Plano: '.$plan->name,
                    'url' => url('/c/'.$produto->checkout_slug.'?'.$query.'&ref='.$ref),
                ];
            }
        }

        $commissionPercent = null;
        if ($affiliate) {
            $commissionPercent = $access->commissionPercentForAffiliate($affiliate, $produto);
        } elseif ($type === 'coprodutor') {
            $coprod = \App\Models\ProductCoproducer::query()
                ->where('product_id', $produto->id)
                ->where('user_id', $user->id)
                ->where('status', \App\Models\ProductCoproducer::STATUS_ACTIVE)
                ->first();
            $commissionPercent = $coprod ? (float) $coprod->commission_percent : null;
        }

        return Inertia::render('Partner/ProductShow', [
            'produto' => [
                'id' => $produto->id,
                'name' => $produto->name,
                'description' => $produto->description,
                'type' => $produto->type,
                'checkout_slug' => $produto->checkout_slug,
                'price' => (float) $produto->price,
                'currency' => $produto->currency ?? 'BRL',
                'image_url' => $produto->image ? app(StorageService::class)->url($produto->image) : null,
            ],
            'partner_type' => $type,
            'affiliate_status' => $affiliate?->status,
            'commission_percent' => $commissionPercent,
            'affiliate' => $affiliate ? [
                'affiliate_code' => $affiliate->affiliate_code,
                'affiliate_pixels' => $affiliate->affiliate_pixels ?? [],
            ] : null,
            'links' => $links,
            'can_use_links' => $canUseLinks,
            'can_edit_pixels' => $canEditPixels,
            'tab' => $request->query('tab', 'overview'),
        ]);
    }

    public function updatePixels(Request $request, Product $produto)
    {
        $user = $request->user();
        $access = app(PartnerAccessService::class);

        if (! $user->isAfiliado() || ! $access->canEditAffiliatePixels($user, $produto->id)) {
            abort(403);
        }

        $affiliate = $access->affiliateMembershipForProduct($user, $produto->id);
        if (! $affiliate || $affiliate->status !== \App\Models\ProductAffiliate::STATUS_APPROVED) {
            abort(403);
        }

        $validated = $request->validate([
            'affiliate_pixels' => ['nullable', 'array'],
            'affiliate_pixels.meta' => ['nullable', 'array'],
            'affiliate_pixels.meta.enabled' => ['nullable', 'boolean'],
            'affiliate_pixels.meta.entries' => ['nullable', 'array'],
            'affiliate_pixels.meta.entries.*.id' => ['nullable', 'string', 'max:64'],
            'affiliate_pixels.meta.entries.*.pixel_id' => ['nullable', 'string', 'max:64'],
            'affiliate_pixels.meta.entries.*.access_token' => ['nullable', 'string', 'max:500'],
            'affiliate_pixels.meta.entries.*.fire_purchase_on_pix' => ['nullable', 'boolean'],
            'affiliate_pixels.meta.entries.*.fire_purchase_on_boleto' => ['nullable', 'boolean'],
            'affiliate_pixels.meta.entries.*.disable_order_bump_events' => ['nullable', 'boolean'],
            'affiliate_pixels.tiktok' => ['nullable', 'array'],
            'affiliate_pixels.tiktok.enabled' => ['nullable', 'boolean'],
            'affiliate_pixels.tiktok.entries' => ['nullable', 'array'],
            'affiliate_pixels.tiktok.entries.*.id' => ['nullable', 'string', 'max:64'],
            'affiliate_pixels.tiktok.entries.*.pixel_id' => ['nullable', 'string', 'max:64'],
            'affiliate_pixels.tiktok.entries.*.access_token' => ['nullable', 'string', 'max:500'],
            'affiliate_pixels.tiktok.entries.*.fire_purchase_on_pix' => ['nullable', 'boolean'],
            'affiliate_pixels.tiktok.entries.*.fire_purchase_on_boleto' => ['nullable', 'boolean'],
            'affiliate_pixels.tiktok.entries.*.disable_order_bump_events' => ['nullable', 'boolean'],
            'affiliate_pixels.google_ads' => ['nullable', 'array'],
            'affiliate_pixels.google_ads.enabled' => ['nullable', 'boolean'],
            'affiliate_pixels.google_ads.entries' => ['nullable', 'array'],
            'affiliate_pixels.google_ads.entries.*.id' => ['nullable', 'string', 'max:64'],
            'affiliate_pixels.google_ads.entries.*.conversion_id' => ['nullable', 'string', 'max:64'],
            'affiliate_pixels.google_ads.entries.*.conversion_label' => ['nullable', 'string', 'max:64'],
            'affiliate_pixels.google_ads.entries.*.fire_purchase_on_pix' => ['nullable', 'boolean'],
            'affiliate_pixels.google_ads.entries.*.fire_purchase_on_boleto' => ['nullable', 'boolean'],
            'affiliate_pixels.google_ads.entries.*.disable_order_bump_events' => ['nullable', 'boolean'],
            'affiliate_pixels.google_analytics' => ['nullable', 'array'],
            'affiliate_pixels.google_analytics.enabled' => ['nullable', 'boolean'],
            'affiliate_pixels.google_analytics.entries' => ['nullable', 'array'],
            'affiliate_pixels.google_analytics.entries.*.id' => ['nullable', 'string', 'max:64'],
            'affiliate_pixels.google_analytics.entries.*.measurement_id' => ['nullable', 'string', 'max:64'],
            'affiliate_pixels.google_analytics.entries.*.fire_purchase_on_pix' => ['nullable', 'boolean'],
            'affiliate_pixels.google_analytics.entries.*.fire_purchase_on_boleto' => ['nullable', 'boolean'],
            'affiliate_pixels.google_analytics.entries.*.disable_order_bump_events' => ['nullable', 'boolean'],
        ]);

        $pixels = $this->sanitizeAffiliatePixels($validated['affiliate_pixels'] ?? []);
        $affiliate->update(['affiliate_pixels' => $pixels]);

        return back()->with('success', 'Pixels atualizados.');
    }

    /**
     * Mantém apenas plataformas de ads/analytics; remove vetores de XSS.
     *
     * @param  array<string, mixed>  $pixels
     * @return array<string, mixed>
     */
    private function sanitizeAffiliatePixels(array $pixels): array
    {
        $out = [
            'meta' => is_array($pixels['meta'] ?? null) ? $pixels['meta'] : ['enabled' => false, 'entries' => []],
            'tiktok' => is_array($pixels['tiktok'] ?? null) ? $pixels['tiktok'] : ['enabled' => false, 'entries' => []],
            'google_ads' => is_array($pixels['google_ads'] ?? null) ? $pixels['google_ads'] : ['enabled' => false, 'entries' => []],
            'google_analytics' => is_array($pixels['google_analytics'] ?? null) ? $pixels['google_analytics'] : ['enabled' => false, 'entries' => []],
            'gtm' => ['enabled' => false, 'container_id' => ''],
            'custom_script' => [],
            'custom_script_integration_ids' => [],
        ];

        // Afiliados não usam integrações centralizadas do produtor.
        foreach (['meta', 'tiktok', 'google_ads', 'google_analytics'] as $platform) {
            unset($out[$platform]['integration_ids']);
            $out[$platform]['integration_ids'] = [];
        }

        return $out;
    }
}
