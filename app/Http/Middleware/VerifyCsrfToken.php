<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * Indicates whether the XSRF-TOKEN cookie should be set on the response.
     *
     * @var bool
     */
    protected $addHttpCookie = true;

    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        //
        'https://uat.mepsfpx.com.my/*',
        'https://uat.mepsfpx.com.my',
        'https://prim.my/transactionReceipt',
        'https://prim.my/paymentStatus',
        'https://dev.prim.my/devtrans',
        'https://prim.my/fpxIndex',
        'https://dev.prim.my/fpxIndex',
        'https://prim.my/mobile/*',
        'https://dev.prim.my/mobile/*'
    ];
}
