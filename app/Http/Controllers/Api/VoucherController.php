<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VoucherResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VoucherController extends Controller
{
    public function index()
    {
        $vouchers = Auth::user()->vouchers()
            ->latest()
            ->get();

        return VoucherResource::collection($vouchers);
    }

    public function active()
    {
        $vouchers = Auth::user()->vouchers()
            ->where('is_used', false)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->latest()
            ->get();

        return VoucherResource::collection($vouchers);
    }
}
