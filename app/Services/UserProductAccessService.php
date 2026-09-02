<?php

namespace App\Services;

use App\Models\MemberModule;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserProductAccessService
{
    public function __construct(
        protected MemberAreaResolver $memberAreaResolver
    ) {}

    /**
     * @return list<string|int>
     */
    public function productIdCandidates(string|int $productId): array
    {
        return array_values(array_unique(array_filter(
            [(string) $productId, is_numeric($productId) ? (int) $productId : null],
            static fn ($v) => $v !== null && $v !== ''
        )));
    }

    public function userOwnsProduct(User $user, string|int $productId): bool
    {
        $candidates = $this->productIdCandidates($productId);

        return $user->products()->whereIn('products.id', $candidates)->exists();
    }

    /**
     * @return array<string, true>
     */
    public function ownedProductIdSet(User $user): array
    {
        $set = [];
        foreach ($user->products()->pluck('products.id') as $id) {
            $set[(string) $id] = true;
            if (is_numeric($id)) {
                $set[(int) $id] = true;
            }
        }

        return $set;
    }

    public function hasOwnedProductId(array $ownedSet, string|int|null $productId): bool
    {
        if ($productId === null || $productId === '') {
            return false;
        }

        return isset($ownedSet[(string) $productId])
            || (is_numeric($productId) && isset($ownedSet[(int) $productId]));
    }

    /**
     * Resolve how the student should open a purchased product.
     *
     * @return array{action: string, url: ?string, label: string, message: ?string}|null
     */
    public function resolveAccess(User $user, Product $product, ?Product $hostProduct = null): ?array
    {
        if (! $this->userOwnsProduct($user, $product->id)) {
            return null;
        }

        if ($hostProduct !== null && (string) $hostProduct->id !== (string) $product->id) {
            $embedded = $this->resolveEmbeddedOpenUrl($hostProduct, $product);
            if ($embedded !== null) {
                return $embedded;
            }
        }

        return match ($product->type) {
            Product::TYPE_AREA_MEMBROS => [
                'action' => 'member_area',
                'url' => $this->memberAreaResolver->baseUrlForProduct($product),
                'label' => 'Acessar curso',
                'message' => null,
            ],
            Product::TYPE_LINK => [
                'action' => 'link',
                'url' => route('student-area.product.access', ['product' => $product->id]),
                'label' => 'Abrir link',
                'message' => null,
            ],
            Product::TYPE_AREA_MEMBROS_EXTERNA => [
                'action' => 'external',
                'url' => null,
                'label' => 'Entrega externa',
                'message' => 'O acesso a este produto é entregue por uma plataforma externa. Verifique seu e-mail de compra.',
            ],
            default => null,
        };
    }

    /**
     * Open URL for a related product inside a host member area context.
     *
     * @return array{action: string, url: string, label: string, message: null}|null
     */
    public function resolveMemberAreaRelatedOpenUrl(Product $hostProduct, Product $relatedProduct, string $baseUrl): ?array
    {
        if ($relatedProduct->type === Product::TYPE_LINK) {
            return [
                'action' => 'link',
                'url' => rtrim($baseUrl, '/').'/products/'.$relatedProduct->id.'/deliverable',
                'label' => 'Abrir link',
                'message' => null,
            ];
        }

        $wrapper = MemberModule::query()
            ->where('product_id', $hostProduct->id)
            ->whereIn('related_product_id', $this->productIdCandidates($relatedProduct->id))
            ->whereNotNull('source_member_module_id')
            ->orderBy('position')
            ->first();

        if ($wrapper) {
            return [
                'action' => 'embedded',
                'url' => rtrim($baseUrl, '/').'/products/'.$relatedProduct->id.'/open',
                'label' => 'Acessar',
                'message' => null,
            ];
        }

        if ($relatedProduct->type === Product::TYPE_AREA_MEMBROS) {
            return [
                'action' => 'member_area',
                'url' => $this->memberAreaResolver->baseUrlForProduct($relatedProduct),
                'label' => 'Acessar curso',
                'message' => null,
            ];
        }

        return null;
    }

    public function resolveDeliverableLink(Product $product): string
    {
        $fromModel = $product->checkout_config;
        $stored = is_array($fromModel) ? $fromModel : [];
        $merged = array_replace_recursive(Product::defaultCheckoutConfig(), $stored);
        $link = trim((string) ($merged['deliverable_link'] ?? ''));
        if ($link !== '') {
            return $link;
        }

        $ids = $this->productIdCandidates($product->id);
        $row = DB::table('products')->whereIn('id', $ids)->first();
        $stored = [];
        if ($row && isset($row->checkout_config) && $row->checkout_config !== null) {
            $raw = $row->checkout_config;
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $stored = is_array($decoded) ? $decoded : [];
            } elseif (is_array($raw)) {
                $stored = $raw;
            } elseif (is_object($raw)) {
                $decoded = json_decode(json_encode($raw), true);
                $stored = is_array($decoded) ? $decoded : [];
            }
        }
        $merged = array_replace_recursive(Product::defaultCheckoutConfig(), $stored);

        return trim((string) ($merged['deliverable_link'] ?? ''));
    }

    /**
     * @return array{action: string, url: string, label: string, message: null}|null
     */
    private function resolveEmbeddedOpenUrl(Product $hostProduct, Product $relatedProduct): ?array
    {
        $baseUrl = $this->memberAreaResolver->baseUrlForProduct($hostProduct);

        return $this->resolveMemberAreaRelatedOpenUrl($hostProduct, $relatedProduct, $baseUrl);
    }
}
