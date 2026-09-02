<?php

namespace App\Services\ProductPackage;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductOrderBump;
use App\Services\StorageService;
use App\Support\ProductPackageMediaCollector;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class ProductExportService
{
    public function __construct(
        private readonly StorageService $storage,
    ) {}

    /**
     * @return array{path: string, filename: string, warnings: list<string>}
     */
    public function export(Product $product, bool $includeMedia = true): array
    {
        $product->load([
            'offers',
            'subscriptionPlans',
            'orderBumps',
            'affiliateProgram',
            'memberAreaDomain',
            'memberSections.modules.lessons',
            'memberTurmas',
            'memberCommunityPages',
            'memberInternalProducts',
        ]);

        $warnings = [];
        $payload = $this->serializeProduct($product, $warnings);

        $collector = new ProductPackageMediaCollector($this->storage);
        if ($includeMedia) {
            $collector->collectFromValue($payload);
        }

        $mediaMap = [];
        $tmpDir = storage_path('app/tmp-product-packages/'.uniqid('exp_', true));
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $zipPath = $tmpDir.'/package.getfy-product';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Não foi possível criar o arquivo de exportação.');
        }

        if ($includeMedia) {
            foreach ($collector->paths() as $path) {
                try {
                    $contents = $this->storage->disk()->get($path);
                    if ($contents === null || $contents === false) {
                        $warnings[] = "Mídia não lida: {$path}";

                        continue;
                    }
                    $archivePath = 'media/'.$path;
                    $zip->addFromString($archivePath, $contents);
                    $mediaMap[$path] = $archivePath;
                } catch (\Throwable $e) {
                    Log::warning('ProductExportService: falha ao incluir mídia', [
                        'path' => $path,
                        'message' => $e->getMessage(),
                    ]);
                    $warnings[] = "Mídia omitida: {$path}";
                }
            }
        }

        $summary = [
            'name' => $product->name,
            'type' => $product->type,
            'billing_type' => $product->billing_type,
            'source_product_id' => $product->id,
            'offers' => count($payload['offers'] ?? []),
            'plans' => count($payload['subscription_plans'] ?? []),
            'sections' => count($payload['member_sections'] ?? []),
            'modules' => $this->countModules($payload),
            'lessons' => $this->countLessons($payload),
        ];

        $manifest = ProductPackageManifest::build($summary, $includeMedia, $warnings, $mediaMap);

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->addFromString('product.json', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->close();

        $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) \Illuminate\Support\Str::slug($product->name)) ?: 'produto';
        $filename = $safeName.'-'.date('Ymd-His').'.getfy-product';

        return [
            'path' => $zipPath,
            'filename' => $filename,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    public function serializeProduct(Product $product, array &$warnings): array
    {
        $checkoutConfig = $this->sanitizeCheckoutConfig(
            is_array($product->checkout_config) ? $product->checkout_config : [],
            (string) $product->id,
            $warnings
        );

        $conversionPixels = $this->sanitizeConversionPixels(
            is_array($product->conversion_pixels) ? $product->conversion_pixels : []
        );

        $memberAreaConfig = $this->sanitizeMemberAreaConfig(
            is_array($product->member_area_config) ? $product->member_area_config : []
        );

        $offers = [];
        foreach ($product->offers as $offer) {
            $offers[] = [
                'export_id' => (string) $offer->id,
                'name' => $offer->name,
                'price' => (float) $offer->price,
                'currency' => $offer->currency,
                'checkout_slug' => $offer->checkout_slug,
                'checkout_config' => $this->sanitizeCheckoutConfig(
                    is_array($offer->checkout_config) ? $offer->checkout_config : [],
                    (string) $product->id,
                    $warnings
                ),
                'position' => (int) $offer->position,
                'combo_product_ids' => [],
            ];
            if (! empty($offer->combo_product_ids)) {
                $warnings[] = "Oferta \"{$offer->name}\": combos de outros produtos omitidos.";
            }
        }

        $plans = [];
        foreach ($product->subscriptionPlans as $plan) {
            $plans[] = [
                'export_id' => (string) $plan->id,
                'name' => $plan->name,
                'price' => (float) $plan->price,
                'currency' => $plan->currency,
                'interval' => $plan->interval,
                'checkout_slug' => $plan->checkout_slug,
                'checkout_config' => $this->sanitizeCheckoutConfig(
                    is_array($plan->checkout_config) ? $plan->checkout_config : [],
                    (string) $product->id,
                    $warnings
                ),
                'position' => (int) $plan->position,
                'gateway_plan_id' => null,
                'combo_product_ids' => [],
            ];
            if (! empty($plan->combo_product_ids)) {
                $warnings[] = "Plano \"{$plan->name}\": combos de outros produtos omitidos.";
            }
        }

        $offerIds = array_map(fn ($o) => (string) $o['export_id'], $offers);
        $planIds = array_map(fn ($p) => (string) $p['export_id'], $plans);

        $bumps = [];
        foreach ($product->orderBumps as $bump) {
            $serialized = $this->serializeOrderBump($bump, (string) $product->id, $offerIds, $planIds, $warnings);
            if ($serialized !== null) {
                $bumps[] = $serialized;
            }
        }

        $affiliate = null;
        if ($product->affiliateProgram) {
            $ap = $product->affiliateProgram;
            $affiliate = [
                'enabled' => (bool) $ap->enabled,
                'default_commission_percent' => (float) $ap->default_commission_percent,
                'manual_approval' => (bool) $ap->manual_approval,
                'share_buyer_data' => (bool) $ap->share_buyer_data,
                'public_slug' => null,
                'support_email' => $ap->support_email,
                'description' => $ap->description,
                'settlement_days_pix' => $ap->settlement_days_pix,
                'settlement_days_card' => $ap->settlement_days_card,
                'settlement_days_boleto' => $ap->settlement_days_boleto,
            ];
        }

        $coupons = $this->serializeCoupons($product);

        $domain = null;
        if ($product->memberAreaDomain) {
            $domain = [
                'type' => 'path',
                'value' => null,
            ];
        }

        $sections = [];
        foreach ($product->memberSections as $section) {
            $modules = [];
            foreach ($section->modules as $module) {
                if ($module->related_product_id || $module->source_member_module_id) {
                    $warnings[] = "Módulo \"{$module->title}\": referência a outro produto omitida (export só do produto atual).";
                }

                $lessons = [];
                foreach ($module->lessons as $lesson) {
                    $lessons[] = [
                        'export_id' => (string) $lesson->id,
                        'title' => $lesson->title,
                        'position' => (int) $lesson->position,
                        'type' => $lesson->type,
                        'content_url' => $lesson->content_url,
                        'link_title' => $lesson->link_title,
                        'content_files' => $lesson->content_files,
                        'support_files' => $lesson->support_files,
                        'useful_links' => $lesson->useful_links,
                        'release_after_days' => $lesson->release_after_days,
                        'release_at_date' => $lesson->release_at_date?->toDateString(),
                        'access_duration_days' => $lesson->access_duration_days,
                        'content_text' => $lesson->content_text,
                        'duration_seconds' => $lesson->duration_seconds,
                        'is_free' => (bool) $lesson->is_free,
                        'watermark_enabled' => (bool) $lesson->watermark_enabled,
                    ];
                }

                $modules[] = [
                    'export_id' => (string) $module->id,
                    'title' => $module->title,
                    'position' => (int) $module->position,
                    'thumbnail' => $module->thumbnail,
                    'show_title_on_cover' => (bool) $module->show_title_on_cover,
                    'related_product_id' => null,
                    'source_member_module_id' => null,
                    'access_type' => $module->access_type,
                    'external_url' => $module->external_url,
                    'release_after_days' => $module->release_after_days,
                    'release_at_date' => $module->release_at_date?->toDateString(),
                    'release_progress_percent' => $module->release_progress_percent,
                    'release_required_module_ids' => array_values(array_map(
                        'strval',
                        is_array($module->release_required_module_ids) ? $module->release_required_module_ids : []
                    )),
                    'access_duration_days' => $module->access_duration_days,
                    'lessons' => $lessons,
                ];
            }

            $sections[] = [
                'export_id' => (string) $section->id,
                'title' => $section->title,
                'position' => (int) $section->position,
                'cover_mode' => $section->cover_mode,
                'section_type' => $section->section_type,
                'modules' => $modules,
            ];
        }

        $turmas = [];
        foreach ($product->memberTurmas as $turma) {
            $turmas[] = [
                'name' => $turma->name,
                'description' => $turma->description,
                'start_date' => $turma->start_date?->toDateString(),
                'end_date' => $turma->end_date?->toDateString(),
                'position' => (int) $turma->position,
            ];
        }

        $communityPages = [];
        foreach ($product->memberCommunityPages as $page) {
            $communityPages[] = [
                'title' => $page->title,
                'icon' => $page->icon,
                'slug' => $page->slug,
                'banner' => $page->banner,
                'position' => (int) $page->position,
                'is_public_posting' => (bool) $page->is_public_posting,
                'is_default' => (bool) $page->is_default,
                'is_featured' => (bool) $page->is_featured,
            ];
        }

        if ($product->memberInternalProducts->isNotEmpty()) {
            $warnings[] = 'Produtos internos da área de membros omitidos (referenciam outros produtos).';
        }

        if (! empty($product->combo_product_ids)) {
            $warnings[] = 'Combos de outros produtos omitidos.';
        }

        return [
            'product' => [
                'export_id' => (string) $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'description' => $product->description,
                'type' => $product->type,
                'billing_type' => $product->billing_type,
                'image' => $product->image,
                'price' => (float) $product->price,
                'currency' => $product->currency,
                'is_active' => (bool) $product->is_active,
                'cajupay_split_payout_enabled' => (bool) $product->cajupay_split_payout_enabled,
                'checkout_config' => $checkoutConfig,
                'conversion_pixels' => $conversionPixels,
                'member_area_config' => $memberAreaConfig,
                'combo_product_ids' => [],
            ],
            'offers' => $offers,
            'subscription_plans' => $plans,
            'order_bumps' => $bumps,
            'affiliate_program' => $affiliate,
            'coupons' => $coupons,
            'member_area_domain' => $domain,
            'member_sections' => $sections,
            'member_turmas' => $turmas,
            'member_community_pages' => $communityPages,
        ];
    }

    /**
     * @param  list<string>  $offerIds
     * @param  list<string>  $planIds
     * @param  list<string>  $warnings
     * @return array<string, mixed>|null
     */
    private function serializeOrderBump(
        ProductOrderBump $bump,
        string $productId,
        array $offerIds,
        array $planIds,
        array &$warnings,
    ): ?array {
        $targetProductId = (string) ($bump->target_product_id ?? '');
        if ($targetProductId !== '' && $targetProductId !== $productId) {
            $warnings[] = "Order bump \"{$bump->title}\": alvo em outro produto omitido.";

            return null;
        }

        $targetOfferId = $bump->target_product_offer_id ? (string) $bump->target_product_offer_id : null;
        $targetPlanId = $bump->target_subscription_plan_id ? (string) $bump->target_subscription_plan_id : null;

        if ($targetOfferId !== null && ! in_array($targetOfferId, $offerIds, true)) {
            $warnings[] = "Order bump \"{$bump->title}\": oferta alvo inválida omitida.";

            return null;
        }
        if ($targetPlanId !== null && ! in_array($targetPlanId, $planIds, true)) {
            $warnings[] = "Order bump \"{$bump->title}\": plano alvo inválido omitido.";

            return null;
        }

        return [
            'title' => $bump->title,
            'description' => $bump->description,
            'price_override' => $bump->price_override !== null ? (float) $bump->price_override : null,
            'is_free' => (bool) $bump->is_free,
            'cta_title' => $bump->cta_title,
            'position' => (int) $bump->position,
            'target_self' => true,
            'target_offer_export_id' => $targetOfferId,
            'target_plan_export_id' => $targetPlanId,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeCoupons(Product $product): array
    {
        $tenantId = $product->tenant_id;
        $coupons = Coupon::query()
            ->where(function ($q) use ($product, $tenantId) {
                $q->where('product_id', $product->id);
                if ($tenantId !== null) {
                    $q->orWhere(function ($q2) use ($product, $tenantId) {
                        $q2->where('tenant_id', $tenantId)
                            ->whereHas('products', fn ($p) => $p->where('products.id', $product->id));
                    });
                }
            })
            ->get();

        $out = [];
        foreach ($coupons as $coupon) {
            $out[] = [
                'export_id' => (string) $coupon->id,
                'code' => $coupon->code,
                'type' => $coupon->type,
                'value' => (float) $coupon->value,
                'min_amount' => $coupon->min_amount !== null ? (float) $coupon->min_amount : null,
                'max_uses' => $coupon->max_uses,
                'valid_from' => $coupon->valid_from?->toIso8601String(),
                'valid_until' => $coupon->valid_until?->toIso8601String(),
                'is_active' => (bool) $coupon->is_active,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function sanitizeCheckoutConfig(array $config, string $productId, array &$warnings): array
    {
        if (! empty($config['upsell']['products']) && is_array($config['upsell']['products'])) {
            $kept = [];
            foreach ($config['upsell']['products'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $pid = (string) ($item['product_id'] ?? '');
                if ($pid === '' || $pid === $productId) {
                    $kept[] = $item;
                }
            }
            if (count($kept) !== count($config['upsell']['products'])) {
                $warnings[] = 'Upsell: produtos externos omitidos.';
            }
            $config['upsell']['products'] = $kept;
        }

        if (! empty($config['downsell']['product_id']) && (string) $config['downsell']['product_id'] !== $productId) {
            $warnings[] = 'Downsell: produto externo omitido.';
            $config['downsell']['product_id'] = null;
            $config['downsell']['product_offer_id'] = null;
        }

        if (isset($config['exit_popup']['coupon_id'])) {
            // Mantém export_id textual; import remapeia se cupom existir no pacote
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $pixels
     * @return array<string, mixed>
     */
    private function sanitizeConversionPixels(array $pixels): array
    {
        foreach (['meta', 'tiktok', 'google_ads', 'google_analytics'] as $platform) {
            if (! isset($pixels[$platform]) || ! is_array($pixels[$platform])) {
                continue;
            }
            $pixels[$platform]['integration_ids'] = [];
            if (! empty($pixels[$platform]['entries']) && is_array($pixels[$platform]['entries'])) {
                foreach ($pixels[$platform]['entries'] as $i => $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }
                    unset($entry['access_token'], $entry['api_token'], $entry['secret']);
                    $pixels[$platform]['entries'][$i] = $entry;
                }
            }
        }
        if (isset($pixels['custom_script_integration_ids'])) {
            $pixels['custom_script_integration_ids'] = [];
        }

        return $pixels;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function sanitizeMemberAreaConfig(array $config): array
    {
        if (isset($config['pwa']) && is_array($config['pwa'])) {
            unset(
                $config['pwa']['vapid_public_key'],
                $config['pwa']['vapid_private_key'],
                $config['pwa']['vapid_subject'],
            );
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function countModules(array $payload): int
    {
        $n = 0;
        foreach ($payload['member_sections'] ?? [] as $section) {
            $n += count($section['modules'] ?? []);
        }

        return $n;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function countLessons(array $payload): int
    {
        $n = 0;
        foreach ($payload['member_sections'] ?? [] as $section) {
            foreach ($section['modules'] ?? [] as $module) {
                $n += count($module['lessons'] ?? []);
            }
        }

        return $n;
    }
}
