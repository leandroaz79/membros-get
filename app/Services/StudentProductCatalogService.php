<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;

class StudentProductCatalogService
{
    public function __construct(
        protected UserProductAccessService $accessService,
        protected MemberAreaResolver $memberAreaResolver,
        protected MemberProgressService $progressService,
        protected RefundService $refundService,
        protected StorageService $storageService
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function catalogForUser(User $user): array
    {
        $products = $user->products()
            ->orderByDesc('product_user.created_at')
            ->orderBy('products.name')
            ->get();

        $items = [];
        foreach ($products as $product) {
            $pivotCreatedAt = $product->pivot?->created_at;
            $access = $this->accessService->resolveAccess($user, $product);
            if ($access === null) {
                continue;
            }

            $refund = $product->type === Product::TYPE_AREA_MEMBROS
                ? $this->refundService->eligibility($product, $user)
                : [
                    'enabled' => false,
                    'can_request' => false,
                    'message' => null,
                    'existing_request' => null,
                ];

            $item = [
                'id' => $product->id,
                'name' => $product->name,
                'type' => $product->type,
                'type_label' => $this->typeLabel($product->type),
                'image_url' => $product->image ? $this->storageService->url($product->image) : null,
                'purchased_at' => $pivotCreatedAt instanceof Carbon
                    ? $pivotCreatedAt->toIso8601String()
                    : null,
                'purchased_at_formatted' => $pivotCreatedAt instanceof Carbon
                    ? $pivotCreatedAt->format('d/m/Y')
                    : null,
                'access' => $access,
                'refund' => [
                    'enabled' => (bool) ($refund['enabled'] ?? false),
                    'can_request' => (bool) ($refund['can_request'] ?? false),
                    'message' => $refund['message'] ?? null,
                    'days_remaining' => $refund['days_remaining'] ?? null,
                    'existing_request' => $refund['existing_request'] ?? null,
                ],
            ];

            if ($product->type === Product::TYPE_AREA_MEMBROS) {
                $totalLessons = $this->progressService->totalLessonsCount($product);
                if ($totalLessons > 0) {
                    $item['progress_percent'] = $this->progressService->completionPercent($product, $user);
                    $item['has_lessons'] = true;
                } else {
                    $item['has_lessons'] = false;
                }

                $continue = $this->progressService->latestContinueWatching($product, $user);
                if ($continue !== null) {
                    $item['continue_watching'] = [
                        'lesson_title' => $continue['lesson_title'],
                        'module_title' => $continue['module_title'],
                        'url' => $this->continueWatchingUrl($product, $continue),
                    ];
                }
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param  array{lesson_id: int|string, module_id: int|string|null}  $continue
     */
    private function continueWatchingUrl(Product $product, array $continue): string
    {
        $base = rtrim($this->memberAreaResolver->baseUrlForProduct($product), '/');
        if (! empty($continue['module_id'])) {
            return $base.'/modulo/'.$continue['module_id'].'?aula='.$continue['lesson_id'];
        }

        return $base.'/aula/'.$continue['lesson_id'];
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            Product::TYPE_AREA_MEMBROS => 'Área de membros',
            Product::TYPE_LINK => 'Link',
            Product::TYPE_AREA_MEMBROS_EXTERNA => 'Área externa',
            Product::TYPE_LINK_PAGAMENTO => 'Link de pagamento',
            Product::TYPE_APLICATIVO => 'Aplicativo',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}
