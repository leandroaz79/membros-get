<?php

namespace App\Http\Controllers;

use App\Events\MemberAreaLoaded;
use App\Services\StudentProductCatalogService;
use Inertia\Inertia;
use Inertia\Response;

class MemberAreaController extends Controller
{
    public function index(StudentProductCatalogService $catalog): Response
    {
        $user = auth()->user();
        $produtos = $catalog->catalogForUser($user);
        event(new MemberAreaLoaded($user, $user->products()->orderBy('name')->get()));

        return Inertia::render('MemberArea/Index', [
            'produtos' => $produtos,
        ]);
    }
}
