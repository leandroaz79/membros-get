<?php

namespace App\Services\ProductPackage;

use App\Models\Coupon;
use App\Models\MemberAreaDomain;
use App\Models\MemberCommunityPage;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\MemberTurma;
use App\Models\Product;
use App\Models\ProductAffiliateProgram;
use App\Models\ProductOffer;
use App\Models\ProductOrderBump;
use App\Models\SubscriptionPlan;
use App\Services\StorageService;
use App\Support\ProductPackageMediaCollector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ZipArchive;

class ProductImportService
{
    public function __construct(
        private readonly StorageService $storage,
    ) {}

    /**
     * @return array{success: bool, product_id?: string, product_name?: string, warnings: list<string>, summary?: array<string, mixed>, message?: string}
     */
    public function preview(UploadedFile $file): array
    {
        try {
            [$manifest] = $this->openPackage($file->getRealPath());
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'warnings' => [],
                'message' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'warnings' => array_values($manifest['warnings'] ?? []),
            'summary' => $manifest['product_summary'] ?? [],
            'include_media' => (bool) ($manifest['include_media'] ?? false),
            'media_files' => (int) ($manifest['counts']['media_files'] ?? count($manifest['media_map'] ?? [])),
        ];
    }

    /**
     * @return array{success: bool, product_id?: string, product_name?: string, warnings: list<string>, message?: string}
     */
    public function import(UploadedFile $file, int $tenantId): array
    {
        try {
            [$manifest, $payload, $zipPath, $tmpExtract] = $this->openPackage($file->getRealPath(), extractMedia: true);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'warnings' => [],
                'message' => $e->getMessage(),
            ];
        }

        $warnings = array_values($manifest['warnings'] ?? []);

        try {
            $product = DB::transaction(function () use ($payload, $manifest, $tmpExtract, $tenantId, &$warnings) {
                return $this->importPayload($payload, $manifest, $tmpExtract, $tenantId, $warnings);
            });
        } catch (\Throwable $e) {
            Log::error('ProductImportService: falha no import', ['message' => $e->getMessage()]);
            $this->cleanupTmp($tmpExtract);

            return [
                'success' => false,
                'warnings' => $warnings,
                'message' => $e->getMessage() ?: 'Falha ao importar o pacote.',
            ];
        }

        $this->cleanupTmp($tmpExtract);

        return [
            'success' => true,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1?: array<string, mixed>, 2?: string, 3?: string|null}
     */
    private function openPackage(string $filePath, bool $extractMedia = false): array
    {
        $zip = new ZipArchive;
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Arquivo inválido. Envie um pacote .getfy-product ou .zip.');
        }

        $manifestRaw = $zip->getFromName('manifest.json');
        $productRaw = $zip->getFromName('product.json');
        if (! is_string($manifestRaw) || ! is_string($productRaw)) {
            $zip->close();
            throw new \RuntimeException('Pacote incompleto (faltam manifest.json ou product.json).');
        }

        $manifest = json_decode($manifestRaw, true);
        $payload = json_decode($productRaw, true);
        if (! is_array($manifest) || ! is_array($payload)) {
            $zip->close();
            throw new \RuntimeException('JSON do pacote inválido.');
        }

        $errors = ProductPackageManifest::validate($manifest);
        if ($errors !== []) {
            $zip->close();
            throw new \RuntimeException(implode(' ', $errors));
        }

        $tmpExtract = null;
        if ($extractMedia) {
            $tmpExtract = storage_path('app/tmp-product-packages/'.uniqid('imp_', true));
            mkdir($tmpExtract, 0755, true);
            $zip->extractTo($tmpExtract);
        }

        $zip->close();

        if ($extractMedia) {
            return [$manifest, $payload, $filePath, $tmpExtract];
        }

        return [$manifest];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $warnings
     */
    private function importPayload(
        array $payload,
        array $manifest,
        ?string $tmpExtract,
        int $tenantId,
        array &$warnings,
    ): Product {
        $src = is_array($payload['product'] ?? null) ? $payload['product'] : [];
        if ($src === []) {
            throw new \RuntimeException('product.json sem dados do produto.');
        }

        $oldProductId = (string) ($src['export_id'] ?? '');
        $baseName = trim((string) ($src['name'] ?? 'Produto importado'));
        if ($baseName === '') {
            $baseName = 'Produto importado';
        }
        $name = $baseName.' (importado)';
        $slug = $this->uniqueSlug($tenantId, Str::slug($name) ?: 'produto-importado');

        $attrs = [
            'tenant_id' => $tenantId,
            'name' => $name,
            'slug' => $slug,
            'description' => $src['description'] ?? null,
            'type' => $src['type'] ?? Product::TYPE_AREA_MEMBROS,
            'billing_type' => $src['billing_type'] ?? Product::BILLING_ONE_TIME,
            'image' => null,
            'price' => (float) ($src['price'] ?? 0),
            'currency' => $src['currency'] ?? 'BRL',
            'is_active' => (bool) ($src['is_active'] ?? true),
            'cajupay_split_payout_enabled' => (bool) ($src['cajupay_split_payout_enabled'] ?? false),
            'checkout_config' => is_array($src['checkout_config'] ?? null) ? $src['checkout_config'] : Product::defaultCheckoutConfig(),
            'conversion_pixels' => is_array($src['conversion_pixels'] ?? null) ? $src['conversion_pixels'] : [],
            'member_area_config' => is_array($src['member_area_config'] ?? null) ? $src['member_area_config'] : [],
            'combo_product_ids' => [],
        ];

        // Em bases legadas/teste o products.id ainda é bigint; em produção é UUID.
        $numericId = $this->nextNumericProductIdIfRequired();
        if ($numericId !== null) {
            $attrs['id'] = $numericId;
        }

        $product = new Product;
        $product->forceFill($attrs);
        $product->save();

        $pathMap = $this->importMediaFiles(
            $manifest,
            $tmpExtract,
            $oldProductId,
            (string) $product->id,
            $warnings
        );
        $urlMap = ProductPackageMediaCollector::buildUrlMap($pathMap, $this->storage);

        // Reescreve configs do produto
        $checkoutConfig = ProductPackageMediaCollector::rewriteValue(
            $product->checkout_config ?? [],
            $urlMap,
            $this->storage
        );
        $memberConfig = ProductPackageMediaCollector::rewriteValue(
            $product->member_area_config ?? [],
            $urlMap,
            $this->storage
        );
        $image = null;
        if (! empty($src['image'])) {
            $rewritten = ProductPackageMediaCollector::rewriteString((string) $src['image'], $urlMap, $this->storage);
            $image = $rewritten !== '' ? $this->storage->pathFromUrl($rewritten) ?? (str_starts_with($rewritten, 'products/') ? $rewritten : null) : null;
            if ($image === null && isset($pathMap[(string) $src['image']])) {
                $image = $pathMap[(string) $src['image']];
            }
            // Se veio como URL, guardar path relativo quando possível
            if ($image === null && is_string($rewritten) && $rewritten !== '') {
                $fromUrl = $this->storage->pathFromUrl($rewritten);
                $image = $fromUrl;
            }
        }

        $product->update([
            'image' => $image,
            'checkout_config' => is_array($checkoutConfig) ? $checkoutConfig : [],
            'member_area_config' => is_array($memberConfig) ? $memberConfig : [],
        ]);

        // Domain path = slug
        if (($payload['member_area_domain'] ?? null) !== null || ($product->type === Product::TYPE_AREA_MEMBROS)) {
            MemberAreaDomain::updateOrCreate(
                ['product_id' => $product->id],
                ['type' => MemberAreaDomain::TYPE_PATH, 'value' => $slug]
            );
        }

        /** @var array<string, string> $offerMap */
        $offerMap = [];
        foreach ($payload['offers'] ?? [] as $offerData) {
            if (! is_array($offerData)) {
                continue;
            }
            $cfg = ProductPackageMediaCollector::rewriteValue(
                is_array($offerData['checkout_config'] ?? null) ? $offerData['checkout_config'] : [],
                $urlMap,
                $this->storage
            );
            $offer = ProductOffer::create([
                'product_id' => $product->id,
                'name' => $offerData['name'] ?? 'Oferta',
                'price' => (float) ($offerData['price'] ?? 0),
                'currency' => $offerData['currency'] ?? $product->currency,
                'checkout_slug' => ! empty($offerData['checkout_slug']) ? ProductOffer::generateUniqueCheckoutSlug() : null,
                'checkout_config' => is_array($cfg) ? $cfg : [],
                'position' => (int) ($offerData['position'] ?? 0),
                'combo_product_ids' => [],
            ]);
            if (! empty($offerData['export_id'])) {
                $offerMap[(string) $offerData['export_id']] = (string) $offer->id;
            }
        }

        /** @var array<string, string> $planMap */
        $planMap = [];
        foreach ($payload['subscription_plans'] ?? [] as $planData) {
            if (! is_array($planData)) {
                continue;
            }
            $cfg = ProductPackageMediaCollector::rewriteValue(
                is_array($planData['checkout_config'] ?? null) ? $planData['checkout_config'] : [],
                $urlMap,
                $this->storage
            );
            $plan = SubscriptionPlan::create([
                'product_id' => $product->id,
                'name' => $planData['name'] ?? 'Plano',
                'price' => (float) ($planData['price'] ?? 0),
                'currency' => $planData['currency'] ?? $product->currency,
                'interval' => $planData['interval'] ?? SubscriptionPlan::INTERVAL_MONTHLY,
                'checkout_slug' => ! empty($planData['checkout_slug']) ? SubscriptionPlan::generateUniqueCheckoutSlug() : null,
                'checkout_config' => is_array($cfg) ? $cfg : [],
                'position' => (int) ($planData['position'] ?? 0),
                'gateway_plan_id' => null,
                'combo_product_ids' => [],
            ]);
            if (! empty($planData['export_id'])) {
                $planMap[(string) $planData['export_id']] = (string) $plan->id;
            }
        }

        foreach ($payload['order_bumps'] ?? [] as $bumpData) {
            if (! is_array($bumpData)) {
                continue;
            }
            $targetOffer = null;
            $targetPlan = null;
            if (! empty($bumpData['target_offer_export_id'])) {
                $targetOffer = $offerMap[(string) $bumpData['target_offer_export_id']] ?? null;
            }
            if (! empty($bumpData['target_plan_export_id'])) {
                $targetPlan = $planMap[(string) $bumpData['target_plan_export_id']] ?? null;
            }

            ProductOrderBump::create([
                'product_id' => $product->id,
                'target_product_id' => $product->id,
                'target_product_offer_id' => $targetOffer,
                'target_subscription_plan_id' => $targetPlan,
                'title' => $bumpData['title'] ?? 'Order bump',
                'description' => $bumpData['description'] ?? null,
                'price_override' => $bumpData['price_override'] ?? null,
                'is_free' => (bool) ($bumpData['is_free'] ?? false),
                'cta_title' => $bumpData['cta_title'] ?? null,
                'position' => (int) ($bumpData['position'] ?? 0),
            ]);
        }

        if (is_array($payload['affiliate_program'] ?? null)) {
            $ap = $payload['affiliate_program'];
            ProductAffiliateProgram::create([
                'product_id' => $product->id,
                'enabled' => (bool) ($ap['enabled'] ?? false),
                'default_commission_percent' => (float) ($ap['default_commission_percent'] ?? 0),
                'manual_approval' => (bool) ($ap['manual_approval'] ?? false),
                'share_buyer_data' => (bool) ($ap['share_buyer_data'] ?? false),
                'public_slug' => null,
                'support_email' => $ap['support_email'] ?? null,
                'description' => $ap['description'] ?? null,
                'settlement_days_pix' => $ap['settlement_days_pix'] ?? null,
                'settlement_days_card' => $ap['settlement_days_card'] ?? null,
                'settlement_days_boleto' => $ap['settlement_days_boleto'] ?? null,
            ]);
        }

        /** @var array<string, int> $couponMap */
        $couponMap = [];
        foreach ($payload['coupons'] ?? [] as $couponData) {
            if (! is_array($couponData)) {
                continue;
            }
            $code = $this->uniqueCouponCode($tenantId, (string) ($couponData['code'] ?? 'CUPOM'));
            $coupon = Coupon::create([
                'tenant_id' => $tenantId,
                'product_id' => $product->id,
                'code' => $code,
                'type' => $couponData['type'] ?? Coupon::TYPE_PERCENT,
                'value' => (float) ($couponData['value'] ?? 0),
                'min_amount' => $couponData['min_amount'] ?? null,
                'max_uses' => $couponData['max_uses'] ?? null,
                'used_count' => 0,
                'valid_from' => $couponData['valid_from'] ?? null,
                'valid_until' => $couponData['valid_until'] ?? null,
                'is_active' => (bool) ($couponData['is_active'] ?? true),
            ]);
            $coupon->products()->syncWithoutDetaching([$product->id]);
            if (! empty($couponData['export_id'])) {
                $couponMap[(string) $couponData['export_id']] = (int) $coupon->id;
            }
        }

        // Remap exit_popup.coupon_id se existir
        $cfg = $product->checkout_config ?? [];
        if (isset($cfg['exit_popup']['coupon_id'])) {
            $oldCouponId = (string) $cfg['exit_popup']['coupon_id'];
            if (isset($couponMap[$oldCouponId])) {
                $cfg['exit_popup']['coupon_id'] = $couponMap[$oldCouponId];
                $product->update(['checkout_config' => $cfg]);
            } else {
                $cfg['exit_popup']['coupon_id'] = null;
                $product->update(['checkout_config' => $cfg]);
                $warnings[] = 'Cupom do exit popup não encontrado no pacote; referência removida.';
            }
        }

        /** @var array<string, int> $moduleMap */
        $moduleMap = [];
        foreach ($payload['member_sections'] ?? [] as $sectionData) {
            if (! is_array($sectionData)) {
                continue;
            }
            $section = MemberSection::create([
                'product_id' => $product->id,
                'title' => $sectionData['title'] ?? 'Seção',
                'position' => (int) ($sectionData['position'] ?? 0),
                'cover_mode' => $sectionData['cover_mode'] ?? 'horizontal',
                'section_type' => $sectionData['section_type'] ?? 'courses',
            ]);

            foreach ($sectionData['modules'] ?? [] as $moduleData) {
                if (! is_array($moduleData)) {
                    continue;
                }
                $thumbnail = ProductPackageMediaCollector::rewriteString(
                    (string) ($moduleData['thumbnail'] ?? ''),
                    $urlMap,
                    $this->storage
                );

                $module = MemberModule::create([
                    'member_section_id' => $section->id,
                    'product_id' => $product->id,
                    'title' => $moduleData['title'] ?? 'Módulo',
                    'position' => (int) ($moduleData['position'] ?? 0),
                    'thumbnail' => $thumbnail !== '' ? $thumbnail : null,
                    'show_title_on_cover' => (bool) ($moduleData['show_title_on_cover'] ?? true),
                    'related_product_id' => null,
                    'source_member_module_id' => null,
                    'access_type' => $moduleData['access_type'] ?? 'paid',
                    'external_url' => $moduleData['external_url'] ?? null,
                    'release_after_days' => $moduleData['release_after_days'] ?? null,
                    'release_at_date' => $moduleData['release_at_date'] ?? null,
                    'release_progress_percent' => $moduleData['release_progress_percent'] ?? null,
                    'release_required_module_ids' => [],
                    'access_duration_days' => $moduleData['access_duration_days'] ?? null,
                ]);

                if (! empty($moduleData['export_id'])) {
                    $moduleMap[(string) $moduleData['export_id']] = (int) $module->id;
                }

                foreach ($moduleData['lessons'] ?? [] as $lessonData) {
                    if (! is_array($lessonData)) {
                        continue;
                    }
                    $contentUrl = ProductPackageMediaCollector::rewriteString(
                        (string) ($lessonData['content_url'] ?? ''),
                        $urlMap,
                        $this->storage
                    );
                    $contentFiles = ProductPackageMediaCollector::rewriteValue(
                        $lessonData['content_files'] ?? null,
                        $urlMap,
                        $this->storage
                    );
                    $supportFiles = ProductPackageMediaCollector::rewriteValue(
                        $lessonData['support_files'] ?? null,
                        $urlMap,
                        $this->storage
                    );

                    $attrs = MemberLesson::onlyExistingColumns([
                        'member_module_id' => $module->id,
                        'product_id' => $product->id,
                        'title' => $lessonData['title'] ?? 'Aula',
                        'position' => (int) ($lessonData['position'] ?? 0),
                        'type' => $lessonData['type'] ?? MemberLesson::TYPE_VIDEO,
                        'content_url' => $contentUrl !== '' ? $contentUrl : null,
                        'link_title' => $lessonData['link_title'] ?? null,
                        'content_files' => is_array($contentFiles) ? $contentFiles : null,
                        'support_files' => is_array($supportFiles) ? $supportFiles : null,
                        'useful_links' => is_array($lessonData['useful_links'] ?? null) ? $lessonData['useful_links'] : null,
                        'release_after_days' => $lessonData['release_after_days'] ?? null,
                        'release_at_date' => $lessonData['release_at_date'] ?? null,
                        'access_duration_days' => $lessonData['access_duration_days'] ?? null,
                        'content_text' => $lessonData['content_text'] ?? null,
                        'duration_seconds' => $lessonData['duration_seconds'] ?? null,
                        'is_free' => (bool) ($lessonData['is_free'] ?? false),
                        'watermark_enabled' => (bool) ($lessonData['watermark_enabled'] ?? false),
                    ]);
                    MemberLesson::create($attrs);
                }
            }
        }

        // 2º passe: release_required_module_ids
        foreach ($payload['member_sections'] ?? [] as $sectionData) {
            if (! is_array($sectionData)) {
                continue;
            }
            foreach ($sectionData['modules'] ?? [] as $moduleData) {
                if (! is_array($moduleData) || empty($moduleData['export_id'])) {
                    continue;
                }
                $newModuleId = $moduleMap[(string) $moduleData['export_id']] ?? null;
                if (! $newModuleId) {
                    continue;
                }
                $required = [];
                foreach ($moduleData['release_required_module_ids'] ?? [] as $oldId) {
                    $mapped = $moduleMap[(string) $oldId] ?? null;
                    if ($mapped) {
                        $required[] = $mapped;
                    }
                }
                MemberModule::where('id', $newModuleId)->update([
                    'release_required_module_ids' => $required !== [] ? $required : null,
                ]);
            }
        }

        foreach ($payload['member_turmas'] ?? [] as $turmaData) {
            if (! is_array($turmaData)) {
                continue;
            }
            MemberTurma::create([
                'product_id' => $product->id,
                'name' => $turmaData['name'] ?? 'Turma',
                'description' => $turmaData['description'] ?? null,
                'start_date' => $turmaData['start_date'] ?? null,
                'end_date' => $turmaData['end_date'] ?? null,
                'position' => (int) ($turmaData['position'] ?? 0),
            ]);
        }

        foreach ($payload['member_community_pages'] ?? [] as $pageData) {
            if (! is_array($pageData)) {
                continue;
            }
            $banner = ProductPackageMediaCollector::rewriteString(
                (string) ($pageData['banner'] ?? ''),
                $urlMap,
                $this->storage
            );
            $icon = ProductPackageMediaCollector::rewriteString(
                (string) ($pageData['icon'] ?? ''),
                $urlMap,
                $this->storage
            );
            MemberCommunityPage::create([
                'product_id' => $product->id,
                'title' => $pageData['title'] ?? 'Comunidade',
                'icon' => $icon !== '' ? $icon : ($pageData['icon'] ?? null),
                'slug' => $pageData['slug'] ?? Str::slug((string) ($pageData['title'] ?? 'comunidade')),
                'banner' => $banner !== '' ? $banner : null,
                'position' => (int) ($pageData['position'] ?? 0),
                'is_public_posting' => (bool) ($pageData['is_public_posting'] ?? false),
                'is_default' => (bool) ($pageData['is_default'] ?? false),
                'is_featured' => (bool) ($pageData['is_featured'] ?? false),
            ]);
        }

        $warnings[] = 'Gateways de pagamento do checkout foram mantidos; confira se estão conectados neste ambiente.';

        return $product->fresh();
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $warnings
     * @return array<string, string> old_path => new_path
     */
    private function importMediaFiles(
        array $manifest,
        ?string $tmpExtract,
        string $oldProductId,
        string $newProductId,
        array &$warnings,
    ): array {
        $map = [];
        if ($tmpExtract === null || ! (bool) ($manifest['include_media'] ?? false)) {
            return $map;
        }

        $mediaMap = is_array($manifest['media_map'] ?? null) ? $manifest['media_map'] : [];
        foreach ($mediaMap as $oldPath => $archivePath) {
            $oldPath = (string) $oldPath;
            $archivePath = (string) $archivePath;
            $full = $tmpExtract.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $archivePath);
            if (! is_file($full)) {
                $warnings[] = "Arquivo de mídia ausente no pacote: {$archivePath}";

                continue;
            }

            $newPath = $this->remapProductPath($oldPath, $oldProductId, $newProductId);
            $dir = dirname($newPath);
            $basename = basename($newPath);

            try {
                $contents = file_get_contents($full);
                if ($contents === false) {
                    $warnings[] = "Falha ao ler mídia: {$archivePath}";

                    continue;
                }
                $this->storage->disk()->put($newPath, $contents);
                $map[$oldPath] = $newPath;
            } catch (\Throwable $e) {
                $warnings[] = "Falha ao importar mídia {$archivePath}: ".$e->getMessage();
            }
        }

        return $map;
    }

    private function remapProductPath(string $oldPath, string $oldProductId, string $newProductId): string
    {
        $oldPath = ltrim($oldPath, '/');
        if ($oldProductId !== '' && str_contains($oldPath, $oldProductId)) {
            return str_replace($oldProductId, $newProductId, $oldPath);
        }

        // products/xxx.ext → products/{newId}-xxx.ext
        if (str_starts_with($oldPath, 'products/')) {
            $base = basename($oldPath);

            return 'products/'.$newProductId.'-'.$base;
        }

        // Prefixo genérico
        $parts = explode('/', $oldPath, 2);
        if (count($parts) === 2) {
            return $parts[0].'/'.$newProductId.'/'.$parts[1];
        }

        return 'imported/'.$newProductId.'/'.basename($oldPath);
    }

    private function uniqueSlug(int $tenantId, string $base): string
    {
        $slug = $base;
        $n = 0;
        while (Product::forTenant($tenantId)->where('slug', $slug)->exists()) {
            $n++;
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    private function nextNumericProductIdIfRequired(): ?string
    {
        try {
            $type = Schema::getColumnType('products', 'id');
        } catch (\Throwable) {
            return null;
        }

        $type = strtolower((string) $type);
        if (! in_array($type, ['integer', 'int', 'bigint', 'smallint', 'tinyint'], true)) {
            return null;
        }

        return (string) ((int) (Product::query()->max('id') ?? 0) + 1);
    }

    private function uniqueCouponCode(int $tenantId, string $base): string
    {
        $code = strtoupper(preg_replace('/\s+/', '', $base) ?: 'CUPOM');
        $candidate = $code;
        $n = 0;
        while (Coupon::forTenant($tenantId)->where('code', $candidate)->exists()) {
            $n++;
            $candidate = $code.'-IMP'.$n;
        }

        return $candidate;
    }

    private function cleanupTmp(?string $dir): void
    {
        if ($dir === null || ! is_dir($dir)) {
            return;
        }
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        } catch (\Throwable) {
            // ignore
        }
    }
}
