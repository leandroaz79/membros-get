<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\UserProductAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StudentProductAccessController extends Controller
{
    public function __construct(
        protected UserProductAccessService $accessService
    ) {}

    public function access(Request $request, Product $product): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login')->with('error', 'Faça login para continuar.');
        }

        if (! $this->accessService->userOwnsProduct($user, $product->id)) {
            abort(403, 'Você não tem acesso a este produto.');
        }

        if ($product->type === Product::TYPE_AREA_MEMBROS) {
            return redirect()->away(
                app(\App\Services\MemberAreaResolver::class)->baseUrlForProduct($product)
            );
        }

        if ($product->type === Product::TYPE_LINK) {
            $link = $this->accessService->resolveDeliverableLink($product);
            if ($link === '') {
                return redirect()->route('student-area.index')
                    ->with('error', 'Link de entrega não configurado para este produto.');
            }

            return str_starts_with($link, 'http://') || str_starts_with($link, 'https://')
                ? redirect()->away($link)
                : redirect('/'.ltrim($link, '/'));
        }

        return redirect()->route('student-area.index')
            ->with('info', 'O acesso a este produto é entregue por uma plataforma externa. Verifique seu e-mail de compra.');
    }
}
